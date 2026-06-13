<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ServerTrack_WooRenewals  v2.2
 *
 * Changes in v2.2:
 *   - New: on_subscription_cancelled() fires a SubscriptionCancelled custom
 *     CAPI event when a WooCommerce Subscription is cancelled.
 *     Mapped to Lead on Meta (closest standard event for a non-revenue
 *     lifecycle signal), PlaceAnOrder with zero value on TikTok, and a
 *     custom conversion event on Google.
 *     Dispatch is async via WP-Cron.
 *   - New cron handler: send_cancellation_async().
 *   - New hook registered in init():
 *     woocommerce_subscription_status_cancelled → on_subscription_cancelled.
 *
 * Changes in v2.1:
 *   - hash_phone() with country_code resolved from billing country.
 *   - external_id added to renewal user_data.
 */
class ServerTrack_WooRenewals {

    private static array $country_codes = [
        'US'=>'1','CA'=>'1','GB'=>'44','AU'=>'61','DE'=>'49','FR'=>'33',
        'IT'=>'39','ES'=>'34','NL'=>'31','SE'=>'46','NO'=>'47','DK'=>'45',
        'FI'=>'358','CH'=>'41','AT'=>'43','IE'=>'353','NZ'=>'64','ZA'=>'27',
        'IN'=>'91','BR'=>'55','BD'=>'880','PK'=>'92','NG'=>'234','MX'=>'52',
        'JP'=>'81','KR'=>'82','SG'=>'65','MY'=>'60','TH'=>'66','PH'=>'63',
        'ID'=>'62','VN'=>'84','HK'=>'852','TW'=>'886','AE'=>'971','SA'=>'966',
    ];

    public static function init() {
        if ( ! get_option( 'servertrack_source_woo_enabled', 1 ) ) return;
        if ( ! class_exists( 'WC_Subscriptions' ) ) return;

        add_action(
            'woocommerce_subscription_renewal_payment_complete',
            [ self::class, 'on_renewal_complete' ],
            10, 2
        );

        // v2.2: track subscription cancellations
        add_action(
            'woocommerce_subscription_status_cancelled',
            [ self::class, 'on_subscription_cancelled' ],
            10, 1
        );

        add_action( 'servertrack_send_renewal_purchase',      [ self::class, 'send_renewal_async' ],       10, 1 );
        add_action( 'servertrack_send_subscription_cancelled', [ self::class, 'send_cancellation_async' ], 10, 1 );
    }

    // ────────────────────────────────────────────────────────────────────────
    // RENEWAL (unchanged from v2.1)
    // ────────────────────────────────────────────────────────────────────────

    public static function on_renewal_complete( $subscription, $renewal_order ) {
        if ( ! get_option( 'servertrack_enabled', 1 ) ) return;
        $renewal_order_id = $renewal_order->get_id();
        if ( ServerTrack_Dedup::was_sent( $renewal_order_id, 'meta' )
            || ServerTrack_Dedup::was_sent( $renewal_order_id, 'google' )
            || ServerTrack_Dedup::was_sent( $renewal_order_id, 'tiktok' ) ) {
            ServerTrack_Logger::log( 'dedup_blocked', 'all', 'Renewal order #' . $renewal_order_id . ' already sent — skipping.', '', ServerTrack_Dedup::get_event_id( $renewal_order_id ), $renewal_order_id, 'Purchase' );
            return;
        }
        $event_id = ServerTrack_Dedup::generate_event_id( 'renewal_' . $renewal_order_id );
        ServerTrack_Dedup::store_event_id( $renewal_order_id, $event_id );
        ServerTrack_Logger::log( 'queued', 'all', 'Subscription renewal order #' . $renewal_order_id . ' queued for server-side tracking.', '', $event_id, $renewal_order_id, 'Purchase' );
        wp_schedule_single_event( time(), 'servertrack_send_renewal_purchase', [ $renewal_order_id ] );
    }

