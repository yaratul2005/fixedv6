<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ServerTrack_Dashboard  v4.2
 *
 * v4.2 — Removed last remaining emoji ('Done ✓' in drain-retries JS callback).
 *         Added 'color' key to every KPI definition and applied
 *         st-kpi-icon-{color} CSS variant class to each KPI icon wrapper so
 *         the SVG badge background renders correctly (was falling back to the
 *         browser's Unicode glyph because the colour class was absent).
 *
 * v4.1 — Replaced every emoji with a clean inline SVG icon.
 *         All emoji strings (📡 ✅ 🎯 🔄 📊 ❌ 🛰 ✅ 🔵 🟡 🔴 📋 🔄 ⏭ 🚫 🕐)
 *         are now rendered as accessible <svg> elements using Lucide-style
 *         24×24 stroke icons. A shared st_svg() helper keeps the markup DRY.
 *
 * v3.2 — Chart.js no longer loaded on the Settings page.
 * v3.1 — Removed duplicate CSS enqueue (browser-cache poisoning fix).
 * v3.0 — Removed premature wp_localize_script call.
 * v2.9 — HTML class names realigned with admin.css selectors.
 * v2.8 — Settings/Sources submenu callbacks fixed.
 * v2.7 — KPI IDs, nonce, breakdown, auto-refresh, dead variable.
 * v2.6 — Class name mismatch after v2.3 brand overhaul.
 */
class ServerTrack_Dashboard {

    // ────────────────────────────────────────────────────────────────────────
    // SVG ICON HELPER
    // Returns a sanitised inline <svg> for a named icon.
    // All icons are 16×16 viewport, stroke-based, currentColor.
    // ────────────────────────────────────────────────────────────────────────

    private static function svg( string $name, string $extra_class = '' ): string {
        $cls = 'st-icon' . ( $extra_class ? ' ' . $extra_class : '' );

        $paths = [
            // KPI row
            'signal'      => '<path d="M1 6s1-1 4-1 5 2 8 2 4-1 4-1"/><path d="M1 10s1-1 4-1 5 2 8 2 4-1 4-1"/><path d="M1 14s1-1 4-1 5 2 8 2 4-1 4-1"/>',
            'check-circle'=> '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>',
            'target'      => '<circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/>',
            'refresh-cw'  => '<polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/>',
            'bar-chart-2' => '<line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/>',
            'x-circle'    => '<circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/>',
            // Panel headings
            'satellite'   => '<circle cx="12" cy="12" r="3"/><path d="M6.41 6.41a7 7 0 0 0 0 9.9 7 7 0 0 0 9.9 0"/><path d="M3.31 3.31a12 12 0 0 0 0 16.97 12 12 0 0 0 16.97 0"/>',
            'activity'    => '<polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>',
            'clipboard'   => '<path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><rect x="8" y="2" width="8" height="4" rx="1" ry="1"/>',
            // EMQ grade dots
            'check-sq'    => '<polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>',
            'circle-dot'  => '<circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="3"/>',
            // Status icons in log
            'check'       => '<polyline points="20 6 9 17 4 12"/>',
            'x'           => '<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>',
            'skip-forward'=> '<polygon points="5 4 15 12 5 20 5 4"/><line x1="19" y1="5" x2="19" y2="19"/>',
            'slash'       => '<circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/>',
            'clock'       => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
            'rotate-ccw'  => '<polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-3.82"/>',
            // Misc
            'alert-tri'   => '<path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>',
            'settings'    => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>',
        ];

        $inner = $paths[ $name ] ?? '<circle cx="12" cy="12" r="2"/>';

        return sprintf(
            '<svg class="%s" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">%s</svg>',
            esc_attr( $cls ),
            $inner
        );
    }

    public static function init(): void {
        add_action( 'admin_menu',            [ self::class, 'register_menu' ] );
        add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue_assets' ], 5 );

        add_action( 'wp_ajax_servertrack_log_data',        [ self::class, 'ajax_log_data' ] );
        add_action( 'wp_ajax_servertrack_platform_health', [ self::class, 'ajax_platform_health' ] );
        add_action( 'wp_ajax_servertrack_stats_breakdown', [ self::class, 'ajax_stats_breakdown' ] );
        add_action( 'wp_ajax_servertrack_clear_log',       [ self::class, 'ajax_clear_log' ] );
        add_action( 'wp_ajax_servertrack_drain_retries',   [ self::class, 'ajax_drain_retries' ] );
    }

    // ────────────────────────────────────────────────────────────────────────
    // MENU
    // ────────────────────────────────────────────────────────────────────────

    public static function register_menu(): void {
        $icon = 'data:image/svg+xml;base64,' . base64_encode(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>'
        );

        add_menu_page(
            __( 'ServerTrack', 'servertrack' ),
            __( 'ServerTrack', 'servertrack' ),
            'manage_options',
            'servertrack',
            [ self::class, 'render_page' ],
            $icon,
            56
        );

        add_submenu_page( 'servertrack', __( 'Dashboard', 'servertrack' ), __( 'Dashboard', 'servertrack' ), 'manage_options', 'servertrack',          [ self::class, 'render_page' ] );
        add_submenu_page( 'servertrack', __( 'Settings',  'servertrack' ), __( 'Settings',  'servertrack' ), 'manage_options', 'servertrack-settings', [ 'ServerTrack_Admin', 'render_page' ] );
        add_submenu_page( 'servertrack', __( 'Event Sources', 'servertrack' ), __( 'Event Sources', 'servertrack' ), 'manage_options', 'servertrack-sources', [ 'ServerTrack_Admin', 'render_page' ] );
    }

    public static function enqueue_assets( string $hook ): void {
        if ( 'toplevel_page_servertrack' !== $hook ) {
            return;
        }
        wp_enqueue_script(
            'chart-js',
            'https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js',
            [],
            '4.4.3',
            true
        );
    }

    // ────────────────────────────────────────────────────────────────────────
    // MAIN DASHBOARD PAGE
    // ────────────────────────────────────────────────────────────────────────

    public static function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) return;

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $current_page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
        if ( $current_page !== '' && $current_page !== 'servertrack' ) {
            return;
        }

        $logs        = get_option( 'servertrack_debug_log', [] );
        $recent_logs = array_slice( array_reverse( $logs ), 0, 200 );
        $stats       = self::compute_stats( $logs );
        $emq_data    = ServerTrack_MatchQuality::get_daily_averages( 7 );
        $platforms   = self::get_platform_statuses( $logs );
        $breakdown   = self::compute_breakdown( $logs );
        $retry_items = get_option( 'servertrack_retry_queue', [] );
        $nonce       = wp_create_nonce( 'servertrack_dashboard' );

        ?>
        <div class="wrap" id="servertrack-wrap">

        <?php ServerTrack_Admin::render_page_header(); ?>

        <?php
        /*
         * KPI definitions.
         * 'color' drives the st-kpi-icon-{color} CSS class on the icon wrapper,
         * which sets the background tint for the SVG badge.
         * Without this class the SVG had no container style and the browser
         * showed the bare Unicode fallback glyph (v4.1 regression, fixed v4.2).
         */
        $kpis = [
            [ 'id' => 'st-kpi-total',  'label' => 'Events Today',  'val' => $stats['today_count'],        'sub' => 'All platforms',   'icon' => 'signal',       'color' => 'teal'   ],
            [ 'id' => 'st-kpi-rate',   'label' => 'Success Rate',  'val' => $stats['success_rate'] . '%', 'sub' => 'Last 7 days',     'icon' => 'check-circle', 'color' => 'green'  ],
            [ 'id' => 'st-kpi-emq',    'label' => 'Avg EMQ Score', 'val' => $stats['avg_emq'],            'sub' => '0–10 scale',      'icon' => 'target',       'color' => 'purple' ],
            [ 'id' => 'st-kpi-retry',  'label' => 'Retry Queue',   'val' => $stats['retry_queue'],        'sub' => 'Pending retries', 'icon' => 'refresh-cw',   'color' => 'orange' ],
            [ 'id' => 'st-kpi-week',   'label' => 'Total (7d)',    'val' => $stats['week_total'],         'sub' => 'Events sent',     'icon' => 'bar-chart-2',  'color' => 'blue'   ],
            [ 'id' => 'st-kpi-errors', 'label' => 'Errors (7d)',   'val' => $stats['week_errors'],        'sub' => 'Failed sends',    'icon' => 'x-circle',     'color' => 'red'    ],
        ];
        ?>
        <div class="st-kpi-grid" id="st-kpis">
            <?php foreach ( $kpis as $k ) : ?>
            <div class="st-kpi">
                <div class="st-kpi-icon st-kpi-icon-<?php echo esc_attr( $k['color'] ); ?>" aria-hidden="true"><?php echo self::svg( $k['icon'], 'st-kpi-svg' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
                <div class="st-kpi-label"><?php echo esc_html( $k['label'] ); ?></div>
                <div class="st-kpi-val" id="<?php echo esc_attr( $k['id'] ); ?>"><?php echo esc_html( $k['val'] ); ?></div>
                <div class="st-kpi-sub"><?php echo esc_html( $k['sub'] ); ?></div>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="st-refresh-badge">
            <span id="st-live-count" class="st-live-label">Live</span>
            <span class="st-pulse" title="Auto-refreshing every 30s"></span>
            <button class="st-refresh-btn" id="st-manual-refresh" title="Refresh now">
                <?php echo self::svg( 'refresh-cw' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                Refresh
                <span class="st-spinner"></span>
            </button>
        </div>

        <div class="st-row">
            <div class="st-panel">
                <div class="st-panel-header">
                    <span class="st-panel-title">
                        <?php echo self::svg( 'satellite' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                        Platform Health
                    </span>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=servertrack-settings' ) ); ?>" class="st-panel-action">Configure
                        <svg class="st-icon" xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
                    </a>
                </div>
                <div class="st-plat-list">
                    <?php foreach ( $platforms as $p ) :
                        $enabled     = $p['enabled'];
                        $badge       = $enabled ? 'on' : 'off';
                        $badge_label = $enabled ? esc_html( $p['status'] ) : esc_html__( 'Disabled', 'servertrack' );
                        $warn        = $enabled && strpos( $p['status'], 'Missing' ) !== false;
                        if ( $warn ) { $badge = 'warn'; }
                    ?>
                    <div class="st-plat-row">
                        <span class="st-plat-name"><?php echo esc_html( $p['name'] ); ?></span>
                        <?php if ( $enabled ) : ?>
                            <span class="st-plat-stat"><?php echo esc_html( $p['today'] ?? 0 ); ?> today</span>
                        <?php endif; ?>
                        <span class="st-badge <?php echo esc_attr( $badge ); ?>"><?php echo $badge_label; ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="st-panel">
                <div class="st-panel-header">
                    <span class="st-panel-title">
                        <?php echo self::svg( 'target' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                        EMQ Scorecard (7 days)
                    </span>
                </div>
                <div class="st-emq-grades">
                    <?php
                    $grade_defs = [
                        'excellent' => [ 'label' => 'Excellent (8–10)', 'icon' => 'check-circle', 'color' => '#22c55e' ],
                        'good'      => [ 'label' => 'Good (6–7.9)',     'icon' => 'circle-dot',   'color' => '#3b82f6' ],
                        'fair'      => [ 'label' => 'Fair (4–5.9)',     'icon' => 'circle-dot',   'color' => '#eab308' ],
                        'poor'      => [ 'label' => 'Poor (0–3.9)',     'icon' => 'x-circle',     'color' => '#ef4444' ],
                    ];
                    foreach ( $grade_defs as $grade => $def ) :
                        $count = $breakdown['emq_grades'][ $grade ] ?? 0;
                    ?>
                    <div class="st-grade-pill <?php echo esc_attr( $grade ); ?>">
                        <svg class="st-icon" xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="<?php echo esc_attr( $def['color'] ); ?>" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><?php
                            if ( $def['icon'] === 'check-circle' ) {
                                echo '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>';
                            } elseif ( $def['icon'] === 'x-circle' ) {
                                echo '<circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/>';
                            } else {
                                echo '<circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="3"/>';
                            }
                        // phpcs:ignore ?></svg>
                        <span><?php echo esc_html( $def['label'] ); ?></span>
                        <span class="st-grade-count"><?php echo esc_html( $count ); ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <canvas id="st-emq-chart" height="110"></canvas>
            </div>
        </div>

        <div class="st-row" style="margin-top:16px;">
            <div class="st-panel">
                <div class="st-panel-header">
                    <span class="st-panel-title">
                        <?php echo self::svg( 'satellite' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                        Events by Platform (7d)
                    </span>
                </div>
                <div style="max-width:260px;margin:0 auto;">
                    <canvas id="st-plat-chart" height="180"></canvas>
                </div>
                <div style="display:flex;justify-content:center;gap:16px;margin-top:12px;flex-wrap:wrap;">
                    <?php foreach ( $breakdown['by_platform'] as $plat => $cnt ) : ?>
                    <span style="font-size:12px;color:var(--st-muted);">
                        <strong style="color:var(--st-text);"><?php echo esc_html( $cnt ); ?></strong>
                        <?php echo esc_html( ucfirst( $plat ) ); ?>
                    </span>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="st-panel">
                <div class="st-panel-header">
                    <span class="st-panel-title">
                        <?php echo self::svg( 'bar-chart-2' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                        Top Event Types (7d)
                    </span>
                </div>
                <canvas id="st-events-chart" height="180"></canvas>
            </div>
        </div>

        <?php if ( ! empty( $retry_items ) ) : ?>
        <div style="margin-top:16px;">
        <div class="st-panel">
            <div class="st-panel-header">
                <span class="st-panel-title">
                    <?php echo self::svg( 'rotate-ccw' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    Retry Queue
                    <span style="font-weight:400;color:var(--st-faint);font-size:12px;">(<?php echo count( $retry_items ); ?> pending)</span>
                </span>
                <button class="st-panel-action" id="st-drain-btn">Drain all now</button>
            </div>
            <div class="st-retry-list" id="st-retry-list">
                <?php foreach ( array_slice( $retry_items, 0, 10 ) as $i => $item ) :
                    $plat  = esc_html( $item['platform']   ?? 'unknown' );
                    $event = esc_html( $item['event_name'] ?? '—' );
                    $tries = (int) ( $item['attempts'] ?? 0 );
                    $ts    = esc_html( $item['last_attempt'] ?? '' );
                ?>
                <div class="st-retry-item">
                    <span class="st-retry-plat"><?php echo $plat; ?></span>
                    <span><?php echo $event; ?></span>
                    <span style="margin-left:auto;color:var(--st-faint);"><?php echo $tries; ?> attempt<?php echo $tries !== 1 ? 's' : ''; ?></span>
                    <span style="color:var(--st-faint);font-size:11px;"><?php echo $ts; ?></span>
                </div>
                <?php endforeach; ?>
                <?php if ( count( $retry_items ) > 10 ) : ?>
                <div style="text-align:center;padding:8px;font-size:12px;color:var(--st-faint);">+ <?php echo count( $retry_items ) - 10; ?> more</div>
                <?php endif; ?>
            </div>
        </div>
        </div>
        <?php endif; ?>

        <div style="margin-top:16px;">
        <div class="st-panel">
            <div class="st-panel-header">
                <span class="st-panel-title">
                    <?php echo self::svg( 'clipboard' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    Live Event Log
                    <span style="font-weight:400;color:var(--st-faint);font-size:12px;margin-left:6px;">last 200 · auto-refreshes every 30s</span>
                    <span class="st-spinner" id="st-log-spinner"></span>
                </span>
                <button class="st-panel-action" id="st-clear-log-btn" style="color:var(--st-error);">Clear log</button>
            </div>

            <div class="st-filter-bar">
                <select id="st-fp" onchange="stFilter()">
                    <option value=""><?php esc_html_e( 'All Platforms', 'servertrack' ); ?></option>
                    <option value="meta">Meta</option>
                    <option value="tiktok">TikTok</option>
                    <option value="google">Google</option>
                </select>
                <select id="st-fs" onchange="stFilter()">
                    <option value=""><?php esc_html_e( 'All Statuses', 'servertrack' ); ?></option>
                    <option value="success">Success</option>
                    <option value="error">Error</option>
                    <option value="skipped">Skipped</option>
                    <option value="dedup_blocked">Dedup Blocked</option>
                    <option value="queued">Queued</option>
                    <option value="retrying">Retrying</option>
                </select>
                <input type="text" id="st-fe" placeholder="Search event…" oninput="stFilter()" style="min-width:160px;">
                <input type="text" id="st-fo" placeholder="Order #…" oninput="stFilter()" style="width:100px;">
            </div>

            <div class="st-log-wrap">
                <table class="st-log-table">
                    <thead>
                        <tr>
                            <th>Time</th>
                            <th>Status</th>
                            <th>Platform</th>
                            <th>Event</th>
                            <th>Order</th>
                            <th>EMQ</th>
                            <th>Message</th>
                        </tr>
                    </thead>
                    <tbody id="st-log-tbody">
                        <?php self::render_log_rows( $recent_logs ); ?>
                    </tbody>
                </table>
            </div>
        </div>
        </div>

        </div><!-- #servertrack-wrap -->

        <script>
        (function(){
            var nonce   = <?php echo wp_json_encode( $nonce ); ?>;
            var ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;

            // ── EMQ 7-day line chart ──────────────────────────────────────────
            (function(){
                var labels = <?php echo wp_json_encode( array_keys( $emq_data ) ); ?>;
                var scores = <?php echo wp_json_encode( array_values( array_map( fn($d) => $d['avg'] ?? 0, $emq_data ) ) ); ?>;
                var ctx = document.getElementById('st-emq-chart');
                if(!ctx) return;
                if(!labels.length){
                    ctx.parentElement.insertAdjacentHTML('beforeend','<p style="color:var(--st-faint);font-size:12px;text-align:center;margin-top:8px;">No EMQ data yet.</p>');
                    ctx.style.display='none'; return;
                }
                new Chart(ctx,{
                    type:'line',
                    data:{labels:labels,datasets:[{
                        label:'Avg EMQ',data:scores,
                        borderColor:'#6366f1',backgroundColor:'rgba(99,102,241,.08)',
                        tension:.4,fill:true,pointRadius:4,pointBackgroundColor:'#6366f1',
                        pointBorderColor:'#fff',pointBorderWidth:2
                    }]},
                    options:{responsive:true,plugins:{legend:{display:false},tooltip:{callbacks:{label:function(c){return ' EMQ: '+c.parsed.y.toFixed(1);}}}},
                        scales:{y:{min:0,max:10,ticks:{stepSize:2,font:{size:10}},grid:{color:'#f3f4f6'}},x:{grid:{display:false},ticks:{font:{size:10}}}}}
                });
            })();

            // ── Platform breakdown doughnut ───────────────────────────────────
            (function(){
                var pd = <?php echo wp_json_encode( $breakdown['by_platform'] ); ?>;
                var labels = Object.keys(pd).map(function(k){ return k.charAt(0).toUpperCase()+k.slice(1); });
                var data   = Object.values(pd);
                var ctx    = document.getElementById('st-plat-chart');
                if(!ctx) return;
                if(!data.length||data.every(function(v){return v===0;})){
                    ctx.parentElement.insertAdjacentHTML('beforeend','<p style="color:var(--st-faint);font-size:12px;text-align:center;margin-top:8px;">No data yet.</p>');
                    ctx.style.display='none'; return;
                }
                new Chart(ctx,{
                    type:'doughnut',
                    data:{labels:labels,datasets:[{
                        data:data,
                        backgroundColor:['#6366f1','#0ea5e9','#22c55e'],
                        borderWidth:2,borderColor:'#fff',hoverOffset:6
                    }]},
                    options:{responsive:true,cutout:'65%',
                        plugins:{legend:{position:'bottom',labels:{font:{size:11},padding:12,boxWidth:10}},
                        tooltip:{callbacks:{label:function(c){return ' '+c.label+': '+c.parsed+' events';}}}}}
                });
            })();

            // ── Top event types bar chart ─────────────────────────────────────
            (function(){
                var te = <?php echo wp_json_encode( $breakdown['top_events'] ); ?>;
                var labels = Object.keys(te);
                var data   = Object.values(te);
                var ctx    = document.getElementById('st-events-chart');
                if(!ctx) return;
                if(!labels.length){
                    ctx.parentElement.insertAdjacentHTML('beforeend','<p style="color:var(--st-faint);font-size:12px;text-align:center;margin-top:8px;">No data yet.</p>');
                    ctx.style.display='none'; return;
                }
                new Chart(ctx,{
                    type:'bar',
                    data:{labels:labels,datasets:[{
                        label:'Events',data:data,
                        backgroundColor:'rgba(99,102,241,.75)',
                        borderRadius:5,borderSkipped:false
                    }]},
                    options:{indexAxis:'y',responsive:true,
                        plugins:{legend:{display:false},tooltip:{callbacks:{label:function(c){return ' '+c.parsed.x+' events';}}}},
                        scales:{x:{grid:{color:'#f3f4f6'},ticks:{font:{size:10}}},y:{grid:{display:false},ticks:{font:{size:11}}}}}
                });
            })();

            // ── Log filter ────────────────────────────────────────────────────
            window.stFilter = function(){
                var fp = document.getElementById('st-fp').value.toLowerCase();
                var fs = document.getElementById('st-fs').value.toLowerCase();
                var fe = document.getElementById('st-fe').value.toLowerCase();
                var fo = document.getElementById('st-fo').value.toLowerCase();
                var rows = document.querySelectorAll('#st-log-tbody tr[data-row]');
                rows.forEach(function(row){
                    var rp = (row.dataset.platform||'').toLowerCase();
                    var rs = (row.dataset.status||'').toLowerCase();
                    var re = (row.dataset.event||'').toLowerCase();
                    var ro = (row.dataset.order||'').toLowerCase();
                    var ok = true;
                    if(fp && rp!==fp) ok=false;
                    if(fs && rs!==fs) ok=false;
                    if(fe && re.indexOf(fe)<0) ok=false;
                    if(fo && ro.indexOf(fo)<0) ok=false;
                    row.style.display = ok ? '' : 'none';
                });
            };

            // ── Auto-refresh ──────────────────────────────────────────────────
            var refreshTimer = null;
            function doRefresh(){
                var btn = document.getElementById('st-manual-refresh');
                if(btn) btn.classList.add('st-spinning');
                var spinner = document.getElementById('st-log-spinner');
                if(spinner) spinner.style.display='inline-block';
                fetch(ajaxUrl+'?action=servertrack_log_data&nonce='+encodeURIComponent(nonce))
                    .then(function(r){return r.json();})
                    .then(function(res){
                        if(res.success && res.data){
                            var tbody = document.getElementById('st-log-tbody');
                            if(tbody) tbody.innerHTML = res.data.rows || '';
                            stFilter();
                            var lc = document.getElementById('st-live-count');
                            if(lc) lc.textContent = (res.data.total||0)+' events';
                        }
                    })
                    .catch(function(){})
                    .finally(function(){
                        if(btn) btn.classList.remove('st-spinning');
                        if(spinner) spinner.style.display='none';
                    });
            }
            var manualBtn = document.getElementById('st-manual-refresh');
            if(manualBtn) manualBtn.addEventListener('click', doRefresh);
            refreshTimer = setInterval(doRefresh, 30000);
            window.addEventListener('beforeunload', function(){ if(refreshTimer) clearInterval(refreshTimer); });

            // ── Clear log ─────────────────────────────────────────────────────
            var clearBtn = document.getElementById('st-clear-log-btn');
            if(clearBtn){
                clearBtn.addEventListener('click', function(){
                    if(!confirm('Clear all log entries?')) return;
                    fetch(ajaxUrl,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=servertrack_clear_log&nonce='+encodeURIComponent(nonce)})
                    .then(function(r){return r.json();})
                    .then(function(res){
                        if(res.success){
                            var tbody = document.getElementById('st-log-tbody');
                            if(tbody) tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:24px;color:var(--st-faint);">Log cleared.</td></tr>';
                        }
                    }).catch(function(){});
                });
            }

            // ── Drain retries ─────────────────────────────────────────────────
            var drainBtn = document.getElementById('st-drain-btn');
            if(drainBtn){
                drainBtn.addEventListener('click', function(){
                    drainBtn.disabled = true;
                    drainBtn.textContent = 'Draining…';
                    fetch(ajaxUrl,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=servertrack_drain_retries&nonce='+encodeURIComponent(nonce)})
                    .then(function(r){return r.json();})
                    .then(function(res){ drainBtn.textContent = res.success ? 'Done' : 'Error'; })
                    .catch(function(){ drainBtn.textContent = 'Error'; });
                });
            }
        })();
        </script>
        <?php
    }

    // ────────────────────────────────────────────────────────────────────────
    // LOG ROWS RENDERER  — SVG status icons, no emoji
    // ────────────────────────────────────────────────────────────────────────

    public static function render_log_rows( array $logs ): void {
        if ( empty( $logs ) ) {
            echo '<tr><td colspan="7" style="text-align:center;padding:24px;color:var(--st-faint);">No events logged yet.</td></tr>';
            return;
        }

        $status_icons = [
            'success'       => '<svg class="st-icon st-icon-status" xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#22c55e" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>',
            'error'         => '<svg class="st-icon st-icon-status" xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>',
            'skipped'       => '<svg class="st-icon st-icon-status" xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polygon points="5 4 15 12 5 20 5 4"/><line x1="19" y1="5" x2="19" y2="19"/></svg>',
            'dedup_blocked' => '<svg class="st-icon st-icon-status" xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#f59e0b" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/></svg>',
            'queued'        => '<svg class="st-icon st-icon-status" xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#6366f1" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>',
            'retrying'      => '<svg class="st-icon st-icon-status" xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#0ea5e9" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-3.82"/></svg>',
        ];

        $status_cls = [
            'success'       => 'success',
            'error'         => 'error',
            'skipped'       => 'skipped',
            'dedup_blocked' => 'dedup',
            'queued'        => 'queued',
            'retrying'      => 'retrying',
        ];

        foreach ( $logs as $entry ) {
            $status   = $entry['status']     ?? '';
            $platform = $entry['platform']   ?? '';
            $event    = $entry['event_name'] ?? '';
            $order    = $entry['order_id']   ?? '';
            $msg      = $entry['message']    ?? '';
            $ts       = $entry['timestamp']  ?? '';
            $emq      = isset( $entry['emq_score'] ) ? number_format( (float) $entry['emq_score'], 1 ) : '—';

            $icon = $status_icons[ $status ] ?? '<span style="width:13px;display:inline-block;">•</span>';
            $cls  = $status_cls[ $status ]   ?? '';

            printf(
                '<tr data-row="1" data-platform="%s" data-status="%s" data-event="%s" data-order="%s">' .
                '<td style="white-space:nowrap;font-size:11px;">%s</td>' .
                '<td><span class="st-dot %s">%s %s</span></td>' .
                '<td>%s</td><td>%s</td><td>%s</td><td>%s</td>' .
                '<td style="max-width:260px;word-break:break-word;">%s</td></tr>',
                esc_attr( $platform ), esc_attr( $status ), esc_attr( $event ), esc_attr( (string) $order ),
                esc_html( $ts ),
                esc_attr( $cls ),
                $icon, // phpcs:ignore — sanitised SVG
                esc_html( ucfirst( $status ) ),
                esc_html( ucfirst( $platform ) ),
                esc_html( $event ),
                esc_html( (string) $order ),
                esc_html( $emq ),
                esc_html( $msg )
            );
        }
    }

    // ────────────────────────────────────────────────────────────────────────
    // STATS HELPERS
    // ────────────────────────────────────────────────────────────────────────

    private static function compute_stats( array $logs ): array {
        $today    = gmdate( 'Y-m-d' );
        $week_ago = gmdate( 'Y-m-d', strtotime( '-7 days' ) );

        $today_count = $week_total = $week_success = $week_errors = $emq_count = 0;
        $emq_sum = 0.0;

        foreach ( $logs as $entry ) {
            $ts     = substr( $entry['timestamp'] ?? '', 0, 10 );
            $status = $entry['status'] ?? '';
            if ( $ts === $today ) $today_count++;
            if ( $ts >= $week_ago ) {
                $week_total++;
                if ( 'success' === $status ) $week_success++;
                if ( 'error'   === $status ) $week_errors++;
                if ( isset( $entry['emq_score'] ) ) { $emq_sum += (float) $entry['emq_score']; $emq_count++; }
            }
        }
        return [
            'today_count'  => $today_count,
            'week_total'   => $week_total,
            'week_errors'  => $week_errors,
            'success_rate' => $week_total > 0 ? (int) round( $week_success / $week_total * 100 ) : 0,
            'avg_emq'      => $emq_count > 0  ? number_format( $emq_sum / $emq_count, 1 ) : '—',
            'retry_queue'  => count( get_option( 'servertrack_retry_queue', [] ) ),
        ];
    }

    private static function compute_breakdown( array $logs ): array {
        $week_ago    = gmdate( 'Y-m-d', strtotime( '-7 days' ) );
        $by_platform = [ 'meta' => 0, 'google' => 0, 'tiktok' => 0 ];
        $by_event    = [];
        $emq_grades  = [ 'excellent' => 0, 'good' => 0, 'fair' => 0, 'poor' => 0 ];

        foreach ( $logs as $entry ) {
            $ts = substr( $entry['timestamp'] ?? '', 0, 10 );
            if ( $ts < $week_ago ) continue;
            $plat = strtolower( $entry['platform'] ?? '' );
            if ( isset( $by_platform[ $plat ] ) ) $by_platform[ $plat ]++;
            $ev = $entry['event_name'] ?? '';
            if ( $ev ) $by_event[ $ev ] = ( $by_event[ $ev ] ?? 0 ) + 1;
            if ( isset( $entry['emq_score'] ) ) {
                $s = (float) $entry['emq_score'];
                if ( $s >= 8 ) $emq_grades['excellent']++;
                elseif ( $s >= 6 ) $emq_grades['good']++;
                elseif ( $s >= 4 ) $emq_grades['fair']++;
                else $emq_grades['poor']++;
            }
        }
        arsort( $by_event );
        return [ 'by_platform' => $by_platform, 'top_events' => array_slice( $by_event, 0, 8, true ), 'emq_grades' => $emq_grades ];
    }

    private static function get_platform_statuses( array $logs ): array {
        $today  = gmdate( 'Y-m-d' );
        $counts = [ 'meta' => 0, 'google' => 0, 'tiktok' => 0 ];
        foreach ( $logs as $entry ) {
            $plat = strtolower( $entry['platform'] ?? '' );
            $ts   = substr( $entry['timestamp'] ?? '', 0, 10 );
            if ( $ts === $today && isset( $counts[ $plat ] ) ) $counts[ $plat ]++;
        }
        $defs = [
            'meta'   => [ 'name' => 'Meta CAPI',     'enabled' => (bool) get_option( 'servertrack_meta_enabled', 0 ),   'ok' => (bool) ( get_option( 'servertrack_meta_pixel_id', '' ) && get_option( 'servertrack_meta_access_token', '' ) ) ],
            'google' => [ 'name' => 'Google Ads',    'enabled' => (bool) get_option( 'servertrack_google_enabled', 0 ), 'ok' => (bool) get_option( 'servertrack_google_refresh_token', '' ) ],
            'tiktok' => [ 'name' => 'TikTok Events', 'enabled' => (bool) get_option( 'servertrack_tiktok_enabled', 0 ), 'ok' => (bool) ( get_option( 'servertrack_tiktok_pixel_id', '' ) && get_option( 'servertrack_tiktok_access_token', '' ) ) ],
        ];
        $out = [];
        foreach ( $defs as $key => $d ) {
            $out[] = [ 'name' => $d['name'], 'enabled' => $d['enabled'], 'status' => $d['enabled'] ? ( $d['ok'] ? 'Active' : 'Missing credentials' ) : 'Disabled', 'today' => $counts[ $key ] ];
        }
        return $out;
    }

    // ────────────────────────────────────────────────────────────────────────
    // AJAX HANDLERS
    // ────────────────────────────────────────────────────────────────────────

    public static function ajax_log_data(): void {
        check_ajax_referer( 'servertrack_dashboard', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );
        $logs = get_option( 'servertrack_debug_log', [] );
        $recent = array_slice( array_reverse( $logs ), 0, 200 );
        ob_start(); self::render_log_rows( $recent ); $html = ob_get_clean();
        wp_send_json_success( [ 'rows' => $html, 'total' => count( $logs ) ] );
    }

    public static function ajax_platform_health(): void {
        check_ajax_referer( 'servertrack_dashboard', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );
        wp_send_json_success( self::get_platform_statuses( get_option( 'servertrack_debug_log', [] ) ) );
    }

    public static function ajax_stats_breakdown(): void {
        check_ajax_referer( 'servertrack_dashboard', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );
        wp_send_json_success( self::compute_breakdown( get_option( 'servertrack_debug_log', [] ) ) );
    }

    public static function ajax_clear_log(): void {
        check_ajax_referer( 'servertrack_dashboard', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );
        ServerTrack_Logger::clear_logs();
        wp_send_json_success( [ 'message' => 'Log cleared.' ] );
    }

    public static function ajax_drain_retries(): void {
        check_ajax_referer( 'servertrack_dashboard', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );
        $queue = get_option( 'servertrack_retry_queue', [] );
        if ( empty( $queue ) ) { wp_send_json_success( [ 'drained' => 0 ] ); return; }
        $drained = 0;
        foreach ( $queue as $item ) {
            if ( class_exists( 'ServerTrack_Retry' ) ) { ServerTrack_Retry::process_item( $item ); $drained++; }
        }
        delete_option( 'servertrack_retry_queue' );
        wp_send_json_success( [ 'drained' => $drained ] );
    }
}
