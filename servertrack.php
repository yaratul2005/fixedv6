<?php
/**
 * Plugin Name:       ServerTrack
 * Plugin URI:        https://github.com/yaratul2005/ServerTrack
 * Description:       Professional server-side CAPI tracking for Meta, TikTok & Google — with identity stitching, click ID persistence, EMQ scoring, offline conversions, pixel dedup, LTV signals, catalog enrichment, webhook outbound, cart abandonment, subscriptions, and admin dashboard.
 * Version:           6.0.4
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            MD. Yaser Ahmmed Ratul
 * License:           GPL-2.0-or-later
 * Text Domain:       servertrack
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * v6.0.4 — Bug fixes.
 *
 * Fixes in this release
 * ----------------------
 *   BUG-FIX-1: ServerTrack_Hasher::event_id() was missing — fatal error on
 *              every WooCommerce CAPI event from the v3.x source layer.
 *   BUG-FIX-2: ServerTrack_Source_WooCommerce::init() was never called —
 *              entire v3.x extended WooCommerce source was dead code.
 *   BUG-FIX-3: Cart abandonment option key mismatch fixed.
 *              (servertrack_source_abandonment_enabled vs
 *               servertrack_source_cart_abandonment_enabled)
 *   BUG-FIX-4: InitiateCheckout event_id used time() — broke deduplication.
 *   BUG-FIX-5: ensure_uid() race condition — replaced random UUID with
 *              deterministic hash_hmac so concurrent requests always produce
 *              the same external_id for new users.
 *
 * v6.0.3 — Bootstrap consolidation.
 *
 * History of the problem v6.0.3 fixed
 * ------------------------------------------
 * The plugin had TWO competing bootstrap systems that were never merged:
 *
 *   1. The ORIGINAL flat system in this file (servertrack_init) loaded only
 *      ~15 classes and missed: ServerTrack_Frontend, ServerTrack_CustomEvents,
 *      ServerTrack_Retry (call), and all v3.x WooCommerce source classes.
 *
 *   2. The NEWER ServerTrack_Core::init() system in
 *      includes/class-servertrack-core.php was never require_once'd or called
 *      from this file, making it completely dead code.
 *
 * Result: frontend pixel never fired, custom events never ran, retry queue
 * was never processed, and half the WooCommerce source classes were silently
 * skipped.
 *
 * Fix: one authoritative servertrack_load_classes() + servertrack_init() here.
 * class-servertrack-core.php is kept as a backward-compat shim (no-op).
 */

define( 'SERVERTRACK_VERSION', '6.0.4' );
define( 'SERVERTRACK_DIR',     plugin_dir_path( __FILE__ ) );
define( 'SERVERTRACK_URL',     plugin_dir_url( __FILE__ ) );

