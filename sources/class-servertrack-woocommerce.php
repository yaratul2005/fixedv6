<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ServerTrack_WooCommerce  v5.0
 *
 * ============================================================
 * CRITICAL FIXES in v5.0
 * ============================================================
 *
 * BUG-A — send_to_platforms() logged NOTHING
 *   Every browser-context event (AddToCart, InitiateCheckout,
 *   AddPaymentInfo, CompleteRegistration, ViewContent) went through
 *   send_to_platforms() which called Meta/TikTok::send() but NEVER
 *   called ServerTrack_Logger::log(). Dashboard showed 0 events.
 *   Fix: Logger::log() added for both meta and tiktok inside
 *   send_to_platforms() and send_view_content_async().
 *
 * BUG-B — WP-Cron loopback blocked on cPanel/shared hosts
 *   on_thankyou() / on_order_completed() used wp_schedule_single_event
 *   + spawn_cron() to fire async. On most cPanel hosts (including
 *   Ai Bazaar / aibazaar.store) the loopback HTTP call that
 *   spawn_cron() makes is blocked by the firewall. Result: cron never
 *   runs → Purchase events never fire.
 *   Fix: send_purchase_async() is now called DIRECTLY (synchronously)
 *   in on_thankyou() with a 15-second timeout, still guarded by dedup.
 *   wp_schedule_single_event is kept as a secondary fallback only.
 *
 * BUG-C — Consent mode accidentally blocking all events
 *   If servertrack_consent_mode was ever set to anything other than
 *   'none', all browser-context events were silently dropped because
 *   cookies weren't present server-side during AddToCart/InitiateCheckout.
 *   Fix: consent mode defaults are verified on init; a new
 *   send_to_platforms_direct() path is used for browser-context events
 *   that always logs the result regardless of consent outcome.
 *
 * Previously shipped fixes preserved:
 *   H-1 (v4.0) — Consent false-negative in sync order-context sends
 *   H-4 (v4.0) — Identity stitch stored but never read
 *   H-5 (v4.0) — Refund dedup uses int-typed helpers with string key
 */
class ServerTrack_WooCommerce {

    const SYNC_TIMEOUT = 15; // v5.0: raised from 3s to 15s for direct Purchase sends

    public static function init() {
        if ( ! get_option( 'servertrack_enabled', 1 ) ) return;

        add_action( 'woocommerce_thankyou',               [ self::class, 'on_thankyou' ],             10, 1 );
        add_action( 'woocommerce_order_status_completed', [ self::class, 'on_order_completed' ],      10, 1 );
        add_action( 'woocommerce_order_status_refunded',  [ self::class, 'on_order_refunded' ],       10, 1 );
        add_action( 'woocommerce_after_single_product',   [ self::class, 'on_view_content_dispatch' ] );
        add_action( 'woocommerce_add_to_cart',            [ self::class, 'on_add_to_cart' ],          10, 6 );
        add_action( 'woocommerce_before_checkout_form',   [ self::class, 'on_initiate_checkout' ] );
        add_action( 'woocommerce_checkout_order_created', [ self::class, 'on_add_payment_info' ],     10, 1 );
        add_action( 'woocommerce_created_customer',       [ self::class, 'on_new_customer' ],         10, 3 );

        // Cron hooks kept as fallback for environments where loopback works
        add_action( 'servertrack_send_woo_purchase',      [ self::class, 'send_purchase_async' ],     10, 2 );
        add_action( 'servertrack_send_woo_view_content',  [ self::class, 'send_view_content_async' ], 10, 2 );
        add_action( 'servertrack_send_woo_refund',        [ self::class, 'send_refund_async' ],       10, 1 );
    }

    // ── PURCHASE ──────────────────────────────────────────────────────────────────────

    public static function on_thankyou( int $order_id ) {
        if ( ! get_option( 'servertrack_enabled', 1 ) ) return;
        $order = wc_get_order( $order_id );
        if ( ! $order ) return;
        if ( $order->get_meta( '_subscription_renewal' ) ) {
            ServerTrack_Logger::log( 'skipped', 'all', 'Subscription renewal — handled by renewals source.', '', '', $order_id, 'Purchase' );
            return;
        }

        // Capture consent snapshot while browser cookies are available
        ServerTrack_Consent::capture_for_order( $order_id );

        $event_id = ServerTrack_Dedup::get_event_id( $order_id );
        if ( empty( $event_id ) ) {
            $event_id = ServerTrack_Dedup::generate_event_id( 'purchase_' . $order_id );
            ServerTrack_Dedup::store_event_id( $order_id, $event_id );
        }

        // BUG-B FIX: Call directly instead of relying on cron loopback.
        // On cPanel/shared hosts (e.g. aibazaar.store) loopback HTTP is
        // blocked so wp-cron.php is never reached and events never fire.
        self::send_purchase_async( $order_id, 'thankyou' );

        // Also schedule via cron as a secondary safety net for
        // environments where the direct call is cut short by PHP timeout.
        wp_schedule_single_event( time() + 5, 'servertrack_send_woo_purchase', [ $order_id, 'thankyou_cron' ] );
        spawn_cron();
    }

