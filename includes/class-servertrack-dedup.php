<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ServerTrack_Dedup  v2.4
 *
 * Handles event ID generation, storage, and deduplication flags
 * for both WooCommerce HPOS (custom orders table) and legacy post meta.
 *
 * Changes in v2.4:
 *   FIX BUG-FIX-3 — Added already_sent() as a public alias of was_sent().
 *     ServerTrack_Source_WooCommerce (v3.3.1) calls
 *     ServerTrack_Dedup::already_sent( $key, $platform ) in multiple places:
 *       handle_purchase(), handle_full_refund(), handle_order_status_change(),
 *       fire_add_to_wishlist_event(), handle_partial_refund().
 *     The method did not exist in any prior version of Dedup — only was_sent()
 *     existed, which takes an integer $order_id. already_sent() accepts either
 *     an integer order ID or a string dedup key, routing to was_sent() for
 *     integer-castable keys and to the options-based path for string keys.
 *     Without this method every WooCommerce CAPI event caused a PHP fatal:
 *     "Call to undefined method ServerTrack_Dedup::already_sent()".
 *
 * Changes in v2.3:
 *   - Added exists() — checks a non-order wp_options dedup key.
 *   - Added set()   — writes a non-order wp_options dedup key.
 *
 * Changes in v2.2:
 *   - Added reset_for_order() — clears the dedup flags and event ID for a
 *     given order_id.
 *   - Added reset_event_key() — clears a non-order dedup key stored in
 *     wp_options.
 *
 * CRITICAL FIX (v2.1):
 *   generate_event_id() now produces UUID v4 via wp_generate_uuid4().
 *   update_meta() only calls save() when value has changed.
 */
class ServerTrack_Dedup {

    // Options-based dedup key prefix (used for non-order events)
    const OPTIONS_PREFIX = 'servertrack_dedup_';

    // ── HPOS detection (cached per request) ──────────────────────────────

    private static ?bool $hpos_enabled = null;

    private static function is_hpos(): bool {
        if ( null !== self::$hpos_enabled ) {
            return self::$hpos_enabled;
        }

        if (
            class_exists( 'Automattic\\WooCommerce\\Internal\\DataStores\\Orders\\CustomOrdersTableController' )
            && function_exists( 'wc_get_container' )
        ) {
            try {
                $controller = wc_get_container()->get(
                    Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class
                );
                self::$hpos_enabled = (bool) $controller->custom_orders_table_usage_is_enabled();
            } catch ( \Exception $e ) {
                self::$hpos_enabled = false;
            }
        } else {
            self::$hpos_enabled = false;
        }

        return self::$hpos_enabled;
    }

    // ── Order object helper ───────────────────────────────────────────────

    private static function get_order( int $order_id ): ?\WC_Abstract_Order {
        if ( ! function_exists( 'wc_get_order' ) ) {
            return null;
        }
        $order = wc_get_order( $order_id );
        return ( $order instanceof \WC_Abstract_Order ) ? $order : null;
    }

    // ── Meta read / write wrappers ────────────────────────────────────────

    private static function get_meta( int $order_id, string $key ) {
        if ( self::is_hpos() ) {
            $order = self::get_order( $order_id );
            if ( ! $order ) {
                return '';
            }
            return $order->get_meta( $key, true );
        }

        return get_post_meta( $order_id, $key, true );
    }

    /**
     * Only persists if the value has changed — avoids redundant HPOS save() calls.
     */
    private static function update_meta( int $order_id, string $key, $value ): void {
        if ( self::is_hpos() ) {
            $order = self::get_order( $order_id );
            if ( ! $order ) {
                return;
            }
            $existing = $order->get_meta( $key, true );
            if ( $existing === $value ) {
                return;
            }
            $order->update_meta_data( $key, $value );
            $order->save();
            return;
        }

        update_post_meta( $order_id, $key, $value );
    }

    private static function delete_meta( int $order_id, string $key ): void {
        if ( self::is_hpos() ) {
            $order = self::get_order( $order_id );
            if ( ! $order ) {
                return;
            }
            $order->delete_meta_data( $key );
            $order->save();
            return;
        }

        delete_post_meta( $order_id, $key );
    }

    // ── Public API ────────────────────────────────────────────────────────

    /**
     * Generates a collision-safe UUID v4 event ID.
     *
     * CRITICAL FIX (v2.1): Previously used md5().
     * Now uses wp_generate_uuid4() (RFC 4122 compliant, 128-bit random).
     * For deterministic IDs, context_string is hashed with the site secret.
     *
     * @param string $context_string  Optional seed for deterministic generation.
     */
    public static function generate_event_id( string $context_string = '' ): string {
        if ( '' === $context_string ) {
            return wp_generate_uuid4();
        }

        $hash  = hash( 'sha256', $context_string . '_' . SECURE_AUTH_KEY, true );
        $bytes = substr( $hash, 0, 16 );

        $bytes[6] = chr( ( ord( $bytes[6] ) & 0x0f ) | 0x40 );
        $bytes[8] = chr( ( ord( $bytes[8] ) & 0x3f ) | 0x80 );

        return vsprintf(
            '%s%s-%s-%s-%s-%s%s%s',
            str_split( bin2hex( $bytes ), 4 )
        );
    }

