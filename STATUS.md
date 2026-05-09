# Status — ua-direct-shipping

Дата: 2026-05-07
Гілка: caркас v0.1.0-dev (день 1)

## Що готове

- ✅ Структура папок (src/Carrier/NovaPoshta, src/Common/{Cache, Database, Http, Logger, Settings, Compat, Exception}, assets, templates, languages)
- ✅ Entry-файл `ua-direct-shipping.php` (header, HPOS declaration, autoload, bootstrap)
- ✅ `composer.json` (PSR-4 autoload `Gunkov\UAShipping\`, dev: phpunit + WPCS)
- ✅ `readme.txt` (WP.org standard)
- ✅ `uninstall.php` (cleanup options + transients)
- ✅ `.gitignore`
- ✅ `Plugin::boot` — точка входу, реєстрація меню/AJAX
- ✅ `Plugin::on_activate` / `on_deactivate` — hooks для cron
- ✅ `Common\Http\HttpClient` — JSON POST wrapper над `wp_remote_post`
- ✅ `Common\Logger\Logger` — info/warning/error → wc_get_logger() або error_log
- ✅ `Common\Exception\{Api,Http}Exception` — типові винятки
- ✅ `Carrier\NovaPoshta\ApiClient` — request/get_areas/search_settlements/get_warehouses; повертає raw arrays поки що (DTO у наступному кроці)
- ✅ `Common\Settings\SettingsPage` — admin UI для API ключа + Test connection + Status table (PHP/WC/HPOS)
- ✅ JS test-connection через AJAX (jQuery + nonce + sanitize)
- ✅ admin.css базовий
- ✅ Custom cron schedule `uads_six_hours` (6 годин для tracking cron)

## Що працює прямо зараз (день 1 smoke)

1. Activate → з'являється `WooCommerce → UA Direct Shipping` submenu
2. Settings page показує: API key field, Test connection button, debug toggle, статусна таблиця (PHP, WC version, HPOS enabled)
3. Натискання "Test connection" → AJAX до `wp_ajax_uads_test_connection` → ApiClient::get_areas() → НП API → результат "Connected. 26 areas returned in XXXms" або помилка з НП

## Що НЕ готове (наступні кроки v0.1)

- ❌ DTO classes (City, Warehouse, Area, Sender, Recipient, Ttn, Status)
- ❌ Repositories (CityRepository, WarehouseRepository — read з `wp_wc_ukr_shipping_np_*` або власних таблиць)
- ❌ Database\SchemaInstaller (CREATE TABLE на activation)
- ❌ ShippingMethod classes (AbstractMethod, WarehouseMethod, PoshtomatMethod, AddressMethod)
- ❌ Checkout\FieldsRenderer + AjaxController + OrderHandler
- ❌ Admin\OrderMetaBox + BulkActions + PdfProxyController
- ❌ Cron\TrackingCron + WarehouseSyncCron
- ❌ BrandedTracking\PageController
- ❌ Templates (checkout/warehouse-fields.php, admin/order-metabox.php, tracking/tracking-page.php)
- ❌ checkout.js (vanilla JS + select2)
- ❌ i18n .pot file generation
- ❌ Composer install (vendor/) — ще немає реальних залежностей runtime, тільки dev

## Що тестувати при першому деплої

```bash
# 1. wp plugin list — має бути ua-direct-shipping
# 2. wp plugin activate ua-direct-shipping → no errors у debug.log
# 3. Browser → /wp-admin/admin.php?page=ua-direct-shipping
# 4. Введи API key, натисни Test connection
# 5. Має бути "✅ Connected. 26 areas returned in <500ms"
# 6. wp option get uads_np_api_key (після збереження)
# 7. wp eval 'echo \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ? "HPOS on" : "HPOS off";'
```

## Залежності runtime (поки що)

Жодних. Все тільки на WP/WC core API.

## Залежності dev

- phpunit/phpunit ^10
- wp-coding-standards/wpcs ^3

(не критичні поки)

## Архітектурні рішення день 1

1. **Manual PSR-4 autoload через `spl_autoload_register`** — щоб працювало без `composer install` (для зручної передачі рейзахом). Composer install лише для dev tools.
2. **Settings без `WC_Settings_*`** — окрема submenu під WooCommerce, бо WC settings tab API дорогий для page з кастомним JS. Per-method settings будуть через стандартний WC Shipping Zone UI (зараз скелет).
3. **HPOS-first** — `OrderUtil::custom_orders_table_usage_is_enabled()` checks. Усі order-операції через `wc_get_order()`, не `get_post()`.
4. **Logger через `wc_get_logger`** — інтегрується з WC > Status > Logs. Fallback на `error_log` якщо WC < 3.0.
5. **API ключ маскується в settings** (4 перші + 4 останні символи) — щоб не leak-нути в скриншотах.
6. **Test connection — окремий AJAX endpoint** з nonce + capability check.
