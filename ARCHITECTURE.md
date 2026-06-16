# ServerTrack Technical Architecture

## The Pipeline Pattern

ServerTrack strictly adheres to a one-way event pipeline: **Source → Intercept/Enrich → Dedup → Dispatch → Platform**.
No platform-specific formatting happens inside a source; no source-specific parsing happens in a platform.

### 1. Sources (Triggers)
A source intercepts user actions (e.g. WooCommerce hook, a REST API payload via proxy, Contact Form 7).
It constructs a raw `ServerTrack_Event` Data Transfer Object. The event is populated with unhashed User Data and Custom Data.

### 2. Signal Enrichment (Module 4)
Before an event finishes instantiation, `ServerTrack_Enrichment` ensures True-Client IP resolution runs against proxy header chains (CF-Connecting-IP, X-Forwarded-For). It unpacks the raw User-Agent into a normalized structure required for maximum CAPI Match Quality (Browser, OS, Device Type).

### 3. Deduplication (Module 3)
A persistent pain point for CAPI setups is multi-firing. ServerTrack employs a dual-pronged approach:
- **Order-based Dedup:** Uses WooCommerce Order Meta. Absolute, permanent deduplication mapped tightly to the transaction ID.
- **Advanced Dedup Engine:** Non-order events (ViewContent, Cart events) are transient-hashed into 5-minute deduplication buckets using `SHA-256`. If the proxy receives a browser payload identical to a PHP hook payload triggered milliseconds apart, the Transient hash acts as a hard mutex lock.

### 4. Pixel Proxy & Cookie Helper (Modules 1 & 2)
Instead of forcing the browser to speak to `connect.facebook.net` directly (which Ad-Blockers block), ServerTrack implements a 1st-party listener on `/wp-json/servertrack/v1/pixel`. This creates a direct bridge to the PHP engine.
Simultaneously, `ServerTrack_CookieHelper` bypasses Safari's Intelligent Tracking Prevention (ITP). ITP limits Javascript cookies to 7 days. By reading URL parameters natively and issuing `Set-Cookie` headers via PHP, the tracker extends click attribution bounds to a full 2 years.

### 5. Platform Dispatchers
Platform classes (`ServerTrack_Meta`, `ServerTrack_TikTok`) consume the `ServerTrack_Event` DTO. They are responsible strictly for formatting the unified DTO into the proprietary JSON payloads required by the API.
Multi-pixel logic dynamically iterates configurations and clones payloads on the fly for multiple ad accounts.

### 6. Queueing & Real-Time SSE (Module 6)
On dispatch failure (network timeout, rate limit), events fall gracefully into `ServerTrack_Retry`. The retry engine leverages an exponential backoff algorithm executed continuously by WP-Cron.
Simultaneously, all payloads (success or fail) are shipped asynchronously via Server-Sent Events (SSE) to the WP Admin dashboard for immediate operational transparency.
