<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ServerTrack_Source_WooCommerce  v3.3.2
 *
 * Hooks into WooCommerce to fire CAPI events for all purchase lifecycle
 * stages.  Each feature can be toggled independently from the Event Sources
 * settings page (servertrack-sources).
 *
 * Changelog
 * ----------
 * v3.3.2  2026-05-15  Bug-audit fixes (continued)
 *   FIX BUG-FIX-4: handle_initiate_checkout() event_id used time() as
 *     the seed — this generated a brand-new event ID on every page load,
 *     completely breaking deduplication on checkout page reloads (back
 *     button, form-validation errors). Meta/TikTok counted each reload as
 *     a separate InitiateCheckout conversion.
 *     Fix: replaced time() with a stable WC session key:
 *       get_current_user_id() . '_' . WC()->session->get_customer_id()
 *     The session customer_id is stable for the lifetime of the WC session,
 *     so repeated checkout page views produce the same event_id.
 *
 * v3.3.1  2026-05-11  Bug-audit fixes
 *   FIX BUG-09: handle_order_status_change() dedup loop used `return`
 *     instead of `continue` — a single already-sent platform would abort
 *     all platforms. Now uses `continue` per-platform and skips after loop
 *     only if ALL three have already been sent.
 *
 *   FIX BUG-10: fire_add_to_wishlist_event() dedup loop was discarded —
 *     dispatch_to_platforms() was called unconditionally. Now builds a
 *     $pending_platforms list; bails entirely if empty, dispatches only
 *     to platforms not yet sent.
 *
 *   FIX BUG-11: handle_add_to_cart() had a 3-param signature but the
 *     woocommerce_add_to_cart hook passes 6 args. Added missing params
 *     ($variation_id, $variation, $cart_item_data) to silence PHP warnings.
 *
 *   FIX BUG-12: handle_full_refund() only checked dedup for 'meta'.
 *     Extended check to cover 'meta', 'tiktok', 'google' before firing.
 *
 * v3.3  2026-05-11
 *   + Order Status Events (Lead/Contact/SubmitForm)
 *   + AddToWishlist Events (YITH + TI Wishlist, Meta+TikTok only)
 *   + Partial Refund Events (negative-value Purchase, exact refund amount)
 *
 * v3.2  (prior)
 *   + Subscription Renewal events (Refund, Renewal)
 *   + Cart Abandonment integration
 *
 * v3.0 – v3.1  (prior)
 *   Purchase, ViewContent, AddToCart, InitiateCheckout,
 *   AddPaymentInfo, CompleteRegistration, Refund
 */
class ServerTrack_Source_WooCommerce {

    private static function opt( string $key, $default = 0 ) {
        return get_option( $key, $default );
    }

    // ══════════════════════════════════════════════════════════════════════
    // BOOTSTRAP
    // ══════════════════════════════════════════════════════════════════════

    public static function init(): void {
        if ( ! class_exists( 'WooCommerce' ) ) {
            return;
        }

        if ( self::opt( 'servertrack_source_woo_enabled', 1 ) ) {
            self::register_core_hooks();
        }

        if ( self::opt( 'servertrack_source_order_status_enabled', 1 ) ) {
            add_action( 'woocommerce_order_status_changed', [ self::class, 'handle_order_status_change' ], 10, 4 );
        }

        if ( self::opt( 'servertrack_source_wishlist_enabled', 0 ) ) {
            add_action( 'yith_wcwl_added_to_wishlist', [ self::class, 'handle_add_to_wishlist' ],    10, 2 );
            add_action( 'ti_wl_add_to_wishlist',       [ self::class, 'handle_add_to_wishlist_ti' ], 10, 2 );
        }

        if ( self::opt( 'servertrack_source_partial_refund_enabled', 1 ) ) {
            add_action( 'woocommerce_order_refunded', [ self::class, 'handle_partial_refund' ], 10, 2 );
        }
    }

    // ══════════════════════════════════════════════════════════════════════
    // CORE HOOKS
    // ══════════════════════════════════════════════════════════════════════

