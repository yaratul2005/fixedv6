<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ServerTrack_Core — backward-compatibility shim (v6.0.3+)
 *
 * This class is intentionally a no-op. In v6.0.2 and earlier, ServerTrack_Core
 * was a competing bootstrap system inside includes/ that was never actually
 * loaded or called from the main plugin file (servertrack.php). As a result
 * every subsystem it bootstrapped — ServerTrack_Frontend, ServerTrack_CustomEvents,
 * ServerTrack_Retry, and all v3.x WooCommerce sources — was silently dead.
 *
 * As of v6.0.3 the single authoritative bootstrap is servertrack_init() in
 * servertrack.php. ServerTrack_Core::init() is kept here as a safe no-op so
 * that any stale third-party code that somehow calls it does not produce a
 * fatal error. It will emit a _doing_it_wrong() notice in WP_DEBUG mode.
 *
 * Do NOT add logic back to this class. Add it to servertrack_init() instead.
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
}
