# Installation & Setup Guide

Покроковий гайд установки та першого налаштування `ua-direct-shipping` на WooCommerce-магазині.

## Передумови

| Компонент | Мінімальна версія |
|---|---|
| PHP | 8.1 |
| WordPress | 6.5 |
| WooCommerce | 9.0 |
| MariaDB / MySQL | 10.4 / 8.0 |
| Місце на диску | ~80 MB (з повною БД відділень) |

WooCommerce має бути активований **до** активації нашого плагіна.

## Крок 1 — Установка

### Варіант A: через GitHub release

```bash
cd /var/www/yoursite/wp-content/plugins/
git clone https://github.com/Gunkov/ua-direct-shipping.git
chown -R www-data:www-data ua-direct-shipping
```

### Варіант B: через wp-cli

```bash
cd /var/www/yoursite/
sudo -u www-data wp plugin install https://github.com/Gunkov/ua-direct-shipping/archive/refs/heads/main.zip --activate
```

### Варіант C: ZIP upload через WP-admin

1. Завантажити ZIP-архів з https://github.com/Gunkov/ua-direct-shipping/archive/refs/heads/main.zip
2. WP-admin → Plugins → Add New → Upload Plugin → Install
3. Activate

## Крок 2 — Активація

WP-admin → Plugins → знайти "**UA Direct Shipping**" → Activate.

При активації плагін автоматично:
- Створить таблиці `wp_uads_np_areas`, `wp_uads_np_cities`, `wp_uads_np_warehouses`, `wp_uads_np_sync_log`
- Зашедулить WP-Cron для tracking (`uads_track_orders_event` кожні 6 годин)
- Зашедулить WP-Cron для warehouse sync (`uads_sync_warehouses_event` щонеділі за замовчуванням)
- Декларує HPOS-сумісність

## Крок 3 — Отримати API ключ Нової Пошти

1. Зайти на https://my.novaposhta.ua → залогінитись (або зареєструватись як приватна особа чи ФОП)
2. Натиснути ПІБ у правому верхньому куті → **Налаштування** → **API**
3. **Створити новий API ключ** → копіюй `ghp_xxxxx...` (32 символи)

## Крок 4 — Базове налаштування плагіна

WP-admin → **WooCommerce → UA Direct Shipping**.

### 4.1. Вставити API ключ

Поле **"API Key"** → вставити твій ключ → **Test connection**.

Має з'явитись `✅ Connected. 25 areas returned in <500ms`. Якщо помилка — перевір ключ або чи немає блокування на firewall.

### 4.2. Налаштувати Sender (відправника)

Тільки після успішного API connection. Заповни блок **"Sender Defaults"**:

- **Counterparty** — авто-підвантажується з твого НП кабінету (`Counterparty.getCounterparties`). Зазвичай одна опція "Приватна особа" або назва ФОП.
- **Contact Person** — також авто з API. Контактна особа на твоєму імені.
- **Phone** — auto-fill з ContactPerson.
- **Місто-відправник** — введи назву міста (наприклад, "Київ") → з'явиться dropdown → обери.
- **Відділення-відправник** — після вибору міста → введи номер чи частину назви → обери з dropdown.

### 4.3. Sync довідників відділень

В тій самій settings page, секція **"База довідників Нової Пошти"**:

1. Натисни **🔄 Оновити довідники Нової Пошти**
2. Підтвердь у dialog'у
3. Сторінку можна закрити — sync йде у фоні **~30-60 хвилин**
4. Прогрес у Telegram (якщо налаштовано) або в `WC → Status → Logs → ua-direct-shipping`

Після завершення:
- `Cities: ~10 977 · Warehouses: ~25 000` — готово
- Всі checkout-пошуки тепер моментальні (5-10ms замість 200-300ms через API)

### 4.4. Опційні налаштування

- **Приховувати вартість на checkout** ☑ — стандарт UA-моделі (платник = отримувач)
- **Email необов'язковий** ☑ — поле email не required для клієнтів без email
- **Сповіщення TTN status** + email менеджера — для нотифікацій про доставку
- **Автоматичний sync** — Off / Daily / Weekly (default) / Monthly

## Крок 5 — Налаштувати Shipping Zones

WP-admin → **WooCommerce → Settings → Shipping → Shipping Zones**.

1. Створити (або відкрити існуючу) зону **"Україна"**:
   - Zone name: `Україна`
   - Zone regions: `Ukraine`
2. Add shipping method 3 рази, кожен з нашими:
   - **UA Direct: Нова Пошта — Відділення**
   - **UA Direct: Нова Пошта — Поштомат**
   - **UA Direct: Нова Пошта — Адресна (кур'єр)**
3. Edit кожен:
   - Title: `Нова Пошта — у відділення` (та аналоги)
   - Cost calculation: `Реальна ставка НП (API)`
   - Free shipping від: `2000` ₴
   - Weight bands: `1|60\n3|80\n10|120\n30|200`
   - Save changes

## Крок 6 — Перевірка checkout

1. Додати товар у cart на frontend
2. Перейти на /checkout/
3. Заповнити ПІБ, Email
4. Обрати "Нова Пошта — у відділення"
5. У нашому блоці: Місто → Відділення → Телефон
6. Place Order

У admin → Orders → новий order має _uads_np_* meta. У metabox "Нова Пошта — ТТН" → Перегляд payload (dry-run) → побачиш ставку від API.

## Крок 7 (опційно) — Конвертувати checkout/cart на shortcode

Якщо WP створив checkout/cart pages з блоковим redaktor (WP 6.5+ default):

```bash
sudo -u www-data wp post update <checkout_page_id> --post_content='[woocommerce_checkout]'
sudo -u www-data wp post update <cart_page_id> --post_content='[woocommerce_cart]'
```

Або вручну через WP-admin → Pages → Cart/Checkout → Edit → видалити Block → додати Shortcode block з `[woocommerce_cart]` / `[woocommerce_checkout]`.

> **Чому:** на момент v0.1.0-dev плагін підтримує **classic checkout** (через `[woocommerce_checkout]`). Block-checkout у roadmap'і v0.2.

## Готово ✅

Магазин готовий приймати реальні замовлення з доставкою через Нову Пошту.

Подальша інструкція — [docs/INTEGRATION.md](INTEGRATION.md) для розробників (CRM/SMS/експорт), [docs/TROUBLESHOOTING.md](TROUBLESHOOTING.md) для типових проблем.
