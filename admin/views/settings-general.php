<?php
/**
 * General settings tab — view fragment only.
 * C1/C9: <form> and settings_fields() removed. render_page() owns the form.
 */
if ( ! defined( 'ABSPATH' ) ) exit;
?>
<table class="form-table" role="presentation">
    <tr>
        <th scope="row"><?php esc_html_e( 'Enable Plugin', 'servertrack' ); ?></th>
        <td>
            <label>
                <input type="checkbox" name="servertrack_enabled" value="1"
                    <?php checked( 1, get_option( 'servertrack_enabled', 1 ) ); ?> />
                <?php esc_html_e( 'Activate server-side event sending', 'servertrack' ); ?>
            </label>
        </td>
    </tr>
    <tr>
        <th scope="row"><?php esc_html_e( 'Test Mode', 'servertrack' ); ?></th>
        <td>
            <label>
                <input type="checkbox" name="servertrack_test_mode" value="1"
                    <?php checked( 1, get_option( 'servertrack_test_mode', 0 ) ); ?> />
                <?php esc_html_e( 'Send events to platform test/sandbox endpoints only', 'servertrack' ); ?>
            </label>
            <p class="description"><?php esc_html_e( 'Enable this during development. Disable before going live.', 'servertrack' ); ?></p>
        </td>
    </tr>
    <tr>
        <th scope="row"><label for="servertrack_consent_mode"><?php esc_html_e( 'Consent Mode', 'servertrack' ); ?></label></th>
        <td>
            <select id="servertrack_consent_mode" name="servertrack_consent_mode">
                <?php
                $current = get_option( 'servertrack_consent_mode', 'none' );
                $options = [
                    'none'       => __( 'None (send all events, no consent check)', 'servertrack' ),
                    'cookie_yes' => __( 'CookieYes (read cookieyes-consent cookie)', 'servertrack' ),
                    'complianz'  => __( 'Complianz (read cmplz_marketing cookie)', 'servertrack' ),
                    'manual'     => __( 'Manual (use servertrack_consent_granted filter)', 'servertrack' ),
                ];
                foreach ( $options as $val => $label ) :
                    printf(
                        '<option value="%s" %s>%s</option>',
                        esc_attr( $val ),
                        selected( $current, $val, false ),
                        esc_html( $label )
                    );
                endforeach;
                ?>
            </select>
            <p class="description"><?php esc_html_e( 'Determines how the plugin checks for user consent before sending PII to ad platforms.', 'servertrack' ); ?></p>
        </td>
    </tr>
</table>
