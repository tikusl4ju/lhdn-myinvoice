<?php
/**
 * LHDN Cron Jobs
 */

if (!defined('ABSPATH')) exit;

class LHDN_Cron {
    
    private $invoice;
    
    public function __construct() {
        $this->invoice = new LHDN_Invoice();
    }

    /**
     * Register cron schedules
     */
    public function register_schedules($schedules) {
        $schedules['cron_myinvoice'] = [
            'interval' => 600,
            'display'  => 'Every 10 Minutes',
        ];
        return $schedules;
    }

    /**
     * Schedule cron events
     */
    public function schedule_events() {
        // Only schedule if plugin is active
        if (!LHDN_Settings::is_plugin_active()) {
            return;
        }
        
        if (!wp_next_scheduled('lhdn_sync_submitted_invoices')) {
            wp_schedule_event(
                time() + 600,
                'cron_myinvoice',
                'lhdn_sync_submitted_invoices'
            );
        }

        if (!wp_next_scheduled('lhdn_retry_err_invoices')) {
            wp_schedule_event(
                time() + 600,
                'cron_myinvoice',
                'lhdn_retry_err_invoices'
            );
        }

        if (!wp_next_scheduled('lhdn_process_queued_invoices')) {
            wp_schedule_event(
                time() + 600,
                'cron_myinvoice',
                'lhdn_process_queued_invoices'
            );
        }
    }

    /**
     * Clear scheduled events
     */
    public function clear_events() {
        wp_clear_scheduled_hook('lhdn_sync_submitted_invoices');
        wp_clear_scheduled_hook('lhdn_retry_err_invoices');
        wp_clear_scheduled_hook('lhdn_process_queued_invoices');
    }

    /**
     * Sync submitted invoices
     */
    public function sync_submitted_invoices() {
        if (!LHDN_Settings::is_plugin_active()) {
            LHDN_Logger::log('Cron skipped (plugin inactive)');
            return;
        }
        
        if (get_transient(__FUNCTION__ . '_lock')) {
            LHDN_Logger::log('Cron skipped (lock)');
            return;
        }
        set_transient(__FUNCTION__ . '_lock', 1, 8 * MINUTE_IN_SECONDS);

        global $wpdb;

        $rows = $wpdb->get_results(
            "SELECT uuid FROM {$wpdb->prefix}lhdn_myinvoice
             WHERE status = 'submitted'
               AND code = '202'
               AND uuid IS NOT NULL
             ORDER BY updated_at ASC
             LIMIT 5"
        );

        if (!$rows) {
            delete_transient(__FUNCTION__ . '_lock');
            return;
        }

        // Force fresh token only if there are rows to process
        $this->invoice->force_refresh_token_for_cron();
        LHDN_Logger::log('Cron: Sync submitted invoices started');

        foreach ($rows as $row) {
            sleep(1);
            LHDN_Logger::log("Cron: Syncing UUID {$row->uuid}");
            $this->invoice->sync_status($row->uuid);
        }

        LHDN_Logger::log('Cron: Sync submitted invoices finished');
        update_option('lhdn_last_sync_cron_run', time());

        $next = wp_next_scheduled('lhdn_sync_submitted_invoices');
        LHDN_Logger::log(
            'Cron finished | next scheduled=' .
            ($next ? wp_date('Y-m-d H:i:s', $next) : 'none')
        );

        delete_transient(__FUNCTION__ . '_lock');
    }

