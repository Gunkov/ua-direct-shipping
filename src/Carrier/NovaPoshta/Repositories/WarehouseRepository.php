<?php
declare(strict_types=1);

namespace Gunkov\UAShipping\Carrier\NovaPoshta\Repositories;

use Gunkov\UAShipping\Carrier\NovaPoshta\DTO\Warehouse;
use Gunkov\UAShipping\Common\Database\SchemaInstaller;

final class WarehouseRepository
{
    public static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'uads_np_warehouses';
    }

    /**
     * Search warehouses by city + (optional) name match + category.
     *
     * @param string $category 'warehouse' | 'poshtomat' | 'all'
     * @return Warehouse[]
     */
    public static function search(string $city_ref, string $term, string $category = 'warehouse', int $limit = 500): array
    {
        if (!SchemaInstaller::tables_exist()) {
            return [];
        }

        global $wpdb;
        $table = self::table();

        $where = ['city_ref = %s', 'is_active = 1'];
        $args  = [$city_ref];

        if ('warehouse' === $category) {
            $where[] = "category_of_warehouse <> 'Postomat'";
        } elseif ('poshtomat' === $category) {
            $where[] = "category_of_warehouse = 'Postomat'";
        }

        if ('' !== $term) {
            $like = '%' . $wpdb->esc_like($term) . '%';
            $where[] = '(number LIKE %s OR description LIKE %s)';
            $args[] = $like;
            $args[] = $like;
        }

        $args[] = $limit;
        $sql = "SELECT * FROM $table WHERE " . implode(' AND ', $where) . " ORDER BY CAST(number AS UNSIGNED) ASC LIMIT %d";
        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$args), ARRAY_A);

        if (!is_array($rows)) {
            return [];
        }

        return array_map(static fn (array $r): Warehouse => new Warehouse(
            ref:                   (string) $r['ref'],
            city_ref:              (string) $r['city_ref'],
            number:                (string) $r['number'],
            description:           (string) $r['description'],
            description_ru:        (string) ($r['description_ru'] ?: null),
            max_weight_allowed:    isset($r['max_weight_allowed']) ? (float) $r['max_weight_allowed'] : null,
            is_poshtomat:          'Postomat' === ($r['category_of_warehouse'] ?? ''),
            category_of_warehouse: (string) ($r['category_of_warehouse'] ?: null),
        ), $rows);
    }

    /**
     * Bulk upsert from API rows. Returns count of rows touched.
     *
     * @param array<int,array<string,mixed>> $api_rows
     */
    public static function upsert_batch(array $api_rows): int
    {
        if (empty($api_rows)) {
            return 0;
        }
        global $wpdb;
        $table = self::table();

        $values = [];
        $placeholders = [];

        foreach ($api_rows as $row) {
            $ref = (string) ($row['Ref'] ?? '');
            if ('' === $ref) continue;

            $values[] = $ref;
            $values[] = (string) ($row['CityRef'] ?? '');
            $values[] = (string) ($row['Number'] ?? '');
            $values[] = (string) ($row['Description'] ?? '');
            $values[] = (string) ($row['DescriptionRu'] ?? '');
            $values[] = (string) ($row['TypeOfWarehouse'] ?? '');
            $values[] = (string) ($row['CategoryOfWarehouse'] ?? '');
            $values[] = (float)  ($row['TotalMaxWeightAllowed'] ?? 0);
            $values[] = 1;

            $placeholders[] = '(%s,%s,%s,%s,%s,%s,%s,%f,%d)';
        }

        if (empty($placeholders)) return 0;

        $sql = "INSERT INTO $table
            (ref, city_ref, number, description, description_ru, type_of_warehouse, category_of_warehouse, max_weight_allowed, is_active)
            VALUES " . implode(',', $placeholders) . "
            ON DUPLICATE KEY UPDATE
                city_ref = VALUES(city_ref),
                number = VALUES(number),
                description = VALUES(description),
                description_ru = VALUES(description_ru),
                type_of_warehouse = VALUES(type_of_warehouse),
                category_of_warehouse = VALUES(category_of_warehouse),
                max_weight_allowed = VALUES(max_weight_allowed),
                is_active = 1";

        return (int) $wpdb->query($wpdb->prepare($sql, ...$values));
    }
}
