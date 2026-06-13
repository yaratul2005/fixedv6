<?php
/**
 * ServerTrack — Offline Conversion Uploader  v3.2
 *
 * Batches completed WooCommerce orders and uploads them as offline
 * conversion events to Meta's Offline Conversions API.
 *
 * v3.2 changes (BUG-03 fix):
 *   dispatch() referenced Graph API v19.0, deprecated May 2025 and subject
 *   to sunset. After sunset all offline conversion POSTs return HTTP 400,
 *   silently swallowed by the Throwable catch — making offline conversions
 *   fail with no clear log reason.
 *
 *   Fix: version bumped to v21.0 (current stable as of 2026).
 *   API version extracted into class constant GRAPH_API_VERSION so future
 *   upgrades require a one-line change, not a search-and-replace.
 *
 * v3.2 also fixes M-3 minor:
 *   build_event_payload() previously only sent em (email) and ph (phone)
 *   in user_data. Meta's offline match scoring also weights fn, ln, ct,
 *   st, zp, country. These are now included when available, increasing
 *   offline match quality on Meta's side.
 *
 * v3.1 changes (M-3 fix):
 *   send_batch() caught Throwable but only logged the exception message.
 *   Now logs structured context: platform, batch_size, full trace.
 *
 * @package ServerTrack
 * @since   4.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ServerTrack_OfflineConversion {

	/** How many orders to batch per API call. */
	const BATCH_SIZE = 50;

	/**
	 * Meta Graph API version.
	 *
	 * BUG-03 FIX: was v19.0 (deprecated May 2025, at risk of sunset).
	 * Bumped to v21.0 (current stable, supported through at least mid-2027).
	 * Update this constant when Meta releases a new stable version.
	 */
	const GRAPH_API_VERSION = 'v21.0';

	/** WooCommerce order statuses that count as a completed offline conversion. */
	const COMPLETED_STATUSES = [ 'completed', 'processing' ];

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		add_action( 'servertrack_offline_upload_batch', [ __CLASS__, 'run_scheduled_upload' ] );
		add_action( 'woocommerce_order_status_completed', [ __CLASS__, 'maybe_queue_order' ], 10, 2 );
	}

	/**
	 * Schedule a one-time upload for an order when its status changes to completed.
	 *
	 * @param int      $order_id
	 * @param WC_Order $order
	 */
	public static function maybe_queue_order( int $order_id, WC_Order $order ): void {
		$key = 'offline_queued_' . $order_id;

		if ( ServerTrack_Dedup::exists( $key ) ) {
			return;
		}

		ServerTrack_Dedup::set( $key );

		wp_schedule_single_event(
			time() + 300,
			'servertrack_offline_upload_batch',
			[ [ 'order_id' => $order_id ] ]
		);
	}

	/**
	 * Run an upload batch from the cron queue.
	 *
	 * @param array $args  e.g. [ 'order_id' => 123 ]
	 */
	public static function run_scheduled_upload( array $args ): void {
		$order_id = isset( $args['order_id'] ) ? absint( $args['order_id'] ) : 0;
		if ( ! $order_id ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		self::send_batch( [ $order ], 'meta' );
	}

	/**
	 * Send a batch of orders as offline conversion events.
	 *
	 * @param WC_Order[] $orders
	 * @param string     $platform  'meta'
	 */
	public static function send_batch( array $orders, string $platform ): void {
		if ( empty( $orders ) ) {
			return;
		}

		$events = [];
		foreach ( $orders as $order ) {
			if ( ! $order instanceof WC_Abstract_Order ) {
				continue;
			}
			$events[] = self::build_event_payload( $order, $platform );
		}

		if ( empty( $events ) ) {
			return;
		}

		try {
			self::dispatch( $events, $platform );

			ServerTrack_Logger::info(
				sprintf( 'Offline batch sent: %d events to %s.', count( $events ), $platform )
			);

		} catch ( \Throwable $e ) {
			ServerTrack_Logger::error(
				sprintf(
					'Offline batch failed [platform=%s batch_size=%d]: %s',
					$platform,
					count( $events ),
					$e->getMessage()
				),
				[
					'platform'   => $platform,
					'batch_size' => count( $events ),
					'exception'  => get_class( $e ),
					'trace'      => $e->getTraceAsString(),
				]
			);
		}
	}

	/**
	 * Build an offline event payload for a single order.
	 *
	 * M-3 minor fix (v3.2):
	 *   Previously only em + ph were sent in user_data.
	 *   Meta's offline match scoring also weights fn, ln, ct, st, zp, country.
	 *   These fields are now hashed and included when available, improving
	 *   offline match quality.
	 *
	 * @param WC_Abstract_Order $order
	 * @param string            $platform
	 * @return array
	 */
	private static function build_event_payload( WC_Abstract_Order $order, string $platform ): array {
		$order_id = $order->get_id();
		$total    = (float) $order->get_total();
		$currency = strtoupper( get_woocommerce_currency() );

		$user_data = [];

		// Email (highest weight)
		$email = $order->get_billing_email();
		if ( $email ) {
			$user_data['em'] = ServerTrack_Hasher::hash_email( $email );
		}

		// Phone
		$phone   = $order->get_billing_phone();
		$country = $order->get_billing_country();
		if ( $phone ) {
			$user_data['ph'] = ServerTrack_Hasher::hash_phone( $phone, $country );
		}

		// Name fields
		$first_name = $order->get_billing_first_name();
		if ( $first_name ) {
			$user_data['fn'] = ServerTrack_Hasher::hash( strtolower( trim( $first_name ) ) );
		}
		$last_name = $order->get_billing_last_name();
		if ( $last_name ) {
			$user_data['ln'] = ServerTrack_Hasher::hash( strtolower( trim( $last_name ) ) );
		}

		// Location fields
		$city = $order->get_billing_city();
		if ( $city ) {
			$user_data['ct'] = ServerTrack_Hasher::hash( strtolower( trim( $city ) ) );
		}
		$state = $order->get_billing_state();
		if ( $state ) {
			$user_data['st'] = ServerTrack_Hasher::hash( strtolower( trim( $state ) ) );
		}
		$zip = $order->get_billing_postcode();
		if ( $zip ) {
			$user_data['zp'] = ServerTrack_Hasher::hash( preg_replace( '/\s+/', '', strtolower( $zip ) ) );
		}
		if ( $country ) {
			$user_data['country'] = ServerTrack_Hasher::hash( strtolower( trim( $country ) ) );
		}

		return [
			'event_name'  => 'Purchase',
			'event_time'  => $order->get_date_created() ? $order->get_date_created()->getTimestamp() : time(),
			'event_id'    => ServerTrack_Dedup::generate_event_id( 'offline_purchase_' . $order_id ),
			'value'       => $total,
			'currency'    => $currency,
			'order_id'    => $order_id,
			'platform'    => $platform,
			'user_data'   => $user_data,
		];
	}

	/**
	 * Dispatch an array of event payloads to the given platform API.
	 *
	 * BUG-03 FIX: was hardcoded to v19.0 — now uses GRAPH_API_VERSION constant
	 * (currently v21.0). Throws on HTTP error or non-2xx response.
	 *
	 * @param array  $events
	 * @param string $platform
	 * @throws \RuntimeException on API error
	 */
	private static function dispatch( array $events, string $platform ): void {
		$settings = get_option( 'servertrack_settings', [] );

		switch ( $platform ) {
			case 'meta':
				$access_token = $settings['meta_access_token'] ?? '';
				$dataset_id   = $settings['meta_offline_dataset_id'] ?? '';
				if ( ! $access_token || ! $dataset_id ) {
					throw new \RuntimeException( 'Meta offline: missing access_token or dataset_id.' );
				}
				// BUG-03 FIX: v19.0 → GRAPH_API_VERSION constant (v21.0).
				$url      = sprintf(
					'https://graph.facebook.com/%s/%s/events',
					self::GRAPH_API_VERSION,
					$dataset_id
				);
				$response = wp_remote_post( $url, [
					'body'    => wp_json_encode( [ 'data' => $events, 'access_token' => $access_token ] ),
					'headers' => [ 'Content-Type' => 'application/json' ],
					'timeout' => 15,
				] );
				break;

			default:
				throw new \RuntimeException( "Unknown platform: {$platform}" );
		}

		if ( is_wp_error( $response ) ) {
			throw new \RuntimeException( $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			$body = wp_remote_retrieve_body( $response );
			throw new \RuntimeException( "API returned HTTP {$code}: {$body}" );
		}
	}
}
