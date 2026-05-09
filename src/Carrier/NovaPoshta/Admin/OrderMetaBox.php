<?php
declare(strict_types=1);

namespace Gunkov\UAShipping\Carrier\NovaPoshta\Admin;

use Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController;
use Gunkov\UAShipping\Carrier\NovaPoshta\ShippingMethod\AddressMethod;
use Gunkov\UAShipping\Carrier\NovaPoshta\ShippingMethod\PoshtomatMethod;
use Gunkov\UAShipping\Carrier\NovaPoshta\ShippingMethod\WarehouseMethod;
use WC_Order;

final class OrderMetaBox
{
    private const NONCE = 'uads_admin_ttn';

    public static function register(): void
    {
        add_action('add_meta_boxes', [self::class, 'add_metabox']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_assets']);

        add_action('wp_ajax_uads_admin_dryrun_ttn',  [self::class, 'ajax_dryrun']);
        add_action('wp_ajax_uads_admin_create_ttn',  [self::class, 'ajax_create']);
        add_action('wp_ajax_uads_admin_delete_ttn',  [self::class, 'ajax_delete']);
    }

    public static function add_metabox(): void
    {
        // HPOS screen + legacy
        $screens = ['shop_order'];
        if (function_exists('wc_get_page_screen_id')) {
            $screens[] = wc_get_page_screen_id('shop-order');
        }

        foreach (array_unique($screens) as $screen) {
            add_meta_box(
                'uads-np-ttn',
                __('Нова Пошта — ТТН', 'ua-direct-shipping'),
                [self::class, 'render'],
                $screen,
                'side',
                'high'
            );
        }
    }

    public static function enqueue_assets(string $hook): void
    {
        if (!in_array($hook, ['post.php', 'post-new.php', 'woocommerce_page_wc-orders'], true)) {
            return;
        }

        wp_enqueue_script(
            'uads-admin-ttn',
            UADS_URL . 'assets/js/admin-ttn.js',
            ['jquery'],
            UADS_VERSION,
            true
        );

        wp_localize_script('uads-admin-ttn', 'UADS_TTN', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce(self::NONCE),
            'i18n'    => [
                'confirmCreate' => __('Створити реальну ТТН у кабінеті НП? Це вже не dry-run.', 'ua-direct-shipping'),
                'confirmDelete' => __('Видалити цю ТТН? Можливо лише поки посилка не зареєстрована на пошті.', 'ua-direct-shipping'),
                'dryRunRunning' => __('Готую payload...', 'ua-direct-shipping'),
                'creating'      => __('Створюю ТТН в НП...', 'ua-direct-shipping'),
                'deleting'      => __('Видаляю ТТН...', 'ua-direct-shipping'),
            ],
        ]);

        wp_enqueue_style(
            'uads-admin-ttn-css',
            UADS_URL . 'assets/css/admin.css',
            [],
            UADS_VERSION
        );
    }

    public static function render($post_or_order): void
    {
        $order = $post_or_order instanceof WC_Order
            ? $post_or_order
            : ($post_or_order instanceof \WP_Post ? wc_get_order($post_or_order->ID) : null);

        if (!$order instanceof WC_Order) {
            echo '<p>' . esc_html__('Order not found.', 'ua-direct-shipping') . '</p>';
            return;
        }

        $method_id = (string) $order->get_meta('_uads_np_method');
        $is_ours = in_array($method_id, [WarehouseMethod::ID, PoshtomatMethod::ID, AddressMethod::ID], true);

        if (!$is_ours) {
            echo '<p>' . esc_html__('Це замовлення не використовує наш метод доставки.', 'ua-direct-shipping') . '</p>';
            return;
        }

        $ttn = (string) $order->get_meta('_uads_np_ttn');
        if ('' !== $ttn) {
            self::render_existing_ttn($order);
            return;
        }

        self::render_create_form($order, $method_id);
    }

