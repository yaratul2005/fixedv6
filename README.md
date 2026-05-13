# ServerTrack

**Professional server-side Conversion API tracking for WordPress / WooCommerce.**
Fires events to Meta (Facebook), TikTok, and Google Ads simultaneously — server-side, deduplicated, consent-aware, and enriched with identity-stitching signals.

> **Current version:** `6.0.3` · Requires WordPress 6.0+ · PHP 8.0+ · WooCommerce 7.0+

---

## Features

| Category | Capability |
|---|---|
| **Platforms** | Meta Conversions API · TikTok Events API v2 · Google Ads Enhanced Conversions |
| **Events** | Purchase · ViewContent · AddToCart · InitiateCheckout · AddPaymentInfo · CompleteRegistration · Refund (full & partial) · Lead · Contact · SubmitForm · AddToWishlist · SubscriptionRenewal · SubscriptionCancelled · SubscriptionPaused · CartAbandonment · Custom (REST) |
| **Dedup** | Browser pixel ↔ server-side event deduplication via shared `eventID` |
| **Identity** | Click-ID persistence (fbclid, ttclid, gclid) · hashed PII (email, phone, name) · EMQ scoring |
| **Consent** | GDPR/TCF-aware per-platform consent layer · cron-safe bypass filter |
| **Reliability** | Exponential back-off retry queue · cron drain · dashboard "Drain all now" |
| **Enrichment** | Catalog enrichment (content_ids, contents) · LTV signals · Offline conversions (Meta) |
| **Webhooks** | HMAC-signed outbound webhooks with secret-at-schedule-time capture |
| **Admin** | Live event log · per-platform charts · EMQ scorecard · retry queue panel |
| **Sources** | WooCommerce · WooCommerce Subscriptions · Cart Abandonment · Contact Form 7 · Easy Digital Downloads |

---

## Installation

1. Upload the `servertrack/` folder to `/wp-content/plugins/`.
2. Activate the plugin through **Plugins → Installed Plugins**.
3. Go to **ServerTrack → Settings** and enter your platform API keys/tokens.
4. Enable the platforms you use (Meta, TikTok, Google, or any combination).
5. Optionally configure consent mode, cart abandonment window, and webhook endpoints.

---

## Configuration

### Platform Toggles

| Option | Default | Description |
|---|---|---|
| `servertrack_meta_enabled` | `0` | Enable Meta Conversions API |
| `servertrack_tiktok_enabled` | `0` | Enable TikTok Events API v2 |
| `servertrack_google_enabled` | `0` | Enable Google Ads Enhanced Conversions |
| `servertrack_debug_mode` | `0` | Write verbose debug log entries |

### Source Toggles

| Option | Default | Description |
|---|---|---|
| `servertrack_source_order_status_enabled` | `1` | Fire events on WooCommerce order status changes |
| `servertrack_source_wishlist_enabled` | `0` | Fire `AddToWishlist` (requires YITH or TI Wishlist) |
| `servertrack_source_partial_refund_enabled` | `1` | Fire negative-value `Purchase` on partial refunds |
| `servertrack_cart_abandonment_enabled` | `0` | Enable cart abandonment detection |

### Consent

Consent is evaluated per-platform via `ServerTrack_Consent::is_granted( 'meta' | 'tiktok' | 'google' )`. In cron/CLI context the check is bypassed and the filter `servertrack_consent_granted` is applied instead:

```php
// Force-grant consent for all background jobs (use only if your consent was captured at checkout)
add_filter( 'servertrack_consent_granted', '__return_true' );
```

---

## Architecture