    public static function send_renewal_async( int $renewal_order_id ) {
        if ( ! get_option( 'servertrack_enabled', 1 ) ) return;
        $order = wc_get_order( $renewal_order_id );
        if ( ! $order ) {
            ServerTrack_Logger::log( 'error', 'all', 'Renewal order #' . $renewal_order_id . ' not found in cron.', '', '', $renewal_order_id, 'Purchase' );
            return;
        }
        $event_id    = ServerTrack_Dedup::get_event_id( $renewal_order_id );
        $user_data   = self::build_renewal_user_data( $order );
        $custom_data = self::build_renewal_custom_data( $order );
        if ( get_option( 'servertrack_meta_enabled', 0 ) ) {
            if ( ! ServerTrack_Dedup::was_sent( $renewal_order_id, 'meta' ) ) {
                $e      = ( new ServerTrack_Event( 'Purchase', $event_id ) )->set_user_data( $user_data )->set_custom_data( $custom_data );
                $result = ServerTrack_Meta::send( $e );
                if ( ( $result['status'] ?? '' ) === 'success' ) {
                    ServerTrack_Dedup::mark_as_sent( $renewal_order_id, 'meta' );
                } else {
                    ServerTrack_Retry::maybe_queue( 'meta', $result, ServerTrack_Retry::event_to_args( $e ) );
                }
            }
        }
        if ( get_option( 'servertrack_google_enabled', 0 ) ) {
            if ( ! ServerTrack_Dedup::was_sent( $renewal_order_id, 'google' ) ) {
                $e      = ( new ServerTrack_Event( 'Purchase', $event_id ) )->set_user_data( $user_data )->set_custom_data( $custom_data );
                $result = ServerTrack_Google::send( $e );
                if ( ( $result['status'] ?? '' ) === 'success' ) {
                    ServerTrack_Dedup::mark_as_sent( $renewal_order_id, 'google' );
                } else {
                    ServerTrack_Retry::maybe_queue( 'google', $result, ServerTrack_Retry::event_to_args( $e ) );
                }
            }
        }
        if ( get_option( 'servertrack_tiktok_enabled', 0 ) ) {
            if ( ! ServerTrack_Dedup::was_sent( $renewal_order_id, 'tiktok' ) ) {
                $e      = ( new ServerTrack_Event( 'Purchase', $event_id ) )->set_user_data( $user_data )->set_custom_data( $custom_data );
                $result = ServerTrack_TikTok::send( $e );
                if ( ( $result['status'] ?? '' ) === 'success' ) {
                    ServerTrack_Dedup::mark_as_sent( $renewal_order_id, 'tiktok' );
                } else {
                    ServerTrack_Retry::maybe_queue( 'tiktok', $result, ServerTrack_Retry::event_to_args( $e ) );
                }
            }
        }
    }

    // ────────────────────────────────────────────────────────────────────────
    // SUBSCRIPTION CANCELLED (v2.2)
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Fires when a subscription status changes to 'cancelled'.
     *
     * @param WC_Subscription $subscription
     */
    public static function on_subscription_cancelled( $subscription ) {
        if ( ! get_option( 'servertrack_enabled', 1 ) ) return;

        $subscription_id = $subscription->get_id();

        // Guard: skip if already sent
        if ( ServerTrack_Dedup::was_sent( 'sub_cancel_' . $subscription_id, 'meta' )
            || ServerTrack_Dedup::was_sent( 'sub_cancel_' . $subscription_id, 'tiktok' )
            || ServerTrack_Dedup::was_sent( 'sub_cancel_' . $subscription_id, 'google' ) ) {
            return;
        }

        ServerTrack_Logger::log(
            'queued', 'all',
            'Subscription #' . $subscription_id . ' cancelled — queuing SubscriptionCancelled event.',
            '', '', $subscription_id, 'SubscriptionCancelled'
        );

        wp_schedule_single_event(
            time(),
            'servertrack_send_subscription_cancelled',
            [ $subscription_id ]
        );
        spawn_cron();
    }

