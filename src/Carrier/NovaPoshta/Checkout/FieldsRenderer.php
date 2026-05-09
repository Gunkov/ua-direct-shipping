<?php
declare(strict_types=1);

namespace Gunkov\UAShipping\Carrier\NovaPoshta\Checkout;

use Gunkov\UAShipping\Carrier\NovaPoshta\ShippingMethod\AddressMethod;
use Gunkov\UAShipping\Carrier\NovaPoshta\ShippingMethod\PoshtomatMethod;
use Gunkov\UAShipping\Carrier\NovaPoshta\ShippingMethod\WarehouseMethod;

final class FieldsRenderer
{
    public static function register(): void
    {
        add_action('woocommerce_after_checkout_billing_form', [self::class, 'render_fields_wrapper']);
        add_action('woocommerce_checkout_process', [self::class, 'validate']);
        add_action('wp_enqueue_scripts', [self::class, 'enqueue_assets']);
        add_filter('woocommerce_checkout_fields', [self::class, 'customize_checkout_fields']);
        add_filter('woocommerce_billing_fields', [self::class, 'customize_billing_fields']);
        // Hide "Ship to a different address?" checkbox when our method is active —
        // delivery target is captured by our city/warehouse/address fields, no need for separate shipping address.
        add_filter('woocommerce_cart_needs_shipping_address', [self::class, 'maybe_hide_shipping_address']);

        // Register middle name as a STANDARD WC billing field so emails/exports/admin native UI all see it
        add_filter('woocommerce_billing_fields', [self::class, 'add_middle_name_field'], 20);
        add_filter('woocommerce_admin_billing_fields', [self::class, 'add_admin_middle_name_field']);
        add_filter('woocommerce_order_formatted_billing_address', [self::class, 'inject_middle_into_address'], 10, 2);
    }

    /**
     * Insert billing_middle_name as a WC standard field between first_name and last_name.
     * Required only when address (courier) method is chosen.
     */
    public static function add_middle_name_field(array $fields): array
    {
        $is_address_required = self::is_address_method_active();

        $middle_field = [
            'label'        => __('По-батькові', 'ua-direct-shipping'),
            'placeholder'  => 'Іванович',
            'required'     => $is_address_required,
            'class'        => ['form-row-wide'],
            'priority'     => 21, // first_name=20, last_name=30
            'autocomplete' => 'additional-name',
        ];

        $new = [];
        foreach ($fields as $key => $val) {
            $new[$key] = $val;
            if ('billing_first_name' === $key) {
                $new['billing_middle_name'] = $middle_field;
            }
        }
        if (!isset($new['billing_middle_name'])) {
            $new['billing_middle_name'] = $middle_field;
        }
        return $new;
    }

    /**
     * Show billing_middle_name in admin order edit screen (as a billing-section field).
     */
    public static function add_admin_middle_name_field(array $fields): array
    {
        if (!isset($fields['middle_name'])) {
            $fields['middle_name'] = [
                'label' => __('По-батькові', 'ua-direct-shipping'),
                'show'  => true,
            ];
        }
        return $fields;
    }

    /**
     * Append middle name to the formatted billing address shown in admin order details and emails.
     */
    public static function inject_middle_into_address(array $address, $order): array
    {
        if (!$order instanceof \WC_Order) return $address;
        $middle = (string) $order->get_meta('_billing_middle_name');
        if ('' === $middle) {
            // Legacy fallback for orders created before native field was registered
            $middle = (string) $order->get_meta('_uads_np_recipient_middle_name');
        }
        if ('' !== $middle) {
            $address['first_name'] = trim((string) ($address['first_name'] ?? '') . ' ' . $middle);
        }
        return $address;
    }

    private static function is_address_method_active(): bool
    {
        if (!function_exists('WC') || !WC()->session) return false;
        $methods = WC()->session->get('chosen_shipping_methods', []);
        foreach ((array) $methods as $m) {
            if (str_starts_with((string) $m, 'uads_np_address')) return true;
        }
        return false;
    }

    public static function maybe_hide_shipping_address($needs)
    {
        if (self::is_our_method_active()) {
            return false;
        }
        return $needs;
    }

