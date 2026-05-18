<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ServerTrack_Logger  v2.4
 *
 * v2.4 fixes:
 *
 *   FIX DASHBOARD-STATIC — log() debug_mode gate swallowed all event data.
 *     log() opened with:
 *       if ( ! get_option( 'servertrack_debug_mode', 0 ) ) { return; }
 *     servertrack_debug_mode defaults to 0, so every Logger::log() call
 *     from Meta::send(), TikTok::send(), and Google::send() silently
 *     returned without writing anything. servertrack_debug_log stayed
 *     empty forever, and the dashboard showed all zeros regardless of
 *     how many real events were sent.
 *
 *     Fix: entries with status 'success', 'error', 'queued', or
 *     'dedup_blocked' are ALWAYS written (force_write=true). Only
 *     'skipped' entries remain gated on debug_mode, since those are
 *     verbose/informational. This matches the existing behaviour of the
 *     error() and warning() severity helpers which already used
 *     force_write=true.
 *
 * v2.3 fixes:
 *
 *   FIX BUG-FIX-1 — Added clear_logs() as a public alias of clear().
 *   FIX BUG-FIX-2 — log() now writes 'event_name' alongside 'event_type'.
 *
 * v2.2 changes (L-1, L-2 fixes):
 *   L-1 — error(), info(), warning() helpers were missing.
 *   L-2 — get_recent() memory inefficiency fixed.
 *
 * v2.1 changes:
 *   - log() fires do_action('servertrack_event_logged') after writing.
 *
 * v2.0 changes:
 *   - log() accepts optional $emq array; max entries raised to 1000.
 *
 * @package ServerTrack
 */
class ServerTrack_Logger {

	const OPTION_KEY  = 'servertrack_debug_log';
	const MAX_ENTRIES = 1000;

	/**
	 * Statuses that are always written, regardless of debug_mode setting.
	 * 'skipped' remains debug_mode-gated (verbose/informational only).
	 */
	const ALWAYS_WRITE_STATUSES = [ 'success', 'error', 'queued', 'dedup_blocked' ];

	// ── Severity helpers ─────────────────────────────────────────

	/**
	 * Log an error-level entry (always written, bypasses debug_mode gate).
	 *
	 * @param string $message  Human-readable error description.
	 * @param array  $context  Optional structured context (platform, trace, etc.).
	 */
	public static function error( string $message, array $context = [] ): void {
		self::write_entry( 'error', 'system', $message, $context, true );
	}

	/**
	 * Log a warning-level entry (always written, debug_mode-independent).
	 *
	 * @param string $message
	 * @param array  $context
	 */
	public static function warning( string $message, array $context = [] ): void {
		self::write_entry( 'warning', 'system', $message, $context, true );
	}

	/**
	 * Log an info-level entry (only written when debug_mode=1).
	 *
	 * @param string $message
	 * @param array  $context
	 */
	public static function info( string $message, array $context = [] ): void {
		self::write_entry( 'info', 'system', $message, $context, false );
	}

	// ── Core log method ──────────────────────────────────────────

