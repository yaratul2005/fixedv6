<?php
if ( ! defined( 'ABSPATH' ) ) exit;

$license_key = get_option( 'servertrack_license_key', '' );
$is_active = ServerTrack_License::is_active();
?>

<?php settings_errors( 'servertrack_license_messages' ); ?>
<div class="st-settings-section">
    <div class="st-settings-section-header">
        <div class="st-section-icon">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
            </svg>
        </div>
        <div class="st-settings-section-title">
            <h3 style="margin:0;">Plugin License</h3>
            <p class="st-settings-section-desc">Activate your license to enable automatic updates and support.</p>
        </div>
    </div>

    <div class="st-settings-section-body">
        <?php wp_nonce_field( 'servertrack_license_action', 'servertrack_license_nonce' ); ?>
        <?php if ( $is_active ) : ?>
            <div class="st-notice st-notice-success" style="margin-bottom:20px;">
                <svg class="st-notice-icon" viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                <span>Your license is <strong>active</strong>. You have access to automatic updates.</span>
            </div>
        <?php else : ?>
            <div class="st-notice st-notice-warning" style="margin-bottom:20px;">
                <svg class="st-notice-icon" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
                <span>Your license is not active. Please enter your key below to receive updates.</span>
            </div>
        <?php endif; ?>

        <table class="form-table" role="presentation">
            <tr>
                <th scope="row">License Key</th>
                <td>
                    <input type="text" name="servertrack_license_key" value="<?php echo esc_attr( $license_key ); ?>" class="regular-text st-field-input" <?php echo $is_active ? 'readonly' : ''; ?> />

                    <?php if ( $is_active ) : ?>
                        <p class="description">To change your license key, deactivate the current one first.</p>
                        <button type="submit" name="st_license_action" value="deactivate" class="button button-secondary" style="margin-top: 10px;">Deactivate License</button>
                    <?php else : ?>
                        <p class="description">Enter the license key from your great10.xyz dashboard.</p>
                        <button type="submit" name="st_license_action" value="activate" class="button button-primary" style="margin-top: 10px;">Activate License</button>
                    <?php endif; ?>
                </td>
            </tr>
        </table>
    </div>
</div>
