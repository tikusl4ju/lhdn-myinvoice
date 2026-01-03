<?php
/**
 * LHDN WooCommerce Integration
 */

if (!defined('ABSPATH')) exit;

class LHDN_WooCommerce {
    
    private $invoice;
    
    public function __construct() {
        $this->invoice = new LHDN_Invoice();
    }

    /**
     * Check if order uses wallet payment method
     */
    private function is_wallet_payment($order) {
        $payment_method = strtolower($order->get_payment_method());
        // Check if payment method contains 'wallet'
        return strpos($payment_method, 'wallet') !== false;
    }

    /**
     * Submit order when completed
     */
    public function submit_from_wc_order($order_id) {
        if (!LHDN_Settings::is_plugin_active()) {
            LHDN_Logger::log("WC Order {$order_id} submission skipped (plugin inactive)");
            return;
        }
        
        if (!class_exists('WC_Order')) return;

        $order = wc_get_order($order_id);
        if (!$order) return;

        // Skip wallet payment orders if setting is enabled
        if (LHDN_Settings::get('exclude_wallet', '1') === '1' && $this->is_wallet_payment($order)) {
            LHDN_Logger::log("WC Order {$order_id} submission skipped (wallet payment method)");
            return;
        }

        // Check if already submitted by checking database status
        global $wpdb;
        $invoice_no = $order->get_order_number();
        $existing_invoice = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT status FROM {$wpdb->prefix}lhdn_myinvoice 
                 WHERE invoice_no = %s 
                 AND status IN ('submitted', 'valid', 'cancelled') 
                 LIMIT 1",
                $invoice_no
            )
        );
        
        if ($existing_invoice) {
            LHDN_Logger::log("WC Order {$order_id} already submitted to LHDN (status: {$existing_invoice->status})");
            return;
        }

        $billing_circle = LHDN_Settings::get('billing_circle', 'on_completed');

        // Handle "After X Days" - set queue status in database
        if (strpos($billing_circle, 'after_') === 0) {
            $days = (int) str_replace('after_', '', str_replace('_days', '', $billing_circle));
            if ($days > 0 && $days <= 7) {
                $dmy = wp_date('dmy'); // Day Month Year
                $queue_status = 'q' . $days . $dmy;
                $queue_date = current_time('mysql');
                
                // Check if invoice record already exists
                $existing = $wpdb->get_row(
                    $wpdb->prepare(
                        "SELECT id FROM {$wpdb->prefix}lhdn_myinvoice WHERE invoice_no = %s LIMIT 1",
                        $invoice_no
                    )
                );
                
                if ($existing) {
                    // Update existing record
                    $wpdb->update(
                        "{$wpdb->prefix}lhdn_myinvoice",
                        [
                            'order_id' => $order_id,
                            'queue_status' => $queue_status,
                            'queue_date' => $queue_date,
                            'status' => 'queued',
                            'updated_at' => current_time('mysql'),
                        ],
                        ['invoice_no' => $invoice_no]
                    );
                } else {
                    // Create new queued record
                    LHDN_Database::save_invoice([
                        'invoice_no' => $invoice_no,
                        'order_id' => $order_id,
                        'status' => 'queued',
                        'queue_status' => $queue_status,
                        'queue_date' => $queue_date,
                    ]);
                }
                
                LHDN_Logger::log("WC Order {$order_id} queued for submission after {$days} day(s) with status: {$queue_status}");
                return;
            }
        }

        // Handle "On Completed Order" - submit immediately
        if ($billing_circle === 'on_completed') {
            LHDN_Logger::log("WC Order completed > Submitting to LHDN ({$order_id})");

            $this->invoice->submit_wc_order($order);
        }
    }

    /**
     * Submit order when processing
     */
    public function submit_from_wc_order_processing($order_id) {
        if (!LHDN_Settings::is_plugin_active()) {
            LHDN_Logger::log("WC Order {$order_id} submission skipped (plugin inactive)");
            return;
        }
        
        if (!class_exists('WC_Order')) return;

        $order = wc_get_order($order_id);
        if (!$order) return;

        // Skip wallet payment orders if setting is enabled
        if (LHDN_Settings::get('exclude_wallet', '1') === '1' && $this->is_wallet_payment($order)) {
            LHDN_Logger::log("WC Order {$order_id} submission skipped (wallet payment method)");
            return;
        }

        // Check if already submitted by checking database status
        global $wpdb;
        $invoice_no = $order->get_order_number();
        $existing_invoice = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT status FROM {$wpdb->prefix}lhdn_myinvoice 
                 WHERE invoice_no = %s 
                 AND status IN ('submitted', 'valid', 'cancelled') 
                 LIMIT 1",
                $invoice_no
            )
        );
        
        if ($existing_invoice) {
            LHDN_Logger::log("WC Order {$order_id} already submitted to LHDN (status: {$existing_invoice->status})");
            return;
        }

        $billing_circle = LHDN_Settings::get('billing_circle', 'on_completed');

        // Only submit if billing circle is "On Processed Order"
        if ($billing_circle !== 'on_processed') {
            return;
        }

        LHDN_Logger::log("WC Order processing > Submitting to LHDN ({$order_id})");

        $this->invoice->submit_wc_order($order);
    }

    /**
     * Add LHDN column to orders list
     */
    public function add_order_column($columns) {
        $new = [];

        foreach ($columns as $key => $label) {
            $new[$key] = $label;

            if ($key === 'order_status') {
                $new['lhdn'] = __('LHDN MyInvois', 'myinvoice-sync');
            }
        }

        return $new;
    }

    /**
     * Display LHDN column content
     */
    public function orders_list_column_content($column, $order) {
        if ($column !== 'lhdn') {
            return;
        }

        global $wpdb;

        $invoice_no = $order->get_order_number();

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT uuid, longid, status, item_class, queue_status, queue_date
                 FROM {$wpdb->prefix}lhdn_myinvoice
                 WHERE invoice_no = %s
                 LIMIT 1",
                $invoice_no
            )
        );

        if (!$row) {
            $order_status = $order->get_status();
            
            // Get custom order statuses from settings
            $custom_statuses = LHDN_Settings::get('custom_order_statuses', '');
            $allowed_statuses = ['completed', 'processing'];
            if (!empty($custom_statuses)) {
                $custom = array_filter(array_map('trim', explode(',', strtolower($custom_statuses))));
                $allowed_statuses = array_merge($allowed_statuses, $custom);
            }
            
            // Check if wallet payment and exclude_wallet setting is enabled
            $is_wallet = LHDN_Settings::get('exclude_wallet', '1') === '1' && $this->is_wallet_payment($order);
            
            if ($is_wallet) {
                // Show "Wallet Order" status instead of Process button
                echo '<small><em>Wallet Order</em></small>';
            } elseif (in_array($order_status, $allowed_statuses)) {
                // Only show Process button for allowed order statuses (non-wallet)
                $order_id = $order->get_id();
                $nonce = wp_create_nonce('lhdn_submit_order_' . $order_id);
                echo '<small><em>Not Submitted</em></small><br>';
                echo '<button type="button" class="button button-small lhdn-process-order-btn" ';
                echo 'data-order-id="' . esc_attr($order_id) . '" ';
                echo 'data-nonce="' . esc_attr($nonce) . '">';
                echo 'Process</button>';
                echo '<span class="lhdn-submit-status" style="margin-left: 5px;"></span>';
            } else {
                echo '<small><em>Not Submitted</em></small>';
            }
            return;
        }

        if($row->item_class === '004'){
          $item_class = "Consolidated";
        }else if($row->item_class === '008'){
          $item_class = "e-Commerce";
        }else{
          $item_class = $row->item_class;
        }

        if ($row->uuid && $row->longid) {
            $url = LHDN_HOST . '/' . $row->uuid . '/share/' . $row->longid;
            if ($row->status === 'cancelled') {
                $document_status = 'Cancelled';
            } elseif ($row->status === 'submitted') {
                $document_status = 'Processing';
            } elseif ($row->status === 'valid') {
                $document_status = 'Submitted';
            } else {
                // For other statuses, show the original status or a generic message
                $document_status = ucfirst($row->status);
            }
            printf(
                '<a href="%s" target="_blank">' . esc_html($document_status) . '</a> ' . '(' . esc_html($item_class) . ')',
                esc_url($url),
                esc_html($row->longid)
            );
        } elseif ($row->uuid) {
            echo '<small><em>Processing ' . '(' . esc_html($item_class) . ')</em></small>';
        } elseif ($row->status === 'retry') {
            echo '<small><em>Retrying ' . '(' . esc_html($item_class) . ')</em></small>';
        } elseif ($row->status == "queued") {
            // Check if wallet payment and exclude_wallet setting is enabled
            $is_wallet = LHDN_Settings::get('exclude_wallet', '1') === '1' && $this->is_wallet_payment($order);
            
            if ($is_wallet) {
                // Show "Wallet Order" status instead of Process button
                echo '<small><em>Wallet Order</em></small>';
            } else {
                $day = preg_match('/^q(\d+)(\d{6})$/', $row->queue_status, $matches) ? $matches[1] : '';
                $remaining_days = '';
                if ($day && $row->queue_date) {
                    $queue_date = strtotime($row->queue_date);
                    $target_date = strtotime('+' . $day . ' days', $queue_date);
                    $current_date = current_time('timestamp');
                    $remaining = ceil(($target_date - $current_date) / DAY_IN_SECONDS);
                    $remaining_days = max(0, $remaining); // Ensure non-negative
                }
                if ($remaining_days !== '') {
                    $days_text = ($remaining_days == 1) ? 'day' : 'days';
                    echo '<small><em>Queueing (' . esc_html($remaining_days) . ' ' . esc_html($days_text) . ')</em></small>';
                } else {
                    echo '<small><em>Queueing</em></small>';
                }
                
                $order_id = $order->get_id();
                $nonce = wp_create_nonce('lhdn_submit_order_' . $order_id);
                echo '<button type="button" class="button button-small lhdn-process-order-btn" ';
                echo 'data-order-id="' . esc_attr($order_id) . '" ';
                echo 'data-nonce="' . esc_attr($nonce) . '">';
                echo 'Process</button>';
                echo '<span class="lhdn-submit-status" style="margin-left: 5px;"></span>';
            }
        } else {
            echo '<small><em>Failed ' . '(' . esc_html($item_class) . ')</em></small>';
        }
    }

    /**
     * Add LHDN Receipt column to My Account orders table
     */
    public function add_my_account_order_column($columns) {
        // Check if Show Receipt setting is enabled
        if (LHDN_Settings::get('show_receipt', '1') !== '1') {
            return $columns;
        }

        $new_columns = [];
        foreach ($columns as $key => $column) {
            $new_columns[$key] = $column;
            // Add LHDN Receipt column after order-status
            if ($key === 'order-status') {
                $new_columns['lhdn-receipt'] = __('MyInvois Receipt', 'myinvoice-sync');
            }
        }
        return $new_columns;
    }

    /**
     * Display LHDN Receipt column content in My Account orders table
     */
    public function my_account_order_column_content($order) {
        // Check if Show Receipt setting is enabled
        if (LHDN_Settings::get('show_receipt', '1') !== '1') {
            return;
        }

        if (!defined('LHDN_HOST')) {
            return;
        }

        global $wpdb;

        $invoice_no = $order->get_order_number();

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT uuid, longid, status
                 FROM {$wpdb->prefix}lhdn_myinvoice
                 WHERE invoice_no = %s
                 LIMIT 1",
                $invoice_no
            )
        );

        if ($row && $row->uuid && $row->longid) {
            $url = LHDN_HOST . '/' . $row->uuid . '/share/' . $row->longid;
            printf(
                '<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
                esc_url($url),
                esc_html__('View Receipt', 'myinvoice-sync')
            );
        } else {
            echo '<span style="color: #999;">—</span>';
        }
    }
}

