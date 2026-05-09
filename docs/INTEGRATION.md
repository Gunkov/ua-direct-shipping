# Developer Integration Guide

Гайд для розробників, що інтегруються з `ua-direct-shipping` (CRM, SMS-сервіси, експорт даних, custom email templates тощо).

## Order Meta Keys

Усі наші meta зберігаються через WC standard API (`$order->update_meta_data()`), HPOS-compatible. Доступ через `wc_get_order($id)->get_meta('<key>')`.

### Загальні (всі методи)

| Meta key | Тип | Опис | Приклад |
|---|---|---|---|
| `_uads_np_method` | string | ID нашого shipping method | `uads_np_warehouse` |
| `_uads_np_city_ref` | UUID | NP CityRef | `8d5a980d-391c-11dd-90d9-001a92567626` |
| `_uads_np_city_name` | string | Назва міста | `Київ` |
| `_uads_np_recipient_phone` | string | Телефон одержувача (нормалізований) | `380501234567` |
| `_uads_np_estimated_shipping_cost` | decimal | Реальна ставка НП на момент checkout | `90.00` |
| `_uads_np_no_email` | flag (`1`) | `1` якщо клієнт без email (placeholder mode) | `1` |
| `billing_middle_name` | string | По-батькові (standard WC field) | `Іванович` |

### Warehouse / Poshtomat (`uads_np_warehouse`, `uads_np_poshtomat`)

| Meta key | Опис |
|---|---|
| `_uads_np_warehouse_ref` | UUID відділення/поштомата |
| `_uads_np_warehouse_number` | Номер ("62") |
| `_uads_np_warehouse_description` | Повна назва ("Відділення №62 (до 30 кг): просп. Григоренка, 22/20") |

### Address (кур'єр) (`uads_np_address`)

| Meta key | Опис |
|---|---|
| `_uads_np_address_street` | Назва вулиці ("Хрещатик") |
| `_uads_np_address_street_ref` | UUID вулиці у НП БД |
| `_uads_np_address_house` | Номер будинку ("18") |
| `_uads_np_address_flat` | Квартира (опційно, "11") |
| `_uads_np_recipient_address_ref` | UUID створеної recipient address (після TTN create) |
| `_uads_np_recipient_counterparty_ref` | UUID Recipient Counterparty (PrivatePerson) |
| `_uads_np_recipient_contact_ref` | UUID Recipient ContactPerson |

### Після створення ТТН

| Meta key | Опис | Приклад |
|---|---|---|
| `_uads_np_ttn` | 14-цифровий номер ТТН (IntDocNumber) | `20451434950042` |
| `_uads_np_ttn_ref` | UUID для NP API delete/update | `08c69a1d-...` |
| `_uads_np_ttn_cost` | CostOnSite (фінальна вартість для отримувача) | `45` |
| `_uads_np_ttn_estimated_date` | Очікувана дата доставки | `09.05.2026` |
| `_uads_np_ttn_created_at` | Timestamp створення (UTC) | `2026-05-08 17:25:00` |

### Tracking (cron-driven)

| Meta key | Опис |
|---|---|
| `_uads_np_ttn_status_code` | NP code (1=Не прийнято, 4=Прийнято, 6=В дорозі, 7=У відділенні, 9=Доставлено, 102/103=Відмова) |
| `_uads_np_ttn_status_text` | Текст статусу |
| `_uads_np_ttn_status_updated` | Останнє оновлення (UTC) |
| `_uads_np_ttn_actual_delivery_date` | Дата фактичної доставки (тільки коли code=9) |

## Action Hooks

```php
// Order meta saved (called after WC standard checkout_create_order)
do_action('woocommerce_checkout_create_order', $order, $data);
// Наш OrderHandler::save_order_meta слухає цей хук і записує _uads_np_* meta.

// TTN status update detected by cron
do_action('uads_track_orders_event'); // Internal — fires our TrackingCron
```

Якщо хочеш реагувати на зміну статусу:

