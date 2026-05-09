=== UA Direct Shipping ===
Contributors: gunkov
Tags: woocommerce, shipping, nova poshta, ukrposhta, ukraine
Requires at least: 6.5
Tested up to: 6.9
Requires PHP: 8.1
Stable tag: 0.1.0-dev
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Direct API integration for Nova Poshta (Ukrposhta planned). No cloud proxies, no freemium gates, no per-domain limits.

== Description ==

UA Direct Shipping connects WooCommerce stores to Nova Poshta delivery service via the official API directly — without cloud middlemen, freemium plans, or shipment-count limits.

**Why direct API:** existing plugins route every label/tracking/rate request through their own cloud infrastructure, which charges per shipment after a free quota and limits each store to one domain license. UA Direct Shipping uses your own NP API key and talks to api.novaposhta.ua straight from your server.

**Features (v0.1.0-dev):**

* Three shipping methods (Warehouse pickup / Poshtomat / Address courier) — registered in WC Shipping Zones
* **Real-time shipping rate** via Nova Poshta `getDocumentPrice` — free, not Pro-gated (recipient city is captured at checkout, sender city + warehouse from settings; result cached 1h)
* **Weight-band cost** tables — free, not Pro-gated (configurable per shipping method)
* Free shipping threshold per method (default 2000 ₴)
* TTN creation from order admin via two-step flow: `Counterparty.save` (PrivatePerson recipient) → `InternetDocument.save`
* Dry-run preview shows exact API payload before any real call — confirmation gate before creating in NP cabinet
* TTN deletion via `InternetDocument.delete` for cleanup
* PDF marking print (A4 / 100×100 / 85×85) via server-side proxy — **API key is never exposed in browser URL**
* Auto-tracking via WP-Cron (every 6h, batch 100 TTNs) — updates `_uads_np_ttn_status_*` meta + maps NP status codes to WC order statuses (configurable; defaults: 9 → completed, 102/103 → cancelled)
* Branded tracking page on your domain at `/{slug}/{ttn}/` — theme-overridable template
* HPOS-compatible from day one (declares `custom_order_tables`)
* PDF proxy with capability check + nonce
* Vanilla JS + selectWoo (WooCommerce core) on checkout — no Vue/React bundle

**Roadmap:**

* v0.2: Ukrposhta integration (requires legal entity API access from sole proprietor)
* v0.2: Block-based checkout support
* v0.3: Bulk CSV TTN export, multi-sender, REST API for CRMs

**Why not just use the existing plugins?**

* The current market leader routes everything through SmartyParcel cloud — 20 free TTNs/month per domain, then $0.03-$0.04 per shipment.
* Other plugins lack real-time rates or weight-based costs in their free tiers.
* Most use heavy Vue/React frontends that conflict with popular themes (Flatsome, Woodmart, Divi).

UA Direct Shipping is GPL-2 forever, with no Pro version planned.

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/ua-direct-shipping/`
2. Activate the plugin through the Plugins menu
3. Go to WooCommerce → UA Direct Shipping
4. Enter your Nova Poshta API key (get one at https://my.novaposhta.ua/settings/index#apikeys)
5. Test the connection
6. Configure shipping methods in WooCommerce → Settings → Shipping → Shipping Zones

== Frequently Asked Questions ==

= Does this plugin support WooCommerce checkout blocks? =

Not in v0.1. Classic shortcode-based checkout (`[woocommerce_checkout]`) only. Block-checkout support planned for v0.2.

= Do I need a Nova Poshta business account? =

No. Standard NP API access (free for any registered NP user) is sufficient.

= What about Ukrposhta? =

Ukrposhta API requires a legal entity contract. Planned for v0.2 once developer has access.

= Can I use this alongside another shipping plugin? =

Yes, but disable the other plugin's NP shipping methods to avoid duplicates.

== Changelog ==

= 0.1.0-dev (2026-05-08) =

* Initial development release.
* Settings page with NP API key + Test connection + sender (counterparty/contact/city/warehouse) defaults.
* DTO/cache/AJAX layer for cities and warehouses search at checkout.
* Three WC shipping methods (Warehouse / Poshtomat / Address) with fixed/weight-band/real-time-API cost calculation.
* Checkout fields renderer + handler (HPOS-aware), saves `_uads_np_*` meta on every order.
* Real-time NP `getDocumentPrice` integration with transient cache.
* Order metabox for TTN creation, dry-run preview, and deletion.
* Two-step Counterparty.save → InternetDocument.save flow for PrivatePerson recipients.
* PDF proxy controller for A4/100×100/85×85 marking print without leaking the API key.
* Tracking cron (every 6h) + branded on-site tracking page with rewrite rule.
* `.pot` file generated for translations (uk_UA + en_US locales planned).

== Upgrade Notice ==

= 0.1.0-dev =

Development release. Not for production use.
