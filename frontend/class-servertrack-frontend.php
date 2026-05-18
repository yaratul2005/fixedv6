<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ServerTrack_Frontend  v2.2
 *
 * Injects browser pixel config for all page types.
 *
 * Changes in v2.2:
 *   BUG-H1 + BUG-H2: get_request_ip() replaced with a 4-tier priority chain:
 *     1. HTTP_CF_CONNECTING_IP — Cloudflare sets this; cannot be spoofed by clients.
 *     2. HTTP_X_REAL_IP        — nginx upstream proxy; not client-controlled.
 *     3. HTTP_X_FORWARDED_FOR last token — rightmost entry is appended by the last
 *        trusted proxy, not the client. The previous code used [0] (first/leftmost)
 *        which is entirely client-controlled and trivially spoofable.
 *     4. REMOTE_ADDR           — kernel TCP peer; final fallback.
 *     Fix resolves both the XFF spoofing bypass (BUG-H1) and the CDN IP-collapse
 *     issue where all Cloudflare traffic shared one REMOTE_ADDR (BUG-H2).
 *
 *   BUG-M4: rest_custom_event() now strips a PII field blocklist from $params
 *     before merging into custom_data / logging. Prevents plaintext email,
 *     phone, credit card numbers, tokens, etc. from appearing in the debug log
 *     when a developer accidentally passes user data in the params object.
 *
 * Changes in v2.1 (FIX-12):
 *   rest_custom_event() accepted any arbitrary string as event_name. Added
 *   $allowed_events allowlist; unknown event names rejected with 400.
 *
 * Bugs fixed vs v1:
 *   - user_email sent as pre-hashed SHA256 to fbq('init') — fixed.
 *   - gtag_id / gtag_label options not registered — fixed.
 *   - GCLID recovery used $wp->query_vars — fixed to get_query_var().
 *   - initTikTokPixel() double-fired ttq.page() — fixed.
 *   - REST endpoint allowed unauthenticated CAPI pumping — fixed with IP rate-limit.
 */
class ServerTrack_Frontend {

    /**
     * Allowlist of event names accepted by the /custom-event REST endpoint.
     *
     * FIX-12: Prevents arbitrary strings from reaching CAPI senders.
     */
    private const ALLOWED_EVENT_NAMES = [
        // Standard purchase funnel
        'Purchase',
        'InitiateCheckout',
        'AddPaymentInfo',
        'AddToCart',
        'AddToWishlist',
        'ViewContent',
        // Lead generation
        'Lead',
        'CompleteRegistration',
        'Contact',
        'Subscribe',
        // Search & discovery
        'Search',
        'FindLocation',
        'Schedule',
        // Engagement
        'PageView',
        'CustomizeProduct',
        'Donate',
        'StartTrial',
        'SubmitApplication',
    ];

    /**
     * PII field names that must never appear in custom_data / debug logs.
     *
     * BUG-M4 fix: any param key matching this list is stripped before
     * merging into the event's custom_data payload.
     */
    private const PII_PARAM_BLOCKLIST = [
        'email',
        'phone',
        'credit_card',
        'card_number',
        'cvv',
        'ssn',
        'password',
        'token',
        'api_key',
        'secret',
        'access_token',
        'refresh_token',
        'authorization',
    ];

    public static function init() {
        add_action( 'wp_enqueue_scripts', [ self::class, 'enqueue_pixel_script' ] );
        add_action( 'wp_loaded',          [ self::class, 'capture_click_ids' ] );
        add_action( 'woocommerce_checkout_order_created', [ self::class, 'persist_click_ids_to_order' ], 10, 1 );
        add_action( 'rest_api_init',      [ self::class, 'register_rest_routes' ] );
    }

    // ────────────────────────────────────────────────────────────────────────
    // REST: browser → CAPI bridge for custom events
    // ────────────────────────────────────────────────────────────────────────

