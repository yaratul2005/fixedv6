<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ServerTrack_Admin — v3.2
 *
 * Changes in v3.2:
 *   C1  — Nested <form> bug. All 5 view files had their own <form> +
 *         settings_fields() wrapped inside render_page()'s outer <form>.
 *         Browsers close the outer form at the first inner <form> tag,
 *         stripping the B2 _wp_http_referer override, the admin nonce,
 *         and the outer submit_button(). Fixed: views now contain ONLY
 *         the <table> + submit_button() — no <form> or settings_fields().
 *
 *   C2  — servertrack_source_woo_enabled was in the Sources view but
 *         never registered. Added to servertrack_sources_settings.
 *
 *   C3  — Sources view used servertrack_source_abandonment_enabled;
 *         register_settings() had _cart_abandonment_enabled. Aligned
 *         both to servertrack_source_cart_abandonment_enabled.
 *
 *   C4  — servertrack_abandonment_window_minutes used in view but never
 *         registered. Added (integer, absint, default 60).
 *
 *   C5  — servertrack_source_cf7_enabled not registered. Added.
 *
 *   C6  — servertrack_source_edd_enabled not registered. Added.
 *
 *   C7  — servertrack_source_subscriptions_enabled registered but had
 *         no UI field. Added a Subscriptions row to the Sources view.
 *
 *   C8  — render_health_notice() checked for screen ID
 *         servertrack_page_servertrack-sources (non-existent). Removed.
 *
 *   C9  — General, Meta, TikTok views had the same nested <form> as
 *         Sources. Removed <form>/settings_fields() from all of them.
 *
 * Changes in v3.1:
 *   FIX B1 — CSS class-name mismatches on Settings page header and tab nav.
 *   FIX B2 — Event Sources tab redirect returns to wrong tab after save.
 *
 * Changes in v3.0:
 *   FIX A6 — "View not found." on every Settings tab.
 *
 * Changes in v2.9:
 *   FIX A3 — Removed duplicate wp_ajax_servertrack_clear_log registration.
 *   FIX A4 — render_health_notice() now only renders on ServerTrack admin pages.
 *
 * Changes in v2.8:
 *   FIX BUG-FIX-4 — register_settings() now registers the three source
 *   options that were previously missing.
 */
class ServerTrack_Admin {

    /**
     * Map: tab slug => option group name.
     * Single source of truth — render_page() calls settings_fields() with
     * the matching group. Views must NOT call settings_fields() themselves.
     */
    const TAB_GROUPS = [
        'general' => 'servertrack_general_settings',
        'meta'    => 'servertrack_meta_settings',
        'google'  => 'servertrack_google_settings',
        'tiktok'  => 'servertrack_tiktok_settings',
        'sources' => 'servertrack_sources_settings',
        'license' => 'servertrack_license_settings',
    ];

    private static function settings_url( string $tab = '', array $extra = [] ): string {
        $args = array_merge( [ 'page' => 'servertrack-settings' ], $extra );
        if ( $tab !== '' ) {
            $args['tab'] = $tab;
        }
        return admin_url( 'admin.php?' . http_build_query( $args ) );
    }

    public static function init() {
        add_action( 'admin_init',            [ self::class, 'register_settings' ] );
        add_action( 'admin_init',            [ self::class, 'handle_oauth_callback' ] );
        add_action( 'admin_init',            [ self::class, 'handle_oauth_revoke' ] );
        add_action( 'admin_init',            [ self::class, 'handle_license_actions' ] );
        add_filter( 'woocommerce_admin_order_actions', [ self::class, 'add_manual_purchase_order_action' ], 10, 2 );
        add_action( 'admin_head', [ self::class, 'add_manual_purchase_order_action_css' ] );
        add_action( 'admin_action_servertrack_manual_purchase', [ self::class, 'handle_manual_purchase_action' ] );
        add_action( 'add_meta_boxes', [ self::class, 'add_manual_purchase_meta_box' ] );
        add_action( 'admin_notices', [ self::class, 'render_manual_purchase_notice' ] );
        add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue_assets' ] );
        add_action( 'admin_notices',         [ self::class, 'render_health_notice' ] );
        add_action( 'wp_ajax_servertrack_test_event',          [ self::class, 'ajax_test_event' ] );
        add_action( 'wp_ajax_servertrack_get_logs',            [ self::class, 'ajax_get_logs' ] );
        add_action( 'wp_ajax_servertrack_get_dashboard_stats', [ self::class, 'ajax_get_dashboard_stats' ] );
    }

