<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ServerTrack_Pinterest {
    public static function send( ServerTrack_Event $event ): array {
        if ( ! get_option( 'servertrack_pinterest_enabled', 0 ) ) {
            return [ 'status' => 'skipped', 'http_code' => 0 ];
        }

        // Dummy sender
        return [ 'status' => 'success', 'http_code' => 200, 'response' => 'Dummy response' ];
    }
}
