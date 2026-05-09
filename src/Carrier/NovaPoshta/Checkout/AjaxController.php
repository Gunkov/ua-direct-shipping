<?php
declare(strict_types=1);

namespace Gunkov\UAShipping\Carrier\NovaPoshta\Checkout;

use Gunkov\UAShipping\Carrier\NovaPoshta\ApiClient;
use Gunkov\UAShipping\Carrier\NovaPoshta\DTO\City;
use Gunkov\UAShipping\Carrier\NovaPoshta\DTO\Warehouse;
use Gunkov\UAShipping\Carrier\NovaPoshta\Repositories\CityRepository;
use Gunkov\UAShipping\Carrier\NovaPoshta\Repositories\WarehouseRepository;
use Gunkov\UAShipping\Common\Cache\TransientCache;
use Gunkov\UAShipping\Common\Database\SchemaInstaller;
use Gunkov\UAShipping\Common\Http\HttpClient;
use Gunkov\UAShipping\Common\Logger\Logger;

final class AjaxController
{
    public const ACTION_SEARCH_CITIES     = 'uads_search_cities';
    public const ACTION_SEARCH_WAREHOUSES = 'uads_search_warehouses';
    public const ACTION_SEARCH_STREETS    = 'uads_search_streets';

    private const NONCE_FRONTEND = 'uads_checkout';

    private const CITIES_TTL_SECONDS     = 3600;       // 1 hour
    private const WAREHOUSES_TTL_SECONDS = 86400;      // 24 hours
    private const STREETS_TTL_SECONDS    = 3600;       // 1 hour

    public static function register(): void
    {
        foreach ([self::ACTION_SEARCH_CITIES => 'handle_search_cities',
                  self::ACTION_SEARCH_WAREHOUSES => 'handle_search_warehouses',
                  self::ACTION_SEARCH_STREETS => 'handle_search_streets'] as $action => $handler) {
            add_action("wp_ajax_{$action}", [self::class, $handler]);
            add_action("wp_ajax_nopriv_{$action}", [self::class, $handler]);
        }
    }

    public static function nonce(): string
    {
        return wp_create_nonce(self::NONCE_FRONTEND);
    }

    public static function handle_search_cities(): void
    {
        if (!self::verify_nonce()) {
            wp_send_json_error(['message' => 'Bad nonce'], 403);
        }

        $term  = isset($_REQUEST['term']) ? trim(sanitize_text_field(wp_unslash((string) $_REQUEST['term']))) : '';
        $limit = isset($_REQUEST['limit']) ? max(1, min(50, (int) $_REQUEST['limit'])) : 20;

        if (mb_strlen($term) < 2) {
            wp_send_json_success([]);
        }

        $client = self::api_client();
        if (null === $client) {
            wp_send_json_error(['message' => __('NP API key not configured', 'ua-direct-shipping')], 500);
        }

        // Try local DB first (fast, no API)
        if (SchemaInstaller::tables_exist()) {
            $local = CityRepository::search($term, $limit);
            if (!empty($local)) {
                $payload = array_map(static fn (City $c) => $c->to_select2_option(), $local);
                wp_send_json_success($payload);
            }
        }

        $cache_key = 'cities_' . md5(mb_strtolower($term) . '|' . $limit);
        $cache     = new TransientCache();

        try {
            /** @var City[] $cities */
            $cities = $cache->remember($cache_key, self::CITIES_TTL_SECONDS, static function () use ($client, $term, $limit): array {
                $rows = $client->search_settlements($term, $limit);
                return array_map(static fn (array $r) => City::from_settlement_api($r), $rows);
            });

            $payload = array_map(static fn (City $c) => $c->to_select2_option(), $cities ?? []);
            wp_send_json_success($payload);
        } catch (\Throwable $e) {
            Logger::instance()->error('search_cities failed', ['term' => $term, 'error' => $e->getMessage()]);
            wp_send_json_error(['message' => $e->getMessage()], 500);
        }
    }

