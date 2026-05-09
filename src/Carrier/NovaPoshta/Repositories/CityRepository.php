<?php
declare(strict_types=1);

namespace Gunkov\UAShipping\Carrier\NovaPoshta\Repositories;

use Gunkov\UAShipping\Carrier\NovaPoshta\DTO\City;
use Gunkov\UAShipping\Common\Database\SchemaInstaller;

final class CityRepository
{
    public static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'uads_np_cities';
    }

    /**
     * @return City[]
     */
    public static function search(string $term, int $limit = 30): array
    {
        if (!SchemaInstaller::tables_exist() || mb_strlen($term) < 2) {
            return [];
        }

        global $wpdb;
        $table = self::table();
        $like = $wpdb->esc_like($term) . '%';

        $sql = "SELECT * FROM $table
            WHERE description LIKE %s OR description_ru LIKE %s
            ORDER BY
                CASE WHEN description LIKE %s THEN 0 ELSE 1 END,
                LENGTH(description) ASC,
                description ASC
            LIMIT %d";

        $rows = $wpdb->get_results($wpdb->prepare($sql, $like, $like, $like, $limit), ARRAY_A);
        if (!is_array($rows)) return [];

        return array_map(static fn (array $r): City => new City(
            ref:               (string) $r['ref'],
            description:       (string) $r['description'],
            description_ru:    (string) ($r['description_ru'] ?: null),
            area_description:  (string) ($r['area_description'] ?: null),
            area_ref:          (string) ($r['area_ref'] ?: null),
            delivery_city_ref: (string) ($r['delivery_city_ref'] ?: null),
        ), $rows);
    }

    /**
     * @param array<int,array<string,mixed>> $api_rows
     */
    public static function upsert_batch(array $api_rows): int
    {
        if (empty($api_rows)) return 0;
        global $wpdb;
        $table = self::table();

        $values = [];
        $placeholders = [];

        foreach ($api_rows as $row) {
            $ref = (string) ($row['Ref'] ?? '');
            if ('' === $ref) continue;

            $values[] = $ref;
            $values[] = (string) ($row['Description'] ?? '');
            $values[] = (string) ($row['DescriptionRu'] ?? '');
            $values[] = (string) ($row['Area'] ?? '');
            $values[] = (string) ($row['AreaDescription'] ?? '');
            $values[] = (string) ($row['DeliveryCity'] ?? '');

            $placeholders[] = '(%s,%s,%s,%s,%s,%s)';
        }

        if (empty($placeholders)) return 0;

        $sql = "INSERT INTO $table
            (ref, description, description_ru, area_ref, area_description, delivery_city_ref)
            VALUES " . implode(',', $placeholders) . "
            ON DUPLICATE KEY UPDATE
                description = VALUES(description),
                description_ru = VALUES(description_ru),
                area_ref = VALUES(area_ref),
                area_description = VALUES(area_description),
                delivery_city_ref = VALUES(delivery_city_ref)";

        return (int) $wpdb->query($wpdb->prepare($sql, ...$values));
    }
}
