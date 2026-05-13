<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ServerTrack_CartAbandonment  v1.1
 *
 * Feature #5 — Server-Side Cart Abandonment Detection & CAPI Event.
 *
 * Changes in v1.1:
 *   BUG-M3: Added Google Ads block in check_abandonment() — previously only
 *           Meta and TikTok received InitiateCheckout abandonment events.
 *           Google block now fires when servertrack_google_enabled is set and
 *           consent is granted, matching the Meta/TikTok pattern exactly.
 *           on_add_to_cart() early-exit guard extended to include Google.
 *   BUG-M7: resolve_email_for_session() session customer array path now wraps
 *           sanitize_email() result in is_email() check before returning,
 *           preventing an empty string from being treated as a valid address.
 *
 * THE PROBLEM:
 *   Cart abandonment pixels (fbq('track','AddToCart')) fire client-side
 *   and are blocked by ~40% of browsers. Stape.io can proxy pixel events
 *   but CANNOT detect abandonment server-side because it has no access to
 *   WooCommerce session data.
 *
 * HOW IT WORKS:
 *   1. When a customer adds to cart (logged-in OR guest with WC session),
 *      a 60-minute WP-Cron job is scheduled keyed by session hash.
 *   2. When the customer completes checkout, a 'completed' flag is stored
 *      in a transient keyed by session hash.
 *   3. When the cron fires after 60 minutes:
 *      - If the 'completed' flag exists → do nothing (purchased, not abandoned).
 *      - If the cart is still non-empty → fire a 'InitiateCheckout' CAPI
 *        event to Meta, TikTok, and Google as the abandonment signal. Meta
 *        recommends InitiateCheckout (not AddToCart) for retargeting.
 *
 * PRIVACY:
 *   - Only fires for sessions where we already have an email (logged-in user
 *     or guest who started checkout). Anonymous sessions with no PII are
 *     skipped — there is nobody to match.
 *   - Full consent check before sending.
 *
 * TIMING:
 *   Default abandonment window: 60 minutes (filterable).
 *   Filter: servertrack_abandonment_window_seconds (default: 3600)
 */
class ServerTrack_CartAbandonment {

    const TRANSIENT_PREFIX_PENDING   = 'servertrack_cart_pending_';
    const TRANSIENT_PREFIX_COMPLETED = 'servertrack_cart_done_';
    const DEFAULT_WINDOW             = 3600; // 60 minutes

    public static function init(): void {
        if ( ! get_option( 'servertrack_enabled', 1 ) ) return;

        add_action( 'woocommerce_add_to_cart',          [ self::class, 'on_add_to_cart' ],       10, 6 );
        add_action( 'woocommerce_thankyou',             [ self::class, 'on_purchase_complete' ], 10, 1 );
        add_action( 'servertrack_check_abandonment',    [ self::class, 'check_abandonment' ],    10, 1 );
    }

    // ────────────────────────────────────────────────────────────────────────
    // HOOKS
    // ────────────────────────────────────────────────────────────────────────

    public static function on_add_to_cart(
        string $cart_item_key,
        int    $product_id,
        int    $quantity,
        int    $variation_id,
        array  $variation      = [],
        array  $cart_item_data = []
    ): void {
        // BUG-M3 fix: include google in the early-exit guard
        if ( ! get_option( 'servertrack_meta_enabled', 0 )
            && ! get_option( 'servertrack_tiktok_enabled', 0 )
            && ! get_option( 'servertrack_google_enabled', 0 ) ) return;

        $session_id = self::get_session_id();
        if ( ! $session_id ) return;

        $window    = (int) apply_filters( 'servertrack_abandonment_window_seconds', self::DEFAULT_WINDOW );
        $cache_key = self::TRANSIENT_PREFIX_PENDING . md5( $session_id );

        // Store cart snapshot (we'll re-read from WC session at cron time)
        set_transient( $cache_key, [
            'session_id'  => $session_id,
            'product_id'  => $product_id,
            'added_at'    => time(),
        ], $window + 300 );

        // Schedule the abandonment check
        // wp_schedule_single_event won't double-schedule if same hook+args exists
        if ( ! wp_next_scheduled( 'servertrack_check_abandonment', [ $session_id ] ) ) {
            wp_schedule_single_event( time() + $window, 'servertrack_check_abandonment', [ $session_id ] );
        }
    }

    public static function on_purchase_complete( int $order_id ): void {
        $session_id = self::get_session_id();
        if ( ! $session_id ) return;
        $done_key = self::TRANSIENT_PREFIX_COMPLETED . md5( $session_id );
        set_transient( $done_key, $order_id, 2 * HOUR_IN_SECONDS );
    }

    // ────────────────────────────────────────────────────────────────────────
    // CRON HANDLER
    // ────────────────────────────────────────────────────────────────────────

