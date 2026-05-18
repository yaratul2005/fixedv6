<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ServerTrack_Google  v2.3
 *
 * Google Ads Offline Conversion API driver.
 *
 * Changes in v2.3 (Bug fixes):
 *
 *   Bug #12 — wp_json_encode() failure not caught:
 *     build_payload() can produce non-serialisable values if order data
 *     contains malformed strings. Checked; logs + returns error on false.
 *
 *   Bug #13 — get_woocommerce_currency() in async build_payload:
 *     build_payload() called get_woocommerce_currency() as a fallback.
 *     While WC is loaded in cron, this is still fragile. Currency is now
 *     read from $event->custom_data['currency'] first (set at event-fire
 *     time in browser context by build_purchase_custom_data()). The
 *     get_woocommerce_currency() fallback is kept but marked as cron-safe.
 *
 * Changes in v2.2 (Bug #8 fix):
 *   Logger::log() arg 8 was passing (int) $code instead of (array) $emq.
 *   Removed arg 8 from all Logger calls — defaults to [].
 *
 * Changes in v2.1:
 *   - Always compare token expiry against time() before every API call.
 *   - Inlined token refresh to avoid stale token on first cron of the day.
 */

class ServerTrack_Google {

    const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';
    const API_VERSION    = 'v16';

    public static function send( ServerTrack_Event $event ): array {
        if ( ! get_option( 'servertrack_google_enabled', 0 ) ) {
            return [ 'status' => 'skipped', 'http_code' => 0 ];
        }

        $customer_id   = get_option( 'servertrack_google_customer_id', '' );
        $conversion_id = get_option( 'servertrack_google_conversion_id', '' );
        $conv_label    = get_option( 'servertrack_google_conversion_label', '' );

        if ( ! $customer_id || ! $conversion_id || ! $conv_label ) {
            ServerTrack_Logger::log( 'error', 'google', 'Missing Google Ads credentials (customer_id / conversion_id / label).' );
            return [ 'status' => 'error', 'http_code' => 0, 'message' => 'Missing credentials.' ];
        }

        $access_token = self::get_access_token();
        if ( ! $access_token ) {
            return [ 'status' => 'error', 'http_code' => 0, 'message' => 'No access token.' ];
        }

        $payload = self::build_payload( $event, $conversion_id, $conv_label );

        // BUG #12 FIX: guard against wp_json_encode() returning false.
        $json = wp_json_encode( $payload );
        if ( false === $json ) {
            ServerTrack_Logger::log(
                'error', 'google',
                'wp_json_encode failed — payload contains non-serialisable data.',
                '', $event->event_id,
                (int) ( $event->custom_data['order_id'] ?? 0 ),
                $event->event_name
            );
            return [ 'status' => 'error', 'http_code' => 0, 'message' => 'JSON encode failed.' ];
        }

        $endpoint = 'https://googleads.googleapis.com/' . self::API_VERSION . '/customers/' . $customer_id . ':uploadClickConversions';

        $response = wp_remote_post( $endpoint, [
            'method'  => 'POST',
            'timeout' => 15,
            'headers' => [
                'Authorization'     => 'Bearer ' . $access_token,
                'Content-Type'      => 'application/json',
                'developer-token'   => get_option( 'servertrack_google_developer_token', '' ),
                'login-customer-id' => $customer_id,
            ],
            'body'    => $json,
        ] );

        if ( is_wp_error( $response ) ) {
            ServerTrack_Logger::log(
                'error', 'google',
                $response->get_error_message(),
                '', $event->event_id,
                (int) ( $event->custom_data['order_id'] ?? 0 ),
                $event->event_name
                // arg 8 (emq) omitted — defaults to []
            );
            return [ 'status' => 'error', 'http_code' => 0, 'message' => $response->get_error_message() ];
        }

        $code     = (int) wp_remote_retrieve_response_code( $response );
        $body_raw = wp_remote_retrieve_body( $response );
        $status   = ( $code >= 200 && $code < 300 ) ? 'success' : 'error';

        ServerTrack_Logger::log(
            $status, 'google',
            (string) $code, $body_raw,
            $event->event_id,
            (int) ( $event->custom_data['order_id'] ?? 0 ),
            $event->event_name
            // arg 8 (emq) omitted — defaults to []
        );

        return [ 'status' => $status, 'http_code' => $code, 'response' => $body_raw ];
    }

    private static function get_access_token(): string {
        $token   = (string) get_option( 'servertrack_google_access_token', '' );
        $expires = (int) get_option( 'servertrack_google_token_expires', 0 );

        if ( $token && $expires > time() + 60 ) {
            return $token;
        }

        return self::refresh_access_token();
    }

    private static function refresh_access_token(): string {
        $client_id     = get_option( 'servertrack_google_client_id', '' );
        $client_secret = get_option( 'servertrack_google_client_secret', '' );
        $refresh_token = get_option( 'servertrack_google_refresh_token', '' );

        if ( empty( $client_id ) || empty( $client_secret ) || empty( $refresh_token ) ) {
            ServerTrack_Logger::log( 'error', 'google', 'OAuth credentials missing — cannot refresh token.' );
            return '';
        }

        $response = wp_remote_post( self::TOKEN_ENDPOINT, [
            'method'  => 'POST',
            'timeout' => 15,
            'headers' => [ 'Content-Type' => 'application/x-www-form-urlencoded' ],
            'body'    => [
                'client_id'     => $client_id,
                'client_secret' => $client_secret,
                'refresh_token' => $refresh_token,
                'grant_type'    => 'refresh_token',
            ],
        ] );

        if ( is_wp_error( $response ) ) {
            ServerTrack_Logger::log( 'error', 'google', 'Token refresh failed: ' . $response->get_error_message() );
            return '';
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( empty( $data['access_token'] ) ) {
            ServerTrack_Logger::log(
                'error', 'google',
                'Token refresh returned no access_token. Response: ' . wp_remote_retrieve_body( $response )
            );
            return '';
        }

        $new_token  = $data['access_token'];
        $expires_in = (int) ( $data['expires_in'] ?? 3600 );

        update_option( 'servertrack_google_access_token', $new_token );
        update_option( 'servertrack_google_token_expires', time() + $expires_in );

        return $new_token;
    }

    private static function build_payload( ServerTrack_Event $event, string $conversion_id, string $conv_label ): array {
        $conversion_time = gmdate( 'c', $event->event_time ?? time() );
        $customer_id     = get_option( 'servertrack_google_customer_id' );

        // BUG #13 FIX: read currency from event DTO (captured in browser context).
        // Falls back to get_woocommerce_currency() only when absent (cron-safe
        // because WC is loaded during cron, but DTO value is always preferred).
        $currency = ! empty( $event->custom_data['currency'] )
            ? strtoupper( $event->custom_data['currency'] )
            : strtoupper( get_woocommerce_currency() );

        $click_conversion = [
            'conversionAction'   => 'customers/' . $customer_id . '/conversionActions/' . $conversion_id,
            'conversionDateTime' => $conversion_time,
            'conversionValue'    => (float) ( $event->custom_data['value'] ?? 0 ),
            'currencyCode'       => $currency,
            'orderId'            => (string) ( $event->custom_data['order_id'] ?? '' ),
        ];

        $user_identifiers = [];
        $ud = $event->user_data ?? [];
        if ( ! empty( $ud['em'] ) ) $user_identifiers[] = [ 'hashedEmail' => $ud['em'] ];
        if ( ! empty( $ud['ph'] ) ) $user_identifiers[] = [ 'hashedPhoneNumber' => $ud['ph'] ];
        if ( ! empty( $ud['fn'] ) || ! empty( $ud['ln'] ) ) {
            $address = [];
            if ( ! empty( $ud['fn'] ) )      $address['hashedFirstName'] = $ud['fn'];
            if ( ! empty( $ud['ln'] ) )      $address['hashedLastName']  = $ud['ln'];
            if ( ! empty( $ud['ct'] ) )      $address['city']            = $ud['ct'];
            if ( ! empty( $ud['st'] ) )      $address['state']           = $ud['st'];
            if ( ! empty( $ud['zp'] ) )      $address['postalCode']      = $ud['zp'];
            if ( ! empty( $ud['country'] ) ) $address['countryCode']     = strtoupper( $ud['country'] );
            $user_identifiers[] = [ 'addressInfo' => $address ];
        }
        if ( $user_identifiers ) {
            $click_conversion['userIdentifiers'] = $user_identifiers;
        }
        if ( ! empty( $ud['gclid'] ) ) {
            $click_conversion['gclid'] = $ud['gclid'];
        }

        // Google Consent Mode v2
        $consent_granted = ServerTrack_Consent::is_granted( 'google', (int) ($event->custom_data['order_id'] ?? 0) );
        $click_conversion['consent'] = [
            'adUserData'        => $consent_granted ? 'GRANTED' : 'DENIED',
            'adPersonalization' => $consent_granted ? 'GRANTED' : 'DENIED',
        ];

        return [
            'conversions'    => [ $click_conversion ],
            'partialFailure' => true,
            'validateOnly'   => false,
        ];
    }
}
