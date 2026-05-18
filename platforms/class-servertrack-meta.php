<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ServerTrack_Meta  v2.3
 *
 * Meta Conversions API Sender.
 * Depends on: ServerTrack_Event, ServerTrack_Logger
 *
 * Changes in v2.3 (FIX-05):
 *   Added platform-enabled guard at the top of send().
 *   Google::send() already had this guard (get_option servertrack_google_enabled).
 *   Meta and TikTok lacked it, relying entirely on call-site checks in the
 *   WooCommerce source and REST endpoint. This adds defence-in-depth:
 *   send() is now safe to call directly without the caller being responsible
 *   for the enabled check. Returns 'skipped' (matching Google's pattern)
 *   so callers can distinguish disabled vs error vs success.
 *
 * Changes in v2.2 (Bug fixes):
 *
 *   Bug #8b — Logger::log() arg 8 type mismatch:
 *     Both Logger calls passed (int) $code / literal 0 as arg 8.
 *     Logger signature: (..., string $event_type, array $emq = []).
 *     Passing an int for an array-typed parameter is a TypeError in strict
 *     mode and silently stores wrong data otherwise.
 *     Fix: arg 8 removed from both calls (defaults to []).
 *     EMQ is computed by the caller (WooCommerce source) via
 *     ServerTrack_MatchQuality::score() and passed directly to Logger there.
 *
 *   Bug #12 — wp_json_encode() failure not caught:
 *     If build payload contains a non-UTF-8 string or recursive reference,
 *     wp_json_encode() returns false. Sending boolean false as POST body
 *     causes a 400 from Meta's API with no useful error message.
 *     Fix: encode result is checked; logs + returns error on false.
 *
 * Changes in v2.1:
 *   1. event_source_url read from event DTO (not $_SERVER in cron context).
 *   2. external_id included for Advanced Matching.
 *   3. Graph API bumped to v22.0.
 */
class ServerTrack_Meta {

    // v22.0 — current stable. v21.0 was deprecated and caused silent drops.
    const API_ENDPOINT = 'https://graph.facebook.com/v22.0/%s/events';

    /**
     * Send an event to Meta CAPI.
     *
     * @param ServerTrack_Event $event  Fully populated event DTO.
     * @return array { status, http_code, response }
     */
    public static function send( ServerTrack_Event $event ): array {
        // FIX-05: Defence-in-depth enabled guard (mirrors Google::send() pattern).
        // Call sites already check this option, but send() must be self-contained.
        if ( ! get_option( 'servertrack_meta_enabled', 0 ) ) {
            return [ 'status' => 'skipped', 'http_code' => 0 ];
        }

        $pixel_id     = trim( (string) get_option( 'servertrack_meta_pixel_id', '' ) );
        $access_token = trim( (string) get_option( 'servertrack_meta_access_token', '' ) );

        if ( '' === $pixel_id || '' === $access_token ) {
            return [
                'status'  => 'error',
                'message' => 'Meta Pixel ID or Access Token not configured. Please save your credentials in the Meta CAPI tab first.',
            ];
        }

        // ── Build hashed user_data object ─────────────────────────────────────
        $ud = [];

        $hashed_map = [
            'email'      => 'em',
            'phone'      => 'ph',
            'first_name' => 'fn',
            'last_name'  => 'ln',
            'city'       => 'ct',
            'state'      => 'st',
            'zip'        => 'zp',
            'country'    => 'country',
        ];
        foreach ( $hashed_map as $src => $dest ) {
            if ( ! empty( $event->user_data[ $src ] ) ) {
                $ud[ $dest ] = [ $event->user_data[ $src ] ];
            }
        }

        $raw_map = [
            'ip'         => 'client_ip_address',
            'user_agent' => 'client_user_agent',
            'fbp'        => 'fbp',
            'fbc'        => 'fbc',
        ];
        foreach ( $raw_map as $src => $dest ) {
            if ( ! empty( $event->user_data[ $src ] ) ) {
                if ( $src === 'fbc' && strpos($event->user_data['fbc'], 'fb.') !== 0 ) {
                    $ts = time() * 1000;
                    $ud[ $dest ] = 'fb.1.' . $ts . '.' . $event->user_data['fbc'];
                } else {
                    $ud[ $dest ] = $event->user_data[ $src ];
                }
            }
        }

        // FIX (v2.1): include external_id for Advanced Matching.
        if ( ! empty( $event->user_data['external_id'] ) ) {
            $ud['external_id'] = [ $event->user_data['external_id'] ];
        }

        // ── Build event_source_url ───────────────────────────────────────────
        if ( ! empty( $event->event_source_url ) ) {
            $source_url = $event->event_source_url;
        } else {
            $request_uri = isset( $_SERVER['REQUEST_URI'] )
                ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) )
                : '/';
            $source_url = home_url( $request_uri );
        }

        // ── Assemble event payload ───────────────────────────────────────────
        $event_payload = [
            'event_name'       => $event->event_name,
            'event_time'       => time(),
            'event_id'         => $event->event_id,
            'event_source_url' => $source_url,
            'action_source'    => 'website',
            'user_data'        => $ud,
            'custom_data'      => $event->custom_data,
        ];

        $body = [
            'data'         => [ $event_payload ],
            'access_token' => $access_token,
        ];

        // Attach test_event_code if present
        $test_code = ! empty( $event->custom_data['_test_event_code'] )
            ? $event->custom_data['_test_event_code']
            : trim( (string) get_option( 'servertrack_meta_test_event_code', '' ) );

        if ( '' !== $test_code ) {
            $body['test_event_code'] = $test_code;
            unset( $event_payload['custom_data']['_test_event_code'] );
        }

        // BUG #12 FIX: guard against wp_json_encode() returning false.
        $json = wp_json_encode( $body );
        if ( false === $json ) {
            ServerTrack_Logger::log(
                'error', 'meta',
                'wp_json_encode failed — payload contains non-serialisable data.',
                '', $event->event_id,
                (int) ( $event->custom_data['order_id'] ?? 0 ),
                $event->event_name
                // arg 8 (emq) intentionally omitted — defaults to []
            );
            return [ 'status' => 'error', 'http_code' => 0, 'message' => 'JSON encode failed.' ];
        }

        $endpoint = sprintf( self::API_ENDPOINT, $pixel_id );

        $response = wp_remote_post( $endpoint, [
            'method'  => 'POST',
            'timeout' => 15,
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body'    => $json,
        ] );

        if ( is_wp_error( $response ) ) {
            // BUG #8b FIX: removed (int) 0 as arg 8 — Logger expects array $emq.
            ServerTrack_Logger::log(
                'error', 'meta',
                $response->get_error_message(),
                '', $event->event_id,
                (int) ( $event->custom_data['order_id'] ?? 0 ),
                $event->event_name
                // arg 8 (emq) intentionally omitted — defaults to []
            );
            return [ 'status' => 'error', 'message' => $response->get_error_message(), 'http_code' => 0 ];
        }

        $code     = (int) wp_remote_retrieve_response_code( $response );
        $body_raw = wp_remote_retrieve_body( $response );
        $status   = ( $code >= 200 && $code < 300 ) ? 'success' : 'error';

        // BUG #8b FIX: removed (int) $code as arg 8 — was corrupting emq log field.
        ServerTrack_Logger::log(
            $status, 'meta',
            (string) $code,
            $body_raw,
            $event->event_id,
            (int) ( $event->custom_data['order_id'] ?? 0 ),
            $event->event_name
            // arg 8 (emq) intentionally omitted — defaults to []
        );

        return [ 'status' => $status, 'http_code' => $code, 'response' => $body_raw ];
    }
}
