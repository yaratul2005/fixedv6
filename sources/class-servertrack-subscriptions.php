<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ServerTrack_Subscriptions  v1.2
 *
 * Feature #4 — WooCommerce Subscriptions CAPI Events.
 *
 * WooCommerce Subscriptions fires unique lifecycle hooks that the core
 * WooCommerce source misses completely. This source handles:
 *
 *   1. Subscription Renewal (new recurring payment):
 *      Hook: woocommerce_subscription_renewal_payment_complete
 *      Event: Purchase (with subscription metadata so Meta knows it's LTV)
 *      Sent to: Meta, TikTok, Google
 *
 *   2. Subscription Cancellation:
 *      Hook: woocommerce_subscription_status_cancelled
 *      Event: CustomEvent 'SubscriptionCancelled' (Meta custom) /
 *             PlaceAnOrder with negative value (TikTok fallback) /
 *             refund hit (Google)
 *      Sent to: Meta, TikTok, Google
 *
 *   3. Subscription Pause (active → on-hold):
 *      Hook: woocommerce_subscription_status_on-hold
 *      Event: CustomEvent 'SubscriptionPaused' (Meta custom)
 *      Sent to: Meta only (TikTok/Google have no equivalent)
 *
 * Dedup: each event uses a stable string key derived from subscription ID +
 * event type. All dedup reads/writes go through the options-based string API
 * (ServerTrack_Dedup::already_sent / mark_string_sent / get / set) so that
 * string keys are never coerced to int and routed to order #0 meta.
 *
 * Consent: inherits per-order consent captured at initial purchase.
 *
 * Changelog:
 *   v1.2 — BUG-M1 fix: switched all three async handlers from the integer
 *           order-meta dedup API to the options-based string dedup API.
 *           get_event_id($key)     → ServerTrack_Dedup::get( $key )
 *           store_event_id($key)   → ServerTrack_Dedup::set_event( $key, $id )
 *           was_sent($key, $p)     → ServerTrack_Dedup::already_sent( $key, $p )
 *           mark_as_sent($key, $p) → ServerTrack_Dedup::mark_string_sent( $key, $p )
 *           Previously all three handlers coerced string keys to int 0,
 *           meaning every subscription dedup read/write hit order #0 meta.
 *           Fresh UUIDs were generated on every cron retry, and the sent
 *           flag was never stored — 100% of subscription events had broken
 *           dedup and could double-fire on retries.
 *   v1.1 — BUG-LIVE-1: Added missing TikTok block to send_cancelled_async().
 *           TikTok uses PlaceAnOrder with a negative value as the closest
 *           equivalent to a cancellation/refund event.
 */
class ServerTrack_Subscriptions {
    const SUBSCRIPTION_CANCEL_DEDUP_KEY = 'sub_cancellation_';

    public static function init(): void {
        if ( ! get_option( 'servertrack_enabled', 1 ) ) return;
        if ( ! class_exists( 'WC_Subscriptions' ) ) return;

        add_action(
            'woocommerce_subscription_renewal_payment_complete',
            [ self::class, 'on_renewal' ], 10, 2
        );
        add_action(
            'woocommerce_subscription_status_cancelled',
            [ self::class, 'on_cancelled' ], 10, 1
        );
        add_action(
            'woocommerce_subscription_status_on-hold',
            [ self::class, 'on_paused' ], 10, 1
        );

        // Async cron handlers
        add_action( 'servertrack_send_sub_renewal',   [ self::class, 'send_renewal_async' ],    10, 2 );
        add_action( 'servertrack_send_sub_cancelled', [ self::class, 'send_cancelled_async' ],  10, 1 );
        add_action( 'servertrack_send_sub_paused',    [ self::class, 'send_paused_async' ],     10, 1 );
    }

    // ────────────────────────────────────────────────────────────────────────
    // HOOKS
    // ────────────────────────────────────────────────────────────────────────

    public static function on_renewal( WC_Subscription $subscription, WC_Order $renewal_order ): void {
        if ( ! get_option( 'servertrack_enabled', 1 ) ) return;
        $sub_id = $subscription->get_id();
        $ord_id = $renewal_order->get_id();
        wp_schedule_single_event( time(), 'servertrack_send_sub_renewal', [ $sub_id, $ord_id ] );
        spawn_cron();
    }

    public static function on_cancelled( WC_Subscription $subscription ): void {
        if ( ! get_option( 'servertrack_enabled', 1 ) ) return;
        wp_schedule_single_event( time(), 'servertrack_send_sub_cancelled', [ $subscription->get_id() ] );
        spawn_cron();
    }

    public static function on_paused( WC_Subscription $subscription ): void {
        if ( ! get_option( 'servertrack_enabled', 1 ) ) return;
        if ( ! get_option( 'servertrack_meta_enabled', 0 ) ) return;
        wp_schedule_single_event( time(), 'servertrack_send_sub_paused', [ $subscription->get_id() ] );
        spawn_cron();
    }

    // ────────────────────────────────────────────────────────────────────────
    // ASYNC CRON HANDLERS
    // ────────────────────────────────────────────────────────────────────────

    public static function send_renewal_async( int $sub_id, int $order_id ): void {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            ServerTrack_Logger::log( 'error', 'all', 'Renewal: order #' . $order_id . ' not found.', '', '', $order_id, 'Renewal' );
            return;
        }

        // BUG-M1 FIX: use options-based string dedup API — never cast string key to int.
        $dedup_key = 'renewal_' . $sub_id . '_' . $order_id;
        $event_id  = self::get_string_event_id( $dedup_key );
        if ( empty( $event_id ) ) {
            $event_id = ServerTrack_Dedup::generate_event_id( $dedup_key );
            self::set_string_event_id( $dedup_key, $event_id );
        }

        $user_data   = self::build_order_user_data( $order );
        $custom_data = self::build_renewal_custom_data( $order, $sub_id );

        // META
        if ( get_option( 'servertrack_meta_enabled', 0 )
            && ! ServerTrack_Dedup::already_sent( $dedup_key, 'meta' )
            && ServerTrack_Consent::is_granted( 'meta', $order_id ) ) {
            $e      = ( new ServerTrack_Event( 'Purchase', $event_id ) )->set_user_data( $user_data )->set_custom_data( $custom_data );
            $result = ServerTrack_Meta::send( $e );
            if ( ( $result['status'] ?? '' ) === 'success' ) {
                ServerTrack_Dedup::mark_string_sent( $dedup_key, 'meta' );
            } else {
                ServerTrack_Retry::maybe_queue( 'meta', $result, ServerTrack_Retry::event_to_args( $e ) );
            }
            ServerTrack_Logger::log( $result['status'] ?? 'error', 'meta', 'Subscription renewal #' . $sub_id, '', $event_id, $order_id, 'Renewal' );
        }

        // TIKTOK
        if ( get_option( 'servertrack_tiktok_enabled', 0 )
            && ! ServerTrack_Dedup::already_sent( $dedup_key, 'tiktok' )
            && ServerTrack_Consent::is_granted( 'tiktok', $order_id ) ) {
            $e      = ( new ServerTrack_Event( 'Purchase', $event_id ) )->set_user_data( $user_data )->set_custom_data( $custom_data );
            $result = ServerTrack_TikTok::send( $e );
            if ( ( $result['status'] ?? '' ) === 'success' ) {
                ServerTrack_Dedup::mark_string_sent( $dedup_key, 'tiktok' );
            } else {
                ServerTrack_Retry::maybe_queue( 'tiktok', $result, ServerTrack_Retry::event_to_args( $e ) );
            }
            ServerTrack_Logger::log( $result['status'] ?? 'error', 'tiktok', 'Subscription renewal #' . $sub_id, '', $event_id, $order_id, 'Renewal' );
        }

        // GOOGLE
        if ( get_option( 'servertrack_google_enabled', 0 )
            && ! ServerTrack_Dedup::already_sent( $dedup_key, 'google' )
            && ServerTrack_Consent::is_granted( 'google', $order_id ) ) {
            $e      = ( new ServerTrack_Event( 'Purchase', $event_id ) )->set_user_data( $user_data )->set_custom_data( $custom_data );
            $result = ServerTrack_Google::send( $e );
            if ( ( $result['status'] ?? '' ) === 'success' ) {
                ServerTrack_Dedup::mark_string_sent( $dedup_key, 'google' );
            } else {
                ServerTrack_Retry::maybe_queue( 'google', $result, ServerTrack_Retry::event_to_args( $e ) );
            }
            ServerTrack_Logger::log( $result['status'] ?? 'error', 'google', 'Subscription renewal #' . $sub_id, '', $event_id, $order_id, 'Renewal' );
        }
    }

    public static function send_cancelled_async( int $sub_id ): void {
        $subscription = wcs_get_subscription( $sub_id );
        if ( ! $subscription ) return;

        // BUG-M1 FIX: options-based string dedup.
        $dedup_key = self::SUBSCRIPTION_CANCEL_DEDUP_KEY . $sub_id;
        $event_id  = self::get_string_event_id( $dedup_key );
        if ( empty( $event_id ) ) {
            $event_id = ServerTrack_Dedup::generate_event_id( $dedup_key );
            self::set_string_event_id( $dedup_key, $event_id );
        }

        $order_id  = $subscription->get_last_order( 'ids' ) ?: 0;
        $user_data = self::build_order_user_data( $subscription );
        $custom    = [
            'currency'        => $subscription->get_currency(),
            'value'           => (float) $subscription->get_total(),
            'content_name'    => 'Subscription Cancelled',
            'content_type'    => 'product',
            'subscription_id' => $sub_id,
        ];

        // META — custom event 'SubscriptionCancelled'
        if ( get_option( 'servertrack_meta_enabled', 0 )
            && ! ServerTrack_Dedup::already_sent( $dedup_key, 'meta' ) ) {
            $e      = ( new ServerTrack_Event( 'SubscriptionCancelled', $event_id ) )->set_user_data( $user_data )->set_custom_data( $custom );
            $result = ServerTrack_Meta::send( $e );
            if ( ( $result['status'] ?? '' ) === 'success' ) ServerTrack_Dedup::mark_string_sent( $dedup_key, 'meta' );
            ServerTrack_Logger::log( $result['status'] ?? 'error', 'meta', 'Sub cancelled #' . $sub_id, '', $event_id, $order_id, 'SubscriptionCancelled' );
        }

        // TIKTOK — PlaceAnOrder with negative value (TikTok has no native cancellation event;
        // negative-value PlaceAnOrder is the documented TikTok fallback for refunds/cancellations).
        if ( get_option( 'servertrack_tiktok_enabled', 0 )
            && ! ServerTrack_Dedup::already_sent( $dedup_key, 'tiktok' ) ) {
            $neg    = array_merge( $custom, [ 'value' => -1 * abs( $custom['value'] ) ] );
            $e      = ( new ServerTrack_Event( 'PlaceAnOrder', $event_id ) )->set_user_data( $user_data )->set_custom_data( $neg );
            $result = ServerTrack_TikTok::send( $e );
            if ( ( $result['status'] ?? '' ) === 'success' ) ServerTrack_Dedup::mark_string_sent( $dedup_key, 'tiktok' );
            ServerTrack_Logger::log( $result['status'] ?? 'error', 'tiktok', 'Sub cancelled #' . $sub_id, '', $event_id, $order_id, 'SubscriptionCancelled' );
        }

        // GOOGLE — refund hit with negative value
        if ( get_option( 'servertrack_google_enabled', 0 )
            && ! ServerTrack_Dedup::already_sent( $dedup_key, 'google' ) ) {
            $neg    = array_merge( $custom, [ 'value' => -1 * abs( $custom['value'] ) ] );
            $e      = ( new ServerTrack_Event( 'refund', $event_id ) )->set_user_data( $user_data )->set_custom_data( $neg );
            $result = ServerTrack_Google::send( $e );
            if ( ( $result['status'] ?? '' ) === 'success' ) ServerTrack_Dedup::mark_string_sent( $dedup_key, 'google' );
            ServerTrack_Logger::log( $result['status'] ?? 'error', 'google', 'Sub cancelled #' . $sub_id, '', $event_id, $order_id, 'SubscriptionCancelled' );
        }
    }

    public static function send_paused_async( int $sub_id ): void {
        if ( ! get_option( 'servertrack_meta_enabled', 0 ) ) return;
        $subscription = wcs_get_subscription( $sub_id );
        if ( ! $subscription ) return;

        // BUG-M1 FIX: options-based string dedup.
        $dedup_key = 'sub_paused_' . $sub_id;
        if ( ServerTrack_Dedup::already_sent( $dedup_key, 'meta' ) ) return;

        $event_id = ServerTrack_Dedup::generate_event_id( $dedup_key );
        self::set_string_event_id( $dedup_key, $event_id );

        $user_data = self::build_order_user_data( $subscription );
        $custom    = [
            'currency'        => $subscription->get_currency(),
            'value'           => (float) $subscription->get_total(),
            'content_name'    => 'Subscription Paused',
            'subscription_id' => $sub_id,
        ];
        $e      = ( new ServerTrack_Event( 'SubscriptionPaused', $event_id ) )->set_user_data( $user_data )->set_custom_data( $custom );
        $result = ServerTrack_Meta::send( $e );
        if ( ( $result['status'] ?? '' ) === 'success' ) ServerTrack_Dedup::mark_string_sent( $dedup_key, 'meta' );
        ServerTrack_Logger::log( $result['status'] ?? 'error', 'meta', 'Sub paused #' . $sub_id, '', $event_id, 0, 'SubscriptionPaused' );
    }

    // ────────────────────────────────────────────────────────────────────────
    // STRING DEDUP EVENT-ID HELPERS
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Retrieve a persisted event ID for a string dedup key.
     *
     * Uses wp_options so string keys are never coerced to int 0.
     *
     * @param string $key  e.g. 'renewal_123_456'
     * @return string  UUID or empty string if not yet stored.
     */
    private static function get_string_event_id( string $key ): string {
        $stored = get_option(
            ServerTrack_Dedup::OPTIONS_PREFIX . sanitize_key( $key . '_event_id' ),
            ''
        );
        return is_string( $stored ) ? $stored : '';
    }

    /**
     * Persist an event ID for a string dedup key.
     *
     * @param string $key      e.g. 'renewal_123_456'
     * @param string $event_id UUID to store.
     */
    private static function set_string_event_id( string $key, string $event_id ): void {
        update_option(
            ServerTrack_Dedup::OPTIONS_PREFIX . sanitize_key( $key . '_event_id' ),
            $event_id,
            false
        );
    }

    // ────────────────────────────────────────────────────────────────────────
    // HELPERS
    // ────────────────────────────────────────────────────────────────────────

    private static function build_order_user_data( WC_Abstract_Order $order ): array {
        $data = [];
        $ip   = method_exists( $order, 'get_customer_ip_address' ) ? $order->get_customer_ip_address() : '';
        if ( $ip ) $data['ip'] = $ip;
        $ua = method_exists( $order, 'get_customer_user_agent' ) ? $order->get_customer_user_agent() : '';
        if ( $ua ) $data['user_agent'] = $ua;
        $email = $order->get_billing_email();
        if ( $email ) $data['email'] = ServerTrack_Hasher::hash_email( $email );
        $phone = $order->get_billing_phone();
        if ( $phone ) $data['phone'] = ServerTrack_Hasher::hash_phone( $phone, '' );
        foreach ( [ 'first_name', 'last_name', 'city', 'state', 'zip', 'country' ] as $field ) {
            $method = 'get_billing_' . $field;
            $val    = method_exists( $order, $method ) ? $order->$method() : '';
            if ( $val ) $data[$field] = ServerTrack_Hasher::hash( $val );
        }
        $customer_id       = (int) ( method_exists( $order, 'get_customer_id' ) ? $order->get_customer_id() : 0 );
        $data['external_id'] = ServerTrack_Identity::get_external_id_for_order( $order );
        return $data;
    }

    private static function build_renewal_custom_data( WC_Order $order, int $sub_id ): array {
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
        return [
            'currency'        => $order->get_currency(),
            'value'           => (float) $order->get_total(),
            'contents'        => $contents,
            'content_ids'     => $content_ids,
            'content_type'    => 'product',
            'order_id'        => $order->get_id(),
            'num_items'       => count( $contents ),
            'content_name'    => 'Subscription Renewal',
            'subscription_id' => $sub_id,
        ];
    }
}
