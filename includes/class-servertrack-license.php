<?php
if ( ! defined( 'ABSPATH' ) ) {
    die();
}

/**
 * ServerTrack_License
 *
 * Handles license key validation and communication with the WC Key Manager API.
 */
class ServerTrack_License {

    const STORE_URL = 'https://great10.xyz';
    const ITEM_ID = ''; // Optional if key-based resolution works natively on wckm
    const CACHE_KEY = 'servertrack_license_status';

    public static function init(): void {
        add_filter( 'pre_set_site_transient_update_plugins', [ self::class, 'check_plugin_updates' ] );
        add_filter( 'plugins_api', [ self::class, 'plugin_info' ], 20, 3 );
    }

    public static function check_plugin_updates( $transient ) {
        if ( empty( $transient->checked ) ) {
            return $transient;
        }

        $plugin_slug = plugin_basename( SERVERTRACK_DIR . 'servertrack.php' );

        $update_info = self::check_for_updates();

        if ( ! empty( $update_info['new_version'] ) && version_compare( SERVERTRACK_VERSION, $update_info['new_version'], '<' ) ) {
            $obj = new stdClass();
            $obj->slug        = 'servertrack';
            $obj->new_version = $update_info['new_version'];
            $obj->url         = $update_info['url'];
            $obj->package     = $update_info['package'];
            $obj->tested      = $update_info['tested'];
            $obj->requires_php = $update_info['requires_php'];

            $transient->response[ $plugin_slug ] = $obj;
        }

        return $transient;
    }

    public static function plugin_info( $res, $action, $args ) {
        if ( 'plugin_information' !== $action ) {
            return $res;
        }

        if ( 'servertrack' !== $args->slug ) {
            return $res;
        }

        $update_info = self::check_for_updates();

        if ( empty( $update_info ) ) {
            return $res;
        }

        $obj = new stdClass();
        $obj->name          = 'ServerTrack';
        $obj->slug          = 'servertrack';
        $obj->version       = $update_info['new_version'];
        $obj->tested        = $update_info['tested'];
        $obj->requires_php  = $update_info['requires_php'];
        $obj->download_link = $update_info['package'];

        if ( ! empty( $update_info['sections'] ) ) {
            $obj->sections = $update_info['sections'];
        }
        if ( ! empty( $update_info['banners'] ) ) {
            $obj->banners = $update_info['banners'];
        }

        return $obj;
    }

    /**
     * Checks if the current license is active.
     */
    public static function is_active(): bool {
        $status = get_option( self::CACHE_KEY, false );
        return $status === 'valid';
    }

    /**
     * Activates a license key with the central server.
     */
    public static function activate( string $license_key ): array {
        if ( empty( $license_key ) ) {
            return [ 'success' => false, 'message' => 'License key cannot be empty.' ];
        }

        $url = add_query_arg( [
            'wckm-api' => 'activate_key',
            'key'      => $license_key,
            'instance' => home_url()
        ], self::STORE_URL );

        $response = wp_remote_get( $url, [ 'timeout' => 15 ] );

        if ( is_wp_error( $response ) ) {
            return [ 'success' => false, 'message' => 'Could not connect to the license server.' ];
        }

        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( ! empty( $data['success'] ) && $data['success'] === true ) {
            update_option( 'servertrack_license_key', $license_key );
            update_option( self::CACHE_KEY, 'valid' );
            return [ 'success' => true, 'message' => 'License activated successfully.' ];
        }

        $error = $data['message'] ?? 'Invalid license key.';
        update_option( self::CACHE_KEY, 'invalid' );

        return [ 'success' => false, 'message' => $error ];
    }

    /**
     * Deactivates a license key.
     */
    public static function deactivate(): array {
        $license_key = get_option( 'servertrack_license_key', '' );

        if ( empty( $license_key ) ) {
            return [ 'success' => false, 'message' => 'No active license key found.' ];
        }

        $url = add_query_arg( [
            'wckm-api' => 'deactivate_key',
            'key'      => $license_key,
            'instance' => home_url()
        ], self::STORE_URL );

        $response = wp_remote_get( $url, [ 'timeout' => 15 ] );

        // Always delete local state regardless of remote success to allow user to force-reset
        delete_option( 'servertrack_license_key' );
        delete_option( self::CACHE_KEY );

        if ( is_wp_error( $response ) ) {
            return [ 'success' => true, 'message' => 'License deactivated locally (could not reach server).' ];
        }

        return [ 'success' => true, 'message' => 'License deactivated successfully.' ];
    }

    /**
     * Checks for updates from the licensing server.
     */
    public static function check_for_updates(): array {
        $license_key = get_option( 'servertrack_license_key', '' );

        if ( empty( $license_key ) ) {
            return [];
        }

        $url = add_query_arg( [
            'wckm-api' => 'get_version',
            'key'      => $license_key
        ], self::STORE_URL );

        $response = wp_remote_get( $url, [ 'timeout' => 15 ] );

        if ( is_wp_error( $response ) ) {
            return [];
        }

        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( ! empty( $data['success'] ) && $data['success'] === true ) {
            return [
                'new_version'   => $data['new_version'] ?? '',
                'package'       => $data['package'] ?? $data['download_link'] ?? '',
                'url'           => $data['homepage'] ?? self::STORE_URL,
                'tested'        => $data['min_wp'] ?? '',
                'requires_php'  => $data['min_php'] ?? '',
                'sections'      => self::parse_array_data( $data['sections'] ?? [] ),
                'banners'       => self::parse_array_data( $data['banners'] ?? [] ),
            ];
        }

        return [];
    }

    /**
     * Parse array data securely, expecting native array or JSON string.
     *
     * Replacing unserialize with JSON decoding mitigates PHP Object Injection vulnerabilities.
     *
     * @param mixed $data The data to parse.
     * @return array The parsed array data.
     */
    private static function parse_array_data( $data ): array {
        if ( is_array( $data ) ) {
            return $data;
        }
        if ( is_string( $data ) ) {
            $decoded = json_decode( $data, true );
            if ( is_array( $decoded ) ) {
                return $decoded;
            }
        }
        return [];
    }
}