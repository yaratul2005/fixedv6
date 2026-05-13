<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ServerTrack_TikTok  v2.3
 *
 * TikTok Events API Sender.
 * Depends on: ServerTrack_Event, ServerTrack_Logger
 *
 * Changes in v2.3 (FIX-05):
 *   Added platform-enabled guard at the top of send().
 *   Google::send() already had this guard. TikTok lacked it, relying entirely
 *   on call-site checks. This adds defence-in-depth: send() is now safe to
 *   call directly without the caller being responsible for the enabled check.
 *   Returns 'skipped' (matching Google's pattern) so callers can distinguish
 *   disabled vs error vs success.
 *
 * Changes in v2.2 (Bug fixes):
 *
 *   Bug #8c — Logger::log() arg 8 type mismatch:
 *     Both Logger calls (WP_Error path and success path) passed (int) $code
 *     or literal 0 as arg 8. Logger signature expects (array $emq = []).
 *     Fix: arg 8 removed from both calls — defaults to [].
 *     EMQ is computed upstream by the WooCommerce source and passed to
 *     Logger directly there, so TikTok::send() does not need it.
 *
 *   Bug #12 — wp_json_encode() failure not caught:
 *     If payload contains non-UTF-8 data, encode returns false.
 *     Fix: checked; logs error + returns early on false.
 *
 * Changes in v2.1:
 *   - page.url read from event DTO instead of $_SERVER (cron safety).
 *   - external_id included for Advanced Matching.
 */
class ServerTrack_TikTok {

    const API_ENDPOINT = 'https://business-api.tiktok.com/open_api/v1.3/event/track/';

    private static array $event_name_map = [
        'Purchase'              => 'CompletePayment',
        'Lead'                  => 'SubmitForm',
        'ViewContent'           => 'ViewContent',
        'AddToCart'             => 'AddToCart',
        'InitiateCheckout'      => 'InitiateCheckout',
        'CompleteRegistration'  => 'CompleteRegistration',
        'AddPaymentInfo'        => 'AddPaymentInfo',
    ];

    /**
     * Send an event to TikTok Events API.
     *
     * @param ServerTrack_Event $event  Fully populated event DTO.
     * @return array { status, http_code, response }
     */
    public static function send( ServerTrack_Event $event ): array {
        // FIX-05: Defence-in-depth enabled guard (mirrors Google::send() pattern).
        // Call sites already check this option, but send() must be self-contained.
        if ( ! get_option( 'servertrack_tiktok_enabled', 0 ) ) {
            return [ 'status' => 'skipped', 'http_code' => 0 ];
        }

        $pixel_id     = trim( (string) get_option( 'servertrack_tiktok_pixel_id', '' ) );
        $access_token = trim( (string) get_option( 'servertrack_tiktok_access_token', '' ) );

        if ( '' === $pixel_id || '' === $access_token ) {
            return [ 'status' => 'error', 'message' => 'TikTok Pixel ID or Access Token not configured.' ];
        }

        $tiktok_event = self::$event_name_map[ $event->event_name ] ?? $event->event_name;

        // ── Build user object ────────────────────────────────────────────────
        $user = [];

        $hashed_fields = [
            'email'      => 'email',
            'phone'      => 'phone_number',
            'first_name' => 'first_name',
            'last_name'  => 'last_name',
        ];
        foreach ( $hashed_fields as $src => $dest ) {
            if ( ! empty( $event->user_data[ $src ] ) ) {
                $user[ $dest ] = $event->user_data[ $src ];
            }
        }

        $raw_fields = [
            'ip'         => 'ip',
            'user_agent' => 'user_agent',
            'ttclid'     => 'ttclid',
        ];
        foreach ( $raw_fields as $src => $dest ) {
            if ( ! empty( $event->user_data[ $src ] ) ) {
                $user[ $dest ] = $event->user_data[ $src ];
            }
        }

        if ( ! empty( $event->user_data['external_id'] ) ) {
            $user['external_id'] = $event->user_data['external_id'];
        }

        // ── page.url from event DTO ──────────────────────────────────────────
        if ( ! empty( $event->event_source_url ) ) {
            $page_url = $event->event_source_url;
        } else {
            $request_uri = isset( $_SERVER['REQUEST_URI'] )
                ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) )
                : '/';
            $page_url = home_url( $request_uri );
        }

        // ── Assemble event payload ───────────────────────────────────────────
        $event_data = [
            'event'      => $tiktok_event,
            'event_time' => time(),
            'event_id'   => $event->event_id,
            'user'       => $user,
            'properties' => [
                'currency'     => $event->custom_data['currency'] ?? 'USD',
                'value'        => $event->custom_data['value'] ?? 0.0,
                'contents'     => $event->custom_data['contents'] ?? [],
                'content_type' => 'product',
            ],
            'page' => [ 'url' => $page_url ],
        ];

        $payload = [
            'pixel_code'   => $pixel_id,
            'event_source' => 'web',
            'partner_name' => 'ServerTrack',
            'data'         => [ $event_data ],
        ];

        // BUG #12 FIX: guard against wp_json_encode() returning false.
        $json = wp_json_encode( $payload );
        if ( false === $json ) {
            ServerTrack_Logger::log(
                'error', 'tiktok',
                'wp_json_encode failed — payload contains non-serialisable data.',
                '', $event->event_id,
                (int) ( $event->custom_data['order_id'] ?? 0 ),
                $event->event_name
                // arg 8 (emq) omitted — defaults to []
            );
            return [ 'status' => 'error', 'http_code' => 0, 'message' => 'JSON encode failed.' ];
        }

        $response = wp_remote_post( self::API_ENDPOINT, [
            'method'  => 'POST',
            'timeout' => 15,
            'headers' => [
                'Content-Type' => 'application/json',
                'Access-Token' => $access_token,
            ],
            'body' => $json,
        ] );

        if ( is_wp_error( $response ) ) {
            // BUG #8c FIX: removed (int) 0 as arg 8 — Logger expects array $emq.
            ServerTrack_Logger::log(
                'error', 'tiktok',
                $response->get_error_message(),
                '', $event->event_id,
                (int) ( $event->custom_data['order_id'] ?? 0 ),
                $event->event_name
                // arg 8 (emq) omitted — defaults to []
            );
            return [ 'status' => 'error', 'message' => $response->get_error_message() ];
        }

        $code     = (int) wp_remote_retrieve_response_code( $response );
        $body_raw = wp_remote_retrieve_body( $response );
        $status   = ( $code >= 200 && $code < 300 ) ? 'success' : 'error';

        // BUG #8c FIX: removed (int) $code as arg 8 — was corrupting emq log field.
        ServerTrack_Logger::log(
            $status, 'tiktok',
            (string) $code, $body_raw,
            $event->event_id,
            (int) ( $event->custom_data['order_id'] ?? 0 ),
            $event->event_name
            // arg 8 (emq) omitted — defaults to []
        );

        return [ 'status' => $status, 'http_code' => $code, 'response' => $body_raw ];
    }
}
