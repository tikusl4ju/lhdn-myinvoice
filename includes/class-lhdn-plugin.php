<?php
/**
 * Main LHDN MyInvoice Plugin Class
 */

if (!defined('ABSPATH')) exit;

class LHDN_MyInvoice_Plugin {
    
    private static $instance = null;
    
    private $admin;
    private $cron;
    private $woocommerce;
    private $user_profile;
    
    /**
     * Get singleton instance
     */
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        $this->load_dependencies();
        $this->define_basic_constants();
        $this->init();
    }

    /**
     * Define basic constants needed early
     */
    private function define_basic_constants() {
        // UBL Version from settings (can be overridden later)
        if (!defined('LHDN_UBL_VERSION')) {
            $ubl_version = LHDN_Settings::get('ubl_version', '1.0');
            // Validate: Only allow 1.1 if PEM certificate exists in database
            if ($ubl_version === '1.1' && empty(self::get_pem_from_database())) {
                // Force to 1.0 if no certificate
                $ubl_version = '1.0';
                LHDN_Settings::set('ubl_version', '1.0');
            }
            define('LHDN_UBL_VERSION', $ubl_version);
        }
        
        // PEM content from database only (no file fallback)
        if (!defined('LHDN_PEM_PATH')) {
            $pem_content = self::get_pem_from_database();
            define('LHDN_PEM_PATH', $pem_content ?: '');
        }
    }

    /**
     * Get PEM content from database
     */
    public static function get_pem_from_database() {
        global $wpdb;
        $table = $wpdb->prefix . 'lhdn_cert';
        
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
            return '';
        }
        
        $cert = $wpdb->get_row(
            "SELECT pem_content FROM {$table} WHERE is_active = 1 ORDER BY created_at DESC LIMIT 1"
        );
        
        return $cert ? $cert->pem_content : '';
    }

    /**
     * Get UBL version (from settings, not constant)
     */
    public static function get_ubl_version() {
        return LHDN_Settings::get('ubl_version', '1.0');
    }

    /**
     * Load required files
     */
    private function load_dependencies() {
        require_once plugin_dir_path(__FILE__) . '../tpl/lhdn-ubl-invoice-tpl.php';
        require_once plugin_dir_path(__FILE__) . 'class-lhdn-settings.php';
        require_once plugin_dir_path(__FILE__) . 'class-lhdn-database.php';
        require_once plugin_dir_path(__FILE__) . 'class-lhdn-logger.php';
        require_once plugin_dir_path(__FILE__) . 'class-lhdn-helpers.php';
        require_once plugin_dir_path(__FILE__) . 'class-lhdn-api.php';
        require_once plugin_dir_path(__FILE__) . 'class-lhdn-invoice.php';
        require_once plugin_dir_path(__FILE__) . 'class-lhdn-cron.php';
        require_once plugin_dir_path(__FILE__) . 'class-lhdn-admin.php';
        require_once plugin_dir_path(__FILE__) . 'class-lhdn-woocommerce.php';
        require_once plugin_dir_path(__FILE__) . 'class-lhdn-user-profile.php';
        require_once plugin_dir_path(__FILE__) . 'class-lhdn-compatibility.php';
    }

    /**
     * Initialize plugin
     */
    private function init() {
        // Initialize settings
        add_action('init', [$this, 'init_settings']);
        
        // Define constants
        $this->define_constants();
        
        // Initialize components
        $this->admin = new LHDN_Admin();
        $this->cron = new LHDN_Cron();
        $this->woocommerce = new LHDN_WooCommerce();
        $this->user_profile = new LHDN_User_Profile();
        
        // Register hooks
        $this->register_hooks();
        
        // Activation/Deactivation
        register_activation_hook(plugin_dir_path(__FILE__) . '../lhdn-myinvoice.php', [$this, 'activate']);
        register_deactivation_hook(plugin_dir_path(__FILE__) . '../lhdn-myinvoice.php', [$this, 'deactivate']);
    }

    /**
     * Initialize settings
     */
    public function init_settings() {
        LHDN_Settings::init_defaults();
    }

    /**
     * Handle cron scheduling based on activation status
     */
    public function handle_cron_scheduling() {
        if (LHDN_Settings::is_plugin_active()) {
            $this->cron->schedule_events();
        } else {
            $this->cron->clear_events();
        }
    }

    /**
     * Define constants
     */
    private function define_constants() {
        if (!defined('LHDN_API_HOST')) {
            define('LHDN_API_HOST', LHDN_Settings::get_api_host());
        }

        if (!defined('LHDN_HOST')) {
            define('LHDN_HOST', LHDN_Settings::get_portal_host());
        }

        if (!defined('LHDN_OAUTH_URL')) {
            define('LHDN_OAUTH_URL', LHDN_Settings::get('oauth_url', '/connect/token/'));
        }

        if (!defined('LHDN_GET_DOC_URL')) {
            define('LHDN_GET_DOC_URL', LHDN_Settings::get('get_doc_url', '/api/v1.0/documents/'));
        }

        if (!defined('LHDN_SUBMIT_DOC_URL')) {
            define('LHDN_SUBMIT_DOC_URL', LHDN_Settings::get('submit_doc_url', '/api/v1.0/documentsubmissions/'));
        }

        if (!defined('LHDN_VALIDATE_TAXPAYERS_TIN_URL')) {
            define('LHDN_VALIDATE_TAXPAYERS_TIN_URL', LHDN_Settings::get('validate_tin_url', '/api/v1.0/taxpayer/validate/'));
        }

        if (!defined('LHDN_CANCEL_DOC_URL')) {
            define('LHDN_CANCEL_DOC_URL', LHDN_Settings::get('cancel_doc_url', '/api/v1.0/documents/state/'));
        }

        if (!defined('LHDN_CLIENT_ID')) {
            define('LHDN_CLIENT_ID', LHDN_Settings::get('client_id', ''));
        }

        if (!defined('LHDN_CLIENT_SECRET1')) {
            define('LHDN_CLIENT_SECRET1', LHDN_Settings::get('client_secret1', ''));
        }

        if (!defined('LHDN_CLIENT_SECRET2')) {
            define('LHDN_CLIENT_SECRET2', LHDN_Settings::get('client_secret2', ''));
        }

        if (!defined('LHDN_SELLER_TIN')) {
            define('LHDN_SELLER_TIN', LHDN_Settings::get('seller_tin', ''));
        }

        if (!defined('LHDN_SELLER_ID_TYPE')) {
            define('LHDN_SELLER_ID_TYPE', LHDN_Settings::get('seller_id_type', 'NRIC'));
        }

        if (!defined('LHDN_SELLER_ID_VALUE')) {
            define('LHDN_SELLER_ID_VALUE', LHDN_Settings::get('seller_id_value', ''));
        }

        if (!defined('LHDN_SELLER_NAME')) {
            define('LHDN_SELLER_NAME', LHDN_Settings::get('seller_name', ''));
        }

        if (!defined('LHDN_SELLER_EMAIL')) {
            define('LHDN_SELLER_EMAIL', LHDN_Settings::get('seller_email', ''));
        }

        if (!defined('LHDN_SELLER_PHONE')) {
            define('LHDN_SELLER_PHONE', LHDN_Settings::get('seller_phone', ''));
        }

        if (!defined('LHDN_SELLER_ADDRESS_CITY')) {
            define('LHDN_SELLER_ADDRESS_CITY', LHDN_Settings::get('seller_city', ''));
        }

        if (!defined('LHDN_SELLER_ADDRESS_POSTCODE')) {
            define('LHDN_SELLER_ADDRESS_POSTCODE', LHDN_Settings::get('seller_postcode', ''));
        }

        if (!defined('LHDN_SELLER_ADDRESS_STATE')) {
            define('LHDN_SELLER_ADDRESS_STATE', LHDN_Settings::get('seller_state', ''));
        }

        if (!defined('LHDN_SELLER_ADDRESS_LINE1')) {
            define('LHDN_SELLER_ADDRESS_LINE1', LHDN_Settings::get('seller_address1', ''));
        }

        if (!defined('LHDN_SELLER_ADDRESS_COUNTRY')) {
            define('LHDN_SELLER_ADDRESS_COUNTRY', LHDN_Settings::get('seller_country', 'MYS'));
        }

        if (!defined('LHDN_SELLER_SST_NUMBER')) {
            define('LHDN_SELLER_SST_NUMBER', LHDN_Settings::get('seller_sst_number', 'NA'));
        }

        if (!defined('LHDN_SELLER_TTX_NUMBER')) {
            define('LHDN_SELLER_TTX_NUMBER', LHDN_Settings::get('seller_ttx_number', 'NA'));
        }
    }

    /**
     * Register WordPress hooks
     */
    private function register_hooks() {
        // Check database structure on admin init (for existing installations)
        add_action('admin_init', ['LHDN_MyInvoice_Plugin', 'check_database_structure'], 5);
        
        // Admin
        add_action('admin_menu', [$this->admin, 'add_menu']);
        add_action('admin_init', [$this->admin, 'admin_init']);
        
        // Cron
        add_filter('cron_schedules', [$this->cron, 'register_schedules']);
        add_action('init', [$this, 'handle_cron_scheduling']);
        add_action('lhdn_sync_submitted_invoices', [$this->cron, 'sync_submitted_invoices']);
        add_action('lhdn_retry_err_invoices', [$this->cron, 'retry_err_invoices']);
        add_action('lhdn_process_queued_invoices', [$this->cron, 'process_queued_invoices']);
        
        // WooCommerce
        add_action('woocommerce_order_status_completed', [$this->woocommerce, 'submit_from_wc_order'], 10, 1);
        add_action('woocommerce_order_status_processing', [$this->woocommerce, 'submit_from_wc_order_processing'], 10, 1);
        
        // Register hooks for custom order statuses (treated like "completed")
        $custom_statuses = LHDN_Settings::get('custom_order_statuses', '');
        if (!empty($custom_statuses)) {
            $statuses = array_filter(array_map('trim', explode(',', $custom_statuses)));
            foreach ($statuses as $status) {
                $status = sanitize_key($status); // Clean the status slug
                if (!empty($status)) {
                    add_action('woocommerce_order_status_' . $status, [$this->woocommerce, 'submit_from_wc_order'], 10, 1);
                }
            }
        }
        add_filter('manage_woocommerce_page_wc-orders_columns', [$this->woocommerce, 'add_order_column'], 20);
        add_action('manage_woocommerce_page_wc-orders_custom_column', [$this->woocommerce, 'orders_list_column_content'], 20, 2);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
        
        // My Account orders
        add_filter('woocommerce_my_account_my_orders_columns', [$this->woocommerce, 'add_my_account_order_column'], 20);
        add_action('woocommerce_my_account_my_orders_column_lhdn-receipt', [$this->woocommerce, 'my_account_order_column_content'], 20, 1);
        
        // User Profile
        add_action('user_profile_update_errors', [$this, 'handle_profile_errors']);
        add_action('show_user_profile', [$this->user_profile, 'add_profile_fields']);
        add_action('edit_user_profile', [$this->user_profile, 'add_profile_fields']);
        add_action('personal_options_update', [$this->user_profile, 'validate_user_tin_on_save'], 20);
        add_action('edit_user_profile_update', [$this->user_profile, 'validate_user_tin_on_save'], 20);
        add_action('woocommerce_edit_account_form', [$this->user_profile, 'add_myaccount_fields']);
        add_action('woocommerce_save_account_details', [$this->user_profile, 'save_myaccount_fields'], 20);
        add_action('woocommerce_before_checkout_form', [$this->user_profile, 'show_tin_status_badge']);
        
        // AJAX
        add_action('wp_ajax_lhdn_get_logs', [$this, 'ajax_get_logs']);
        add_action('wp_ajax_lhdn_submit_order', [$this, 'ajax_submit_order']);
    }

    /**
     * Handle profile errors
     */
    public function handle_profile_errors($errors) {
        if (empty($GLOBALS['lhdn_profile_error'])) {
            return;
        }

        if ($GLOBALS['lhdn_profile_error'] === 'partial') {
            $errors->add(
                'lhdn_partial',
                __('Please fill TIN, ID Type and ID Value together or leave all empty.', 'myinvoice-sync')
            );
        }

        if ($GLOBALS['lhdn_profile_error'] === 'invalid') {
            $errors->add(
                'lhdn_invalid_tin',
                __('LHDN TIN validation failed. Please verify your details.', 'myinvoice-sync')
            );
        }

        unset($GLOBALS['lhdn_profile_error']);
    }

    /**
     * AJAX get logs
     */
    public function ajax_get_logs() {
        wp_send_json(get_option('lhdn_logs', []));
    }

    /**
     * Enqueue admin scripts
     */
    public function enqueue_admin_scripts($hook) {
        // Only load on WooCommerce orders page
        if ($hook !== 'woocommerce_page_wc-orders' && $hook !== 'edit.php') {
            return;
        }

        // Ensure jQuery is loaded
        wp_enqueue_script('jquery');
        
        wp_add_inline_script('jquery', '
            jQuery(document).ready(function($) {
                $(document).on("click", ".lhdn-process-order-btn", function(e) {
                    e.preventDefault();
                    var $btn = $(this);
                    var $status = $btn.siblings(".lhdn-submit-status");
                    var orderId = $btn.data("order-id");
                    var nonce = $btn.data("nonce");
                    
                    $btn.prop("disabled", true).text("Waiting...");
                    $status.html("");
                    
                    $.ajax({
                        url: ajaxurl,
                        type: "POST",
                        data: {
                            action: "lhdn_submit_order",
                            order_id: orderId,
                            nonce: nonce
                        },
                        success: function(response) {
                            if (response.success) {
                                $status.html("<br /><span style=\"color: green;\">✓ Done</span>");
                                setTimeout(function() {
                                    location.reload();
                                }, 1000);
                            } else {
                                $btn.prop("disabled", false).text("Process");
                                $status.html("<span style=\"color: red;\">Error: " + (response.data?.message || "Processing failed") + "</span>");
                            }
                        },
                        error: function() {
                            $btn.prop("disabled", false).text("Process");
                            $status.html("<span style=\"color: red;\">Error: Request failed</span>");
                        }
                    });
                });
            });
        ');
    }

    /**
     * AJAX submit order
     */
    public function ajax_submit_order() {
        $order_id = isset($_POST['order_id']) ? intval(wp_unslash($_POST['order_id'])) : 0;
        
        if (!$order_id) {
            wp_send_json_error(['message' => 'Invalid order ID']);
            return;
        }

        check_ajax_referer('lhdn_submit_order_' . $order_id, 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
            return;
        }

        if (!LHDN_Settings::is_plugin_active()) {
            wp_send_json_error(['message' => 'Plugin is currently inactive. Please activate the plugin in settings.']);
            return;
        }

        $this->woocommerce->submit_from_wc_order($order_id);
        
        wp_send_json_success(['message' => 'Order submitted successfully']);
    }

    /**
     * Check database structure (for existing installations)
     */
    public static function check_database_structure() {
        // Only run once per day to avoid performance issues
        $last_check = get_transient('lhdn_db_structure_check');
        if ($last_check) {
            return;
        }
        
        LHDN_Database::check_and_update_table_structure();
        
        // Set transient to run once per day
        set_transient('lhdn_db_structure_check', true, DAY_IN_SECONDS);
    }

    /**
     * Activation hook
     */
    public function activate() {
        LHDN_Database::create_tables();
        LHDN_Settings::init_defaults();
        
        // Plugin is inactive by default, so don't schedule cron events
        // Cron will be scheduled when user activates plugin via settings page
        
        delete_option('lhdn_logs');
        delete_transient('lhdn_db_structure_check'); // Force check on next admin load
    }

    /**
     * Deactivation hook
     */
    public function deactivate() {
        $cron = new LHDN_Cron();
        $cron->clear_events();
    }
}

