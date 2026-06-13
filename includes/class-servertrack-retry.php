<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ServerTrack_Retry  v2.3
 *
 * Queues failed CAPI events for exponential-backoff retry via WP-Cron.
 *
 * v2.3 changes (BUG-08 definitive fix):
 *   process() previously called Dedup::mark_as_sent( $dedup_key, $platform )
 *   when a string $dedup_key was present. However mark_as_sent() signature is
 *   mark_as_sent( int $order_id, string $platform ) — passing a string for an
 *   int parameter is a TypeError in strict mode and stores corrupt meta
 *   otherwise. The correct method for string-keyed non-order events is
 *   Dedup::set( string $key ), which writes to wp_options under the
 *   servertrack_dedup_{key} namespace (same system used by Subscriptions source).
 *   Fix: split the success branch — string $dedup_key → Dedup::set(),
 *        integer $order_id > 0 → Dedup::mark_as_sent().
 *
 * v2.2 changes (dashboard integration patch):
 *   1. process_queue() public alias added so the admin dashboard drain-all
 *      AJAX action (servertrack_drain_retries) can call
 *      ServerTrack_Retry::process_queue() without knowing the internal
 *      method name.  Delegates directly to process().
 *
 *   2. maybe_queue() now stores 'event_name' and 'last_attempt' at the top
 *      level of each queue item (alongside platform, attempts, etc.) so the
 *      dashboard retry panel can display them without digging into event_args.
 *
 *   3. process() updates 'last_attempt' to time() on every attempt (success
 *      or failure) so the timestamp shown in the dashboard stays accurate.
 *
 * v2.1 changes (BUG-08 partial fix — superseded by v2.3):
 *   process() called Dedup::mark_as_sent( $order_id, $platform ) only when
 *   order_id > 0. Subscription renewals, cart abandonment events, and other
 *   non-order events carry order_id = 0 in their event_args. When a retry
 *   succeeded for those events, mark_as_sent was never called, so the dedup
 *   guard never recorded the success — the event could re-fire on the next
 *   cron cycle, causing double-attribution.
 *
 *   Partial fix in v2.1: added dedup_key string path but still routed through
 *   mark_as_sent() which expects int. v2.3 corrects this to Dedup::set().
 */
class ServerTrack_Retry {

    /** Option key for the pending retry queue. */
    const QUEUE_OPTION = 'servertrack_retry_queue';

    /** Maximum retry attempts before an event is abandoned. */
    const MAX_ATTEMPTS = 5;

    /** Backoff base in seconds (doubles each attempt: 60, 120, 240, 480, 960). */
    const BACKOFF_BASE = 60;

    public static function init(): void {
        add_action( 'servertrack_process_retry_queue', [ self::class, 'process' ] );
    }

    // ── Queue management ──────────────────────────────────────────────────

    /**
     * Maybe queue a failed event for retry.
     *
     * Only queues if the result indicates a transient failure (network error,
     * 5xx, 429). Hard failures (4xx except 429) are not retried.
     *
     * v2.2: also stores 'event_name' and 'last_attempt' at the queue item's
     * top level so the dashboard panel can display them without parsing event_args.
     *
     * @param string $platform   'meta' | 'tiktok' | 'google'
     * @param array  $result     Result array from platform sender
     * @param array  $event_args Serialisable event arguments
     */
    public static function maybe_queue( string $platform, array $result, array $event_args ): void {
        $status = $result['status'] ?? '';
        $code   = (int) ( $result['http_code'] ?? 0 );

        // Only retry transient failures
        $should_retry = ( $status === 'error' )
            && ( $code === 0 || $code === 429 || $code >= 500 );

        if ( ! $should_retry ) {
            return;
        }

        $queue = get_option( self::QUEUE_OPTION, [] );

        // Stable UID prevents duplicate queue entries on race conditions
        $uid = md5( $platform . ( $event_args['event_id'] ?? '' ) );

        if ( isset( $queue[ $uid ] ) ) {
            return; // Already queued
        }

        $attempts = 0;
        $delay    = self::BACKOFF_BASE;

        $queue[ $uid ] = [
            'platform'     => $platform,
            'event_name'   => $event_args['event_name'] ?? 'Unknown',   // v2.2: top-level for dashboard
            'event_args'   => $event_args,
            'attempts'     => $attempts,
            'next_retry'   => time() + $delay,
            'queued_at'    => time(),
            'last_attempt' => null,                                       // v2.2: set on first attempt
        ];

        update_option( self::QUEUE_OPTION, $queue, false );
    }

