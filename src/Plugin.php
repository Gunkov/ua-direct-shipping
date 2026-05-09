<?php
declare(strict_types=1);

namespace Gunkov\UAShipping;

use Gunkov\UAShipping\Carrier\NovaPoshta\Admin\BulkActions;
use Gunkov\UAShipping\Carrier\NovaPoshta\Admin\CsvExporter;
use Gunkov\UAShipping\Carrier\NovaPoshta\Admin\OrderMetaBox;
use Gunkov\UAShipping\Carrier\NovaPoshta\Admin\PdfProxyController;
use Gunkov\UAShipping\Carrier\NovaPoshta\ApiClient;
use Gunkov\UAShipping\Carrier\NovaPoshta\BrandedTracking\PageController;
use Gunkov\UAShipping\Carrier\NovaPoshta\Checkout\AjaxController;
use Gunkov\UAShipping\Carrier\NovaPoshta\Checkout\FieldsRenderer;
use Gunkov\UAShipping\Carrier\NovaPoshta\Checkout\OrderHandler;
use Gunkov\UAShipping\Carrier\NovaPoshta\Cron\TrackingCron;
use Gunkov\UAShipping\Carrier\NovaPoshta\Cron\WarehouseSyncCron;
use Gunkov\UAShipping\Common\Database\SchemaInstaller;
use Gunkov\UAShipping\Carrier\NovaPoshta\ShippingMethod\AddressMethod;
use Gunkov\UAShipping\Carrier\NovaPoshta\ShippingMethod\PoshtomatMethod;
use Gunkov\UAShipping\Carrier\NovaPoshta\ShippingMethod\WarehouseMethod;
use Gunkov\UAShipping\Common\Http\HttpClient;
use Gunkov\UAShipping\Common\Logger\Logger;
use Gunkov\UAShipping\Common\Settings\SettingsPage;

final class Plugin
{
    public static function boot(): void
    {
        load_plugin_textdomain('ua-direct-shipping', false, dirname(plugin_basename(UADS_FILE)) . '/languages');

        SettingsPage::register();

        add_action('init', [AjaxController::class, 'register']);
        add_action('init', [FieldsRenderer::class, 'register']);
        add_action('init', [OrderHandler::class, 'register']);
        add_action('init', [OrderMetaBox::class, 'register']);
        add_action('init', [PdfProxyController::class, 'register']);
        add_action('init', [BulkActions::class, 'register']);
        add_action('init', [CsvExporter::class, 'register']);
        TrackingCron::register();
        WarehouseSyncCron::register();
        PageController::register();

        // Deferred rewrite flush — runs once after PageController has added its rule on `init`.
        if ('1' === (string) get_option('uads_needs_rewrite_flush', '0')) {
            add_action('init', static function () {
                flush_rewrite_rules(false);
                delete_option('uads_needs_rewrite_flush');
            }, 99);
        }
        add_action('wp_ajax_uads_test_connection', [self::class, 'ajax_test_connection']);

        add_action('woocommerce_shipping_init', [self::class, 'load_shipping_methods']);
        add_filter('woocommerce_shipping_methods', [self::class, 'register_shipping_methods']);

        add_filter('woocommerce_cart_shipping_method_full_label', [self::class, 'add_method_description'], 10, 2);

        // Hide shipping calculation on /cart/ page — keep cart clean (subtotal only).
        // Shipping methods + rates are calculated on /checkout/ where user picks city via our select2.
        add_filter('woocommerce_cart_ready_to_calc_shipping', static function ($ready) {
            if (function_exists('is_cart') && is_cart()) {
                return false;
            }
            return $ready;
        });
    }

    /**
     * Append a one-liner description under each NP shipping method in the
     * cart/checkout shipping radio list.
     */
    public static function add_method_description(string $label, $method): string
    {
        $descriptions = [
            WarehouseMethod::ID => __('До 30 кг. Кінцева точка — відділення НП у вибраному місті.', 'ua-direct-shipping'),
            PoshtomatMethod::ID => __('До 20 кг. Самовивіз з поштомата у будь-який зручний час.', 'ua-direct-shipping'),
            AddressMethod::ID   => __('Кур\'єр НП доставить за вашою адресою.', 'ua-direct-shipping'),
        ];
        $method_id = is_object($method) && property_exists($method, 'method_id') ? (string) $method->method_id : '';
        if (!isset($descriptions[$method_id])) {
            return $label;
        }
        return $label . '<br /><small class="uads-method-description">' . esc_html($descriptions[$method_id]) . '</small>';
    }

    public static function load_shipping_methods(): void
    {
        // Trigger autoload for shipping method classes (WC introspects via reflection)
        class_exists(WarehouseMethod::class);
        class_exists(PoshtomatMethod::class);
        class_exists(AddressMethod::class);
    }

    /**
     * @param array<string,string> $methods
     * @return array<string,string>
     */
    public static function register_shipping_methods(array $methods): array
    {
        $methods[WarehouseMethod::ID]  = WarehouseMethod::class;
        $methods[PoshtomatMethod::ID]  = PoshtomatMethod::class;
        $methods[AddressMethod::ID]    = AddressMethod::class;
        return $methods;
    }

    public static function on_activate(): void
    {
        if (false === get_option('uads_db_version')) {
            update_option('uads_db_version', UADS_VERSION);
        }
        SchemaInstaller::install();
        TrackingCron::schedule();
        WarehouseSyncCron::schedule();
        // Schedule a one-shot soft flush after init has registered our rewrite rule.
        // (flush_rewrite_rules() during activation may run before init → rule missing.)
        update_option('uads_needs_rewrite_flush', '1');
    }

    public static function on_deactivate(): void
    {
        TrackingCron::unschedule();
        WarehouseSyncCron::unschedule();
        flush_rewrite_rules(false);
    }

    public static function ajax_test_connection(): void
    {
        check_ajax_referer('uads_test_connection');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Forbidden', 'ua-direct-shipping')], 403);
        }

        $api_key = isset($_POST['api_key']) ? sanitize_text_field(wp_unslash($_POST['api_key'])) : '';

        if ('' === $api_key) {
            $api_key = (string) get_option('uads_np_api_key', '');
        }

        if ('' === $api_key) {
            wp_send_json_error(['message' => __('API key is empty', 'ua-direct-shipping')]);
        }

        $client = new ApiClient($api_key, new HttpClient(), Logger::instance());

        try {
            $start = microtime(true);
            $areas = $client->get_areas();
            $ms    = (int) round((microtime(true) - $start) * 1000);

            wp_send_json_success([
                'message' => sprintf(
                    /* translators: 1: areas count, 2: response time ms */
                    __('Connected. %1$d areas returned in %2$dms.', 'ua-direct-shipping'),
                    count($areas),
                    $ms
                ),
                'areas_count' => count($areas),
                'response_ms' => $ms,
            ]);
        } catch (\Throwable $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }
}

add_filter('cron_schedules', static function (array $schedules): array {
    $schedules['uads_six_hours'] = [
        'interval' => 6 * HOUR_IN_SECONDS,
        'display'  => __('Every 6 hours (UA Direct Shipping)', 'ua-direct-shipping'),
    ];
    return $schedules;
});
