<?php
/**
 * ServerTrack — Debug Log Tab (redesigned)
 *
 * Renders the full log table with filter pills, inline response
 * expand, and test-event panel. All data is either server-rendered
 * on first load then kept fresh by admin.js auto-refresh (30 s).
 */
if ( ! defined( 'ABSPATH' ) ) exit;
$servertrack_logs = get_option( 'servertrack_debug_log', [] );
?>

<!-- Test Event Panel -->
<div class="st-card" style="margin-bottom:16px">
    <h3 class="st-card-title">
        <svg viewBox="0 0 24 24"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
        <?php esc_html_e( 'Send Test Event', 'servertrack' ); ?>
    </h3>
    <p style="color:var(--st-text-muted);font-size:.8125rem;margin:0 0 14px">
        <?php esc_html_e( 'Fire a live Purchase test event to confirm API connectivity for each platform.', 'servertrack' ); ?>
    </p>
    <div style="display:flex;gap:10px;flex-wrap:wrap">
        <button type="button" class="st-test-btn" data-platform="meta" style="flex:1;min-width:160px">
            <svg viewBox="0 0 24 24"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
            <?php esc_html_e( 'Send Test → Meta', 'servertrack' ); ?>
        </button>
        <button type="button" class="st-test-btn" data-platform="google" style="flex:1;min-width:160px">
            <svg viewBox="0 0 24 24"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
            <?php esc_html_e( 'Send Test → Google', 'servertrack' ); ?>
        </button>
        <button type="button" class="st-test-btn" data-platform="tiktok" style="flex:1;min-width:160px">
            <svg viewBox="0 0 24 24"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
            <?php esc_html_e( 'Send Test → TikTok', 'servertrack' ); ?>
        </button>
    </div>
    <div class="st-test-result" id="st-test-result-meta"   style="margin-top:10px"></div>
    <div class="st-test-result" id="st-test-result-google" style="margin-top:10px"></div>
    <div class="st-test-result" id="st-test-result-tiktok" style="margin-top:10px"></div>
</div>

<!-- Log Table -->
<div class="st-log-section" id="servertrack-debug-panel">

    <div class="st-log-header">
        <h2 class="st-log-title">
            <span class="st-live-dot"></span>
            <?php esc_html_e( 'Event Log', 'servertrack' ); ?>
            <span style="font-size:.75rem;font-weight:400;color:var(--st-text-muted);margin-left:4px">
                — <?php echo count( $servertrack_logs ); ?> <?php esc_html_e( 'entries', 'servertrack' ); ?>
            </span>
        </h2>
        <div class="st-log-actions">
            <button type="button" class="st-btn" id="servertrack-refresh-log">
                <svg viewBox="0 0 24 24"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
                <?php esc_html_e( 'Refresh', 'servertrack' ); ?>
            </button>
            <button type="button" class="st-btn st-btn-danger" id="servertrack-clear-log">
                <svg viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>
                <?php esc_html_e( 'Clear Log', 'servertrack' ); ?>
            </button>
        </div>
    </div>

    <div class="st-log-filters">
        <span class="st-filter-label"><?php esc_html_e( 'Filter:', 'servertrack' ); ?></span>
        <button class="st-filter-btn is-active" data-filter="all"><?php esc_html_e( 'All', 'servertrack' ); ?></button>
        <button class="st-filter-btn" data-filter="success"><?php esc_html_e( 'Success', 'servertrack' ); ?></button>
        <button class="st-filter-btn" data-filter="error"><?php esc_html_e( 'Error', 'servertrack' ); ?></button>
        <button class="st-filter-btn" data-filter="skipped"><?php esc_html_e( 'Skipped', 'servertrack' ); ?></button>
        <button class="st-filter-btn" data-filter="dedup_blocked"><?php esc_html_e( 'Dedup', 'servertrack' ); ?></button>
    </div>

    <div class="st-log-table-wrap">
        <table class="st-log-table" id="servertrack-log-table">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Time', 'servertrack' ); ?></th>
                    <th><?php esc_html_e( 'Platform', 'servertrack' ); ?></th>
                    <th><?php esc_html_e( 'Event', 'servertrack' ); ?></th>
                    <th><?php esc_html_e( 'Event ID', 'servertrack' ); ?></th>
                    <th><?php esc_html_e( 'Order ID', 'servertrack' ); ?></th>
                    <th><?php esc_html_e( 'Status', 'servertrack' ); ?></th>
                    <th><?php esc_html_e( 'HTTP', 'servertrack' ); ?></th>
                    <th><?php esc_html_e( 'Message', 'servertrack' ); ?></th>
                    <th><?php esc_html_e( 'Response', 'servertrack' ); ?></th>
                </tr>
            </thead>
            <tbody id="servertrack-log-body">
                <?php if ( empty( $servertrack_logs ) ) : ?>
                    <tr><td colspan="9" style="padding:0">
                        <div class="st-empty-state">
                            <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                            <h3><?php esc_html_e( 'No log entries yet', 'servertrack' ); ?></h3>
                            <p><?php esc_html_e( 'Events will appear here after tracking starts.', 'servertrack' ); ?></p>
                        </div>
                    </td></tr>
                <?php else : ?>
                    <?php foreach ( $servertrack_logs as $st_entry ) :
                        $st_platform = strtolower( $st_entry['platform'] ?? '' );
                        $st_status   = $st_entry['status'] ?? '';
                        $st_badge_cls = in_array( $st_status, [ 'success' ], true ) ? 'success'
                                      : ( 'error' === $st_status ? 'error' : 'warning' );
                        $st_response  = esc_html( $st_entry['response'] ?? '' );
                        $st_short     = mb_strlen( $st_response ) > 60 ? mb_substr( $st_response, 0, 60 ) . '…' : $st_response;
                    ?>
                    <tr>
                        <td style="white-space:nowrap;color:var(--st-text-muted);font-size:.75rem"><?php echo esc_html( $st_entry['timestamp'] ?? '' ); ?></td>
                        <td><span class="st-platform-chip st-platform-chip-<?php echo esc_attr( $st_platform ); ?>"><?php echo esc_html( strtoupper( $st_platform ) ); ?></span></td>
                        <td style="font-weight:600"><?php echo esc_html( $st_entry['event_name'] ?? '' ); ?></td>
                        <td style="font-family:monospace;font-size:.75rem;color:var(--st-text-muted)"><?php echo esc_html( $st_entry['event_id'] ?? '' ); ?></td>
                        <td><?php echo esc_html( empty( $st_entry['order_id'] ) ? '' : $st_entry['order_id'] ); ?></td>
                        <td><span class="st-log-badge st-log-badge-<?php echo esc_attr( $st_badge_cls ); ?>"><?php echo esc_html( $st_status ); ?></span></td>
                        <td style="font-variant-numeric:tabular-nums;font-size:.75rem"><?php echo esc_html( empty( $st_entry['http_code'] ) ? '' : $st_entry['http_code'] ); ?></td>
                        <td style="max-width:180px"><?php echo esc_html( $st_entry['message'] ?? '' ); ?></td>
                        <td class="st-response-cell">
                            <?php if ( mb_strlen( $st_response ) > 60 ) : ?>
                                <span class="st-response-short"><?php echo esc_html( $st_short ); ?></span>
                                <button class="st-response-toggle"><?php esc_html_e( 'expand', 'servertrack' ); ?></button>
                                <div class="st-response-full"><?php echo $st_response; // already esc_html'd above ?></div>
                            <?php else : ?>
                                <?php echo $st_response; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

</div><!-- /.st-log-section -->