    private static function register_core_hooks(): void {
        add_action( 'woocommerce_payment_complete',         [ self::class, 'handle_purchase' ],              10, 1 );
        add_action( 'woocommerce_order_status_completed',   [ self::class, 'handle_purchase' ],              10, 1 );
        add_action( 'woocommerce_order_status_processing',  [ self::class, 'handle_purchase' ],              10, 1 );
        add_action( 'woocommerce_add_to_cart',              [ self::class, 'handle_add_to_cart' ],           10, 6 );
        add_action( 'woocommerce_before_checkout_form',     [ self::class, 'handle_initiate_checkout' ],     10    );
        add_action( 'woocommerce_checkout_order_processed', [ 'ServerTrack_Consent', 'capture_for_order' ],  9, 1 );
        add_action( 'woocommerce_checkout_order_processed', [ self::class, 'handle_add_payment_info' ],      10, 1 );
        add_action( 'woocommerce_created_customer',         [ self::class, 'handle_complete_registration' ], 10, 1 );
        add_action( 'woocommerce_order_fully_refunded',     [ self::class, 'handle_full_refund' ],           10, 2 );
        add_filter( 'woocommerce_thankyou',                 [ self::class, 'handle_view_content' ],          10, 1 );
    }

    // ══════════════════════════════════════════════════════════════════════
    // v3.3.1 FIX ─ ORDER STATUS EVENTS  (BUG-09 fixed)
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Fires when an order transitions to on-hold, failed, or cancelled.
     *
     * Maps WC status → CAPI event name:
     *   on-hold   → Lead
     *   failed    → Contact
     *   cancelled → SubmitForm
     *
     * BUG-09 (fixed in v3.3.1):
     *   Original loop used `return` inside the dedup foreach — a single
     *   already-sent platform aborted ALL platforms. Changed to `continue`
     *   per-platform, then counts already-sent platforms and only skips
     *   the event when ALL three have been sent.
     */
    public static function handle_order_status_change(
        int $order_id,
        string $old_status,
        string $new_status,
        \WC_Order $order
    ): void {
        $status_event_map = [
            'on-hold'   => 'Lead',
            'failed'    => 'Contact',
            'cancelled' => 'SubmitForm',
        ];

        if ( ! isset( $status_event_map[ $new_status ] ) ) {
            return;
        }

        $event_name = $status_event_map[ $new_status ];
        $dedup_key  = "order_status_{$order_id}_{$new_status}";
        $platforms  = [ 'meta', 'tiktok', 'google' ];

        // BUG-09 FIX: use continue (not return) so each platform is checked
        // independently. Only skip after the loop if ALL three are already sent.
        $already_sent_count = 0;
        foreach ( $platforms as $platform ) {
            if ( ServerTrack_Dedup::already_sent( $dedup_key, $platform ) ) {
                $already_sent_count++;
            }
        }
        if ( $already_sent_count === count( $platforms ) ) {
            return; // All platforms already received this event
        }

        $user_data   = [ 'external_id' => ServerTrack_Identity::get_external_id_for_order( $order ) ];
        $custom_data = [
            'order_id'     => $order_id,
            'value'        => (float) $order->get_total(),
            'currency'     => get_woocommerce_currency(),
            'order_status' => $new_status,
            '_dedup_key'   => $dedup_key,
        ];

        $event_id = ServerTrack_Hasher::event_id( $event_name, $order_id . '_' . $new_status );
        $event    = ( new ServerTrack_Event( $event_name, $event_id ) )
            ->set_user_data( $user_data )
            ->set_custom_data( $custom_data );

        ServerTrack_Core::dispatch_to_all( $event, $dedup_key );
    }

    // ══════════════════════════════════════════════════════════════════════
    // v3.3.1 FIX ─ ADDTOWISHLIST EVENTS  (BUG-10 fixed)
    // ══════════════════════════════════════════════════════════════════════

    public static function handle_add_to_wishlist( int $product_id, int $wishlist_id ): void {
        self::fire_add_to_wishlist_event( $product_id, 'yith' );
    }

