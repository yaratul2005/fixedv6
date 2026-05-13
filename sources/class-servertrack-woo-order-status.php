<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ServerTrack_WooOrderStatus  v1.0
 *
 * Sends server-side CAPI events for WooCommerce order lifecycle status
 * transitions that carry audience-building value:
 *
 *   on-hold   → payment pending / bank-transfer waiting
 *                Audience: near-converters who need a nudge.
 *   failed    → payment failed at checkout
 *                Audience: high-intent users; retarget with friction-reducing offers.
 *   cancelled → order cancelled (one-off, not subscription — see WooRenewals for that)
 *                Audience: lost customers for win-back campaigns.
 *
 * Platform event mapping:
 *   Meta   → Lead  (standard event, closest to a lifecycle signal without revenue)
 *   TikTok → Contact (on-hold) / SubmitForm (failed/cancelled)
 *   Google → generate_lead (custom conversion)
 *
 * Dedup strategy:
 *   Uses Dedup::exists() / Dedup::set() (options-based, string-key safe).
 *   Key format: order_status_{status}_{order_id}_{platform}
 *   Each platform deduplicated independently so a failure on one platform
 *   does not block the others from retrying.
 *
 * Async:
 *   All sends are dispatched via WP-Cron (wp_schedule_single_event + spawn_cron)
 *   to avoid blocking the admin status-change request.
 *
 * @package ServerTrack
 * @since   5.0.0
 */
class ServerTrack_WooOrderStatus {

    /** Statuses we track. Maps status slug => human label. */
    const TRACKED_STATUSES = [
        'on-hold'   => 'OrderOnHold',
        'failed'    => 'OrderFailed',
        'cancelled' => 'OrderCancelled',
    ];

    /** Meta event name for all three statuses (lifecycle signal). */
    const META_EVENT = 'Lead';

    /** TikTok event map per status. */
    const TIKTOK_EVENT_MAP = [
        'on-hold'   => 'Contact',
        'failed'    => 'SubmitForm',
        'cancelled' => 'SubmitForm',
    ];

    /** Google event map per status. */
    const GOOGLE_EVENT_MAP = [
        'on-hold'   => 'generate_lead',
        'failed'    => 'generate_lead',
        'cancelled' => 'generate_lead',
    ];

    public static function init(): void {
        if ( ! get_option( 'servertrack_source_order_status_enabled', 1 ) ) {
            return;
        }

        foreach ( array_keys( self::TRACKED_STATUSES ) as $status ) {
            add_action(
                'woocommerce_order_status_' . $status,
                [ self::class, 'on_order_status_change' ],
                10, 2
            );
        }

        add_action(
            'servertrack_send_order_status_event',
            [ self::class, 'send_status_event_async' ],
            10, 2
        );
    }

    // ── Hook handler ─────────────────────────────────────────────────────────

    /**
     * Fired by woocommerce_order_status_{status}.
     *
     * @param int      $order_id
     * @param WC_Order $order
     */
    public static function on_order_status_change( int $order_id, WC_Order $order ): void {
        if ( ! get_option( 'servertrack_enabled', 1 ) ) {
            return;
        }

        // Determine status from current hook name
        $current_filter = current_filter();
        $status         = str_replace( 'woocommerce_order_status_', '', $current_filter );

        if ( ! isset( self::TRACKED_STATUSES[ $status ] ) ) {
            return;
        }

        // Skip subscription renewal orders — handled by WooRenewals
        if ( $order->get_meta( '_subscription_renewal' ) ) {
            return;
        }

        $base_key = 'order_status_' . $status . '_' . $order_id;

        // Skip if already queued or sent for all enabled platforms
        $meta_sent   = ! get_option( 'servertrack_meta_enabled', 0 )   || ServerTrack_Dedup::exists( $base_key . '_meta' );
        $tiktok_sent = ! get_option( 'servertrack_tiktok_enabled', 0 ) || ServerTrack_Dedup::exists( $base_key . '_tiktok' );
        $google_sent = ! get_option( 'servertrack_google_enabled', 0 ) || ServerTrack_Dedup::exists( $base_key . '_google' );

        if ( $meta_sent && $tiktok_sent && $google_sent ) {
            ServerTrack_Logger::log(
                'dedup_blocked', 'all',
                'OrderStatus ' . $status . ' #' . $order_id . ' already sent to all platforms.',
                '', '', $order_id, self::TRACKED_STATUSES[ $status ]
            );
            return;
        }

        ServerTrack_Logger::log(
            'queued', 'all',
            'Order #' . $order_id . ' status → ' . $status . '. Queuing CAPI event.',
            '', '', $order_id, self::TRACKED_STATUSES[ $status ]
        );

        wp_schedule_single_event(
            time(),
            'servertrack_send_order_status_event',
            [ $order_id, $status ]
        );
        spawn_cron();
    }

