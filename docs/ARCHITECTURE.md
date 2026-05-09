# Architecture Overview

Високорівневий огляд того, як влаштований `ua-direct-shipping`. Для повного design-документа з усіма deep-research висновками — див. внутрішні `research/STAGE_0_FINDINGS.md` і `research/PLUGIN_ARCHITECTURE.md`.

## Принципи дизайну

1. **Прямі API без cloud-проксі.** Інтеграція безпосередньо з `api.novaposhta.ua` через ключ магазину. Жодних посередників.
2. **Self-contained.** Плагін повністю самодостатній: власна БД відділень, локальна логіка, не залежить від інших платних плагінів.
3. **HPOS-first.** Сумісність з High-Performance Order Storage WC з день-1.
4. **Vanilla JS на frontend.** Без Vue/React — тільки jQuery + selectWoo (WC core). Менше bundle, менше конфліктів з темами.
5. **Type-safe DTO.** Всі API responses парсимо у typed PHP 8.1 classes — не raw arrays. Захист від edge cases (`null` polя, перейменування fields у NP API).
6. **Prefer WP standard hooks** для distribution-friendly. middle name = `billing_middle_name` standard field, не custom — щоб emails/exports/native admin UI це бачили автоматично.

## Високорівнева схема

```
Покупець                                Магазин-адмін
   │                                          │
   ├─→ /shop/ → cart → /checkout/             ├─→ /wp-admin/orders → Order
   │                                          │
   │   FieldsRenderer + checkout.js           │   OrderMetaBox + admin-ttn.js
   │   AjaxController (search_*)              │   TtnCreator (2-step API)
   │   AbstractMethod (cost calc)             │   PdfProxyController
   │                                          │   BulkActions / CsvExporter
   │                                          │
   ↓                                          ↓
   OrderHandler::save_order_meta              ApiClient::request()
   _uads_np_* meta saved                      → api.novaposhta.ua
                                                ↓
                                              InternetDocument.save
                                                ↓ TTN created
                                              ┌─────────────────┐
TrackingCron (every 6h) ─────────────────────→│  NP кабінет     │
   getStatusDocuments → status updates         │  (Чернетки/     │
   email manager + WC status change            │   Активні ТТН)  │
                                              └─────────────────┘
                                                       ↑
WarehouseSyncCron (weekly) ────────────────────────────┘
   getAreas/getCities/getWarehouses
   → wp_uads_np_* tables (~25k rows)
```

## Data Flow: Checkout → ТТН

### Stage 1: Checkout (frontend, real-time)

1. User adds product → /cart/ → /checkout/.
2. `FieldsRenderer::render_fields_wrapper()` outputs `<div id="uads-checkout-fields">`.
3. `checkout.js` listens to `updated_checkout` event:
   - Detects shipping method change
   - Renders fields (city / warehouse / address) for the chosen method
   - Restores state on switch (city/phone preserved between method changes)
4. User types city → `AjaxController::handle_search_cities` → `CityRepository::search` → local DB result (5-10 ms).
5. User picks city → JS triggers `update_checkout` → WC recalculates shipping rate.
6. `AbstractMethod::calculate_shipping`:
   - Reads `WC()->session->get('uads_chosen_city_ref')` (set by `OrderHandler::capture_city_ref_for_rates`)
   - For `cost_calc_type=api` → calls `getDocumentPrice` (with 1h cache)
   - Stores real cost in session for later persistence
7. Cost displayed (or `0` + "(платить отримувач у НП)" if `uads_hide_shipping_cost=1`).
8. User submits Place Order.
9. `OrderHandler::save_order_meta` writes `_uads_np_*` meta + copies billing → shipping address.

### Stage 2: TTN creation (admin, manual or bulk)

1. Admin opens order edit page.
2. `OrderMetaBox::render_create_form` shows fields with prefills from order/product.
3. Admin clicks "Перегляд payload (dry-run)":
   - `TtnCreator::build_payload` builds the InternetDocument.save payload
   - Validates required fields
   - Additionally calls `getDocumentPrice` for live rate + COD fee preview
4. Admin clicks "Створити ТТН":
   - `TtnCreator::ensure_private_recipient`: `Counterparty.save` for PrivatePerson recipient → returns Ref + ContactPerson Ref
   - For `WarehouseDoors`: `TtnCreator::ensure_recipient_address`: `Address.getStreet` + `Address.save` → returns address Ref
   - `InternetDocument.save` with full payload → returns IntDocNumber + Ref + EstimatedDeliveryDate
   - Persists to order meta
5. Order meta now has `_uads_np_ttn`, `_uads_np_ttn_ref`, etc.
6. Admin can print PDF (A4/100×100/85×85) — via `PdfProxyController` (server-side proxy, API key not in URL).

### Stage 3: Tracking (background, automatic)

1. WP-Cron fires `uads_track_orders_event` every 6 hours.
2. `TrackingCron::execute`:
   - Fetches orders with status `processing|on-hold` and non-empty `_uads_np_ttn`
   - Builds `documents` array (TTN + phone for each order)
   - Calls `getStatusDocuments` (batch up to 100)
   - For each status update:
     - Updates `_uads_np_ttn_status_*` meta
     - Maps NP status code → WC status (via `StatusMapper`)
     - Adds order note
     - Sends email notification (if `uads_notify_manager_on_status=1`)
3. Order may transition `processing → completed` (NP code 9) or `processing → cancelled` (102/103).