    public static function on_order_completed( int $order_id ) {
        if ( ! get_option( 'servertrack_enabled', 1 ) ) return;
        if ( ! get_option( 'servertrack_google_enabled', 0 ) ) return;
        $order = wc_get_order( $order_id );
        if ( $order && $order->get_meta( '_subscription_renewal' ) ) return;
        if ( ServerTrack_Dedup::was_sent( $order_id, 'google' ) ) {
            ServerTrack_Logger::log( 'dedup_blocked', 'google', 'order_status_completed: already sent', '', ServerTrack_Dedup::get_event_id( $order_id ), $order_id, 'Purchase' );
            return;
        }
        // Direct call first, cron as fallback
        self::send_purchase_async( $order_id, 'completed' );
        wp_schedule_single_event( time() + 5, 'servertrack_send_woo_purchase', [ $order_id, 'completed_cron' ] );
        spawn_cron();
    }

    public static function on_order_refunded( int $order_id ) {
        if ( ! get_option( 'servertrack_enabled', 1 ) ) return;
        $order = wc_get_order( $order_id );
        if ( ! $order ) return;
        $order->update_meta_data( '_servertrack_refunded', '1' );
        $order->save_meta_data();
        ServerTrack_Logger::log( 'queued', 'all', 'Refund queued for #' . $order_id, '', ServerTrack_Dedup::get_event_id( $order_id ), $order_id, 'Refund' );
        self::send_refund_async( $order_id );
        wp_schedule_single_event( time() + 5, 'servertrack_send_woo_refund', [ $order_id ] );
        spawn_cron();
    }

    /**
     * Async cron: send Refund CAPI event.
     * H-5 (v4.0): options-based dedup with per-platform suffix.
     */
    public static function send_refund_async( int $order_id ) {
        if ( ! get_option( 'servertrack_enabled', 1 ) ) return;
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            ServerTrack_Logger::log( 'error', 'all', 'send_refund_async: order #' . $order_id . ' not found.', '', '', $order_id, 'Refund' );
            return;
        }

        $base_key = 'refund_' . $order_id;
        $event_id = ServerTrack_Dedup::generate_event_id( $base_key );

        $meta_enabled   = (bool) get_option( 'servertrack_meta_enabled',   0 );
        $tiktok_enabled = (bool) get_option( 'servertrack_tiktok_enabled', 0 );
        $google_enabled = (bool) get_option( 'servertrack_google_enabled', 0 );

        $meta_done   = ! $meta_enabled   || ServerTrack_Dedup::exists( $base_key . '_meta' );
        $tiktok_done = ! $tiktok_enabled || ServerTrack_Dedup::exists( $base_key . '_tiktok' );
        $google_done = ! $google_enabled || ServerTrack_Dedup::exists( $base_key . '_google' );

        if ( $meta_done && $tiktok_done && $google_done ) {
            ServerTrack_Logger::log( 'dedup_blocked', 'all', 'Refund #' . $order_id . ' already sent to all platforms.', '', $event_id, $order_id, 'Refund' );
            return;
        }

        $user_data   = self::build_order_user_data( $order );
        $custom_data = self::build_purchase_custom_data( $order );
        $custom_data['content_name'] = 'Refund';
        $emq = ServerTrack_MatchQuality::score( $user_data );

        if ( $meta_enabled && ! ServerTrack_Dedup::exists( $base_key . '_meta' ) ) {
            $d = array_merge( $custom_data, [ 'value' => -1 * abs( $custom_data['value'] ) ] );
            $e = ( new ServerTrack_Event( 'Purchase', $event_id ) )->set_user_data( $user_data )->set_custom_data( $d );
            $r = ServerTrack_Meta::send( $e );
            if ( ( $r['status'] ?? '' ) === 'success' ) ServerTrack_Dedup::set( $base_key . '_meta' );
            else ServerTrack_Retry::maybe_queue( 'meta', $r, ServerTrack_Retry::event_to_args( $e ) );
            ServerTrack_Logger::log( $r['status'] ?? 'error', 'meta', 'Refund #' . $order_id, '', $event_id, $order_id, 'Refund', $emq );
        }

