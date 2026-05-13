<?php
/**
 * ServerTrack — Customer LTV Signal in Purchase Payload (Feature #6)
 *
 * Enriches every Purchase CAPI event with the customer's historical
 * lifetime value and order count, enabling Meta's value-based lookalike
 * audiences and LTV-optimised bidding strategies.
 *
 * v1.2 changes (L-3, L-5 fixes):
 *
 *   L-3 — N+1 query in calculate_ltv().
 *     Previous: wc_get_orders() returned IDs, then wc_get_order() was
 *     called inside foreach — 1 query per past order. A returning customer
 *     with 200 orders triggered 200 extra DB queries on every Purchase event.
 *     Fix: pass 'return' => 'objects' (default) so wc_get_orders() fetches
 *     all order objects in a single query. Loop over objects directly.
 *
 *   L-5 — date() ignores WordPress timezone in get_customer_stats().
 *     date() uses PHP's default timezone (often UTC). Stores in UTC+6 saw
 *     first/last order dates shift to the previous day near midnight.
 *     Fix: replaced date() with wp_date() which applies the WP site timezone.
 *
 * @package ServerTrack
 * @since   6.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ServerTrack_LTV {

	/**
	 * Register filter hooks.
	 */
	public static function init(): void {
		add_filter( 'servertrack_purchase_custom_data', [ __CLASS__, 'enrich_custom_data' ], 10, 2 );
	}

	/**
	 * Add LTV signals to the purchase custom_data array.
	 *
	 * @param array    $custom_data
	 * @param WC_Order $order
	 * @return array
	 */
	public static function enrich_custom_data( array $custom_data, WC_Order $order ): array {
		return array_merge( $custom_data, self::calculate_ltv( $order ) );
	}

	/**
	 * Calculate LTV data for the customer associated with an order.
	 *
	 * L-3 FIX (v1.2):
	 *   Previous implementation used 'return' => 'ids' then called
	 *   wc_get_order($id) inside a foreach loop — classic N+1 query pattern.
	 *   A customer with 200 past orders caused 200 additional DB queries on
	 *   every Purchase CAPI send, adding ~200ms latency per order.
	 *
	 *   Fix: omit 'return' key so wc_get_orders() returns WC_Order objects
	 *   directly (its default). This fetches all past orders in a single
	 *   batched query. The foreach now iterates over objects, not IDs.
	 *
	 * @param WC_Order $order
	 * @return array
	 */
	public static function calculate_ltv( WC_Order $order ): array {
		$user_id = $order->get_user_id();
		$email   = $order->get_billing_email();

		$historical_value = 0.0;
		$order_count      = 0;

		$query_args = [
			'status'  => [ 'completed', 'processing' ],
			'limit'   => -1,
			'exclude' => [ $order->get_id() ],
			// L-3 FIX: no 'return' key — default is objects, not IDs.
			// Previously 'return' => 'ids' required N wc_get_order() calls inside
			// the loop. Now all order objects are fetched in a single query.
		];

		if ( $user_id > 0 ) {
			$query_args['customer_id'] = $user_id;
		} elseif ( $email ) {
			$query_args['billing_email'] = $email;
		} else {
			// No customer identifier — treat as brand-new customer
			$current_value = (float) $order->get_total();
			return [
				'predicted_ltv'   => $current_value,
				'order_count'     => 1,
				'customer_type'   => 'new',
				'avg_order_value' => $current_value,
			];
		}

		$past_orders = wc_get_orders( $query_args );

		foreach ( $past_orders as $past_order ) {
			// L-3 FIX: $past_order is already a WC_Order object — no wc_get_order() needed.
			$historical_value += (float) $past_order->get_total();
			$order_count++;
		}

		$current_value   = (float) $order->get_total();
		$predicted_ltv   = round( $historical_value + $current_value, 2 );
		$total_orders    = $order_count + 1;
		$avg_order_value = $total_orders > 0
			? round( $predicted_ltv / $total_orders, 2 )
			: $current_value;
		$customer_type   = $order_count > 0 ? 'returning' : 'new';

		$ltv_data = [
			'predicted_ltv'   => $predicted_ltv,
			'order_count'     => $total_orders,
			'customer_type'   => $customer_type,
			'avg_order_value' => $avg_order_value,
		];

		return apply_filters( 'servertrack_ltv_data', $ltv_data, $order, $historical_value );
	}

	/**
	 * Get LTV stats for a customer by user ID or email.
	 *
	 * L-5 FIX (v1.2):
	 *   Previous code used date('Y-m-d', $ts) which applies PHP's default
	 *   timezone (often UTC). Stores in positive UTC offsets (e.g. UTC+6)
	 *   saw first/last order dates shift to the previous calendar day for
	 *   orders placed before midnight local time.
	 *   Fix: replaced date() with wp_date() which uses the WordPress
	 *   'timezone_string' / 'gmt_offset' site setting.
	 *
	 * @param int    $user_id
	 * @param string $email
	 * @return array
	 */
	public static function get_customer_stats( int $user_id = 0, string $email = '' ): array {
		$query_args = [
			'status' => [ 'completed', 'processing' ],
			'limit'  => -1,
		];

		if ( $user_id > 0 ) {
			$query_args['customer_id'] = $user_id;
		} elseif ( $email ) {
			$query_args['billing_email'] = $email;
		} else {
			return [];
		}

		$orders = wc_get_orders( $query_args );

		if ( empty( $orders ) ) {
			return [ 'total_spend' => 0, 'order_count' => 0, 'avg_order_value' => 0 ];
		}

		$total_spend = 0.0;
		$dates       = [];

		foreach ( $orders as $o ) {
			$total_spend += (float) $o->get_total();
			if ( $o->get_date_created() ) {
				$dates[] = $o->get_date_created()->getTimestamp();
			}
		}

		$count = count( $orders );
		sort( $dates );

		return [
			'total_spend'      => round( $total_spend, 2 ),
			'order_count'      => $count,
			'avg_order_value'  => round( $total_spend / $count, 2 ),
			// L-5 FIX: wp_date() respects the WP site timezone setting.
			// date() used PHP default timezone (UTC) causing off-by-one day
			// errors for stores in positive UTC offsets.
			'first_order_date' => $dates ? wp_date( 'Y-m-d', $dates[0] ) : '',
			'last_order_date'  => $dates ? wp_date( 'Y-m-d', end( $dates ) ) : '',
		];
	}
}
