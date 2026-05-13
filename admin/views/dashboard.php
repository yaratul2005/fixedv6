<?php
/**
 * ServerTrack — Dashboard Overview Tab  v2.3
 *
 * Uses .st-dashboard-grid CSS class for responsive two-column layout.
 * The old inline style="display:grid;grid-template-columns:1fr 320px"
 * has been removed — it had no responsive breakpoint and broke on narrow screens.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$st_meta_configured   = get_option( 'servertrack_meta_enabled', 0 )
                        && get_option( 'servertrack_meta_pixel_id', '' )
                        && get_option( 'servertrack_meta_access_token', '' );
$st_google_configured = get_option( 'servertrack_google_enabled', 0 )
                        && get_option( 'servertrack_google_refresh_token', '' );
$st_tiktok_configured = get_option( 'servertrack_tiktok_enabled', 0 )
                        && get_option( 'servertrack_tiktok_pixel_id', '' )
                        && get_option( 'servertrack_tiktok_access_token', '' );
?>

<!-- KPI Cards -->
<div class="st-kpi-grid" id="st-kpi-grid">

    <div class="st-kpi-card">
        <div class="st-kpi-icon st-kpi-icon-teal">
            <svg viewBox="0 0 24 24"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
        </div>
        <div class="st-kpi-value" id="st-kpi-total">
            <div class="st-skeleton st-skeleton-kpi-value"></div>
        </div>
        <div class="st-kpi-label" id="st-kpi-label-total">
            <div class="st-skeleton st-skeleton-kpi-label"></div>
        </div>
        <div class="st-kpi-trend st-kpi-trend-info"></div>
    </div>

    <div class="st-kpi-card">
        <div class="st-kpi-icon st-kpi-icon-green">
            <svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
        </div>
        <div class="st-kpi-value" id="st-kpi-success">
            <div class="st-skeleton st-skeleton-kpi-value"></div>
        </div>
        <div class="st-kpi-label" id="st-kpi-label-success">
            <div class="st-skeleton st-skeleton-kpi-label"></div>
        </div>
        <div class="st-kpi-trend st-kpi-trend-success"></div>
    </div>

    <div class="st-kpi-card">
        <div class="st-kpi-icon st-kpi-icon-red">
            <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
        </div>
        <div class="st-kpi-value" id="st-kpi-failed">
            <div class="st-skeleton st-skeleton-kpi-value"></div>
        </div>
        <div class="st-kpi-label" id="st-kpi-label-failed">
            <div class="st-skeleton st-skeleton-kpi-label"></div>
        </div>
        <div class="st-kpi-trend st-kpi-trend-error"></div>
    </div>

    <div class="st-kpi-card">
        <div class="st-kpi-icon st-kpi-icon-blue">
            <svg viewBox="0 0 24 24"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
        </div>
        <div class="st-kpi-value" id="st-kpi-rate">
            <div class="st-skeleton st-skeleton-kpi-value"></div>
        </div>
        <div class="st-kpi-label" id="st-kpi-label-rate">
            <div class="st-skeleton st-skeleton-kpi-label"></div>
        </div>
        <div class="st-kpi-trend st-kpi-trend-info"></div>
    </div>

</div><!-- /.st-kpi-grid -->

<!-- Responsive two-column layout: Platform Health + Activity Feed -->
<div class="st-dashboard-grid">

    <!-- Platform Health Cards -->
    <div>
        <div class="st-card-title" style="margin-bottom:12px">
            <svg viewBox="0 0 24 24" style="width:16px;height:16px;fill:none;stroke:var(--st-brand);stroke-width:2;stroke-linecap:round;stroke-linejoin:round">
                <path d="M22 12h-4l-3 9L9 3l-3 9H2"/>
            </svg>
            <?php esc_html_e( 'Platform Status', 'servertrack' ); ?>
        </div>

        <div class="st-platform-grid">

            <!-- Meta CAPI -->
            <div class="st-platform-card">
                <div class="st-platform-card-header">
                    <div class="st-platform-identity">
                        <div class="st-platform-logo st-platform-logo-meta">
                            <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M12 2C6.477 2 2 6.477 2 12c0 4.991 3.657 9.128 8.438 9.879V14.89h-2.54V12h2.54V9.797c0-2.506 1.492-3.89 3.777-3.89 1.094 0 2.238.195 2.238.195v2.46h-1.26c-1.243 0-1.63.771-1.63 1.562V12h2.773l-.443 2.89h-2.33v6.989C18.343 21.129 22 16.99 22 12c0-5.523-4.477-10-10-10z"/></svg>
                        </div>
                        <div>
                            <div class="st-platform-name"><?php esc_html_e( 'Meta CAPI', 'servertrack' ); ?></div>
                            <div class="st-platform-subtext"><?php esc_html_e( 'Conversions API', 'servertrack' ); ?></div>
                        </div>
                    </div>
                    <span class="st-status-pill <?php echo $st_meta_configured ? 'st-status-ok' : ( get_option('servertrack_meta_enabled',0) ? 'st-status-warning' : 'st-status-inactive' ); ?>" id="st-health-pill-meta">
                        <span class="st-status-pill-dot"></span>
                        <?php echo $st_meta_configured ? esc_html__( 'Active', 'servertrack' ) : ( get_option('servertrack_meta_enabled',0) ? esc_html__( 'Setup Required', 'servertrack' ) : esc_html__( 'Inactive', 'servertrack' ) ); ?>
                    </span>
                </div>
                <div class="st-platform-stats">
                    <div class="st-platform-stat">
                        <div class="st-platform-stat-val" id="st-last-send-meta"><?php esc_html_e( '—', 'servertrack' ); ?></div>
                        <div class="st-platform-stat-key"><?php esc_html_e( 'Last Send', 'servertrack' ); ?></div>
                    </div>
                    <div class="st-platform-stat">
                        <div class="st-platform-stat-val"><?php echo $st_meta_configured ? esc_html__( 'Connected', 'servertrack' ) : esc_html__( 'Not set', 'servertrack' ); ?></div>
                        <div class="st-platform-stat-key"><?php esc_html_e( 'Token', 'servertrack' ); ?></div>
                    </div>
                </div>
                <button type="button" class="st-test-btn" data-platform="meta">
                    <svg viewBox="0 0 24 24"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                    <?php esc_html_e( 'Send Test → Meta', 'servertrack' ); ?>
                </button>
                <div class="st-test-result" id="st-test-result-meta"></div>
            </div>

            <!-- Google Ads -->
            <div class="st-platform-card">
                <div class="st-platform-card-header">
                    <div class="st-platform-identity">
                        <div class="st-platform-logo st-platform-logo-google">
                            <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M21.35 11.1H12.18V13.83H18.69C18.36 17.64 15.19 19.27 12.19 19.27C8.36 19.27 5 16.25 5 12C5 7.9 8.2 4.73 12.2 4.73C15.29 4.73 17.1 6.7 17.1 6.7L19 4.72C19 4.72 16.56 2 12.1 2C6.42 2 2.03 6.8 2.03 12C2.03 17.05 6.16 22 12.25 22C17.6 22 21.5 18.33 21.5 12.91C21.5 11.76 21.35 11.1 21.35 11.1Z"/></svg>
                        </div>
                        <div>
                            <div class="st-platform-name"><?php esc_html_e( 'Google Ads', 'servertrack' ); ?></div>
                            <div class="st-platform-subtext"><?php esc_html_e( 'Enhanced Conversions', 'servertrack' ); ?></div>
                        </div>
                    </div>
                    <span class="st-status-pill <?php echo $st_google_configured ? 'st-status-ok' : ( get_option('servertrack_google_enabled',0) ? 'st-status-warning' : 'st-status-inactive' ); ?>" id="st-health-pill-google">
                        <span class="st-status-pill-dot"></span>
                        <?php echo $st_google_configured ? esc_html__( 'Active', 'servertrack' ) : ( get_option('servertrack_google_enabled',0) ? esc_html__( 'Setup Required', 'servertrack' ) : esc_html__( 'Inactive', 'servertrack' ) ); ?>
                    </span>
                </div>
                <div class="st-platform-stats">
                    <div class="st-platform-stat">
                        <div class="st-platform-stat-val" id="st-last-send-google"><?php esc_html_e( '—', 'servertrack' ); ?></div>
                        <div class="st-platform-stat-key"><?php esc_html_e( 'Last Send', 'servertrack' ); ?></div>
                    </div>
                    <div class="st-platform-stat">
                        <div class="st-platform-stat-val"><?php echo $st_google_configured ? esc_html__( 'OAuth OK', 'servertrack' ) : esc_html__( 'Not set', 'servertrack' ); ?></div>
                        <div class="st-platform-stat-key"><?php esc_html_e( 'OAuth', 'servertrack' ); ?></div>
                    </div>
                </div>
                <button type="button" class="st-test-btn" data-platform="google">
                    <svg viewBox="0 0 24 24"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                    <?php esc_html_e( 'Send Test → Google', 'servertrack' ); ?>
                </button>
                <div class="st-test-result" id="st-test-result-google"></div>
            </div>

            <!-- TikTok -->
            <div class="st-platform-card">
                <div class="st-platform-card-header">
                    <div class="st-platform-identity">
                        <div class="st-platform-logo st-platform-logo-tiktok">
                            <svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor"><path d="M19.321 5.562a5.124 5.124 0 0 1-.443-.258 6.228 6.228 0 0 1-1.137-.966c-.849-.971-1.166-1.956-1.282-2.645h.004C16.368 1.2 16.4 1 16.4 1h-3.797v14.4c0 .193 0 .384-.008.573-.008.203-.018.4-.038.586a3.04 3.04 0 0 1-.303 1.067 3.065 3.065 0 0 1-2.742 1.693 3.072 3.072 0 0 1-3.072-3.072 3.072 3.072 0 0 1 3.072-3.072c.3 0 .588.044.862.123V9.386a6.896 6.896 0 0 0-.862-.055 6.868 6.868 0 0 0-6.868 6.868A6.868 6.868 0 0 0 10.512 23a6.868 6.868 0 0 0 6.868-6.868V8.545a9.984 9.984 0 0 0 5.82 1.868V6.636a6.242 6.242 0 0 1-3.879-1.074z"/></svg>
                        </div>
                        <div>
                            <div class="st-platform-name"><?php esc_html_e( 'TikTok Events', 'servertrack' ); ?></div>
                            <div class="st-platform-subtext"><?php esc_html_e( 'Events API', 'servertrack' ); ?></div>
                        </div>
                    </div>
                    <span class="st-status-pill <?php echo $st_tiktok_configured ? 'st-status-ok' : ( get_option('servertrack_tiktok_enabled',0) ? 'st-status-warning' : 'st-status-inactive' ); ?>" id="st-health-pill-tiktok">
                        <span class="st-status-pill-dot"></span>
                        <?php echo $st_tiktok_configured ? esc_html__( 'Active', 'servertrack' ) : ( get_option('servertrack_tiktok_enabled',0) ? esc_html__( 'Setup Required', 'servertrack' ) : esc_html__( 'Inactive', 'servertrack' ) ); ?>
                    </span>
                </div>
                <div class="st-platform-stats">
                    <div class="st-platform-stat">
                        <div class="st-platform-stat-val" id="st-last-send-tiktok"><?php esc_html_e( '—', 'servertrack' ); ?></div>
                        <div class="st-platform-stat-key"><?php esc_html_e( 'Last Send', 'servertrack' ); ?></div>
                    </div>
                    <div class="st-platform-stat">
                        <div class="st-platform-stat-val"><?php echo $st_tiktok_configured ? esc_html__( 'Connected', 'servertrack' ) : esc_html__( 'Not set', 'servertrack' ); ?></div>
                        <div class="st-platform-stat-key"><?php esc_html_e( 'Token', 'servertrack' ); ?></div>
                    </div>
                </div>
                <button type="button" class="st-test-btn" data-platform="tiktok">
                    <svg viewBox="0 0 24 24"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                    <?php esc_html_e( 'Send Test → TikTok', 'servertrack' ); ?>
                </button>
                <div class="st-test-result" id="st-test-result-tiktok"></div>
            </div>

        </div><!-- /.st-platform-grid -->
    </div><!-- /platform health col -->

    <!-- Activity Feed -->
    <div class="st-card">
        <h3 class="st-card-title">
            <svg viewBox="0 0 24 24"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
            <?php esc_html_e( 'Recent Events', 'servertrack' ); ?>
        </h3>
        <ul class="st-activity-feed" id="st-activity-feed">
            <li class="st-loading-screen">
                <div class="st-spinner"></div>
                <div class="st-loading-text"><?php esc_html_e( 'Loading…', 'servertrack' ); ?></div>
            </li>
        </ul>
    </div>

</div><!-- /.st-dashboard-grid -->