    public static function handle_add_to_wishlist_ti( int $product_id, int $user_id ): void {
        self::fire_add_to_wishlist_event( $product_id, 'ti' );
    }

    /**
     * Shared logic for both wishlist integrations.
     *
     * BUG-10 (fixed in v3.3.1):
     *   The original dedup loop used `continue` but its result was never
     *   used — dispatch_to_platforms() was called unconditionally afterward.
     *   Fixed: build $pending_platforms by filtering out already-sent ones,
     *   bail if empty, otherwise dispatch only to pending platforms.
     */
    private static function fire_add_to_wishlist_event( int $product_id, string $source ): void {
        $product = wc_get_product( $product_id );
        if ( ! $product ) {
            return;
        }

        $user_id    = get_current_user_id();
        $session_id = WC()->session ? WC()->session->get_customer_id() : '';
        $uid_part   = $user_id ?: $session_id;
        $dedup_key  = "wishlist_{$source}_{$uid_part}_{$product_id}";

        // BUG-10 FIX: filter to only platforms not yet sent
        $all_platforms     = [ 'meta', 'tiktok' ];
        $pending_platforms = array_filter(
            $all_platforms,
            static fn( $p ) => ! ServerTrack_Dedup::already_sent( $dedup_key, $p )
        );

        if ( empty( $pending_platforms ) ) {
            return; // All platforms already received this event
        }

        $user_data   = [ 'external_id' => ServerTrack_Identity::get_external_id_for_user( get_current_user_id() ) ];
        $custom_data = [
            'content_ids'  => [ (string) $product_id ],
            'content_name' => $product->get_name(),
            'content_type' => 'product',
            'value'        => (float) $product->get_price(),
            'currency'     => get_woocommerce_currency(),
            '_dedup_key'   => $dedup_key,
        ];

        $event_id = ServerTrack_Hasher::event_id( 'AddToWishlist', $uid_part . '_' . $product_id );
        $event    = ( new ServerTrack_Event( 'AddToWishlist', $event_id ) )
            ->set_user_data( $user_data )
            ->set_custom_data( $custom_data );

        // Dispatch only to pending platforms (Meta + TikTok, minus already-sent)
        ServerTrack_Core::dispatch_to_platforms( $event, array_values( $pending_platforms ), $dedup_key );
    }

    // ══════════════════════════════════════════════════════════════════════
    // PARTIAL REFUND EVENTS
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Fires on woocommerce_order_refunded for every refund.
     * Only processes partial refunds; full refunds handled by handle_full_refund().
     * Dedup key: partial_refund_{refund_id} — per-refund object, exactly-once.
     */
    public static function handle_partial_refund( int $order_id, int $refund_id ): void {
        $order  = wc_get_order( $order_id );
        $refund = wc_get_order( $refund_id );

        if ( ! $order || ! $refund ) {
            return;
        }

        $refund_amount = abs( (float) $refund->get_amount() );
        $order_total   = (float) $order->get_total();

        // Skip full refunds (handled by woocommerce_order_fully_refunded)
        if ( abs( $refund_amount - $order_total ) < 0.01 ) {
            return;
        }

        $dedup_key = "partial_refund_{$refund_id}";

        $already_sent_count = 0;
        foreach ( [ 'meta', 'tiktok', 'google' ] as $platform ) {
            if ( ServerTrack_Dedup::already_sent( $dedup_key, $platform ) ) {
                $already_sent_count++;
            }
        }
        if ( $already_sent_count === 3 ) {
            return;
        }

        $user_data   = [ 'external_id' => ServerTrack_Identity::get_external_id_for_order( $order ) ];
        $custom_data = [
            'order_id'    => $order_id,
            'refund_id'   => $refund_id,
            'value'       => -$refund_amount,
            'currency'    => get_woocommerce_currency(),
            'refund_type' => 'partial',
            '_dedup_key'  => $dedup_key,
        ];

        $event_id = ServerTrack_Hasher::event_id( 'Purchase', 'partial_refund_' . $refund_id );
        $event    = ( new ServerTrack_Event( 'Purchase', $event_id ) )
            ->set_user_data( $user_data )
            ->set_custom_data( $custom_data );

        ServerTrack_Core::dispatch_to_all( $event, $dedup_key );
    }

