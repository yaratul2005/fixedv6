<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ServerTrack_Hasher  v2.2
 *
 * IMPORTANT FIX (v2.1) — E.164 phone normalisation before hashing:
 *
 *   Previously hash_phone() stripped non-numeric characters and prepended a
 *   country code, but it did NOT enforce E.164 format correctly:
 *
 *     - A Bangladeshi number stored as '01712345678' with country_code = '880'
 *       was hashed as '88001712345678' — WRONG. E.164 is '+8801712345678'
 *       which after stripping '+' and leading zeros becomes '8801712345678'.
 *     - The function used strpos() to check if the number started with the
 *       country code, but strpos() returns 0 (falsy!) when the number DOES
 *       start with the code — so it always prepended, producing double prefixes
 *       like '880880...' for numbers already stored with the country code.
 *
 *   Meta requires phones to be normalised to E.164 (without '+') before SHA-256
 *   hashing. A wrong hash produces zero match signal for that customer.
 *
 *   Fix: hash_phone() now:
 *     1. Strips all non-numeric characters
 *     2. Strips leading zeros (E.164 has none after country code)
 *     3. Checks if the result ALREADY starts with the country code
 *        using === 0 (strict) comparison
 *     4. Only prepends the country code if it is not already present
 *     5. Passes the clean E.164 string (no '+') to SHA-256
 *
 * NEW FIX (v2.2) — BUG-FIX-1: Add missing event_id() method:
 *
 *   ServerTrack_Source_WooCommerce called ServerTrack_Hasher::event_id() in
 *   ~10 places (Purchase, AddToCart, ViewContent, InitiateCheckout,
 *   AddPaymentInfo, CompleteRegistration, AddToWishlist, PartialRefund,
 *   FullRefund, OrderStatusChange) but the method never existed.
 *   Every WooCommerce CAPI event fired a PHP fatal error:
 *   "Call to undefined method ServerTrack_Hasher::event_id()"
 *
 *   Fix: event_id() is now defined. It delegates to
 *   ServerTrack_Dedup::generate_event_id() with a namespaced seed built
 *   from the event name + '_' + the caller-supplied context (order ID,
 *   cart key, user ID, etc.), ensuring each event gets a stable,
 *   reproducible ID that survives page reloads and cron retries.
 */
class ServerTrack_Hasher {

    /**
     * General SHA-256 hash for generic strings (email, name, city, etc.).
     * Normalises to lowercase + trim before hashing, as required by Meta/TikTok.
     */
    public static function hash( string $value ): string {
        $normalized = strtolower( trim( $value ) );
        return hash( 'sha256', $normalized );
    }

    /**
     * Hash a phone number after normalising it to E.164 format.
     *
     * E.164 format (without leading '+'):
     *   • Country code  (no leading zeros)
     *   • Subscriber number (no leading zeros for country, but local zeros kept)
     *   Example: Bangladesh +880 1712345678 → '8801712345678'
     *            US         +1  2125551234  → '12125551234'
     *
     * @param string $phone         Raw phone number (any format).
     * @param string $country_code  Numeric country dialling code WITHOUT '+' or '00'
     *                              e.g. '880' for BD, '1' for US/CA, '44' for GB.
     *                              Pass empty string to skip prepending.
     */
    public static function hash_phone( string $phone, string $country_code = '' ): string {
        // Step 1: strip everything except digits
        $digits = preg_replace( '/[^0-9]/', '', $phone );

        if ( '' === $digits ) {
            return '';
        }

        if ( '' !== $country_code ) {
            $cc = preg_replace( '/[^0-9]/', '', $country_code );

            if ( '' !== $cc ) {
                // Step 2: check if number already starts with the country code.
                // Use === 0 (strict), NOT just strpos() — strpos returns 0 when
                // the string DOES start with the needle, which is falsy in PHP.
                if ( strpos( $digits, $cc ) === 0 ) {
                    // Already has country code — use as-is
                    $e164 = $digits;
                } else {
                    // Strip a leading zero from the national number before prepending.
                    // Most national formats have a leading 0 that E.164 drops.
                    // e.g. BD '01712345678' → strip '0' → '1712345678' → '8801712345678'
                    $national = ltrim( $digits, '0' );
                    $e164     = $cc . $national;
                }
            } else {
                $e164 = $digits;
            }
        } else {
            $e164 = $digits;
        }

        // Step 3: hash the clean E.164 digits string (lowercase of digits = itself)
        return hash( 'sha256', $e164 );
    }

    /**
     * Hash an email address.
     * Normalises to lowercase + trim (RFC 5321 local-part is case-insensitive
     * in practice and Meta/TikTok both require lowercase).
     */
    public static function hash_email( string $email ): string {
        return self::hash( $email );
    }

    /**
     * Generate a stable, reproducible event ID for a CAPI event.
     *
     * BUG-FIX-1 (v2.2): This method was called in ~10 places inside
     * ServerTrack_Source_WooCommerce but was never defined, causing a PHP
     * fatal error on every WooCommerce CAPI event.
     *
     * The ID is built from: event_name + '_' + context (e.g. order ID,
     * cart item key, user ID). Delegates to ServerTrack_Dedup::generate_event_id()
     * so the ID is consistent with dedup storage expectations.
     *
     * @param string          $event_name  CAPI event name, e.g. 'Purchase'.
     * @param string|int      $context     Unique context: order ID, cart key, user ID, etc.
     * @return string                      Stable SHA-256-based event ID string.
     */
    public static function event_id( string $event_name, $context ): string {
        $seed = $event_name . '_' . (string) $context;
        return ServerTrack_Dedup::generate_event_id( $seed );
    }
}
