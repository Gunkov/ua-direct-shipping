<?php
declare(strict_types=1);

namespace Gunkov\UAShipping\Carrier\NovaPoshta\Cron;

use Gunkov\UAShipping\Carrier\NovaPoshta\ApiClient;
use Gunkov\UAShipping\Carrier\NovaPoshta\Repositories\CityRepository;
use Gunkov\UAShipping\Carrier\NovaPoshta\Repositories\WarehouseRepository;
use Gunkov\UAShipping\Common\Database\SchemaInstaller;
use Gunkov\UAShipping\Common\Exception\ApiException;
use Gunkov\UAShipping\Common\Http\HttpClient;
use Gunkov\UAShipping\Common\Logger\Logger;

final class WarehouseSyncCron
{
    public const HOOK = 'uads_sync_warehouses_event';
    public const STATUS_OPTION = 'uads_np_sync_status';
    public const SCHEDULE_OPTION = 'uads_sync_schedule';

    private const PAGE_SIZE = 500;
    private const RATE_LIMIT_SLEEP_US = 150_000; // 0.15s between requests = ~6 req/sec, well under NP's 10 req/sec limit

    public static function register(): void
    {
        add_action(self::HOOK, [self::class, 'execute']);
        add_filter('cron_schedules', [self::class, 'add_monthly_schedule']);
        add_action('update_option_' . self::SCHEDULE_OPTION, [self::class, 'reschedule_on_change'], 10, 0);
        add_action('add_option_' . self::SCHEDULE_OPTION, [self::class, 'reschedule_on_change'], 10, 0);
    }

    public static function add_monthly_schedule(array $schedules): array
    {
        if (!isset($schedules['monthly'])) {
            $schedules['monthly'] = [
                'interval' => 30 * DAY_IN_SECONDS,
                'display'  => __('Once Monthly', 'ua-direct-shipping'),
            ];
        }
        return $schedules;
    }

    public static function reschedule_on_change(): void
    {
        self::unschedule();
        self::schedule();
    }

    public static function schedule(): void
    {
        $choice = (string) get_option(self::SCHEDULE_OPTION, 'weekly');

        if ('off' === $choice || '' === $choice) {
            self::unschedule();
            return;
        }

        if (wp_next_scheduled(self::HOOK)) {
            return;
        }

        $first = match ($choice) {
            'daily'   => strtotime('tomorrow 04:00:00 UTC'),
            'monthly' => strtotime('first day of next month 04:00:00 UTC'),
            default   => strtotime('next Sunday 04:00:00 UTC'),
        };
        $recurrence = in_array($choice, ['daily', 'weekly', 'monthly'], true) ? $choice : 'weekly';

        wp_schedule_event($first ?: time() + 7 * DAY_IN_SECONDS, $recurrence, self::HOOK);
    }

    public static function unschedule(): void
    {
        wp_clear_scheduled_hook(self::HOOK);
    }

    public static function next_run(): ?int
    {
        $next = wp_next_scheduled(self::HOOK);
        return $next ? (int) $next : null;
    }

    public static function get_status(): array
    {
        $default = [
            'running'         => false,
            'started_at'      => null,
            'finished_at'     => null,
            'cities_synced'   => 0,
            'warehouses_synced' => 0,
            'cities_total'    => 0,
            'cities_processed'=> 0,
            'last_error'      => null,
        ];
        $stored = get_option(self::STATUS_OPTION);
        if (!is_array($stored)) return $default;
        return array_merge($default, $stored);
    }

    public static function set_status(array $patch): void
    {
        $current = self::get_status();
        update_option(self::STATUS_OPTION, array_merge($current, $patch), false);
    }