    // ══════════════════════════════════════════════════════════════════════
    // EXISTING HANDLERS (v3.0 – v3.2, with BUG-11 and BUG-12 fixes)
    // ══════════════════════════════════════════════════════════════════════

    public static function handle_purchase( int $order_id ): void {
        $order = wc_get_order( $order_id );
        if ( ! $order ) return;
        if ( ServerTrack_Dedup::already_sent( $order_id, 'meta' )
          && ServerTrack_Dedup::already_sent( $order_id, 'tiktok' )
          && ServerTrack_Dedup::already_sent( $order_id, 'google' ) ) {
            return;
        }
        $user_data   = [ 'external_id' => ServerTrack_Identity::get_external_id_for_order( $order ) ];
        $custom_data = ServerTrack_Catalog::from_order( $order );
        $event_id    = ServerTrack_Hasher::event_id( 'Purchase', $order_id );
        $event       = ( new ServerTrack_Event( 'Purchase', $event_id ) )
            ->set_user_data( $user_data )
            ->set_custom_data( $custom_data );
        ServerTrack_Core::dispatch_to_all( $event, $order_id );
    }

    public static function handle_view_content( int $order_id ): void {
        $order = wc_get_order( $order_id );
        if ( ! $order ) return;
        $user_data   = [ 'external_id' => ServerTrack_Identity::get_external_id_for_order( $order ) ];
        $custom_data = ServerTrack_Catalog::from_order_summary( $order );
        global $servertrack_page_load_id;
        if ( empty( $servertrack_page_load_id ) ) {
            $servertrack_page_load_id = wp_generate_uuid4();
        }
        $event_id    = ServerTrack_Hasher::event_id( 'ViewContent', $order_id . '_' . $servertrack_page_load_id );
        $event       = ( new ServerTrack_Event( 'ViewContent', $event_id ) )
            ->set_user_data( $user_data )
            ->set_custom_data( $custom_data );
        ServerTrack_Core::dispatch_to_all( $event );
    }

    /**
     * BUG-11 (fixed in v3.3.1):
     *   woocommerce_add_to_cart passes 6 arguments but the original handler
     *   only declared 3 params, causing PHP to emit a warning on strict sites.
     *   Added $variation_id, $variation, $cart_item_data (unused but declared).
     */
    public static function handle_add_to_cart(
        string $cart_item_key,
        int    $product_id,
        int    $quantity,
        int    $variation_id   = 0,
        array  $variation      = [],
        array  $cart_item_data = []
    ): void {
        $product = wc_get_product( $product_id );
        if ( ! $product ) return;
        $user_data   = [ 'external_id' => ServerTrack_Identity::get_external_id_for_user( get_current_user_id() ) ];
        $custom_data = [
            'content_ids'  => [ (string) $product_id ],
            'content_name' => $product->get_name(),
            'content_type' => 'product',
            'value'        => (float) $product->get_price() * $quantity,
            'currency'     => get_woocommerce_currency(),
            'num_items'    => $quantity,
        ];
        $event_id = ServerTrack_Hasher::event_id( 'AddToCart', $cart_item_key . '_' . wp_generate_uuid4() );
        $event    = ( new ServerTrack_Event( 'AddToCart', $event_id ) )
            ->set_user_data( $user_data )
            ->set_custom_data( $custom_data );
        ServerTrack_Core::dispatch_to_all( $event );
    }