```php
add_action('woocommerce_order_status_changed', function ($order_id, $old, $new, $order) {
    if ('completed' === $new && '' !== $order->get_meta('_uads_np_ttn')) {
        // Order delivered through NP — наприклад, SMS клієнту
        $phone = $order->get_meta('_uads_np_recipient_phone');
        my_sms_service_send($phone, 'Дякуємо за покупку!');
    }
}, 10, 4);
```

## Filter Hooks

### `woocommerce_billing_fields`

Додаємо `billing_middle_name` як standard WC field (priority 21, між first/last name).

### `woocommerce_cart_needs_shipping_address`

Повертаємо `false` коли наш метод обраний — приховуємо WC native shipping address блок.

### `woocommerce_cart_ready_to_calc_shipping`

Повертаємо `false` на сторінці `/cart/` — приховуємо WC built-in shipping calculator (бо ми використовуємо власний на checkout).

### `woocommerce_cart_shipping_method_full_label`

Додаємо короткий опис під назвою методу:
- "Нова Пошта — у відділення" → "До 30 кг. Кінцева точка — відділення НП у вибраному місті."

### `woocommerce_order_formatted_billing_address`

Інжектимо middle name у display: "Ім'я По-батькові Прізвище" замість "Ім'я Прізвище".

### `woocommerce_admin_order_data_after_billing_address`

Рендеримо custom block "📦 Нова Пошта" з нашими meta у admin order edit screen.

### `cron_schedules`

Реєструємо `monthly` schedule (~30 днів).

## AJAX Endpoints (frontend, public)

Всі require nonce `uads_checkout` (через `_wpnonce` параметр).

| Action | Метод | Параметри | Повертає |
|---|---|---|---|
| `uads_search_cities` | GET/POST | `term` (≥2 chars), `limit` (1-50) | `[{id, text, ref, city_ref, name, area}]` |
| `uads_search_warehouses` | GET/POST | `city_ref` UUID, `term`, `category` (`warehouse`\|`poshtomat`) | `[{id, text, ref, number, is_poshtomat, max_weight_kg}]` |
| `uads_search_streets` | GET/POST | `city_ref` UUID, `term` (≥2 chars) | `[{id, text, ref, name}]` |

## AJAX Endpoints (admin, capability `manage_woocommerce`)

Require nonce `uads_admin_ttn` чи відповідний для дії.

| Action | Опис |
|---|---|
| `uads_admin_dryrun_ttn` | Build payload + validation + live ставка |
| `uads_admin_create_ttn` | Реальне створення ТТН через `InternetDocument.save` |
| `uads_admin_delete_ttn` | Видалення ТТН з НП кабінету |
| `uads_admin_print_marking` | PDF проксі (A4 / 100×100 / 85×85) — без leak API ключа |
| `uads_admin_trigger_sync` | Запуск warehouse sync у фоні |
| `uads_test_connection` | Перевірка API ключа НП |

## Database Tables

Створюються при активації плагіна. Префікс — стандартний WP (`wp_`).

```sql
-- Cities (10977 rows після sync)
CREATE TABLE wp_uads_np_cities (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ref CHAR(36) NOT NULL UNIQUE,
    description VARCHAR(160),
    description_ru VARCHAR(160),
    area_ref CHAR(36),
    area_description VARCHAR(120),
    delivery_city_ref CHAR(36),
    KEY idx_area_ref (area_ref),
    KEY idx_description (description(64))
);

-- Warehouses (~25k rows)
CREATE TABLE wp_uads_np_warehouses (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ref CHAR(36) NOT NULL UNIQUE,
    city_ref CHAR(36),
    number VARCHAR(20),
    description VARCHAR(255),
    description_ru VARCHAR(255),
    type_of_warehouse CHAR(36),
    category_of_warehouse VARCHAR(50), -- 'Postomat' для поштомата, інакше warehouse
    max_weight_allowed DECIMAL(10,2),
    is_active TINYINT(1) DEFAULT 1,
    KEY idx_city_ref (city_ref)
);

-- Areas (~26)
CREATE TABLE wp_uads_np_areas (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ref CHAR(36) NOT NULL UNIQUE,
    description VARCHAR(120),
    description_ru VARCHAR(120),
    center_ref CHAR(36)
);

-- Sync log
CREATE TABLE wp_uads_np_sync_log (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    started_at DATETIME,
    finished_at DATETIME,
    status VARCHAR(20),
    areas_count INT UNSIGNED,
    cities_count INT UNSIGNED,
    warehouses_count INT UNSIGNED,
    error_message TEXT
);
```

