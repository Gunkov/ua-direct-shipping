<?php
/**
 * Branded tracking page template.
 *
 * @var string $ttn
 * @var ?\Gunkov\UAShipping\Carrier\NovaPoshta\DTO\TrackingStatus $status
 *
 * Theme override: copy to `your-theme/uads/tracking-page.php`.
 */

defined('ABSPATH') || exit;
?>
<style>
    .uads-track-page a {
        color: #2271b1;
        text-decoration: underline;
    }
    .uads-track-page a:hover {
        color: #135e96;
    }
</style>
<main class="uads-track-page" style="max-width: 720px; margin: 2em auto; padding: 1em;">
    <h1 style="margin-bottom: 0.5em;">
        <?php esc_html_e('Відстеження посилки', 'ua-direct-shipping'); ?>
    </h1>
    <p style="font-size: 1.1em; color: #555;">
        <?php esc_html_e('ТТН:', 'ua-direct-shipping'); ?>
        <code style="font-size: 1.2em; padding: 2px 6px; background: #f4f4f4;"><?php echo esc_html((string) $ttn); ?></code>
    </p>

    <?php if (!$status instanceof \Gunkov\UAShipping\Carrier\NovaPoshta\DTO\TrackingStatus): ?>
        <div class="uads-track-error" style="padding: 1em; background: #fff3cd; border-left: 4px solid #ffc107; margin-top: 1em;">
            <p>
                <?php esc_html_e('Статус посилки тимчасово недоступний. Спробуйте оновити сторінку через кілька хвилин.', 'ua-direct-shipping'); ?>
            </p>
        </div>
    <?php else: ?>
        <div class="uads-track-status" style="padding: 1.2em; background: #f7f7f7; border-radius: 6px; margin-top: 1em;">
            <p style="margin: 0; font-size: 1.3em;">
                <strong>
                    <?php
                    $code = $status->status_code;
                    if (in_array($code, [9], true)) {
                        echo '✅ ';
                    } elseif (in_array($code, [102, 103, 2], true)) {
                        echo '❌ ';
                    } else {
                        echo '🚚 ';
                    }
                    echo esc_html($status->status);
                    ?>
                </strong>
                <?php if ($status->status_code): ?>
                    <span style="color: #888; font-size: 0.85em;">(<?php echo esc_html((string) $status->status_code); ?>)</span>
                <?php endif; ?>
            </p>
        </div>

        <table style="width: 100%; margin-top: 1.5em; border-collapse: collapse;">
            <?php if ($status->city_recipient): ?>
                <tr>
                    <th style="text-align: left; padding: 8px; border-bottom: 1px solid #eee; width: 40%;">
                        <?php esc_html_e('Місто отримувача', 'ua-direct-shipping'); ?>
                    </th>
                    <td style="padding: 8px; border-bottom: 1px solid #eee;">
                        <?php echo esc_html($status->city_recipient); ?>
                    </td>
                </tr>
            <?php endif; ?>
            <?php if ($status->warehouse_recipient): ?>
                <tr>
                    <th style="text-align: left; padding: 8px; border-bottom: 1px solid #eee;">
                        <?php esc_html_e('Відділення', 'ua-direct-shipping'); ?>
                    </th>
                    <td style="padding: 8px; border-bottom: 1px solid #eee;">
                        <?php echo esc_html($status->warehouse_recipient); ?>
                    </td>
                </tr>
            <?php endif; ?>
            <?php if ($status->estimated_delivery_date): ?>
                <tr>
                    <th style="text-align: left; padding: 8px; border-bottom: 1px solid #eee;">
                        <?php esc_html_e('Очікувана доставка', 'ua-direct-shipping'); ?>
                    </th>
                    <td style="padding: 8px; border-bottom: 1px solid #eee;">
                        <?php echo esc_html($status->estimated_delivery_date); ?>
                    </td>
                </tr>
            <?php endif; ?>
            <?php if ($status->actual_delivery_date): ?>
                <tr>
                    <th style="text-align: left; padding: 8px; border-bottom: 1px solid #eee;">
                        <?php esc_html_e('Фактична доставка', 'ua-direct-shipping'); ?>
                    </th>
                    <td style="padding: 8px; border-bottom: 1px solid #eee;">
                        <?php echo esc_html($status->actual_delivery_date); ?>
                    </td>
                </tr>
            <?php endif; ?>
            <?php if ($status->document_cost !== null && $status->document_cost > 0): ?>
                <tr>
                    <th style="text-align: left; padding: 8px; border-bottom: 1px solid #eee;">
                        <?php esc_html_e('Вартість доставки', 'ua-direct-shipping'); ?>
                    </th>
                    <td style="padding: 8px; border-bottom: 1px solid #eee;">
                        <?php echo esc_html((string) $status->document_cost); ?> ₴
                    </td>
                </tr>
            <?php endif; ?>
        </table>
    <?php endif; ?>

    <p style="margin-top: 1.5em; font-size: 0.95em; color: #555;">
        <?php esc_html_e('Дані надаються Новою Поштою. Також можна перевірити на', 'ua-direct-shipping'); ?>
        <a href="<?php echo esc_url('https://novaposhta.ua/tracking/?cargo_number=' . rawurlencode((string) $ttn)); ?>"
           target="_blank" rel="noopener"
           style="color:#2271b1; text-decoration:underline; font-weight:500;">
            novaposhta.ua →
        </a>
    </p>
</main>
