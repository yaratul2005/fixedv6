<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ServerTrack_WooWishlist  v1.0
 *
 * Sends server-side AddToWishlist CAPI events when a customer adds a product
 * to a WooCommerce wishlist.
 *
 * SUPPORTED WISHLIST PLUGINS:
 *   1. YITH WooCommerce Wishlist (free & premium)
 *      Hook: yith_wcwl_added_to_wishlist( $product_id, $wishlist_id )
 *   2. TI WooCommerce Wishlist
 *      Hook: ti_woocommerce_wishlist_add_to_wishlist( $product_id, $user_id )
 *
 * PLATFORM MAPPING:
 *   Meta   → AddToWishlist (standard event, tracked in Ads Manager)
 *   TikTok → AddToWishlist (standard event)
 *   Google → no equivalent standard event; logged as skipped to avoid noise
 *
 * DEDUP STRATEGY:
 *   Transient-based, not options-based, because wishlist adds are high-frequency
 *   page events. Key: servertrack_wl_{user_hash}_{product_id}, TTL 60 minutes.
 *   This prevents double-fires within a session (e.g. adding the same product
 *   twice in quick succession) without polluting the wp_options table.
 *
 * OPT-IN:
 *   Only active when servertrack_source_wishlist_enabled = 1 (default: 0).
 *   Admin can toggle in Settings → Sources.
 *
 * @package ServerTrack
 * @since   5.0.0
 */
class ServerTrack_WooWishlist {

    /** Transient TTL: 60 minutes per session. */
    const DEDUP_TTL = HOUR_IN_SECONDS;

    public static function init(): void {
        if ( ! get_option( 'servertrack_source_wishlist_enabled', 0 ) ) {
            return;
        }
        if ( ! get_option( 'servertrack_enabled', 1 ) ) {
            return;
        }

        // YITH WooCommerce Wishlist
        add_action( 'yith_wcwl_added_to_wishlist',                  [ self::class, 'on_yith_wishlist_add' ],  10, 2 );
        // TI WooCommerce Wishlist
        add_action( 'ti_woocommerce_wishlist_add_to_wishlist',       [ self::class, 'on_ti_wishlist_add' ],    10, 2 );

        // Async handler
        add_action( 'servertrack_send_wishlist_add',                 [ self::class, 'send_wishlist_async' ],   10, 1 );
    }

    // ── Hook handlers ─────────────────────────────────────────────────────────

    /**
     * YITH WooCommerce Wishlist: yith_wcwl_added_to_wishlist
     *
     * @param int $product_id
     * @param int $wishlist_id
     */
    public static function on_yith_wishlist_add( int $product_id, int $wishlist_id ): void {
        self::maybe_queue( $product_id );
    }

    /**
     * TI WooCommerce Wishlist: ti_woocommerce_wishlist_add_to_wishlist
     *
     * @param int $product_id
     * @param int $user_id
     */
    public static function on_ti_wishlist_add( int $product_id, int $user_id ): void {
        self::maybe_queue( $product_id );
    }

    // ── Internal queue dispatch ───────────────────────────────────────────────

    /**
     * Build dedup key and queue the async event if not already sent.
     *
     * @param int $product_id
     */
    private static function maybe_queue( int $product_id ): void {
        $meta_on   = (bool) get_option( 'servertrack_meta_enabled', 0 );
        $tiktok_on = (bool) get_option( 'servertrack_tiktok_enabled', 0 );

        if ( ! $meta_on && ! $tiktok_on ) {
            return;
        }

        // Build a session-scoped dedup key
        $user_hash = is_user_logged_in()
            ? md5( 'user_' . get_current_user_id() )
            : md5( 'guest_' . ( isset( $_COOKIE['woocommerce_cart_hash'] ) ? sanitize_text_field( wp_unslash( $_COOKIE['woocommerce_cart_hash'] ) ) : session_id() ) ); // phpcs:ignore

        $transient_key = 'servertrack_wl_' . $user_hash . '_' . $product_id;

        if ( get_transient( $transient_key ) ) {
            return; // Already fired for this user+product this session
        }

        set_transient( $transient_key, 1, self::DEDUP_TTL );

        // Capture browser context while we're still in HTTP request
        $context = [
            'ip'         => self::get_real_ip(),
            'user_agent' => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '', // phpcs:ignore
        ];
        if ( ! empty( $_COOKIE['_fbp'] ) )    $context['fbp']    = sanitize_text_field( wp_unslash( $_COOKIE['_fbp'] ) );    // phpcs:ignore
        if ( ! empty( $_COOKIE['_fbc'] ) )    $context['fbc']    = sanitize_text_field( wp_unslash( $_COOKIE['_fbc'] ) );    // phpcs:ignore
        if ( ! empty( $_COOKIE['ttclid'] ) )  $context['ttclid'] = sanitize_text_field( wp_unslash( $_COOKIE['ttclid'] ) );  // phpcs:ignore
        if ( ! empty( $_COOKIE['_gcl_aw'] ) ) $context['gclid']  = sanitize_text_field( wp_unslash( $_COOKIE['_gcl_aw'] ) ); // phpcs:ignore

        if ( is_user_logged_in() ) {
            $user = wp_get_current_user();
            if ( $user->user_email ) {
                $context['email']       = ServerTrack_Hasher::hash_email( $user->user_email );
                $context['external_id'] = ServerTrack_Identity::get_external_id_for_user( $user->ID );
            }
        }

        wp_schedule_single_event(
            time(),
            'servertrack_send_wishlist_add',
            [ [ 'product_id' => $product_id, 'context' => $context ] ]
        );
        spawn_cron();
    }