Безпечно SELECT-ити для аналітики чи export. UPDATE/INSERT робить тільки наш cron.

## WP Options

```
uads_np_api_key                          (string)  NP API key (32 chars)
uads_np_default_sender_ref               (UUID)    Sender Counterparty Ref
uads_np_default_sender_contact_ref       (UUID)    Sender ContactPerson Ref
uads_np_default_sender_phone             (string)  +380...
uads_np_default_sender_city_ref          (UUID)    NP City Ref відправника
uads_np_default_sender_city_name         (string)  Display name ("Київ")
uads_np_default_sender_warehouse_ref     (UUID)    NP Warehouse Ref відправника
uads_np_default_sender_warehouse_label   (string)  Display label
uads_hide_shipping_cost                  ('0'/'1') Toggle hide cost on checkout
uads_email_optional                      ('0'/'1') Toggle email required→optional
uads_notify_manager_on_status            ('0'/'1') Toggle email notify on TTN status change
uads_notify_manager_email                (email)   Manager email
uads_sync_schedule                       (string)  off | daily | weekly | monthly
uads_db_schema_version                   (string)  Internal schema version
uads_np_sync_status                      (array)   Last sync state (running, started_at, counts, last_error)
uads_needs_rewrite_flush                 ('0'/'1') Internal — flush rewrite rules on next init
```

## Code Examples

### Send SMS to recipient when TTN status = "У відділенні"

```php
add_action('woocommerce_order_status_changed', function ($order_id, $old, $new) {
    if ('shipped' !== $new) return;
    $order = wc_get_order($order_id);
    $ttn = $order->get_meta('_uads_np_ttn');
    if (!$ttn) return;
    $phone = $order->get_meta('_uads_np_recipient_phone');
    if (!$phone) return;
    $sms_text = sprintf('Замовлення %s прибуло у відділення Нової Пошти. ТТН: %s', $order->get_order_number(), $ttn);
    my_sms_provider_send($phone, $sms_text);
}, 10, 3);
```

### Sync new orders to KeyCRM

```php
add_action('woocommerce_checkout_create_order', function ($order, $data) {
    if ('' === $order->get_meta('_uads_np_method')) return; // not our method
    
    $payload = [
        'external_id' => $order->get_id(),
        'customer_name' => $order->get_billing_first_name() . ' ' . $order->get_meta('_billing_middle_name') . ' ' . $order->get_billing_last_name(),
        'customer_phone' => $order->get_meta('_uads_np_recipient_phone'),
        'customer_email' => $order->get_billing_email(),
        'shipping' => [
            'method' => $order->get_meta('_uads_np_method'),
            'city' => $order->get_meta('_uads_np_city_name'),
            'warehouse' => $order->get_meta('_uads_np_warehouse_description'),
        ],
        'cost_estimate' => $order->get_meta('_uads_np_estimated_shipping_cost'),
    ];
    
    wp_remote_post('https://api.keycrm.app/v1/order', [
        'headers' => ['Authorization' => 'Bearer ' . KEYCRM_TOKEN],
        'body' => wp_json_encode($payload),
    ]);
}, 20, 2);
```

### Custom email template with NP details

