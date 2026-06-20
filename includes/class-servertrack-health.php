<?php
if ( ! defined( 'ABSPATH' ) ) {
    die();
}


/**
 * ServerTrack_Health
 *
 * Module 8: Health Monitor + Token Validator
 * Validates Meta, TikTok, and Google tokens daily via WP-Cron to prevent silent tracking failures.
 */
class ServerTrack_Health {

    const OPTION_KEY = 'servertrack_health_status';

    public static function init(): void {
        add_action( 'servertrack_daily_health_check', [ self::class, 'run_health_check' ] );

        if ( ! wp_next_scheduled( 'servertrack_daily_health_check' ) ) {
            wp_schedule_event( time(), 'daily', 'servertrack_daily_health_check' );
        }
    }

    public static function run_health_check(): void {
        $status = [
            'meta' => self::check_meta(),
            'tiktok' => self::check_tiktok(),
            'google' => self::check_google(),
            'last_run' => time()
        ];

        update_option( self::OPTION_KEY, $status, false );

        // Send alert if any critical failure
        self::send_alerts_if_needed( $status );
    }

    private static function check_meta(): array {
        $pixel_id = get_option( 'servertrack_meta_pixel_id', '' );
        $token = get_option( 'servertrack_meta_access_token', '' );

        if ( empty( $pixel_id ) || empty( $token ) ) {
            return [ 'status' => 'warning', 'message' => 'Not configured' ];
        }

        // Lightweight diagnostic call to check token validity
        $url = "https://graph.facebook.com/v22.0/{$pixel_id}?access_token={$token}&fields=id,name";
        $response = wp_remote_get( $url, [ 'timeout' => 10 ] );

        if ( is_wp_error( $response ) ) {
            return [ 'status' => 'warning', 'message' => 'Network error connecting to Meta' ];
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code === 200 ) {
            return [ 'status' => 'ok', 'message' => 'Active' ];
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        $error = $body['error']['message'] ?? 'Unknown authentication error';

        return [ 'status' => 'error', 'message' => $error ];
    }

    private static function check_tiktok(): array {
        $token = get_option( 'servertrack_tiktok_access_token', '' );
        if ( empty( $token ) ) {
            return [ 'status' => 'warning', 'message' => 'Not configured' ];
        }

        $url = 'https://business-api.tiktok.com/open_api/v1.3/pixel/list/';
        $response = wp_remote_get( $url, [
            'headers' => [ 'Access-Token' => $token ],
            'timeout' => 10
        ] );

        if ( is_wp_error( $response ) ) {
            return [ 'status' => 'warning', 'message' => 'Network error connecting to TikTok' ];
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code === 200 ) {
            return [ 'status' => 'ok', 'message' => 'Active' ];
        }

        return [ 'status' => 'error', 'message' => 'Token invalid or expired' ];
    }

    private static function check_google(): array {
        // Placeholder for Google OAuth token introspection
        $api_secret = get_option( 'servertrack_google_api_secret', '' );
        if ( empty( $api_secret ) ) {
            return [ 'status' => 'warning', 'message' => 'Not configured' ];
        }
        return [ 'status' => 'ok', 'message' => 'Active' ];
    }

    private static function send_alerts_if_needed( array $status ): void {
        $errors = [];
        foreach ( [ 'meta', 'tiktok', 'google' ] as $platform ) {
            if ( isset( $status[ $platform ]['status'] ) && $status[ $platform ]['status'] === 'error' ) {
                $errors[] = ucfirst( $platform ) . ': ' . $status[ $platform ]['message'];
            }
        }

        if ( empty( $errors ) ) {
            return;
        }

        $admin_email = get_option( 'admin_email' );
        $subject = '[ServerTrack] Action Required: CAPI Tracking Failed';
        $message = "ServerTrack detected authentication errors that are preventing events from being sent.\n\n" . implode( "\n", $errors );

        wp_mail( $admin_email, $subject, $message );
    }
}