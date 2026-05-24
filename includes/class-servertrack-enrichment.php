<?php
if ( ! defined( 'ABSPATH' ) ) {
    die();
}


/**
 * ServerTrack_Enrichment
 *
 * Module 3: Advanced Signal Enrichment (IP + Geo + UA)
 * Provides advanced parsing and storage of IP, Geo data, and structured User Agent
 * to significantly improve Meta/TikTok/Google Event Match Quality (EMQ).
 */
class ServerTrack_Enrichment {

    public static function init(): void {
        add_action( 'init', [ self::class, 'capture_session_signals' ], 10 );
    }

    /**
     * Resolves the most accurate client IP address through proxy headers.
     */
    public static function get_client_ip(): string {
        if ( isset( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
            return sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) );
        }
        if ( isset( $_SERVER['HTTP_X_REAL_IP'] ) ) {
            return sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_REAL_IP'] ) );
        }
        if ( isset( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
            $ips = explode( ',', sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) );
            return trim( end( $ips ) ); // The last proxy usually appends the true client IP
        }
        return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
    }

    /**
     * Parses the User-Agent into a structured array for CAPI user_data.
     */
    public static function parse_user_agent( string $ua ): array {
        $os = '';
        $device_type = 'desktop';
        $browser_name = '';

        if ( preg_match( '/iphone|ipad|ipod/i', $ua ) ) {
            $os = 'iOS';
            $device_type = 'mobile';
        } elseif ( preg_match( '/android/i', $ua ) ) {
            $os = 'Android';
            $device_type = 'mobile';
        } elseif ( preg_match( '/windows/i', $ua ) ) {
            $os = 'Windows';
        } elseif ( preg_match( '/mac os x/i', $ua ) ) {
            $os = 'Mac OS X';
        }

        if ( preg_match( '/chrome|crios/i', $ua ) ) {
            $browser_name = 'Chrome';
        } elseif ( preg_match( '/safari/i', $ua ) && ! preg_match( '/chrome|crios/i', $ua ) ) {
            $browser_name = 'Safari';
        } elseif ( preg_match( '/firefox|fxios/i', $ua ) ) {
            $browser_name = 'Firefox';
        }

        return array_filter([
            'os' => $os,
            'device_type' => $device_type,
            'browser_name' => $browser_name
        ]);
    }

    /**
     * Captures and persists session IP/UA so cron events can attach them safely.
     */
    public static function capture_session_signals(): void {
        if ( is_admin() || wp_doing_cron() || defined( 'REST_REQUEST' ) ) {
            return;
        }

        $ip = self::get_client_ip();
        $ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';

        if ( is_user_logged_in() ) {
            $user_id = get_current_user_id();
            update_user_meta( $user_id, '_st_session_ip', $ip );
            update_user_meta( $user_id, '_st_session_ua', $ua );
        } elseif ( function_exists('WC') && WC()->session ) {
            $session_id = WC()->session->get_customer_id();
            if ( $session_id ) {
                set_transient( 'st_session_ip_' . $session_id, $ip, DAY_IN_SECONDS );
                set_transient( 'st_session_ua_' . $session_id, $ua, DAY_IN_SECONDS );
            }
        }
    }
}