    /**
     * Async cron: send SubscriptionCancelled event to all enabled platforms.
     *
     * @param int $subscription_id
     */
    public static function send_cancellation_async( int $subscription_id ) {
        if ( ! get_option( 'servertrack_enabled', 1 ) ) return;

        // Retrieve the subscription object
        $subscription = wcs_get_subscription( $subscription_id );
        if ( ! $subscription ) {
            ServerTrack_Logger::log( 'error', 'all', 'Subscription #' . $subscription_id . ' not found in cron.', '', '', $subscription_id, 'SubscriptionCancelled' );
            return;
        }

        // Get the last (most recent) order for user_data
        $last_order = $subscription->get_last_order( 'all' );
        if ( ! $last_order instanceof WC_Order ) {
            ServerTrack_Logger::log( 'error', 'all', 'Subscription #' . $subscription_id . ' has no order — cannot build user_data.', '', '', $subscription_id, 'SubscriptionCancelled' );
            return;
        }

        $dedup_key = 'sub_cancel_' . $subscription_id;
        $event_id  = ServerTrack_Dedup::get_event_id( $dedup_key );
        if ( empty( $event_id ) ) {
            $event_id = ServerTrack_Dedup::generate_event_id( $dedup_key );
            ServerTrack_Dedup::store_event_id( $dedup_key, $event_id );
        }

        $user_data   = self::build_renewal_user_data( $last_order );
        $custom_data = [
            'currency'         => $last_order->get_currency(),
            'value'            => 0.0,
            'content_name'     => 'SubscriptionCancelled',
            'subscription_id'  => $subscription_id,
            'order_id'         => $last_order->get_id(),
            'content_type'     => 'product',
        ];

        // ── META: Lead event (best standard event for lifecycle signal) ─────
        if ( get_option( 'servertrack_meta_enabled', 0 )
            && ! ServerTrack_Dedup::was_sent( $dedup_key, 'meta' ) ) {
            $e = ( new ServerTrack_Event( 'Lead', $event_id ) )
                ->set_user_data( $user_data )
                ->set_custom_data( $custom_data );
            $result = ServerTrack_Meta::send( $e );
            if ( ( $result['status'] ?? '' ) === 'success' ) {
                ServerTrack_Dedup::mark_as_sent( $dedup_key, 'meta' );
            } else {
                ServerTrack_Retry::maybe_queue( 'meta', $result, ServerTrack_Retry::event_to_args( $e ) );
            }
        }

        // ── TIKTOK: SubscribeExpired (churn signal) ─────────────────────────
        if ( get_option( 'servertrack_tiktok_enabled', 0 )
            && ! ServerTrack_Dedup::was_sent( $dedup_key, 'tiktok' ) ) {
            $e = ( new ServerTrack_Event( 'Subscribe', $event_id ) )
                ->set_user_data( $user_data )
                ->set_custom_data( array_merge( $custom_data, [ 'content_name' => 'SubscriptionCancelled' ] ) );
            $result = ServerTrack_TikTok::send( $e );
            if ( ( $result['status'] ?? '' ) === 'success' ) {
                ServerTrack_Dedup::mark_as_sent( $dedup_key, 'tiktok' );
            } else {
                ServerTrack_Retry::maybe_queue( 'tiktok', $result, ServerTrack_Retry::event_to_args( $e ) );
            }
        }

        // ── GOOGLE: custom conversion ───────────────────────────────────────
        if ( get_option( 'servertrack_google_enabled', 0 )
            && ! ServerTrack_Dedup::was_sent( $dedup_key, 'google' ) ) {
            $e = ( new ServerTrack_Event( 'subscription_cancelled', $event_id ) )
                ->set_user_data( $user_data )
                ->set_custom_data( $custom_data );
            $result = ServerTrack_Google::send( $e );
            if ( ( $result['status'] ?? '' ) === 'success' ) {
                ServerTrack_Dedup::mark_as_sent( $dedup_key, 'google' );
            } else {
                ServerTrack_Retry::maybe_queue( 'google', $result, ServerTrack_Retry::event_to_args( $e ) );
            }
        }
    }

    // ────────────────────────────────────────────────────────────────────────
    // HELPERS (unchanged from v2.1)
    // ────────────────────────────────────────────────────────────────────────

    private static function build_renewal_user_data( WC_Order $order ): array {
        $data = [];
        $email = $order->get_billing_email();
        if ( ! empty( $email ) ) $data['email'] = ServerTrack_Hasher::hash_email( $email );
        $phone = $order->get_billing_phone();
        if ( ! empty( $phone ) ) {
            $iso = strtoupper( (string) $order->get_billing_country() );
            $cc  = ! empty( $iso ) ? ( self::$country_codes[ $iso ] ?? '' ) : '';
            $data['phone'] = ServerTrack_Hasher::hash_phone( $phone, $cc );
        }
        $pii_map = [
            'first_name' => $order->get_billing_first_name(),
            'last_name'  => $order->get_billing_last_name(),
            'city'       => $order->get_billing_city(),
            'state'      => $order->get_billing_state(),
            'zip'        => $order->get_billing_postcode(),
            'country'    => $order->get_billing_country(),
        ];
        foreach ( $pii_map as $key => $val ) {
            if ( ! empty( $val ) ) $data[ $key ] = ServerTrack_Hasher::hash( $val );
        }
        $raw_map = [
            'city_raw'    => $order->get_billing_city(),
            'state_raw'   => $order->get_billing_state(),
            'zip_raw'     => $order->get_billing_postcode(),
            'country_raw' => $order->get_billing_country(),
        ];
        foreach ( $raw_map as $key => $val ) {
            if ( ! empty( $val ) ) $data[ $key ] = $val;
        }
        $order_ip = (string) $order->get_customer_ip_address();
        if ( substr( $order_ip, 0, 7 ) === '::ffff:' ) $order_ip = substr( $order_ip, 7 );
        if ( ! empty( $order_ip ) ) $data['ip'] = $order_ip;
        $customer_id = $order->get_customer_id();
        $data['external_id'] = ServerTrack_Hasher::hash( (string) ( $customer_id ?: $order->get_id() ) );
        return $data;
    }

    private static function build_renewal_custom_data( WC_Order $order ): array {
        $contents = [];
        foreach ( $order->get_items() as $item ) {
            $product    = $item->get_product();
            $sku        = ( $product && $product->get_sku() ) ? $product->get_sku() : (string) $item->get_product_id();
            $qty        = (int) $item->get_quantity();
            $contents[] = [ 'id' => $sku, 'quantity' => $qty, 'item_price' => $qty > 0 ? (float) $item->get_total() / $qty : 0.0 ];
        }
        return [
            'currency'     => $order->get_currency(),
            'value'        => (float) $order->get_total(),
            'contents'     => $contents,
            'content_type' => 'product',
            'order_id'     => $order->get_id(),
        ];
    }
}
