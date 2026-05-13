<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ServerTrack_Consent  v2.2
 *
 * Changes in v2.2:
 *   BUG-M5: The cron-bypass deny path previously called
 *     ServerTrack_Logger::log('skipped', ...) which respects the debug_mode
 *     gate — meaning in production (debug_mode=0) consent failures were silently
 *     swallowed with no trace in the log table.
 *     Fixed: replaced with ServerTrack_Logger::warning(...) which bypasses the
 *     debug_mode check so store owners always see consent-block events regardless
 *     of debug settings. This is critical for GDPR audit trails.
 *
 * Changes in v2.1 (CRITICAL FIX — Per-order consent storage):
 *
 *   Previously, cron/async context bypassed cookie checks entirely with the
 *   assumption that "the originating request already passed consent". This is
 *   unsafe because:
 *
 *     1. A customer could withdraw consent between checkout and the async
 *        cron firing (e.g., on slow hosts where cron fires minutes later).
 *     2. On stores processing backdated or manual orders, no browser session
 *        ever existed — the bypass sends events with zero consent basis.
 *     3. GDPR audit trails require proof of consent at the time of the send,
 *        not a blanket bypass.
 *
 *   Fix: consent state is now captured and stored as order meta at the moment
 *   the customer's browser makes the InitiateCheckout / Purchase hook call.
 *   Async cron jobs read the stored per-order consent — not a cookie or bypass.
 *
 *   For non-order events (ViewContent, AddToCart, CF7 Lead) that run
 *   synchronously in browser context, cookie checks still apply as before.
 *   The cron bypass is now a narrow last-resort fallback only, not the default.
 *
 * Storage:
 *   Order meta key: _servertrack_consent
 *   Value: serialised array [ 'meta' => true/false, 'google' => true/false, 'tiktok' => true/false ]
 *   Written by: ServerTrack_Consent::capture_for_order( $order_id )
 *   Read by:    ServerTrack_Consent::is_granted( $platform, $order_id )
 */
class ServerTrack_Consent {

    /** Order meta key where per-order consent snapshot is stored. */
    const ORDER_META_KEY = '_servertrack_consent';

    /**
     * Checks if consent is granted for a given platform.
     *
     * @param string   $platform  'meta' | 'google' | 'tiktok'
     * @param int|null $order_id  Pass the order ID for async/cron contexts.
     * @return bool
     */
    public static function is_granted( string $platform, ?int $order_id = null ): bool {
        $mode = get_option( 'servertrack_consent_mode', 'none' );

        // No consent mode configured — always allowed
        if ( 'none' === $mode ) {
            return true;
        }

        // ── Per-order consent (async/cron context) ───────────────────────
        if ( null !== $order_id && $order_id > 0 && function_exists( 'wc_get_order' ) ) {
            $order = wc_get_order( $order_id );
            if ( $order instanceof \WC_Abstract_Order ) {
                $stored = $order->get_meta( self::ORDER_META_KEY, true );
                if ( is_array( $stored ) && isset( $stored[ $platform ] ) ) {
                    return (bool) $stored[ $platform ];
                }
            }
        }

        // ── Cron / CLI / REST — no browser session ──────────────────────
        if ( self::is_cron_or_cli() ) {
            if ( 'manual' === $mode ) {
                return (bool) apply_filters( 'servertrack_consent_granted', false, $platform );
            }
            // BUG-M5 fix: use warning() instead of log() so this is ALWAYS
            // written to the log regardless of the debug_mode setting.
            // In production (debug_mode=0), log() is gated and swallows this
            // entry silently — store owners would have no visibility into
            // why events are being blocked. warning() bypasses the gate.
            ServerTrack_Logger::warning(
                'Consent check in cron/CLI: no per-order consent record found for order #'
                . (int) ( $order_id ?? 0 )
                . '. Ensure ServerTrack_Consent::capture_for_order() is called at checkout. '
                . 'Platform=' . $platform . '. Event blocked (GDPR-safe deny).'
            );
            return false;
        }

        // ── Browser context — live cookie check ──────────────────────────
        if ( 'cookie_yes' === $mode ) {
            if ( isset( $_COOKIE['cookieyes-consent'] ) ) {
                $consent_cookie = sanitize_text_field( wp_unslash( $_COOKIE['cookieyes-consent'] ) );
                if (
                    strpos( $consent_cookie, 'analytics:yes' )     !== false &&
                    strpos( $consent_cookie, 'advertisement:yes' ) !== false
                ) {
                    return true;
                }
            }
            return false;
        }

        if ( 'complianz' === $mode ) {
            $marketing_allowed  = isset( $_COOKIE['cmplz_marketing'] )  && 'allow' === sanitize_text_field( wp_unslash( $_COOKIE['cmplz_marketing'] ) );
            $statistics_allowed = isset( $_COOKIE['cmplz_statistics'] ) && 'allow' === sanitize_text_field( wp_unslash( $_COOKIE['cmplz_statistics'] ) );
            return $marketing_allowed && $statistics_allowed;
        }

        if ( 'manual' === $mode ) {
            return (bool) apply_filters( 'servertrack_consent_granted', false, $platform );
        }

        return true;
    }

