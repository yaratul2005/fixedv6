<?php
/**
 * ServerTrack — Webhook Outbound (Feature #9)  v1.1
 *
 * Fires an outbound webhook to any configured URL every time ServerTrack
 * logs a CAPI event.
 *
 * v1.1 changes (L-4 fix):
 *   deliver_webhook() previously re-read servertrack_webhook_secret from
 *   wp_options at delivery time (2+ seconds after the event fired).
 *   If an admin changed the secret in the gap between event and delivery,
 *   the HMAC signature in X-ServerTrack-Signature would be computed with
 *   the NEW secret while the receiver still expected the OLD secret —
 *   causing signature verification failures on the remote end.
 *
 *   Fix: maybe_fire_webhook() reads the secret once at schedule time and
 *   passes it as a parameter to the cron args. deliver_webhook() uses the
 *   passed-in secret, never re-reading from options.
 *
 * @package ServerTrack
 * @since   6.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ServerTrack_Webhook {

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		add_action( 'servertrack_event_logged',  [ __CLASS__, 'maybe_fire_webhook' ], 10, 5 );
		add_action( 'servertrack_deliver_webhook', [ __CLASS__, 'deliver_webhook' ],   10, 7 );
	}

	/**
	 * Decide whether to fire the webhook and dispatch async.
	 *
	 * L-4 FIX (v1.1): Secret is captured HERE at event time and passed to
	 * deliver_webhook() via cron args. Previously deliver_webhook() re-read
	 * the secret from options at delivery time, meaning a secret rotation
	 * between schedule and delivery produced wrong HMAC signatures.
	 *
	 * @param string $platform
	 * @param string $event_name
	 * @param int    $order_id
	 * @param string $status
	 * @param array  $emq
	 */
	public static function maybe_fire_webhook(
		string $platform,
		string $event_name,
		int    $order_id,
		string $status,
		array  $emq
	): void {
		if ( ! self::is_enabled() ) {
			return;
		}

		$url = trim( (string) get_option( 'servertrack_webhook_url', '' ) );
		if ( ! $url || ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return;
		}

		$allowed_events = self::get_allowed_events();
		if ( ! empty( $allowed_events ) && ! in_array( $event_name, $allowed_events, true ) ) {
			return;
		}

		// L-4 FIX: Read secret once at schedule time so the HMAC is computed
		// with the same secret that was active when the event fired.
		$secret = (string) get_option( 'servertrack_webhook_secret', '' );

		wp_schedule_single_event( time() + 2, 'servertrack_deliver_webhook', [
			$platform, $event_name, $order_id, $status, $emq, $url, $secret,
		] );
	}

	/**
	 * Async cron handler: build payload and POST to webhook URL.
	 *
	 * L-4 FIX (v1.1): $secret is now a parameter, not an options re-read.
	 * Signature: (..., string $url, string $secret) — 7 args total.
	 *
	 * @param string $platform
	 * @param string $event_name
	 * @param int    $order_id
	 * @param string $status
	 * @param array  $emq
	 * @param string $url
	 * @param string $secret   L-4 FIX: captured at schedule time, not re-read here.
	 */
	public static function deliver_webhook(
		string $platform,
		string $event_name,
		int    $order_id,
		string $status,
		array  $emq,
		string $url,
		string $secret
	): void {
		$payload = [
			'event'     => $event_name,
			'platform'  => $platform,
			'order_id'  => $order_id,
			'status'    => $status,
			'emq'       => $emq,
			'timestamp' => time(),
			'site_url'  => get_site_url(),
			'plugin'    => 'ServerTrack/' . SERVERTRACK_VERSION,
		];

		$payload  = apply_filters( 'servertrack_webhook_payload', $payload );
		$raw_body = wp_json_encode( $payload );

		$headers = [
			'Content-Type'           => 'application/json',
			'X-ServerTrack-Version'  => SERVERTRACK_VERSION,
			'X-ServerTrack-Event'    => $event_name,
			'X-ServerTrack-Platform' => $platform,
		];

		if ( $secret ) {
			$headers['X-ServerTrack-Signature'] = 'sha256=' . hash_hmac( 'sha256', $raw_body, $secret );
		}

		$response = wp_remote_post( $url, [
			'headers'   => $headers,
			'body'      => $raw_body,
			'timeout'   => 10,
			'blocking'  => true,
			'sslverify' => true,
		] );

		if ( get_option( 'servertrack_debug_mode' ) ) {
			$http_code  = is_wp_error( $response )
				? 0
				: wp_remote_retrieve_response_code( $response );
			$log_status = ( $http_code >= 200 && $http_code < 300 ) ? 'success' : 'error';

			ServerTrack_Logger::log(
				$log_status,
				'webhook',
				'Webhook delivery → ' . $url,
				(string) $http_code,
				'',
				$order_id,
				$event_name
			);
		}
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Helpers
	// ─────────────────────────────────────────────────────────────────────────

	public static function is_enabled(): bool {
		return (bool) get_option( 'servertrack_webhook_enabled', 0 );
	}

	/**
	 * @return string[]
	 */
	public static function get_allowed_events(): array {
		$raw = (string) get_option( 'servertrack_webhook_events', '' );
		if ( ! $raw ) {
			return [];
		}
		return array_filter( array_map( 'trim', explode( ',', $raw ) ) );
	}

	/**
	 * Send a test webhook to verify the endpoint is reachable.
	 *
	 * @param string $url
	 * @param string $secret
	 * @return array { success: bool, message: string, http_code: int }
	 */
	public static function send_test( string $url, string $secret = '' ): array {
		$payload  = [
			'event'     => 'Test',
			'platform'  => 'servertrack',
			'order_id'  => 0,
			'status'    => 'success',
			'emq'       => [],
			'timestamp' => time(),
			'site_url'  => get_site_url(),
			'plugin'    => 'ServerTrack/' . SERVERTRACK_VERSION,
			'message'   => 'This is a test webhook from ServerTrack.',
		];
		$raw_body = wp_json_encode( $payload );

		$headers = [
			'Content-Type'          => 'application/json',
			'X-ServerTrack-Version' => SERVERTRACK_VERSION,
			'X-ServerTrack-Event'   => 'Test',
		];
		if ( $secret ) {
			$headers['X-ServerTrack-Signature'] = 'sha256=' . hash_hmac( 'sha256', $raw_body, $secret );
		}

		$response = wp_remote_post( $url, [
			'headers'  => $headers,
			'body'     => $raw_body,
			'timeout'  => 15,
			'blocking' => true,
		] );

		if ( is_wp_error( $response ) ) {
			return [ 'success' => false, 'message' => $response->get_error_message(), 'http_code' => 0 ];
		}

		$code = wp_remote_retrieve_response_code( $response );
		return [
			'success'   => $code >= 200 && $code < 300,
			'message'   => wp_remote_retrieve_response_message( $response ),
			'http_code' => $code,
		];
	}
}
