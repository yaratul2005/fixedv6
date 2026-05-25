# ServerTrack Core Modules & Feature Highlights

## 1. 1st-Party CAPI Gateway (Pixel Proxy)
Traditional trackers drop payloads from the browser directly to external domains (like `analytics.tiktok.com`). This fails inherently against network ad-blockers and privacy extensions. ServerTrack solves this via an internal REST bridge, safely proxying browser beacons directly into the PHP engine to maintain seamless data fidelity.

## 2. Server-Side Cookie Survival (ITP Defeat)
Safari sets a strict 7-day expiration limit on cookies created via `document.cookie`. This destroys remarketing attribution for iOS/macOS users. ServerTrack intercepts click IDs (`fbclid`, `gclid`) server-side prior to rendering HTML and enforces HTTP-only `Set-Cookie` directives, elevating the cookie's lifespan to 2 years.

## 3. Real-Time Debug Console
A built-in Server-Sent Events (SSE) diagnostic console allows administrators to view incoming traffic, outbound payload JSON arrays, and the exact HTTP response strings returned by Meta and TikTok natively without digging through raw error logs.

## 4. Advanced Match Quality Enrichment
Conversion APIs live and die by match scoring. ServerTrack strips and standardizes HTTP properties (IP, UA) via advanced fallback resolution (circumventing Cloudflare IP collapse issues) while safely hashing sensitive PII in real-time.

## 5. Multi-Touch UTM History Tracking
Users rarely convert on their first click. The UTM Attribution Engine maintains an internal history queue of up to 10 session touches, allowing advertisers to push "First Touch", "Last Touch", and "Full Path" arrays directly into Meta Custom Parameters for deep-dive ROAS reporting.

## 6. Proactive Health Validation
Tracking failures usually happen silently when tokens expire or are revoked. ServerTrack features an internal WP-Cron validation heartbeat that pings diagnostic endpoints daily and triggers direct email alerts to store administrators upon authentication failure.

## 7. Intelligent Deduplication Engine
Utilizes `wp_generate_uuid4` action-syncing coupled with a `SHA-256` transient bucketing mechanism to ensure events triggered symmetrically between the browser pixel and the server-side hooks are merged identically without data loss.

## 8. GDPR & CookieBot Integration
Deep compatibility hooks respect consent-management layers. It safely buffers asynchronous lifecycle events via persistent snapshotting so legitimate offline conversions (Subscriptions, Offline Orders) aren't artificially dropped due to missing live browser cookies.