### Stage 4: Branded tracking (public)

1. Customer visits `https://yoursite.com/uads-track/{ttn}/`.
2. WP rewrite rule routes to `PageController::maybe_render`.
3. Fetches status via `ApiClient::get_status_documents` (5-min cache).
4. Renders `templates/tracking/tracking-page.php` with status timeline.
5. Customer sees branded page (your domain, your theme).

## Key Design Decisions

### Чому 2-step Counterparty.save → InternetDocument.save

Для PrivatePerson recipient НП API вимагає створення Counterparty entity (через `Counterparty.save`) з усіма даними покупця. Receipts identifies recipient by Counterparty Ref + ContactPerson Ref, не plain string-name. Це дозволяє reuse одного recipient для кількох замовлень.

### Чому local DB замість on-demand API

- ~25k warehouses × частих checkout запитів = тисячі API hits на день.
- NP API rate-limit 10 req/sec, latency 200-300 ms.
- Local DB: 5-10 ms, нуль API навантаження, працює навіть якщо NP API лежить.
- Trade-off: 60-80 MB на диску, 30-60 хвилин одноразовий sync, weekly refresh (нові відділення).

### Чому street НЕ зберігаємо локально

~1 мільйон вулиць (Україна 10977 cities × avg ~100 streets). Sync = 5-10 годин, БД ~150-200 MB. Streets нечасто потрібні (тільки для адресної доставки, ~10-20% замовлень). On-demand AJAX з 1h transient cache — оптимальний баланс.

### Чому cost=0 на checkout, не реальна ставка

UA-практика: магазин не приховує доставку у вартість товара. Доставку платить **отримувач у НП відділенні**. Це історично прийняте, customer'и звикли. Фіксована модель з всіма ставками заздалегідь — некоректна для НП, бо багато факторів (вага, габарити, місто).

Real-time rate ми знаємо (через `getDocumentPrice`) і зберігаємо у `_uads_np_estimated_shipping_cost` — для analytics і admin dashboard. На UI customer не бачить.

### Чому HPOS

WC 8+ за замовчуванням використовує HPOS — окремі таблиці для orders замість `wp_posts`. Ми декларуємо compatibility з день-1, всі meta — через `$order->update_meta_data()` (не `update_post_meta`).

### Чому без Block-checkout у v0.1

Block-checkout (новий React-based UI WC 8+) використовує `@woocommerce/block-checkout` API з SlotFill — окрема ecosystem. v0.1 фокус на classic shortcode `[woocommerce_checkout]` бо:
- 95% UA-магазинів на Flatsome/Woodmart/Storefront — classic
- Block-checkout складніший (React/SlotFill) і ще не stable у багатьох темах
- Roadmap'ом v0.2-0.3

## File Structure

```
ua-direct-shipping/
├── ua-direct-shipping.php             # Plugin entry, autoload, HPOS declaration
├── composer.json                      # PSR-4 autoload Gunkov\UAShipping\
├── readme.txt                         # wp.org standard
├── README.md                          # GitHub readme
├── LICENSE                            # GPLv2
├── docs/
│   ├── INSTALLATION.md                # User-facing install guide
│   ├── INTEGRATION.md                 # Developer hooks/meta
│   ├── TROUBLESHOOTING.md             # Common issues
│   └── ARCHITECTURE.md                # This file
├── languages/                         # i18n (.pot/.po/.mo)
├── assets/
│   ├── css/{admin,checkout}.css
│   └── js/{admin-settings,admin-ttn,checkout}.js
├── src/                               # PHP source (PSR-4)
│   ├── Plugin.php
│   ├── Carrier/NovaPoshta/
│   │   ├── ApiClient.php
│   │   ├── DTO/
│   │   ├── ShippingMethod/
│   │   ├── Repositories/
│   │   ├── Checkout/
│   │   ├── Admin/
│   │   ├── Cron/
│   │   └── BrandedTracking/
│   └── Common/
│       ├── Cache/
│       ├── Database/
│       ├── Http/
│       ├── Logger/
│       ├── Settings/
│       └── Exception/
└── templates/
    └── tracking/tracking-page.php     # Theme-overridable
```

## Залежності

**Runtime:**
- WordPress 6.5+ (HPOS APIs, `wc_get_order`, etc.)
- WooCommerce 9.0+ (HPOS)
- PHP 8.1+ (typed properties, `match`, `str_contains`)
- jQuery (WP core)
- selectWoo (WC core, registered conditionally)
- Redis Object Cache (опційно — прискорює transients)

**Dev (composer):**
- phpunit/phpunit ^10
- wp-coding-standards/wpcs ^3

**Зовнішні API:**
- `api.novaposhta.ua/v2.0/json/` — основні методи
- `my.novaposhta.ua/orders/print*` — PDF marking download (через PdfProxyController)
- `api.telegram.org/bot/sendMessage` — TG прогрес під час sync (опційно, hardcoded credentials)

## Roadmap

| Версія | Фічі |
|---|---|
| **v0.1.0-dev** | Поточна. Усі core features (NP only). |
| v0.2 | Block-checkout, Укрпошта (потребує юр.особу), pre-warm street cache |
| v0.3 | Multi-sender, Bulk CSV import TTN, REST API for external CRMs |
| v0.4 | Розетка Delivery, Meest carriers (extending Carrier interface) |
| v1.0 | wp.org public release, full i18n, stable |
