<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ServerTrack_Core — backward-compatibility shim (v6.0.3+)
 *
 * This class is intentionally a no-op for its bootstrap. In v6.0.2 and earlier,
 * ServerTrack_Core was a competing bootstrap system inside includes/ that was
 * never actually loaded or called from the main plugin file (servertrack.php).
 *
 * As of v6.0.3 the single authoritative bootstrap is servertrack_init() in
 * servertrack.php. ServerTrack_Core::init() is kept here as a safe no-op.
 *
 * BUG-FIX (v6.0.5):
 *   dispatch_to_all() and dispatch_to_platforms() were missing entirely from
 *   this shim. ServerTrack_Source_WooCommerce calls them on every WooCommerce
 *   CAPI event (Purchase, AddToCart, InitiateCheckout, etc.). The missing
 *   methods caused a PHP Fatal Error ("Call to undefined method") on every
 *   single event, producing the "There has been a critical error on this
 *   website." white screen.
 *
 *   Fix: implement both dispatch methods here using the platform classes
 *   (ServerTrack_Meta, ServerTrack_TikTok, ServerTrack_Google) and
 *   ServerTrack_Dedup for per-platform already-sent guards.
 */
class ServerTrack_Core {

    /**
     * No-op. The real bootstrap runs via servertrack_init() at plugins_loaded.
     *
     * @since 6.0.3
     */
    public static function init(): void {
        _doing_it_wrong(
            __METHOD__,
            'ServerTrack_Core::init() is a no-op shim since v6.0.3. ' .
            'The authoritative bootstrap is servertrack_init() in servertrack.php.',
            '6.0.3'
        );
    }

    /**
     * Dispatch an event to ALL three platforms (Meta, TikTok, Google),
     * skipping any that have already received this event (per dedup key).
     *
     * This is the method called by ServerTrack_Source_WooCommerce for every
     * WooCommerce CAPI event. It was missing from this shim, causing a PHP
     * Fatal Error on every hook firing.
     *
     * @param ServerTrack_Event  $event     Fully populated event DTO.
     * @param string|int|null    $dedup_key Dedup key; defaults to event_id.
     */
    public static function dispatch_to_all( ServerTrack_Event $event, $dedup_key = null ): void {
        self::dispatch_to_platforms( $event, [ 'meta', 'tiktok', 'google' ], $dedup_key );
    }

    /**
     * Dispatch an event to a specific subset of platforms.
     *
     * Checks ServerTrack_Dedup::already_sent() per platform before sending,
     * then marks each successfully dispatched platform via
     * ServerTrack_Dedup::mark_sent().
     *
     * @param ServerTrack_Event  $event      Fully populated event DTO.
     * @param string[]           $platforms  Any subset of ['meta','tiktok','google'].
     * @param string|int|null    $dedup_key  Dedup key; defaults to event_id.
     */
    public static function dispatch_to_platforms(
        ServerTrack_Event $event,
        array $platforms,
        $dedup_key = null
    ): void {
        $key = (string) ( $dedup_key ?? $event->event_id );

        foreach ( $platforms as $platform ) {
            // Skip if already sent to this platform.
            if ( ServerTrack_Dedup::already_sent( $key, $platform ) ) {
                continue;
            }

            $result = [ 'status' => 'skipped' ];

            switch ( $platform ) {
                case 'meta':
                    if ( get_option( 'servertrack_meta_enabled', 0 ) ) {
                        $result = ServerTrack_Meta::send( $event );
                    }
                    break;

                case 'tiktok':
                    if ( get_option( 'servertrack_tiktok_enabled', 0 ) ) {
                        $result = ServerTrack_TikTok::send( $event );
                    }
                    break;

                case 'google':
                    if ( get_option( 'servertrack_google_enabled', 0 ) ) {
                        $result = ServerTrack_Google::send( $event );
                    }
                    break;
            }

            // Mark sent on success so retries / duplicate hooks don't re-fire.
            if ( isset( $result['status'] ) && $result['status'] === 'success' ) {
                ServerTrack_Dedup::mark_sent( $key, $platform );
            }
        }
    }
}
