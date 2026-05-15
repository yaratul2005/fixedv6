<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ServerTrack_Identity  v1.1
 *
 * Feature #1 — Cross-Device Identity Stitching.
 *
 * Stape.io and GTM server containers have ZERO access to your WordPress user
 * table. This class exploits that advantage:
 *
 *   - Every logged-in WP user gets a persistent, stable `servertrack_uid`
 *     stored in user meta. This UID is SHA-256 hashed and sent as external_id
 *     on every CAPI event — giving Meta/Google/TikTok the strongest possible
 *     identity signal across sessions, devices, and browsers.
 *
 *   - When a guest checkout email matches an existing WP account, the order's
 *     external_id is retroactively updated to the real WP user's hashed UID
 *     (not the guest order ID fallback). This closes the cross-device gap:
 *     desktop-logged-in + mobile-guest now resolve to the same identity.
 *
 *   - The UID is generated once per user and never changes, so Meta's identity
 *     graph can merge events across months of browsing history.
 *
 * Changelog
 * ----------
 * v1.1  2026-05-15  BUG-FIX-5: ensure_uid() race condition + misleading comment
 *
 *   The original ensure_uid() implementation:
 *     1. Had a comment claiming it was "Deterministic: same user always gets
 *        same UID on this site" — which was FALSE. It called wp_generate_uuid4(),
 *        a PRNG-based function that produces a different random value on every
 *        invocation.
 *     2. Had a race condition: two concurrent requests for the same new user
 *        each called wp_generate_uuid4() before either saved the meta value,
 *        producing two DIFFERENT UIDs — one was stored, the other silently
 *        discarded. The user could get different external_id values across
 *        platforms depending on which request "won" the race.
 *
 *   Fix: replaced wp_generate_uuid4() with a deterministic hash_hmac() call:
 *     hash_hmac( 'sha256', $user_id . get_site_url(), SECURE_AUTH_KEY )
 *
 *   This is genuinely deterministic: the same user_id + site_url + secret key
 *   always produce the same UID, on every PHP process, simultaneously. The race
 *   condition is eliminated because every concurrent request generates the
 *   identical value — it does not matter which request "wins" the meta write.
 *
 * Usage:
 *   ServerTrack_Identity::init()    — call from Core::init()
 *   ServerTrack_Identity::get_external_id_for_order( $order )  — in WooCommerce source
 *   ServerTrack_Identity::get_external_id_for_user( $user_id ) — for logged-in events
 */
class ServerTrack_Identity {

    const USER_META_KEY = 'servertrack_uid';

    public static function init(): void {
        // When a guest order is placed, try to stitch to existing WP user
        add_action( 'woocommerce_checkout_order_processed', [ self::class, 'stitch_order_to_user' ], 20, 3 );
        // When a new WP user is created, pre-generate their UID immediately
        add_action( 'user_register', [ self::class, 'ensure_uid' ], 10, 1 );
    }

    /**
     * Returns the hashed external_id for a WooCommerce order.
     *
     * Priority:
     *   1. Logged-in customer → hashed stable UID from user meta
     *   2. Guest email matches existing WP user → that user's hashed UID
     *   3. Fallback → hashed order ID (original v4 behaviour)
     *
     * @param WC_Abstract_Order $order
     * @return string SHA-256 hashed external_id
     */
    public static function get_external_id_for_order( WC_Abstract_Order $order ): string {
        // Logged-in customer
        $customer_id = (int) $order->get_customer_id();
        if ( $customer_id > 0 ) {
            return self::get_external_id_for_user( $customer_id );
        }

        // Guest — try to match email to existing WP account
        $email = $order->get_billing_email();
        if ( $email ) {
            $user = get_user_by( 'email', $email );
            if ( $user instanceof WP_User && $user->ID > 0 ) {
                return self::get_external_id_for_user( $user->ID );
            }
        }

        // Final fallback — hashed order ID
        return ServerTrack_Hasher::hash( (string) $order->get_id() );
    }

    /**
     * Returns the stable hashed UID for a WP user.
     * Generates and stores the UID if it doesn't exist yet.
     *
     * @param int $user_id
     * @return string SHA-256 hashed UID
     */
    public static function get_external_id_for_user( int $user_id ): string {
        return ServerTrack_Hasher::hash( self::ensure_uid( $user_id ) );
    }

    /**
     * Ensures a stable UID exists in user meta.
     * Idempotent — safe to call repeatedly from concurrent requests.
     *
     * BUG-FIX-5 (v1.1):
     *   The previous implementation used wp_generate_uuid4() — a PRNG that
     *   produces a different value on every call. Two concurrent requests for
     *   a new user would race to store different UIDs, causing inconsistent
     *   external_id values across platforms.
     *
     *   Fix: use hash_hmac() with SECURE_AUTH_KEY as the HMAC secret.
     *   This is genuinely deterministic — any number of concurrent processes
     *   always derive the exact same UID for the same user_id, so the race
     *   condition is eliminated at the source.
     *
     * @param int $user_id
     * @return string The raw (unhashed) UID string
     */
    public static function ensure_uid( int $user_id ): string {
        $existing = get_user_meta( $user_id, self::USER_META_KEY, true );
        if ( ! empty( $existing ) && is_string( $existing ) ) {
            return $existing;
        }

        // BUG-FIX-5: Deterministic UID — same user_id + site always → same UID.
        // SECURE_AUTH_KEY is a site-specific secret defined in wp-config.php,
        // ensuring the UID is unguessable even if user IDs are public knowledge.
        $uid = hash_hmac( 'sha256', $user_id . get_site_url(), SECURE_AUTH_KEY );

        update_user_meta( $user_id, self::USER_META_KEY, $uid );
        return $uid;
    }

    /**
     * On checkout: if the billing email matches an existing WP user,
     * store that user's hashed UID as order meta so async cron can use it.
     *
     * @param int      $order_id
     * @param array    $posted_data
     * @param WC_Order $order
     */
    public static function stitch_order_to_user( int $order_id, array $posted_data, WC_Order $order ): void {
        // Already a logged-in order — no stitching needed
        if ( (int) $order->get_customer_id() > 0 ) {
            return;
        }

        $email = $order->get_billing_email();
        if ( ! $email ) {
            return;
        }

        $user = get_user_by( 'email', $email );
        if ( ! ( $user instanceof WP_User ) || $user->ID <= 0 ) {
            return;
        }

        // Store stitched external_id on order so async cron reads the
        // correct identity without re-running the email lookup
        $hashed_uid = self::get_external_id_for_user( $user->ID );
        $order->update_meta_data( '_servertrack_external_id', $hashed_uid );
        $order->save_meta_data();

        ServerTrack_Logger::log(
            'success', 'identity',
            'Cross-device stitch: guest email matched WP user #' . $user->ID . '. external_id updated.',
            '', '', $order_id, 'Identity'
        );
    }
}