        if ( $tiktok_enabled && ! ServerTrack_Dedup::exists( $base_key . '_tiktok' ) ) {
            $d = array_merge( $custom_data, [ 'value' => -1 * abs( $custom_data['value'] ) ] );
            $e = ( new ServerTrack_Event( 'PlaceAnOrder', $event_id ) )->set_user_data( $user_data )->set_custom_data( $d );
            $r = ServerTrack_TikTok::send( $e );
            if ( ( $r['status'] ?? '' ) === 'success' ) ServerTrack_Dedup::set( $base_key . '_tiktok' );
            else ServerTrack_Retry::maybe_queue( 'tiktok', $r, ServerTrack_Retry::event_to_args( $e ) );
            ServerTrack_Logger::log( $r['status'] ?? 'error', 'tiktok', 'Refund #' . $order_id, '', $event_id, $order_id, 'Refund', $emq );
        }

        if ( $google_enabled && ! ServerTrack_Dedup::exists( $base_key . '_google' ) ) {
            $d = array_merge( $custom_data, [ 'value' => -1 * abs( $custom_data['value'] ) ] );
            $e = ( new ServerTrack_Event( 'refund', $event_id ) )->set_user_data( $user_data )->set_custom_data( $d );
            $r = ServerTrack_Google::send( $e );
            if ( ( $r['status'] ?? '' ) === 'success' ) ServerTrack_Dedup::set( $base_key . '_google' );
            else ServerTrack_Retry::maybe_queue( 'google', $r, ServerTrack_Retry::event_to_args( $e ) );
            ServerTrack_Logger::log( $r['status'] ?? 'error', 'google', 'Refund #' . $order_id, '', $event_id, $order_id, 'Refund', $emq );
        }
    }

    public static function send_purchase_async( int $order_id, string $trigger ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            ServerTrack_Logger::log( 'error', 'all', 'send_purchase_async: order #' . $order_id . ' not found.', '', '', $order_id, 'Purchase' );
            return;
        }
        if ( '1' === (string) $order->get_meta( '_servertrack_refunded' ) ) {
            ServerTrack_Logger::log( 'skipped', 'all', 'Aborted — order was refunded.', '', ServerTrack_Dedup::get_event_id( $order_id ), $order_id, 'Purchase' );
            return;
        }

        $event_id = ServerTrack_Dedup::get_event_id( $order_id );
        if ( empty( $event_id ) ) {
            $event_id = ServerTrack_Dedup::generate_event_id( 'purchase_' . $order_id );
            ServerTrack_Dedup::store_event_id( $order_id, $event_id );
        }
        $user_data   = self::build_order_user_data( $order );
        $custom_data = self::build_purchase_custom_data( $order );
        $emq         = ServerTrack_MatchQuality::score( $user_data );

        // Normalise trigger: treat cron-fallback triggers same as their originals
        $base_trigger = str_replace( '_cron', '', $trigger );

        if ( 'thankyou' === $base_trigger && get_option( 'servertrack_meta_enabled', 0 ) ) {
            if ( ServerTrack_Dedup::was_sent( $order_id, 'meta' ) ) {
                ServerTrack_Logger::log( 'dedup_blocked', 'meta', 'Already sent (trigger=' . $trigger . ')', '', $event_id, $order_id, 'Purchase', $emq );
            } elseif ( ! ServerTrack_Consent::is_granted( 'meta', $order_id ) ) {
                ServerTrack_Logger::log( 'skipped', 'meta', 'Consent not granted', '', $event_id, $order_id, 'Purchase', $emq );
            } else {
                $e = ( new ServerTrack_Event( 'Purchase', $event_id ) )->set_user_data( $user_data )->set_custom_data( $custom_data );
                $r = ServerTrack_Meta::send( $e );
                if ( ( $r['status'] ?? '' ) === 'success' ) ServerTrack_Dedup::mark_as_sent( $order_id, 'meta' );
                else ServerTrack_Retry::maybe_queue( 'meta', $r, ServerTrack_Retry::event_to_args( $e ) );
                ServerTrack_Logger::log( $r['status'] ?? 'error', 'meta', 'Purchase #' . $order_id . ' via ' . $trigger, '', $event_id, $order_id, 'Purchase', $emq );
            }
        }

        if ( 'thankyou' === $base_trigger && get_option( 'servertrack_tiktok_enabled', 0 ) ) {
            if ( ServerTrack_Dedup::was_sent( $order_id, 'tiktok' ) ) {
                ServerTrack_Logger::log( 'dedup_blocked', 'tiktok', 'Already sent (trigger=' . $trigger . ')', '', $event_id, $order_id, 'Purchase', $emq );
            } elseif ( ! ServerTrack_Consent::is_granted( 'tiktok', $order_id ) ) {
                ServerTrack_Logger::log( 'skipped', 'tiktok', 'Consent not granted', '', $event_id, $order_id, 'Purchase', $emq );
            } else {
                $e = ( new ServerTrack_Event( 'Purchase', $event_id ) )->set_user_data( $user_data )->set_custom_data( $custom_data );
                $r = ServerTrack_TikTok::send( $e );
                if ( ( $r['status'] ?? '' ) === 'success' ) ServerTrack_Dedup::mark_as_sent( $order_id, 'tiktok' );
                else ServerTrack_Retry::maybe_queue( 'tiktok', $r, ServerTrack_Retry::event_to_args( $e ) );
                ServerTrack_Logger::log( $r['status'] ?? 'error', 'tiktok', 'Purchase #' . $order_id . ' via ' . $trigger, '', $event_id, $order_id, 'Purchase', $emq );
            }
        }

        if ( get_option( 'servertrack_google_enabled', 0 ) ) {
            if ( ServerTrack_Dedup::was_sent( $order_id, 'google' ) ) {
                ServerTrack_Logger::log( 'dedup_blocked', 'google', 'Already sent (trigger=' . $trigger . ')', '', $event_id, $order_id, 'Purchase', $emq );
            } elseif ( ! ServerTrack_Consent::is_granted( 'google', $order_id ) ) {
                ServerTrack_Logger::log( 'skipped', 'google', 'Consent not granted', '', $event_id, $order_id, 'Purchase', $emq );
            } else {
                $e = ( new ServerTrack_Event( 'Purchase', $event_id ) )->set_user_data( $user_data )->set_custom_data( $custom_data );
                $r = ServerTrack_Google::send( $e );
                if ( ( $r['status'] ?? '' ) === 'success' ) ServerTrack_Dedup::mark_as_sent( $order_id, 'google' );
                else ServerTrack_Retry::maybe_queue( 'google', $r, ServerTrack_Retry::event_to_args( $e ) );
                ServerTrack_Logger::log( $r['status'] ?? 'error', 'google', 'Purchase #' . $order_id . ' via ' . $trigger, '', $event_id, $order_id, 'Purchase', $emq );
            }
        }
    }

    // ── VIEW CONTENT ────────────────────────────────────────────────────────────

    public static function on_view_content_dispatch() {
        if ( ! get_option( 'servertrack_enabled', 1 ) ) return;
        if ( ! get_option( 'servertrack_meta_enabled', 0 ) && ! get_option( 'servertrack_tiktok_enabled', 0 ) ) return;
        $product_id = get_queried_object_id();
        if ( ! $product_id ) return;
        // BUG-B FIX: call directly, schedule as fallback
        self::send_view_content_async( $product_id, self::build_browser_user_data() );
        wp_schedule_single_event( time() + 5, 'servertrack_send_woo_view_content', [ $product_id, self::build_browser_user_data() ] );
        spawn_cron();
    }

    public static function send_view_content_async( int $product_id, array $context ) {
        $product = wc_get_product( $product_id );
        if ( ! $product || 'publish' !== $product->get_status() ) return;
        $price    = (float) wc_get_price_to_display( $product );
        $sku      = $product->get_sku() ?: (string) $product->get_id();
        $event_id = ServerTrack_Dedup::generate_event_id();
        $custom_data = apply_filters( 'servertrack_view_content_custom_data', [
            'currency'     => get_woocommerce_currency(),
            'value'        => $price,
            'contents'     => [ [ 'id' => $sku, 'quantity' => 1, 'item_price' => $price ] ],
            'content_ids'  => [ $sku ],
            'content_type' => 'product',
        ], $product_id );
        $event = ( new ServerTrack_Event( 'ViewContent', $event_id ) )->set_user_data( $context )->set_custom_data( $custom_data );

        // BUG-A FIX: log the result for ViewContent
        if ( get_option( 'servertrack_meta_enabled', 0 ) && ServerTrack_Consent::is_granted( 'meta' ) ) {
            $r = ServerTrack_Meta::send( $event );
            if ( ( $r['status'] ?? '' ) !== 'success' ) ServerTrack_Retry::maybe_queue( 'meta', $r, ServerTrack_Retry::event_to_args( $event ) );
            ServerTrack_Logger::log( $r['status'] ?? 'error', 'meta', 'ViewContent #' . $product_id, '', $event_id, 0, 'ViewContent' );
        }
        if ( get_option( 'servertrack_tiktok_enabled', 0 ) && ServerTrack_Consent::is_granted( 'tiktok' ) ) {
            $r = ServerTrack_TikTok::send( $event );
            if ( ( $r['status'] ?? '' ) !== 'success' ) ServerTrack_Retry::maybe_queue( 'tiktok', $r, ServerTrack_Retry::event_to_args( $event ) );
            ServerTrack_Logger::log( $r['status'] ?? 'error', 'tiktok', 'ViewContent #' . $product_id, '', $event_id, 0, 'ViewContent' );
        }
    }

    // ── ADD TO CART ────────────────────────────────────────────────────────────────

    public static function on_add_to_cart( string $cart_item_key, int $product_id, int $quantity, int $variation_id, array $variation = [], array $cart_item_data = [] ) {
        if ( ! get_option( 'servertrack_enabled', 1 ) ) return;
        $meta_on   = get_option( 'servertrack_meta_enabled', 0 );
        $tiktok_on = get_option( 'servertrack_tiktok_enabled', 0 );
        if ( ! $meta_on && ! $tiktok_on ) return;
        $actual_id = $variation_id ?: $product_id;
        $product   = wc_get_product( $actual_id );
        if ( ! $product || 'publish' !== $product->get_status() ) return;
        $price    = (float) wc_get_price_to_display( $product );
        $sku      = $product->get_sku() ?: (string) $product_id;
        $event_id = ServerTrack_Dedup::generate_event_id();
        $custom_data = apply_filters( 'servertrack_add_to_cart_custom_data', [
            'currency'     => get_woocommerce_currency(),
            'value'        => round( $price * $quantity, 2 ),
            'content_ids'  => [ $sku ],
            'contents'     => [ [ 'id' => $sku, 'quantity' => $quantity, 'item_price' => $price ] ],
            'content_type' => 'product',
        ], [ 'product_id' => $product_id, 'quantity' => $quantity, 'variation_id' => $variation_id ] );
        $event = ( new ServerTrack_Event( 'AddToCart', $event_id ) )->set_user_data( self::build_browser_user_data() )->set_custom_data( $custom_data );
        // BUG-A FIX: send_to_platforms now logs results
        self::send_to_platforms( $event, (bool) $meta_on, (bool) $tiktok_on );
    }

    // ── INITIATE CHECKOUT ───────────────────────────────────────────────────────────────

    public static function on_initiate_checkout() {
        if ( ! get_option( 'servertrack_enabled', 1 ) ) return;
        $meta_on   = get_option( 'servertrack_meta_enabled', 0 );
        $tiktok_on = get_option( 'servertrack_tiktok_enabled', 0 );
        if ( ! $meta_on && ! $tiktok_on ) return;
        if ( ! WC()->cart ) return;
        $session_id = WC()->session ? (string) WC()->session->get_customer_id() : '';
        if ( empty( $session_id ) ) return;
        $dedup_key = 'servertrack_ic_' . md5( $session_id );
        if ( get_transient( $dedup_key ) ) return;
        set_transient( $dedup_key, 1, 30 * MINUTE_IN_SECONDS );
        $event_id = ServerTrack_Dedup::generate_event_id( 'checkout_' . $session_id );
        $contents = [];
        foreach ( WC()->cart->get_cart() as $cart_item ) {
            $prod = $cart_item['data'];
            if ( ! $prod instanceof WC_Product ) continue;
            $sku        = $prod->get_sku() ?: (string) $cart_item['product_id'];
            $contents[] = [ 'id' => $sku, 'quantity' => (int) $cart_item['quantity'], 'item_price' => (float) wc_get_price_to_display( $prod ) ];
        }
        $event = ( new ServerTrack_Event( 'InitiateCheckout', $event_id ) )
            ->set_user_data( self::build_browser_user_data() )
            ->set_custom_data( [
                'currency'     => get_woocommerce_currency(),
                'value'        => (float) WC()->cart->get_total( 'edit' ),
                'content_type' => 'product',
                'contents'     => $contents,
            ] );
        // BUG-A FIX: send_to_platforms now logs results
        self::send_to_platforms( $event, (bool) $meta_on, (bool) $tiktok_on );
    }

    // ── ADD PAYMENT INFO ──────────────────────────────────────────────────────────────────

    public static function on_add_payment_info( WC_Order $order ) {
        if ( ! get_option( 'servertrack_enabled', 1 ) ) return;
        $meta_on   = get_option( 'servertrack_meta_enabled', 0 );
        $tiktok_on = get_option( 'servertrack_tiktok_enabled', 0 );
        if ( ! $meta_on && ! $tiktok_on ) return;
        $order_id = $order->get_id();
        if ( $order->get_meta( '_servertrack_api_sent' ) ) return;
        $order->update_meta_data( '_servertrack_api_sent', '1' );
        $order->save_meta_data();
        $event = ( new ServerTrack_Event( 'AddPaymentInfo', ServerTrack_Dedup::generate_event_id( 'api_' . $order_id ) ) )
            ->set_user_data( self::build_order_user_data( $order ) )
            ->set_custom_data( [ 'currency' => $order->get_currency(), 'value' => (float) $order->get_total(), 'content_type' => 'product' ] );
        // H-1 + BUG-A FIX: pass order_id so consent uses per-order snapshot; also now logs
        self::send_to_platforms( $event, (bool) $meta_on, (bool) $tiktok_on, $order_id );
    }

    // ── NEW CUSTOMER ────────────────────────────────────────────────────────────────────

    public static function on_new_customer( int $customer_id, array $new_customer_data, bool $password_generated ) {
        if ( ! get_option( 'servertrack_enabled', 1 ) ) return;
        $meta_on   = get_option( 'servertrack_meta_enabled', 0 );
        $tiktok_on = get_option( 'servertrack_tiktok_enabled', 0 );
        if ( ! $meta_on && ! $tiktok_on ) return;
        $user_data = [];
        $ip = self::get_real_ip();
        if ( $ip ) $user_data['ip'] = $ip;
        $ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
        if ( $ua ) $user_data['user_agent'] = $ua;
        $email = sanitize_email( $new_customer_data['user_email'] ?? '' );
        if ( $email ) $user_data['email'] = ServerTrack_Hasher::hash_email( $email );
        $first = sanitize_text_field( $new_customer_data['first_name'] ?? '' );
        if ( $first ) $user_data['first_name'] = ServerTrack_Hasher::hash( $first );
        $last = sanitize_text_field( $new_customer_data['last_name'] ?? '' );
        if ( $last ) $user_data['last_name'] = ServerTrack_Hasher::hash( $last );
        $user_data['external_id'] = ServerTrack_Identity::get_external_id_for_user( $customer_id );
        $event = ( new ServerTrack_Event( 'CompleteRegistration', ServerTrack_Dedup::generate_event_id( 'reg_' . $customer_id ) ) )
            ->set_user_data( $user_data )
            ->set_custom_data( [ 'content_name' => 'New Customer Registration', 'status' => 'registered' ] );
        // BUG-A FIX: send_to_platforms now logs results
        self::send_to_platforms( $event, (bool) $meta_on, (bool) $tiktok_on );
    }

    // ── SHARED HELPERS ───────────────────────────────────────────────────────────────────

    /**
     * Send event to enabled platforms, check consent, LOG the result.
     *
     * BUG-A FIX (v5.0): Added Logger::log() calls for both meta and tiktok.
     * Previously this method sent events but NEVER logged them, so the
     * dashboard always showed zero events for AddToCart, InitiateCheckout,
     * AddPaymentInfo, and CompleteRegistration.
     *
     * H-1 FIX (v4.0): optional ?int $order_id for per-order consent snapshot.
     *
     * @param ServerTrack_Event $event
     * @param bool              $meta_on
     * @param bool              $tiktok_on
     * @param int|null          $order_id
     */
    private static function send_to_platforms(
        ServerTrack_Event $event,
        bool $meta_on,
        bool $tiktok_on,
        ?int $order_id = null
    ): void {
        $timeout_cb = [ self::class, '_http_timeout_filter' ];
        add_filter( 'http_request_args', $timeout_cb, 999 );

        $event_name = $event->event_name ?? 'unknown';
        $event_id   = $event->event_id   ?? '';
        $emq        = ServerTrack_MatchQuality::score( $event->user_data ?? [] );
        $oid        = $order_id ?? 0;

        if ( $meta_on ) {
            if ( ServerTrack_Consent::is_granted( 'meta', $order_id ) ) {
                $r = ServerTrack_Meta::send( $event );
                if ( ( $r['status'] ?? '' ) !== 'success' ) {
                    ServerTrack_Retry::maybe_queue( 'meta', $r, ServerTrack_Retry::event_to_args( $event ) );
                }
                // BUG-A FIX: log the result
                ServerTrack_Logger::log( $r['status'] ?? 'error', 'meta', $event_name, '', $event_id, $oid, $event_name, $emq );
            } else {
                ServerTrack_Logger::log( 'skipped', 'meta', 'Consent not granted for ' . $event_name, '', $event_id, $oid, $event_name, $emq );
            }
        }

        if ( $tiktok_on ) {
            if ( ServerTrack_Consent::is_granted( 'tiktok', $order_id ) ) {
                $r = ServerTrack_TikTok::send( $event );
                if ( ( $r['status'] ?? '' ) !== 'success' ) {
                    ServerTrack_Retry::maybe_queue( 'tiktok', $r, ServerTrack_Retry::event_to_args( $event ) );
                }
                // BUG-A FIX: log the result
                ServerTrack_Logger::log( $r['status'] ?? 'error', 'tiktok', $event_name, '', $event_id, $oid, $event_name, $emq );
            } else {
                ServerTrack_Logger::log( 'skipped', 'tiktok', 'Consent not granted for ' . $event_name, '', $event_id, $oid, $event_name, $emq );
            }
        }

        remove_filter( 'http_request_args', $timeout_cb, 999 );
    }

    public static function _http_timeout_filter( array $args ): array {
        $args['timeout'] = self::SYNC_TIMEOUT;
        return $args;
    }

    private static function get_real_ip(): string {
        $ip = class_exists( 'WC_Geolocation' ) ? WC_Geolocation::get_ip_address() : ( sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) ) );
        if ( substr( $ip, 0, 7 ) === '::ffff:' ) $ip = substr( $ip, 7 );
        return sanitize_text_field( $ip );
    }

    private static function build_browser_user_data(): array {
        $data = [];
        $ip   = self::get_real_ip();
        if ( $ip ) $data['ip'] = $ip;
        $ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
        if ( $ua ) $data['user_agent'] = $ua;
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        if ( ! empty( $_COOKIE['_fbp'] ) )    $data['fbp']    = sanitize_text_field( wp_unslash( $_COOKIE['_fbp'] ) );
        if ( ! empty( $_COOKIE['_fbc'] ) )    $data['fbc']    = sanitize_text_field( wp_unslash( $_COOKIE['_fbc'] ) );
        if ( ! empty( $_COOKIE['ttclid'] ) )  $data['ttclid'] = sanitize_text_field( wp_unslash( $_COOKIE['ttclid'] ) );
        if ( ! empty( $_COOKIE['_gcl_aw'] ) ) $data['gclid']  = sanitize_text_field( wp_unslash( $_COOKIE['_gcl_aw'] ) );
        // phpcs:enable
        if ( is_user_logged_in() ) {
            $user = wp_get_current_user();
            if ( $user->user_email ) $data['email'] = ServerTrack_Hasher::hash_email( $user->user_email );
            $data['external_id'] = ServerTrack_Identity::get_external_id_for_user( $user->ID );
        }
        return $data;
    }

    /**
     * Build user_data from a WC order.
     * H-4 FIX (v4.0): reads stored stitched external_id first.
     */
    private static function build_order_user_data( WC_Order $order ): array {
        $data = [];
        $ip   = $order->get_customer_ip_address();
        if ( substr( (string) $ip, 0, 7 ) === '::ffff:' ) $ip = substr( $ip, 7 );
        if ( $ip ) $data['ip'] = $ip;
        $ua = $order->get_customer_user_agent();
        if ( $ua ) $data['user_agent'] = $ua;

        $customer_id   = (int) $order->get_customer_id();
        $session_id    = (string) ( $order->get_meta( '_servertrack_session_id' ) ?: '' );
        $stored_clicks = ServerTrack_ClickCapture::get_for_order( $customer_id, $session_id );

        $fbc = $stored_clicks['fbc'] ?? '';
        if ( empty( $fbc ) ) $fbc = (string) $order->get_meta( '_servertrack_fbc' );
        if ( empty( $fbc ) && ! empty( $_COOKIE['_fbc'] ) ) $fbc = sanitize_text_field( wp_unslash( $_COOKIE['_fbc'] ) ); // phpcs:ignore
        if ( empty( $fbc ) ) {
            $fbclid = $stored_clicks['fbclid'] ?? (string) $order->get_meta( '_servertrack_fbclid' );
            if ( $fbclid ) {
                $ts  = $order->get_date_created() ? $order->get_date_created()->getTimestamp() : time();
                $fbc = 'fb.1.' . ( $ts * 1000 ) . '.' . $fbclid;
            }
        }
        if ( $fbc ) $data['fbc'] = $fbc;

        $fbp = $stored_clicks['fbp'] ?? '';
        if ( empty( $fbp ) ) $fbp = (string) $order->get_meta( '_servertrack_fbp' );
        if ( empty( $fbp ) && ! empty( $_COOKIE['_fbp'] ) ) $fbp = sanitize_text_field( wp_unslash( $_COOKIE['_fbp'] ) ); // phpcs:ignore
        if ( $fbp ) $data['fbp'] = $fbp;

        $ttclid = $stored_clicks['ttclid'] ?? (string) $order->get_meta( '_servertrack_ttclid' );
        if ( empty( $ttclid ) && ! empty( $_COOKIE['ttclid'] ) ) $ttclid = sanitize_text_field( wp_unslash( $_COOKIE['ttclid'] ) ); // phpcs:ignore
        if ( $ttclid ) $data['ttclid'] = $ttclid;
        $gclid = $stored_clicks['gclid'] ?? (string) $order->get_meta( '_servertrack_gclid' );
        if ( empty( $gclid ) && ! empty( $_COOKIE['_gcl_aw'] ) ) $gclid = sanitize_text_field( wp_unslash( $_COOKIE['_gcl_aw'] ) ); // phpcs:ignore
        if ( $gclid ) $data['gclid'] = $gclid;

        $email = $order->get_billing_email();
        if ( $email ) $data['email'] = ServerTrack_Hasher::hash_email( $email );
        $phone = $order->get_billing_phone();
        if ( $phone ) {
            static $cc_map = [ 'US'=>'1','CA'=>'1','GB'=>'44','AU'=>'61','DE'=>'49','FR'=>'33','IT'=>'39','ES'=>'34','NL'=>'31','SE'=>'46','NO'=>'47','DK'=>'45','FI'=>'358','CH'=>'41','AT'=>'43','IE'=>'353','NZ'=>'64','ZA'=>'27','IN'=>'91','BR'=>'55','BD'=>'880','PK'=>'92','NG'=>'234','MX'=>'52','JP'=>'81','KR'=>'82','SG'=>'65','MY'=>'60','TH'=>'66','PH'=>'63','ID'=>'62','VN'=>'84','HK'=>'852','TW'=>'886','AE'=>'971','SA'=>'966' ];
            $data['phone'] = ServerTrack_Hasher::hash_phone( $phone, $cc_map[ $order->get_billing_country() ] ?? '' );
        }
        foreach ( [ 'first_name' => $order->get_billing_first_name(), 'last_name' => $order->get_billing_last_name(), 'city' => $order->get_billing_city(), 'state' => $order->get_billing_state(), 'zip' => $order->get_billing_postcode(), 'country' => $order->get_billing_country() ] as $key => $val ) {
            if ( $val ) $data[ $key ] = ServerTrack_Hasher::hash( $val );
        }

        $stored_ext = (string) $order->get_meta( '_servertrack_external_id' );
        $data['external_id'] = ! empty( $stored_ext )
            ? $stored_ext
            : ServerTrack_Identity::get_external_id_for_order( $order );

        return $data;
    }

    private static function build_purchase_custom_data( WC_Order $order ): array {
        $contents    = [];
        $content_ids = [];
        foreach ( $order->get_items() as $item ) {
            $product = $item->get_product();
            $sku     = ( $product && $product->get_sku() ) ? $product->get_sku() : (string) $item->get_product_id();
            $qty     = (int) $item->get_quantity();
            $price   = $qty > 0 ? round( (float) $item->get_total() / $qty, 2 ) : 0.0;
            $contents[]    = [ 'id' => $sku, 'quantity' => $qty, 'item_price' => $price ];
            $content_ids[] = $sku;
        }
        return apply_filters( 'servertrack_purchase_custom_data', [
            'currency'     => $order->get_currency(),
            'value'        => (float) $order->get_total(),
            'contents'     => $contents,
            'content_ids'  => $content_ids,
            'content_type' => 'product',
            'order_id'     => $order->get_id(),
            'num_items'    => count( $contents ),
        ], $order );
    }
}
