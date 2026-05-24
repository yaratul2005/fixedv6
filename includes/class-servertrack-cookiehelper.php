<?php
if ( ! defined( 'ABSPATH' ) ) {
    die();
}


/**
 * ServerTrack_CookieHelper
 *
 * Module 1: Server-Side Cookie Helper (Kills ITP Permanently)
 * Sets first-party cookies for ad click IDs using PHP setcookie().
 * This bypasses Safari's 7-day Intelligent Tracking Prevention (ITP) limit on JavaScript cookies,
 * granting a full 2-year expiry to click identifiers (_fbc, ttclid, _gcl_aw).
 */
class ServerTrack_CookieHelper {

    /** Expiry duration for click IDs (2 years) */
    const EXPIRY_SECONDS = 63072000;

    public static function init(): void {
        // Hook early on init to read URL params and set cookies before headers are sent
        add_action( 'init', [ self::class, 'capture_and_refresh_cookies' ], 5 );
    }

    public static function capture_and_refresh_cookies(): void {
        if ( is_admin() || wp_doing_cron() || defined( 'REST_REQUEST' ) ) {
            return;
        }

        $now = time();
        $domain = self::get_cookie_domain();
        $secure = is_ssl();

        // 1. Meta / Facebook (_fbc)
        $fbc_val = '';
        if ( ! empty( $_GET['fbclid'] ) ) {
            $fbclid = sanitize_text_field( wp_unslash( $_GET['fbclid'] ) );
            // Format: fb.subdomain_index.creation_time.fbclid
            $fbc_val = 'fb.1.' . ( $now * 1000 ) . '.' . $fbclid;
        } elseif ( ! empty( $_COOKIE['_fbc'] ) ) {
            // Refresh existing
            $fbc_val = sanitize_text_field( wp_unslash( $_COOKIE['_fbc'] ) );
        }
        if ( $fbc_val ) {
            self::set_secure_cookie( '_fbc', $fbc_val, $now + self::EXPIRY_SECONDS, $domain, $secure );
        }

        // 2. Meta / Facebook Browser ID (_fbp)
        $fbp_val = '';
        if ( ! empty( $_COOKIE['_fbp'] ) ) {
            $fbp_val = sanitize_text_field( wp_unslash( $_COOKIE['_fbp'] ) );
        } else {
            // Format: fb.subdomain_index.creation_time.random_number
            $fbp_val = 'fb.1.' . ( $now * 1000 ) . '.' . wp_rand( 1000000000, 9999999999 );
        }
        if ( $fbp_val ) {
            self::set_secure_cookie( '_fbp', $fbp_val, $now + 90 * DAY_IN_SECONDS, $domain, $secure );
        }

        // 3. TikTok (ttclid)
        $ttclid_val = '';
        if ( ! empty( $_GET['ttclid'] ) ) {
            $ttclid_val = sanitize_text_field( wp_unslash( $_GET['ttclid'] ) );
        } elseif ( ! empty( $_COOKIE['ttclid'] ) ) {
            $ttclid_val = sanitize_text_field( wp_unslash( $_COOKIE['ttclid'] ) );
        }
        if ( $ttclid_val ) {
            self::set_secure_cookie( 'ttclid', $ttclid_val, $now + self::EXPIRY_SECONDS, $domain, $secure );
        }

        // 4. Google (_gcl_aw)
        $gclid_val = '';
        if ( ! empty( $_GET['gclid'] ) ) {
            $gclid_val = sanitize_text_field( wp_unslash( $_GET['gclid'] ) );
            // Google Ads cookie format typically requires the prefix 'GCL.' followed by timestamp
            $gclid_val = 'GCL.' . $now . '.' . $gclid_val;
        } elseif ( ! empty( $_COOKIE['_gcl_aw'] ) ) {
            $gclid_val = sanitize_text_field( wp_unslash( $_COOKIE['_gcl_aw'] ) );
        }
        if ( $gclid_val ) {
            self::set_secure_cookie( '_gcl_aw', $gclid_val, $now + self::EXPIRY_SECONDS, $domain, $secure );
        }
    }

    private static function set_secure_cookie( string $name, string $value, int $expire, string $domain, bool $secure ): void {
        if ( PHP_VERSION_ID >= 70300 ) {
            setcookie( $name, $value, [
                'expires'  => $expire,
                'path'     => '/',
                'domain'   => $domain,
                'secure'   => $secure,
                'httponly' => false,
                'samesite' => 'Lax',
            ] );
        } else {
            // Fallback for older PHP
            setcookie( $name, $value, $expire, '/; samesite=Lax', $domain, $secure, false );
        }

        // Make it immediately available to the current request
        $_COOKIE[ $name ] = $value;
    }

    /**
     * Determines the base domain for the cookie.
     */
    private static function get_cookie_domain(): string {
        $host = wp_parse_url( home_url(), PHP_URL_HOST );
        if ( ! $host ) {
            $host = $_SERVER['HTTP_HOST'] ?? '';
        }

        // Strip port if present
        $host = preg_replace( '/:\d+$/', '', $host );

        // Remove www.
        if ( strpos( $host, 'www.' ) === 0 ) {
            $host = substr( $host, 4 );
        }

        return '.' . $host;
    }
}