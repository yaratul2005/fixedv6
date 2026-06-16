<?php
/**
 * ServerTrack — WP-CLI Commands (Feature #10)
 *
 * Provides developer-friendly CLI commands for testing, debugging, and
 * managing ServerTrack from the server terminal.
 *
 * Available commands:
 *   wp servertrack status              — Show plugin config and platform status
 *   wp servertrack log [--limit=50]    — Display recent event log entries
 *   wp servertrack log clear           — Clear all log entries
 *   wp servertrack test-purchase <id>  — Re-fire a purchase event for an order
 *   wp servertrack retry               — Process the retry queue now
 *   wp servertrack emq <order_id>      — Score an order's user_data
 *   wp servertrack ltv <user_id>       — Show customer LTV stats
 *   wp servertrack webhook test <url>  — Send a test webhook to a URL
 *
 * @package ServerTrack
 * @since   6.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
    return;
}

class ServerTrack_CLI {

    /**
     * Show current plugin configuration and platform status.
     *
     * ## EXAMPLES
     *
     *   wp servertrack status
     *
     * @when after_wp_load
     */
    public function status(): void {
        WP_CLI::line( '' );
        WP_CLI::line( '=== ServerTrack ' . SERVERTRACK_VERSION . ' ===' );
        WP_CLI::line( '' );

        $rows = [
            [ 'Setting', 'Value' ],
        ];

        $rows[] = [ 'Plugin enabled',     get_option( 'servertrack_enabled' ) ? 'Yes' : 'No' ];
        $rows[] = [ 'Debug mode',          get_option( 'servertrack_debug_mode' ) ? 'ON' : 'off' ];
        $rows[] = [ 'Meta pixel ID',       get_option( 'servertrack_meta_pixel_id', '—' ) ?: '—' ];
        $rows[] = [ 'Meta access token',   get_option( 'servertrack_meta_access_token' ) ? '*** set ***' : 'NOT SET' ];
        $rows[] = [ 'TikTok pixel ID',     get_option( 'servertrack_tiktok_pixel_id', '—' ) ?: '—' ];
        $rows[] = [ 'TikTok access token', get_option( 'servertrack_tiktok_access_token' ) ? '*** set ***' : 'NOT SET' ];
        $rows[] = [ 'Google MP secret',    get_option( 'servertrack_google_api_secret' ) ? '*** set ***' : 'NOT SET' ];
        $rows[] = [ 'Webhook enabled',     get_option( 'servertrack_webhook_enabled' ) ? 'Yes' : 'No' ];
        $rows[] = [ 'Webhook URL',         get_option( 'servertrack_webhook_url', '—' ) ?: '—' ];

        $log   = (array) get_option( 'servertrack_debug_log', [] );
        $retry = (array) get_option( 'servertrack_retry_queue', [] );
        $rows[] = [ 'Log entries', count( $log ) ];
        $rows[] = [ 'Retry queue', count( $retry ) . ' item(s)' ];

        WP_CLI\Utils\format_items( 'table', array_slice( $rows, 1 ), [ 'Setting', 'Value' ] );
    }

    /**
     * Display recent event log entries.
     *
     * ## OPTIONS
     *
     * [--limit=<n>]
     * : Number of entries to show. Default 50.
     *
     * [--platform=<platform>]
     * : Filter by platform (meta, tiktok, google, webhook).
     *
     * [--status=<status>]
     * : Filter by status (success, error).
     *
     * ## EXAMPLES
     *
     *   wp servertrack log
     *   wp servertrack log --limit=20 --platform=meta --status=error
     *
     * @subcommand log
     * @when after_wp_load
     */
    public function log( array $args, array $assoc_args ): void {
        $sub = $args[0] ?? '';
        if ( $sub === 'clear' ) {
            update_option( 'servertrack_debug_log', [] );
            WP_CLI::success( 'Log cleared.' );
            return;
        }

        $limit    = (int) ( $assoc_args['limit']    ?? 50 );
        $platform = $assoc_args['platform'] ?? '';
        $status   = $assoc_args['status']   ?? '';

        $log = array_reverse( (array) get_option( 'servertrack_debug_log', [] ) );

        if ( $platform ) {
            $log = array_filter( $log, fn( $e ) => ( $e['platform'] ?? '' ) === $platform );
        }
        if ( $status ) {
            $log = array_filter( $log, fn( $e ) => ( $e['status'] ?? '' ) === $status );
        }

        $log = array_slice( array_values( $log ), 0, $limit );

        if ( empty( $log ) ) {
            WP_CLI::line( 'No log entries found.' );
            return;
        }

        // FIX #6: Logger v2.0 stores 'timestamp', not 'time'
        $rows = array_map( function ( $entry ) {
            return [
                'time'     => $entry['timestamp']  ?? '',  // fixed: was $entry['time']
                'platform' => $entry['platform']   ?? '',
                'event'    => $entry['event_type'] ?? '',
                'order_id' => $entry['order_id']   ?? '',
                'status'   => $entry['status']     ?? '',
                'emq'      => isset( $entry['emq_score'] )
                    ? $entry['emq_score'] . ' (' . ( $entry['emq_grade'] ?? '' ) . ')'
                    : '—',
            ];
        }, $log );

        WP_CLI\Utils\format_items( 'table', $rows, [ 'time', 'platform', 'event', 'order_id', 'status', 'emq' ] );
    }

    /**
     * Re-fire the Purchase CAPI event for an existing order.
     *
     * Resets all ServerTrack dedup flags for the order so the event passes
     * through all guards as if it had never been sent before.
     * Does NOT remove any WooCommerce order data.
     *
     * ## OPTIONS
     *
     * <order_id>
     * : The WooCommerce order ID to test.
     *
     * ## EXAMPLES
     *
     *   wp servertrack test-purchase 123
     *
     * @subcommand test-purchase
     * @when after_wp_load
     */
    public function test_purchase( array $args ): void {
        $order_id = (int) ( $args[0] ?? 0 );
        if ( ! $order_id ) {
            WP_CLI::error( 'Please provide an order ID.' );
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            WP_CLI::error( "Order #{$order_id} not found." );
        }

        WP_CLI::line( "Re-firing Purchase event for order #{$order_id}..." );

        // FIX #1: ServerTrack_Dedup::delete() never existed.
        // Use reset_for_order() which correctly clears _servertrack_event_id
        // and _servertrack_server_sent via HPOS-aware delete_meta().
        ServerTrack_Dedup::reset_for_order( $order_id );

        // Pass 'thankyou' trigger so Meta + TikTok blocks execute
        // (the Google block runs regardless of trigger).
        do_action( 'servertrack_send_woo_purchase', $order_id, 'thankyou' );

        WP_CLI::success( 'Done. Check log: wp servertrack log --limit=5' );
    }

    /**
     * Process the retry queue immediately.
     *
     * ## EXAMPLES
     *
     *   wp servertrack retry
     *
     * @when after_wp_load
     */
    public function retry(): void {
        $queue = (array) get_option( 'servertrack_retry_queue', [] );
        $count = count( $queue );

        if ( $count === 0 ) {
            WP_CLI::line( 'Retry queue is empty.' );
            return;
        }

        WP_CLI::line( "Processing {$count} item(s) in retry queue..." );
        do_action( 'servertrack_process_retry_queue' );
        WP_CLI::success( 'Done.' );
    }

    /**
     * Score the user_data for a given order using the EMQ scorer.
     *
     * ## OPTIONS
     *
     * <order_id>
     * : WooCommerce order ID.
     *
     * ## EXAMPLES
     *
     *   wp servertrack emq 123
     *
     * @when after_wp_load
     */
    public function emq( array $args ): void {
        $order_id = (int) ( $args[0] ?? 0 );
        if ( ! $order_id ) {
            WP_CLI::error( 'Please provide an order ID.' );
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            WP_CLI::error( "Order #{$order_id} not found." );
        }

        $email = $order->get_billing_email();
        $phone = preg_replace( '/[^0-9+]/', '', $order->get_billing_phone() );
        $fbc   = $order->get_meta( '_servertrack_fbc' );
        $fbp   = $order->get_meta( '_servertrack_fbp' );

        $user_data = array_filter( [
            'em'  => $email ? ServerTrack_Hasher::hash( strtolower( trim( $email ) ) ) : '',
            'ph'  => $phone ? ServerTrack_Hasher::hash( $phone ) : '',
            'fbc' => $fbc ?: '',
            'fbp' => $fbp ?: '',
            'fn'  => $order->get_billing_first_name() ? ServerTrack_Hasher::hash_name( $order->get_billing_first_name() ) : '',
            'ln'  => $order->get_billing_last_name()  ? ServerTrack_Hasher::hash_name( $order->get_billing_last_name() )  : '',
            'zp'  => $order->get_billing_postcode()   ? ServerTrack_Hasher::hash_zip( $order->get_billing_postcode() )   : '',
            'ct'  => $order->get_billing_city()       ? ServerTrack_Hasher::hash_city( $order->get_billing_city() )       : '',
        ] );

        $emq = ServerTrack_MatchQuality::score( $user_data );

        WP_CLI::line( '' );
        WP_CLI::line( "EMQ Score for Order #{$order_id}" );
        WP_CLI::line( '─────────────────────────────' );
        WP_CLI::line( 'Score : ' . ( $emq['score'] ?? '?' ) . ' / 10' );
        WP_CLI::line( 'Grade : ' . strtoupper( $emq['grade'] ?? '?' ) );
        WP_CLI::line( '' );

        $rows = [];
        foreach ( ( $emq['signals'] ?? [] ) as $signal => $val ) {
            $rows[] = [ 'signal' => $signal, 'present' => $val ? 'yes' : 'no' ];
        }
        if ( $rows ) {
            WP_CLI\Utils\format_items( 'table', $rows, [ 'signal', 'present' ] );
        }
    }

    /**
     * Show Customer LTV stats.
     *
     * ## OPTIONS
     *
     * <user_id>
     * : WordPress user ID.
     *
     * ## EXAMPLES
     *
     *   wp servertrack ltv 42
     *
     * @when after_wp_load
     */
    public function ltv( array $args ): void {
        $user_id = (int) ( $args[0] ?? 0 );
        if ( ! $user_id ) {
            WP_CLI::error( 'Please provide a user ID.' );
        }

        if ( ! class_exists( 'ServerTrack_LTV' ) ) {
            WP_CLI::error( 'LTV class not loaded.' );
        }

        $stats = ServerTrack_LTV::get_customer_stats( $user_id );

        if ( empty( $stats ) ) {
            WP_CLI::line( "No orders found for user #{$user_id}." );
            return;
        }

        WP_CLI::line( '' );
        WP_CLI::line( "LTV Stats for User #{$user_id}" );
        WP_CLI::line( '─────────────────────────────' );
        foreach ( $stats as $key => $val ) {
            WP_CLI::line( str_pad( $key, 20 ) . ': ' . $val );
        }
    }

    /**
     * Send a test webhook.
     *
     * ## OPTIONS
     *
     * <url>
     * : The webhook URL to test.
     *
     * ## EXAMPLES
     *
     *   wp servertrack webhook test https://example.com/hook
     *
     * @subcommand webhook
     * @when after_wp_load
     */
    public function webhook( array $args ): void {
        $sub = $args[0] ?? '';
        $url = $args[1] ?? '';

        if ( $sub !== 'test' || ! $url ) {
            WP_CLI::error( 'Usage: wp servertrack webhook test <url>' );
        }

        WP_CLI::line( "Sending test webhook to: {$url}" );

        $result = ServerTrack_Webhook::send_test( $url );

        if ( $result['success'] ) {
            WP_CLI::success( "Webhook delivered! HTTP {$result['http_code']}" );
        } else {
            WP_CLI::error( "Failed: HTTP {$result['http_code']} — {$result['message']}" );
        }
    }
}

// Register all subcommands
WP_CLI::add_command( 'servertrack', 'ServerTrack_CLI' );