    /**
     * Retrieves the stored event ID for an order.
     */
    public static function get_event_id( int $order_id ): string {
        $event_id = self::get_meta( $order_id, '_servertrack_event_id' );
        return is_string( $event_id ) ? $event_id : '';
    }

    /**
     * Persists the event ID to order meta.
     */
    public static function store_event_id( int $order_id, string $event_id ): void {
        self::update_meta( $order_id, '_servertrack_event_id', $event_id );
    }

    /**
     * Marks a platform as having successfully received a server event.
     *
     * @param string $platform  'meta' | 'google' | 'tiktok'
     */
    public static function mark_as_sent( int $order_id, string $platform ): void {
        $sent = self::get_meta( $order_id, '_servertrack_server_sent' );
        if ( ! is_array( $sent ) ) {
            $sent = [];
        }
        if ( in_array( $platform, $sent, true ) ) {
            return;
        }
        $sent[] = $platform;
        self::update_meta( $order_id, '_servertrack_server_sent', $sent );
    }

    /**
     * Returns true if a server event has already been sent to the given platform.
     *
     * @param int    $order_id
     * @param string $platform  'meta' | 'google' | 'tiktok'
     */
    public static function was_sent( int $order_id, string $platform ): bool {
        $sent = self::get_meta( $order_id, '_servertrack_server_sent' );
        if ( ! is_array( $sent ) ) {
            return false;
        }
        return in_array( $platform, $sent, true );
    }

    /**
     * already_sent() — poly-dispatch alias added in v2.4 (BUG-FIX-3).
     *
     * ServerTrack_Source_WooCommerce calls already_sent() with two different
     * $key types:
     *   • Integer-castable strings / ints  → order-meta path (was_sent)
     *   • Arbitrary string keys like "full_refund_42",
     *     "order_status_42_on-hold", "wishlist_yith_7_99", etc.
     *     → options-based path (exists)
     *
     * Routing logic:
     *   If $key is numeric (ctype_digit or is_int), delegate to was_sent().
     *   Otherwise treat as a string dedup key and look up via options.
     *
     * @param int|string $key       Order ID (int) or string dedup key
     * @param string     $platform  'meta' | 'google' | 'tiktok'
     * @return bool
     */
    public static function already_sent( $key, string $platform ): bool {
        if ( is_int( $key ) || ( is_string( $key ) && ctype_digit( $key ) ) ) {
            return self::was_sent( (int) $key, $platform );
        }
        // String dedup key — check per-platform option.
        return (bool) get_option(
            self::OPTIONS_PREFIX . sanitize_key( $key . '_' . $platform ),
            false
        );
    }

    /**
     * Mark a string dedup key + platform as sent (companion to already_sent).
     *
     * Called internally by dispatch_to_all / dispatch_to_platforms after a
     * successful send for non-integer dedup keys.
     *
     * @param string $key      String dedup key (e.g. 'full_refund_42')
     * @param string $platform 'meta' | 'google' | 'tiktok'
     */
    public static function mark_string_sent( string $key, string $platform ): void {
        update_option(
            self::OPTIONS_PREFIX . sanitize_key( $key . '_' . $platform ),
            1,
            false
        );
    }

    /**
     * Polymorphic mark_sent() — routes based on key type (int order_id vs string dedup_key).
     * BUG-FIX (v2.5): Added to support ServerTrack_Core::dispatch_to_platforms() marking logic.
     *
     * @param int|string $key       Order ID (int) or string dedup key
     * @param string     $platform  'meta' | 'google' | 'tiktok'
     */
    public static function mark_sent( $key, string $platform ): void {
        if ( is_int( $key ) || ( is_string( $key ) && ctype_digit( $key ) ) ) {
            self::mark_as_sent( (int) $key, $platform );
        } else {
            self::mark_string_sent( (string) $key, $platform );
        }
    }

    /**
     * Reset all dedup flags and the event ID for a given order.
     *
     * Used by: wp servertrack test-purchase <order_id>
     *
     * @param int $order_id  WooCommerce order ID.
     */
    public static function reset_for_order( int $order_id ): void {
        self::delete_meta( $order_id, '_servertrack_event_id' );
        self::delete_meta( $order_id, '_servertrack_server_sent' );
    }

    // ── Options-based dedup (for non-order events e.g. Offline Conversion) ──

    /**
     * Check whether a non-order dedup key has been marked as sent.
     *
     * FIX (v2.3): This method was called by ServerTrack_OfflineConversion
     * but never existed, causing a PHP fatal error and preventing the
     * offline dedup guard from running entirely.
     *
     * @param string $key  e.g. 'offline_123'
     * @return bool
     */
    public static function exists( string $key ): bool {
        return (bool) get_option( self::OPTIONS_PREFIX . sanitize_key( $key ), false );
    }

    /**
     * Mark a non-order dedup key as sent.
     *
     * FIX (v2.3): Paired with exists() — both were missing.
     *
     * @param string $key  e.g. 'offline_123'
     */
    public static function set( string $key ): void {
        update_option( self::OPTIONS_PREFIX . sanitize_key( $key ), 1, false );
    }

    /**
     * Remove a non-order dedup key (e.g. to allow re-sending in testing).
     *
     * @param string $key  e.g. 'offline_123'
     */
    public static function reset_event_key( string $key ): void {
        delete_option( self::OPTIONS_PREFIX . sanitize_key( $key ) );
    }
}