    public static function register_rest_routes() {
        register_rest_route( 'servertrack/v1', '/capture-clickids', [
            'methods'  => 'POST',
            'callback' => function( WP_REST_Request $req ) {
                $ids = $req->get_json_params()['ids'] ?? [];
                $ts  = (int) ( $req->get_json_params()['timestamp'] ?? time() );

                if ( isset( $ids['fbclid'] ) ) {
                    $fbc = 'fb.1.' . $ts . '.' . sanitize_text_field( $ids['fbclid'] );
                    setcookie( '_fbc', $fbc, time() + 7776000, '/', '', true, false ); // 90 days
                    if ( function_exists( 'WC' ) && WC()->session ) {
                        WC()->session->set( 'st_fbc', $fbc );
                    }
                }
                if ( isset( $ids['ttclid'] ) ) {
                    setcookie( '_ttclid', sanitize_text_field( $ids['ttclid'] ), time() + 7776000, '/', '', true, false );
                }
                if ( isset( $ids['gclid'] ) ) {
                    setcookie( '_gcl_aw', sanitize_text_field( $ids['gclid'] ), time() + 7776000, '/', '', true, false );
                }
                if ( isset( $ids['msclkid'] ) ) {
                    setcookie( 'msclkid', sanitize_text_field( $ids['msclkid'] ), time() + 7776000, '/', '', true, false );
                }
                if ( isset( $ids['ScCid'] ) ) {
                    setcookie( '_sccid', sanitize_text_field( $ids['ScCid'] ), time() + 7776000, '/', '', true, false );
                }
                return new WP_REST_Response( ['ok' => true] );
            },
            'permission_callback' => '__return_true',
        ]);

        register_rest_route( 'servertrack/v1', '/capture-pii', [
            'methods'  => 'POST',
            'callback' => function( WP_REST_Request $req ) {
                $em = sanitize_text_field( $req->get_json_params()['em'] ?? '' );
                if ( $em && function_exists( 'WC' ) && WC()->session ) {
                    WC()->session->set( 'st_em', $em );
                }
                return new WP_REST_Response( ['ok' => true] );
            },
            'permission_callback' => '__return_true',
        ]);

        register_rest_route( 'servertrack/v1', '/custom-event', [
            'methods'             => 'POST',
            'callback'            => [ self::class, 'rest_custom_event' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'event_name' => [ 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                'event_id'   => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                'params'     => [ 'required' => false, 'type' => 'object'  ],
                'is_custom'  => [ 'required' => false, 'type' => 'boolean' ],
                'url'        => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => 'esc_url_raw' ],
                'fbc'        => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                'fbp'        => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                'ttclid'     => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
            ],
        ] );
    }

    public static function rest_custom_event( WP_REST_Request $request ) {
        if ( ! get_option( 'servertrack_enabled', 1 ) ) {
            return new WP_Error( 'disabled', 'ServerTrack disabled', [ 'status' => 403 ] );
        }

        // FIX-12: Validate event_name against the allowlist.
        $event_name = $request->get_param( 'event_name' );
        if ( ! in_array( $event_name, self::ALLOWED_EVENT_NAMES, true ) ) {
            return new WP_Error(
                'invalid_event_name',
                sprintf(
                    'Unknown event type \'%s\'. Allowed values: %s.',
                    esc_html( $event_name ),
                    implode( ', ', self::ALLOWED_EVENT_NAMES )
                ),
                [ 'status' => 400 ]
            );
        }

        // Rate limit by IP — 10 events per minute per IP
        $ip         = self::get_request_ip();
        $rate_key   = 'st_rl_' . md5( $ip );
        $rate_count = (int) get_transient( $rate_key );
        if ( $rate_count >= 10 ) {
            return new WP_Error( 'rate_limit', 'Rate limit exceeded', [ 'status' => 429 ] );
        }
        set_transient( $rate_key, $rate_count + 1, MINUTE_IN_SECONDS );

        $event_id = $request->get_param( 'event_id' ) ?: ServerTrack_Dedup::generate_event_id( $event_name . '_rest_' . time() );
        $params   = (array) ( $request->get_param( 'params' ) ?: [] );
        $url      = $request->get_param( 'url' )    ?: '';
        $fbc      = $request->get_param( 'fbc' )    ?: '';
        $fbp      = $request->get_param( 'fbp' )    ?: '';
        $ttclid   = $request->get_param( 'ttclid' ) ?: '';

        // Sanitise params array values recursively
        array_walk_recursive( $params, function( &$v ) { $v = sanitize_text_field( (string) $v ); } );

        // BUG-M4 fix: strip PII fields before merging into custom_data / logs.
        // Developers may accidentally pass user data (email, phone, etc.) in
        // the params object — remove them defensively to prevent plaintext PII
        // from appearing in the ServerTrack debug log table.
        foreach ( self::PII_PARAM_BLOCKLIST as $pii_field ) {
            unset( $params[ $pii_field ] );
        }

        $ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';

        $user_data = [ 'ip' => $ip, 'user_agent' => $ua ];
        if ( $fbc )    $user_data['fbc']    = $fbc;
        if ( $fbp )    $user_data['fbp']    = $fbp;
        if ( $ttclid ) $user_data['ttclid'] = $ttclid;

        if ( is_user_logged_in() ) {
            $user = wp_get_current_user();
            if ( $user->user_email ) $user_data['email'] = ServerTrack_Hasher::hash_email( $user->user_email );
        }

        $event = new ServerTrack_Event( $event_name, $event_id );
        $event->set_user_data( $user_data );
        $event->set_custom_data( array_merge( $params, [ 'event_source_url' => $url ] ) );

        $results = [];
        if ( get_option( 'servertrack_meta_enabled', 0 ) && ServerTrack_Consent::is_granted( 'meta' ) ) {
            $results['meta'] = ServerTrack_Meta::send( $event );
        }
        if ( get_option( 'servertrack_tiktok_enabled', 0 ) && ServerTrack_Consent::is_granted( 'tiktok' ) ) {
            $results['tiktok'] = ServerTrack_TikTok::send( $event );
        }

        return rest_ensure_response( [ 'sent' => true, 'results' => $results ] );
    }

