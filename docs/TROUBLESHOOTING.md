# Troubleshooting

Типові проблеми та рішення.

## Установка / активація

### Плагін не активується, помилка "WooCommerce required"

WC має бути встановлений і активний **до** активації нашого плагіна. Спочатку Activate WooCommerce, потім UA Direct Shipping.

### Після активації нічого не змінилось — нема submenu "UA Direct Shipping"

1. Перевір що плагін реально активний: `wp plugin list --status=active | grep ua-direct`
2. Перевір що user має право `manage_woocommerce`
3. Очисти кеш opcache: `php -r 'opcache_reset();'` або restart php-fpm

## API ключ і connection

### "Test connection" → ❌ "API key invalid"

1. Перевір ключ через cURL:
   ```bash
   curl -s -X POST https://api.novaposhta.ua/v2.0/json/ \
     -H "Content-Type: application/json" \
     -d '{"apiKey":"YOUR_KEY","modelName":"AddressGeneral","calledMethod":"getAreas"}'
   ```
2. Якщо `success: false` з повідомленням "API key invalid" — ключ дійсно неправильний. Згенеруй новий у [НП кабінеті](https://my.novaposhta.ua/settings/index#apikeys).
3. Якщо тут success — проблема у нашому коді, повідом через GitHub Issues.

### "Test connection" → ❌ HTTP error / timeout

1. Перевір вихідний firewall сервера — `api.novaposhta.ua` має бути доступний на порту 443.
2. Якщо хостинг блокує outgoing HTTP — звернись до hosting support.

## Sync довідників

### "🔄 Оновити довідники Нової Пошти" → нічого не відбувається

Sync виконується через WP-Cron у фоні. На "тихих" сайтах WP-Cron може не tригеритись. Виправлення:
1. Налаштуй системний cron:
   ```bash
   */5 * * * * curl -s https://yoursite.com/wp-cron.php?doing_wp_cron > /dev/null
   ```
2. Або запусти sync вручну з CLI:
   ```bash
   sudo -u www-data wp eval '\Gunkov\UAShipping\Carrier\NovaPoshta\Cron\WarehouseSyncCron::execute();' --path=/var/www/yoursite
   ```

### Sync застряг ("running" forever, але прогресу немає)

1. Перевір PID процесу: `ps aux | grep "wp eval"`
2. Якщо процесу немає, але `uads_np_sync_status.running == true`:
   ```bash
   sudo -u www-data wp option update uads_np_sync_status '{"running":false}' --format=json
   ```
3. Знов натисни "🔄 Оновити довідники".

### Sync завершився, але warehouses < 20000

Неповний sync через NP API rate-limit. Запусти sync ще раз — `INSERT ... ON DUPLICATE KEY UPDATE` не дублює, лише дозаливає пропущені.

## Checkout

### Поля міста / відділення не з'являються

1. Hard reload з очищенням кешу (Ctrl+Shift+R, або інкогніто).
2. Перевір DevTools → Console на JavaScript errors.
3. Перевір що selectWoo (WC core) завантажений — у Console: `typeof jQuery.fn.selectWoo`. Має бути `"function"`.
4. Перевір що `_uads_chosen_city_ref` reset на initial GET (це робить `OrderHandler::reset_stale_city_ref`).

### Поле "По-батькові" не показується для address-методу

`billing_middle_name` реєструється як standard WC field. Якщо у тебе custom checkout template (theme override) — він може ігнорувати наш filter `woocommerce_billing_fields`. Або інший плагін (Checkout Manager) перетирає fields.

### Cart show "Розрахувати вартість доставки" блок

Ми його приховуємо через `woocommerce_cart_ready_to_calc_shipping`. Якщо не зник:
1. Перевір що `woocommerce_enable_shipping_calc=no`
2. Інші плагіни (наприклад YITH WooCommerce Calculator) можуть форсити показ — деактивуй їх.

### "Ship to a different address?" checkbox видно

Filter `woocommerce_cart_needs_shipping_address` повертає `false` коли наш метод. Якщо checkbox не зник:
1. Перевір що chosen_shipping_methods у session починається з `uads_np_`
2. Перевір CSS — `body.uads-np-active #ship-to-different-address-checkbox` має `display: none !important`.

### Email з зірочкою (required) хоч `email_optional` увімкнено

WC рендерить зірочку server-side при `required=true`. Наш filter ставить `required=false` коли наш метод. Якщо не спрацьовує:
1. Hard reload
2. Перевір що `wp option get uads_email_optional` повертає `1`

## TTN

### "Recipient not selected; ContactRecipient not selected"

Ми робимо 2-step: `Counterparty.save` (PrivatePerson) → `InternetDocument.save`. Помилка означає що Counterparty.save не повернув Ref.

Найчастіше:
1. **Тестове ім'я** ("Тест", "Test") — НП блокує content moderation. Введи реальне ім'я.
2. **Phone не валідний** — у Counterparty.save phone у форматі `380XXXXXXXXX` (12 digits, без `+`).

### "Address Ref is missing or invalid" на address-методі

`Address.save` не повернув address ref. Причини:
1. Вулицю обрано з autocomplete dropdown (новий order) — має працювати
2. Вулицю введено вільним текстом (legacy order) — fuzzy search може не знайти. Перейди на новий order через checkout з autocomplete.

### "non-PDF" при першому натисканні "Друк A4"

NP API має race condition: PDF генерується ~1-2 сек після `InternetDocument.save`. Наш `PdfProxyController` уже робить retry з 1.5с паузою (до 3 спроб). Якщо все одно не виходить — це справжня проблема з ТТН, перевір її в кабінеті НП.

### "Contact Recipient contains invalid words"

NP блокує "тест", "перевірка", "test" і тощо в іменах. Введи реальне ім'я.

## Branded tracking page

### `/uads-track/{ttn}/` віддає 404

Rewrite rules не flushed. Виправлення:
```bash
sudo -u www-data wp rewrite flush --hard --path=/var/www/yoursite
```

Або в WP-admin → Settings → Permalinks → Save (без змін, просто save — це flush rewrite).

### Сторінка є, але не показує статус ("Статус посилки тимчасово недоступний")

1. Перевір що TTN валідна (існує в кабінеті НП).
2. Перевір API connection.
3. Очисти transient: `wp transient delete uads_track_<TTN>`.

## Tracking cron

### Статус ТТН не оновлюється автоматично

1. Перевір schedule: `wp cron event list | grep uads_track`
2. Якщо schedule є але не виконується — WP-Cron не тригериться. Налаштуй системний cron (див. Sync секцію вище).
3. Запусти manually: `wp cron event run uads_track_orders_event`

### Email-нотифікації не надходять

1. Перевір `uads_notify_manager_on_status = 1` (toggle)
2. Перевір `uads_notify_manager_email` — має бути реальний mailbox що приймає листи.
3. Тест wp_mail():
   ```bash
   sudo -u www-data wp eval 'echo wp_mail("your@email.com", "Test", "Test body") ? "OK" : "FAIL";' --path=/var/www/yoursite
   ```
4. Якщо FAIL — у тебе SMTP проблема. Постав плагін [WP Mail SMTP](https://wordpress.org/plugins/wp-mail-smtp/) і налаштуй Gmail/SendGrid/Mailgun.
5. Перевір **спам-папку** — нотифікації від WordPress часто туди.

## Performance

### Checkout повільний (>1 сек до показу dropdown'у міст)

1. Перевір що localна БД заповнена: `wp option get uads_np_sync_status`
2. Якщо empty — sync ще не виконувався або провалився. Запусти `🔄 Оновити довідники`.
3. Якщо БД повна, але повільно — Redis cache не активний. Установи [Redis Object Cache](https://wordpress.org/plugins/redis-cache/).

### Sync довідників повільний (>2 годин)

NP API rate-limit ~10 req/sec. Ми робимо 6 req/sec (sleep 150ms). Не намагайся збільшити — отримаєш 429 і перерви.

Альтернативно: перевір що інший плагін чи cron не блокує наш sync (одночасні Tracking + Sync крони).

## HPOS

### Order meta не зберігається на HPOS-сайті

Ми використовуємо `$order->update_meta_data()` + `$order->save()` — HPOS-compatible. Якщо meta не зберігається:
1. Перевір що WC ≥ 9.0
2. Перевір compatibility: `wp eval 'echo \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ? "ON" : "OFF";'`
3. Не використовуй `update_post_meta()` напряму.

## Інше

### "Permission denied to wp-content/plugins/ua-direct-shipping"

```bash
sudo chown -R www-data:www-data /var/www/yoursite/wp-content/plugins/ua-direct-shipping
sudo chmod -R 755 /var/www/yoursite/wp-content/plugins/ua-direct-shipping
```

### Як видалити плагін повністю (з даними)?

1. Deactivate у WP-admin
2. Delete plugin (це викличе `uninstall.php` — drop options + transients)
3. Tables `wp_uads_np_*` лишаються (на випадок re-install). Видалити вручну:
   ```sql
   DROP TABLE wp_uads_np_areas, wp_uads_np_cities, wp_uads_np_warehouses, wp_uads_np_sync_log;
   ```

### Як перевірити версію плагіна?

`wp plugin get ua-direct-shipping --field=version`

## Якщо нічого з вище не допомогло

1. Увімкни WP_DEBUG_LOG в `wp-config.php`:
   ```php
   define('WP_DEBUG', true);
   define('WP_DEBUG_LOG', true);
   define('WP_DEBUG_DISPLAY', false);
   ```
2. Відтвори проблему.
3. Подивись `wp-content/debug.log` на наші ([uads/...]) записи.
4. Створи issue на [GitHub](https://github.com/Gunkov/ua-direct-shipping/issues) з:
   - WP version, WC version, PHP version
   - Стек trace (якщо є)
   - Кроки відтворення