    /**
     * When our NP shipping method is active, mark standard WC billing fields that
     * we replace with our own widgets as not-required.
     *
     * Visual hiding is done via CSS (body.uads-np-active rules in checkout.css)
     * driven by checkout.js. Backend just relaxes validation so submit doesn't fail.
     *
     * @param array<string,array<string,mixed>> $fields
     * @return array<string,array<string,mixed>>
     */
    public static function customize_checkout_fields(array $fields): array
    {
        if (!self::is_our_method_active()) {
            return $fields;
        }

        $relax = ['billing_state', 'billing_postcode', 'billing_address_1',
                  'billing_address_2', 'billing_city', 'billing_phone'];
        if ('0' !== (string) get_option('uads_email_optional', '1')) {
            $relax[] = 'billing_email';
        }
        foreach ($relax as $key) {
            if (isset($fields['billing'][$key])) {
                $fields['billing'][$key]['required'] = false;
            }
            if (isset($fields['shipping'][$key])) {
                $fields['shipping'][$key]['required'] = false;
            }
        }

        return $fields;
    }

    /**
     * @param array<string,array<string,mixed>> $fields
     * @return array<string,array<string,mixed>>
     */
    public static function customize_billing_fields(array $fields): array
    {
        if (!self::is_our_method_active()) {
            return $fields;
        }
        $relax = ['billing_state', 'billing_postcode', 'billing_address_1',
                  'billing_address_2', 'billing_city', 'billing_phone'];
        if ('0' !== (string) get_option('uads_email_optional', '1')) {
            $relax[] = 'billing_email';
        }
        foreach ($relax as $key) {
            if (isset($fields[$key])) {
                $fields[$key]['required'] = false;
            }
        }
        return $fields;
    }

    private static function is_our_method_active(): bool
    {
        if (!function_exists('WC') || !WC()->session) {
            return false;
        }
        $methods = WC()->session->get('chosen_shipping_methods', []);
        foreach ((array) $methods as $m) {
            if (str_starts_with((string) $m, 'uads_np_')) {
                return true;
            }
        }
        return false;
    }

    public static function enqueue_assets(): void
    {
        if (!is_checkout() || is_order_received_page()) {
            return;
        }

        // WC core ships select2 — reuse it
        wp_enqueue_script('selectWoo');
        wp_enqueue_style('select2');

        wp_enqueue_script(
            'uads-checkout',
            UADS_URL . 'assets/js/checkout.js',
            ['jquery', 'selectWoo', 'wc-checkout'],
            UADS_VERSION,
            true
        );

        wp_enqueue_style(
            'uads-checkout',
            UADS_URL . 'assets/css/checkout.css',
            ['select2'],
            UADS_VERSION
        );

        wp_localize_script('uads-checkout', 'UADS_CHECKOUT', [
            'ajaxurl'       => admin_url('admin-ajax.php'),
            'nonce'         => AjaxController::nonce(),
            'methodIds'     => [
                'warehouse' => WarehouseMethod::ID,
                'poshtomat' => PoshtomatMethod::ID,
                'address'   => AddressMethod::ID,
            ],
            'i18n' => [
                'searchCity'      => __('Введіть назву міста...', 'ua-direct-shipping'),
                'selectCity'      => __('Спочатку оберіть місто', 'ua-direct-shipping'),
                'searchWarehouse' => __('Введіть номер або назву...', 'ua-direct-shipping'),
                'noResults'       => __('Нічого не знайдено', 'ua-direct-shipping'),
                'searching'       => __('Пошук...', 'ua-direct-shipping'),
                'phoneLabel'      => __('Телефон одержувача', 'ua-direct-shipping'),
                'phonePlaceholder'=> '+380501234567',
                'cityLabel'       => __('Місто', 'ua-direct-shipping'),
                'whLabel'         => __('Відділення', 'ua-direct-shipping'),
                'pmLabel'         => __('Поштомат', 'ua-direct-shipping'),
                'streetLabel'     => __('Вулиця', 'ua-direct-shipping'),
                'houseLabel'      => __('Будинок', 'ua-direct-shipping'),
                'flatLabel'       => __('Квартира', 'ua-direct-shipping'),
            ],
        ]);
    }

