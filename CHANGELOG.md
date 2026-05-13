# ServerTrack Changelog

---

## v6.0.3 — 2026-05-12

### Critical Fix: Bootstrap Consolidation

The plugin had **two competing bootstrap systems** that were never merged, causing the frontend pixel, custom events, retry queue, and half the WooCommerce source classes to never register.

- **BUG-BOOT-1** `ServerTrack_Frontend` was never `require_once`'d — pixel never fired on any front-end page.
- **BUG-BOOT-2** `ServerTrack_CustomEvents` was never loaded — REST custom-event endpoint silently missing.
- **BUG-BOOT-3** `ServerTrack_Core::init()` in `includes/class-servertrack-core.php` was the newer system but was never called — completely dead code.
- **BUG-BOOT-4** Six WooCommerce source classes (order-status, wishlist, partial-refund, woo-renewals, woo-abandonment, subscriptions) were absent from the flat loader — hooks never registered.

**Fix:** One authoritative `servertrack_load_classes()` + `servertrack_init()` in `servertrack.php`. `class-servertrack-core.php` retained as a safe no-op backward-compat shim. Strict dependency-order loading guarantees no class is required before its dependency.

---

## v6.0.2 — 2026-05-12

### Security & Availability Fixes — Rate Limiter (PR #17)

- **BUG-H1 (Security)** REST rate limiter used the **first** `X-Forwarded-For` token, which is entirely client-controlled. An attacker could rotate fake IPs per request and bypass the limiter trivially. Fixed: use the **rightmost** (last) XFF token, which is appended by the last trusted proxy and cannot be spoofed by the client.
- **BUG-H2 (Availability)** Behind a CDN (e.g. Cloudflare), `REMOTE_ADDR` is a shared egress IP used by tens of thousands of concurrent visitors. All legitimate users mapped to one rate-limit bucket → mass 429 errors under any traffic spike. Fixed: priority chain — `HTTP_CF_CONNECTING_IP` → `HTTP_X_REAL_IP` → XFF last token → `REMOTE_ADDR`.

---

## v6.0.1 — 2026-05-12

### Bug Fixes — Subscriptions & InitiateCheckout (PR #11)

- **BUG-M1 / BUG-M6 (Critical — Dedup)** Subscription handlers called `ServerTrack_Dedup::get_event_id( $dedup_key )` and `store_event_id()` with string keys like `'renewal_123_456'`. Both functions were typed `int $order_id`, causing PHP type coercion: the string became `0`, so **all renewals read and wrote dedup state to order 0's meta**. Fresh UUID generated on every call → pixel dedup broken for 100 % of subscription events → retried renewals double-fired. Fixed: options-based dedup helpers (`ServerTrack_Dedup::get()` / `ServerTrack_Dedup::set()`) used for all non-order contexts.
- **BUG-M2 (High — Missing Block)** `send_cancelled_async()` had Meta and Google blocks but **no TikTok block** — TikTok never received `SubscriptionCancelled` events even when enabled. Fixed: TikTok block added with `PlaceAnOrder` negative-value pattern, matching the existing Meta/Google behaviour.
- **BUG-M6 (Medium — Dedup)** Same integer-dedup issue as BUG-M1 in `send_paused_async()`. Fixed in the same pass.
- **BUG-INIT (Medium)** `InitiateCheckout` events in WooCommerce sources fired without a dedup guard on the TikTok path, risking duplicates on session restore. Fixed: dedup check added before TikTok dispatch.

---

## v6.0.0 — 2026-05-12

### Major Release — Platform Expansion & Infrastructure Overhaul

#### New Features
- **Google Ads CAPI** — full server-side integration for Purchase, ViewContent, AddToCart, InitiateCheckout, Lead, and CompleteRegistration.
- **TikTok Events API v2** — upgraded from v1 to the current v2 endpoint; deduplicated pixel+server events.
- **Consent v2** (`class-servertrack-consent-v2.php`) — GDPR/TCF-aware consent layer with per-platform granularity and cron-safe bypass filter `servertrack_consent_granted`.
- **Identity Stitching** — cross-session identity graph using click-ID persistence (fbclid, ttclid, gclid) and hashed PII match keys.
- **Match Quality (EMQ) Scoring** — real-time score calculation and admin dashboard scorecard.
- **Offline Conversions** — Meta Offline Conversions API integration for CRM-uploaded events.
- **LTV Signals** — lifetime-value enrichment on Purchase events.
- **Catalog Enrichment** — `content_ids`, `content_type`, `contents` array auto-populated from WooCommerce product catalogue.
- **Webhook Outbound** (`class-servertrack-webhook.php`) — configurable outbound webhooks with secret-signed HMAC payloads and secret-at-schedule-time capture (prevents rotation race).
- **Cart Abandonment** — session-based abandonment detection with configurable window; fires `InitiateCheckout` to Meta + TikTok *(Google block added in v6.0.0-patch — see BUG-M3)*.
- **Pixel Dedup** — `class-servertrack-pixel-dedup.php` provides browser↔server deduplication via shared `eventID`.
- **Retry Queue v3** — exponential back-off, max-attempts cap, cron drain, and dashboard "Drain all now" button.

