<?php
if ( ! defined( 'ABSPATH' ) ) {
    die();
}


/**
 * ServerTrack_Proxy
 *
 * Module 2: First-Party Pixel Proxy
 * Accepts raw browser pixel payloads via a local REST endpoint to circumvent ad blockers.
 * Enriches the payload with real server-side IP and user agent, then fires to the platform CAPI.
 * Deduplicates against any existing server-side events.
 */
class ServerTrack_Proxy {

    public static function init(): void {
        add_action( 'rest_api_init', [ self::class, 'register_routes' ] );
    }

    public static function register_routes(): void {
        register_rest_route( 'servertrack/v1', '/pixel/(?P<platform>[a-zA-Z0-9-]+)', [
            'methods'             => 'POST',
            'callback'            => [ self::class, 'handle_pixel_payload' ],
            'permission_callback' => '__return_true', // Public endpoint
            'args'                => [
                'platform' => [
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ] );
    }

    public static function handle_pixel_payload( WP_REST_Request $request ) {
        if ( ! get_option( 'servertrack_enabled', 1 ) ) {
            return new WP_Error( 'disabled', 'ServerTrack disabled', [ 'status' => 403 ] );
        }

        $platform = strtolower( $request->get_param( 'platform' ) );
        $valid_platforms = [ 'meta', 'tiktok', 'google', 'snapchat', 'pinterest', 'linkedin' ];

        if ( ! in_array( $platform, $valid_platforms, true ) ) {
            return new WP_Error( 'invalid_platform', 'Invalid platform specified', [ 'status' => 400 ] );
        }

        // Apply rate limit
        $ip = ServerTrack_Frontend::get_request_ip();
        $ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? hash( 'sha256', wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : 'no-ua';
        $rate_key = 'st_proxy_rl_' . md5( $ip . $ua );

        $rate_count = get_transient( $rate_key ) ?: [ 'tokens' => 30, 'last_refill' => time() ];
        $now = time();
        $elapsed = $now - $rate_count['last_refill'];
        // Allow up to 30 requests per minute
        $refilled = min( 30, $rate_count['tokens'] + floor( $elapsed / 2 ) );

        if ( $refilled <= 0 ) {
            return new WP_Error( 'rate_limit', 'Rate limit exceeded', [ 'status' => 429 ] );
        }

        set_transient( $rate_key, [ 'tokens' => $refilled - 1, 'last_refill' => $now ], MINUTE_IN_SECONDS );

        // Extract payload data
        $body = $request->get_json_params();
        if ( empty( $body ) ) {
            return new WP_Error( 'invalid_payload', 'Empty or invalid JSON payload', [ 'status' => 400 ] );
        }

        $event_name = sanitize_text_field( $body['event_name'] ?? 'PageView' );
        $event_id   = sanitize_text_field( $body['event_id'] ?? ServerTrack_Dedup::generate_event_id() );

        $params = (array) ( $body['params'] ?? [] );
        array_walk_recursive( $params, function( &$v ) {
            $v = sanitize_text_field( (string) $v );
            if ( preg_match( '/^\d{4}[\s-]?\d{4}[\s-]?\d{4}[\s-]?\d{4}/', $v ) ) $v = 'REDACTED_CREDIT_CARD';
            if ( preg_match( '/^\d{3}-\d{2}-\d{4}/', $v ) ) $v = 'REDACTED_SSN';
        });

        $user_data = [
            'ip'         => $ip,
            'user_agent' => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '',
        ];

        // Capture cookies
        if ( ! empty( $_COOKIE['_fbc'] ) ) $user_data['fbc'] = sanitize_text_field( wp_unslash( $_COOKIE['_fbc'] ) );
        if ( ! empty( $_COOKIE['_fbp'] ) ) $user_data['fbp'] = sanitize_text_field( wp_unslash( $_COOKIE['_fbp'] ) );
        if ( ! empty( $_COOKIE['ttclid'] ) ) $user_data['ttclid'] = sanitize_text_field( wp_unslash( $_COOKIE['ttclid'] ) );

        if ( is_user_logged_in() ) {
            $user = wp_get_current_user();
            if ( $user->user_email ) $user_data['email'] = ServerTrack_Hasher::hash_email( $user->user_email );
            $user_data['external_id'] = ServerTrack_Identity::get_external_id_for_user( $user->ID );
        }

        // Build event
        $event = new ServerTrack_Event( $event_name, $event_id );
        $event->set_user_data( $user_data );
        $event->set_custom_data( array_merge( $params, [
            'event_source_url' => sanitize_text_field( $body['url'] ?? $_SERVER['HTTP_REFERER'] ?? '' ),
            '_dedup_key'       => 'proxy_' . md5( $event_name . $event_id )
        ] ) );

        // Deduplicate against server-side if it exists
        $dedup_key = $event->custom_data['_dedup_key'];
        if ( ServerTrack_Dedup::already_sent( $dedup_key, $platform ) ) {
            return new WP_REST_Response( [ 'status' => 'skipped', 'reason' => 'deduplicated' ], 200 );
        }

        // Dispatch
        if ( get_option( 'servertrack_' . $platform . '_enabled', 0 ) && ServerTrack_Consent::is_granted( $platform ) ) {
            $class_name = 'ServerTrack_' . ucfirst( $platform );
            if ( class_exists( $class_name ) && method_exists( $class_name, 'send' ) ) {
                $result = call_user_func( [ $class_name, 'send' ], $event );

                if ( ( $result['status'] ?? '' ) === 'success' ) {
                    ServerTrack_Dedup::mark_string_sent( $dedup_key, $platform );
                }

                return new WP_REST_Response( $result, 200 );
            }
        }

        return new WP_REST_Response( [ 'status' => 'skipped', 'reason' => 'disabled_or_no_consent' ], 200 );
    }
}