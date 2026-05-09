<?php
declare(strict_types=1);

namespace Gunkov\UAShipping\Common\Database;

final class SchemaInstaller
{
    public const SCHEMA_VERSION = '1';
    private const VERSION_OPTION = 'uads_db_schema_version';

    public static function install(): void
    {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();
        $prefix = $wpdb->prefix;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql_areas = "CREATE TABLE {$prefix}uads_np_areas (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            ref CHAR(36) NOT NULL,
            description VARCHAR(120) NOT NULL DEFAULT '',
            description_ru VARCHAR(120) NOT NULL DEFAULT '',
            center_ref CHAR(36) NOT NULL DEFAULT '',
            UNIQUE KEY uniq_ref (ref),
            KEY idx_description (description)
        ) $charset_collate;";

        $sql_cities = "CREATE TABLE {$prefix}uads_np_cities (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            ref CHAR(36) NOT NULL,
            description VARCHAR(160) NOT NULL DEFAULT '',
            description_ru VARCHAR(160) NOT NULL DEFAULT '',
            area_ref CHAR(36) NOT NULL DEFAULT '',
            area_description VARCHAR(120) NOT NULL DEFAULT '',
            delivery_city_ref CHAR(36) NOT NULL DEFAULT '',
            UNIQUE KEY uniq_ref (ref),
            KEY idx_area_ref (area_ref),
            KEY idx_description (description(64))
        ) $charset_collate;";

        $sql_warehouses = "CREATE TABLE {$prefix}uads_np_warehouses (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            ref CHAR(36) NOT NULL,
            city_ref CHAR(36) NOT NULL DEFAULT '',
            number VARCHAR(20) NOT NULL DEFAULT '',
            description VARCHAR(255) NOT NULL DEFAULT '',
            description_ru VARCHAR(255) NOT NULL DEFAULT '',
            type_of_warehouse CHAR(36) NOT NULL DEFAULT '',
            category_of_warehouse VARCHAR(50) NOT NULL DEFAULT '',
            max_weight_allowed DECIMAL(10,2) NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            UNIQUE KEY uniq_ref (ref),
            KEY idx_city_ref (city_ref),
            KEY idx_type (type_of_warehouse),
            KEY idx_description (description(64))
        ) $charset_collate;";

        $sql_sync_log = "CREATE TABLE {$prefix}uads_np_sync_log (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            started_at DATETIME NOT NULL,
            finished_at DATETIME NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'running',
            areas_count INT UNSIGNED NOT NULL DEFAULT 0,
            cities_count INT UNSIGNED NOT NULL DEFAULT 0,
            warehouses_count INT UNSIGNED NOT NULL DEFAULT 0,
            error_message TEXT NULL,
            KEY idx_started_at (started_at)
        ) $charset_collate;";

        dbDelta($sql_areas);
        dbDelta($sql_cities);
        dbDelta($sql_warehouses);
        dbDelta($sql_sync_log);

        update_option(self::VERSION_OPTION, self::SCHEMA_VERSION);
    }

    public static function tables_exist(): bool
    {
        global $wpdb;
        $prefix = $wpdb->prefix;
        $needed = ["{$prefix}uads_np_areas", "{$prefix}uads_np_cities", "{$prefix}uads_np_warehouses"];
        foreach ($needed as $t) {
            $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $t));
            if ($found !== $t) {
                return false;
            }
        }
        return true;
    }

    public static function counts(): array
    {
        global $wpdb;
        if (!self::tables_exist()) {
            return ['areas' => 0, 'cities' => 0, 'warehouses' => 0];
        }
        return [
            'areas'      => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}uads_np_areas"),
            'cities'     => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}uads_np_cities"),
            'warehouses' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}uads_np_warehouses"),
        ];
    }
}
