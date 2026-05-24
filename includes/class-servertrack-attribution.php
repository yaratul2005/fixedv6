<?php
if ( ! defined( 'ABSPATH' ) ) {
    die();
}


/**
 * ServerTrack_Attribution
 *
 * Module 7: UTM Attribution Engine
 * Tracks UTM parameters across pageviews and maintains a session-based history of the last 10 touches.
 * Exposes this history to be appended to conversion events.
 */
class ServerTrack_Attribution {

    const HISTORY_KEY = 'st_utm_history';
    const MAX_TOUCHES = 10;

    public static function init(): void {
        add_action( 'init', [ self::class, 'capture_utm_params' ], 5 );
        // Filter into event custom data to append attribution info
        add_filter( 'servertrack_event_custom_data', [ self::class, 'append_attribution_to_event' ], 10, 2 );
    }

    public static function capture_utm_params(): void {
        if ( is_admin() || wp_doing_cron() || defined( 'REST_REQUEST' ) ) {
            return;
        }

        $params = [
            'utm_source'   => sanitize_text_field( wp_unslash( $_GET['utm_source'] ?? '' ) ),
            'utm_medium'   => sanitize_text_field( wp_unslash( $_GET['utm_medium'] ?? '' ) ),
            'utm_campaign' => sanitize_text_field( wp_unslash( $_GET['utm_campaign'] ?? '' ) ),
            'utm_term'     => sanitize_text_field( wp_unslash( $_GET['utm_term'] ?? '' ) ),
            'utm_content'  => sanitize_text_field( wp_unslash( $_GET['utm_content'] ?? '' ) ),
        ];

        // Filter out empty params
        $params = array_filter( $params );

        if ( empty( $params ) ) {
            return;
        }

        $params['timestamp'] = time();
        $params['url']       = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) );

        $history = self::get_history();

        // Prevent duplicate consecutive touches
        if ( ! empty( $history ) ) {
            $last_touch = end( $history );
            $last_params = array_intersect_key( $last_touch, array_flip( ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'] ) );
            $current_params = array_intersect_key( $params, array_flip( ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'] ) );

            if ( $last_params === $current_params ) {
                return;
            }
        }

        $history[] = $params;

        if ( count( $history ) > self::MAX_TOUCHES ) {
            $history = array_slice( $history, -self::MAX_TOUCHES );
        }

        self::save_history( $history );
    }

    private static function get_history(): array {
        if ( is_user_logged_in() ) {
            return get_user_meta( get_current_user_id(), self::HISTORY_KEY, true ) ?: [];
        }

        if ( function_exists('WC') && WC()->session ) {
            $session_id = WC()->session->get_customer_id();
            if ( $session_id ) {
                return get_transient( self::HISTORY_KEY . '_' . $session_id ) ?: [];
            }
        }

        // Fallback to PHP session if neither is available
        if ( session_status() === PHP_SESSION_NONE ) {
            @session_start();
        }
        return $_SESSION[ self::HISTORY_KEY ] ?? [];
    }

    private static function save_history( array $history ): void {
        if ( is_user_logged_in() ) {
            update_user_meta( get_current_user_id(), self::HISTORY_KEY, $history );
            return;
        }

        if ( function_exists('WC') && WC()->session ) {
            $session_id = WC()->session->get_customer_id();
            if ( $session_id ) {
                set_transient( self::HISTORY_KEY . '_' . $session_id, $history, 30 * DAY_IN_SECONDS );
                return;
            }
        }

        if ( session_status() === PHP_SESSION_NONE ) {
            @session_start();
        }
        $_SESSION[ self::HISTORY_KEY ] = $history;
    }

    public static function append_attribution_to_event( array $custom_data, ServerTrack_Event $event ): array {
        // Only append to key conversion events
        $conversion_events = [ 'Purchase', 'Lead', 'CompleteRegistration' ];
        if ( ! in_array( $event->event_name, $conversion_events, true ) ) {
            return $custom_data;
        }

        $history = self::get_history();
        if ( empty( $history ) ) {
            return $custom_data;
        }

        // First touch
        $first = reset( $history );
        foreach ( [ 'source', 'medium', 'campaign', 'term', 'content' ] as $param ) {
            $key = "utm_{$param}";
            if ( ! empty( $first[ $key ] ) ) {
                $custom_data[ "first_{$key}" ] = $first[ $key ];
            }
        }

        // Last touch
        $last = end( $history );
        foreach ( [ 'source', 'medium', 'campaign', 'term', 'content' ] as $param ) {
            $key = "utm_{$param}";
            if ( ! empty( $last[ $key ] ) ) {
                $custom_data[ "last_{$key}" ] = $last[ $key ];
            }
        }

        // Full path as JSON string (some platforms allow array, but string is safest for custom_data)
        $custom_data['utm_path'] = wp_json_encode( $history );

        return $custom_data;
    }
}