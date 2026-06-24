<?php
/**
 * Meta CAPI settings tab — view fragment only.
 * C1/C9: <form> and settings_fields() removed. render_page() owns the form.
 */
if ( ! defined( 'ABSPATH' ) ) exit;
?>
<div class="st-settings-section">
<table class="form-table" role="presentation">
    <tr>
        <th scope="row"><?php esc_html_e( 'Enable Meta CAPI', 'servertrack' ); ?></th>
        <td>
            <label class="st-toggle-label st-row" style="cursor:pointer; display:flex; align-items:center;">
    <div class="st-toggle" style="margin-right:12px;">
        <input type="checkbox" name="servertrack_meta_enabled" value="1"
                    <?php checked( 1, get_option( 'servertrack_meta_enabled', 0 ) ); ?> />
        <span class="st-toggle-slider"></span>
    </div>
    <span class="st-toggle-text" style="font-weight:500;"><?php esc_html_e( 'Send events to Meta Conversions API', 'servertrack' ); ?></span>
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
                   class="regular-text st-field-input"
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
                   class="regular-text st-field-input"
                   autocomplete="new-password" />
            <p class="description"><?php esc_html_e( 'Generate from Meta Events Manager → Settings → System User Token.', 'servertrack' ); ?></p>
        </td>
    </tr>
    <!-- Advanced Matching -->
    <tr>
        <th scope="row"><label><?php esc_html_e( 'Advanced Matching PII', 'servertrack' ); ?></label></th>
        <td>
            <fieldset>
                <legend class="screen-reader-text"><span><?php esc_html_e( 'Advanced Matching Signals', 'servertrack' ); ?></span></legend>
                <p class="description" style="margin-bottom:8px;"><?php esc_html_e( 'Select which customer signals to hash and send to Meta to improve match quality.', 'servertrack' ); ?></p>
                <label class="st-toggle-label st-row" style="cursor:pointer; display:flex; align-items:center;">
    <div class="st-toggle" style="margin-right:12px;">
        <input type="checkbox" name="servertrack_meta_am_email" value="1"
                        <?php checked( 1, get_option( 'servertrack_meta_am_email', 1 ) ); ?>>
        <span class="st-toggle-slider"></span>
    </div>
    <span class="st-toggle-text" style="font-weight:500;"><?php esc_html_e( 'Email', 'servertrack' ); ?></span>
</label><br>
                <label class="st-toggle-label st-row" style="cursor:pointer; display:flex; align-items:center;">
    <div class="st-toggle" style="margin-right:12px;">
        <input type="checkbox" name="servertrack_meta_am_phone" value="1"
                        <?php checked( 1, get_option( 'servertrack_meta_am_phone', 1 ) ); ?>>
        <span class="st-toggle-slider"></span>
    </div>
    <span class="st-toggle-text" style="font-weight:500;"><?php esc_html_e( 'Phone Number', 'servertrack' ); ?></span>
</label><br>
                <label class="st-toggle-label st-row" style="cursor:pointer; display:flex; align-items:center;">
    <div class="st-toggle" style="margin-right:12px;">
        <input type="checkbox" name="servertrack_meta_am_name" value="1"
                        <?php checked( 1, get_option( 'servertrack_meta_am_name', 1 ) ); ?>>
        <span class="st-toggle-slider"></span>
    </div>
    <span class="st-toggle-text" style="font-weight:500;"><?php esc_html_e( 'First & Last Name', 'servertrack' ); ?></span>
</label><br>
                <label class="st-toggle-label st-row" style="cursor:pointer; display:flex; align-items:center;">
    <div class="st-toggle" style="margin-right:12px;">
        <input type="checkbox" name="servertrack_meta_am_city" value="1"
                        <?php checked( 1, get_option( 'servertrack_meta_am_city', 1 ) ); ?>>
        <span class="st-toggle-slider"></span>
    </div>
    <span class="st-toggle-text" style="font-weight:500;"><?php esc_html_e( 'City', 'servertrack' ); ?></span>
</label><br>
                <label class="st-toggle-label st-row" style="cursor:pointer; display:flex; align-items:center;">
    <div class="st-toggle" style="margin-right:12px;">
        <input type="checkbox" name="servertrack_meta_am_state" value="1"
                        <?php checked( 1, get_option( 'servertrack_meta_am_state', 1 ) ); ?>>
        <span class="st-toggle-slider"></span>
    </div>
    <span class="st-toggle-text" style="font-weight:500;"><?php esc_html_e( 'State / Province', 'servertrack' ); ?></span>
</label><br>
                <label class="st-toggle-label st-row" style="cursor:pointer; display:flex; align-items:center;">
    <div class="st-toggle" style="margin-right:12px;">
        <input type="checkbox" name="servertrack_meta_am_zip" value="1"
                        <?php checked( 1, get_option( 'servertrack_meta_am_zip', 1 ) ); ?>>
        <span class="st-toggle-slider"></span>
    </div>
    <span class="st-toggle-text" style="font-weight:500;"><?php esc_html_e( 'ZIP / Postal Code', 'servertrack' ); ?></span>
</label><br>
                <label class="st-toggle-label st-row" style="cursor:pointer; display:flex; align-items:center;">
    <div class="st-toggle" style="margin-right:12px;">
        <input type="checkbox" name="servertrack_meta_am_country" value="1"
                        <?php checked( 1, get_option( 'servertrack_meta_am_country', 1 ) ); ?>>
        <span class="st-toggle-slider"></span>
    </div>
    <span class="st-toggle-text" style="font-weight:500;"><?php esc_html_e( 'Country', 'servertrack' ); ?></span>
</label>
            </fieldset>
        </td>
    </tr>
    <tr>
        <th scope="row"><label for="servertrack_meta_test_event_code"><?php esc_html_e( 'Test Event Code', 'servertrack' ); ?></label></th>
        <td>
            <input type="text"
                   id="servertrack_meta_test_event_code"
                   name="servertrack_meta_test_event_code"
                   value="<?php echo esc_attr( get_option( 'servertrack_meta_test_event_code', '' ) ); ?>"
                   class="regular-text st-field-input"
                   placeholder="TEST12345 (optional)"
                   autocomplete="off" />
            <p class="description"><?php esc_html_e( 'Only required when using Meta Test Events tool. Leave blank in production.', 'servertrack' ); ?></p>
        </td>
    </tr>
</table>
</div><!-- /.st-settings-section -->
<hr />
<h2><?php esc_html_e( 'Send Test Event', 'servertrack' ); ?></h2>
<p><?php esc_html_e( 'Sends a dummy Purchase event to Meta CAPI to verify your credentials.', 'servertrack' ); ?></p>
<button type="button" class="button button-secondary servertrack-test-btn" data-platform="meta">
    <?php esc_html_e( 'Send Test Event → Meta', 'servertrack' ); ?>
</button>
<div class="st-test-result servertrack-test-response" id="servertrack-test-response-meta"></div>
