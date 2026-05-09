<?php
declare(strict_types=1);

namespace Gunkov\UAShipping\Carrier\NovaPoshta\Admin;

use Gunkov\UAShipping\Common\Logger\Logger;
use WC_Order;

final class PdfProxyController
{
    public const ACTION = 'uads_admin_print_marking';
    public const NONCE  = 'uads_admin_print';

    /** @var array<string,array{path:string,filename:string}> */
    private const FORMATS = [
        'a4' => [
            'path'     => 'printDocument/orders[]/%s/type/pdf/apiKey/%s',
            'filename' => 'ttn-%s-a4.pdf',
        ],
        '100x100' => [
            'path'     => 'printMarkings/orders[]/%s/type/pdf/apiKey/%s',
            'filename' => 'ttn-%s-100x100.pdf',
        ],
        '85x85' => [
            'path'     => 'printMarking85x85/orders[]/%s/type/pdf/apiKey/%s',
            'filename' => 'ttn-%s-85x85.pdf',
        ],
    ];

    public static function register(): void
    {
        add_action('wp_ajax_' . self::ACTION, [self::class, 'handle']);
    }

    public static function url(int $order_id, string $format): string
    {
        return add_query_arg([
            'action'   => self::ACTION,
            'order_id' => $order_id,
            'format'   => $format,
            '_wpnonce' => wp_create_nonce(self::NONCE),
        ], admin_url('admin-ajax.php'));
    }

    public static function handle(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Forbidden', 'ua-direct-shipping'), '', ['response' => 403]);
        }

        $nonce = isset($_GET['_wpnonce']) ? (string) $_GET['_wpnonce'] : '';
        if (!wp_verify_nonce($nonce, self::NONCE)) {
            wp_die(esc_html__('Bad nonce', 'ua-direct-shipping'), '', ['response' => 403]);
        }

        $order_id = isset($_GET['order_id']) ? (int) $_GET['order_id'] : 0;
        $format   = isset($_GET['format']) ? (string) $_GET['format'] : 'a4';

        if (!isset(self::FORMATS[$format])) {
            wp_die(esc_html__('Unknown format', 'ua-direct-shipping'), '', ['response' => 400]);
        }

        $order = wc_get_order($order_id);
        if (!$order instanceof WC_Order) {
            wp_die(esc_html__('Order not found', 'ua-direct-shipping'), '', ['response' => 404]);
        }

        $ttn = (string) $order->get_meta('_uads_np_ttn');
        if ('' === $ttn) {
            wp_die(esc_html__('No TTN attached to this order', 'ua-direct-shipping'), '', ['response' => 404]);
        }

        $api_key = (string) get_option('uads_np_api_key', '');
        if ('' === $api_key) {
            wp_die(esc_html__('NP API key not configured', 'ua-direct-shipping'), '', ['response' => 500]);
        }

        $cfg = self::FORMATS[$format];
        $url = 'https://my.novaposhta.ua/orders/' . sprintf($cfg['path'], rawurlencode($ttn), rawurlencode($api_key));

        // NP often returns 200 with non-PDF body if requested immediately after InternetDocument.save —
        // PDF generation lags by ~1-2 sec. Retry up to 3 times with 1.5s delay between attempts.
        $max_attempts = 3;
        $delay_us     = 1_500_000;
        $body         = '';
        $code         = 0;
        $last_error   = null;

        for ($attempt = 1; $attempt <= $max_attempts; $attempt++) {
            $response = wp_remote_get($url, [
                'timeout'     => 30,
                'redirection' => 3,
            ]);

            if (is_wp_error($response)) {
                $last_error = $response->get_error_message();
                Logger::instance()->warning('PDF proxy transport error, retrying', [
                    'order' => $order_id, 'format' => $format, 'attempt' => $attempt, 'error' => $last_error,
                ]);
                if ($attempt < $max_attempts) {
                    usleep($delay_us);
                    continue;
                }
                Logger::instance()->error('PDF proxy failed after retries', ['order' => $order_id, 'error' => $last_error]);
                wp_die(esc_html__('Failed to fetch PDF from NP', 'ua-direct-shipping'), '', ['response' => 502]);
            }

            $code = (int) wp_remote_retrieve_response_code($response);
            $body = (string) wp_remote_retrieve_body($response);

            if (200 === $code && strncmp($body, '%PDF-', 5) === 0) {
                if ($attempt > 1) {
                    Logger::instance()->info('PDF proxy succeeded on retry', ['order' => $order_id, 'attempt' => $attempt]);
                }
                break;
            }

            // 4xx — fatal, no retry (invalid TTN / no permission)
            if ($code >= 400 && $code < 500) {
                Logger::instance()->error('PDF proxy 4xx response', [
                    'order' => $order_id, 'format' => $format, 'code' => $code,
                    'body_head' => substr($body, 0, 200),
                ]);
                wp_die(esc_html__('NP rejected request (TTN may be invalid).', 'ua-direct-shipping'), '', ['response' => 502]);
            }

            // 200 but body is not PDF — likely PDF still generating, retry
            if ($attempt < $max_attempts) {
                Logger::instance()->info('PDF proxy non-PDF body, retrying', [
                    'order' => $order_id, 'format' => $format, 'attempt' => $attempt, 'code' => $code,
                    'body_head' => substr($body, 0, 100),
                ]);
                usleep($delay_us);
                continue;
            }

            Logger::instance()->error('PDF proxy non-PDF after retries', [
                'order' => $order_id, 'format' => $format, 'code' => $code,
                'body_head' => substr($body, 0, 200),
            ]);
            wp_die(esc_html__('NP returned non-PDF after retries (TTN may be invalid or generation slow).', 'ua-direct-shipping'), '', ['response' => 502]);
        }

        nocache_headers();
        header('Content-Type: application/pdf');
        header('Content-Length: ' . strlen($body));
        header('Content-Disposition: inline; filename="' . sprintf($cfg['filename'], $ttn) . '"');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        echo $body;
        exit;
    }
}