    public static function render_fields_wrapper(): void
    {
        $methods = [
            WarehouseMethod::ID => __('Нова Пошта — у відділення', 'ua-direct-shipping'),
            PoshtomatMethod::ID => __('Нова Пошта — у поштомат', 'ua-direct-shipping'),
            AddressMethod::ID   => __('Нова Пошта — Адресна', 'ua-direct-shipping'),
        ];
        $available = self::available_method_ids();
        ?>
        <div id="uads-shipping-selector" class="uads-shipping-selector">
            <p class="uads-selector-title"><strong><?php esc_html_e('Тип доставки', 'ua-direct-shipping'); ?></strong></p>
            <?php foreach ($methods as $mid => $label): ?>
                <?php if (!in_array($mid, $available, true)) continue; ?>
                <label class="uads-method-option">
                    <input type="radio" name="uads_method_radio" class="uads-method-radio" value="<?php echo esc_attr($mid); ?>" />
                    <span><?php echo esc_html($label); ?></span>
                </label>
            <?php endforeach; ?>
        </div>
        <div id="uads-checkout-fields" class="uads-checkout-fields"
             data-methods="<?php echo esc_attr(implode(',', array_keys($methods))); ?>">
            <div class="uads-loading" style="display:none;"><?php esc_html_e('Завантаження...', 'ua-direct-shipping'); ?></div>
            <div class="uads-fields-host"></div>
        </div>
        <?php
    }

    /**
     * IDs of our methods that are actually enabled in some shipping zone for the current cart.
     * @return string[]
     */
    private static function available_method_ids(): array
    {
        if (!function_exists('WC') || !WC()->shipping()) return [];
        $available = [];
        foreach (WC()->shipping()->get_packages() as $package) {
            foreach ($package['rates'] ?? [] as $rate_id => $rate) {
                $method_id = method_exists($rate, 'get_method_id') ? $rate->get_method_id() : '';
                if ('' !== $method_id && str_starts_with((string) $method_id, 'uads_np_')) {
                    $available[] = $method_id;
                }
            }
        }
        return array_values(array_unique($available));
    }

    public static function validate(): void
    {
        $chosen_methods = WC()->session ? WC()->session->get('chosen_shipping_methods', []) : [];
        if (empty($chosen_methods)) return;

        $chosen = (string) reset($chosen_methods);
        $is_ours = false;
        foreach ([WarehouseMethod::ID, PoshtomatMethod::ID, AddressMethod::ID] as $id) {
            if (str_starts_with($chosen, $id)) {
                $is_ours = true;
                break;
            }
        }
        if (!$is_ours) return;

        $city_ref = isset($_POST['uads_city_ref']) ? sanitize_text_field(wp_unslash($_POST['uads_city_ref'])) : '';
        if ('' === $city_ref) {
            wc_add_notice(__('Будь ласка, оберіть місто доставки.', 'ua-direct-shipping'), 'error');
        }

        if (str_starts_with($chosen, WarehouseMethod::ID) || str_starts_with($chosen, PoshtomatMethod::ID)) {
            $wh_ref = isset($_POST['uads_warehouse_ref']) ? sanitize_text_field(wp_unslash($_POST['uads_warehouse_ref'])) : '';
            if ('' === $wh_ref) {
                wc_add_notice(__('Оберіть відділення/поштомат Нової Пошти.', 'ua-direct-shipping'), 'error');
            }
        }

        if (str_starts_with($chosen, AddressMethod::ID)) {
            // billing_middle_name validated by WC core (registered as standard field with required=true)
            foreach (['uads_address_street_ref' => __('вулицю (виберіть з пошуку)', 'ua-direct-shipping'),
                      'uads_address_house'      => __('номер будинку', 'ua-direct-shipping')] as $key => $label) {
                if (empty($_POST[$key])) {
                    /* translators: %s: field name */
                    wc_add_notice(sprintf(__('Заповніть %s.', 'ua-direct-shipping'), $label), 'error');
                }
            }
        }

        $phone = isset($_POST['uads_recipient_phone']) ? sanitize_text_field(wp_unslash($_POST['uads_recipient_phone'])) : '';
        $digits = preg_replace('/[\s\-()]/', '', $phone) ?? '';
        // Accept +380XXXXXXXXX, 380XXXXXXXXX, 0XXXXXXXXX (Ukrainian mobile numbers)
        if (1 !== preg_match('/^(\+?38)?0\d{9}$/', $digits)) {
            wc_add_notice(__('Введіть телефон одержувача у форматі +380XXXXXXXXX або 0XXXXXXXXX.', 'ua-direct-shipping'), 'error');
        }
    }
}
