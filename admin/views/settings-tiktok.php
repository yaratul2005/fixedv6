<?php
/**
 * TikTok Events settings tab — view fragment only.
 * C1/C9: <form> and settings_fields() removed. render_page() owns the form.
 */
if ( ! defined( 'ABSPATH' ) ) exit;
?>
<table class="form-table" role="presentation">
    <tr>
        <th scope="row"><?php esc_html_e( 'Enable TikTok Events', 'servertrack' ); ?></th>
        <td>
            <label>
                <input type="checkbox" name="servertrack_tiktok_enabled" value="1"
                    <?php checked( 1, get_option( 'servertrack_tiktok_enabled', 0 ) ); ?> />
                <?php esc_html_e( 'Send events to TikTok Events API', 'servertrack' ); ?>
            </label>
        </td>
    </tr>
    <tr>
        <th scope="row"><label for="servertrack_tiktok_pixel_id"><?php esc_html_e( 'TikTok Pixel ID', 'servertrack' ); ?></label></th>
        <td>
            <input type="text"
                   id="servertrack_tiktok_pixel_id"
                   name="servertrack_tiktok_pixel_id"
                   value="<?php echo esc_attr( get_option( 'servertrack_tiktok_pixel_id', '' ) ); ?>"
                   class="regular-text"
                   placeholder="e.g. C1234ABCD5678"
                   autocomplete="off" />
            <p class="description"><?php esc_html_e( 'Found in TikTok Ads Manager → Assets → Events → Web Events.', 'servertrack' ); ?></p>
        </td>
    </tr>
    <tr>
        <th scope="row"><label for="servertrack_tiktok_access_token"><?php esc_html_e( 'Access Token', 'servertrack' ); ?></label></th>
        <td>
            <input type="password"
                   id="servertrack_tiktok_access_token"
                   name="servertrack_tiktok_access_token"
                   value="<?php echo esc_attr( get_option( 'servertrack_tiktok_access_token', '' ) ); ?>"
                   class="regular-text"
                   autocomplete="new-password" />
            <p class="description"><?php esc_html_e( 'Generate from TikTok Events Manager → Manage → Generate Access Token.', 'servertrack' ); ?></p>
        </td>
    </tr>
</table>
<hr />
<h2><?php esc_html_e( 'Send Test Event', 'servertrack' ); ?></h2>
<p><?php esc_html_e( 'Sends a dummy Purchase event to TikTok Events API to verify your credentials.', 'servertrack' ); ?></p>
<button type="button" class="button button-secondary servertrack-test-btn" data-platform="tiktok">
    <?php esc_html_e( 'Send Test Event → TikTok', 'servertrack' ); ?>
</button>
<div class="servertrack-test-response" id="servertrack-test-response-tiktok"></div>
