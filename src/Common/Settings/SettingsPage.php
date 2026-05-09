<?php
declare(strict_types=1);

namespace Gunkov\UAShipping\Common\Settings;

final class SettingsPage
{
    private const MENU_SLUG  = 'ua-direct-shipping';
    private const NONCE_NAME = 'uads_test_connection';

    /** Saved hook suffix from add_submenu_page() — used for strict admin_enqueue_scripts check. */
    private static string $page_hook = '';

    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'add_menu']);
        add_action('admin_init', [self::class, 'register_settings']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_assets']);
        add_action('wp_ajax_uads_admin_trigger_sync', [self::class, 'ajax_trigger_sync']);
    }

    public static function ajax_trigger_sync(): void
    {
        check_ajax_referer('uads_trigger_sync');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Forbidden', 'ua-direct-shipping')], 403);
        }

        $status = \Gunkov\UAShipping\Carrier\NovaPoshta\Cron\WarehouseSyncCron::get_status();
        if (!empty($status['running'])) {
            wp_send_json_error(['message' => __('Sync вже виконується. Дочекайтесь завершення.', 'ua-direct-shipping')]);
        }

        // Schedule one-shot cron + spawn it asynchronously
        wp_schedule_single_event(time() + 1, \Gunkov\UAShipping\Carrier\NovaPoshta\Cron\WarehouseSyncCron::HOOK);
        spawn_cron();

        wp_send_json_success([
            'message' => __('Sync запущено у фоні. Прогрес — у Telegram (@AIGunkovbot). Триватиме ~30-60 хв. Сторінку можна закрити.', 'ua-direct-shipping'),
        ]);
    }

    public static function add_menu(): void
    {
        // Save the hook handle that WordPress generates — using it directly avoids
        // the "parent menu title can be translated" trap from Trac #18857.
        self::$page_hook = (string) add_submenu_page(
            'woocommerce',
            __('UA Direct Shipping', 'ua-direct-shipping'),
            __('UA Direct Shipping', 'ua-direct-shipping'),
            'manage_woocommerce',
            self::MENU_SLUG,
            [self::class, 'render']
        );
    }

    public static function register_settings(): void
    {
        register_setting('uads_settings_general', 'uads_np_api_key', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => '',
        ]);

        register_setting('uads_settings_general', 'uads_debug_mode', [
            'type'              => 'boolean',
            'sanitize_callback' => static fn ($v): bool => (bool) $v,
            'default'           => false,
        ]);

        register_setting('uads_settings_general', 'uads_np_default_sender_city_ref', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => '',
        ]);

        register_setting('uads_settings_general', 'uads_hide_shipping_cost', [
            'type'              => 'string',
            'sanitize_callback' => static fn ($v): string => '1' === (string) $v ? '1' : '0',
            'default'           => '1',
        ]);

        register_setting('uads_settings_general', 'uads_email_optional', [
            'type'              => 'string',
            'sanitize_callback' => static fn ($v): string => '1' === (string) $v ? '1' : '0',
            'default'           => '1',
        ]);

        register_setting('uads_settings_general', 'uads_notify_manager_on_status', [
            'type'              => 'string',
            'sanitize_callback' => static fn ($v): string => '1' === (string) $v ? '1' : '0',
            'default'           => '1',
        ]);

        register_setting('uads_settings_general', 'uads_notify_manager_email', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_email',
            'default'           => '',
        ]);

        register_setting('uads_settings_general', 'uads_sync_schedule', [
            'type'              => 'string',
            'sanitize_callback' => static function ($v): string {
                $v = (string) $v;
                return in_array($v, ['off', 'daily', 'weekly', 'monthly'], true) ? $v : 'weekly';
            },
            'default'           => 'weekly',
        ]);

        register_setting('uads_settings_general', 'uads_np_default_sender_city_name', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => '',
        ]);

        register_setting('uads_settings_general', 'uads_np_default_sender_warehouse_label', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => '',
        ]);
    }

    public static function enqueue_assets(string $hook): void
    {
        // Strict match against the hook returned by add_submenu_page (avoids translated-parent-slug bug).
        if (self::$page_hook === '' || $hook !== self::$page_hook) {
            return;
        }

        // 1. selectWoo registration — WC only registers it on frontend/order-edit screens.
        if (function_exists('WC')) {
            $wc_url = WC()->plugin_url();
            $wc_ver = WC()->version;
            if (!wp_script_is('selectWoo', 'registered')) {
                wp_register_script('selectWoo', $wc_url . '/assets/js/selectWoo/selectWoo.full.min.js', ['jquery'], $wc_ver, true);
            }
            if (!wp_style_is('select2', 'registered')) {
                wp_register_style('select2', $wc_url . '/assets/css/select2.css', [], $wc_ver);
            }
        }

        // 2. Canonical pattern: register-then-localize-then-enqueue (matches WC_Admin_Assets approach).
        wp_register_script(
            'uads-admin-settings',
            UADS_URL . 'assets/js/admin-settings.js',
            ['jquery'],
            UADS_VERSION,
            true
        );

        wp_localize_script('uads-admin-settings', 'UADS_SETTINGS', [
            'ajaxurl'        => admin_url('admin-ajax.php'),
            'nonce'          => wp_create_nonce(self::NONCE_NAME),
            'syncNonce'      => wp_create_nonce('uads_trigger_sync'),
            'checkoutNonce'  => \Gunkov\UAShipping\Carrier\NovaPoshta\Checkout\AjaxController::nonce(),
            'i18n'           => [
                'testing'     => __('Testing connection…', 'ua-direct-shipping'),
                'success'     => __('Connection successful', 'ua-direct-shipping'),
                'failed'      => __('Connection failed', 'ua-direct-shipping'),
                'syncStart'   => __('Запуск sync...', 'ua-direct-shipping'),
                'syncConfirm' => __('Запустити повний sync довідників? Триватиме ~30-60 хв у фоні. Прогрес — у Telegram.', 'ua-direct-shipping'),
            ],
        ]);

        wp_enqueue_script('selectWoo');
        wp_enqueue_style('select2');
        wp_enqueue_script('uads-admin-settings');
        wp_enqueue_style(
            'uads-admin-settings',
            UADS_URL . 'assets/css/admin.css',
            [],
            UADS_VERSION
        );
    }

    public static function render(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        $api_key   = (string) get_option('uads_np_api_key', '');
        $debug     = (bool) get_option('uads_debug_mode', false);
        $sender_city_ref = (string) get_option('uads_np_default_sender_city_ref', '');
        $sender_city_name = (string) get_option('uads_np_default_sender_city_name', '');
        $sender_wh_ref   = (string) get_option('uads_np_default_sender_warehouse_ref', '');
        $sender_wh_label = (string) get_option('uads_np_default_sender_warehouse_label', '');
        $sync_schedule   = (string) get_option('uads_sync_schedule', 'weekly');
        $hide_shipping_cost = '0' !== (string) get_option('uads_hide_shipping_cost', '1');
        $email_optional     = '0' !== (string) get_option('uads_email_optional', '1');
        $notify_manager     = '0' !== (string) get_option('uads_notify_manager_on_status', '1');
        $notify_email       = (string) get_option('uads_notify_manager_email', get_option('admin_email'));
        $api_masked = self::mask_key($api_key);
        ?>
        <div class="wrap uads-settings">
            <h1><?php esc_html_e('UA Direct Shipping', 'ua-direct-shipping'); ?></h1>
            <p class="description">
                <?php esc_html_e('Direct API integration for Nova Poshta. No cloud, no per-domain limits.', 'ua-direct-shipping'); ?>
                <code>v<?php echo esc_html(UADS_VERSION); ?></code>
            </p>

            <form method="post" action="options.php" novalidate>
                <?php settings_fields('uads_settings_general'); ?>

                <h2><?php esc_html_e('Nova Poshta API', 'ua-direct-shipping'); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="uads_np_api_key"><?php esc_html_e('API Key', 'ua-direct-shipping'); ?></label>
                        </th>
                        <td>
                            <input
                                type="password"
                                id="uads_np_api_key"
                                name="uads_np_api_key"
                                value="<?php echo esc_attr($api_key); ?>"
                                class="regular-text"
                                autocomplete="off"
                                placeholder="<?php echo esc_attr($api_masked ?: '32-character API key'); ?>"
                            />
                            <button type="button" id="uads-test-conn" class="button button-secondary">
                                <?php esc_html_e('Test connection', 'ua-direct-shipping'); ?>
                            </button>
                            <p class="description">
                                <?php
                                printf(
                                    /* translators: %s: link to NP API key page */
                                    esc_html__('Get your key at %s', 'ua-direct-shipping'),
                                    '<a href="https://my.novaposhta.ua/settings/index#apikeys" target="_blank" rel="noopener">my.novaposhta.ua → Налаштування → API</a>'
                                );
                                ?>
                            </p>
                            <div id="uads-test-result" class="uads-test-result" aria-live="polite"></div>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label><?php esc_html_e('Місто-відправник', 'ua-direct-shipping'); ?></label>
                        </th>
                        <td>
                            <select id="uads_sender_city_select" style="min-width:320px;">
                                <?php if ($sender_city_ref && $sender_city_name): ?>
                                    <option value="<?php echo esc_attr($sender_city_ref); ?>" selected><?php echo esc_html($sender_city_name); ?></option>
                                <?php else: ?>
                                    <option></option>
                                <?php endif; ?>
                            </select>
                            <input type="hidden" name="uads_np_default_sender_city_ref"  id="uads_sender_city_ref"  value="<?php echo esc_attr($sender_city_ref); ?>" />
                            <input type="hidden" name="uads_np_default_sender_city_name" id="uads_sender_city_name" value="<?php echo esc_attr($sender_city_name); ?>" />
                            <p class="description">
                                <?php esc_html_e('Введіть назву міста — список з НП API.', 'ua-direct-shipping'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label><?php esc_html_e('Відділення-відправник', 'ua-direct-shipping'); ?></label>
                        </th>
                        <td>
                            <select id="uads_sender_warehouse_select" style="min-width:420px;" <?php echo $sender_city_ref ? '' : 'disabled'; ?>>
                                <?php if ($sender_wh_ref && $sender_wh_label): ?>
                                    <option value="<?php echo esc_attr($sender_wh_ref); ?>" selected><?php echo esc_html($sender_wh_label); ?></option>
                                <?php else: ?>
                                    <option></option>
                                <?php endif; ?>
                            </select>
                            <input type="hidden" name="uads_np_default_sender_warehouse_ref"   id="uads_sender_wh_ref"   value="<?php echo esc_attr($sender_wh_ref); ?>" />
                            <input type="hidden" name="uads_np_default_sender_warehouse_label" id="uads_sender_wh_label" value="<?php echo esc_attr($sender_wh_label); ?>" />
                            <p class="description">
                                <?php esc_html_e('Сначала оберіть місто, потім — відділення.', 'ua-direct-shipping'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Checkout UX', 'ua-direct-shipping'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="uads_hide_shipping_cost" value="1" <?php checked($hide_shipping_cost); ?> />
                                <?php esc_html_e('Приховувати вартість доставки на checkout (платить отримувач у НП)', 'ua-direct-shipping'); ?>
                            </label>
                            <p class="description">
                                <?php esc_html_e('Стандарт UA-магазинів: покупець бачить лише вартість товару. За доставку платить у НП відділенні при отриманні. Реальна ставка зберігається в order meta для аналітики.', 'ua-direct-shipping'); ?>
                            </p>
                            <br />
                            <label>
                                <input type="checkbox" name="uads_email_optional" value="1" <?php checked($email_optional); ?> />
                                <?php esc_html_e('Email необов\'язковий (placeholder якщо не введено)', 'ua-direct-shipping'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="uads_debug_mode"><?php esc_html_e('Debug logging', 'ua-direct-shipping'); ?></label>
                        </th>
                        <td>
                            <label>
                                <input
                                    type="checkbox"
                                    id="uads_debug_mode"
                                    name="uads_debug_mode"
                                    value="1"
                                    <?php checked($debug); ?>
                                />
                                <?php esc_html_e('Log all API calls to WC → Status → Logs (source: ua-direct-shipping)', 'ua-direct-shipping'); ?>
                            </label>
                        </td>
                    </tr>
                </table>

                <h2><?php esc_html_e('Сповіщення', 'ua-direct-shipping'); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e('TTN status', 'ua-direct-shipping'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="uads_notify_manager_on_status" value="1" <?php checked($notify_manager); ?> />
                                <?php esc_html_e('Сповіщати менеджера на email при зміні статусу TTN (cron)', 'ua-direct-shipping'); ?>
                            </label>
                            <br /><br />
                            <input type="email" name="uads_notify_manager_email" value="<?php echo esc_attr($notify_email); ?>" class="regular-text" placeholder="<?php echo esc_attr(get_option('admin_email')); ?>" />
                            <p class="description">
                                <?php esc_html_e('Якщо порожньо — використовується admin_email сайту.', 'ua-direct-shipping'); ?>
                            </p>
                        </td>
                    </tr>
                </table>

                <h2><?php esc_html_e('База довідників Нової Пошти', 'ua-direct-shipping'); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e('Стан', 'ua-direct-shipping'); ?></th>
                        <td>
                            <?php
                            $counts = \Gunkov\UAShipping\Common\Database\SchemaInstaller::counts();
                            $sync_status = \Gunkov\UAShipping\Carrier\NovaPoshta\Cron\WarehouseSyncCron::get_status();
                            ?>
                            <p>
                                <strong><?php esc_html_e('Cities:', 'ua-direct-shipping'); ?></strong> <?php echo esc_html(number_format_i18n($counts['cities'])); ?>
                                · <strong><?php esc_html_e('Warehouses:', 'ua-direct-shipping'); ?></strong> <?php echo esc_html(number_format_i18n($counts['warehouses'])); ?>
                            </p>
                            <p>
                                <strong><?php esc_html_e('Останній sync:', 'ua-direct-shipping'); ?></strong>
                                <?php
                                if (!empty($sync_status['finished_at'])) {
                                    echo esc_html($sync_status['finished_at']) . ' UTC';
                                } elseif (!empty($sync_status['running'])) {
                                    echo '<em>' . esc_html__('виконується...', 'ua-direct-shipping') . '</em>';
                                } else {
                                    echo '<em>' . esc_html__('ніколи', 'ua-direct-shipping') . '</em>';
                                }
                                ?>
                            </p>
                            <?php if (!empty($sync_status['last_error'])): ?>
                                <p style="color:#b32d2e;">
                                    <?php esc_html_e('Остання помилка:', 'ua-direct-shipping'); ?>
                                    <code><?php echo esc_html($sync_status['last_error']); ?></code>
                                </p>
                            <?php endif; ?>
                            <p>
                                <button type="button" id="uads-sync-now" class="button button-secondary" <?php echo !empty($sync_status['running']) ? 'disabled' : ''; ?>>
                                    🔄 <?php esc_html_e('Оновити довідники Нової Пошти', 'ua-direct-shipping'); ?>
                                </button>
                                <span id="uads-sync-result" style="margin-left:10px;"></span>
                            </p>
                            <p class="description">
                                <?php esc_html_e('Натискай тут якщо потрібно sync негайно (нові відділення, після помилок тощо). Регулярний sync — за розкладом нижче.', 'ua-direct-shipping'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="uads_sync_schedule"><?php esc_html_e('Автоматичний sync', 'ua-direct-shipping'); ?></label>
                        </th>
                        <td>
                            <select name="uads_sync_schedule" id="uads_sync_schedule">
                                <option value="off"     <?php selected('off', $sync_schedule); ?>><?php esc_html_e('Вимкнено', 'ua-direct-shipping'); ?></option>
                                <option value="daily"   <?php selected('daily', $sync_schedule); ?>><?php esc_html_e('Щодня (04:00 UTC)', 'ua-direct-shipping'); ?></option>
                                <option value="weekly"  <?php selected('weekly', $sync_schedule); ?>><?php esc_html_e('Щотижня (нд 04:00 UTC)', 'ua-direct-shipping'); ?></option>
                                <option value="monthly" <?php selected('monthly', $sync_schedule); ?>><?php esc_html_e('Щомісяця (1-го 04:00 UTC)', 'ua-direct-shipping'); ?></option>
                            </select>
                            <p class="description">
                                <?php
                                $next_run = \Gunkov\UAShipping\Carrier\NovaPoshta\Cron\WarehouseSyncCron::next_run();
                                if ($next_run) {
                                    /* translators: %s: next scheduled sync date */
                                    printf(esc_html__('Наступний запуск: %s UTC', 'ua-direct-shipping'), esc_html(gmdate('Y-m-d H:i', $next_run)));
                                } else {
                                    esc_html_e('Розклад вимкнено.', 'ua-direct-shipping');
                                }
                                ?>
                            </p>
                        </td>
                    </tr>
                </table>

                <h2><?php esc_html_e('CSV-експорт ТТН', 'ua-direct-shipping'); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e('Період', 'ua-direct-shipping'); ?></th>
                        <td>
                            <input type="date" id="uads-csv-from" value="<?php echo esc_attr(date('Y-m-01')); ?>" />
                            —
                            <input type="date" id="uads-csv-to" value="<?php echo esc_attr(date('Y-m-t')); ?>" />
                            <a id="uads-csv-export" href="#" class="button button-secondary">
                                ⬇ <?php esc_html_e('Експорт CSV', 'ua-direct-shipping'); ?>
                            </a>
                            <script>
                            (function(){
                                document.getElementById('uads-csv-export').addEventListener('click', function(e){
                                    e.preventDefault();
                                    var from = document.getElementById('uads-csv-from').value;
                                    var to = document.getElementById('uads-csv-to').value;
                                    window.location.href = '<?php echo esc_js(\Gunkov\UAShipping\Carrier\NovaPoshta\Admin\CsvExporter::url('__FROM__', '__TO__')); ?>'.replace('__FROM__', encodeURIComponent(from)).replace('__TO__', encodeURIComponent(to));
                                });
                            })();
                            </script>
                            <p class="description">
                                <?php esc_html_e('Експортує всі замовлення з виданими ТТН за вказаний період. Excel UTF-8.', 'ua-direct-shipping'); ?>
                            </p>
                        </td>
                    </tr>
                </table>

                <h2><?php esc_html_e('Status', 'ua-direct-shipping'); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e('Plugin version', 'ua-direct-shipping'); ?></th>
                        <td><code><?php echo esc_html(UADS_VERSION); ?></code></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('PHP version', 'ua-direct-shipping'); ?></th>
                        <td><code><?php echo esc_html(PHP_VERSION); ?></code></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('WooCommerce', 'ua-direct-shipping'); ?></th>
                        <td><code><?php echo esc_html(defined('WC_VERSION') ? WC_VERSION : 'n/a'); ?></code></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('HPOS enabled', 'ua-direct-shipping'); ?></th>
                        <td>
                            <?php
                            $hpos = false;
                            if (class_exists(\Automattic\WooCommerce\Utilities\OrderUtil::class)) {
                                $hpos = \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
                            }
                            echo $hpos ? '✅' : '❌';
                            ?>
                        </td>
                    </tr>
                </table>

                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    private static function mask_key(string $key): string
    {
        if (strlen($key) < 8) {
            return '';
        }
        return substr($key, 0, 4) . str_repeat('•', strlen($key) - 8) . substr($key, -4);
    }
}