```
servertrack.php                  ← Bootstrap: loads all classes, registers hooks
│
├── includes/
│   ├── class-servertrack-event.php          Event value object
│   ├── class-servertrack-dedup.php          Per-order and options-based dedup
│   ├── class-servertrack-consent.php        Consent gate (v1)
│   ├── class-servertrack-consent-v2.php     GDPR/TCF consent gate (v2)
│   ├── class-servertrack-retry.php          Exponential back-off retry queue
│   ├── class-servertrack-logger.php         Structured log with debug-mode gate
│   ├── class-servertrack-identity.php       Identity stitching + click-ID persistence
│   ├── class-servertrack-matchquality.php   EMQ score calculation
│   ├── class-servertrack-webhook.php        HMAC-signed outbound webhooks
│   ├── class-servertrack-offline-conversion.php  Meta Offline Conversions API
│   ├── class-servertrack-pixel-dedup.php    Browser↔server dedup (eventID)
│   ├── class-servertrack-ltv.php            Lifetime value enrichment
│   ├── class-servertrack-catalog.php        Product catalog enrichment
│   └── class-servertrack-custom-events.php  REST endpoint for custom events
│
├── platforms/
│   ├── class-servertrack-meta.php           Meta Conversions API sender
│   ├── class-servertrack-tiktok.php         TikTok Events API v2 sender
│   └── class-servertrack-google.php         Google Ads Enhanced Conversions sender
│
├── sources/
│   ├── class-servertrack-woocommerce.php          Core WooCommerce events
│   ├── class-servertrack-source-woocommerce.php   Extended WooCommerce events
│   ├── class-servertrack-subscriptions.php        WooCommerce Subscriptions events
│   ├── class-servertrack-woo-renewals.php         Renewal/cancellation hooks
│   ├── class-servertrack-cart-abandonment.php     Cart abandonment (session-based)
│   ├── class-servertrack-woo-abandonment.php      WooCommerce abandonment hooks
│   ├── class-servertrack-woo-order-status.php     Order status change events
│   ├── class-servertrack-woo-wishlist.php         AddToWishlist events
│   ├── class-servertrack-woo-partial-refund.php   Partial refund events
│   ├── class-servertrack-cf7.php                  Contact Form 7 integration
│   └── class-servertrack-edd.php                  Easy Digital Downloads integration
│
├── frontend/
│   └── class-servertrack-frontend.php       Browser pixel + REST rate limiter
│
└── admin/
    ├── class-servertrack-dashboard.php      Dashboard UI
    └── class-servertrack-admin.php          Settings page
```

---

## Custom Events (REST API)

Fire arbitrary events from JavaScript or server-side code:

```javascript
fetch('/wp-json/servertrack/v1/custom-event', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({
    event_name: 'Lead',
    params: {
      value: 49.99,
      currency: 'USD',
      content_name: 'Newsletter signup'
      // Note: email, phone, credit_card, ssn, password are blocked for security
    }
  })
});
```

Rate limit: **10 requests per minute per IP** (spoofing-resistant — uses CF-Connecting-IP → X-Real-IP → XFF last token → REMOTE_ADDR chain).

---

## Deduplication

All events are protected against double-firing by a two-layer dedup system:

1. **Browser↔Server dedup** — the browser pixel and server CAPI share the same `event_id`. Platforms use this to deduplicate.
2. **Server-side dedup** — before each platform send, `ServerTrack_Dedup::was_sent( $key, $platform )` checks whether the event was already dispatched. Uses:
   - **Order meta** (`_servertrack_event_id`) for order-scoped events.
   - **WordPress options** for non-order contexts: subscription renewals, cancellations, pauses, cart abandonment.

---

## Hooks & Filters

```php
// Override consent decision for a platform in cron/CLI context
add_filter( 'servertrack_consent_granted', function( bool $granted, string $platform ): bool {
    return true; // grant all platforms in background jobs
}, 10, 2 );

// Modify event data before sending to Meta
add_filter( 'servertrack_meta_event_data', function( array $data, string $event_name ): array {
    $data['custom_data']['my_param'] = 'my_value';
    return $data;
}, 10, 2 );

// Disable a specific source entirely
add_filter( 'servertrack_source_enabled', function( bool $enabled, string $source ): bool {
    if ( 'cart_abandonment' === $source ) return false;
    return $enabled;
}, 10, 2 );
```

---

## Known Limitations

- **Offline Conversions** — Meta only. Google Ads offline conversions and TikTok offline conversions are not yet supported (TikTok's API is in beta).
- **AddToWishlist** — fired to Meta + TikTok only; Google GA4 has no native wishlist event.
- **WooCommerce Subscriptions** — requires the official WooCommerce Subscriptions plugin.

---

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for the full version history.

**Quick summary:**

| Version | Highlights |
|---|---|
| **6.0.3** | Bootstrap consolidation — frontend pixel, custom events, all WooCommerce sources now load correctly |
| **6.0.2** | Rate limiter security fix (XFF spoofing) + CDN mass-429 fix |
| **6.0.1** | Subscription dedup string-key coercion fix · TikTok cancelled block · InitiateCheckout dedup |
| **6.0.0** | Google Ads CAPI · TikTok API v2 · Consent v2 · Identity stitching · EMQ scoring · Offline conversions · LTV · Catalog enrichment · Webhooks · Cart abandonment · Pixel dedup · Retry v3 · Cart abandonment Google block · REST PII filter · Consent warning log · Email validation |
| 3.3.1 | WooCommerce source dedup loop fixes (BUG-09 – BUG-12) |
| 3.3.0 | Admin Dashboard v2 · Order status events · Wishlist events · Partial refund events · Retry v2.2 |
| 3.2.0 | Subscription renewals · Cart abandonment |
| 3.0–3.1 | Core WooCommerce events |

---

## License

GPL-2.0-or-later · © MD. Yaser Ahmmed Ratul
