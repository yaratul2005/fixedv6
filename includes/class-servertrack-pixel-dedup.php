<?php
/**
 * ServerTrack — Browser Pixel event_id Injection (Feature #5)
 *
 * Closes the server-side ↔ browser-pixel deduplication loop.
 *
 * Problem:
 *   Even with server-side CAPI, many stores still load the Meta browser pixel
 *   (fbq) for fast front-end events (PageView, ViewContent). Without a shared
 *   event_id, Meta counts both the pixel fire AND the CAPI event as separate
 *   conversions — doubling reported purchases.
 *
 * Solution:
 *   1. On WooCommerce order completion, generate a canonical event_id and store
 *      it in order meta: _servertrack_event_id_{event_name}
 *   2. Inject a tiny JS snippet into the Thank You page that calls
 *      fbq('track', 'Purchase', {...}, {eventID: '...'}) using the SAME event_id
 *      that was already sent via CAPI.
 *   3. Meta deduplicates: one conversion counted, not two.
 *
 * Covered events: Purchase (thank-you page), InitiateCheckout (checkout page),
 *                 AddToCart (product page — injected via JS data attribute).
 *
 * Bug #4 fix (v2.1):
 *   inject_purchase_dedup_snippet() now uses a static $fired flag to ensure
 *   the fbq snippet is only emitted once per request.
 *
 * M-1 fix (v2.2):
 *   generate_event_id() was non-deterministic (microtime + random password seed).
 *   IDs differed between CAPI send and pixel injection for the same order,
 *   breaking deduplication entirely. Now uses a deterministic seed:
 *   event_name + order_id + SECURE_AUTH_KEY, formatted as UUID v4 to match
 *   the Dedup::generate_event_id() contract.
 *
 * M-2 fix (v2.2):
 *   REST endpoint /event-id had permission_callback => __return_true, allowing
 *   unauthenticated visitors to enumerate order event IDs via order_id param.
 *   Now requires a valid nonce (servertrack_event_id) for browser requests,
 *   or shop_manager/administrator capability for server-side use.
 *
 * @package ServerTrack
 * @since   6.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ServerTrack_PixelDedup {

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		add_action( 'woocommerce_checkout_order_created',    [ __CLASS__, 'store_purchase_event_id' ], 10, 1 );
		add_action( 'woocommerce_before_checkout_form',      [ __CLASS__, 'inject_initiate_checkout_id' ] );
		add_action( 'woocommerce_before_add_to_cart_button', [ __CLASS__, 'inject_add_to_cart_data' ] );
		add_action( 'woocommerce_thankyou',                  [ __CLASS__, 'inject_purchase_dedup_snippet' ], 10, 1 );
		add_action( 'rest_api_init',                         [ __CLASS__, 'register_rest_endpoint' ] );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Event ID generation & storage
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Generate and store the Purchase event_id at order creation time.
	 *
	 * @param WC_Order $order
	 */
	public static function store_purchase_event_id( WC_Order $order ): void {
		$event_id = self::generate_event_id( 'purchase', $order->get_id() );
		$order->update_meta_data( '_servertrack_event_id_purchase', $event_id );
		$order->save();
	}

	/**
	 * Generate a deterministic, collision-resistant event_id formatted as UUID v4.
	 *
	 * M-1 FIX (v2.2):
	 *   Previous implementation seeded sha256 with microtime() + wp_generate_password().
	 *   This produced a DIFFERENT id on each call, meaning the id stored in order meta
	 *   at checkout never matched the id injected on the thank-you page — breaking
	 *   pixel/CAPI deduplication for 100% of Purchase events.
	 *
	 *   Fix: seed is now deterministic: event_name + context_id + SECURE_AUTH_KEY.
	 *   The result is formatted as RFC 4122 UUID v4 (bits 6 and 8 set correctly)
	 *   to match the Dedup::generate_event_id() contract used by the CAPI sender.
	 *
	 * @param string $event_name  e.g. 'purchase', 'initiatecheckout'
	 * @param int    $context_id  Order ID, session hash, or product ID
	 * @return string  UUID v4 string
	 */
	public static function generate_event_id( string $event_name, int $context_id = 0 ): string {
		$seed  = $event_name . '_' . $context_id . '_' . SECURE_AUTH_KEY;
		$hash  = hash( 'sha256', $seed, true );
		$bytes = substr( $hash, 0, 16 );

		// Set UUID v4 version bits
		$bytes[6] = chr( ( ord( $bytes[6] ) & 0x0f ) | 0x40 );
		$bytes[8] = chr( ( ord( $bytes[8] ) & 0x3f ) | 0x80 );

		return vsprintf(
			'%s%s-%s-%s-%s-%s%s%s',
			str_split( bin2hex( $bytes ), 4 )
		);
	}

	/**
	 * Get the stored event_id for a given order + event name.
	 *
	 * @param int    $order_id
	 * @param string $event_name
	 * @return string
	 */
	public static function get_order_event_id( int $order_id, string $event_name = 'purchase' ): string {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return '';
		}
		return (string) $order->get_meta( '_servertrack_event_id_' . $event_name ) ?: '';
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Front-end injection
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Inject the Purchase dedup snippet on the WooCommerce thank-you page.
	 *
	 * Static $fired guard prevents double-injection (Bug #4 / v2.1).
	 *
	 * @param int $order_id
	 */
	public static function inject_purchase_dedup_snippet( int $order_id ): void {
		static $fired = false;
		if ( $fired ) {
			return;
		}
		$fired = true;

		$event_id = self::get_order_event_id( $order_id, 'purchase' );
		if ( ! $event_id ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$value    = (float) $order->get_total();
		$currency = strtoupper( get_woocommerce_currency() );

		$contents = [];
		foreach ( $order->get_items() as $item ) {
			/** @var WC_Order_Item_Product $item */
			$product = $item->get_product();
			if ( $product ) {
				$contents[] = [
					'id'       => $product->get_sku() ?: (string) $product->get_id(),
					'quantity' => $item->get_quantity(),
				];
			}
		}

		$data = [
			'value'        => $value,
			'currency'     => $currency,
			'content_type' => 'product',
			'contents'     => $contents,
			'order_id'     => (string) $order_id,
		];

		$data_json     = wp_json_encode( $data );
		$event_id_json = wp_json_encode( $event_id );

		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
		echo "<script>\n";
		echo "/* ServerTrack — Purchase pixel dedup (event_id matches CAPI) */\n";
		echo "if(typeof fbq==='function'){";
		echo "fbq('track','Purchase',{$data_json},{eventID:{$event_id_json}});";
		echo "}\n";
		echo "</script>\n";
		// phpcs:enable
	}

	/**
	 * Inject InitiateCheckout event_id as a hidden input + JS call.
	 */
	public static function inject_initiate_checkout_id(): void {
		$event_id = self::generate_event_id( 'initiatecheckout', 0 );

		if ( function_exists( 'WC' ) && WC()->session ) {
			WC()->session->set( 'servertrack_ic_event_id', $event_id );
		}

		$event_id_json = wp_json_encode( $event_id );
		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
		echo "<script>\n";
		echo "/* ServerTrack — InitiateCheckout pixel dedup */\n";
		echo "if(typeof fbq==='function'){";
		echo "fbq('track','InitiateCheckout',{},{eventID:{$event_id_json}});";
		echo "}\n";
		echo "</script>\n";
		// phpcs:enable
	}

	/**
	 * Inject AddToCart event_id as a data attribute on the product page.
	 */
	public static function inject_add_to_cart_data(): void {
		global $product;
		if ( ! $product ) {
			return;
		}
		$event_id = self::generate_event_id( 'addtocart', $product->get_id() );
		echo '<input type="hidden" id="servertrack-atc-event-id" value="' . esc_attr( $event_id ) . '">' . "\n";
		echo "<script>\n";
		echo "document.addEventListener('click',function(e){";
		echo "var btn=e.target.closest('.single_add_to_cart_button');";
		echo "if(!btn)return;";
		echo "var eid=document.getElementById('servertrack-atc-event-id');";
		echo "if(eid&&typeof fbq==='function'){";
		echo "fbq('track','AddToCart',{},{eventID:eid.value});";
		echo "}});\n";
		echo "</script>\n";
	}

	// ─────────────────────────────────────────────────────────────────────────
	// REST endpoint: GET /wp-json/servertrack/v1/event-id
	// ─────────────────────────────────────────────────────────────────────────

	public static function register_rest_endpoint(): void {
		register_rest_route( 'servertrack/v1', '/event-id', [
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => [ __CLASS__, 'rest_get_event_id' ],
			/**
			 * M-2 FIX (v2.2):
			 *   Previously __return_true — any unauthenticated visitor could enumerate
			 *   order event IDs by passing ?order_id=N, leaking order existence and
			 *   event correlation data.
			 *   Fix: shop_manager/administrator may call without a nonce (server-side
			 *   use). Browser callers must supply a valid 'servertrack_event_id' nonce
			 *   via the X-WP-Nonce header or _wpnonce query param.
			 */
			'permission_callback' => [ __CLASS__, 'rest_permission_check' ],
			'args'                => [
				'event' => [
					'required'          => false,
					'default'           => 'generic',
					'sanitize_callback' => 'sanitize_key',
				],
				'order_id' => [
					'required'          => false,
					'default'           => 0,
					'sanitize_callback' => 'absint',
				],
			],
		] );
	}

	/**
	 * Permission check for the /event-id REST endpoint.
	 *
	 * M-2 FIX: Allow shop managers and admins unconditionally.
	 * For all other callers, verify a nonce generated with the
	 * 'servertrack_event_id' action so unauthenticated enumeration
	 * of order event IDs is not possible.
	 *
	 * @param WP_REST_Request $request
	 * @return bool|WP_Error
	 */
	public static function rest_permission_check( WP_REST_Request $request ) {
		if ( current_user_can( 'manage_woocommerce' ) ) {
			return true;
		}

		$nonce = $request->get_header( 'X-WP-Nonce' )
			?: $request->get_param( '_wpnonce' );

		if ( $nonce && wp_verify_nonce( $nonce, 'servertrack_event_id' ) ) {
			return true;
		}

		return new WP_Error(
			'rest_forbidden',
			__( 'Nonce verification failed.', 'servertrack' ),
			[ 'status' => 403 ]
		);
	}

	public static function rest_get_event_id( WP_REST_Request $request ): WP_REST_Response {
		$event    = $request->get_param( 'event' );
		$order_id = (int) $request->get_param( 'order_id' );

		if ( $order_id > 0 ) {
			$event_id = self::get_order_event_id( $order_id, $event );
			if ( ! $event_id ) {
				$event_id = self::generate_event_id( $event, $order_id );
			}
		} else {
			$event_id = self::generate_event_id( $event, 0 );
		}

		return new WP_REST_Response( [ 'event_id' => $event_id ], 200 );
	}
}
