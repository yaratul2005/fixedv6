=== ServerTrack ===
Contributors: yaratul2005
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Tags: server-side tracking, facebook pixel, conversions api, google ads, tiktok pixel

A high-performance, zero-dependency server-side tracking plugin for WordPress. Completely bypasses ad blockers and iOS privacy restrictions.

== Description ==

ServerTrack completely bypasses browser ad blockers and iOS tracking restrictions (ITP) by moving your conversion tracking directly to your server.

Instead of relying on fragile browser pixels that can be blocked or deleted, ServerTrack communicates directly with advertising platform APIs securely from your server backend.

### ⚡ Zero Dependencies & Blazing Fast
Built strictly with core PHP and native WordPress APIs. No Composer, no NPM, no bloated vendor folders. Checkout performance is fully protected because all purchase events are offloaded to WP-Cron and executed asynchronously.

### 🎯 Supported Platforms
*   **Meta (Facebook) Conversions API (CAPI)**
*   **Google Ads (Enhanced Conversions via OAuth 2.0)**
*   **TikTok Events API**

### 🛒 Seamless Integrations
*   **WooCommerce:** Tracks `Purchase`, `ViewContent`, `AddToCart`, `InitiateCheckout`, and `Lead` (Account Registration). Intelligently handles refunds and WooCommerce Subscriptions renewal orders.
*   **Contact Form 7:** Tracks form submissions as `Lead` events. Includes a visual field mapper.
*   **Easy Digital Downloads:** Tracks `Purchase` and `Lead` (Registration) events. Supports EDD 3.0+ and legacy EDD <3.0 APIs.

### 🔒 Privacy & GDPR Compliant
ServerTrack respects user privacy natively. It strictly hashes all Personally Identifiable Information (PII) using SHA-256 before transmission and includes support for native consent modes (`granted` vs `denied`).

### 🛠️ Built-in Debugger
Stop guessing if your events are sending. ServerTrack includes a live Debug Log in the admin dashboard showing real-time HTTP response codes directly from Meta, Google, and TikTok.

== Installation ==

1. Upload the `servertrack` folder to the `/wp-content/plugins/` directory, or install the ZIP file via the WordPress Plugins menu.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Navigate to **Settings → ServerTrack** to configure your API keys and enable your desired platforms and event sources.
4. For Google Ads, go to the **Google Ads** tab, enter your OAuth Client ID and Client Secret, click **Save**, then click **Connect with Google** to complete authorisation.
5. Check the **Debug Log** tab after a test purchase to verify a 200 Success response.

== Frequently Asked Questions ==

= Does this replace the browser pixel? =
No. ServerTrack is designed to work *alongside* your browser pixel. It generates a unique `event_id` and securely shares it between the server and the browser, allowing platforms like Meta and TikTok to safely deduplicate the events without over-reporting.

= Will this slow down my checkout? =
Absolutely not. ServerTrack intercepts the purchase, generates an ID, and immediately hands control back to WooCommerce. The actual API transmission happens asynchronously in the background via WP-Cron a few moments later.

= Why isn't my CF7 form tracking? =
Ensure that Contact Form 7 is enabled in the **Sources** tab, and double-check your field mapping. If your email field in CF7 is `[email-742]`, you must enter `email-742` in the ServerTrack mapper.

= Does ServerTrack track WooCommerce Subscriptions renewals? =
Yes. Since version 1.1.0, renewal orders are tracked server-side automatically via the `woocommerce_subscription_renewal_payment_complete` hook. No browser session is needed — ServerTrack uses the billing data stored on the renewal order.

= How do I connect Google Ads? =
1. Create an OAuth 2.0 Client ID in Google Cloud Console (Web Application type).
2. Add the Redirect URI shown in the **Google Ads** tab to your Cloud Console app.
3. Paste Client ID and Client Secret into the **Google Ads** tab and click Save.
4. Click **Connect with Google** and complete the authorisation flow.

== Screenshots ==

1. The unified ServerTrack Admin Dashboard (Settings API Native).
2. Configuring WooCommerce and Contact Form 7 sources.
3. The real-time Debug Log showing successful API payloads.
4. Google Ads tab with OAuth 2.0 Connect UI and token status indicator.

== Changelog ==

= 1.1.0 =
* Added: Google OAuth 2.0 Connect UI in the Google Ads admin tab (no manual refresh token copy-paste required).
* Added: Google token status card — shows connection state, expiry time, and a one-click Disconnect button.
* Added: WooCommerce Subscriptions renewal order tracking (server-side, no browser session required).
* Fixed: Contact Form 7 plugin detection changed from `function_exists('wpcf7')` to `class_exists('WPCF7')` — fixes silent CF7 Lead tracking failures on some WordPress load orders.
* Fixed: EDD purchase sends now correctly route API failures to the retry queue. `mark_as_sent()` is only called on confirmed success.
* Fixed: EDD registration Lead sends now wired to retry queue.
* Improved: Activation guards — plugin now aborts activation with a clear error message if PHP < 7.4 or WordPress < 6.0.
* Improved: Deactivation hook now clears all four ServerTrack cron hooks (including the new renewal and retry hooks).
* Improved: Uninstall now removes HPOS order meta from `wc_orders_meta` table (WooCommerce 8.2+ with HPOS enabled).
* Improved: Uninstall now removes retry queue transients and cancels all cron hooks.
* Improved: `servertrack_consent_mode` setting is now validated against an allowlist (`none`, `granted`, `denied`).

= 1.0.0 =
* Initial public release.
* Added support for Meta CAPI, Google Ads, and TikTok Events API.
* Integrated with WooCommerce, Contact Form 7, and EDD.
* Added async WP-Cron processing engine.
* Added SHA-256 PII hasher and deduplication engine.
