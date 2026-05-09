# UA Direct Shipping

WooCommerce-плагін для української доставки **Нової Пошти** через прямий API. Без cloud-проксі, без freemium-обмежень, без per-domain ліцензій.

> v0.1.0-dev — у production на двох магазинах автора, готується до публічного релізу.

## Чим відрізняється від існуючих

Ринкова ситуація для UA-доставки в WooCommerce:

| Плагін | Архітектура | Free-tier limits | TTN limit |
|---|---|---|---|
| `wc-ukr-shipping` (kirillbdev) | Frontend до SmartyParcel cloud | Pro $9-13/міс | 20 ТТН/міс per-domain |
| `morkva-ua-shipping` (Ihor Kit) | Прямі API НП/УП/Розетки | Pro для weight-cost / юр.особа УП | без ліміту |
| `shipping-nova-poshta-for-woocommerce` (Marusenkov) | прямі API | — | — (closed wp.org 2025-12) |
| **`ua-direct-shipping` (нашо)** | **Прямі API НП без cloud** | **Усе free, GPL** | **без ліміту** |

Кут: real-time rates у Free, weight-band у Free, branded tracking on-site, без cloud-залежностей.

## Що працює (v0.1.0-dev)

**Доставка:**
- Три shipping methods: Нова Пошта — у відділення / у поштомат / Адресна (кур'єр)
- Real-time ставка через `getDocumentPrice` з кешем 1h (replace fallback fixed)
- Weight-band cost (mins-max-band → ціна за діапазон ваги)
- Free shipping threshold per method
- Volumetric weight (НП формула `L × W × H / 4000`) з product dimensions

**Checkout UX:**
- Vanilla JS + selectWoo (без важкого Vue/React)
- Local БД відділень (10977 cities + ~20-25k warehouses у `wp_uads_np_*`)
- Street autocomplete для address-доставки (через `Address.getStreet`)
- Phone validation (UA-патерн `+380XXXXXXXXX` / `0XXXXXXXXX`)
- Hide зайві WC fields (Country/State/Postcode/Address1/City) коли наш метод обраний
- Country lock на UA only
- Email optional (placeholder ніколи не вставляється — лишається порожнім)
- Cost = 0 на checkout + label "(платить отримувач у НП)" — стандарт UA-моделі. Real estimated cost у `_uads_np_estimated_shipping_cost` для аналітики магазина

**Admin TTN flow:**
- Order metabox "Нова Пошта — ТТН" з dry-run preview (показує payload що піде у API + поточну ставку + комісію за накладений платіж)
- Auto-detect COD з WC payment_method (`cod` → checkbox checked + сума автоматично)
- 2-step Counterparty.save → InternetDocument.save (для PrivatePerson recipient)
- 3-step для address: + Address.save для recipient address
- L/W/H поля з prefill з product dimensions
- Bulk action "Створити ТТН для вибраних" у Orders list
- PDF друк A4 / 100×100 / 85×85 через server-side proxy (без leak API key у URL)
- Видалення ТТН одним кліком

**Tracking:**
- WP-Cron кожні 6 годин (`getStatusDocuments`, batch до 100)
- Auto WC status mapping (NP code 9 → wc-completed; 102/103 → wc-cancelled)
- Email-нотифікація менеджеру на зміну status
- Branded tracking page on-site `/uads-track/{ttn}/` — інтегрується з темою

**Settings:**
- Sender setup через dropdown'и (Counterparty / City / Warehouse — без UUID-копіювання)
- Manual sync button + sync schedule (Off / Daily / Weekly / Monthly)
- CSV export TTN за період (UTF-8 BOM для Excel)
- Test connection до НП API
- Toggle для "приховати вартість на checkout" і "email optional"

## Що ще не реалізовано

- **Укрпошта** — потребує юр.особу для API доступу. Заплановано у v0.2 коли буде ФОП.
- **Block-checkout** — підтримка classic shortcode (`[woocommerce_checkout]`). У v0.2 за потреби.
- **wp.org публікація** — потребує brand-assets (icon, banner, screenshots).
- **Pre-warm street cache** для top-10 міст (зараз on-demand через API).

## Технічні вимоги

- PHP 8.1+
- WordPress 6.5+
- WooCommerce 9.0+
- HPOS-compatible (declared `custom_order_tables`)

## Інсталяція

1. Завантажити zip → WP-admin → Plugins → Add New → Upload Plugin
2. Activate
3. WC → UA Direct Shipping → ввести API ключ Нової Пошти ([отримати](https://my.novaposhta.ua/settings/index#apikeys))
4. Test connection → налаштувати Sender (City + Warehouse через dropdown'и)
5. Натиснути "🔄 Оновити довідники Нової Пошти" (один раз, ~30-60 хв у фоні)
6. WC → Settings → Shipping → Zone → додати наші 3 методи

## Hooks для розробників (інтеграції)

Order meta keys (для CRM/SMS/експорту):

```
_uads_np_method                        # uads_np_warehouse | uads_np_poshtomat | uads_np_address
_uads_np_city_ref                      # NP CityRef UUID
_uads_np_city_name                     # "Київ"
_uads_np_warehouse_ref                 # NP WarehouseRef UUID (для warehouse/poshtomat)
_uads_np_warehouse_number              # "62"
_uads_np_warehouse_description         # "Відділення №62: просп. Григоренка, 22/20"
_uads_np_address_street                # назва вулиці (для address)
_uads_np_address_street_ref            # NP StreetRef UUID
_uads_np_address_house                 # "18"
_uads_np_address_flat                  # "11"
_uads_np_recipient_phone               # "+380XXXXXXXXX"
_uads_np_estimated_shipping_cost       # реальна ставка з API на момент checkout
_uads_np_ttn                           # 14-цифровий номер ТТН
_uads_np_ttn_ref                       # UUID для delete/update
_uads_np_ttn_status_code               # NP code (1=Не прийнято, 7=У відділенні, 9=Доставлено, ...)
_uads_np_ttn_status_text               # Текст статусу
billing_middle_name                    # standard WC field — По-батькові
```

Filters:

- `woocommerce_billing_fields` — додаємо `billing_middle_name`
- `woocommerce_cart_needs_shipping_address` — false коли наш метод
- `woocommerce_cart_ready_to_calc_shipping` — false на /cart/

WP-Cron events:

- `uads_track_orders_event` — кожні 6 годин (tracking)
- `uads_sync_warehouses_event` — за розкладом (БД sync)

## Ліцензія

GPLv2 or later — як WordPress core. Можна форкати, модифікувати, продавати, публікувати — лише з тією самою ліцензією.

## Автор

Sergiy Gunkov — https://gunkov.pp.ua

Питання / баги / pull requests — [issues](../../issues).