    // ── Async cron handler ────────────────────────────────────────────────────

    /**
     * Send AddToWishlist event to Meta and TikTok.
     *
     * @param array $args  [ 'product_id' => int, 'context' => array ]
     */
    public static function send_wishlist_async( array $args ): void {
        if ( ! get_option( 'servertrack_enabled', 1 ) ) {
            return;
        }

        $product_id = absint( $args['product_id'] ?? 0 );
        $context    = $args['context'] ?? [];

        if ( ! $product_id ) {
            return;
        }

        $product = wc_get_product( $product_id );
        if ( ! $product || 'publish' !== $product->get_status() ) {
            return;
        }

        $price    = (float) wc_get_price_to_display( $product );
        $sku      = $product->get_sku() ?: (string) $product->get_id();
        $event_id = ServerTrack_Dedup::generate_event_id( 'wishlist_' . $product_id );

        $custom_data = apply_filters( 'servertrack_wishlist_custom_data', [
            'currency'     => get_woocommerce_currency(),
            'value'        => $price,
            'content_ids'  => [ $sku ],
            'contents'     => [ [ 'id' => $sku, 'quantity' => 1, 'item_price' => $price ] ],
            'content_type' => 'product',
            'content_name' => $product->get_name(),
        ], $product_id );

        $event = ( new ServerTrack_Event( 'AddToWishlist', $event_id ) )
            ->set_user_data( $context )
            ->set_custom_data( $custom_data );

        // META
        if ( get_option( 'servertrack_meta_enabled', 0 )
            && ServerTrack_Consent::is_granted( 'meta' ) ) {
            $r = ServerTrack_Meta::send( $event );
            if ( ( $r['status'] ?? '' ) !== 'success' ) {
                ServerTrack_Retry::maybe_queue( 'meta', $r, ServerTrack_Retry::event_to_args( $event ) );
            }
            ServerTrack_Logger::log( $r['status'] ?? 'error', 'meta', 'AddToWishlist #' . $product_id, '', $event_id, 0, 'AddToWishlist' );
        }

        // TIKTOK
        if ( get_option( 'servertrack_tiktok_enabled', 0 )
            && ServerTrack_Consent::is_granted( 'tiktok' ) ) {
            $r = ServerTrack_TikTok::send( $event );
            if ( ( $r['status'] ?? '' ) !== 'success' ) {
                ServerTrack_Retry::maybe_queue( 'tiktok', $r, ServerTrack_Retry::event_to_args( $event ) );
            }
            ServerTrack_Logger::log( $r['status'] ?? 'error', 'tiktok', 'AddToWishlist #' . $product_id, '', $event_id, 0, 'AddToWishlist' );
        }

        // Google: no standard AddToWishlist event — log as skipped
        if ( get_option( 'servertrack_google_enabled', 0 ) ) {
            ServerTrack_Logger::log( 'skipped', 'google', 'AddToWishlist: no Google standard event — skipped.', '', $event_id, 0, 'AddToWishlist' );
        }
    }

    // ── Helper ────────────────────────────────────────────────────────────────

    private static function get_real_ip(): string {
        $ip = class_exists( 'WC_Geolocation' )
            ? WC_Geolocation::get_ip_address()
            : sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) ); // phpcs:ignore
        if ( substr( $ip, 0, 7 ) === '::ffff:' ) $ip = substr( $ip, 7 );
        return sanitize_text_field( $ip );
    }
}
