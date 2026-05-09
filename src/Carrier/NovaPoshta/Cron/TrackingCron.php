<?php
declare(strict_types=1);

namespace Gunkov\UAShipping\Carrier\NovaPoshta\Cron;

use Gunkov\UAShipping\Carrier\NovaPoshta\ApiClient;
use Gunkov\UAShipping\Carrier\NovaPoshta\DTO\TrackingStatus;
use Gunkov\UAShipping\Common\Exception\ApiException;
use Gunkov\UAShipping\Common\Http\HttpClient;
use Gunkov\UAShipping\Common\Logger\Logger;
use WC_Order;

final class TrackingCron
{
    public const HOOK = 'uads_track_orders_event';

    private const BATCH_LIMIT = 100;

    public static function register(): void
    {
        add_action(self::HOOK, [self::class, 'execute']);
    }

    public static function schedule(): void
    {
        if (!wp_next_scheduled(self::HOOK)) {
            wp_schedule_event(time() + 600, 'uads_six_hours', self::HOOK);
        }
    }

    public static function unschedule(): void
    {
        wp_clear_scheduled_hook(self::HOOK);
    }

    public static function execute(): void
    {
        $api_key = (string) get_option('uads_np_api_key', '');
        if ('' === $api_key) {
            Logger::instance()->warning('TrackingCron: no API key, skipping');
            return;
        }

        $orders = self::fetch_orders_to_track();
        if (empty($orders)) {
            Logger::instance()->info('TrackingCron: no orders to track');
            return;
        }

        $client = new ApiClient($api_key, new HttpClient(), Logger::instance());
        $documents = [];
        $by_ttn    = [];

        foreach ($orders as $order) {
            $ttn = (string) $order->get_meta('_uads_np_ttn');
            if ('' === $ttn) {
                continue;
            }
            $phone = (string) ($order->get_meta('_uads_np_recipient_phone') ?: $order->get_billing_phone());
            $documents[]    = ['DocumentNumber' => $ttn, 'Phone' => $phone];
            $by_ttn[$ttn]   = $order;
        }

        if (empty($documents)) {
            return;
        }

        try {
            $rows = $client->get_status_documents($documents);
        } catch (ApiException $e) {
            Logger::instance()->error('TrackingCron: getStatusDocuments failed', ['error' => $e->getMessage()]);
            return;
        }

        $processed = 0;
        foreach ($rows as $row) {
            $status = TrackingStatus::from_api($row);
            if ('' === $status->document_number || !isset($by_ttn[$status->document_number])) {
                continue;
            }

            $order = $by_ttn[$status->document_number];
            self::apply_status_to_order($order, $status);
            $processed++;
        }

        Logger::instance()->info('TrackingCron: processed', ['count' => $processed, 'total_orders' => count($orders)]);
    }

    /**
     * @return WC_Order[]
     */
    private static function fetch_orders_to_track(): array
    {
        $args = [
            'status'     => ['processing', 'on-hold'],
            'limit'      => self::BATCH_LIMIT,
            'orderby'    => 'date',
            'order'      => 'ASC',
            'meta_query' => [
                [
                    'key'     => '_uads_np_ttn',
                    'value'   => '',
                    'compare' => '!=',
                ],
            ],
        ];
        $orders = wc_get_orders($args);
        return is_array($orders) ? $orders : [];
    }

    private static function apply_status_to_order(WC_Order $order, TrackingStatus $status): void
    {
        $old_code = (int) $order->get_meta('_uads_np_ttn_status_code');
        $new_code = $status->status_code;

        $order->update_meta_data('_uads_np_ttn_status_code', (string) $new_code);
        $order->update_meta_data('_uads_np_ttn_status_text', $status->status);
        $order->update_meta_data('_uads_np_ttn_status_updated', current_time('mysql', true));
        if ($status->actual_delivery_date) {
            $order->update_meta_data('_uads_np_ttn_actual_delivery_date', $status->actual_delivery_date);
        }

        if ($new_code === $old_code) {
            $order->save();
            return;
        }

        $note = sprintf('НП трекінг: %s (код %d)', $status->status, $new_code);

        $new_wc_status = StatusMapper::map_to_wc($new_code);
        if ($new_wc_status && $order->get_status() !== $new_wc_status) {
            $order->update_status($new_wc_status, $note);
        } else {
            $order->add_order_note($note);
            $order->save();
        }

        if ('1' === (string) get_option('uads_notify_manager_on_status', '1')) {
            self::notify_manager($order, $status, $new_wc_status);
        }
    }

    private static function notify_manager(WC_Order $order, TrackingStatus $status, ?string $new_wc_status): void
    {
        $to = (string) get_option('uads_notify_manager_email', get_option('admin_email'));
        if ('' === $to || !is_email($to)) {
            return;
        }

        $site = (string) get_bloginfo('name');
        $subject = sprintf('[%s] НП #%s: %s',
            $site, (string) $order->get_meta('_uads_np_ttn'), $status->status);

        $admin_url = admin_url('admin.php?page=wc-orders&action=edit&id=' . $order->get_id());
        $body  = sprintf("Замовлення #%d\n", $order->get_id());
        $body .= sprintf("ТТН: %s\n", (string) $order->get_meta('_uads_np_ttn'));
        $body .= sprintf("Статус НП: %s (код %d)\n", $status->status, $status->status_code);
        if ($new_wc_status) {
            $body .= sprintf("WC статус: %s\n", $new_wc_status);
        }
        if ($status->actual_delivery_date) {
            $body .= sprintf("Доставлено: %s\n", $status->actual_delivery_date);
        }
        $body .= "\nПереглянути в адмінці: " . $admin_url;

        wp_mail($to, $subject, $body);
    }
}
