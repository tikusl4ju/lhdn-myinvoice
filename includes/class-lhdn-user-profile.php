<?php
/**
 * LHDN User Profile Integration
 */

if (!defined('ABSPATH')) exit;

class LHDN_User_Profile {
    
    private $api;
    
    public function __construct() {
        $this->api = new LHDN_API();
    }

    /**
     * Save myaccount fields (wrapper for validate_user_tin_on_save)
     */
    public function save_myaccount_fields($user_id) {
        $this->validate_user_tin_on_save($user_id);
    }

    /**
     * Add user profile fields
     */
    public function add_profile_fields($user) {
        wp_nonce_field('lhdn_user_profile', 'lhdn_nonce');

        $validation = get_user_meta($user->ID, 'lhdn_tin_validation', true);
        $msg        = get_user_meta($user->ID, 'lhdn_tin_validation_msg', true);
        $checked    = get_user_meta($user->ID, 'lhdn_tin_last_checked', true);
        ?>
        <h2>LHDN e-Invoicing</h2>

        <table class="form-table">

            <?php if ($validation) : ?>
            <tr>
                <th>TIN Validation Status</th>
                <td>
                    <?php
                    switch ($validation) {
                        case 'valid':
                            echo '<span style="color:#2ecc71;font-weight:600;">✔ Valid</span>';
                            break;
                        case 'invalid':
                            echo '<span style="color:#e74c3c;font-weight:600;">✖ Invalid</span>';
                            break;
                        case 'error':
                            echo '<span style="color:#f39c12;font-weight:600;">⚠ Error</span>';
                            break;
                        case 'skipped':
                            echo '<span style="color:#777;font-weight:600;">— Not provided</span>';
                            break;
                        default:
                            echo '<span style="color:#777;">⚠ Not validated</span>';
                    }
                    ?>

                    <?php if ($msg) : ?>
                        <p class="description">
                            <?php echo esc_html($msg); ?>
                        </p>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endif; ?>

            <tr>
                <th><label for="lhdn_tin">TIN Number</label></th>
                <td>
                    <input type="text"
                           name="lhdn_tin"
                           id="lhdn_tin"
                           value="<?php echo esc_attr(get_user_meta($user->ID, 'lhdn_tin', true)); ?>"
                           class="regular-text" />
                    <p class="description">Optional taxpayer identification number</p>
                </td>
            </tr>

            <tr>
                <th><label for="lhdn_id_type">ID Type</label></th>
                <td>
                    <select name="lhdn_id_type" id="lhdn_id_type">
                        <option value="">— Select —</option>
                        <?php
                        $types = ['NRIC', 'PASSPORT', 'ARMY'];
                        $selected = get_user_meta($user->ID, 'lhdn_id_type', true);

                        foreach ($types as $type) {
                            printf(
                                '<option value="%1$s" %2$s>%1$s</option>',
                                esc_attr($type),
                                selected($selected, $type, false)
                            );
                        }
                        ?>
                    </select>
                    <p class="description">Optional identification type</p>
                </td>
            </tr>

            <tr>
                <th><label for="lhdn_id_value">ID Value</label></th>
                <td>
                    <input type="text"
                           name="lhdn_id_value"
                           id="lhdn_id_value"
                           value="<?php echo esc_attr(get_user_meta($user->ID, 'lhdn_id_value', true)); ?>"
                           class="regular-text" />
                    <p class="description">Optional identification number</p>
                </td>
            </tr>

        </table>
        <?php
    }