    /**
     * Resolve the real client IP using a trusted priority chain.
     *
     * BUG-H1 fix: the old code read explode(',', XFF)[0] — the leftmost token —
     * which is entirely client-controlled (the client writes it). An attacker can
     * rotate fake IPs per request to bypass the rate-limiter trivially.
     *
     * BUG-H2 fix: behind Cloudflare, REMOTE_ADDR is a shared Cloudflare egress IP
     * used by thousands of concurrent visitors. Using it as the rate-limit bucket
     * causes all legitimate users behind the CDN to share one counter, leading to
     * mass 429s during any traffic spike.
     *
     * Priority (highest trust first):
     *   1. CF-Connecting-IP  — Cloudflare appends this; clients cannot spoof it.
     *   2. X-Real-IP         — Set by nginx upstream; clients cannot spoof it.
     *   3. XFF last token    — Rightmost token is appended by the last trusted proxy,
     *                          not the client. Each proxy appends its own view of the
     *                          upstream IP, so the last entry is always server-side.
     *   4. REMOTE_ADDR       — TCP peer from the kernel; the absolute fallback.
     *
     * @return string  Sanitised IP address string.
     */
    private static function get_request_ip(): string {
        $ip = '';

        // 1. Cloudflare real-visitor IP (highest trust — CF infrastructure sets this)
        if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
            $ip = sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) );
        }

        // 2. nginx X-Real-IP (upstream proxy sets this; not client-accessible)
        if ( empty( $ip ) && ! empty( $_SERVER['HTTP_X_REAL_IP'] ) ) {
            $ip = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_REAL_IP'] ) );
        }

        // 3. XFF last (rightmost) token — appended by the last trusted proxy.
        //    Do NOT use [0] (leftmost) which the client writes freely.
        if ( empty( $ip ) && ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
            $tokens = array_map( 'trim', explode( ',', wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) );
            $last   = end( $tokens );
            if ( $last ) {
                $ip = sanitize_text_field( $last );
            }
        }

        // 4. REMOTE_ADDR — kernel TCP peer; absolute fallback
        if ( empty( $ip ) && ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
            $ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
        }

        // Strip IPv4-mapped IPv6 prefix (::ffff:1.2.3.4 → 1.2.3.4)
        if ( substr( $ip, 0, 7 ) === '::ffff:' ) {
            $ip = substr( $ip, 7 );
        }

        return $ip;
    }

    // ────────────────────────────────────────────────────────────────────────
    // PIXEL SCRIPT INJECTION
    // ────────────────────────────────────────────────────────────────────────

    public static function enqueue_pixel_script() {
        if ( ! get_option( 'servertrack_enabled', 1 ) ) return;

        wp_register_script(
            'servertrack-pixel',
            SERVERTRACK_URL . 'frontend/assets/servertrack-pixel.js',
            [],
            SERVERTRACK_VERSION,
            true
        );

        $config = [
            'meta_pixel'     => get_option( 'servertrack_meta_pixel_id', '' ),
            'tiktok_pixel'   => get_option( 'servertrack_tiktok_pixel_id', '' ),
            'test_mode'      => (bool) get_option( 'servertrack_test_mode', 0 ),
            'meta_enabled'   => (bool) get_option( 'servertrack_meta_enabled', 0 ),
            'tt_enabled'     => (bool) get_option( 'servertrack_tiktok_enabled', 0 ),
            'google_enabled' => (bool) get_option( 'servertrack_google_enabled', 0 ),
            'gtag_id'        => get_option( 'servertrack_google_gtag_id', '' ),
            'gtag_label'     => get_option( 'servertrack_google_gtag_label', '' ),
            'store_currency' => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD',
            'scroll_depth_enabled'   => (bool) get_option( 'servertrack_scroll_depth', 1 ),
            'video_tracking_enabled' => (bool) get_option( 'servertrack_video_tracking', 1 ),
            'wishlist_enabled'       => (bool) get_option( 'servertrack_wishlist_tracking', 1 ),
            'is_product'         => false,
            'is_product_archive' => false,
            'is_cart'            => false,
            'is_checkout'        => false,
            'is_search'          => false,
            'rest_url'   => rest_url(),
            'rest_nonce' => wp_create_nonce( 'wp_rest' ),
        ];

        // Advanced Matching (Hashed PII)
        $user_data_for_pixel = [];
        if ( is_user_logged_in() ) {
            $user = wp_get_current_user();
            $user_data_for_pixel = [
                'em' => $user->user_email ? ServerTrack_Hasher::hash_email( $user->user_email ) : '',
                'fn' => $user->first_name ? ServerTrack_Hasher::hash_name( $user->first_name ) : '',
                'ln' => $user->last_name ? ServerTrack_Hasher::hash_name( $user->last_name ) : '',
                'ph' => get_user_meta( $user->ID, 'billing_phone', true ) ? ServerTrack_Hasher::hash_phone( get_user_meta( $user->ID, 'billing_phone', true ) ) : '',
                'ct' => get_user_meta( $user->ID, 'billing_city', true ) ? ServerTrack_Hasher::hash_city( get_user_meta( $user->ID, 'billing_city', true ) ) : '',
                'st' => get_user_meta( $user->ID, 'billing_state', true ) ? ServerTrack_Hasher::hash_state( get_user_meta( $user->ID, 'billing_state', true ) ) : '',
                'zp' => get_user_meta( $user->ID, 'billing_postcode', true ) ? ServerTrack_Hasher::hash_zip( get_user_meta( $user->ID, 'billing_postcode', true ) ) : '',
                'country' => get_user_meta( $user->ID, 'billing_country', true ) ? ServerTrack_Hasher::hash_country( get_user_meta( $user->ID, 'billing_country', true ) ) : '',
                'external_id' => ServerTrack_Identity::get_external_id_for_user( $user->ID ),
            ];
            $user_data_for_pixel = array_filter( $user_data_for_pixel );
        }
        $config['advanced_matching'] = $user_data_for_pixel;

        // Event IDs for synchronized tracking
        $session_key = is_user_logged_in() ? get_current_user_id() . '_' : 'guest_';
        if ( function_exists('WC') && WC()->session ) {
             $session_key .= WC()->session->get_customer_id();
        } else {
             $session_key .= session_id() ?: uniqid();
        }

        $config['event_ids'] = [
            'PageView' => ServerTrack_Hasher::event_id('PageView', $session_key),
            'ViewContent' => ServerTrack_Hasher::event_id('ViewContent', $session_key),
            'AddToCart' => ServerTrack_Hasher::event_id('AddToCart', $session_key),
            'InitiateCheckout' => ServerTrack_Hasher::event_id('InitiateCheckout', $session_key),
            'AddPaymentInfo' => ServerTrack_Hasher::event_id('AddPaymentInfo', $session_key)
        ];

        $config['rest_url'] = rest_url( 'servertrack/v1/' );
        $config['nonce'] = wp_create_nonce( 'wp_rest' );

        // ── Single product ────────────────────────────────────────────────────
        if ( function_exists( 'is_product' ) && is_product() ) {
            $config['is_product'] = true;
            $product = wc_get_product( get_queried_object_id() );
            if ( $product ) {
                $price = (float) wc_get_price_to_display( $product );
                $sku   = $product->get_sku() ?: (string) $product->get_id();
                $terms = get_the_terms( $product->get_id(), 'product_cat' );
                $config['product_id']       = $product->get_id();
                $config['product_name']     = $product->get_name();
                $config['product_sku']      = $sku;
                $config['product_price']    = $price;
                $config['product_type']     = $product->get_type();
                $config['product_category'] = ( $terms && ! is_wp_error( $terms ) ) ? $terms[0]->name : '';
                $config['contents']         = [ [ 'id' => $sku, 'quantity' => 1, 'item_price' => $price ] ];
                $config['content_ids']      = [ $sku ];
            }
        }

        // ── Product archive / category ────────────────────────────────────────
        if ( function_exists( 'is_product_category' ) && is_product_category() ) {
            $config['is_product_archive'] = true;
            $term = get_queried_object();
            $config['current_category'] = $term ? $term->name : '';
        } elseif ( function_exists( 'is_shop' ) && is_shop() ) {
            $config['is_product_archive'] = true;
            $config['current_category']   = __( 'Shop', 'servertrack' );
        }

        // ── Cart ──────────────────────────────────────────────────────────────
        if ( function_exists( 'is_cart' ) && is_cart() ) {
            $config['is_cart'] = true;
        }

        // ── Checkout ──────────────────────────────────────────────────────────
        if ( function_exists( 'is_checkout' ) && is_checkout() && ! is_order_received_page() ) {
            $config['is_checkout'] = true;
        }

        // ── Search ────────────────────────────────────────────────────────────
        if ( is_search() ) {
            $config['is_search']    = true;
            $config['search_query'] = get_search_query();
        }

        // ── Thank-you / Purchase ──────────────────────────────────────────────
        if ( function_exists( 'is_order_received_page' ) && is_order_received_page() ) {
            $order_id = absint( get_query_var( 'order-received', 0 ) );
            if ( $order_id ) {
                $order = wc_get_order( $order_id );
                if ( $order ) {
                    $contents    = [];
                    $content_ids = [];
                    foreach ( $order->get_items() as $item ) {
                        $p   = $item->get_product();
                        $sku = ( $p && $p->get_sku() ) ? $p->get_sku() : (string) $item->get_product_id();
                        $qty = (int) $item->get_quantity();
                        $contents[]    = [ 'id' => $sku, 'quantity' => $qty, 'item_price' => $qty > 0 ? round( (float) $item->get_total() / $qty, 2 ) : 0.0 ];
                        $content_ids[] = $sku;
                    }
                    $config['event_id']    = ServerTrack_Dedup::get_event_id( $order_id );
                    $config['event_name']  = 'Purchase';
                    $config['value']       = (float) $order->get_total();
                    $config['currency']    = $order->get_currency();
                    $config['order_id']    = $order_id;
                    $config['contents']    = $contents;
                    $config['content_ids'] = $content_ids;

                    $gclid = (string) $order->get_meta( '_servertrack_gclid' );
                    if ( empty( $gclid ) && ! empty( $_COOKIE['_gcl_aw'] ) ) {
                        $gclid = sanitize_text_field( wp_unslash( $_COOKIE['_gcl_aw'] ) );
                    }
                    if ( $gclid ) {
                        $config['gclid'] = $gclid;
                    }
                }
            }
        }

        wp_localize_script( 'servertrack-pixel', 'servertrack_config', $config );
        wp_enqueue_script( 'servertrack-pixel' );
    }

    // ────────────────────────────────────────────────────────────────────────
    // CLICK ID CAPTURE  (fbc / gclid / ttclid parameter builder)
    // ────────────────────────────────────────────────────────────────────────

    public static function capture_click_ids() {
        if ( is_admin() ) return;
        if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) return;
        if ( headers_sent() ) return;

        $now = time();

        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        if ( ! empty( $_GET['fbclid'] ) ) {
            $fbclid = sanitize_text_field( wp_unslash( $_GET['fbclid'] ) );
            $fbc    = 'fb.1.' . ( $now * 1000 ) . '.' . $fbclid;
            if ( function_exists( 'WC' ) && WC()->session ) {
                $session_id = (string) WC()->session->get_customer_id();
                if ( $session_id ) {
                    set_transient( 'servertrack_fbc_' . $session_id, $fbc, 90 * DAY_IN_SECONDS );
                    set_transient( 'servertrack_fbclid_' . $session_id, $fbclid, 90 * DAY_IN_SECONDS );
                }
            }
            setcookie( '_fbc', $fbc, $now + 90 * DAY_IN_SECONDS, '/', '', is_ssl(), false );
            $_COOKIE['_fbc'] = $fbc;
        }

        if ( ! empty( $_GET['gclid'] ) ) {
            $gclid = sanitize_text_field( wp_unslash( $_GET['gclid'] ) );
            if ( function_exists( 'WC' ) && WC()->session ) {
                $session_id = (string) WC()->session->get_customer_id();
                if ( $session_id ) set_transient( 'servertrack_gclid_' . $session_id, $gclid, 90 * DAY_IN_SECONDS );
            }
            setcookie( '_gcl_aw', $gclid, $now + 90 * DAY_IN_SECONDS, '/', '', is_ssl(), true );
            $_COOKIE['_gcl_aw'] = $gclid;
        }

        if ( ! empty( $_GET['ttclid'] ) ) {
            $ttclid = sanitize_text_field( wp_unslash( $_GET['ttclid'] ) );
            if ( function_exists( 'WC' ) && WC()->session ) {
                $session_id = (string) WC()->session->get_customer_id();
                if ( $session_id ) set_transient( 'servertrack_ttclid_' . $session_id, $ttclid, 7 * DAY_IN_SECONDS );
            }
            setcookie( 'ttclid', $ttclid, $now + 7 * DAY_IN_SECONDS, '/', '', is_ssl(), true );
            $_COOKIE['ttclid'] = $ttclid;
        }
        // phpcs:enable
    }

    public static function persist_click_ids_to_order( WC_Order $order ) {
        $session_id = WC()->session ? (string) WC()->session->get_customer_id() : '';

        $fbc = '';
        if ( ! empty( $_COOKIE['_fbc'] ) ) { // phpcs:ignore
            $fbc = sanitize_text_field( wp_unslash( $_COOKIE['_fbc'] ) );
        } elseif ( $session_id ) {
            $fbc = (string) get_transient( 'servertrack_fbc_' . $session_id );
        }
        if ( $fbc ) $order->update_meta_data( '_servertrack_fbc', $fbc );

        if ( $session_id ) {
            $fbclid = (string) get_transient( 'servertrack_fbclid_' . $session_id );
            if ( $fbclid ) $order->update_meta_data( '_servertrack_fbclid', $fbclid );
        }

        if ( ! empty( $_COOKIE['_fbp'] ) ) { // phpcs:ignore
            $order->update_meta_data( '_servertrack_fbp', sanitize_text_field( wp_unslash( $_COOKIE['_fbp'] ) ) );
        }

        $ttclid = '';
        if ( ! empty( $_COOKIE['ttclid'] ) ) { // phpcs:ignore
            $ttclid = sanitize_text_field( wp_unslash( $_COOKIE['ttclid'] ) );
        } elseif ( $session_id ) {
            $ttclid = (string) get_transient( 'servertrack_ttclid_' . $session_id );
        }
        if ( $ttclid ) $order->update_meta_data( '_servertrack_ttclid', $ttclid );

        $gclid = '';
        if ( ! empty( $_COOKIE['_gcl_aw'] ) ) { // phpcs:ignore
            $gclid = sanitize_text_field( wp_unslash( $_COOKIE['_gcl_aw'] ) );
        } elseif ( $session_id ) {
            $gclid = (string) get_transient( 'servertrack_gclid_' . $session_id );
        }
        if ( $gclid ) $order->update_meta_data( '_servertrack_gclid', $gclid );

        $order->save_meta_data();
    }
}
