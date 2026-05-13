/**
 * ServerTrack Admin JS — v3.1
 *
 * Handles (Settings page only):
 *  - Platform test-event buttons
 *  - Debug log: filter, clear, refresh, response toggle
 *  - Toast notification system
 *  - Confirm dialogs for destructive actions
 *
 * Dashboard AJAX (drain retries, manual refresh, KPI auto-refresh,
 * dashboard clear-log) is handled by the inline <script> block rendered
 * by ServerTrack_Dashboard::render_page() — it uses the dashboard nonce
 * directly from PHP via wp_json_encode().
 *
 * Depends on: servertrack_admin (wp_localize_script)
 * {
 *   ajax_url,
 *   nonce,           — wp_create_nonce('servertrack_admin_nonce')
 *                       Used by: test_event, get_logs, get_dashboard_stats,
 *                                clear_log (Settings debug tab)
 *   dashboard_nonce, — wp_create_nonce('servertrack_dashboard')
 *                       Used by: Dashboard inline JS (not this file)
 *   platforms: { meta, google, tiktok } (enabled, configured)
 * }
 */
(function ($) {
  'use strict';

  if (typeof servertrack_admin === 'undefined') return;

  var cfg = servertrack_admin;

  /* ─────────────────────────────────────────────────
     TOAST SYSTEM
  ───────────────────────────────────────────────── */
  var $toastContainer;

  function ensureToastContainer() {
    if (!$toastContainer || !$toastContainer.length) {
      $toastContainer = $('<div id="st-toast-container"></div>').appendTo('body');
    }
    return $toastContainer;
  }

  var TOAST_ICONS = {
    success: '<svg class="st-toast-icon" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>',
    error:   '<svg class="st-toast-icon" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>',
    info:    '<svg class="st-toast-icon" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>'
  };

  function showToast(type, title, msg, duration) {
    var container = ensureToastContainer();
    var icon = TOAST_ICONS[type] || TOAST_ICONS.info;
    var msgHtml = msg ? '<div class="st-toast-msg">' + msg + '</div>' : '';
    var $toast = $(
      '<div class="st-toast st-toast-' + type + '">' +
        icon +
        '<div class="st-toast-body">' +
          '<div class="st-toast-title">' + title + '</div>' +
          msgHtml +
        '</div>' +
      '</div>'
    ).appendTo(container);

    setTimeout(function () {
      $toast.addClass('is-leaving');
      setTimeout(function () { $toast.remove(); }, 220);
    }, duration || 3500);
  }

  /* ─────────────────────────────────────────────────
     PLATFORM TEST BUTTONS
  ───────────────────────────────────────────────── */
  $(document).on('click', '.st-test-btn[data-platform]', function () {
    var $btn      = $(this);
    var platform  = $btn.data('platform');
    var $result   = $btn.closest('.st-platform-card').find('.st-test-result');

    if ($btn.prop('disabled')) return;

    $btn.prop('disabled', true).addClass('is-sending');
    $result.removeClass('is-visible is-success is-error').text('');

    $.post(
      cfg.ajax_url,
      {
        action:   'servertrack_test_event',
        nonce:    cfg.nonce,
        platform: platform
      },
      function (res) {
        $btn.prop('disabled', false).removeClass('is-sending');

        if (res.success) {
          $result.addClass('is-visible is-success').text('✓ ' + (res.data.message || 'Test event sent'));
          showToast('success', 'Test sent', platform + ' test event delivered.');
        } else {
          var errMsg = (res.data && res.data.message) ? res.data.message : 'Request failed';
          $result.addClass('is-visible is-error').text('✗ ' + errMsg);
          showToast('error', 'Test failed', errMsg);
        }
      }
    ).fail(function () {
      $btn.prop('disabled', false).removeClass('is-sending');
      $result.addClass('is-visible is-error').text('✗ Network error');
      showToast('error', 'Network error', 'Could not reach the server.');
    });
  });

  /* ─────────────────────────────────────────────────
     CLEAR DEBUG LOG  (Settings → Debug tab only)
     Button ID: #st-clear-log
     Nonce action: servertrack_admin_nonce  → cfg.nonce
     Handler: ServerTrack_Admin::ajax_clear_log()
     NOTE: Dashboard clear-log (#st-clear-log-btn) is handled by
           the dashboard inline <script> using the dashboard nonce.
  ───────────────────────────────────────────────── */
  $(document).on('click', '#st-clear-log', function () {
    if (!window.confirm('Clear all log entries? This cannot be undone.')) return;

    var $btn = $(this).prop('disabled', true);

    $.post(
      cfg.ajax_url,
      { action: 'servertrack_clear_log', nonce: cfg.nonce },
      function (res) {
        $btn.prop('disabled', false);
        if (res.success) {
          $('#st-log-tbody').html(
            '<tr><td colspan="7" class="st-empty">Log cleared.</td></tr>'
          );
          showToast('success', 'Log cleared', 'All debug entries removed.');
        } else {
          showToast('error', 'Error', 'Could not clear the log.');
        }
      }
    ).fail(function () {
      $btn.prop('disabled', false);
      showToast('error', 'Network error', 'Could not reach the server.');
    });
  });

  /* ─────────────────────────────────────────────────
     LOG FILTER BUTTONS
  ───────────────────────────────────────────────── */
  $(document).on('click', '.st-filter-btn', function () {
    var $btn    = $(this);
    var filter  = $btn.data('filter');
    var $group  = $btn.closest('.st-log-filters');

    $group.find('.st-filter-btn').removeClass('is-active');
    $btn.addClass('is-active');

    $('#st-log-tbody tr[data-row]').each(function () {
      var $row = $(this);
      if (!filter || filter === 'all') {
        $row.show();
      } else {
        $row.toggle($row.data('status') === filter);
      }
    });
  });

  /* ─────────────────────────────────────────────────
     LOG REFRESH BUTTON  (Settings → Debug tab)
     Fix v3.1: Changed $.get() → $.post() — WordPress AJAX
     requires POST. $.get() was returning '0' or '-1'.
     Fix v3.1: ajax_get_logs() now returns { html: '<tr>…</tr>' }
     instead of a raw array, so res.data.html correctly injects rows.
  ───────────────────────────────────────────────── */
  $(document).on('click', '#st-refresh-log', function () {
    var $btn = $(this).addClass('st-spinning').prop('disabled', true);

    $.post(
      cfg.ajax_url,
      { action: 'servertrack_get_logs', nonce: cfg.nonce },
      function (res) {
        $btn.removeClass('st-spinning').prop('disabled', false);
        if (res.success && res.data) {
          $('#st-log-tbody').html(res.data.html || '');
          showToast('info', 'Refreshed', 'Log updated.');
        }
      }
    ).fail(function () {
      $btn.removeClass('st-spinning').prop('disabled', false);
      showToast('error', 'Error', 'Refresh failed.');
    });
  });

  /* ─────────────────────────────────────────────────
     RESPONSE CELL EXPAND TOGGLE
  ───────────────────────────────────────────────── */
  $(document).on('click', '.st-response-toggle', function () {
    $(this).next('.st-response-full').toggleClass('is-open');
  });

  /* ─────────────────────────────────────────────────
     GENERIC CONFIRM FOR DESTRUCTIVE ACTIONS
  ───────────────────────────────────────────────── */
  $(document).on('click', '[data-confirm]', function (e) {
    if (!window.confirm($(this).data('confirm'))) {
      e.preventDefault();
      e.stopImmediatePropagation();
    }
  });

  /* ─────────────────────────────────────────────────
     AUTO-DISMISS WP NOTICES after 4 s
  ───────────────────────────────────────────────── */
  setTimeout(function () {
    $('#servertrack-wrap .notice.is-dismissible, #servertrack-wrap .notice-success').each(function () {
      var $n = $(this);
      $n.fadeOut(400, function () { $n.remove(); });
    });
  }, 4000);

}(jQuery));