    public static function check_abandonment( string $session_id ): void {
        if ( ! get_option( 'servertrack_enabled', 1 ) ) return;

        // If the customer completed purchase — do nothing
        $done_key = self::TRANSIENT_PREFIX_COMPLETED . md5( $session_id );
        if ( get_transient( $done_key ) ) {
            ServerTrack_Logger::log( 'skipped', 'all', 'Cart abandonment: session completed purchase.', '', '', 0, 'CartAbandonment' );
            return;
        }

        // Retrieve cart snapshot
        $cache_key = self::TRANSIENT_PREFIX_PENDING . md5( $session_id );
        $snapshot  = get_transient( $cache_key );
        if ( ! $snapshot ) {
            ServerTrack_Logger::log( 'skipped', 'all', 'Cart abandonment: no snapshot found for session.', '', '', 0, 'CartAbandonment' );
            return;
        }

        // Try to get the customer's email from WC session or user meta
        $email = self::resolve_email_for_session( $session_id );
        if ( ! $email ) {
            ServerTrack_Logger::log( 'skipped', 'all', 'Cart abandonment: no email — anonymous session, skipping.', '', '', 0, 'CartAbandonment' );
            return;
        }

        // Build minimal user_data
        $user_data = [
            'email' => ServerTrack_Hasher::hash_email( $email ),
        ];
        $user = get_user_by( 'email', $email );
        if ( $user instanceof WP_User && $user->ID > 0 ) {
            $user_data['external_id'] = ServerTrack_Identity::get_external_id_for_user( $user->ID );
        }

        $event_id    = ServerTrack_Dedup::generate_event_id( 'abandon_' . $session_id );
        $custom_data = [
            'currency'     => get_woocommerce_currency(),
            'content_type' => 'product',
            'content_name' => 'Abandoned Cart',
        ];

        $meta_on   = get_option( 'servertrack_meta_enabled', 0 );
        $tiktok_on = get_option( 'servertrack_tiktok_enabled', 0 );
        // BUG-M3 fix: read Google toggle
        $google_on = get_option( 'servertrack_google_enabled', 0 );

        if ( $meta_on && ServerTrack_Consent::is_granted( 'meta' ) ) {
            $e      = ( new ServerTrack_Event( 'InitiateCheckout', $event_id ) )->set_user_data( $user_data )->set_custom_data( $custom_data );
            $result = ServerTrack_Meta::send( $e );
            ServerTrack_Logger::log( $result['status'] ?? 'error', 'meta', 'Cart abandonment email=' . $email, '', $event_id, 0, 'CartAbandonment' );
        }

        if ( $tiktok_on && ServerTrack_Consent::is_granted( 'tiktok' ) ) {
            $e      = ( new ServerTrack_Event( 'InitiateCheckout', $event_id ) )->set_user_data( $user_data )->set_custom_data( $custom_data );
            $result = ServerTrack_TikTok::send( $e );
            ServerTrack_Logger::log( $result['status'] ?? 'error', 'tiktok', 'Cart abandonment email=' . $email, '', $event_id, 0, 'CartAbandonment' );
        }

        // BUG-M3 fix: Google Ads abandonment block — was entirely missing
        if ( $google_on && ServerTrack_Consent::is_granted( 'google' ) ) {
            $e      = ( new ServerTrack_Event( 'InitiateCheckout', $event_id ) )->set_user_data( $user_data )->set_custom_data( $custom_data );
            $result = ServerTrack_Google::send( $e );
            ServerTrack_Logger::log( $result['status'] ?? 'error', 'google', 'Cart abandonment email=' . $email, '', $event_id, 0, 'CartAbandonment' );
        }

        // Clean up snapshot
        delete_transient( $cache_key );
    }

    // ────────────────────────────────────────────────────────────────────────
    // HELPERS
    // ────────────────────────────────────────────────────────────────────────

    private static function get_session_id(): string {
        if ( WC()->session && method_exists( WC()->session, 'get_customer_id' ) ) {
            return (string) WC()->session->get_customer_id();
        }
        return '';
    }

    private static function resolve_email_for_session( string $session_id ): string {
        // Logged-in user
        if ( is_user_logged_in() ) {
            $user = wp_get_current_user();
            return $user->user_email ?? '';
        }
        // Check if WC stored email in the session
        if ( WC()->session ) {
            $email = (string) ( WC()->session->get( 'billing_email' ) ?? '' );
            if ( $email && is_email( $email ) ) return $email;
            // Also check customer data object stored in session
            $customer = WC()->session->get( 'customer' );
            if ( is_array( $customer ) && ! empty( $customer['email'] ) ) {
                // BUG-M7 fix: validate with is_email() before returning —
                // sanitize_email() returns '' for invalid input but the
                // original code returned that empty string without checking.
                $candidate = sanitize_email( $customer['email'] );
                if ( is_email( $candidate ) ) {
                    return $candidate;
                }
            }
        }
        return '';
    }
}