```php
add_action('woocommerce_email_order_meta', function ($order, $sent_to_admin, $plain_text, $email) {
    if ('' === $order->get_meta('_uads_np_method')) return;
    
    if ($plain_text) {
        echo "\n\nДоставка Новою Поштою:\n";
        echo "Місто: " . $order->get_meta('_uads_np_city_name') . "\n";
        $whouse = $order->get_meta('_uads_np_warehouse_description');
        if ($whouse) echo "Відділення: " . $whouse . "\n";
        $ttn = $order->get_meta('_uads_np_ttn');
        if ($ttn) echo "ТТН: " . $ttn . " (відстежити: https://novaposhta.ua/tracking/?cargo_number=" . $ttn . ")\n";
    } else {
        echo '<h3>Доставка Новою Поштою</h3>';
        echo '<table>';
        echo '<tr><td>Місто:</td><td>' . esc_html($order->get_meta('_uads_np_city_name')) . '</td></tr>';
        // ... etc
        echo '</table>';
    }
}, 20, 4);
```

### Read warehouse list directly (for analytics)

```php
global $wpdb;
$top_cities = $wpdb->get_results("
    SELECT c.description AS city, COUNT(w.id) AS warehouse_count
    FROM {$wpdb->prefix}uads_np_cities c
    JOIN {$wpdb->prefix}uads_np_warehouses w ON w.city_ref = c.ref
    GROUP BY c.ref
    ORDER BY warehouse_count DESC
    LIMIT 10
");
// $top_cities[0]->city = 'Київ', warehouse_count = ~432
```

## Branded Tracking Page

URL: `/uads-track/{ttn}/` (slug налаштовується в settings).

Template: `templates/tracking/tracking-page.php` (theme-overridable: copy to `your-theme/uads/tracking-page.php`).

Доступні змінні в template:
- `$ttn` (string) — TTN number
- `$status` (`Gunkov\UAShipping\Carrier\NovaPoshta\DTO\TrackingStatus|null`) — поточний статус від API

Кеш — 5 хвилин у transients.

## Architecture / Source Code

Повний design doc — [PLUGIN_ARCHITECTURE.md](https://github.com/Gunkov/ua-direct-shipping/blob/main/research/PLUGIN_ARCHITECTURE.md) (внутрішній).

Namespace `Gunkov\UAShipping\` (PSR-4).

```
src/
├── Plugin.php                              # Bootstrap, register everything
├── Carrier/NovaPoshta/
│   ├── ApiClient.php                       # generic NP API wrapper
│   ├── DTO/{Area,City,Warehouse,TrackingStatus}.php
│   ├── ShippingMethod/
│   │   ├── AbstractMethod.php              # Cost calc (fixed/weight/api)
│   │   └── {Warehouse,Poshtomat,Address}Method.php
│   ├── Repositories/{City,Warehouse}Repository.php
│   ├── Checkout/
│   │   ├── FieldsRenderer.php              # Frontend block rendering, validation
│   │   ├── AjaxController.php              # search_cities/warehouses/streets
│   │   └── OrderHandler.php                # Save meta on checkout submit
│   ├── Admin/
│   │   ├── TtnCreator.php                  # 2-step Counterparty.save → InternetDocument.save
│   │   ├── OrderMetaBox.php                # Admin order page metabox
│   │   ├── PdfProxyController.php          # Proxy NP PDFs (no API key in URL)
│   │   ├── BulkActions.php                 # Orders list bulk action
│   │   └── CsvExporter.php                 # CSV export
│   ├── Cron/
│   │   ├── TrackingCron.php                # Status polling every 6h
│   │   ├── WarehouseSyncCron.php           # Full DB sync
│   │   └── StatusMapper.php                # NP code → WC status
│   └── BrandedTracking/PageController.php
└── Common/
    ├── Cache/TransientCache.php            # WP transients wrapper
    ├── Database/SchemaInstaller.php
    ├── Http/HttpClient.php
    ├── Logger/Logger.php
    ├── Settings/SettingsPage.php
    └── Exception/{Api,Http}Exception.php
```

## Питання? Issues?

[GitHub Issues](https://github.com/Gunkov/ua-direct-shipping/issues) — для bugs, feature requests, integration questions.
