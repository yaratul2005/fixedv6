<?php
/**
 * ServerTrack — Consent V2 Module  v1.2
 *
 * Reads and writes the per-order TCF/consent record used by the CAPI
 * sender to decide whether events may be forwarded to each platform.
 *
 * v1.2 changes (M-4 fix):
 *   get_consent_for_order() previously returned true (opted-in default)
 *   when the order meta key '_servertrack_consent_v2' was absent — i.e.
 *   for ALL orders placed before this module was installed, or for any
 *   order where consent was not explicitly captured at checkout.
 *
 *   This is a privacy violation: the absence of an explicit consent
 *   record must be treated as NO consent, not YES consent.
 *
 *   Fix: missing meta now returns false. Explicit true (string '1' or
 *   boolean true) is the only value treated as consent granted.
 *
 * @package ServerTrack
 * @since   5.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ServerTrack_ConsentV2 {

	/** Order meta key used to store the per-order consent record. */
	const META_KEY = '_servertrack_consent_v2';

	/**
	 * Store consent state for an order at checkout time.
	 *
	 * Called from the WooCommerce checkout hook after order creation.
	 * Reads the live cookie / TCF signal at the point the customer
	 * submits the checkout form and persists it to order meta so it
	 * is available in async/cron context later.
	 *
	 * @param int $order_id
	 */
	public static function capture_for_order( int $order_id ): void {
		$granted = self::read_live_consent_signal();

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$order->update_meta_data( self::META_KEY, $granted ? '1' : '0' );
		$order->save();
	}

	/**
	 * Returns whether CAPI events may be sent for the given order.
	 *
	 * M-4 FIX (v1.2):
	 *   Previous logic: `return (bool) $meta ?: true;`
	 *   This returned true when meta was absent (falsy empty string from
	 *   get_meta()). Every order placed before the consent module was
	 *   installed was therefore treated as opted-in.
	 *
	 *   Correct privacy-safe logic:
	 *   - Absent meta (key never written)  → false  (no consent on record)
	 *   - Meta == '0' or false             → false  (explicit opt-out)
	 *   - Meta == '1' or true              → true   (explicit opt-in)
	 *
	 * @param int $order_id
	 * @return bool
	 */
	public static function get_consent_for_order( int $order_id ): bool {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return false;
		}

		$meta = $order->get_meta( self::META_KEY, true );

		// M-4 FIX: key_exists check — distinguish "not set" from "set to 0".
		// get_meta returns '' when the key is absent; '1' or '0' when set.
		if ( '' === $meta || null === $meta || false === $meta ) {
			// No consent record on file — treat as no consent.
			return false;
		}

		return '1' === (string) $meta;
	}

	/**
	 * Read the live consent signal from the browser at checkout time.
	 *
	 * Priority:
	 *   1. GDPR cookie from a recognised CMP (Complianz, CookieYes, etc.)
	 *   2. Custom servertrack_consent cookie
	 *   3. Absence of a CMP → assume consent required but unknown → false
	 *
	 * @return bool
	 */
	private static function read_live_consent_signal(): bool {
		// Complianz
		if ( function_exists( 'cmplz_has_consent' ) ) {
			return (bool) cmplz_has_consent( 'marketing' );
		}

		// CookieYes
		$cookieyes = $_COOKIE['cookieyes-consent'] ?? '';
		if ( $cookieyes ) {
			return str_contains( $cookieyes, 'advertisement:yes' );
		}

		// ServerTrack custom consent cookie
		$custom = $_COOKIE['servertrack_consent'] ?? '';
		if ( '' !== $custom ) {
			return '1' === $custom;
		}

		// No recognised consent signal — default to false (privacy-safe).
		return false;
	}
}