    /**
     * Process the retry queue.
     * Called by WP-Cron every 5 minutes.
     */
    public static function process(): void {
        $queue = get_option( self::QUEUE_OPTION, [] );
        if ( empty( $queue ) ) {
            return;
        }

        $now     = time();
        $updated = false;

        foreach ( $queue as $uid => $item ) {
            if ( $item['next_retry'] > $now ) {
                continue; // Not yet due
            }

            if ( $item['attempts'] >= self::MAX_ATTEMPTS ) {
                unset( $queue[ $uid ] );
                $updated = true;
                ServerTrack_Logger::warning(
                    sprintf( 'Retry abandoned after %d attempts [uid=%s].', self::MAX_ATTEMPTS, $uid )
                );
                continue;
            }

            $platform   = $item['platform'];
            $event_args = $item['event_args'];
            $result     = self::dispatch_retry( $platform, $event_args );

            // v2.2: always stamp last_attempt
            $item['last_attempt'] = gmdate( 'Y-m-d H:i:s' );

            if ( ( $result['status'] ?? '' ) === 'success' ) {
                unset( $queue[ $uid ] );
                $updated = true;

                /*
                 * BUG-08 DEFINITIVE FIX (v2.3):
                 *
                 *   String-keyed non-order events (subscriptions, cart abandonment,
                 *   offline conversions) carry a string $dedup_key in event_args
                 *   and order_id = 0. The correct dedup method for these is
                 *   Dedup::set( string $key ), which writes to wp_options.
                 *
                 *   Integer order_id events (standard WooCommerce purchases) use
                 *   Dedup::mark_as_sent( int $order_id, string $platform ), which
                 *   writes to order meta.
                 *
                 *   v2.1 mistakenly routed string keys through mark_as_sent() which
                 *   requires int — causing a TypeError in strict mode and silent
                 *   data corruption otherwise.
                 */
                $order_id  = (int) ( $event_args['custom_data']['order_id'] ?? 0 );
                $dedup_key = (string) ( $event_args['dedup_key'] ?? '' );

                if ( '' !== $dedup_key ) {
                    // Non-order event: use the options-based string-key dedup API.
                    ServerTrack_Dedup::set( $dedup_key );
                } elseif ( $order_id > 0 ) {
                    // Standard WooCommerce order: use the order-meta dedup API.
                    ServerTrack_Dedup::mark_as_sent( $order_id, $platform );
                }

                ServerTrack_Logger::info(
                    sprintf( 'Retry succeeded [uid=%s platform=%s attempt=%d].', $uid, $platform, $item['attempts'] + 1 )
                );

            } else {
                $item['attempts']++;
                $item['next_retry'] = time() + ( self::BACKOFF_BASE * ( 2 ** $item['attempts'] ) );
                $queue[ $uid ]      = $item;
                $updated            = true;
            }
        }

        if ( $updated ) {
            update_option( self::QUEUE_OPTION, $queue, false );
        }
    }

    /**
     * Public alias for process() — called by the dashboard drain-all AJAX action.
     *
     * Added in v2.2 so ServerTrack_Dashboard::ajax_drain_retries() can call
     * ServerTrack_Retry::process_queue() via the stable public API, while
     * WP-Cron continues to invoke process() via its registered action hook.
     */
    public static function process_queue(): void {
        self::process();
    }

    /**
     * Dispatch a single retry attempt to the appropriate platform sender.
     *
     * @param string $platform
     * @param array  $event_args
     * @return array Result array with 'status' key
     */
    private static function dispatch_retry( string $platform, array $event_args ): array {
        try {
            $event_id    = $event_args['event_id']    ?? '';
            $event_name  = $event_args['event_name']  ?? 'Purchase';
            $user_data   = $event_args['user_data']   ?? [];
            $custom_data = $event_args['custom_data'] ?? [];

            $event = ( new ServerTrack_Event( $event_name, $event_id ) )
                ->set_user_data( $user_data )
                ->set_custom_data( $custom_data );

            switch ( $platform ) {
                case 'meta':
                    return ServerTrack_Meta::send( $event );
                case 'tiktok':
                    return ServerTrack_TikTok::send( $event );
                case 'google':
                    return ServerTrack_Google::send( $event );
                default:
                    return [ 'status' => 'error', 'message' => 'Unknown platform: ' . $platform ];
            }
        } catch ( \Throwable $e ) {
            return [ 'status' => 'error', 'message' => $e->getMessage() ];
        }
    }

    /**
     * Convert a ServerTrack_Event into a serialisable args array for the queue.
     *
     * v2.1: now includes 'dedup_key' from custom_data['_dedup_key'] when present,
     * so process() can call the correct dedup method for non-order events (BUG-08 fix).
     *
     * @param ServerTrack_Event $event
     * @return array
     */
    public static function event_to_args( ServerTrack_Event $event ): array {
        $args = [
            'event_id'    => $event->event_id,
            'event_name'  => $event->event_name,
            'user_data'   => $event->user_data,
            'custom_data' => $event->custom_data,
        ];

        // BUG-08 FIX: carry dedup_key so process() can mark non-order events as sent.
        if ( ! empty( $event->custom_data['_dedup_key'] ) ) {
            $args['dedup_key'] = $event->custom_data['_dedup_key'];
        }

        return $args;
    }
}