    private static function render_existing_ttn(WC_Order $order): void
    {
        $ttn  = (string) $order->get_meta('_uads_np_ttn');
        $cost = (string) $order->get_meta('_uads_np_ttn_cost');
        $eta  = (string) $order->get_meta('_uads_np_ttn_estimated_date');
        $created = (string) $order->get_meta('_uads_np_ttn_created_at');
        $oid = (int) $order->get_id();
        ?>
        <div class="uads-ttn-existing" data-order-id="<?php echo esc_attr((string) $oid); ?>">
            <p>
                <strong>✅ ТТН створено</strong><br />
                <code style="font-size: 1.2em;"><?php echo esc_html($ttn); ?></code>
            </p>
            <p>
                <?php /* translators: 1: cost on site, 2: estimated date, 3: created at */ ?>
                <?php printf(
                    esc_html__('Вартість на сайті: %1$s ₴ | Орієнт. доставка: %2$s | Створено: %3$s UTC', 'ua-direct-shipping'),
                    esc_html($cost),
                    esc_html($eta),
                    esc_html($created)
                ); ?>
            </p>
            <p style="display:flex; gap: 6px; flex-wrap: wrap;">
                <a href="<?php echo esc_url(PdfProxyController::url($oid, 'a4')); ?>" target="_blank" rel="noopener" class="button">
                    🖨 A4
                </a>
                <a href="<?php echo esc_url(PdfProxyController::url($oid, '100x100')); ?>" target="_blank" rel="noopener" class="button">
                    🖨 100×100
                </a>
                <a href="<?php echo esc_url(PdfProxyController::url($oid, '85x85')); ?>" target="_blank" rel="noopener" class="button">
                    🖨 85×85
                </a>
            </p>
            <p>
                <button type="button" class="button button-secondary uads-ttn-delete">
                    🗑 <?php esc_html_e('Видалити ТТН', 'ua-direct-shipping'); ?>
                </button>
            </p>
            <div class="uads-ttn-result" style="margin-top: 1em;"></div>
        </div>
        <?php
    }