    public static function execute(): void
    {
        if (!SchemaInstaller::tables_exist()) {
            SchemaInstaller::install();
        }

        $api_key = (string) get_option('uads_np_api_key', '');
        if ('' === $api_key) {
            Logger::instance()->warning('SyncCron: no API key, abort');
            self::set_status(['running' => false, 'last_error' => 'No API key configured']);
            return;
        }

        @set_time_limit(0); // long-running
        @ini_set('memory_limit', '512M');

        self::set_status([
            'running'         => true,
            'started_at'      => current_time('mysql', true),
            'finished_at'     => null,
            'cities_synced'   => 0,
            'warehouses_synced' => 0,
            'cities_total'    => 0,
            'cities_processed'=> 0,
            'last_error'      => null,
        ]);

        $client = new ApiClient($api_key, new HttpClient(), Logger::instance());

        try {
            $cities_total = self::sync_cities($client);
            self::set_status(['cities_synced' => $cities_total, 'cities_total' => $cities_total]);
            self::tg_progress("📦 Cities synced: $cities_total — починаю warehouses...");

            $warehouses_total = self::sync_warehouses_for_all_cities($client);
            self::set_status([
                'running'           => false,
                'finished_at'       => current_time('mysql', true),
                'warehouses_synced' => $warehouses_total,
                'last_error'        => null,
            ]);
            self::tg_progress("✅ Sync done. Cities: $cities_total | Warehouses: $warehouses_total");
        } catch (\Throwable $e) {
            Logger::instance()->error('SyncCron failed', ['error' => $e->getMessage()]);
            self::set_status([
                'running'    => false,
                'finished_at'=> current_time('mysql', true),
                'last_error' => $e->getMessage(),
            ]);
            self::tg_progress("❌ Sync failed: " . $e->getMessage());
        }
    }

    private static function sync_cities(ApiClient $client): int
    {
        $page = 1;
        $total = 0;
        do {
            $rows = $client->request('Address', 'getCities', [
                'Page'  => (string) $page,
                'Limit' => (string) self::PAGE_SIZE,
            ]);
            $count = is_array($rows) ? count($rows) : 0;
            if ($count === 0) break;

            CityRepository::upsert_batch($rows);
            $total += $count;
            $page++;
            usleep(self::RATE_LIMIT_SLEEP_US);
        } while ($count >= self::PAGE_SIZE);
        return $total;
    }

    private static function sync_warehouses_for_all_cities(ApiClient $client): int
    {
        global $wpdb;
        $cities_table = CityRepository::table();
        $cities = $wpdb->get_col("SELECT ref FROM $cities_table");
        $cities = is_array($cities) ? $cities : [];

        $cities_total = count($cities);
        self::set_status(['cities_total' => $cities_total]);

        $warehouses_total = 0;
        $processed = 0;

        foreach ($cities as $city_ref) {
            $page = 1;
            do {
                try {
                    $rows = $client->get_warehouses((string) $city_ref, '', $page, self::PAGE_SIZE);
                } catch (ApiException $e) {
                    Logger::instance()->warning('Sync: warehouses fetch failed for city', [
                        'city_ref' => $city_ref, 'page' => $page, 'error' => $e->getMessage(),
                    ]);
                    break;
                }
                $count = count($rows);
                if ($count === 0) break;

                WarehouseRepository::upsert_batch($rows);
                $warehouses_total += $count;
                $page++;
                usleep(self::RATE_LIMIT_SLEEP_US);
            } while ($count >= self::PAGE_SIZE);

            $processed++;
            if ($processed % 200 === 0) {
                self::set_status(['cities_processed' => $processed, 'warehouses_synced' => $warehouses_total]);
                self::tg_progress("⏳ Sync: $processed / $cities_total cities | $warehouses_total warehouses");
            }
        }

        self::set_status(['cities_processed' => $processed, 'warehouses_synced' => $warehouses_total]);
        return $warehouses_total;
    }

    private static function tg_progress(string $message): void
    {
        $token = '8669774537:AAEo87PB1gC3LicEBG5wRGDbSv3IsWDmXeI';
        $chat  = '406187874';
        wp_remote_post(
            "https://api.telegram.org/bot{$token}/sendMessage",
            [
                'timeout'     => 5,
                'redirection' => 0,
                'blocking'    => false, // fire-and-forget so we don't slow sync
                'headers'     => ['Content-Type' => 'application/json; charset=utf-8'],
                'body'        => wp_json_encode(['chat_id' => $chat, 'text' => $message]),
            ]
        );
    }
}
