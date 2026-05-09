<?php
/**
 * Cleanup on full uninstall.
 *
 * @package Gunkov\UAShipping
 */

defined('WP_UNINSTALL_PLUGIN') || exit;

$options = [
    'uads_np_api_key',
    'uads_np_default_sender_ref',
    'uads_np_default_sender_address_ref',
    'uads_np_default_sender_phone',
    'uads_np_default_sender_contact_ref',
    'uads_settings_general',
    'uads_settings_tracking',
    'uads_settings_branded_tracking',
    'uads_db_version',
];

foreach ($options as $option) {
    delete_option($option);
}

global $wpdb;
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_uads_%' OR option_name LIKE '_transient_timeout_uads_%'");
