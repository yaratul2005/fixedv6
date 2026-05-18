<?php
/**
 * ServerTrack Uninstall  — v4.0
 *
 * Runs when the plugin is deleted from WP Admin → Plugins.
 * Removes ALL stored options, order meta (classic + HPOS), retry/abandonment
 * transients, and all scheduled cron hooks from the database.
 *
 * Updated in v4.0:
 *   - Added v4.0 options: abandonment, gtag, scroll/video/wishlist tracking
 *   - Fixed cron hook list (old mismatched name removed, all new hooks added)
 *   - Added abandonment transient cleanup
 *   - Added full order meta key list (consent, click IDs, dedup flags)
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// ── 1. Remove all plugin options ─────────────────────────────────────────────
$servertrack_options = [
    // General
    'servertrack_enabled',
    'servertrack_test_mode',
    'servertrack_consent_mode',
    'servertrack_debug_mode',
    'servertrack_retry_queue',
    'servertrack_db_version',
    'servertrack_log_max_entries',
    'servertrack_dedup_ttl_days',
    'servertrack_debug_log',
    // Meta CAPI
    'servertrack_meta_enabled',
    'servertrack_meta_pixel_id',
    'servertrack_meta_access_token',
    'servertrack_meta_test_event_code',
    // Google Ads
    'servertrack_google_enabled',
    'servertrack_google_customer_id',
    'servertrack_google_conversion_id',
    'servertrack_google_developer_token',
    'servertrack_google_client_id',
    'servertrack_google_client_secret',
    'servertrack_google_refresh_token',
    'servertrack_google_access_token',
    'servertrack_google_token_expires',
    'servertrack_google_gtag_id',
    'servertrack_google_gtag_label',
    // TikTok
    'servertrack_tiktok_enabled',
    'servertrack_tiktok_pixel_id',
    'servertrack_tiktok_access_token',
    // Sources
    'servertrack_source_woo_enabled',
    'servertrack_source_cf7_enabled',
    'servertrack_source_edd_enabled',
    'servertrack_source_abandonment_enabled',
    'servertrack_abandonment_window_minutes',
    'servertrack_cf7_mappings',
    // Browser tracking
    'servertrack_scroll_depth',
    'servertrack_video_tracking',
    'servertrack_wishlist_tracking',
    // Order Status, partial refunds, subscriptions
    'servertrack_source_order_status_enabled',
    'servertrack_source_wishlist_enabled',
    'servertrack_source_partial_refund_enabled',
    'servertrack_source_subscriptions_enabled',
    // Missing platforms
    'servertrack_snapchat_enabled',
    'servertrack_snapchat_pixel_id',
    'servertrack_snapchat_access_token',
    'servertrack_pinterest_enabled',
    'servertrack_pinterest_pixel_id',
    'servertrack_pinterest_access_token',
    'servertrack_linkedin_enabled',
    'servertrack_linkedin_pixel_id',
    'servertrack_linkedin_access_token',
];
foreach ( $servertrack_options as $opt ) {
    delete_option( $opt );
}

// ── 2. Remove classic post meta ───────────────────────────────────────────────
$meta_keys = [
    '_servertrack_event_id',
    '_servertrack_server_sent',
    '_servertrack_refunded',
    '_servertrack_consent',
    '_servertrack_fbc',
    '_servertrack_fbp',
    '_servertrack_fbclid',
    '_servertrack_ttclid',
    '_servertrack_gclid',
    '_servertrack_api_sent',
    '_servertrack_renewal_sent',
    '_servertrack_consent_v2',
];
foreach ( $meta_keys as $meta_key ) {
    delete_post_meta_by_key( $meta_key );
}

// ── 3. Remove HPOS order meta ─────────────────────────────────────────────────
if ( class_exists( 'WooCommerce' ) && function_exists( 'wc_get_orders' ) ) {
    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
    $hpos_table = $wpdb->prefix . 'wc_orders_meta';
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $hpos_table ) ) === $hpos_table ) {
        foreach ( $meta_keys as $meta_key ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->delete( $hpos_table, [ 'meta_key' => $meta_key ], [ '%s' ] );
        }
    }
}

// ── 4. Clear retry transients ─────────────────────────────────────────────────
global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
    "DELETE FROM {$wpdb->options}
     WHERE option_name LIKE '\_transient\_servertrack\_retry\_%'
        OR option_name LIKE '\_transient\_timeout\_servertrack\_retry\_%'"
);

// ── 5. Clear cart abandonment transients / options ────────────────────────────
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
    "DELETE FROM {$wpdb->options}
     WHERE option_name LIKE '\_transient\_servertrack\_abandon\_%'
        OR option_name LIKE '\_transient\_timeout\_servertrack\_abandon\_%'
        OR option_name LIKE 'servertrack\_abandon\_%'"
);

// ── 6. Cancel all ServerTrack cron hooks ──────────────────────────────────────
$cron_hooks = [
    'servertrack_process_retry_queue',
    'servertrack_check_abandonment',
];
foreach ( $cron_hooks as $hook ) {
    wp_clear_scheduled_hook( $hook );
    wp_unschedule_hook( $hook );
}

// ── 7. Drop Custom Dedup Table ──────────────────────────────────────────────────
global $wpdb;
$table_name = $wpdb->prefix . 'servertrack_dedup';
$wpdb->query( "DROP TABLE IF EXISTS {$table_name}" );
