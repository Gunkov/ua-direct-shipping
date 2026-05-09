<?php
declare(strict_types=1);

namespace Gunkov\UAShipping\Carrier\NovaPoshta\Admin;

use WC_Order;

/**
 * CSV export of all orders that have an NP TTN attached, filtered by date range.
 * Triggered via admin URL: /wp-admin/admin.php?action=uads_export_ttn_csv&...&_wpnonce=...
 */
final class CsvExporter
{
    public const ACTION = 'uads_export_ttn_csv';
    public const NONCE  = 'uads_export_ttn';

    public static function register(): void
    {
        add_action('admin_action_' . self::ACTION, [self::class, 'export']);
    }

    public static function url(string $from = '', string $to = ''): string
    {
        $from = $from ?: date('Y-m-01');
        $to   = $to   ?: date('Y-m-t');
        return add_query_arg([
            'action'   => self::ACTION,
            'from'     => $from,
            'to'       => $to,
            '_wpnonce' => wp_create_nonce(self::NONCE),
        ], admin_url('admin.php'));
    }

    public static function export(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Forbidden', 'ua-direct-shipping'), '', ['response' => 403]);
        }
        $nonce = isset($_GET['_wpnonce']) ? (string) $_GET['_wpnonce'] : '';
        if (!wp_verify_nonce($nonce, self::NONCE)) {
            wp_die(esc_html__('Bad nonce', 'ua-direct-shipping'), '', ['response' => 403]);
        }

        $from = isset($_GET['from']) ? sanitize_text_field((string) $_GET['from']) : date('Y-m-01');
        $to   = isset($_GET['to'])   ? sanitize_text_field((string) $_GET['to'])   : date('Y-m-t');

        $args = [
            'limit'      => 5000,
            'orderby'    => 'date',
            'order'      => 'DESC',
            'date_created' => $from . '...' . $to,
            'meta_query' => [
                ['key' => '_uads_np_ttn', 'value' => '', 'compare' => '!='],
            ],
        ];
        $orders = wc_get_orders($args);

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="uads-ttn-' . $from . '_' . $to . '.csv"');

        $fp = fopen('php://output', 'w');
        // UTF-8 BOM so Excel opens cyrillic correctly
        fwrite($fp, "\xEF\xBB\xBF");

        fputcsv($fp, [
            'Order ID', 'Date', 'TTN', 'TTN Cost', 'TTN Status', 'Status Updated',
            'Method', 'City', 'Warehouse', 'Recipient', 'Phone', 'Order Total', 'WC Status',
        ]);

        foreach ($orders as $order) {
            if (!$order instanceof WC_Order) continue;
            fputcsv($fp, [
                $order->get_id(),
                $order->get_date_created() ? $order->get_date_created()->format('Y-m-d H:i') : '',
                (string) $order->get_meta('_uads_np_ttn'),
                (string) $order->get_meta('_uads_np_ttn_cost'),
                (string) $order->get_meta('_uads_np_ttn_status_text'),
                (string) $order->get_meta('_uads_np_ttn_status_updated'),
                (string) $order->get_meta('_uads_np_method'),
                (string) $order->get_meta('_uads_np_city_name'),
                (string) $order->get_meta('_uads_np_warehouse_description'),
                trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()),
                (string) $order->get_meta('_uads_np_recipient_phone'),
                (string) $order->get_total(),
                (string) $order->get_status(),
            ]);
        }
        fclose($fp);
        exit;
    }
}