    // ─────────────────────────────────────────────────────────────────
    // Assets
    // ─────────────────────────────────────────────────────────────────

    public static function enqueue_assets( string $hook ) {
        $allowed_hooks = [
            'settings_page_servertrack',
            'servertrack_page_servertrack-settings',
            'toplevel_page_servertrack',
        ];
        if ( ! in_array( $hook, $allowed_hooks, true ) ) return;

        wp_enqueue_style(
            'servertrack-admin',
            SERVERTRACK_URL . 'admin/assets/admin.css',
            [],
            SERVERTRACK_VERSION
        );
        wp_enqueue_script(
            'servertrack-admin',
            SERVERTRACK_URL . 'admin/assets/admin.js',
            [ 'jquery' ],
            SERVERTRACK_VERSION,
            true
        );
        wp_localize_script( 'servertrack-admin', 'servertrack_admin', [
            'ajax_url'        => admin_url( 'admin-ajax.php' ),
            'nonce'           => wp_create_nonce( 'servertrack_admin_nonce' ),
            'dashboard_nonce' => wp_create_nonce( 'servertrack_dashboard' ),
            'platforms' => [
                'meta'   => [
                    'enabled'    => (bool) get_option( 'servertrack_meta_enabled', 0 ),
                    'configured' => (bool) (
                        get_option( 'servertrack_meta_pixel_id', '' ) &&
                        get_option( 'servertrack_meta_access_token', '' )
                    ),
                ],
                'google' => [
                    'enabled'    => (bool) get_option( 'servertrack_google_enabled', 0 ),
                    'configured' => (bool) get_option( 'servertrack_google_refresh_token', '' ),
                ],
                'tiktok' => [
                    'enabled'    => (bool) get_option( 'servertrack_tiktok_enabled', 0 ),
                    'configured' => (bool) (
                        get_option( 'servertrack_tiktok_pixel_id', '' ) &&
                        get_option( 'servertrack_tiktok_access_token', '' )
                    ),
                ],
            ],
        ] );
    }

    // ─────────────────────────────────────────────────────────────────
    // Settings Registration
    // ─────────────────────────────────────────────────────────────────

