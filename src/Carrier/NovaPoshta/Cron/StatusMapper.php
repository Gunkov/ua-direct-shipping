<?php
declare(strict_types=1);

namespace Gunkov\UAShipping\Carrier\NovaPoshta\Cron;

/**
 * Maps NP tracking status codes to WC order statuses.
 *
 * NP codes (subset):
 *   1   Не прийнято
 *   2   Видалено
 *   3   Номер не знайдено
 *   4   Прийнято до відправлення
 *   5   Передано на склад
 *   6   В дорозі
 *   7   Прибуло у відділення / Postomat
 *   8   Очікує отримувач
 *   9   Доставлено / Отримано
 *  10   Видалено зберігання
 *  11   Адресат відсутній
 * 102   Відмова отримувача
 * 103   Не отримано (зберігання)
 *
 * WC core statuses: pending, processing, on-hold, completed, cancelled, refunded, failed
 */
final class StatusMapper
{
    /**
     * @return array<int,string> code → status (without `wc-` prefix)
     */
    public static function default_map(): array
    {
        return [
            9   => 'completed',
            102 => 'cancelled',
            103 => 'cancelled',
        ];
    }

    public static function get_map(): array
    {
        $stored = get_option('uads_np_status_map', []);
        if (!is_array($stored) || empty($stored)) {
            return self::default_map();
        }
        return $stored;
    }

    public static function map_to_wc(int $code): ?string
    {
        $map = self::get_map();
        $candidate = $map[$code] ?? null;
        if (!is_string($candidate) || '' === $candidate) {
            return null;
        }
        return $candidate;
    }

    public static function is_final_code(int $code): bool
    {
        return in_array($code, [2, 9, 10, 102, 103], true);
    }
}
