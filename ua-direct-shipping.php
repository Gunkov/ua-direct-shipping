<?php
/**
 * Plugin Name: UA Direct Shipping
 * Plugin URI: https://gunkov.pp.ua/ua-direct-shipping
 * Description: Direct API integration for Nova Poshta (Ukrposhta планується). Без cloud-проксі, без freemium gates, без per-domain limits.
 * Version: 0.1.0-dev
 * Author: Sergiy Gunkov
 * Author URI: https://gunkov.pp.ua
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: ua-direct-shipping
 * Domain Path: /languages
 * Requires PHP: 8.1
 * Requires at least: 6.5
 * Requires Plugins: woocommerce
 * WC requires at least: 9.0
 * WC tested up to: 10.7
 *
 * @package Gunkov\UAShipping
 */

defined('ABSPATH') || exit;

const UADS_VERSION = '0.1.0-dev4';
const UADS_FILE    = __FILE__;
const UADS_SLUG    = 'ua-direct-shipping';

if (!defined('UADS_DIR')) {
    define('UADS_DIR', plugin_dir_path(__FILE__));
}
if (!defined('UADS_URL')) {
    define('UADS_URL', plugin_dir_url(__FILE__));
}

add_action('before_woocommerce_init', static function (): void {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            UADS_FILE,
            true
        );
    }
});

spl_autoload_register(static function (string $class): void {
    $prefix = 'Gunkov\\UAShipping\\';
    if (strpos($class, $prefix) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file     = UADS_DIR . 'src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_readable($file)) {
        require_once $file;
    }
});

add_action('plugins_loaded', static function (): void {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', static function (): void {
            echo '<div class="notice notice-error"><p>'
                . esc_html__('UA Direct Shipping requires WooCommerce to be installed and active.', 'ua-direct-shipping')
                . '</p></div>';
        });
        return;
    }
    Gunkov\UAShipping\Plugin::boot();
});

register_activation_hook(__FILE__, [Gunkov\UAShipping\Plugin::class, 'on_activate']);
register_deactivation_hook(__FILE__, [Gunkov\UAShipping\Plugin::class, 'on_deactivate']);