    // ── Async cron handler ────────────────────────────────────────────────────

    /**
     * Send the order-status CAPI event. Called by WP-Cron.
     *
     * @param int    $order_id
     * @param string $status   'on-hold' | 'failed' | 'cancelled'
     */
    public static function send_status_event_async( int $order_id, string $status ): void {
        if ( ! get_option( 'servertrack_enabled', 1 ) ) {
            return;
        }
        if ( ! isset( self::TRACKED_STATUSES[ $status ] ) ) {
            return;
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            ServerTrack_Logger::log(
                'error', 'all',
                'send_status_event_async: order #' . $order_id . ' not found.',
                '', '', $order_id, self::TRACKED_STATUSES[ $status ]
            );
            return;
        }

        $base_key    = 'order_status_' . $status . '_' . $order_id;
        $event_label = self::TRACKED_STATUSES[ $status ];
        $event_id    = ServerTrack_Dedup::generate_event_id( $base_key );
        $user_data   = self::build_user_data( $order );
        $custom_data = [
            'currency'     => $order->get_currency(),
            'value'        => (float) $order->get_total(),
            'content_name' => $event_label,
            'order_id'     => $order_id,
            'order_status' => $status,
            'content_type' => 'product',
            '_dedup_key'   => $base_key,  // carried into retry queue (BUG-08 pattern)
        ];
        $emq = ServerTrack_MatchQuality::score( $user_data );

        // ── META ─────────────────────────────────────────────────────────────
        if ( get_option( 'servertrack_meta_enabled', 0 )
            && ! ServerTrack_Dedup::exists( $base_key . '_meta' )
            && ServerTrack_Consent::is_granted( 'meta', $order_id ) ) {
            $e = ( new ServerTrack_Event( self::META_EVENT, $event_id ) )
                ->set_user_data( $user_data )
                ->set_custom_data( $custom_data );
            $r = ServerTrack_Meta::send( $e );
            if ( ( $r['status'] ?? '' ) === 'success' ) {
                ServerTrack_Dedup::set( $base_key . '_meta' );
            } else {
                ServerTrack_Retry::maybe_queue( 'meta', $r, ServerTrack_Retry::event_to_args( $e ) );
            }
            ServerTrack_Logger::log( $r['status'] ?? 'error', 'meta', $event_label . ' #' . $order_id, '', $event_id, $order_id, $event_label, $emq );
        }

        // ── TIKTOK ───────────────────────────────────────────────────────────
        if ( get_option( 'servertrack_tiktok_enabled', 0 )
            && ! ServerTrack_Dedup::exists( $base_key . '_tiktok' )
            && ServerTrack_Consent::is_granted( 'tiktok', $order_id ) ) {
            $tiktok_event = self::TIKTOK_EVENT_MAP[ $status ] ?? 'Contact';
            $e = ( new ServerTrack_Event( $tiktok_event, $event_id ) )
                ->set_user_data( $user_data )
                ->set_custom_data( $custom_data );
            $r = ServerTrack_TikTok::send( $e );
            if ( ( $r['status'] ?? '' ) === 'success' ) {
                ServerTrack_Dedup::set( $base_key . '_tiktok' );
            } else {
                ServerTrack_Retry::maybe_queue( 'tiktok', $r, ServerTrack_Retry::event_to_args( $e ) );
            }
            ServerTrack_Logger::log( $r['status'] ?? 'error', 'tiktok', $event_label . ' #' . $order_id, '', $event_id, $order_id, $event_label, $emq );
        }

        // ── GOOGLE ───────────────────────────────────────────────────────────
        if ( get_option( 'servertrack_google_enabled', 0 )
            && ! ServerTrack_Dedup::exists( $base_key . '_google' )
            && ServerTrack_Consent::is_granted( 'google', $order_id ) ) {
            $google_event = self::GOOGLE_EVENT_MAP[ $status ] ?? 'generate_lead';
            $e = ( new ServerTrack_Event( $google_event, $event_id ) )
                ->set_user_data( $user_data )
                ->set_custom_data( $custom_data );
            $r = ServerTrack_Google::send( $e );
            if ( ( $r['status'] ?? '' ) === 'success' ) {
                ServerTrack_Dedup::set( $base_key . '_google' );
            } else {
                ServerTrack_Retry::maybe_queue( 'google', $r, ServerTrack_Retry::event_to_args( $e ) );
            }
            ServerTrack_Logger::log( $r['status'] ?? 'error', 'google', $event_label . ' #' . $order_id, '', $event_id, $order_id, $event_label, $emq );
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Build hashed user_data from a WC order.
     * Matches the pattern used by WooRenewals::build_renewal_user_data().
     *
     * @param WC_Order $order
     * @return array
     */
    private static function build_user_data( WC_Order $order ): array {
        static $cc_map = [
            'US'=>'1','CA'=>'1','GB'=>'44','AU'=>'61','DE'=>'49','FR'=>'33',
            'IT'=>'39','ES'=>'34','NL'=>'31','SE'=>'46','NO'=>'47','DK'=>'45',
            'FI'=>'358','CH'=>'41','AT'=>'43','IE'=>'353','NZ'=>'64','ZA'=>'27',
            'IN'=>'91','BR'=>'55','BD'=>'880','PK'=>'92','NG'=>'234','MX'=>'52',
            'JP'=>'81','KR'=>'82','SG'=>'65','MY'=>'60','TH'=>'66','PH'=>'63',
            'ID'=>'62','VN'=>'84','HK'=>'852','TW'=>'886','AE'=>'971','SA'=>'966',
        ];

        $data = [];

        // IP
        $ip = (string) $order->get_customer_ip_address();
        if ( substr( $ip, 0, 7 ) === '::ffff:' ) $ip = substr( $ip, 7 );
        if ( $ip ) $data['ip'] = $ip;

        // User-Agent
        $ua = $order->get_customer_user_agent();
        if ( $ua ) $data['user_agent'] = $ua;

        // Email
        $email = $order->get_billing_email();
        if ( $email ) $data['email'] = ServerTrack_Hasher::hash_email( $email );

        // Phone
        $phone   = $order->get_billing_phone();
        $country = strtoupper( (string) $order->get_billing_country() );
        if ( $phone ) {
            $cc = $cc_map[ $country ] ?? '';
            $data['phone'] = ServerTrack_Hasher::hash_phone( $phone, $cc );
        }

        // PII fields
        foreach ( [
            'first_name' => $order->get_billing_first_name(),
            'last_name'  => $order->get_billing_last_name(),
            'city'       => $order->get_billing_city(),
            'state'      => $order->get_billing_state(),
            'zip'        => $order->get_billing_postcode(),
            'country'    => $order->get_billing_country(),
        ] as $key => $val ) {
            if ( $val ) $data[ $key ] = ServerTrack_Hasher::hash( (string) $val );
        }

        // External ID — prefer stored stitched value (H-4 pattern)
        $stored_ext = (string) $order->get_meta( '_servertrack_external_id' );
        $data['external_id'] = ! empty( $stored_ext )
            ? $stored_ext
            : ServerTrack_Identity::get_external_id_for_order( $order );

        return $data;
    }
}
