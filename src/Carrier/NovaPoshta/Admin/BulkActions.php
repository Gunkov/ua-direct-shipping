<?php
declare(strict_types=1);

namespace Gunkov\UAShipping\Carrier\NovaPoshta\Admin;

use Gunkov\UAShipping\Carrier\NovaPoshta\ShippingMethod\AddressMethod;
use Gunkov\UAShipping\Carrier\NovaPoshta\ShippingMethod\PoshtomatMethod;
use Gunkov\UAShipping\Carrier\NovaPoshta\ShippingMethod\WarehouseMethod;
use Gunkov\UAShipping\Common\Logger\Logger;
use WC_Order;

/**
 * Bulk action "Create TTN for selected orders" in Orders list.
 * Supports both HPOS (woocommerce_page_wc-orders) and legacy (edit-shop_order).
 */
final class BulkActions
{
    public const ACTION = 'uads_bulk_create_ttn';

    public static function register(): void
    {
        // HPOS screen
        add_filter('bulk_actions-woocommerce_page_wc-orders', [self::class, 'add_action']);
        add_filter('handle_bulk_actions-woocommerce_page_wc-orders', [self::class, 'handle'], 10, 3);

        // Legacy
        add_filter('bulk_actions-edit-shop_order', [self::class, 'add_action']);
        add_filter('handle_bulk_actions-edit-shop_order', [self::class, 'handle'], 10, 3);

        add_action('admin_notices', [self::class, 'notice']);
    }

    public static function add_action(array $actions): array
    {
        $actions[self::ACTION] = __('UA Direct: Створити ТТН для вибраних', 'ua-direct-shipping');
        return $actions;
    }

    public static function handle(string $redirect_url, string $action, array $order_ids): string
    {
        if (self::ACTION !== $action) {
            return $redirect_url;
        }

        $creator = TtnCreator::from_settings();
        if (!$creator) {
            return add_query_arg(['uads_bulk_error' => 'no_api_key'], $redirect_url);
        }

        $created = 0;
        $skipped = 0;
        $failed  = 0;
        $our_method_ids = [WarehouseMethod::ID, PoshtomatMethod::ID, AddressMethod::ID];

        foreach ($order_ids as $oid) {
            $order = wc_get_order((int) $oid);
            if (!$order instanceof WC_Order) {
                $skipped++;
                continue;
            }

            // Already has TTN — skip
            if ('' !== (string) $order->get_meta('_uads_np_ttn')) {
                $skipped++;
                continue;
            }

            // Not our shipping method — skip
            $method_id = (string) $order->get_meta('_uads_np_method');
            if (!in_array($method_id, $our_method_ids, true)) {
                $skipped++;
                continue;
            }

            try {
                $payload = $creator->build_payload($order);
                $errors  = $creator->validate_payload($payload);
                if (!empty($errors)) {
                    Logger::instance()->warning('Bulk TTN: validation failed', ['order' => $order->get_id(), 'errors' => $errors]);
                    $failed++;
                    continue;
                }
                $creator->create($order, $payload);
                $created++;
            } catch (\Throwable $e) {
                Logger::instance()->error('Bulk TTN: create failed', ['order' => $order->get_id(), 'error' => $e->getMessage()]);
                $failed++;
            }

            // Rate limit — 0.3s between create calls (NP limit ~10 req/sec)
            usleep(300_000);
        }

        return add_query_arg([
            'uads_bulk_created' => $created,
            'uads_bulk_skipped' => $skipped,
            'uads_bulk_failed'  => $failed,
        ], $redirect_url);
    }

    public static function notice(): void
    {
        if (isset($_GET['uads_bulk_error']) && 'no_api_key' === $_GET['uads_bulk_error']) {
            echo '<div class="notice notice-error"><p>'
                . esc_html__('UA Direct Shipping: API key not configured. Bulk action skipped.', 'ua-direct-shipping')
                . '</p></div>';
            return;
        }

        if (!isset($_GET['uads_bulk_created']) && !isset($_GET['uads_bulk_failed'])) {
            return;
        }

        $created = isset($_GET['uads_bulk_created']) ? (int) $_GET['uads_bulk_created'] : 0;
        $skipped = isset($_GET['uads_bulk_skipped']) ? (int) $_GET['uads_bulk_skipped'] : 0;
        $failed  = isset($_GET['uads_bulk_failed'])  ? (int) $_GET['uads_bulk_failed']  : 0;

        $class = $failed > 0 ? 'notice-warning' : 'notice-success';
        echo '<div class="notice ' . esc_attr($class) . ' is-dismissible"><p>';
        printf(
            esc_html__('UA Direct: створено ТТН — %1$d, пропущено — %2$d, помилок — %3$d.', 'ua-direct-shipping'),
            $created, $skipped, $failed
        );
        echo '</p></div>';
    }
}
