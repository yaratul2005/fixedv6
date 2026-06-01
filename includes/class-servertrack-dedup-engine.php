<?php
if ( ! defined( 'ABSPATH' ) ) {
    die();
}


/**
 * ServerTrack_DedupEngine
 *
 * Module 4: Advanced Deduplication Engine
 * Solves duplicate tracking issues for non-order events (ViewContent, AddToCart, etc.)
 * Uses transient-based storage keyed by a 5-minute bucketed fingerprint to merge identical
 * events from both browser and server sources automatically.
 */
class ServerTrack_DedupEngine {

    const BUCKET_SIZE = 300; // 5 minutes

    public static function init(): void {
        // Init hooks if needed
    }

    /**
     * Generates a unique deduplication fingerprint.
     */
    public static function generate_fingerprint( string $event_name, string $external_id, string $product_id = '' ): string {
        $timestamp_bucket = floor( time() / self::BUCKET_SIZE );
        $payload = $event_name . '|' . $external_id . '|' . $product_id . '|' . $timestamp_bucket;

        return hash( 'sha256', $payload );
    }

    /**
     * Checks if an event has already been processed and marks it if not.
     * Replaces the fragile wp_options approach for non-order events.
     */
    public static function check_and_mark( string $event_name, string $external_id, string $product_id = '', string $platform = 'all' ): bool {
        $fingerprint = self::generate_fingerprint( $event_name, $external_id, $product_id );
        $cache_key = 'st_dedup_' . $fingerprint . '_' . $platform;

        // Try object cache first (Redis/Memcached if available)
        $found = false;
        $cached = wp_cache_get( $cache_key, 'servertrack_dedup', false, $found );

        if ( $found && $cached ) {
            return true; // Already processed
        }

        // Fallback to transient if object cache is not persistent,
        // but wp_cache_set acts as a local memory array even without persistent cache,
        // which helps during a single request lifecycle anyway.
        // We'll still use transient as the cross-request fallback if needed, but primarily wp_cache.
        if ( get_transient( $cache_key ) ) {
            // Populate cache so next time we don't hit DB
            wp_cache_set( $cache_key, true, 'servertrack_dedup', self::BUCKET_SIZE * 2 );
            return true;
        }

        // Mark as processed (valid for 10 minutes to cover the bucket overlap safely)
        wp_cache_set( $cache_key, true, 'servertrack_dedup', self::BUCKET_SIZE * 2 );
        set_transient( $cache_key, true, self::BUCKET_SIZE * 2 );

        return false;
    }

    /**
     * Generates an idempotency key to prevent double-firing in retry queues.
     */
    public static function generate_idempotency_key( ServerTrack_Event $event ): string {
        return hash_hmac( 'sha256', wp_json_encode( $event ), SECURE_AUTH_KEY );
    }
}