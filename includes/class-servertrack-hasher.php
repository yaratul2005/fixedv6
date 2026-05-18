<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ServerTrack_Hasher  v2.2
 *
 * v2.2  2026-05-15  Added event_id() method — was called in 10 places across
 *   Source_WooCommerce (AddToCart, Purchase, ViewContent, InitiateCheckout,
 *   AddPaymentInfo, CompleteRegistration, AddToWishlist, PartialRefund,
 *   FullRefund, OrderStatusChange) but was never defined. Caused PHP Fatal
 *   Error on every WooCommerce event, including Add to Cart.
 *
 * v2.1  E.164 phone normalisation fix (see hash_phone() docblock).
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

    public static function hash_name( string $name ): string {
        $normalized = preg_replace( '/[^a-z0-9]/', '', strtolower( trim( $name ) ) );
        return hash( 'sha256', $normalized );
    }

    public static function hash_city( string $city ): string {
        $normalized = preg_replace( '/[^a-z0-9]/', '', strtolower( trim( $city ) ) );
        return hash( 'sha256', $normalized );
    }

    public static function hash_state( string $state ): string {
        $normalized = preg_replace( '/[^a-z0-9]/', '', strtolower( trim( $state ) ) );
        return hash( 'sha256', $normalized );
    }

    public static function hash_zip( string $zip ): string {
        $normalized = preg_replace( '/[\s\-]/', '', strtolower( trim( $zip ) ) );
        return hash( 'sha256', $normalized );
    }

    public static function hash_country( string $country ): string {
        $normalized = preg_replace( '/[^a-z]/', '', strtolower( trim( $country ) ) );
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
        $digits = preg_replace( '/[^0-9]/', '', $phone );

        if ( '' === $digits ) {
            return '';
        }

        if ( '' !== $country_code ) {
            $cc = ltrim( preg_replace( '/[^0-9]/', '', $country_code ), '0' );

            if ( '' !== $cc ) {
                if ( strpos( ltrim( $digits, '0' ), $cc ) === 0 ) {
                    $e164 = ltrim( $digits, '0' );
                } else {
                    $national = ltrim( $digits, '0' );
                    $e164     = $cc . $national;
                }
            } else {
                $e164 = ltrim( $digits, '0' );
            }
        } else {
            $e164 = ltrim( $digits, '0' );
        }

        return hash( 'sha256', $e164 );
    }

    /**
     * Hash an email address.
     * Normalises to lowercase + trim.
     */
    public static function hash_email( string $email ): string {
        return self::hash( $email );
    }

    /**
     * Generate a stable, unique event ID for CAPI deduplication.
     *
     * Builds a namespaced seed from the event name + context, then delegates
     * to ServerTrack_Dedup::generate_event_id() for the final hash/UUID output.
     *
     * Called by ServerTrack_Source_WooCommerce for all 10 CAPI events:
     *   Purchase, ViewContent, AddToCart, InitiateCheckout, AddPaymentInfo,
     *   CompleteRegistration, AddToWishlist, PartialRefund, FullRefund,
     *   Lead / Contact / SubmitForm (OrderStatusChange).
     *
     * @param string     $event_name  CAPI event name, e.g. 'Purchase', 'AddToCart'.
     * @param int|string $context     Disambiguating context — order ID, cart item key,
     *                                user ID, or any unique string for this event instance.
     * @return string  Event ID string safe for Meta / TikTok / Google CAPI payloads.
     */
    public static function event_id( string $event_name, $context ): string {
        $seed = $event_name . '_' . $context;
        return ServerTrack_Dedup::generate_event_id( $seed );
    }
}
