<?php
/**
 * Meta CAPI settings tab — view fragment only.
 * C1/C9: <form> and settings_fields() removed. render_page() owns the form.
 */
if ( ! defined( 'ABSPATH' ) ) exit;
?>
<table class="form-table" role="presentation">
    <tr>
        <th scope="row"><?php esc_html_e( 'Enable Meta CAPI', 'servertrack' ); ?></th>
        <td>
            <label>
                <input type="checkbox" name="servertrack_meta_enabled" value="1"
                    <?php checked( 1, get_option( 'servertrack_meta_enabled', 0 ) ); ?> />
                <?php esc_html_e( 'Send events to Meta Conversions API', 'servertrack' ); ?>
            </label>
        </td>
    </tr>
    <tr>
        <th scope="row"><label for="servertrack_meta_pixel_id"><?php esc_html_e( 'Meta Pixel ID', 'servertrack' ); ?></label></th>
        <td>
            <input type="text"
                   id="servertrack_meta_pixel_id"
                   name="servertrack_meta_pixel_id"
                   value="<?php echo esc_attr( get_option( 'servertrack_meta_pixel_id', '' ) ); ?>"
                   class="regular-text"
                   placeholder="e.g. 123456789012345"
                   autocomplete="off" />
        </td>
    </tr>
    <tr>
        <th scope="row"><label for="servertrack_meta_access_token"><?php esc_html_e( 'System User Access Token', 'servertrack' ); ?></label></th>
        <td>
            <input type="password"
                   id="servertrack_meta_access_token"
                   name="servertrack_meta_access_token"
                   value="<?php echo esc_attr( get_option( 'servertrack_meta_access_token', '' ) ); ?>"
                   class="regular-text"
                   autocomplete="new-password" />
            <p class="description"><?php esc_html_e( 'Generate from Meta Events Manager → Settings → System User Token.', 'servertrack' ); ?></p>
        </td>
    </tr>
    <tr>
        <th scope="row"><label for="servertrack_meta_test_event_code"><?php esc_html_e( 'Test Event Code', 'servertrack' ); ?></label></th>
        <td>
            <input type="text"
                   id="servertrack_meta_test_event_code"
                   name="servertrack_meta_test_event_code"
                   value="<?php echo esc_attr( get_option( 'servertrack_meta_test_event_code', '' ) ); ?>"
                   class="regular-text"
                   placeholder="TEST12345 (optional)"
                   autocomplete="off" />
            <p class="description"><?php esc_html_e( 'Only required when using Meta Test Events tool. Leave blank in production.', 'servertrack' ); ?></p>
        </td>
    </tr>
</table>
<hr />
<h2><?php esc_html_e( 'Send Test Event', 'servertrack' ); ?></h2>
<p><?php esc_html_e( 'Sends a dummy Purchase event to Meta CAPI to verify your credentials.', 'servertrack' ); ?></p>
<button type="button" class="button button-secondary servertrack-test-btn" data-platform="meta">
    <?php esc_html_e( 'Send Test Event → Meta', 'servertrack' ); ?>
</button>
<div class="servertrack-test-response" id="servertrack-test-response-meta"></div>
