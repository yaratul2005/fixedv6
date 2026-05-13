<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ServerTrack_MatchQuality  v2.0
 *
 * Feature #3 — Real-Time Event Match Quality (EMQ) Scorer.
 *
 * HIGH FIX (H-2) in v2.0:
 *   get_daily_averages() previously read from servertrack_debug_log which is
 *   only written when debug_mode=1. In production (debug_mode=0) the option is
 *   always empty, so the EMQ trend panel on the dashboard was permanently blank.
 *
 *   Fixed: now reads from servertrack_event_stats — the always-on stats store
 *   written by Logger on every event regardless of debug_mode.
 *   Falls back to debug_log only when stats store has no EMQ data (pre-upgrade).
 *
 * Scoring model (mirrors Meta's published EMQ weights):
 *   email          → +3.5 pts
 *   phone          → +2.5 pts
 *   fbc            → +2.0 pts
 *   fbp            → +1.5 pts
 *   external_id    → +1.0 pts
 *   first_name     → +0.5 pts
 *   last_name      → +0.5 pts
 *   ip             → +0.5 pts
 *   city/state/zip/country → +0.3 pts each
 *   user_agent     → +0.3 pts
 *
 * Max possible raw score ≈ 13.6 → normalised to 0–10 for the dashboard.
 */
class ServerTrack_MatchQuality {

    const MAX_RAW = 13.6;

    const WEIGHTS = [
        'email'       => 3.5,
        'phone'       => 2.5,
        'fbc'         => 2.0,
        'fbp'         => 1.5,
        'external_id' => 1.0,
        'first_name'  => 0.5,
        'last_name'   => 0.5,
        'ip'          => 0.5,
        'city'        => 0.3,
        'state'       => 0.3,
        'zip'         => 0.3,
        'country'     => 0.3,
        'user_agent'  => 0.3,
    ];

    /**
     * Score a user_data array.
     *
     * @param  array $user_data
     * @return array { score:float, grade:string, breakdown:array, missing:array }
     */
    public static function score( array $user_data ): array {
        $raw       = 0.0;
        $breakdown = [];
        $missing   = [];

        foreach ( self::WEIGHTS as $field => $weight ) {
            if ( ! empty( $user_data[ $field ] ) ) {
                $raw              += $weight;
                $breakdown[$field] = $weight;
            } elseif ( $weight >= 1.0 ) {
                $missing[$field] = $weight;
            }
        }

        $score = round( min( 10.0, ( $raw / self::MAX_RAW ) * 10 ), 1 );

        if ( $score >= 7.5 )      $grade = 'excellent';
        elseif ( $score >= 5.5 )  $grade = 'good';
        elseif ( $score >= 3.5 )  $grade = 'fair';
        else                       $grade = 'poor';

        return [
            'score'     => $score,
            'grade'     => $grade,
            'breakdown' => $breakdown,
            'missing'   => $missing,
        ];
    }

    /**
     * Annotate an event with its EMQ score (attaches to custom_data['_emq']).
     * The _emq key is stripped before API transmission.
     */
    public static function annotate( ServerTrack_Event $event ): array {
        $result = self::score( $event->user_data );
        $event->custom_data['_emq'] = $result;
        return $result;
    }

    /**
     * Get daily average EMQ scores for the dashboard trend panel.
     *
     * HIGH FIX (H-2 — v2.0):
     *   Now reads servertrack_event_stats (always-on) instead of
     *   servertrack_debug_log (debug_mode=1 only → always empty in prod).
     *
     *   Stats entry shape (written by Logger v3.0+):
     *     [ 'ts'=>int, 'status'=>str, 'platform'=>str,
     *       'event_type'=>str, 'order_id'=>int, 'emq_score'=>float|null ]
     *
     *   Fallback: if stats store has no EMQ data, reads debug_log so
     *   the chart is not immediately broken on freshly-upgraded installs.
     *
     * @param  int   $days  Past days to aggregate (default 7)
     * @return array        [ 'Y-m-d' => [ 'avg'=>float, 'count'=>int ], … ]
     */
    public static function get_daily_averages( int $days = 7 ): array {
        $cutoff = time() - ( $days * DAY_IN_SECONDS );
        $totals = [];
        $counts = [];

        // ── Primary: always-on stats store ──────────────────────────────────
        $stats   = get_option( 'servertrack_event_stats', [] );
        $has_emq = false;

        if ( is_array( $stats ) && ! empty( $stats ) ) {
            foreach ( $stats as $entry ) {
                if ( empty( $entry['ts'] ) || (int) $entry['ts'] < $cutoff ) continue;
                if ( ! isset( $entry['emq_score'] ) ) continue;
                $has_emq         = true;
                $date            = gmdate( 'Y-m-d', (int) $entry['ts'] );
                $totals[ $date ] = ( $totals[ $date ] ?? 0.0 ) + (float) $entry['emq_score'];
                $counts[ $date ] = ( $counts[ $date ] ?? 0 ) + 1;
            }
        }

        // ── Fallback: legacy debug log (pre-upgrade sites) ───────────────────
        if ( ! $has_emq ) {
            $logs = get_option( 'servertrack_debug_log', [] );
            if ( is_array( $logs ) ) {
                foreach ( $logs as $entry ) {
                    if ( empty( $entry['timestamp'] ) ) continue;
                    $ts = strtotime( $entry['timestamp'] );
                    if ( $ts < $cutoff ) continue;
                    if ( ! isset( $entry['emq_score'] ) ) continue;
                    $date            = substr( $entry['timestamp'], 0, 10 );
                    $totals[ $date ] = ( $totals[ $date ] ?? 0.0 ) + (float) $entry['emq_score'];
                    $counts[ $date ] = ( $counts[ $date ] ?? 0 ) + 1;
                }
            }
        }

        $result = [];
        foreach ( $totals as $date => $total ) {
            $result[ $date ] = [
                'avg'   => round( $total / $counts[ $date ], 1 ),
                'count' => $counts[ $date ],
            ];
        }
        ksort( $result );
        return $result;
    }
}
