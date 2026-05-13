<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ServerTrack_ClickCapture  v1.1
 *
 * Feature #2 — Server-Side Click ID Capture & Persistence.
 *
 * THE PROBLEM Stape.io cannot solve:
 *   Browser cookies for fbclid (_fbc), ttclid, gclid are subject to:
 *     - Safari ITP: 7-day expiry cap on JS-set cookies
 *     - Firefox ETP: aggressive 24h / 7-day limits
 *     - Chrome Privacy Sandbox: third-party cookie deprecation
 *     - Ad blockers clearing cookies on page load
 *
 *   Result: by the time a customer returns to complete a purchase (even 2
 *   days later), their click IDs are gone — attribution is lost.
 *
 * THE SOLUTION:
 *   A lightweight REST endpoint accepts click IDs POSTed by a tiny JS snippet
 *   that runs IMMEDIATELY on page load (before any redirect or cookie expiry).
 *   Click IDs are stored server-side:
 *     - In WP user meta for logged-in users (persists forever)
 *     - In a WordPress transient keyed by hashed session ID (30-day TTL)
 *
 *   When an order is placed, click IDs are pulled from the server-side store
 *   FIRST (most reliable), then fall back to cookie/order meta.
 *
 * ENDPOINT:
 *   POST /wp-json/servertrack/v1/capture
 *   Body: { fbclid, fbc, fbp, ttclid, gclid, session_id }
 *   Auth: none required (public endpoint — stores anonymous click data only)
 *
 * BUG-04 FIX (v1.1):
 *   get_js_snippet() previously baked a server-rendered nonce directly into
 *   the inline JS: 'X-WP-Nonce':'{$nonce}'.
 *
 *   On sites using full-page caching (LiteSpeed, WP Rocket, W3 Total Cache,
 *   Cloudflare), the same cached HTML — including the same nonce — is served
 *   to every visitor. WP nonces are user-scoped and expire after 12 hours,
 *   so cached visitors received either the wrong user's nonce or an expired
 *   one. The REST endpoint was silently rejecting these requests, meaning
 *   click IDs were never captured on any cached page.
 *
 *   Fix: the /capture endpoint has permission_callback => __return_true because
 *   it stores only anonymous click attribution data and does not perform any
 *   privileged operation. There is no security benefit to nonce-gating it.
 *   Removed wp_create_nonce() from the PHP side and removed the X-WP-Nonce
 *   header from the JS fetch() call entirely.
 *   The endpoint is now fully cache-safe and nonce-free.
 */
class ServerTrack_ClickCapture {

    /** Transient prefix for guest session click ID storage. */
    const TRANSIENT_PREFIX = 'servertrack_clicks_';

    /** WP user meta key for persistent click ID storage. */
    const USER_META_KEY = 'servertrack_click_ids';

    /** Transient TTL: 30 days (covers long consideration cycles). */
    const TTL = 30 * DAY_IN_SECONDS;

    public static function init(): void {
        add_action( 'rest_api_init', [ self::class, 'register_endpoint' ] );
    }

    // ── REST Endpoint ──────────────────────────────────────────────────────

    public static function register_endpoint(): void {
        register_rest_route(
            'servertrack/v1',
            '/capture',
            [
                'methods'             => 'POST',
                'callback'            => [ self::class, 'handle_capture' ],
                /*
                 * BUG-04 FIX: Intentionally public. This endpoint stores only
                 * anonymous click attribution data (fbclid, gclid, ttclid).
                 * No user data, no privileged operations. Nonce validation
                 * was removed because it caused silent failures on cached pages
                 * (see class docblock). Cache-safe and correct.
                 */
                'permission_callback' => '__return_true',
                'args'                => [
                    'fbclid'     => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                    'fbc'        => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                    'fbp'        => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                    'ttclid'     => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                    'gclid'      => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                    'session_id' => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                ],
            ]
        );
    }

