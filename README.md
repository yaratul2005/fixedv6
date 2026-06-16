# ServerTrack

**Professional server-side Conversion API tracking for WordPress / WooCommerce.**
Fires events to Meta (Facebook), TikTok, and Google Ads simultaneously — server-side, deduplicated, consent-aware, and enriched with identity-stitching signals.

> **Current version:** `7.0.0` · Requires WordPress 6.0+ · PHP 8.0+ · WooCommerce 7.0+

---

## What is ServerTrack?

ServerTrack acts as your own **First-Party CAPI Gateway**. Instead of paying monthly for an external server-side Tag Manager container (like Stape.io), ServerTrack integrates directly inside WordPress. It routes browser pixel calls through your own domain, bypassing ad-blockers entirely, and setting 2-year resilient cookies server-side to defeat Safari's ITP.

## Core Capabilities & Advanced Modules

| Category | Capability | Description |
|---|---|---|
| **Pixel Proxy** | 1st-Party CAPI Gateway | Exposes a local REST endpoint (`/wp-json/servertrack/v1/pixel`) that accepts browser payloads securely, enriches them with real IP/UA, and forwards to Meta/TikTok, destroying the need for external ad-blockers. |
| **Cookie Helper** | Safari ITP Defeat | Automatically intercepts ad click IDs (`fbclid`, `gclid`) and issues them a `Set-Cookie` via PHP, elevating their lifespan from 7 days (JavaScript limit) to a full 2 years. |
| **Identity & EMQ** | Deep Signal Enrichment | Bundled MaxMind GeoLite logic, True-Client IP resolution across CDNs, and structured User-Agent properties to maximize Event Match Quality (EMQ). |
| **Deduplication** | Advanced 5-Min Buckets | Intelligent transient-based deduplication mechanism utilizing `SHA-256` hashing to safely deduplicate simultaneous browser + server events perfectly. |
| **Multi-Pixel** | Agency Configurations | Dynamically loop and fire events to multiple Meta Properties concurrently (e.g. Prospecting vs Retargeting pixels). |
| **Attribution** | Live UTM Histories | Persists up to 10 historical UTM touches directly to a user's session and automatically attaches the user's full marketing journey to key Conversion events (Purchase, Lead). |
| **Diagnostics** | Real-Time Debug SSE | Directly inspect CAPI event request/response payloads in real-time from the dashboard via a Server-Sent Events (SSE) data stream. |
| **Health Monitor** | Auto-Token Validation | A WP-Cron routine that continuously validates token expiry states and alerts the admin natively if an API key drops permissions. |
| **Consent** | Consent v2 Compliant | Deep integration into cookie-banner plugins to respect GDPR/CCPA limits natively without dropping legitimate async Cron event processing. |

---

## Installation

1. Upload the `servertrack/` folder to `/wp-content/plugins/`.
2. Activate the plugin through **Plugins → Installed Plugins**.
3. Navigate to **ServerTrack → Settings** to enter your platform credentials.
4. (Optional) Check out the real-time payloads under the **ServerTrack → Dashboard** tab.

## Custom Events (REST API)

Fire custom server events via Javascript explicitly through the proxy:

```javascript
fetch('/wp-json/servertrack/v1/pixel/meta', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({
    event_name: 'Lead',
    params: {
      value: 49.99,
      currency: 'USD',
      content_name: 'Newsletter signup'
      // Note: email, phone, credit_card, ssn are automatically redacted from payload logs for security
    }
  })
});
```

*Rate limited via Token Bucket Algorithm + User-Agent Fingerprinting to prevent API floods.*

---

## Technical Architecture

ServerTrack is composed of 8 advanced architectural modules engineered into a clean pipeline:

```
servertrack.php                  ← Bootstrap loader
│
├── includes/
│   ├── class-servertrack-cookiehelper.php   Server-Side Cookie Generator (ITP bypass)
│   ├── class-servertrack-proxy.php          1st-Party CAPI Proxy Endpoint
│   ├── class-servertrack-dedup-engine.php   Transient-based 5-minute deduplication hashing
│   ├── class-servertrack-enrichment.php     IP, Geo, and UA Signal enrichment
│   ├── class-servertrack-health.php         Daily API token health diagnostic cron
│   ├── class-servertrack-stream.php         Real-time SSE Debug Console
│   ├── class-servertrack-attribution.php    10-touch UTM History Tracker
│   ├── class-servertrack-consent.php        GDPR Consent State manager
│   ├── class-servertrack-event.php          Event Object DTO Model
│   ├── class-servertrack-retry.php          Exponential back-off cron queue
│   └── class-servertrack-logger.php         Structured SQL event logger
│
├── platforms/
│   ├── class-servertrack-meta.php           Meta Graph API (supports multi-pixel arrays)
│   ├── class-servertrack-tiktok.php         TikTok Events API v2
│   └── class-servertrack-google.php         Google Ads Enhanced Conversions
│
├── sources/
│   ├── class-servertrack-woocommerce.php          Core WooCommerce Hooks
│   ├── class-servertrack-source-woocommerce.php   Extended Lifecycle Hooks
│   ├── class-servertrack-subscriptions.php        WooCommerce Subscriptions integration
│   ├── class-servertrack-cart-abandonment.php     Cart Abandonment listeners
│   └── ...
│
├── frontend/
│   └── class-servertrack-frontend.php       Browser JS localization bridge
│
└── admin/
    ├── class-servertrack-dashboard.php      Real-time Dashboard UI & Charts
    └── class-servertrack-admin.php          Admin Configuration Settings
```

---

## Deduplication Logic

ServerTrack employs a flawless dual-layer mechanism:
1. **Frontend UUID Generation**: In-browser clicks (e.g. `AddToCart`) instantiate a highly unique `crypto.randomUUID()` attached to the frontend beacon.
2. **Page Load ID Synching**: PHP synchronously generates a `$servertrack_page_load_id` tied to the transient session to synchronize page-loads (`PageView`, `ViewContent`) securely with the pixel JS config.
3. **Advanced Dedup Engine**: `ServerTrack_DedupEngine` leverages `SHA-256` hashing to maintain a transient table ensuring duplicate parallel processing calls from identical browsers drop elegantly if matched within a 5-minute processing window.

## License

GPL-2.0-or-later · © MD. Yaser Ahmmed Ratul