    /**
     * Retry err invoices
     */
    public function retry_err_invoices() {
        if (!LHDN_Settings::is_plugin_active()) {
            LHDN_Logger::log('Cron skipped (plugin inactive)');
            return;
        }
        
        if (get_transient(__FUNCTION__ . '_lock')) {
            LHDN_Logger::log('Cron skipped (lock)');
            return;
        }
        set_transient(__FUNCTION__ . '_lock', 1, 8 * MINUTE_IN_SECONDS);

        global $wpdb;

        $rows = $wpdb->get_results(
            "SELECT invoice_no, retry_count
             FROM {$wpdb->prefix}lhdn_myinvoice
             WHERE status = 'retry'
               AND retry_count < 3
             ORDER BY updated_at ASC
             LIMIT 5"
        );

        if (!$rows) {
            delete_transient(__FUNCTION__ . '_lock');
            return;
        }

        // Force fresh token only if there are rows to process
        $this->invoice->force_refresh_token_for_cron();
        LHDN_Logger::log('Cron: Retry status invoices started');

        foreach ($rows as $row) {
            $invoiceNo  = $row->invoice_no;
            $retryCount = (int) $row->retry_count;

            $wpdb->update(
                "{$wpdb->prefix}lhdn_myinvoice",
                [
                    'retry_count' => $retryCount + 1,
                    'updated_at'  => current_time('mysql')
                ],
                ['invoice_no' => $invoiceNo]
            );

            sleep(min(8, pow(2, $retryCount)));

            if (str_starts_with($invoiceNo, 'TEST-')) {
                LHDN_Logger::log("Retry #".($retryCount+1)." TEST invoice {$invoiceNo}");
                $this->invoice->resubmit_test($invoiceNo);
            } else {
                LHDN_Logger::log("Retry #".($retryCount+1)." WC invoice {$invoiceNo}");
                $this->invoice->resubmit_wc_order($invoiceNo);
            }
        }

        $now = current_time('mysql');
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$wpdb->prefix}lhdn_myinvoice
                 SET status = 'failed',
                     updated_at = %s
                 WHERE status = 'retry'
                   AND retry_count >= 3",
                $now
            )
        );

        LHDN_Logger::log('Cron: Retry failed invoices finished');
        update_option('lhdn_last_retry_cron_run', time());

        $next = wp_next_scheduled('lhdn_retry_err_invoices');
        LHDN_Logger::log(
            'Cron fired | next=' . ($next ? wp_date('Y-m-d H:i:s', $next) : 'none')
        );

        delete_transient(__FUNCTION__ . '_lock');
    }

    /**
     * Process queued invoices (After X Days)
     */
    public function process_queued_invoices() {
        if (!LHDN_Settings::is_plugin_active()) {
            LHDN_Logger::log('Cron skipped (plugin inactive)');
            return;
        }
        
        if (get_transient(__FUNCTION__ . '_lock')) {
            LHDN_Logger::log('Cron skipped (lock)');
            return;
        }
        set_transient(__FUNCTION__ . '_lock', 1, 8 * MINUTE_IN_SECONDS);

        global $wpdb;

        // Get all invoices with queue status from database
        $rows = $wpdb->get_results(
            "SELECT invoice_no, queue_status, queue_date
             FROM {$wpdb->prefix}lhdn_myinvoice
             WHERE queue_status IS NOT NULL
               AND queue_status != ''
               AND status = 'queued'
             ORDER BY queue_date ASC
             LIMIT 20"
        );

        if (empty($rows)) {
            delete_transient(__FUNCTION__ . '_lock');
            return;
        }

        $submitted_count = 0;
        foreach ($rows as $row) {
            $invoice_no = $row->invoice_no;
            $queue_status = $row->queue_status;
            $queue_date = $row->queue_date;

            if (!$queue_status || !$queue_date) {
                continue;
            }

            // Extract days from queue status (format: q1ddmmyy, q2ddmmyy, etc.)
            // Format: q + days + ddmmyy (6 digits for date)
            if (preg_match('/^q(\d+)(\d{6})$/', $queue_status, $matches)) {
                $days = (int) $matches[1];

                // Calculate target date using WordPress timezone
                $queue_timestamp = strtotime($queue_date);
                $target_timestamp = $queue_timestamp + ($days * DAY_IN_SECONDS);
                $current_timestamp = current_time('timestamp');

                // Check if it's time to submit
                if ($current_timestamp >= $target_timestamp) {
                    
                    if($submitted_count === 0){
                        // Force fresh token only for the first invoice being processed
                        $this->invoice->force_refresh_token_for_cron();
                    }
                    
                    LHDN_Logger::log("Processing delayed invoice: {$invoice_no}, queue_status: {$queue_status}, days: {$days}, target: " . wp_date('Y-m-d H:i:s', $target_timestamp) . ", current: " . wp_date('Y-m-d H:i:s', $current_timestamp));
                    // Get the WooCommerce order
                    // Try to find order by ID first (if invoice_no is numeric)
                    $order = null;
                    if (is_numeric($invoice_no)) {
                        $order = wc_get_order((int) $invoice_no);
                    }
                    
                    // If not found, search by order number
                    if (!$order) {
                        $orders = wc_get_orders([
                            'limit' => 100,
                            'orderby' => 'date',
                            'order' => 'DESC',
                        ]);
                        foreach ($orders as $test_order) {
                            if ($test_order->get_order_number() === $invoice_no) {
                                $order = $test_order;
                                break;
                            }
                        }
                    }
                    
                    if (!$order) {
                        LHDN_Logger::log("Order not found for invoice {$invoice_no}, skipping");
                        continue;
                    }

                    // Skip wallet payment orders if setting is enabled
                    if (LHDN_Settings::get('exclude_wallet', '1') === '1') {
                        $payment_method = strtolower($order->get_payment_method());
                        if (strpos($payment_method, 'wallet') !== false) {
                            LHDN_Logger::log("Invoice {$invoice_no} submission skipped (wallet payment method)");
                            // Clean up queue status from database
                            $wpdb->update(
                                "{$wpdb->prefix}lhdn_myinvoice",
                                [
                                    'queue_status' => null,
                                    'queue_date' => null,
                                    'updated_at' => current_time('mysql'),
                                ],
                                ['invoice_no' => $invoice_no]
                            );
                            continue;
                        }
                    }

                    // Check if already submitted by checking database status
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
                        // Already submitted - clean up queue status from database
                        $wpdb->update(
                            "{$wpdb->prefix}lhdn_myinvoice",
                            [
                                'queue_status' => null,
                                'queue_date' => null,
                                'updated_at' => current_time('mysql'),
                            ],
                            ['invoice_no' => $invoice_no]
                        );
                        LHDN_Logger::log("Invoice {$invoice_no} already submitted (status: {$existing_invoice->status}), skipping");
                        continue;
                    }

                    LHDN_Logger::log("Processing delayed invoice for order #{$order->get_id()} (invoice {$invoice_no}, queued for {$days} day(s))");

                    // Submit the invoice
                    $this->invoice->submit_wc_order($order);

                    // Clean up queue status from database
                    $wpdb->update(
                        "{$wpdb->prefix}lhdn_myinvoice",
                        [
                            'queue_status' => null,
                            'queue_date' => null,
                            'updated_at' => current_time('mysql'),
                        ],
                        ['invoice_no' => $invoice_no]
                    );

                    $submitted_count++;
                    sleep(1); // Prevent overload
                } else {
                    //LHDN_Logger::log("Delayed invoice not ready: {$invoice_no}, queue_status: {$queue_status}, days: {$days}, target: " . wp_date('Y-m-d H:i:s', $target_timestamp) . ", current: " . wp_date('Y-m-d H:i:s', $current_timestamp));
                }
            } else {
                LHDN_Logger::log("Invalid queue_status format: {$invoice_no}, queue_status: {$queue_status}");
            }
        }
        if($submitted_count != 0){
            LHDN_Logger::log("Cron: Processed {$submitted_count} delayed invoice(s)");
        }
        update_option('lhdn_last_delayed_cron_run', time());

        delete_transient(__FUNCTION__ . '_lock');
    }
}

