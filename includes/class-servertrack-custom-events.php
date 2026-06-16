<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ServerTrack_CustomEvents  v3.0
 *
 * Provides a PHP action hook for firing custom CAPI events from
 * themes, other plugins, or custom code:
 *
 *   do_action( 'servertrack_custom_event', 'EventName', [ 'param' => 'value' ] );
 *
 * Also handles:
 *   - Search  (WP search queries → Meta Search + TikTok Search)
 *   - ViewCategory (WooCommerce shop/category archives → Meta ViewCategory)
 *   - UserRegistration outside WooCommerce (wp_register_user)
 *   - ContactForm7 Lead events (delegates to CF7 source for full mapping)
 *
 * Every event fires to Meta CAPI + TikTok CAPI (server-side) when those
 * platforms are enabled and consent is granted.  The browser-side half is
 * handled by servertrack-pixel.js via the REST bridge.
 */
class ServerTrack_CustomEvents {

    public static function init() {
        // PHP action hook — themes/plugins call this directly
        add_action( 'servertrack_custom_event', [ self::class, 'handle_custom_event' ], 10, 2 );

        // WP search → Search CAPI event
        add_action( 'pre_get_posts', [ self::class, 'on_search_query' ] );

        // WooCommerce category / shop archive → ViewCategory CAPI event
        add_action( 'woocommerce_before_shop_loop', [ self::class, 'on_view_category' ] );

        // Standalone WP user registration (outside WooCommerce checkout)
        add_action( 'user_register', [ self::class, 'on_user_register' ], 10, 1 );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PHP ACTION HOOK  —  do_action( 'servertrack_custom_event', $name, $params )
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Fire a custom CAPI event from PHP.
     *
     * @param string $event_name  Any string. Standard Meta event names are sent
     *                            via fbq('track'). Unknown names go via
     *                            fbq('trackCustom') — handled transparently by
     *                            the platform classes.
     * @param array  $params      Associative array of custom_data parameters.
     *                            Common keys: value, currency, content_name,
     *                            content_category, content_ids, contents.
     */
    public static function handle_custom_event( string $event_name, array $params = [] ) {
        if ( ! get_option( 'servertrack_enabled', 1 ) ) return;
        if ( empty( $event_name ) ) return;

        $meta_on   = get_option( 'servertrack_meta_enabled', 0 );
        $tiktok_on = get_option( 'servertrack_tiktok_enabled', 0 );
        if ( ! $meta_on && ! $tiktok_on ) return;

        $event_id = ServerTrack_Dedup::generate_event_id( $event_name . '_php_' . time() . '_' . wp_rand() );

        $event = new ServerTrack_Event( $event_name, $event_id );
        $event->set_user_data( self::build_request_user_data() );
        $event->set_custom_data( $params );

        if ( $meta_on && ServerTrack_Consent::is_granted( 'meta' ) ) {
            $result = ServerTrack_Meta::send( $event );
            ServerTrack_Logger::log(
                $result['status'] ?? 'error', 'meta',
                'CustomEvent: ' . $event_name,
                '', $event_id, 0, $event_name
            );
        }
        if ( $tiktok_on && ServerTrack_Consent::is_granted( 'tiktok' ) ) {
            $result = ServerTrack_TikTok::send( $event );
            ServerTrack_Logger::log(
                $result['status'] ?? 'error', 'tiktok',
                'CustomEvent: ' . $event_name,
                '', $event_id, 0, $event_name
            );
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // SEARCH
    // ─────────────────────────────────────────────────────────────────────────

    public static function on_search_query( WP_Query $query ) {
        if ( ! $query->is_main_query() || ! $query->is_search() || is_admin() ) return;
        if ( ! get_option( 'servertrack_enabled', 1 ) ) return;

        $meta_on   = get_option( 'servertrack_meta_enabled', 0 );
        $tiktok_on = get_option( 'servertrack_tiktok_enabled', 0 );
        if ( ! $meta_on && ! $tiktok_on ) return;

        $search_query = sanitize_text_field( (string) ( $query->get( 's' ) ?: '' ) );
        if ( empty( $search_query ) ) return;

        // Dedup: one Search event per query string per session (5 min TTL)
        $session_id = '';
        if ( function_exists( 'WC' ) && WC()->session ) {
            $session_id = (string) WC()->session->get_customer_id();
        }
        $dedup_key = 'servertrack_search_' . md5( $search_query . '_' . $session_id );
        if ( get_transient( $dedup_key ) ) return;
        set_transient( $dedup_key, 1, 5 * MINUTE_IN_SECONDS );

        $event_id = ServerTrack_Dedup::generate_event_id( 'search_' . md5( $search_query ) . '_' . time() );
        $event    = new ServerTrack_Event( 'Search', $event_id );
        $event->set_user_data( self::build_request_user_data() );
        $event->set_custom_data( [
            'search_string' => $search_query,
            'content_type'  => 'product',
        ] );

        if ( $meta_on && ServerTrack_Consent::is_granted( 'meta' ) ) {
            ServerTrack_Meta::send( $event );
        }
        if ( $tiktok_on && ServerTrack_Consent::is_granted( 'tiktok' ) ) {
            ServerTrack_TikTok::send( $event );
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // VIEW CATEGORY  (WooCommerce shop / product archive)
    // ─────────────────────────────────────────────────────────────────────────

    public static function on_view_category() {
        if ( ! get_option( 'servertrack_enabled', 1 ) ) return;

        $meta_on   = get_option( 'servertrack_meta_enabled', 0 );
        $tiktok_on = get_option( 'servertrack_tiktok_enabled', 0 );
        if ( ! $meta_on && ! $tiktok_on ) return;

        // Only fires on archive/category pages
        if ( ! is_product_category() && ! is_shop() && ! is_product_tag() ) return;

        $category_name = '';
        if ( is_product_category() || is_product_tag() ) {
            $term = get_queried_object();
            $category_name = $term ? (string) $term->name : '';
        } elseif ( is_shop() ) {
            $category_name = __( 'Shop', 'servertrack' );
        }

        // Dedup: once per category per session (10 min TTL)
        $session_id = function_exists( 'WC' ) && WC()->session
            ? (string) WC()->session->get_customer_id() : '';
        $dedup_key = 'servertrack_vc_' . md5( $category_name . '_' . $session_id );
        if ( get_transient( $dedup_key ) ) return;
        set_transient( $dedup_key, 1, 10 * MINUTE_IN_SECONDS );

        $event_id = ServerTrack_Dedup::generate_event_id( 'viewcat_' . md5( $category_name ) . '_' . time() );
        $event    = new ServerTrack_Event( 'ViewCategory', $event_id );
        $event->set_user_data( self::build_request_user_data() );
        $event->set_custom_data( [
            'content_category' => $category_name,
            'content_type'     => 'product',
        ] );

        if ( $meta_on && ServerTrack_Consent::is_granted( 'meta' ) ) {
            ServerTrack_Meta::send( $event );
        }
        if ( $tiktok_on && ServerTrack_Consent::is_granted( 'tiktok' ) ) {
            ServerTrack_TikTok::send( $event );
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // USER REGISTER  (non-WooCommerce registration)
    // WooCommerce registration is handled by ServerTrack_WooCommerce::on_new_customer
    // ─────────────────────────────────────────────────────────────────────────

    public static function on_user_register( int $user_id ) {
        if ( ! get_option( 'servertrack_enabled', 1 ) ) return;

        $meta_on   = get_option( 'servertrack_meta_enabled', 0 );
        $tiktok_on = get_option( 'servertrack_tiktok_enabled', 0 );
        if ( ! $meta_on && ! $tiktok_on ) return;

        // Skip — WooCommerce will handle it via woocommerce_created_customer
        if ( doing_action( 'woocommerce_created_customer' ) ) return;
        // Also skip if WooCommerce checkout is in progress
        if ( function_exists( 'is_checkout' ) && is_checkout() ) return;

        $user = get_userdata( $user_id );
        if ( ! $user ) return;

        $event_id = ServerTrack_Dedup::generate_event_id( 'reg_wp_' . $user_id );
        $event    = new ServerTrack_Event( 'CompleteRegistration', $event_id );

        $user_data = self::build_request_user_data();
        if ( $user->user_email ) {
            $user_data['email'] = ServerTrack_Hasher::hash_email( $user->user_email );
        }
        if ( $user->first_name ) {
            $user_data['first_name'] = ServerTrack_Hasher::hash_name( $user->first_name );
        }
        if ( $user->last_name ) {
            $user_data['last_name'] = ServerTrack_Hasher::hash_name( $user->last_name );
        }

        $event->set_user_data( $user_data );
        $event->set_custom_data( [
            'content_name' => 'New Registration',
            'status'       => 'registered',
        ] );

        if ( $meta_on && ServerTrack_Consent::is_granted( 'meta' ) ) {
            ServerTrack_Meta::send( $event );
        }
        if ( $tiktok_on && ServerTrack_Consent::is_granted( 'tiktok' ) ) {
            ServerTrack_TikTok::send( $event );
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // HELPERS
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Build user_data from the current HTTP request context.
     * Safe to call in both browser requests and REST API requests.
     */
    private static function build_request_user_data(): array {
        $data = [];

        // IP — prefer X-Forwarded-For for load-balanced environments
        $ip = '';
        if ( class_exists( 'WC_Geolocation' ) ) {
            $ip = WC_Geolocation::get_ip_address();
        } elseif ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
            $ip = sanitize_text_field( wp_unslash( explode( ',', $_SERVER['HTTP_X_FORWARDED_FOR'] )[0] ) );
        } elseif ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
            $ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
        }
        // Strip IPv4-mapped IPv6 prefix
        if ( substr( $ip, 0, 7 ) === '::ffff:' ) {
            $ip = substr( $ip, 7 );
        }
        if ( $ip ) $data['ip'] = $ip;

        // User-Agent
        $ua = isset( $_SERVER['HTTP_USER_AGENT'] )
            ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
        if ( $ua ) $data['user_agent'] = $ua;

        // Click IDs from cookies
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        if ( ! empty( $_COOKIE['_fbc'] ) )    $data['fbc']    = sanitize_text_field( wp_unslash( $_COOKIE['_fbc'] ) );
        if ( ! empty( $_COOKIE['_fbp'] ) )    $data['fbp']    = sanitize_text_field( wp_unslash( $_COOKIE['_fbp'] ) );
        if ( ! empty( $_COOKIE['ttclid'] ) )  $data['ttclid'] = sanitize_text_field( wp_unslash( $_COOKIE['ttclid'] ) );
        if ( ! empty( $_COOKIE['_gcl_aw'] ) ) $data['gclid']  = sanitize_text_field( wp_unslash( $_COOKIE['_gcl_aw'] ) );
        // phpcs:enable

        // Logged-in user PII
        if ( is_user_logged_in() ) {
            $user = wp_get_current_user();
            if ( $user->user_email ) {
                $data['email']      = ServerTrack_Hasher::hash_email( $user->user_email );
                $data['external_id']= ServerTrack_Hasher::hash( (string) $user->ID );
            }
        }

        return $data;
    }
}