    private static function render_create_form(WC_Order $order, string $method_id): void
    {
        $weight = 0.0;
        $max_l = $max_w = $max_h = 0.0;
        $weight_from_product = false;
        $dims_from_product   = false;
        foreach ($order->get_items() as $item) {
            if (!method_exists($item, 'get_product')) continue;
            $p = $item->get_product();
            if (!$p instanceof \WC_Product) continue;
            $qty = (int) $item->get_quantity();
            $w   = (float) $p->get_weight();
            if ($w > 0) $weight_from_product = true;
            $weight += $w * $qty;

            $pl = (float) $p->get_length();
            $pw = (float) $p->get_width();
            $ph = (float) $p->get_height();
            if ($pl > 0 || $pw > 0 || $ph > 0) $dims_from_product = true;
            $max_l = max($max_l, $pl);
            $max_w = max($max_w, $pw);
            $max_h = max($max_h, $ph);
        }
        // Defaults if no product dimensions
        if ($max_l <= 0) $max_l = 30;
        if ($max_w <= 0) $max_w = 20;
        if ($max_h <= 0) $max_h = 10;
        if ($weight <= 0) $weight = 0.5;
        $estimated_cost = (float) $order->get_meta('_uads_np_estimated_shipping_cost');

        // Auto-detect cash-on-delivery from WC payment method.
        // If user paid in advance (LiqPay/Monobank/BACS) — COD off; if 'cod' gateway — COD on with order total.
        $payment_method = (string) $order->get_payment_method();
        $is_cod = ('cod' === $payment_method);
        $cod_default_amount = $is_cod ? (float) $order->get_total() : 0.0;

        $sender_set = '' !== get_option('uads_np_default_sender_ref', '') &&
                      '' !== get_option('uads_np_default_sender_warehouse_ref', '');

        ?>
        <div class="uads-ttn-create" data-order-id="<?php echo esc_attr((string) $order->get_id()); ?>">
            <?php if (!$sender_set): ?>
                <p style="color: #b32d2e;">
                    ⚠️ <?php esc_html_e('Не налаштований Sender в WC → UA Direct Shipping settings. Створення ТТН неможливе.', 'ua-direct-shipping'); ?>
                </p>
                <p>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=ua-direct-shipping')); ?>" class="button">
                        <?php esc_html_e('Перейти в settings', 'ua-direct-shipping'); ?>
                    </a>
                </p>
                <?php return; ?>
            <?php endif; ?>

            <p>
                <label><?php esc_html_e('Тип послуги', 'ua-direct-shipping'); ?></label>
                <select name="service_type" class="widefat">
                    <option value="WarehouseWarehouse" <?php selected($method_id, WarehouseMethod::ID); ?>>WarehouseWarehouse</option>
                    <option value="WarehouseDoors" <?php selected($method_id, AddressMethod::ID); ?>>WarehouseDoors</option>
                    <option value="WarehousePostomat" <?php selected($method_id, PoshtomatMethod::ID); ?>>WarehousePostomat</option>
                </select>
            </p>
            <p>
                <label><?php esc_html_e('Тип вантажу', 'ua-direct-shipping'); ?></label>
                <select name="cargo_type" class="widefat">
                    <option value="Cargo">Cargo</option>
                    <option value="Documents">Documents</option>
                    <option value="Parcel">Parcel</option>
                </select>
            </p>
            <p>
                <label><?php esc_html_e('Вага фактична, кг', 'ua-direct-shipping'); ?></label>
                <input type="number" name="weight" step="0.1" min="0.1" value="<?php echo esc_attr((string) max(0.1, $weight)); ?>" class="widefat uads-w-actual" />
                <?php if ($weight_from_product): ?>
                    <small style="color:#46b450;"><?php esc_html_e('✓ з картки товару', 'ua-direct-shipping'); ?></small>
                <?php else: ?>
                    <small style="color:#b88b00;"><?php esc_html_e('⚠ за замовчуванням (вага в товарі не заповнена)', 'ua-direct-shipping'); ?></small>
                <?php endif; ?>
            </p>
            <p style="margin-bottom:2px;">
                <?php esc_html_e('Габарити (см)', 'ua-direct-shipping'); ?>
                <?php if ($dims_from_product): ?>
                    <small style="color:#46b450;"><?php esc_html_e('✓ з картки товару', 'ua-direct-shipping'); ?></small>
                <?php else: ?>
                    <small style="color:#b88b00;"><?php esc_html_e('⚠ за замовчуванням (заповніть габарити в товарі)', 'ua-direct-shipping'); ?></small>
                <?php endif; ?>
            </p>
            <p style="display:flex; gap:6px; margin-top:0;">
                <span style="flex:1;">
                    <label style="font-size:0.85em; color:#666;"><?php esc_html_e('Довжина', 'ua-direct-shipping'); ?></label>
                    <input type="number" name="length_cm" step="1" min="1" value="<?php echo esc_attr((string) (int) $max_l); ?>" class="widefat uads-dim" />
                </span>
                <span style="flex:1;">
                    <label style="font-size:0.85em; color:#666;"><?php esc_html_e('Ширина', 'ua-direct-shipping'); ?></label>
                    <input type="number" name="width_cm" step="1" min="1" value="<?php echo esc_attr((string) (int) $max_w); ?>" class="widefat uads-dim" />
                </span>
                <span style="flex:1;">
                    <label style="font-size:0.85em; color:#666;"><?php esc_html_e('Висота', 'ua-direct-shipping'); ?></label>
                    <input type="number" name="height_cm" step="1" min="1" value="<?php echo esc_attr((string) (int) $max_h); ?>" class="widefat uads-dim" />
                </span>
            </p>
            <p style="background:#f7f7f7; padding:6px 8px; font-size:0.85em; margin:0;">
                <?php esc_html_e('Об\'ємна вага:', 'ua-direct-shipping'); ?>
                <span class="uads-vol-weight">—</span> кг.
                <?php esc_html_e('Розрахункова вага НП =', 'ua-direct-shipping'); ?>
                <strong class="uads-billable-weight">—</strong> кг.
            </p>
            <p>
                <label><?php esc_html_e('Кількість місць', 'ua-direct-shipping'); ?></label>
                <input type="number" name="seats" min="1" value="1" class="widefat" />
            </p>
            <?php if ($estimated_cost > 0): ?>
                <p style="font-size:0.85em; color:#666; margin:0 0 6px;">
                    <?php
                    /* translators: %s: estimated NP shipping cost in UAH */
                    printf(esc_html__('Оцінка НП на checkout: %s ₴', 'ua-direct-shipping'), esc_html((string) $estimated_cost));
                    ?>
                </p>
            <?php endif; ?>
            <p>
                <label><?php esc_html_e('Опис', 'ua-direct-shipping'); ?></label>
                <input type="text" name="description" value="<?php echo esc_attr(sprintf('Замовлення №%d', $order->get_id())); ?>" class="widefat" />
            </p>
            <p>
                <label><?php esc_html_e('Оголошена вартість, ₴', 'ua-direct-shipping'); ?></label>
                <input type="number" name="declared_cost" step="0.01" min="200" value="<?php echo esc_attr((string) max(200.0, (float) $order->get_subtotal())); ?>" class="widefat" />
            </p>
            <p>
                <label>
                    <input type="checkbox" name="enable_cod" <?php checked($is_cod); ?> />
                    <?php esc_html_e('Накладений платіж', 'ua-direct-shipping'); ?>
                </label>
                <input type="number" name="cod_amount" step="0.01" min="0" value="<?php echo esc_attr((string) $cod_default_amount); ?>" class="widefat uads-cod-amount" <?php disabled(!$is_cod); ?> />
                <small style="color:#666;">
                    <?php
                    /* translators: %s: WC payment method id (cod / bacs / liqpay / etc) */
                    printf(esc_html__('Метод оплати у WC: %s', 'ua-direct-shipping'), '<code>' . esc_html($payment_method ?: '—') . '</code>');
                    ?>
                </small>
            </p>

            <hr />

            <p>
                <button type="button" class="button button-secondary uads-ttn-dryrun">
                    🔍 <?php esc_html_e('Перегляд payload (dry-run)', 'ua-direct-shipping'); ?>
                </button>
            </p>

            <div class="uads-dryrun-output" style="display:none; margin-top: 1em;"></div>

            <p style="margin-top: 1em;">
                <button type="button" class="button button-primary uads-ttn-create-real">
                    🚚 <?php esc_html_e('Створити ТТН', 'ua-direct-shipping'); ?>
                </button>
            </p>

            <div class="uads-ttn-result" style="margin-top: 1em;"></div>
        </div>
        <?php
    }