    public static function register_settings() {

        $general_options = [
            'servertrack_enabled'      => [ 'type' => 'integer', 'sanitize' => 'absint',                                  'default' => 1      ],
            'servertrack_test_mode'    => [ 'type' => 'integer', 'sanitize' => 'absint',                                  'default' => 0      ],
            'servertrack_consent_mode' => [ 'type' => 'string',  'sanitize' => [ self::class, 'sanitize_consent_mode' ],  'default' => 'none' ],
        ];
        self::register_group( 'servertrack_general_settings', $general_options );

        $meta_options = [
            'servertrack_meta_enabled'         => [ 'type' => 'integer', 'sanitize' => 'absint',              'default' => 0  ],
            'servertrack_meta_pixel_id'        => [ 'type' => 'string',  'sanitize' => 'sanitize_text_field', 'default' => '' ],
            'servertrack_meta_access_token'    => [ 'type' => 'string',  'sanitize' => 'sanitize_text_field', 'default' => '' ],
            'servertrack_meta_test_event_code' => [ 'type' => 'string',  'sanitize' => 'sanitize_text_field', 'default' => '' ],
            'servertrack_meta_am_email'   => [ 'type' => 'integer', 'sanitize' => 'absint', 'default' => 1 ],
            'servertrack_meta_am_phone'   => [ 'type' => 'integer', 'sanitize' => 'absint', 'default' => 1 ],
            'servertrack_meta_am_name'    => [ 'type' => 'integer', 'sanitize' => 'absint', 'default' => 1 ],
            'servertrack_meta_am_city'    => [ 'type' => 'integer', 'sanitize' => 'absint', 'default' => 1 ],
            'servertrack_meta_am_state'   => [ 'type' => 'integer', 'sanitize' => 'absint', 'default' => 1 ],
            'servertrack_meta_am_zip'     => [ 'type' => 'integer', 'sanitize' => 'absint', 'default' => 1 ],
            'servertrack_meta_am_country' => [ 'type' => 'integer', 'sanitize' => 'absint', 'default' => 1 ],
        ];
        self::register_group( 'servertrack_meta_settings', $meta_options );

        $google_options = [
            'servertrack_google_enabled'          => [ 'type' => 'integer', 'sanitize' => 'absint',              'default' => 0  ],
            'servertrack_google_customer_id'      => [ 'type' => 'string',  'sanitize' => 'sanitize_text_field', 'default' => '' ],
            'servertrack_google_conversion_id'    => [ 'type' => 'string',  'sanitize' => 'sanitize_text_field', 'default' => '' ],
            'servertrack_google_conversion_label' => [ 'type' => 'string',  'sanitize' => 'sanitize_text_field', 'default' => '' ],
            'servertrack_google_developer_token'  => [ 'type' => 'string',  'sanitize' => 'sanitize_text_field', 'default' => '' ],
            'servertrack_google_consent_ad_user_data' => [ 'type' => 'integer', 'sanitize' => 'absint', 'default' => 1 ],
            'servertrack_google_consent_ad_personalization' => [ 'type' => 'integer', 'sanitize' => 'absint', 'default' => 1 ],
            'servertrack_google_refresh_token'    => [ 'type' => 'string',  'sanitize' => 'sanitize_text_field', 'default' => '' ],
            'servertrack_google_client_id'        => [ 'type' => 'string',  'sanitize' => 'sanitize_text_field', 'default' => '' ],
            'servertrack_google_client_secret'    => [ 'type' => 'string',  'sanitize' => 'sanitize_text_field', 'default' => '' ],
        ];
        self::register_group( 'servertrack_google_settings', $google_options );

        $tiktok_options = [
            'servertrack_tiktok_enabled'      => [ 'type' => 'integer', 'sanitize' => 'absint',              'default' => 0  ],
            'servertrack_tiktok_pixel_id'     => [ 'type' => 'string',  'sanitize' => 'sanitize_text_field', 'default' => '' ],
            'servertrack_tiktok_access_token' => [ 'type' => 'string',  'sanitize' => 'sanitize_text_field', 'default' => '' ],
        ];
        self::register_group( 'servertrack_tiktok_settings', $tiktok_options );

        /*
         * C2  — servertrack_source_woo_enabled added (was in view, not registered).
         * C3  — key aligned to servertrack_source_cart_abandonment_enabled
         *       (view had _abandonment_enabled, a different name).
         * C4  — servertrack_abandonment_window_minutes added.
         * C5  — servertrack_source_cf7_enabled added.
         * C6  — servertrack_source_edd_enabled added.
         * C7  — servertrack_source_subscriptions_enabled was already here;
         *       a UI toggle has been added to the Sources view.
         */
        $license_options = [
            'servertrack_license_key' => [ 'type' => 'string', 'sanitize' => 'sanitize_text_field', 'default' => '' ],
        ];
        self::register_group( 'servertrack_license_settings', $license_options );

        $sources_options = [
            'servertrack_source_woo_enabled'              => [ 'type' => 'integer', 'sanitize' => 'absint', 'default' => 1  ],
            'servertrack_source_cart_abandonment_enabled' => [ 'type' => 'integer', 'sanitize' => 'absint', 'default' => 0  ],
            'servertrack_abandonment_window_minutes'      => [ 'type' => 'integer', 'sanitize' => 'absint', 'default' => 60 ],
            'servertrack_manual_purchase_enabled'         => [ 'type' => 'integer', 'sanitize' => 'absint', 'default' => 0  ],
            'servertrack_source_order_status_enabled'     => [ 'type' => 'integer', 'sanitize' => 'absint', 'default' => 1  ],
            'servertrack_source_wishlist_enabled'         => [ 'type' => 'integer', 'sanitize' => 'absint', 'default' => 0  ],
            'servertrack_source_partial_refund_enabled'   => [ 'type' => 'integer', 'sanitize' => 'absint', 'default' => 1  ],
            'servertrack_source_cf7_enabled'              => [ 'type' => 'integer', 'sanitize' => 'absint', 'default' => 0  ],
            'servertrack_source_edd_enabled'              => [ 'type' => 'integer', 'sanitize' => 'absint', 'default' => 0  ],
            'servertrack_source_subscriptions_enabled'    => [ 'type' => 'integer', 'sanitize' => 'absint', 'default' => 0  ],
        ];
        self::register_group( 'servertrack_sources_settings', $sources_options );
    }