	/**
	 * Append a structured CAPI event log entry.
	 *
	 * v2.4 FIX (DASHBOARD-STATIC):
	 *   Entries whose status is 'success', 'error', 'queued', or
	 *   'dedup_blocked' are always written. Only 'skipped' (and any
	 *   unrecognised status) remains gated on debug_mode=1, because
	 *   those are verbose informational entries not needed for the
	 *   dashboard KPIs, charts, or log table.
	 *
	 * @param string $status      success|error|skipped|queued|dedup_blocked|webhook
	 * @param string $platform    meta|tiktok|google|all|identity|webhook
	 * @param string $message     Human-readable description
	 * @param string $response    Raw API response string (optional)
	 * @param string $event_id    UUID event ID (optional)
	 * @param int    $order_id    WC order ID (optional, 0 for non-order events)
	 * @param string $event_type  Purchase|ViewContent|AddToCart|... (optional)
	 * @param array  $emq         [ 'score' => float, 'grade' => string ] (optional)
	 */
	public static function log(
		string $status,
		string $platform,
		string $message,
		string $response   = '',
		string $event_id   = '',
		int    $order_id   = 0,
		string $event_type = '',
		array  $emq        = []
	): void {
		// v2.4 FIX: always write actionable statuses; gate only verbose ones.
		$force_write = in_array( $status, self::ALWAYS_WRITE_STATUSES, true );

		if ( ! $force_write && ! get_option( 'servertrack_debug_mode', 0 ) ) {
			return;
		}

		$entry = [
			'timestamp'  => current_time( 'Y-m-d H:i:s' ),
			'status'     => $status,
			'platform'   => $platform,
			'message'    => $message,
			'event_id'   => $event_id,
			'order_id'   => $order_id,
			// BUG-FIX-2 (v2.3): store as both 'event_type' (legacy) and 'event_name'
			// (Dashboard::render_log_rows / compute_breakdown key).
			'event_type' => $event_type,
			'event_name' => $event_type,
		];

		if ( ! empty( $emq ) && isset( $emq['score'] ) ) {
			$entry['emq_score'] = $emq['score'];
			$entry['emq_grade'] = $emq['grade'] ?? '';
		}

		self::append_entry( $entry );

		/**
		 * Fires after a CAPI event is logged.
		 *
		 * @param string $platform
		 * @param string $event_type
		 * @param int    $order_id
		 * @param string $status
		 * @param array  $emq
		 */
		do_action( 'servertrack_event_logged', $platform, $event_type, $order_id, $status, $emq );
	}

	// ── Read / clear ──────────────────────────────────────────────

	/**
	 * Get recent log entries (most recent first).
	 *
	 * L-2 FIX (v2.2): slice from the tail first (-$limit), then reverse
	 * only the small slice. Memory usage scales with $limit, not MAX_ENTRIES.
	 *
	 * @param int $limit  Max entries to return.
	 * @return array
	 */
	public static function get_recent( int $limit = 100 ): array {
		$logs = get_option( self::OPTION_KEY, [] );
		if ( empty( $logs ) ) {
			return [];
		}
		return array_reverse( array_slice( $logs, -$limit ) );
	}

	/**
	 * Clear all log entries.
	 */
	public static function clear(): void {
		update_option( self::OPTION_KEY, [], false );
	}

	/**
	 * Alias of clear() — added in v2.3 (BUG-FIX-1).
	 */
	public static function clear_logs(): void {
		self::clear();
	}

	// ── Internal helpers ───────────────────────────────────────

	/**
	 * Write a severity-level entry (used by error/warning/info helpers).
	 *
	 * @param string $status        'error'|'warning'|'info'
	 * @param string $platform      'system' for non-CAPI entries
	 * @param string $message
	 * @param array  $context       Arbitrary key-value context bag
	 * @param bool   $force_write   When true, bypasses debug_mode gate
	 */
	private static function write_entry(
		string $status,
		string $platform,
		string $message,
		array  $context,
		bool   $force_write
	): void {
		if ( ! $force_write && ! get_option( 'servertrack_debug_mode', 0 ) ) {
			return;
		}

		$entry = [
			'timestamp' => current_time( 'Y-m-d H:i:s' ),
			'status'    => $status,
			'platform'  => $platform,
			'message'   => $message,
		];

		if ( ! empty( $context ) ) {
			$entry['context'] = $context;
		}

		self::append_entry( $entry );
	}

	/**
	 * Append an entry to the log store with FIFO pruning.
	 *
	 * @param array $entry
	 */
	private static function append_entry( array $entry ): void {
		$logs   = get_option( self::OPTION_KEY, [] );
		$logs[] = $entry;

		if ( count( $logs ) > self::MAX_ENTRIES ) {
			$logs = array_slice( $logs, -self::MAX_ENTRIES );
		}

		$serialized = wp_json_encode( $logs );
		$size_mb = strlen( $serialized ) / 1024 / 1024;

		if ( $size_mb > 2.0 ) {
			self::warning(
				'Debug log approaching MySQL size limit. Aggressive truncation applied.',
				[ 'size_mb' => $size_mb ]
			);
			$logs = array_slice( $logs, -50 );
		}

		$result = update_option( self::OPTION_KEY, $logs, false );

		if ( ! $result && $size_mb > 3.0 ) {
			error_log( 'ServerTrack: Debug log update failed. Size: ' . $size_mb . 'MB. Consider clearing debug log.' );
		}
	}
}