    /**
     * Captures the current browser consent state and stores it as order meta.
     *
     * @param int $order_id  WooCommerce order ID.
     */
    public static function capture_for_order( int $order_id ): void {
        if ( ! function_exists( 'wc_get_order' ) ) {
            return;
        }
        $order = wc_get_order( $order_id );
        if ( ! ( $order instanceof \WC_Abstract_Order ) ) {
            return;
        }

        $existing = $order->get_meta( self::ORDER_META_KEY, true );
        if ( is_array( $existing ) && ! empty( $existing ) ) {
            return;
        }

        $platforms = [ 'meta', 'google', 'tiktok' ];
        $consent   = [];
        foreach ( $platforms as $platform ) {
            $consent[ $platform ] = self::check_browser_consent( $platform );
        }

        $order->update_meta_data( self::ORDER_META_KEY, $consent );
        $order->save_meta_data();
    }

    /**
     * Pure browser-cookie consent check (no cron bypass, no order lookup).
     *
     * @param string $platform
     * @return bool
     */
    private static function check_browser_consent( string $platform ): bool {
        $mode = get_option( 'servertrack_consent_mode', 'none' );

        if ( 'none' === $mode ) {
            return true;
        }

        if ( 'cookie_yes' === $mode ) {
            if ( isset( $_COOKIE['cookieyes-consent'] ) ) {
                $consent_cookie = sanitize_text_field( wp_unslash( $_COOKIE['cookieyes-consent'] ) );
                return (
                    strpos( $consent_cookie, 'analytics:yes' )     !== false &&
                    strpos( $consent_cookie, 'advertisement:yes' ) !== false
                );
            }
            return false;
        }

        if ( 'complianz' === $mode ) {
            $marketing  = isset( $_COOKIE['cmplz_marketing'] )  && 'allow' === sanitize_text_field( wp_unslash( $_COOKIE['cmplz_marketing'] ) );
            $statistics = isset( $_COOKIE['cmplz_statistics'] ) && 'allow' === sanitize_text_field( wp_unslash( $_COOKIE['cmplz_statistics'] ) );
            return $marketing && $statistics;
        }

        if ( 'manual' === $mode ) {
            return (bool) apply_filters( 'servertrack_consent_granted', false, $platform );
        }

        return true;
    }

    /**
     * Returns true when running inside WP-Cron, WP-CLI, or a REST request.
     */
    private static function is_cron_or_cli(): bool {
        if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
            return true;
        }
        if ( defined( 'WP_CLI' ) && WP_CLI ) {
            return true;
        }
        if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
            return true;
        }
        return false;
    }
}
