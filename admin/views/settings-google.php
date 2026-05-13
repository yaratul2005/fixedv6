<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Admin view — Google Ads tab
 *
 * Day 6 additions:
 *   - Inline OAuth 2.0 flow UI: "Connect with Google" button, redirect URI helper,
 *     and a clear-token button to revoke / re-authenticate.
 *   - Token status indicator: shows connected account + expiry or "Not connected".
 *   - Client-side toggle: credential fields hidden when Google is disabled.
 *   - Inline step-by-step guide for Google Cloud Console setup.
 */
?>
<form method="post" action="options.php">
    <?php settings_fields( 'servertrack_settings' ); ?>

    <table class="form-table" role="presentation">

        <!-- Enable / Disable -->
        <tr>
            <th scope="row"><?php esc_html_e( 'Enable Google Ads', 'servertrack' ); ?></th>
            <td>
                <label>
                    <input type="checkbox" name="servertrack_google_enabled" value="1"
                        id="st-google-enabled"
                        <?php checked( 1, get_option( 'servertrack_google_enabled', 0 ) ); ?>>
                    <?php esc_html_e( 'Send server-side conversion events to Google Ads (Enhanced Conversions).', 'servertrack' ); ?>
                </label>
            </td>
        </tr>

    </table>

    <div id="st-google-fields" style="<?php echo get_option( 'servertrack_google_enabled', 0 ) ? '' : 'display:none'; ?>">

        <!-- ── OAuth Status Card ─────────────────────────────────────────── -->
        <?php
        $access_token  = get_option( 'servertrack_google_access_token', '' );
        $token_expires = (int) get_option( 'servertrack_google_token_expires', 0 );
        $refresh_token = get_option( 'servertrack_google_refresh_token', '' );
        $is_connected  = ! empty( $refresh_token );
        ?>
        <div class="st-oauth-card <?php echo $is_connected ? 'st-oauth-card--connected' : 'st-oauth-card--disconnected'; ?>">
            <div class="st-oauth-card__icon">
                <?php if ( $is_connected ) : ?>
                    <span class="st-badge st-badge--success">&#10003; <?php esc_html_e( 'Connected', 'servertrack' ); ?></span>
                <?php else : ?>
                    <span class="st-badge st-badge--warning">&#9679; <?php esc_html_e( 'Not connected', 'servertrack' ); ?></span>
                <?php endif; ?>
            </div>
            <div class="st-oauth-card__body">
                <?php if ( $is_connected ) : ?>
                    <p><?php esc_html_e( 'Google account is authorised. Refresh token is stored securely.', 'servertrack' ); ?>
                    <?php if ( $token_expires > 0 ) : ?>
                        &nbsp;<small><?php printf(
                            /* translators: %s = human-readable time */
                            esc_html__( 'Access token expires: %s', 'servertrack' ),
                            esc_html( human_time_diff( time(), $token_expires ) . ' ' . __( 'from now', 'servertrack' ) )
                        ); ?></small>
                    <?php endif; ?>
                    </p>
                    <p>
                        <a href="<?php echo esc_url( admin_url( 'options-general.php?page=servertrack&tab=google&st_google_action=revoke&_wpnonce=' . wp_create_nonce( 'st_google_revoke' ) ) ); ?>"
                           class="button button-secondary st-btn-revoke"
                           onclick="return confirm('<?php esc_attr_e( 'This will remove your Google OAuth tokens. You will need to re-connect. Continue?', 'servertrack' ); ?>');">
                            <?php esc_html_e( 'Disconnect Google Account', 'servertrack' ); ?>
                        </a>
                    </p>
                <?php else : ?>
                    <p><?php esc_html_e( 'Connect your Google account to authorise ServerTrack to send Enhanced Conversions on your behalf.', 'servertrack' ); ?></p>
                    <?php
                    // Build the OAuth URL only when client_id + client_secret are saved
                    $client_id     = get_option( 'servertrack_google_client_id', '' );
                    $client_secret = get_option( 'servertrack_google_client_secret', '' );
                    $redirect_uri  = admin_url( 'options-general.php?page=servertrack&tab=google' );

                    if ( $client_id && $client_secret ) :
                        $oauth_url = add_query_arg( [
                            'client_id'             => rawurlencode( $client_id ),
                            'redirect_uri'          => rawurlencode( $redirect_uri ),
                            'response_type'         => 'code',
                            'scope'                 => rawurlencode( 'https://www.googleapis.com/auth/adwords' ),
                            'access_type'           => 'offline',
                            'prompt'                => 'consent',
                        ], 'https://accounts.google.com/o/oauth2/v2/auth' );
                        ?>
                        <a href="<?php echo esc_url( $oauth_url ); ?>" class="button button-primary">
                            <?php esc_html_e( '&#9654; Connect with Google', 'servertrack' ); ?>
                        </a>
                    <?php else : ?>
                        <p><em><?php esc_html_e( 'Save your Client ID and Client Secret below first, then return here to connect.', 'servertrack' ); ?></em></p>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- ── How to get credentials ────────────────────────────────────── -->
        <div class="st-help-box">
            <p><strong><?php esc_html_e( 'How to set up Google OAuth credentials:', 'servertrack' ); ?></strong></p>
            <ol>
                <li><?php printf(
                    /* translators: %s = URL */
                    wp_kses( __( 'Go to <a href="%s" target="_blank" rel="noopener">Google Cloud Console → APIs &amp; Services → Credentials</a>.', 'servertrack' ), [ 'a' => [ 'href' => [], 'target' => [], 'rel' => [] ] ] ),
                    'https://console.cloud.google.com/apis/credentials'
                ); ?></li>
                <li><?php esc_html_e( 'Create an OAuth 2.0 Client ID of type "Web application".', 'servertrack' ); ?></li>
                <li><?php esc_html_e( 'Under "Authorised redirect URIs", add the following URI exactly:', 'servertrack' ); ?>
                    <code class="st-copy-uri"><?php echo esc_url( admin_url( 'options-general.php?page=servertrack&tab=google' ) ); ?></code>
                    <button type="button" class="button-link st-copy-btn" data-target=".st-copy-uri"><?php esc_html_e( 'Copy', 'servertrack' ); ?></button>
                </li>
                <li><?php esc_html_e( 'Enable the Google Ads API in the Cloud Console.', 'servertrack' ); ?></li>
                <li><?php esc_html_e( 'Paste your Client ID and Client Secret into the fields below, save, then click "Connect with Google".', 'servertrack' ); ?></li>
            </ol>
        </div>

        <table class="form-table" role="presentation">

            <!-- Customer ID -->
            <tr>
                <th scope="row"><label for="st_google_customer_id"><?php esc_html_e( 'Google Ads Customer ID', 'servertrack' ); ?></label></th>
                <td>
                    <input type="text" id="st_google_customer_id"
                           name="servertrack_google_customer_id"
                           value="<?php echo esc_attr( get_option( 'servertrack_google_customer_id', '' ) ); ?>"
                           class="regular-text" placeholder="123-456-7890">
                    <p class="description"><?php esc_html_e( 'Your 10-digit Google Ads account ID (without dashes). Found in Google Ads → top-right menu.', 'servertrack' ); ?></p>
                </td>
            </tr>

            <!-- Conversion ID -->
            <tr>
                <th scope="row"><label for="st_google_conversion_id"><?php esc_html_e( 'Conversion Action ID', 'servertrack' ); ?></label></th>
                <td>
                    <input type="text" id="st_google_conversion_id"
                           name="servertrack_google_conversion_id"
                           value="<?php echo esc_attr( get_option( 'servertrack_google_conversion_id', '' ) ); ?>"
                           class="regular-text" placeholder="e.g. 12345678">
                    <p class="description"><?php esc_html_e( 'Conversion action numeric ID from Google Ads → Goals → Conversions.', 'servertrack' ); ?></p>
                </td>
            </tr>

            <!-- Developer Token -->
            <tr>
                <th scope="row"><label for="st_google_developer_token"><?php esc_html_e( 'Developer Token', 'servertrack' ); ?></label></th>
                <td>
                    <input type="password" id="st_google_developer_token"
                           name="servertrack_google_developer_token"
                           value="<?php echo esc_attr( get_option( 'servertrack_google_developer_token', '' ) ); ?>"
                           class="regular-text" autocomplete="new-password">
                    <p class="description"><?php esc_html_e( 'From Google Ads → API Centre. Required for all Ads API calls.', 'servertrack' ); ?></p>
                </td>
            </tr>

            <!-- Client ID -->
            <tr>
                <th scope="row"><label for="st_google_client_id"><?php esc_html_e( 'OAuth Client ID', 'servertrack' ); ?></label></th>
                <td>
                    <input type="text" id="st_google_client_id"
                           name="servertrack_google_client_id"
                           value="<?php echo esc_attr( get_option( 'servertrack_google_client_id', '' ) ); ?>"
                           class="regular-text" placeholder="xxxxxx.apps.googleusercontent.com">
                </td>
            </tr>

            <!-- Client Secret -->
            <tr>
                <th scope="row"><label for="st_google_client_secret"><?php esc_html_e( 'OAuth Client Secret', 'servertrack' ); ?></label></th>
                <td>
                    <input type="password" id="st_google_client_secret"
                           name="servertrack_google_client_secret"
                           value="<?php echo esc_attr( get_option( 'servertrack_google_client_secret', '' ) ); ?>"
                           class="regular-text" autocomplete="new-password">
                </td>
            </tr>

            <!-- Refresh Token (read-only display) -->
            <?php if ( $refresh_token ) : ?>
            <tr>
                <th scope="row"><?php esc_html_e( 'Refresh Token', 'servertrack' ); ?></th>
                <td>
                    <input type="text" value="<?php echo esc_attr( substr( $refresh_token, 0, 8 ) . str_repeat( '•', 20 ) ); ?>"
                           class="regular-text" disabled readonly>
                    <p class="description"><?php esc_html_e( 'Stored securely. Use Disconnect to revoke.', 'servertrack' ); ?></p>
                </td>
            </tr>
            <?php endif; ?>

        </table>
    </div><!-- /#st-google-fields -->

    <?php submit_button( __( 'Save Google Settings', 'servertrack' ) ); ?>
</form>

<script>
(function(){
    var toggle = document.getElementById('st-google-enabled');
    var fields = document.getElementById('st-google-fields');
    if ( toggle && fields ) {
        toggle.addEventListener('change', function(){
            fields.style.display = this.checked ? '' : 'none';
        });
    }
    // Copy URI helper
    document.querySelectorAll('.st-copy-btn').forEach(function(btn){
        btn.addEventListener('click', function(){
            var target = document.querySelector(btn.dataset.target);
            if ( ! target ) return;
            var text = target.textContent || target.innerText;
            navigator.clipboard.writeText(text.trim()).then(function(){
                btn.textContent = '<?php esc_html_e( 'Copied!', 'servertrack' ); ?>';
                setTimeout(function(){ btn.textContent = '<?php esc_html_e( 'Copy', 'servertrack' ); ?>'; }, 2000);
            });
        });
    });
})();
</script>