// ─────────────────────────────────────────────────────────────────────────────
// Class loader — strict dependency order: dependency before dependent.
// ─────────────────────────────────────────────────────────────────────────────
function servertrack_load_classes(): void {

    // ── Core infrastructure ───────────────────────────────────────────────────
    require_once SERVERTRACK_DIR . 'includes/class-servertrack-hasher.php';
    require_once SERVERTRACK_DIR . 'includes/class-servertrack-event.php';
    require_once SERVERTRACK_DIR . 'includes/class-servertrack-dedup.php';
    require_once SERVERTRACK_DIR . 'includes/class-servertrack-consent.php';
    require_once SERVERTRACK_DIR . 'includes/class-servertrack-consent-v2.php';
    require_once SERVERTRACK_DIR . 'includes/class-servertrack-retry.php';
    require_once SERVERTRACK_DIR . 'includes/class-servertrack-logger.php';
    require_once SERVERTRACK_DIR . 'includes/class-servertrack-identity.php';
    require_once SERVERTRACK_DIR . 'includes/class-servertrack-clickcapture.php';
    require_once SERVERTRACK_DIR . 'includes/class-servertrack-matchquality.php';
    require_once SERVERTRACK_DIR . 'includes/class-servertrack-offline-conversion.php';
    require_once SERVERTRACK_DIR . 'includes/class-servertrack-pixel-dedup.php';
    require_once SERVERTRACK_DIR . 'includes/class-servertrack-ltv.php';
    require_once SERVERTRACK_DIR . 'includes/class-servertrack-catalog.php';
    require_once SERVERTRACK_DIR . 'includes/class-servertrack-webhook.php';
    // BUG-2 FIX: custom-events was present but never loaded.
    require_once SERVERTRACK_DIR . 'includes/class-servertrack-custom-events.php';
    // Backward-compat shim — keeps ServerTrack_Core as a safe no-op class.
    require_once SERVERTRACK_DIR . 'includes/class-servertrack-core.php';

    if ( defined( 'WP_CLI' ) && WP_CLI ) {
        require_once SERVERTRACK_DIR . 'includes/class-servertrack-cli.php';
    }

    // ── Platform senders ─────────────────────────────────────────────────────
    require_once SERVERTRACK_DIR . 'platforms/class-servertrack-meta.php';
    require_once SERVERTRACK_DIR . 'platforms/class-servertrack-tiktok.php';
    require_once SERVERTRACK_DIR . 'platforms/class-servertrack-google.php';

    // ── WooCommerce event sources ─────────────────────────────────────────────
    // Core WooCommerce purchase/refund/view events.
    require_once SERVERTRACK_DIR . 'sources/class-servertrack-woocommerce.php';
    // Extended WooCommerce source (v3.x — wishlist, partial refund, order status).
    require_once SERVERTRACK_DIR . 'sources/class-servertrack-source-woocommerce.php';
    // Subscription renewal/cancellation events.
    require_once SERVERTRACK_DIR . 'sources/class-servertrack-woo-renewals.php';
    // Cart abandonment — opt-in, guarded by option check in init().
    require_once SERVERTRACK_DIR . 'sources/class-servertrack-cart-abandonment.php';
    require_once SERVERTRACK_DIR . 'sources/class-servertrack-woo-abandonment.php';
    // Order lifecycle status events: on-hold, failed, cancelled.
    require_once SERVERTRACK_DIR . 'sources/class-servertrack-woo-order-status.php';
    // AddToWishlist events — opt-in.
    require_once SERVERTRACK_DIR . 'sources/class-servertrack-woo-wishlist.php';
    // Partial refund events.
    require_once SERVERTRACK_DIR . 'sources/class-servertrack-woo-partial-refund.php';
    // Subscriptions (WooCommerce Subscriptions plugin wrapper).
    require_once SERVERTRACK_DIR . 'sources/class-servertrack-subscriptions.php';

    // ── Optional third-party sources ─────────────────────────────────────────
    require_once SERVERTRACK_DIR . 'sources/class-servertrack-cf7.php';
    require_once SERVERTRACK_DIR . 'sources/class-servertrack-edd.php';

    // ── Admin ─────────────────────────────────────────────────────────────────
    if ( is_admin() ) {
        require_once SERVERTRACK_DIR . 'admin/class-servertrack-dashboard.php';
        require_once SERVERTRACK_DIR . 'admin/class-servertrack-admin.php';
    }

    // ── Frontend pixel ────────────────────────────────────────────────────────
    if ( ! is_admin() ) {
        require_once SERVERTRACK_DIR . 'frontend/class-servertrack-frontend.php';
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Plugin init — runs at plugins_loaded priority 20.
// ─────────────────────────────────────────────────────────────────────────────
function servertrack_init(): void {
    servertrack_load_classes();
    servertrack_run_upgrade();

    // ── Core infrastructure ───────────────────────────────────────────────────
    ServerTrack_Identity::init();
    ServerTrack_ClickCapture::init();
    ServerTrack_OfflineConversion::init();
    ServerTrack_PixelDedup::init();
    ServerTrack_LTV::init();
    ServerTrack_Catalog::init();
    ServerTrack_Webhook::init();
    // BUG-3 FIX: Retry::init() was never called → queue never processed.
    ServerTrack_Retry::init();
    // BUG-2 FIX: CustomEvents::init() was never called.
    ServerTrack_CustomEvents::init();

    // ── WooCommerce sources ───────────────────────────────────────────────────
    if ( class_exists( 'WooCommerce' ) ) {
        ServerTrack_WooCommerce::init();
        // BUG-FIX-2: Source_WooCommerce::init() was never called — the entire
        // v3.x extended WooCommerce source (wishlist, partial refund, order
        // status events, enhanced purchase dedup) was silently dead code.
        ServerTrack_Source_WooCommerce::init();

        // Renewals (WooCommerce Subscriptions plugin).
        if ( class_exists( 'WC_Subscriptions' ) ) {
            ServerTrack_WooRenewals::init();
            ServerTrack_Subscriptions::init();
        }

        // BUG-FIX-3: was checking 'servertrack_source_abandonment_enabled'
        // but admin saves to 'servertrack_source_cart_abandonment_enabled'.
        if ( get_option( 'servertrack_source_cart_abandonment_enabled', 0 ) ) {
            ServerTrack_CartAbandonment::init();
            ServerTrack_WooAbandonment::init();
        }

        // Order lifecycle status events (on-hold, failed, cancelled).
        if ( get_option( 'servertrack_source_order_status_enabled', 1 ) ) {
            ServerTrack_WooOrderStatus::init();
        }

        // AddToWishlist events — opt-in.
        if ( get_option( 'servertrack_source_wishlist_enabled', 0 ) ) {
            ServerTrack_WooWishlist::init();
        }

        // Partial refund events.
        if ( get_option( 'servertrack_source_partial_refund_enabled', 1 ) ) {
            ServerTrack_WooPartialRefund::init();
        }
    }

    // ── Optional third-party sources ─────────────────────────────────────────
    if ( class_exists( 'WPCF7' ) && get_option( 'servertrack_source_cf7_enabled', 0 ) ) {
        ServerTrack_CF7::init();
    }
    if ( class_exists( 'Easy_Digital_Downloads' ) && get_option( 'servertrack_source_edd_enabled', 0 ) ) {
        ServerTrack_EDD::init();
    }

    // ── Admin ─────────────────────────────────────────────────────────────────
    if ( is_admin() ) {
        ServerTrack_Dashboard::init();
        ServerTrack_Admin::init();
    }

    // ── Frontend pixel ────────────────────────────────────────────────────────
    if ( ! is_admin() ) {
        ServerTrack_Frontend::init();
    }
}
add_action( 'plugins_loaded', 'servertrack_init', 20 );

// ─────────────────────────────────────────────────────────────────────────────
// Upgrade guard — version-keyed so it only runs once per version bump.
// ─────────────────────────────────────────────────────────────────────────────
function servertrack_run_upgrade(): void {
    $installed = get_option( 'servertrack_db_version', '0' );
    if ( version_compare( $installed, SERVERTRACK_VERSION, '>=' ) ) {
        return; // Nothing to do.
    }
    servertrack_register_defaults();
    update_option( 'servertrack_db_version', SERVERTRACK_VERSION );
}

function servertrack_register_defaults(): void {
    $defaults = [
        // Core toggles
        'servertrack_enabled'                        => 1,
        'servertrack_debug_mode'                     => 0,
        'servertrack_debug_log'                      => [],
        'servertrack_retry_queue'                    => [],
        // Consent
        'servertrack_consent_mode'                   => 'none',
        // Platform toggles
        'servertrack_meta_enabled'                   => 0,
        'servertrack_meta_pixel_id'                  => '',
        'servertrack_meta_access_token'              => '',
        'servertrack_meta_test_event_code'           => '',
        'servertrack_google_enabled'                 => 0,
        'servertrack_google_conversion_id'           => '',
        'servertrack_google_conversion_label'        => '',
        'servertrack_google_refresh_token'           => '',
        'servertrack_google_client_id'               => '',
        'servertrack_google_client_secret'           => '',
        'servertrack_tiktok_enabled'                 => 0,
        'servertrack_tiktok_pixel_id'                => '',
        'servertrack_tiktok_access_token'            => '',
        // Source toggles
        'servertrack_source_woo_enabled'                      => 1,
        'servertrack_source_cart_abandonment_enabled'         => 0,
        'servertrack_abandonment_window_minutes'              => 60,
        'servertrack_source_order_status_enabled'             => 1,
        'servertrack_source_wishlist_enabled'                 => 0,
        'servertrack_source_partial_refund_enabled'           => 1,
        'servertrack_source_cf7_enabled'                      => 0,
        'servertrack_source_edd_enabled'                      => 0,
        'servertrack_source_subscriptions_enabled'            => 0,
    ];

    foreach ( $defaults as $key => $value ) {
        if ( false === get_option( $key ) ) {
            add_option( $key, $value, '', 'no' );
        }
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// WP-Cron schedules
// ─────────────────────────────────────────────────────────────────────────────
add_filter( 'cron_schedules', function ( array $schedules ): array {
    if ( ! isset( $schedules['every_five_minutes'] ) ) {
        $schedules['every_five_minutes'] = [
            'interval' => 300,
            'display'  => __( 'Every 5 Minutes', 'servertrack' ),
        ];
    }
    return $schedules;
} );

// ─────────────────────────────────────────────────────────────────────────────
// Activation / deactivation
// ─────────────────────────────────────────────────────────────────────────────
register_activation_hook( __FILE__, function (): void {
    servertrack_load_classes();
    servertrack_register_defaults();
    if ( ! wp_next_scheduled( 'servertrack_process_retry_queue' ) ) {
        wp_schedule_event( time(), 'every_five_minutes', 'servertrack_process_retry_queue' );
    }
    if ( ! wp_next_scheduled( 'servertrack_check_abandonment' ) ) {
        wp_schedule_event( time(), 'every_five_minutes', 'servertrack_check_abandonment' );
    }
} );

register_deactivation_hook( __FILE__, function (): void {
    wp_clear_scheduled_hook( 'servertrack_process_retry_queue' );
    wp_clear_scheduled_hook( 'servertrack_check_abandonment' );
} );
