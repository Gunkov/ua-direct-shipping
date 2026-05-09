<?php
declare(strict_types=1);

namespace Gunkov\UAShipping\Carrier\NovaPoshta\Checkout;

use Gunkov\UAShipping\Carrier\NovaPoshta\ShippingMethod\AddressMethod;
use Gunkov\UAShipping\Carrier\NovaPoshta\ShippingMethod\PoshtomatMethod;
use Gunkov\UAShipping\Carrier\NovaPoshta\ShippingMethod\WarehouseMethod;
use WC_Order;

final class OrderHandler
{
    public static function register(): void
    {
        add_action('woocommerce_checkout_create_order', [self::class, 'save_order_meta'], 10, 2);
        add_action('woocommerce_checkout_update_order_review', [self::class, 'capture_city_ref_for_rates']);
        add_action('woocommerce_before_checkout_form', [self::class, 'reset_stale_city_ref'], 5);
        // Show our meta in admin order details (under Billing address block)
        add_action('woocommerce_admin_order_data_after_billing_address', [self::class, 'render_admin_meta_summary']);
    }

    /**
     * Render NP shipping summary (middle name, recipient phone, method) in admin order edit screen.
     */
    public static function render_admin_meta_summary($order): void
    {
        if (!$order instanceof \WC_Order) return;
        $method = (string) $order->get_meta('_uads_np_method');
        if ('' === $method) return;

        $phone  = (string) $order->get_meta('_uads_np_recipient_phone');
        $city   = (string) $order->get_meta('_uads_np_city_name');
        $whouse = (string) $order->get_meta('_uads_np_warehouse_description');
        $street = (string) $order->get_meta('_uads_np_address_street');
        $house  = (string) $order->get_meta('_uads_np_address_house');
        $flat   = (string) $order->get_meta('_uads_np_address_flat');

        $method_labels = [
            'uads_np_warehouse' => __('у відділення', 'ua-direct-shipping'),
            'uads_np_poshtomat' => __('у поштомат', 'ua-direct-shipping'),
            'uads_np_address'   => __('адресна (кур\'єр)', 'ua-direct-shipping'),
        ];
        ?>
        <div class="uads-admin-meta" style="margin-top: 1em; padding: 8px 10px; background: #f7f7f7; border-left: 3px solid #2271b1;">
            <h4 style="margin: 0 0 6px 0;">📦 <?php esc_html_e('Нова Пошта', 'ua-direct-shipping'); ?></h4>
            <p style="margin: 0 0 4px 0;">
                <strong><?php esc_html_e('Метод:', 'ua-direct-shipping'); ?></strong>
                <?php echo esc_html($method_labels[$method] ?? $method); ?>
            </p>
            <?php /* billing_middle_name тепер у WC native блоці "Платіжна адреса" — нам не треба його тут дублювати */ ?>
            <?php if ($city): ?>
                <p style="margin: 0 0 4px 0;">
                    <strong><?php esc_html_e('Місто:', 'ua-direct-shipping'); ?></strong>
                    <?php echo esc_html($city); ?>
                </p>
            <?php endif; ?>
            <?php if ($whouse): ?>
                <p style="margin: 0 0 4px 0;">
                    <strong><?php esc_html_e('Відділення:', 'ua-direct-shipping'); ?></strong>
                    <?php echo esc_html($whouse); ?>
                </p>
            <?php endif; ?>
            <?php if ($street): ?>
                <p style="margin: 0 0 4px 0;">
                    <strong><?php esc_html_e('Адреса:', 'ua-direct-shipping'); ?></strong>
                    <?php echo esc_html(trim($street . ', ' . $house . ($flat ? ', кв. ' . $flat : ''))); ?>
                </p>
            <?php endif; ?>
            <?php if ($phone): ?>
                <p style="margin: 0;">
                    <strong><?php esc_html_e('Телефон одержувача:', 'ua-direct-shipping'); ?></strong>
                    <?php echo esc_html($phone); ?>
                </p>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * On initial GET /checkout/ load — clear any stale recipient city ref left in session
     * from previous checkout sessions. Without this WC keeps showing a price calculated
     * for the previous city even though the user hasn't picked one yet on this visit.
     *
     * Only fires on real page load — AJAX update_checkout posts run their own filter
     * (`woocommerce_checkout_update_order_review`) and re-set the city ref freshly.
     */
    public static function reset_stale_city_ref(): void
    {
        if (!WC()->session) return;
        if (defined('DOING_AJAX') && DOING_AJAX) return;
        WC()->session->set('uads_chosen_city_ref', '');

        // First visit (mobile, fresh session): pre-pick first available NP method as default
        // so checkout-fields filter knows our method is active and hides standard WC fields immediately.
        $chosen = (array) WC()->session->get('chosen_shipping_methods', []);
        $has_ours = false;
        foreach ($chosen as $m) {
            if (is_string($m) && str_starts_with($m, 'uads_np_')) { $has_ours = true; break; }
        }
        if (!$has_ours && function_exists('WC') && WC()->shipping()) {
            foreach (WC()->shipping()->get_packages() as $i => $package) {
                foreach (($package['rates'] ?? []) as $rate_id => $rate) {
                    if (str_starts_with((string) $rate_id, 'uads_np_')) {
                        $chosen[$i] = $rate_id;
                        break;
                    }
                }
            }
            if (!empty($chosen)) {
                WC()->session->set('chosen_shipping_methods', $chosen);
            }
        }
    }

    /**
     * Capture chosen city_ref to WC session — used by AbstractMethod::cost_api_or_fallback
     * for real-time NP getDocumentPrice (recipient city).
     */
    public static function capture_city_ref_for_rates(string $post_data): void
    {
        if (!WC()->session) return;
        parse_str($post_data, $parsed);
        $city_ref = isset($parsed['uads_city_ref']) ? sanitize_text_field((string) $parsed['uads_city_ref']) : '';
        if (1 === preg_match('/^[a-f0-9-]{36}$/i', $city_ref)) {
            WC()->session->set('uads_chosen_city_ref', $city_ref);
        }
    }

    public static function save_order_meta(WC_Order $order, array $data): void
    {
        $shipping_methods = $order->get_shipping_methods();
        if (empty($shipping_methods)) return;

        /** @var \WC_Order_Item_Shipping $first */
        $first = reset($shipping_methods);
        $method_id = $first->get_method_id();

        $is_ours = in_array($method_id, [WarehouseMethod::ID, PoshtomatMethod::ID, AddressMethod::ID], true);
        if (!$is_ours) return;

        $post = self::get_posted();

        $order->update_meta_data('_uads_np_method', $method_id);
        $order->update_meta_data('_uads_np_city_ref', $post['city_ref']);
        $order->update_meta_data('_uads_np_city_name', $post['city_name']);
        $order->update_meta_data('_uads_np_recipient_phone', $post['phone']);

        // Save real shipping cost from session (when hidden from checkout)
        if (WC()->session) {
            $est = (float) WC()->session->get('uads_estimated_cost_' . $method_id, 0);
            if ($est > 0) {
                $order->update_meta_data('_uads_np_estimated_shipping_cost', (string) $est);
            }
        }

        if ('' !== $post['phone'] && '' === (string) $order->get_billing_phone()) {
            $order->set_billing_phone($post['phone']);
        }

        // Email optional — leave empty rather than inject a placeholder. Just flag for admin.
        if ('0' !== (string) get_option('uads_email_optional', '1') && '' === (string) $order->get_billing_email()) {
            $order->update_meta_data('_uads_np_no_email', '1');
        }

        if ('' !== $post['city_name']) {
            $order->set_billing_city($post['city_name']);
        }

        // Copy billing → shipping so admin "Адреса доставки" is populated.
        // We hid the "Ship to different address" checkbox, so WC never built shipping address itself.
        $order->set_shipping_first_name((string) $order->get_billing_first_name());
        $order->set_shipping_last_name((string) $order->get_billing_last_name());
        $order->set_shipping_country((string) ($order->get_billing_country() ?: 'UA'));
        if ('' !== $post['city_name']) {
            $order->set_shipping_city($post['city_name']);
        }
        if ('' !== $post['phone']) {
            // WC core supports billing_phone; shipping_phone is also available since WC 5.6
            if (method_exists($order, 'set_shipping_phone')) {
                $order->set_shipping_phone($post['phone']);
            }
        }

        if ($method_id === WarehouseMethod::ID || $method_id === PoshtomatMethod::ID) {
            $order->update_meta_data('_uads_np_warehouse_ref', $post['warehouse_ref']);
            $order->update_meta_data('_uads_np_warehouse_number', $post['warehouse_number']);
            $order->update_meta_data('_uads_np_warehouse_description', $post['warehouse_description']);

            $address_line = $post['warehouse_description'] !== ''
                ? $post['warehouse_description']
                : sprintf('Відділення №%s', $post['warehouse_number']);
            $order->set_billing_address_1($address_line);
            $order->set_shipping_address_1($address_line);
        }

        if ($method_id === AddressMethod::ID) {
            $order->update_meta_data('_uads_np_address_street', $post['street_name']);
            $order->update_meta_data('_uads_np_address_street_ref', $post['street_ref']);
            $order->update_meta_data('_uads_np_address_house', $post['house']);
            $order->update_meta_data('_uads_np_address_flat', $post['flat']);

            $address_line = trim(sprintf('%s, %s%s',
                $post['street_name'] !== '' ? $post['street_name'] : 'вул.',
                $post['house'],
                $post['flat'] !== '' ? ', кв. ' . $post['flat'] : ''
            ));
            $order->set_billing_address_1($address_line);
            $order->set_shipping_address_1($address_line);
        }

    }

    /**
     * @return array{city_ref:string,city_name:string,warehouse_ref:string,warehouse_number:string,warehouse_description:string,street:string,house:string,flat:string,phone:string}
     */
    private static function get_posted(): array
    {
        $get = static fn (string $k): string =>
            isset($_POST[$k]) ? sanitize_text_field(wp_unslash((string) $_POST[$k])) : '';

        return [
            'city_ref'              => $get('uads_city_ref'),
            'city_name'             => $get('uads_city_name'),
            'warehouse_ref'         => $get('uads_warehouse_ref'),
            'warehouse_number'      => $get('uads_warehouse_number'),
            'warehouse_description' => $get('uads_warehouse_description'),
            'street_ref'            => $get('uads_address_street_ref'),
            'street_name'           => $get('uads_address_street_name'),
            'house'                 => $get('uads_address_house'),
            'flat'                  => $get('uads_address_flat'),
            'phone'                 => $get('uads_recipient_phone'),
            // middle_name is now a standard WC field (billing_middle_name) — saved by WC core, not us
        ];
    }
}