    /**
     * Validate and save user TIN
     */
    public function validate_user_tin_on_save($user_id) {
        if (!LHDN_Settings::is_plugin_active()) {
            LHDN_Logger::log("User TIN validation skipped for user {$user_id} (plugin inactive)");
            return;
        }
        
        if (!current_user_can('edit_user', $user_id)) {
            return;
        }

        if (!$this->verify_nonce()) {
            LHDN_Logger::log('LHDN nonce verification failed');
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in verify_nonce() method above
        $tin      = isset($_POST['lhdn_tin']) ? sanitize_text_field(wp_unslash($_POST['lhdn_tin'])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in verify_nonce() method above
        $id_type  = isset($_POST['lhdn_id_type']) ? sanitize_text_field(wp_unslash($_POST['lhdn_id_type'])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in verify_nonce() method above
        $id_value = isset($_POST['lhdn_id_value']) ? sanitize_text_field(wp_unslash($_POST['lhdn_id_value'])) : '';

        if ($tin === '' && $id_type === '' && $id_value === '') {
            delete_user_meta($user_id, 'lhdn_tin');
            delete_user_meta($user_id, 'lhdn_id_type');
            delete_user_meta($user_id, 'lhdn_id_value');
            update_user_meta($user_id, 'lhdn_tin_validation', 'skipped');
            return;
        }

        if (!$tin || !$id_type || !$id_value) {
            if ($this->is_woocommerce_account()) {
                wc_add_notice(
                    __('Please fill TIN, ID Type and ID Value together or leave all empty.', 'myinvoice-sync'),
                    'error'
                );
            } else {
                $GLOBALS['lhdn_profile_error'] = 'partial';
            }
            return;
        }

        $result = $this->api->validate_tin($tin, $id_type, $id_value);

        if ($result['status'] !== 'valid') {
            delete_user_meta($user_id, 'lhdn_tin');
            delete_user_meta($user_id, 'lhdn_id_type');
            delete_user_meta($user_id, 'lhdn_id_value');

            update_user_meta($user_id, 'lhdn_tin_validation', $result['status']);
            update_user_meta($user_id, 'lhdn_tin_validation_msg', $result['message']);
            update_user_meta($user_id, 'lhdn_tin_last_checked', current_time('mysql'));

            if ($this->is_woocommerce_account()) {
                wc_add_notice(
                    __('LHDN TIN validation failed: ', 'myinvoice-sync') . esc_html($result['message']),
                    'error'
                );
            } else {
                $GLOBALS['lhdn_profile_error'] = 'invalid';
            }
            return;
        }

        update_user_meta($user_id, 'lhdn_tin', $tin);
        update_user_meta($user_id, 'lhdn_id_type', $id_type);
        update_user_meta($user_id, 'lhdn_id_value', $id_value);
        update_user_meta($user_id, 'lhdn_tin_validation', 'valid');
        delete_user_meta($user_id, 'lhdn_tin_validation_msg');
        update_user_meta($user_id, 'lhdn_tin_last_checked', current_time('mysql'));

        LHDN_Logger::log("User {$user_id} TIN validated successfully");
    }

    /**
     * Add myaccount fields
     */
    public function add_myaccount_fields() {
        wp_nonce_field('lhdn_myaccount', 'lhdn_nonce');

        $user_id = get_current_user_id();
        if (!$user_id) return;

        $tin      = get_user_meta($user_id, 'lhdn_tin', true);
        $id_type  = get_user_meta($user_id, 'lhdn_id_type', true);
        $id_value = get_user_meta($user_id, 'lhdn_id_value', true);

        $validation = get_user_meta($user_id, 'lhdn_tin_validation', true);
        $msg        = get_user_meta($user_id, 'lhdn_tin_validation_msg', true);
        $checked    = get_user_meta($user_id, 'lhdn_tin_last_checked', true);
        ?>

        <fieldset>
            <legend><strong>LHDN e-Invoicing</strong></legend>
            <?php if ($validation) : ?>
                <p>
                    <strong>TIN Status:</strong>
                    <?php
                    switch ($validation) {
                        case 'valid':
                            echo '<span style="color:#2ecc71;">✔ Valid</span>';
                            break;
                        case 'invalid':
                            echo '<span style="color:#e74c3c;">✖ Invalid</span>';
                            break;
                        case 'error':
                            echo '<span style="color:#f39c12;">⚠ Error</span>';
                            break;
                        case 'skipped':
                            echo '<span style="color:#777;">— Not provided</span>';
                            break;
                        default:
                            echo '<span style="color:#777;">⚠ Not validated</span>';
                    }
                    ?>
                </p>

                <?php if ($msg) : ?>
                    <p><small><?php echo esc_html($msg); ?></small></p>
                <?php endif; ?>
            <?php endif; ?>

            <p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
                <label for="lhdn_tin">TIN Number</label>
                <input type="text"
                       class="woocommerce-Input woocommerce-Input--text input-text"
                       name="lhdn_tin"
                       id="lhdn_tin"
                       value="<?php echo esc_attr($tin); ?>" />
            </p>

            <p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
                <label for="lhdn_id_type">ID Type</label>
                <select name="lhdn_id_type" id="lhdn_id_type"
                        class="woocommerce-Input woocommerce-Input-select">
                    <option value="">— Select —</option>
                    <?php
                    foreach (['NRIC', 'PASSPORT', 'ARMY'] as $type) {
                        printf(
                            '<option value="%1$s" %2$s>%1$s</option>',
                            esc_attr($type),
                            selected($id_type, $type, false)
                        );
                    }
                    ?>
                </select>
            </p>

            <p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
                <label for="lhdn_id_value">ID Value</label>
                <input type="text"
                       class="woocommerce-Input woocommerce-Input--text input-text"
                       name="lhdn_id_value"
                       id="lhdn_id_value"
                       value="<?php echo esc_attr($id_value); ?>" />
            </p>

        </fieldset>
        <br />
        <?php if($validation !== 'valid'){ ?>
        <p>Check your TIN number via the MyTax Portal (e-Daftar or Your Profile Information) or contact the HASiL Contact Centre (03-8911 1000); or visit the nearest LHDNM offices.</p>
        <?php
        }
    }

    /**
     * Verify nonce
     */
    private function verify_nonce() {
        if (!isset($_POST['lhdn_nonce'])) {
            return false;
        }

        $nonce = isset($_POST['lhdn_nonce']) ? sanitize_text_field(wp_unslash($_POST['lhdn_nonce'])) : '';
        if (empty($nonce)) {
            return false;
        }

        return wp_verify_nonce($nonce, 'lhdn_user_profile')
            || wp_verify_nonce($nonce, 'lhdn_myaccount');
    }

    /**
     * Check if WooCommerce account page
     */
    private function is_woocommerce_account() {
        return function_exists('is_account_page') && is_account_page();
    }

    /**
     * Show TIN status badge on checkout
     */
    public function show_tin_status_badge() {
        if (!LHDN_Settings::is_plugin_active()) {
            return;
        }
        
        // Check if TIN badge is enabled
        if (LHDN_Settings::get('show_tin_badge', '1') !== '1') {
            return;
        }
        
        if (!is_user_logged_in()) {
            return;
        }

        $user_id = get_current_user_id();

        $tin      = get_user_meta($user_id, 'lhdn_tin', true);
        $status   = get_user_meta($user_id, 'lhdn_tin_validation', true);
        $checked  = get_user_meta($user_id, 'lhdn_tin_last_checked', true);
        $msg      = get_user_meta($user_id, 'lhdn_tin_validation_msg', true);

        $label = 'Not validated';
        $color = '#777';
        $icon  = '⚪';

        switch ($status) {
            case 'valid':
                $label = 'Valid';
                $color = '#2ecc71';
                $icon  = '✔';
                break;

            case 'invalid':
                $label = 'Invalid';
                $color = '#e74c3c';
                $icon  = '✖';
                break;

            case 'error':
                $label = 'Error';
                $color = '#f39c12';
                $icon  = '⚠';
                break;
        }
        ?>

        <div class="lhdn-tin-status"
             style="margin-bottom:20px;padding:12px 15px;border-left:5px solid <?php echo esc_attr($color); ?>;background:#f9f9f9;">
            <strong>
                LHDN TIN Status:
                <span style="color:<?php echo esc_attr($color); ?>;">
                    <?php echo esc_html($icon . ' ' . $label); ?>
                </span>
            </strong>

            <?php if ($msg && $status !== 'valid') : ?>
                <div style="font-size:13px;color:#666;margin-top:5px;">
                    <?php echo esc_html($msg); ?>
                </div>
            <?php endif; ?>

            <?php if ($status !== 'valid') : ?>
                <div style="margin-top:8px;">
                    Check your TIN number via the MyTax Portal (e-Daftar or Your Profile Information) or contact the HASiL Contact Centre (03-8911 1000); or visit the nearest LHDNM offices. <br />
                    <a href="<?php echo esc_url(wc_get_page_permalink('myaccount') . 'edit-account/'); ?>">
                        Please update TIN details in your profile HERE
                    </a>
                </div>
            <?php endif; ?>

        </div>

        <?php
    }
}