    public static function ajax_dryrun(): void
    {
        self::check_ajax();
        $order = self::get_order_from_request();

        $form_input = self::collect_form_input();
        $creator = TtnCreator::from_settings();
        if (!$creator) {
            wp_send_json_error(['message' => 'API key not configured']);
        }

        $payload = $creator->build_payload($order, $form_input);
        $errors  = $creator->validate_payload($payload);

        // Best-effort live NP rate estimation — no TTN created, just price calc
        $rate = null;
        $cod_fee = null;
        $rate_error = null;
        if (empty($errors)) {
            try {
                $api_key = (string) get_option('uads_np_api_key', '');
                if ('' !== $api_key) {
                    $client = new \Gunkov\UAShipping\Carrier\NovaPoshta\ApiClient(
                        $api_key,
                        new \Gunkov\UAShipping\Common\Http\HttpClient(),
                        \Gunkov\UAShipping\Common\Logger\Logger::instance()
                    );
                    $price_request = [
                        'CitySender'    => (string) ($payload['CitySender'] ?? ''),
                        'CityRecipient' => (string) ($payload['CityRecipient'] ?? ''),
                        'Weight'        => (string) ($payload['Weight'] ?? '1'),
                        'Cost'          => (string) ($payload['Cost'] ?? '200'),
                        'ServiceType'   => (string) ($payload['ServiceType'] ?? 'WarehouseWarehouse'),
                    ];
                    // If COD is enabled — ask NP to also calculate the redelivery (money transfer) fee
                    if (!empty($payload['BackwardDeliveryData'][0]['RedeliveryString'])) {
                        $price_request['RedeliveryCalculate'] = [
                            'CargoType' => 'Money',
                            'Amount'    => (string) $payload['BackwardDeliveryData'][0]['RedeliveryString'],
                        ];
                    }
                    $price = $client->get_document_price($price_request);
                    $rate = (float) $price['cost'];
                    $cod_fee = (float) $price['cost_redelivery'];
                }
            } catch (\Throwable $e) {
                $rate_error = $e->getMessage();
            }
        }

        wp_send_json_success([
            'payload'    => $payload,
            'errors'     => $errors,
            'valid'      => empty($errors),
            'rate'       => $rate,
            'cod_fee'    => $cod_fee,
            'rate_error' => $rate_error,
        ]);
    }