    public static function handle_search_warehouses(): void
    {
        if (!self::verify_nonce()) {
            wp_send_json_error(['message' => 'Bad nonce'], 403);
        }

        $city_ref = isset($_REQUEST['city_ref']) ? sanitize_text_field(wp_unslash((string) $_REQUEST['city_ref'])) : '';
        $term     = isset($_REQUEST['term']) ? trim(sanitize_text_field(wp_unslash((string) $_REQUEST['term']))) : '';
        $category = isset($_REQUEST['category']) ? sanitize_key((string) $_REQUEST['category']) : 'warehouse';

        // Backward compat: legacy `include_poshtomats=1` from older JS = combined list
        if ('warehouse' === $category && !empty($_REQUEST['include_poshtomats'])) {
            $category = 'all';
        }
        if (!in_array($category, ['warehouse', 'poshtomat', 'all'], true)) {
            $category = 'warehouse';
        }

        if (1 !== preg_match('/^[a-f0-9-]{36}$/i', $city_ref)) {
            wp_send_json_error(['message' => __('Invalid city_ref', 'ua-direct-shipping')], 400);
        }

        $client = self::api_client();
        if (null === $client) {
            wp_send_json_error(['message' => __('NP API key not configured', 'ua-direct-shipping')], 500);
        }

        // Local DB first
        if (SchemaInstaller::tables_exist()) {
            $local = WarehouseRepository::search($city_ref, $term, $category, 500);
            if (!empty($local)) {
                $payload = array_map(static fn (Warehouse $w) => $w->to_select2_option(), $local);
                wp_send_json_success($payload);
            }
        }

        $cache_key = 'wh_' . substr(md5($city_ref), 0, 12) . '_' . md5($term . '|' . $category);
        $cache     = new TransientCache();

        try {
            /** @var Warehouse[] $warehouses */
            $warehouses = $cache->remember($cache_key, self::WAREHOUSES_TTL_SECONDS, static function () use ($client, $city_ref, $term, $category): array {
                $rows = $client->get_warehouses($city_ref, $term, 1, 500);
                $whs  = array_map(static fn (array $r) => Warehouse::from_api($r), $rows);
                if ('warehouse' === $category) {
                    $whs = array_filter($whs, static fn (Warehouse $w) => !$w->is_poshtomat);
                } elseif ('poshtomat' === $category) {
                    $whs = array_filter($whs, static fn (Warehouse $w) => $w->is_poshtomat);
                }
                return array_values($whs);
            });

            $payload = array_map(static fn (Warehouse $w) => $w->to_select2_option(), $warehouses ?? []);
            wp_send_json_success($payload);
        } catch (\Throwable $e) {
            Logger::instance()->error('search_warehouses failed', ['city_ref' => $city_ref, 'term' => $term, 'error' => $e->getMessage()]);
            wp_send_json_error(['message' => $e->getMessage()], 500);
        }
    }

    public static function handle_search_streets(): void
    {
        if (!self::verify_nonce()) {
            wp_send_json_error(['message' => 'Bad nonce'], 403);
        }

        $city_ref = isset($_REQUEST['city_ref']) ? sanitize_text_field(wp_unslash((string) $_REQUEST['city_ref'])) : '';
        $term     = isset($_REQUEST['term']) ? trim(sanitize_text_field(wp_unslash((string) $_REQUEST['term']))) : '';

        if (1 !== preg_match('/^[a-f0-9-]{36}$/i', $city_ref)) {
            wp_send_json_error(['message' => __('Invalid city_ref', 'ua-direct-shipping')], 400);
        }
        if (mb_strlen($term) < 2) {
            wp_send_json_success([]);
        }

        $client = self::api_client();
        if (null === $client) {
            wp_send_json_error(['message' => __('NP API key not configured', 'ua-direct-shipping')], 500);
        }

        $cache_key = 'streets_' . substr(md5($city_ref), 0, 12) . '_' . md5(mb_strtolower($term));
        $cache     = new TransientCache();

        try {
            $streets = $cache->remember($cache_key, self::STREETS_TTL_SECONDS, static function () use ($client, $city_ref, $term): array {
                return $client->get_streets($city_ref, $term, 30);
            });
            $payload = array_map(static fn (array $s) => [
                'id'   => (string) ($s['Ref'] ?? ''),
                'text' => (string) ($s['Description'] ?? ''),
                'ref'  => (string) ($s['Ref'] ?? ''),
                'name' => (string) ($s['Description'] ?? ''),
            ], $streets ?? []);
            wp_send_json_success($payload);
        } catch (\Throwable $e) {
            Logger::instance()->error('search_streets failed', ['city' => $city_ref, 'term' => $term, 'error' => $e->getMessage()]);
            wp_send_json_error(['message' => $e->getMessage()], 500);
        }
    }

    private static function verify_nonce(): bool
    {
        $nonce = isset($_REQUEST['_wpnonce']) ? (string) $_REQUEST['_wpnonce'] : '';
        if ('' === $nonce && !empty($_REQUEST['nonce'])) {
            $nonce = (string) $_REQUEST['nonce'];
        }
        return (bool) wp_verify_nonce($nonce, self::NONCE_FRONTEND);
    }

    private static function api_client(): ?ApiClient
    {
        $api_key = (string) get_option('uads_np_api_key', '');
        if ('' === $api_key) {
            return null;
        }
        return new ApiClient($api_key, new HttpClient(), Logger::instance());
    }
}