    private static function register_group( string $group, array $options ): void {
        foreach ( $options as $key => $args ) {
            register_setting(
                $group,
                $key,
                [
                    'type'              => $args['type'],
                    'sanitize_callback' => $args['sanitize'],
                    'default'           => $args['default'],
                ]
            );
        }
    }

    // ─────────────────────────────────────────────────────────────────
    // OAuth callbacks
    // ─────────────────────────────────────────────────────────────────

    public static function handle_oauth_callback(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( empty( $_GET['servertrack_oauth'] ) || empty( $_GET['code'] ) ) {
            return;
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        check_admin_referer( 'servertrack_oauth_google', 'state' );
        $code = sanitize_text_field( wp_unslash( $_GET['code'] ) );
        if ( class_exists( 'ServerTrack_Google_OAuth' ) ) {
            $result = ServerTrack_Google_OAuth::exchange_code( $code );
            $tab    = 'google';
            $extra  = $result ? [ 'oauth' => 'success' ] : [ 'oauth' => 'error' ];
        } else {
            $extra = [ 'oauth' => 'error' ];
            $tab   = 'google';
        }
        wp_safe_redirect( self::settings_url( $tab, $extra ) );
        exit;
    }

            public static function add_manual_purchase_order_action( $actions, $order ) {
        if ( ! get_option( 'servertrack_manual_purchase_enabled', 0 ) ) {
            return $actions;
        }

        if ( $order->get_meta( '_servertrack_manual_purchase_sent' ) === 'yes' ) {
            return $actions;
        }

        $url = wp_nonce_url( admin_url( 'admin.php?action=servertrack_manual_purchase&order_id=' . $order->get_id() ), 'servertrack_manual_purchase_' . $order->get_id() );

        $actions['st_manual_purchase'] = [
            'url'    => $url,
            'name'   => __( 'Fire CAPI Purchase Event', 'servertrack' ),
            'action' => 'st-manual-purchase',
        ];

        return $actions;
    }

    public static function add_manual_purchase_order_action_css() {
        if ( ! get_option( 'servertrack_manual_purchase_enabled', 0 ) ) {
            return;
        }
        echo '<style>.wc-action-button-st-manual-purchase::after { font-family: dashicons; content: "\f502"; color: #0ea5a0; }</style>';
    }

        public static function add_manual_purchase_meta_box() {
        if ( ! get_option( 'servertrack_manual_purchase_enabled', 0 ) ) {
            return;
        }

        $screen = class_exists( '\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController' )
            && function_exists('wc_get_page_screen_id')
            ? wc_get_page_screen_id( 'shop-order' )
            : 'shop_order';

        add_meta_box(
            'servertrack_manual_purchase',
            __( 'ServerTrack - Purchase Event', 'servertrack' ),
            [ self::class, 'render_manual_purchase_meta_box' ],
            $screen,
            'side',
            'high'
        );
    }

    public static function render_manual_purchase_meta_box( $post_or_order_object ) {
        $order = ( $post_or_order_object instanceof WP_Post )
            ? wc_get_order( $post_or_order_object->ID )
            : $post_or_order_object;

        if ( ! $order ) {
            echo '<p>Could not load order.</p>';
            return;
        }

        $sent = $order->get_meta( '_servertrack_manual_purchase_sent' ) === 'yes';
        $url = wp_nonce_url( admin_url( 'admin.php?action=servertrack_manual_purchase&order_id=' . $order->get_id() ), 'servertrack_manual_purchase_' . $order->get_id() );

        if ( $sent ) {
            echo '<div style="color:#10b981; font-weight:600; padding:10px 0;"><span class="dashicons dashicons-yes-alt"></span> ' . __( 'Purchase event successfully synced.', 'servertrack' ) . '</div>';
        } else {
            echo '<p>' . __( 'Manual purchase mode is active. This order has not been synced to advertising platforms yet.', 'servertrack' ) . '</p>';
            echo '<a href="' . esc_url( $url ) . '" class="button button-primary" style="width:100%; text-align:center;">' . __( 'Fire Purchase Event', 'servertrack' ) . '</a>';
        }
    }

    public static function render_manual_purchase_notice() {
        if ( isset( $_GET['st_manual_status'] ) && isset( $_GET['st_manual_msg'] ) ) {
            $class = $_GET['st_manual_status'] === 'success' ? 'notice-success' : 'notice-error';
            $message = sanitize_text_field( urldecode( $_GET['st_manual_msg'] ) );
            printf( '<div class="notice %1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $class ), esc_html( $message ) );
        }
    }

    public static function handle_manual_purchase_action() {
        if ( ! current_user_can( 'edit_shop_orders' ) ) {
            wp_die( 'Unauthorized.' );
        }

        $order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
        check_admin_referer( 'servertrack_manual_purchase_' . $order_id );

        if ( class_exists( 'ServerTrack_Source_WooCommerce' ) ) {
            $result = ServerTrack_Source_WooCommerce::fire_manual_purchase( $order_id );

            $url = admin_url( 'edit.php?post_type=shop_order' );
            if ( function_exists( 'wc_get_page_screen_id' ) && wc_get_page_screen_id( 'shop-order' ) ) {
                $url = admin_url( 'admin.php?page=wc-orders' ); // HPOS
            }

            $url = add_query_arg( [
                'st_manual_status' => $result['success'] ? 'success' : 'error',
                'st_manual_msg'    => urlencode( $result['message'] )
            ], $url );

            wp_safe_redirect( $url );
            die();
        }
    }

    public static function handle_license_actions(): void {
        if ( ! current_user_can( 'manage_options' ) ) return;

        if ( isset( $_POST['st_license_action'] ) && isset( $_POST['servertrack_license_key'] ) ) {
            check_admin_referer( 'servertrack_license_action', 'servertrack_license_nonce' );
            $action = sanitize_text_field( wp_unslash( $_POST['st_license_action'] ) );
            $key = sanitize_text_field( wp_unslash( $_POST['servertrack_license_key'] ) );

            if ( 'activate' === $action ) {
                $result = ServerTrack_License::activate( $key );
                add_settings_error( 'servertrack_license_messages', 'st_license', $result['message'], $result['success'] ? 'success' : 'error' );
            } elseif ( 'deactivate' === $action ) {
                $result = ServerTrack_License::deactivate();
                add_settings_error( 'servertrack_license_messages', 'st_license', $result['message'], $result['success'] ? 'success' : 'error' );
            }
        }
    }

    public static function handle_oauth_revoke(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( empty( $_GET['servertrack_revoke'] ) ) {
            return;
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        check_admin_referer( 'servertrack_revoke_google' );
        if ( class_exists( 'ServerTrack_Google_OAuth' ) ) {
            ServerTrack_Google_OAuth::revoke();
        }
        wp_safe_redirect( self::settings_url( 'google', [ 'revoked' => '1' ] ) );
        exit;
    }

    // ─────────────────────────────────────────────────────────────────
    // Health Notice
    // C8 — Removed non-existent screen ID servertrack_page_servertrack-sources.
    //      The Settings page screen ID is servertrack_page_servertrack-settings.
    // ─────────────────────────────────────────────────────────────────

    public static function render_health_notice(): void {
        $screen = get_current_screen();
        $allowed_screens = [
            'servertrack_page_servertrack-settings',
            'settings_page_servertrack',
            'toplevel_page_servertrack',
        ];
        if ( ! $screen || ! in_array( $screen->id, $allowed_screens, true ) ) {
            return;
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $issues = [];

        if ( get_option( 'servertrack_meta_enabled', 0 ) ) {
            if ( ! get_option( 'servertrack_meta_pixel_id', '' ) || ! get_option( 'servertrack_meta_access_token', '' ) ) {
                $issues[] = sprintf(
                    'Meta CAPI is enabled but missing credentials. <a href="%s">Configure Meta →</a>',
                    esc_url( self::settings_url( 'meta' ) )
                );
            }
        }

        if ( get_option( 'servertrack_google_enabled', 0 ) ) {
            if ( ! get_option( 'servertrack_google_refresh_token', '' ) ) {
                $issues[] = sprintf(
                    'Google Ads is enabled but not authenticated. <a href="%s">Configure Google →</a>',
                    esc_url( self::settings_url( 'google' ) )
                );
            }
        }

        if ( get_option( 'servertrack_tiktok_enabled', 0 ) ) {
            if ( ! get_option( 'servertrack_tiktok_pixel_id', '' ) || ! get_option( 'servertrack_tiktok_access_token', '' ) ) {
                $issues[] = sprintf(
                    'TikTok Events is enabled but missing credentials. <a href="%s">Configure TikTok →</a>',
                    esc_url( self::settings_url( 'tiktok' ) )
                );
            }
        }

        if ( empty( $issues ) ) {
            return;
        }

        echo '<div class="notice notice-warning is-dismissible"><p><strong>ServerTrack:</strong></p><ul>';
        foreach ( $issues as $issue ) {
            echo '<li>' . wp_kses( $issue, [ 'a' => [ 'href' => [] ] ] ) . '</li>';
        }
        echo '</ul></div>';
    }

    // ─────────────────────────────────────────────────────────────────
    // Page Header
    // ─────────────────────────────────────────────────────────────────

    public static function render_page_header(): void {
        ?>
        <div class="st-page-header">
            <div class="st-page-header-left">
                <img
                    src="<?php echo esc_url( SERVERTRACK_URL . 'admin/assets/bglogo.png' ); ?>"
                    alt="ServerTrack"
                    width="46"
                    height="46"
                    class="st-logo-img"
                    onerror="this.style.display='none';this.nextElementSibling.style.display='flex';"
                />
                <span class="st-logo-icon-fallback" style="display:none;">
                    <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M22 12h-4l-3 9L9 3l-3 9H2"/>
                    </svg>
                </span>
                <div class="st-page-title-group">
                    <h1>ServerTrack</h1>
                    <p>Server-Side Tracking</p>
                </div>
            </div>
            <div class="st-header-badges">
                <span class="st-header-version"><?php echo esc_html( 'v' . SERVERTRACK_VERSION ); ?></span>
                <nav>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=servertrack' ) ); ?>"
                       style="color:rgba(255,255,255,.6);text-decoration:none;font-size:.8125rem;margin-right:12px;"
                    ><?php esc_html_e( 'Dashboard', 'servertrack' ); ?></a>
                    <a href="<?php echo esc_url( self::settings_url() ); ?>"
                       style="color:rgba(255,255,255,.6);text-decoration:none;font-size:.8125rem;"
                    ><?php esc_html_e( 'Settings', 'servertrack' ); ?></a>
                </nav>
            </div>
        </div>
        <?php
    }

    // ─────────────────────────────────────────────────────────────────
    // Settings Page
    //
    // The outer <form> here is THE only form on the page.
    // Views must NOT contain their own <form> or settings_fields() call.
    // C1 — All views have been stripped of their nested <form> wrappers.
    // ─────────────────────────────────────────────────────────────────

    public static function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) return;

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general';

        if ( 'servertrack-sources' === $page ) {
            $tab = 'sources';
        }

        if ( ! array_key_exists( $tab, self::TAB_GROUPS ) ) {
            $tab = 'general';
        }
        ?>
        <div class="wrap" id="servertrack-wrap">
        <?php self::render_page_header(); ?>

        <nav class="st-tab-nav">
            <?php
            $tabs = [
                'general' => __( 'General', 'servertrack' ),
                'meta'    => __( 'Meta CAPI', 'servertrack' ),
                'google'  => __( 'Google Ads', 'servertrack' ),
                'tiktok'  => __( 'TikTok', 'servertrack' ),
                'sources' => __( 'Event Sources', 'servertrack' ),
                'license' => __( 'License', 'servertrack' ),
            ];
            foreach ( $tabs as $slug => $label ) :
                $url     = esc_url( self::settings_url( $slug ) );
                $classes = 'nav-tab' . ( $tab === $slug ? ' nav-tab-active' : '' );
            ?>
            <a href="<?php echo $url; ?>" class="<?php echo esc_attr( $classes ); ?>"><?php echo esc_html( $label ); ?></a>
            <?php endforeach; ?>
        </nav>

        <form method="post" action="options.php" class="st-settings-form">
            <?php
            settings_fields( self::TAB_GROUPS[ $tab ] );

            /*
             * B2 FIX — override _wp_http_referer so options.php redirects
             * back to the correct tab after saving.
             */
            $return_url = self::settings_url( $tab );
            echo '<input type="hidden" name="_wp_http_referer" value="' . esc_attr( $return_url ) . '" />';
            ?>

            <?php
            $view = plugin_dir_path( __FILE__ ) . 'views/settings-' . $tab . '.php';
            if ( file_exists( $view ) ) {
                include $view;
            } else {
                echo '<p>' . esc_html__( 'View not found.', 'servertrack' ) . '</p>';
            }
            submit_button();
            ?>
        </form>
        </div>
        <?php
    }

    // ─────────────────────────────────────────────────────────────────
    // Sanitizers
    // ─────────────────────────────────────────────────────────────────

    public static function sanitize_consent_mode( $value ): string {
        $allowed = [ 'none', 'manual', 'cookieyes', 'complianz' ];
        return in_array( $value, $allowed, true ) ? $value : 'none';
    }

    // ─────────────────────────────────────────────────────────────────
    // AJAX Handlers
    // ─────────────────────────────────────────────────────────────────

    public static function ajax_test_event(): void {
        check_ajax_referer( 'servertrack_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

        $platform   = isset( $_POST['platform'] ) ? sanitize_key( wp_unslash( $_POST['platform'] ) ) : '';
        $event_name = isset( $_POST['event_name'] ) ? sanitize_text_field( wp_unslash( $_POST['event_name'] ) ) : 'Purchase';

        $allowed_platforms = [ 'meta', 'google', 'tiktok' ];
        if ( ! in_array( $platform, $allowed_platforms, true ) ) {
            wp_send_json_error( 'Invalid platform.' );
        }

        $event_id = ServerTrack_Dedup::generate_event_id();
        $event    = ( new ServerTrack_Event( $event_name, $event_id ) )
            ->set_custom_data( [ 'value' => 1.00, 'currency' => 'USD' ] );

        $result = [];
        if ( 'meta' === $platform && class_exists( 'ServerTrack_Meta' ) ) {
            $result = ServerTrack_Meta::send( $event );
        } elseif ( 'google' === $platform && class_exists( 'ServerTrack_Google' ) ) {
            $result = ServerTrack_Google::send( $event );
        } elseif ( 'tiktok' === $platform && class_exists( 'ServerTrack_TikTok' ) ) {
            $result = ServerTrack_TikTok::send( $event );
        }

        if ( ! empty( $result['status'] ) && 'success' === $result['status'] ) {
            wp_send_json_success( $result );
        } else {
            wp_send_json_error( $result );
        }
    }

    public static function ajax_get_logs(): void {
        check_ajax_referer( 'servertrack_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

        $logs   = get_option( 'servertrack_debug_log', [] );
        $recent = array_slice( array_reverse( $logs ), 0, 200 );
        $count  = count( $logs );

        ob_start();
        ServerTrack_Dashboard::render_log_rows( $recent );
        $html = ob_get_clean();

        wp_send_json_success( [
            'html'  => $html,
            'total' => $count,
        ] );
    }

    public static function ajax_get_dashboard_stats(): void {
        check_ajax_referer( 'servertrack_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

        $stats = get_option( 'servertrack_stats', [] );
        wp_send_json_success( $stats );
    }
}