#### Known Issues Fixed Post-Launch
See v6.0.1, v6.0.2, v6.0.3 entries above.

#### Bug Fixes Included in v6.0.0 GA
- **BUG-M3 (High — Missing Block)** `check_abandonment()` had Meta and TikTok blocks but **no Google block** — cart abandonment events never reached Google Ads even when Google was enabled. Fixed: Google `InitiateCheckout` block added with consent guard.
- **BUG-M4 (Medium — Info Disclosure)** REST custom-event endpoint merged `$params` directly into `custom_data` without filtering sensitive fields. A developer passing `user_email` in params would have it logged as plaintext in `servertrack_debug_log`. Fixed: blocklist (`email`, `phone`, `credit_card`, `ssn`, `password`) stripped from `$params` before merge.
- **BUG-M5 (Medium — Silent Failure)** `ServerTrack_Consent::is_granted()` in cron/CLI context logged consent-skip notices via `ServerTrack_Logger::log()`, which respects `debug_mode`. With `debug_mode=0` (production default) the skip was **never logged** — consent failures in cron were completely invisible. Fixed: use `ServerTrack_Logger::warning()` which bypasses the debug-mode gate for operational warnings.
- **BUG-M7 (Medium — Validation)** Cart abandonment `get_email_from_session()` called `sanitize_email()` on the raw session value but never validated the result with `is_email()`. An invalid session value returned an empty string that propagated silently. Fixed: `is_email()` guard added; returns `''` cleanly when invalid.

---

## v3.3.1 — 2026-05-11

### Bug Fixes (WooCommerce Source)

- **BUG-09** `handle_order_status_change()` — dedup loop used `return` instead of `continue`; a single already-sent platform silently dropped the event for ALL platforms. Fixed: count per-platform, skip only when all 3 are done.
- **BUG-10** `fire_add_to_wishlist_event()` — dedup loop result was discarded; `dispatch_to_platforms()` was called unconditionally, guaranteeing duplicate wishlist events. Fixed: build `$pending_platforms` array, bail if empty, dispatch only to unsent platforms.
- **BUG-11** `handle_add_to_cart()` — signature declared 3 params but `woocommerce_add_to_cart` passes 6 args, causing PHP warnings on debug/strict sites. Fixed: added `$variation_id`, `$variation`, `$cart_item_data` params.
- **BUG-12** `handle_full_refund()` — dedup check only covered `meta`; TikTok and Google full-refund events were silently dropped if Meta was already sent. Fixed: check all 3 platforms before firing.

---

## v3.3.0 — 2026-05-11

### New Features

#### Admin Dashboard v2.0
- Auto-refresh live event log (AJAX, every 30 s, no page reload)
- Per-platform doughnut chart (Meta / TikTok / Google, 7-day window)
- Top-5 event types horizontal bar chart
- EMQ Scorecard with colour-coded grade pills (Excellent / Good / Fair / Poor)
- Retry queue panel with "Drain all now" AJAX button
- Clear log action (nonce-guarded, confirmation required)
- Live counter badge in header

#### WooCommerce Source — Order Status Events
- `on-hold` → fires `Lead` to all platforms
- `failed` → fires `Contact` to all platforms
- `cancelled` → fires `SubmitForm` to all platforms
- Dedup key: `order_status_{order_id}_{status}`
- Toggle: `servertrack_source_order_status_enabled` (default: on)

#### WooCommerce Source — AddToWishlist Events
- Supports YITH WooCommerce Wishlist and TI WooCommerce Wishlist
- Fires `AddToWishlist` to Meta + TikTok only (Google GA4 has no native wishlist event)
- Includes `content_ids`, `content_name`, `value`, `currency`
- Toggle: `servertrack_source_wishlist_enabled` (default: off, opt-in)

#### WooCommerce Source — Partial Refund Events
- Hook: `woocommerce_order_refunded`
- Differentiates partial vs full via ±0.01 float tolerance
- Sends `Purchase` with negative value = exact refund amount
- Dedup key: `partial_refund_{refund_id}` — exactly-once per refund object
- Toggle: `servertrack_source_partial_refund_enabled` (default: on)

#### Retry v2.2
- `process_queue()` public alias → delegates to `process()`; used by dashboard drain-all AJAX
- `event_name` stored as top-level key in queue items for dashboard display
- `last_attempt` timestamp stamped on every attempt

---

## v3.2.0 (prior)
- Subscription Renewal events (Refund, Renewal)
- Cart Abandonment integration

## v3.0 – v3.1 (prior)
- Purchase, ViewContent, AddToCart, InitiateCheckout, AddPaymentInfo, CompleteRegistration, Refund
