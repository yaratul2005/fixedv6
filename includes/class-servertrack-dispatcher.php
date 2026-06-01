<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ServerTrack_Dispatcher  v1.0
 *
 * Async dispatch pipeline for ServerTrack.
 * Uses wp_remote_post loopback mechanism to avoid blocking checkout.
 */
class ServerTrack_Dispatcher {

    const ACTION_NAME = 'servertrack_async_dispatch';

    public static function init(): void {
        // add_action( 'admin_post_nopriv_' . self::ACTION_NAME, [ self::class, 'process_dispatch' ] );
        add_action( self::ACTION_NAME, [ self::class, 'process_dispatch_as' ] );
    }

    /**
     * Dispatch an event to the requested platforms asynchronously.
     *
     * @param ServerTrack_Event $event
     * @param array $platforms
     * @param string|int|null $dedup_key
     */
        public static function dispatch( ServerTrack_Event $event, array $platforms, $dedup_key = null ): void {
        $key = (string) ( $dedup_key ?? $event->event_id );
        $payload = [
            'event'     => wp_json_encode( ServerTrack_Retry::event_to_args( $event ) ),
            'platforms' => wp_json_encode( $platforms ),
            'dedup_key' => $key,
        ];

        as_enqueue_async_action( self::ACTION_NAME, [ $payload ] );
    }

    public static function process_dispatch_as( $payload ) {
        $event_data = isset( $payload['event'] ) ? json_decode( $payload['event'], true ) : [];
        $platforms  = isset( $payload['platforms'] ) ? json_decode( $payload['platforms'], true ) : [];
        $dedup_key  = isset( $payload['dedup_key'] ) ? sanitize_text_field( $payload['dedup_key'] ) : '';

        if ( empty( $event_data ) || empty( $platforms ) ) {
            return;
        }

        $event = new ServerTrack_Event( $event_data['event_name'] ?? '', $event_data['event_id'] ?? '' );
        $event->set_user_data( $event_data['user_data'] ?? [] )
              ->set_custom_data( $event_data['custom_data'] ?? [] );

        if ( ! empty( $event_data['event_source_url'] ) ) {
            $event->set_source_url( $event_data['event_source_url'] );
        }

        foreach ( $platforms as $platform ) {
            if ( ServerTrack_Dedup::already_sent( $dedup_key, $platform ) ) {
                continue;
            }

            $result = [ 'status' => 'skipped' ];

            switch ( $platform ) {
                case 'meta':
                    if ( get_option( 'servertrack_meta_enabled', 0 ) ) {
                        $result = ServerTrack_Meta::send( $event );
                    }
                    break;
                case 'tiktok':
                    if ( get_option( 'servertrack_tiktok_enabled', 0 ) ) {
                        $result = ServerTrack_TikTok::send( $event );
                    }
                    break;
                case 'google':
                    if ( get_option( 'servertrack_google_enabled', 0 ) ) {
                        $result = ServerTrack_Google::send( $event );
                    }
                    break;
                case 'snapchat':
                    if ( get_option( 'servertrack_snapchat_enabled', 0 ) ) {
                        $result = ServerTrack_Snapchat::send( $event );
                    }
                    break;
                case 'pinterest':
                    if ( get_option( 'servertrack_pinterest_enabled', 0 ) ) {
                        $result = ServerTrack_Pinterest::send( $event );
                    }
                    break;
                case 'linkedin':
                    if ( get_option( 'servertrack_linkedin_enabled', 0 ) ) {
                        $result = ServerTrack_LinkedIn::send( $event );
                    }
                    break;
            }

            if ( isset( $result['status'] ) && $result['status'] === 'success' ) {
                ServerTrack_Dedup::mark_sent( $dedup_key, $platform );
            } elseif ( isset( $result['status'] ) && $result['status'] === 'error' ) {
                ServerTrack_Retry::maybe_queue( $platform, $result, $event_data );
            }
        }
    }

    /**
     * Handle the async dispatch loopback.
     */
    public static function process_dispatch(): void {
        if ( empty( $_POST['action'] ) || $_POST['action'] !== self::ACTION_NAME ) {
            return;
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
        if ( empty( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], self::ACTION_NAME ) ) {
            return;
        }

        $event_data = isset( $_POST['event'] ) ? json_decode( wp_unslash( $_POST['event'] ), true ) : [];
        $platforms  = isset( $_POST['platforms'] ) ? json_decode( wp_unslash( $_POST['platforms'] ), true ) : [];
        $dedup_key  = isset( $_POST['dedup_key'] ) ? sanitize_text_field( wp_unslash( $_POST['dedup_key'] ) ) : '';

        if ( empty( $event_data ) || empty( $platforms ) ) {
            return;
        }

        $event = new ServerTrack_Event( $event_data['event_name'] ?? '', $event_data['event_id'] ?? '' );
        $event->set_user_data( $event_data['user_data'] ?? [] )
              ->set_custom_data( $event_data['custom_data'] ?? [] );

        if ( ! empty( $event_data['event_source_url'] ) ) {
            $event->set_source_url( $event_data['event_source_url'] );
        }

        foreach ( $platforms as $platform ) {
            if ( ServerTrack_Dedup::already_sent( $dedup_key, $platform ) ) {
                continue;
            }

            $result = [ 'status' => 'skipped' ];

            switch ( $platform ) {
                case 'meta':
                    if ( get_option( 'servertrack_meta_enabled', 0 ) ) {
                        $result = ServerTrack_Meta::send( $event );
                    }
                    break;
                case 'tiktok':
                    if ( get_option( 'servertrack_tiktok_enabled', 0 ) ) {
                        $result = ServerTrack_TikTok::send( $event );
                    }
                    break;
                case 'google':
                    if ( get_option( 'servertrack_google_enabled', 0 ) ) {
                        $result = ServerTrack_Google::send( $event );
                    }
                    break;
                case 'snapchat':
                    if ( get_option( 'servertrack_snapchat_enabled', 0 ) ) {
                        $result = ServerTrack_Snapchat::send( $event );
                    }
                    break;
                case 'pinterest':
                    if ( get_option( 'servertrack_pinterest_enabled', 0 ) ) {
                        $result = ServerTrack_Pinterest::send( $event );
                    }
                    break;
                case 'linkedin':
                    if ( get_option( 'servertrack_linkedin_enabled', 0 ) ) {
                        $result = ServerTrack_LinkedIn::send( $event );
                    }
                    break;
            }

            if ( isset( $result['status'] ) && $result['status'] === 'success' ) {
                ServerTrack_Dedup::mark_sent( $dedup_key, $platform );
            } elseif ( isset( $result['status'] ) && $result['status'] === 'error' ) {
                ServerTrack_Retry::maybe_queue( $platform, $result, $event_data );
            }
        }
    }
}
