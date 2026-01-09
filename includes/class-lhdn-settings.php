<?php
/**
 * LHDN Settings Manager
 */

if (!defined('ABSPATH')) exit;

class LHDN_Settings {
    
    /**
     * Get setting value
     */
    public static function get($key, $default = null) {
        global $wpdb;
        $table = $wpdb->prefix . 'lhdn_settings';

        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
            return $default;
        }

        $val = $wpdb->get_var(
            $wpdb->prepare("SELECT setting_value FROM {$table} WHERE setting_key = %s", $key)
        );

        return $val !== null ? maybe_unserialize($val) : $default;
    }

    /**
     * Set setting value
     */
    public static function set($key, $value) {
        global $wpdb;
        $table = $wpdb->prefix . 'lhdn_settings';

        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
            // Table doesn't exist, try to create it
            if (class_exists('LHDN_Database')) {
                LHDN_Database::create_tables();
                // Check again after creation attempt
                if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
                    return false;
                }
            } else {
                return false;
            }
        }

        return $wpdb->replace($table, [
            'setting_key'   => $key,
            'setting_value' => maybe_serialize($value),
            'updated_at'    => current_time('mysql')
        ]);
    }

    /**
     * Initialize default settings
     */
    public static function init_defaults() {
        $defaults = [
            'debug_enabled' => '1',
            'show_tin_badge' => '1',
            'show_receipt' => '1',
            'exclude_wallet' => '1',
            'tin_enforce' => '0',
            'custom_order_statuses' => '',
            'environment'   => 'sandbox', // sandbox or production
            'api_host'      => 'https://preprod-api.myinvois.hasil.gov.my',
            'host'           => 'https://preprod.myinvois.hasil.gov.my',
            'oauth_url'      => '/connect/token',
            'get_doc_url'    => '/api/v1.0/documents/',
            'submit_doc_url' => '/api/v1.0/documentsubmissions/',
            'validate_tin_url'=> '/api/v1.0/taxpayer/validate/',
            'cancel_doc_url' => '/api/v1.0/documents/state/',
            'client_id'      => 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx',
            'client_secret1' => 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx',
            'client_secret2' => 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx',
            'seller_tin'     => 'C88888888888',
            'seller_id_type' => 'BRN',
            'seller_id_value' => '888888888888',
            'seller_name'    => 'Seller Name Registered In LHDN',
            'seller_email'   => 'seller@example.com',
            'seller_phone'   => '60123456789',
            'seller_city'    => 'Petaling Jaya',
            'seller_postcode' => '46200',
            'seller_state'   => 'SL',
            'seller_address1' => 'Address',
            'seller_country' => 'MYS',
            'seller_sst_number' => 'NA', // Seller SST Number
            'seller_ttx_number' => 'NA', // Seller TTX Number
            'tax_category_id' => 'E', // Default to Tax exemption
            'industry_classification_code' => '86909', // Default MSIC code
            'billing_circle' => 'on_completed', // Billing Circle: on_completed, on_processed, after_1_day, after_2_days, etc.
            'ubl_version' => '1.0', // UBL Version: 1.0 or 1.1
            'plugin_active' => '0', // Plugin activation status: '1' for active, '0' for inactive (default: inactive)
        ];

        foreach ($defaults as $key => $value) {
            if (self::get($key) === null) {
                self::set($key, $value);
            }
        }
    }

    /**
     * Get API host based on environment
     */
    public static function get_api_host() {
        $environment = self::get('environment', 'sandbox');
        
        if ($environment === 'production') {
            return 'https://api.myinvois.hasil.gov.my';
        }
        
        return 'https://preprod-api.myinvois.hasil.gov.my';
    }

    /**
     * Get portal host based on environment
     */
    public static function get_portal_host() {
        $environment = self::get('environment', 'sandbox');
        
        if ($environment === 'production') {
            return 'https://myinvois.hasil.gov.my';
        }
        
        return 'https://preprod.myinvois.hasil.gov.my';
    }

    /**
     * Update environment and sync API/Portal hosts
     */
    public static function set_environment($environment) {
        $old_environment = self::get('environment', 'sandbox');
        
        self::set('environment', $environment);
        
        // Auto-update API and Portal hosts based on environment
        self::set('api_host', self::get_api_host());
        self::set('host', self::get_portal_host());
        
        // Clear OAuth token cache when switching environments
        // Tokens from one environment won't work in another
        if ($old_environment !== $environment) {
            global $wpdb;
            $table = $wpdb->prefix . 'lhdn_tokens';
            $wpdb->query("DELETE FROM {$table}");
            LHDN_Logger::log("Environment changed from {$old_environment} to {$environment}. OAuth tokens cleared.");
        }
    }

    /**
     * Check if plugin is active
     */
    public static function is_plugin_active() {
        return self::get('plugin_active', '0') === '1';
    }
}