    public static function handle_capture( WP_REST_Request $request ): WP_REST_Response {
        $data = [
            'fbclid' => $request->get_param( 'fbclid' ) ?? '',
            'fbc'    => $request->get_param( 'fbc' )    ?? '',
            'fbp'    => $request->get_param( 'fbp' )    ?? '',
            'ttclid' => $request->get_param( 'ttclid' ) ?? '',
            'gclid'  => $request->get_param( 'gclid' )  ?? '',
        ];

        // Strip empty values
        $data = array_filter( $data );

        if ( empty( $data ) ) {
            return new WP_REST_Response( [ 'stored' => false, 'reason' => 'no_data' ], 200 );
        }

        // ── Store for logged-in user ───────────────────────────────────────
        if ( is_user_logged_in() ) {
            $user_id   = get_current_user_id();
            $existing  = get_user_meta( $user_id, self::USER_META_KEY, true );
            $existing  = is_array( $existing ) ? $existing : [];
            $merged    = array_merge( $existing, $data );
            update_user_meta( $user_id, self::USER_META_KEY, $merged );
        }

        // ── Always store in session transient (covers both guest + logged-in) ─
        $session_id = $request->get_param( 'session_id' ) ?? '';
        if ( $session_id ) {
            $key      = self::TRANSIENT_PREFIX . md5( $session_id );
            $existing = get_transient( $key );
            $existing = is_array( $existing ) ? $existing : [];
            set_transient( $key, array_merge( $existing, $data ), self::TTL );
        }

        return new WP_REST_Response( [ 'stored' => true, 'keys' => array_keys( $data ) ], 200 );
    }

    // ── Retrieval API ──────────────────────────────────────────────────────

    /**
     * Retrieve stored click IDs for building order user_data.
     *
     * @param int    $customer_id  WC order customer_id (0 for guests)
     * @param string $session_id   WC session customer_id string
     * @return array { fbclid, fbc, fbp, ttclid, gclid } — only present keys
     */
    public static function get_for_order( int $customer_id, string $session_id = '' ): array {
        if ( $customer_id > 0 ) {
            $stored = get_user_meta( $customer_id, self::USER_META_KEY, true );
            if ( is_array( $stored ) && ! empty( $stored ) ) {
                return $stored;
            }
        }

        if ( $session_id ) {
            $key    = self::TRANSIENT_PREFIX . md5( $session_id );
            $stored = get_transient( $key );
            if ( is_array( $stored ) && ! empty( $stored ) ) {
                return $stored;
            }
        }

        return [];
    }

    /**
     * Returns the JS snippet for injection by ServerTrack_Frontend.
     *
     * BUG-04 FIX (v1.1):
     *   Removed wp_create_nonce() and the 'X-WP-Nonce' header from the
     *   fetch() call. The /capture endpoint is intentionally public
     *   (permission_callback => __return_true) — nonce validation was
     *   causing silent capture failures on full-page-cached sites where
     *   every visitor received the same stale nonce baked into the HTML.
     *
     * @return string Inline JavaScript (no <script> tags)
     */
    public static function get_js_snippet(): string {
        $endpoint = esc_url( rest_url( 'servertrack/v1/capture' ) );

        // phpcs:disable
        return <<<JS
(function(){
    var p = new URLSearchParams(window.location.search);
    var fbclid = p.get('fbclid') || '';
    var ttclid = p.get('ttclid') || '';
    var gclid  = p.get('gclid')  || '';
    var fbc    = '';
    var fbp    = '';
    // Read cookies
    document.cookie.split(';').forEach(function(c){
        var kv = c.trim().split('=');
        if(kv[0]==='_fbc')  fbc = decodeURIComponent(kv[1]||'');
        if(kv[0]==='_fbp')  fbp = decodeURIComponent(kv[1]||'');
        if(kv[0]==='ttclid'&&!ttclid) ttclid = decodeURIComponent(kv[1]||'');
    });
    // Build fbc from fbclid if cookie not set
    if(fbclid && !fbc){
        fbc = 'fb.1.' + Date.now() + '.' + fbclid;
    }
    if(!fbclid && !fbc && !fbp && !ttclid && !gclid) return;
    var sid = '';
    try{ sid = document.cookie.match(/wp_woocommerce_session_([^=]+)=([^;]+)/)||[]; sid = sid[2]||''; }catch(e){}
    fetch('{$endpoint}', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({fbclid:fbclid,fbc:fbc,fbp:fbp,ttclid:ttclid,gclid:gclid,session_id:sid})
    }).catch(function(){});
})();
JS;
        // phpcs:enable
    }
}