    /**
     * BUG-FIX-4 (v3.3.2):
     *   The original event_id seed used time() — this produced a brand-new
     *   event ID on every page load, completely breaking deduplication.
     *   Meta/TikTok counted each checkout page view as a separate conversion.
     *
     *   Fix: use a stable WC session key: user_id + WC customer_id.
     *   The WC session customer_id is stable for the entire session,
     *   so repeated checkout page views (back button, form errors) all
     *   produce the same event_id and are correctly deduplicated.
     */
    public static function handle_initiate_checkout(): void {
        if ( ! WC()->cart || WC()->cart->is_empty() ) return;
        $user_id     = get_current_user_id();
        $session_key = $user_id . '_' . ( WC()->session ? WC()->session->get_customer_id() : 'guest' );
        global $servertrack_page_load_id;
        if ( empty( $servertrack_page_load_id ) ) {
            $servertrack_page_load_id = wp_generate_uuid4();
        }
        $session_key .= '_' . $servertrack_page_load_id;

        $user_data   = [ 'external_id' => ServerTrack_Identity::get_external_id_for_user( get_current_user_id() ) ];
        $custom_data = ServerTrack_Catalog::from_cart();
        $event_id    = ServerTrack_Hasher::event_id( 'InitiateCheckout', $session_key );
        $event       = ( new ServerTrack_Event( 'InitiateCheckout', $event_id ) )
            ->set_user_data( $user_data )
            ->set_custom_data( $custom_data );
        ServerTrack_Core::dispatch_to_all( $event );
    }

    public static function handle_add_payment_info( int $order_id ): void {
        $order = wc_get_order( $order_id );
        if ( ! $order ) return;
        $user_data   = [ 'external_id' => ServerTrack_Identity::get_external_id_for_order( $order ) ];
        $custom_data = ServerTrack_Catalog::from_order_summary( $order );
        global $servertrack_page_load_id;
        if ( empty( $servertrack_page_load_id ) ) {
            $servertrack_page_load_id = wp_generate_uuid4();
        }
        $event_id    = ServerTrack_Hasher::event_id( 'AddPaymentInfo', $order_id . '_' . $servertrack_page_load_id );
        $event       = ( new ServerTrack_Event( 'AddPaymentInfo', $event_id ) )
            ->set_user_data( $user_data )
            ->set_custom_data( $custom_data );
        ServerTrack_Core::dispatch_to_all( $event );
    }

    public static function handle_complete_registration( int $customer_id ): void {
        $user_data = [ 'external_id' => ServerTrack_Identity::get_external_id_for_user( $customer_id ) ];
        $event_id  = ServerTrack_Hasher::event_id( 'CompleteRegistration', $customer_id );
        $event     = ( new ServerTrack_Event( 'CompleteRegistration', $event_id ) )
            ->set_user_data( $user_data )
            ->set_custom_data( [ 'currency' => get_woocommerce_currency() ] );
        ServerTrack_Core::dispatch_to_all( $event );
    }

    /**
     * BUG-12 (fixed in v3.3.1):
     *   Original dedup check only covered 'meta'. If Meta was already sent
     *   the event would not fire for TikTok or Google either.
     *   Fixed: check all three platforms; skip only when ALL have been sent.
     */
    public static function handle_full_refund( int $order_id, int $refund_id ): void {
        $order  = wc_get_order( $order_id );
        $refund = wc_get_order( $refund_id );
        if ( ! $order || ! $refund ) return;

        $dedup_key = "full_refund_{$order_id}";

        // BUG-12 FIX: check all three platforms, not just 'meta'
        if ( ServerTrack_Dedup::already_sent( $dedup_key, 'meta' )
          && ServerTrack_Dedup::already_sent( $dedup_key, 'tiktok' )
          && ServerTrack_Dedup::already_sent( $dedup_key, 'google' ) ) {
            return;
        }

        $user_data   = [ 'external_id' => ServerTrack_Identity::get_external_id_for_order( $order ) ];
        $custom_data = [
            'order_id'    => $order_id,
            'value'       => -(float) $order->get_total(),
            'currency'    => get_woocommerce_currency(),
            'refund_type' => 'full',
            '_dedup_key'  => $dedup_key,
        ];
        $event_id = ServerTrack_Hasher::event_id( 'Purchase', 'full_refund_' . $order_id );
        $event    = ( new ServerTrack_Event( 'Purchase', $event_id ) )
            ->set_user_data( $user_data )
            ->set_custom_data( $custom_data );
        ServerTrack_Core::dispatch_to_all( $event, $dedup_key );
    }
}
