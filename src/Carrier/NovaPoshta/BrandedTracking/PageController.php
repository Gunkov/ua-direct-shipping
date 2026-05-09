<?php
declare(strict_types=1);

namespace Gunkov\UAShipping\Carrier\NovaPoshta\BrandedTracking;

use Gunkov\UAShipping\Carrier\NovaPoshta\ApiClient;
use Gunkov\UAShipping\Carrier\NovaPoshta\DTO\TrackingStatus;
use Gunkov\UAShipping\Common\Cache\TransientCache;
use Gunkov\UAShipping\Common\Exception\ApiException;
use Gunkov\UAShipping\Common\Http\HttpClient;
use Gunkov\UAShipping\Common\Logger\Logger;

final class PageController
{
    public const QUERY_VAR = 'uads_track';
    public const SLUG_OPTION = 'uads_branded_tracking_slug';
    public const ENABLED_OPTION = 'uads_branded_tracking_enabled';

    private const CACHE_TTL_SECONDS = 300;

    public static function register(): void
    {
        add_action('init', [self::class, 'add_rewrite']);
        add_filter('query_vars', [self::class, 'add_query_var']);
        add_action('template_redirect', [self::class, 'maybe_render']);
    }

    public static function default_slug(): string
    {
        return 'uads-track';
    }

    public static function slug(): string
    {
        $slug = (string) get_option(self::SLUG_OPTION, self::default_slug());
        $slug = trim($slug, "/ \t\n");
        return '' === $slug ? self::default_slug() : $slug;
    }

    public static function is_enabled(): bool
    {
        return '0' !== (string) get_option(self::ENABLED_OPTION, '1');
    }

    public static function add_rewrite(): void
    {
        if (!self::is_enabled()) {
            return;
        }
        add_rewrite_rule(
            '^' . preg_quote(self::slug(), '/') . '/([0-9]{8,18})/?$',
            'index.php?' . self::QUERY_VAR . '=$matches[1]',
            'top'
        );
    }

    public static function add_query_var(array $vars): array
    {
        $vars[] = self::QUERY_VAR;
        return $vars;
    }

    public static function maybe_render(): void
    {
        $ttn = (string) get_query_var(self::QUERY_VAR);
        if ('' === $ttn || !preg_match('/^[0-9]{8,18}$/', $ttn)) {
            return;
        }

        if (!self::is_enabled()) {
            status_header(404);
            nocache_headers();
            exit;
        }

        $status = self::fetch_status($ttn);

        get_header();
        $template = locate_template('uads/tracking-page.php');
        if ('' === $template) {
            $template = UADS_DIR . 'templates/tracking/tracking-page.php';
        }
        if (is_readable($template)) {
            include $template; // expects $ttn, $status in scope
        }
        get_footer();
        exit;
    }

    public static function fetch_status(string $ttn): ?TrackingStatus
    {
        $cache = new TransientCache();
        $cached = $cache->get('track_' . $ttn);
        if ($cached instanceof TrackingStatus) {
            return $cached;
        }

        $api_key = (string) get_option('uads_np_api_key', '');
        if ('' === $api_key) {
            return null;
        }

        try {
            $client = new ApiClient($api_key, new HttpClient(), Logger::instance());
            $rows = $client->get_status_documents([['DocumentNumber' => $ttn, 'Phone' => '']]);
            if (empty($rows[0])) {
                return null;
            }
            $status = TrackingStatus::from_api($rows[0]);
            $cache->set('track_' . $ttn, $status, self::CACHE_TTL_SECONDS);
            return $status;
        } catch (ApiException $e) {
            Logger::instance()->warning('Branded tracking fetch failed', ['ttn' => $ttn, 'error' => $e->getMessage()]);
            return null;
        }
    }

    public static function url(string $ttn): string
    {
        return home_url('/' . self::slug() . '/' . rawurlencode($ttn) . '/');
    }
}