    public static function ajax_create(): void
    {
        self::check_ajax();
        $order = self::get_order_from_request();

        $form_input = self::collect_form_input();
        $creator = TtnCreator::from_settings();
        if (!$creator) {
            wp_send_json_error(['message' => 'API key not configured']);
        }

        $payload = $creator->build_payload($order, $form_input);
        $errors  = $creator->validate_payload($payload);
        if (!empty($errors)) {
            wp_send_json_error(['message' => 'Validation failed', 'errors' => $errors]);
        }

        try {
            $result = $creator->create($order, $payload);
            wp_send_json_success([
                'ttn'  => $result['IntDocNumber'],
                'ref'  => $result['Ref'],
                'cost' => $result['CostOnSite'],
                'eta'  => $result['EstimatedDeliveryDate'],
            ]);
        } catch (\Throwable $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    public static function ajax_delete(): void
    {
        self::check_ajax();
        $order = self::get_order_from_request();

        $creator = TtnCreator::from_settings();
        if (!$creator) {
            wp_send_json_error(['message' => 'API key not configured']);
        }

        try {
            $ok = $creator->delete($order);
            if ($ok) {
                wp_send_json_success(['message' => __('ТТН видалено', 'ua-direct-shipping')]);
            }
            wp_send_json_error(['message' => 'Delete failed']);
        } catch (\Throwable $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    private static function check_ajax(): void
    {
        check_ajax_referer(self::NONCE);
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Forbidden'], 403);
        }
    }

    private static function get_order_from_request(): WC_Order
    {
        $order_id = isset($_REQUEST['order_id']) ? (int) $_REQUEST['order_id'] : 0;
        $order = wc_get_order($order_id);
        if (!$order instanceof WC_Order) {
            wp_send_json_error(['message' => 'Invalid order']);
        }
        return $order;
    }

    /**
     * @return array<string,mixed>
     */
    private static function collect_form_input(): array
    {
        $get = static fn (string $k, mixed $default = ''): mixed =>
            isset($_REQUEST[$k]) ? sanitize_text_field(wp_unslash((string) $_REQUEST[$k])) : $default;

        $cod_enabled = !empty($_REQUEST['enable_cod']);
        return [
            'service_type'    => $get('service_type'),
            'cargo_type'      => $get('cargo_type', 'Cargo'),
            'weight'          => (float) $get('weight', 1),
            'length_cm'       => (float) $get('length_cm', 30),
            'width_cm'        => (float) $get('width_cm', 20),
            'height_cm'       => (float) $get('height_cm', 10),
            'seats'           => (int) $get('seats', 1),
            'description'     => $get('description'),
            'declared_cost'   => (float) $get('declared_cost', 200),
            'cod_amount'      => $cod_enabled ? (float) $get('cod_amount', 0) : 0.0,
        ];
    }
}
