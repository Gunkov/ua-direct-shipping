# Changelog

## 2026-05-27 — Видалено проміжний Telegram-прогрес при синхронізації

**Проблема:** При оновленні списку відділень НП `WarehouseSyncCron` надсилав у Telegram
повідомлення кожні 200 міст — ~55 повідомлень замість 2-3.

**Що зроблено:**
- Видалено `if ($processed % 200 === 0) { self::tg_progress(...) }` у циклі `sync_warehouses_for_all_cities()`
- Файл: `src/Carrier/NovaPoshta/Cron/WarehouseSyncCron.php:212-215`
- Тепер у Telegram тільки: старт (`📦 Cities synced...`), фініш (`✅ Sync done...`), помилка (`❌ Sync failed...`)
- Коміт: `10976a1` — `Remove intermediate Telegram progress during warehouse sync`
- Деплой на shiptest + skarby + kroszilla через tar+ssh
- Оновлено `docs/INSTALLATION.md` та `docs/ARCHITECTURE.md` (згадки про Telegram)
