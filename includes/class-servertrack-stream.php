<?php
if ( ! defined( 'ABSPATH' ) ) {
    die();
}


/**
 * ServerTrack_Stream
 *
 * Module 6: Real-Time Debug Console + Payload Inspector
 * Uses Server-Sent Events (SSE) to push live CAPI payloads directly to the admin dashboard.
 * Maintains a circular buffer of the last 200 events in a transient for immediate inspection.
 */
class ServerTrack_Stream {

    const STREAM_OPTION = 'servertrack_live_stream_buffer';
    const MAX_EVENTS = 200;

    public static function init(): void {
        add_action( 'rest_api_init', [ self::class, 'register_routes' ] );
        // Hook into event logging to populate the stream buffer
        add_action( 'servertrack_event_dispatched', [ self::class, 'push_to_buffer' ], 10, 3 );
    }

    public static function register_routes(): void {
        // SSE Endpoint
        register_rest_route( 'servertrack/v1', '/stream', [
            'methods'             => 'GET',
            'callback'            => [ self::class, 'handle_sse_connection' ],
            'permission_callback' => function() { return current_user_can( 'manage_options' ); },
        ] );

        // Test Fire Endpoint
        register_rest_route( 'servertrack/v1', '/test-fire', [
            'methods'             => 'POST',
            'callback'            => [ self::class, 'handle_test_fire' ],
            'permission_callback' => function() { return current_user_can( 'manage_options' ); },
        ] );
    }

    public static function push_to_buffer( string $platform, ServerTrack_Event $event, array $response ): void {
        $entry = [
            'time'       => time(),
            'platform'   => $platform,
            'event_name' => $event->event_name,
            'event_id'   => $event->event_id,
            'payload'    => $event->to_array(),
            'response'   => $response,
        ];

        $buffer = get_option( self::STREAM_OPTION, [] );
        if ( ! is_array( $buffer ) ) $buffer = [];

        array_unshift( $buffer, $entry );

        if ( count( $buffer ) > self::MAX_EVENTS ) {
            $buffer = array_slice( $buffer, 0, self::MAX_EVENTS );
        }

        update_option( self::STREAM_OPTION, $buffer, false );
    }

    public static function handle_sse_connection( WP_REST_Request $request ) {
        if ( function_exists( 'header_remove' ) ) {
            header_remove( 'X-Powered-By' );
        }

        header( 'Content-Type: text/event-stream' );
        header( 'Cache-Control: no-cache' );
        header( 'Connection: keep-alive' );
        header( 'X-Accel-Buffering: no' ); // Nginx

        // Disable time limit for the long polling SSE script
        if ( function_exists( 'set_time_limit' ) ) {
            set_time_limit( 0 );
        }

        // Output existing buffer first
        $buffer = get_option( self::STREAM_OPTION, [] );
        if ( is_array( $buffer ) ) {
            foreach ( array_reverse( $buffer ) as $entry ) {
                echo "data: " . wp_json_encode( $entry ) . "\n\n";
            }
        }
        ob_flush();
        flush();

        $last_count = count( $buffer );
        $start_time = time();

        // Simple poll loop for new events (running for 30s to avoid hanging forever)
        while ( ( time() - $start_time ) < 30 ) {
            // Bypass WP object cache to ensure we get fresh DB values across concurrent requests
            wp_cache_delete( self::STREAM_OPTION, 'options' );
            $current_buffer = get_option( self::STREAM_OPTION, [] );
            $current_count = is_array( $current_buffer ) ? count( $current_buffer ) : 0;

            if ( $current_count > 0 && $current_buffer !== $buffer ) {
                // Determine new events
                $new_events = array_slice( $current_buffer, 0, max( 0, $current_count - $last_count ) );

                foreach ( array_reverse( $new_events ) as $entry ) {
                    echo "data: " . wp_json_encode( $entry ) . "\n\n";
                }

                $buffer = $current_buffer;
                $last_count = $current_count;

                ob_flush();
                flush();
            }

            // Connection aborted by client
            if ( connection_aborted() ) {
                break;
            }

            sleep( 2 ); // Poll every 2 seconds
        }

        die(); // Important for SSE
    }

    public static function handle_test_fire( WP_REST_Request $request ) {
        $event_name = sanitize_text_field( $request->get_param( 'event_name' ) ?: 'Purchase' );
        $event_id   = ServerTrack_Dedup::generate_event_id( 'test_' . time() );

        $event = new ServerTrack_Event( $event_name, $event_id );
        $event->set_user_data( [
            'email' => hash( 'sha256', 'test@example.com' ),
            'ip'    => ServerTrack_Enrichment::get_client_ip(),
        ] );
        $event->set_custom_data( [
            'value'        => 99.99,
            'currency'     => 'USD',
            'content_type' => 'product',
            'order_id'     => 'TEST-' . wp_rand( 1000, 9999 ),
        ] );

        // Force 'test_event_code' for Meta if testing
        if ( get_option( 'servertrack_meta_enabled' ) ) {
            $event->set_custom_data( array_merge( $event->custom_data, [ 'test_event_code' => 'TEST1234' ] ) );
        }

        // Dispatch logic (simulated by calling ServerTrack_Core or similar)
        // Since we want to capture the response immediately and push to buffer:
        $results = [];
        foreach ( [ 'meta', 'tiktok', 'google' ] as $platform ) {
            if ( get_option( "servertrack_{$platform}_enabled" ) ) {
                $class_name = 'ServerTrack_' . ucfirst( $platform );
                if ( class_exists( $class_name ) && method_exists( $class_name, 'send' ) ) {
                    $results[ $platform ] = call_user_func( [ $class_name, 'send' ], $event );
                    self::push_to_buffer( $platform, $event, $results[ $platform ] );
                }
            }
        }

        return new WP_REST_Response( [ 'status' => 'success', 'results' => $results ], 200 );
    }
}