<?php
/**
 * LHDN Invoice Handler
 */

if (!defined('ABSPATH')) exit;

class LHDN_Invoice {
    
    private $api;
    private $db;
    
    public function __construct() {
        $this->api = new LHDN_API();
        $this->db = new LHDN_Database();
    }

    /**
     * Force refresh token for cron jobs
     * This ensures a fresh token is obtained at the start of each cron run
     */
    public function force_refresh_token_for_cron() {
        return $this->api->get_token(true);
    }

    /**
     * Clean and validate phone number for LHDN submission
     * Requirements: 8-20 characters, digits only (optional + at front), no spaces
     * 
     * @param string $phone Raw phone number
     * @return string Cleaned phone number or empty string if invalid
     */
    private function clean_phone_number($phone) {
        if (empty($phone)) {
            return '';
        }
        
        // Remove all spaces, dashes, parentheses, and other non-digit characters except +
        $cleaned = preg_replace('/[^\d+]/', '', trim($phone));
        
        // Remove + if it's not at the beginning
        if (strpos($cleaned, '+') !== false && strpos($cleaned, '+') !== 0) {
            $cleaned = str_replace('+', '', $cleaned);
        }
        
        // Ensure + is only at the beginning if present
        if (strpos($cleaned, '+') !== false && strpos($cleaned, '+') !== 0) {
            $cleaned = '+' . str_replace('+', '', $cleaned);
        }
        
        // Validate length (8-20 characters)
        $length = mb_strlen($cleaned);
        if ($length < 8 || $length > 20) {
            return '';
        }
        
        // Validate format: optional + at front, then only digits
        if (!preg_match('/^\+?\d+$/', $cleaned)) {
            return '';
        }
        
        return $cleaned;
    }

    /**
     * Submit invoice
     */
    public function submit(array $args) {
        $invoiceNo = $args['invoice_no'];
        $itemClassificationCode = $args['item_classification_code'];
        $buyer     = $args['buyer'];
        $lines     = $args['lines'];
        $total     = $args['total'];
        $tax_amount = $args['tax_amount'];
        $seller_address = $args['seller_address'];
        $order_id = isset($args['order_id']) ? $args['order_id'] : null;

        // Get tax category ID and industry classification code from settings
        $taxCategoryId = LHDN_Settings::get('tax_category_id', 'E');
        $industryClassificationCode = LHDN_Settings::get('industry_classification_code', '86909');

        // Build UBL
        $ubl = lhdn_invoice_doc_ubl($invoiceNo, [
            'item_classification_code' => $itemClassificationCode,
            'tax_category_id' => $taxCategoryId,
            'industry_classification_code' => $industryClassificationCode,
            'buyer_tin'      => $buyer['tin'],
            'buyer_id_type'  => $buyer['id_type'],
            'buyer_id_value' => $buyer['id_value'],
            'buyer_name'     => $buyer['name'],
            'buyer_phone'    => $buyer['phone'],
            'buyer_email'    => $buyer['email'],
            'buyer_address'  => $buyer['address'],
            'seller_address' => $seller_address,
            'lines'          => $lines,
            'total'          => round($total, 2),
            'tax_amount'     => round($tax_amount, 2),
        ]);

        // Get UBL version from settings (allows dynamic switching)
        $ubl_version = LHDN_MyInvoice_Plugin::get_ubl_version();
        
        if ($ubl_version === '1.1') {
            // Get PEM from database only (no file fallback)
            $pem_content = LHDN_MyInvoice_Plugin::get_pem_from_database();
            if (!empty($pem_content)) {
                $ubl = lhdn_sign_invoice_v1_1_with_pem($ubl);
            } else {
                // No certificate found - this shouldn't happen if validation works, but log error
                LHDN_Logger::log('Error: UBL 1.1 selected but no PEM certificate found in database. Invoice will be unsigned.');
                // Force to unsigned format
                unset($ubl['Invoice'][0]['Signature']);
                unset($ubl['Invoice'][0]['UBLExtensions']);
                // Also force version back to 1.0 in settings to prevent future issues
                LHDN_Settings::set('ubl_version', '1.0');
            }
        } else {
            unset($ubl['Invoice'][0]['Signature']);
            unset($ubl['Invoice'][0]['UBLExtensions']);
        }

        $json = LHDN_Helpers::canonical_json($ubl);
        $hash = hash('sha256', $json);
        $b64  = base64_encode($json);

        LHDN_Logger::log("Submitting invoice {$invoiceNo}");

        // Preserve existing retry_count if this is a retry attempt
        global $wpdb;
        $existing_invoice = $wpdb->get_row($wpdb->prepare(
            "SELECT retry_count FROM {$wpdb->prefix}lhdn_myinvoice WHERE invoice_no = %s LIMIT 1",
            $invoiceNo
        ));
        $preserved_retry_count = $existing_invoice ? (int) $existing_invoice->retry_count : 0;

        $save_data = [
            'invoice_no' => $invoiceNo,
            'item_class' => $itemClassificationCode,
            'document_hash' => $hash,
            'payload' => $json,
            'status' => 'processing',
            'retry_count' => $preserved_retry_count,
        ];
        
        if ($order_id !== null) {
            $save_data['order_id'] = $order_id;
        }
        
        $this->db::save_invoice($save_data);

        $payload = [
            "documents" => [[
                "format"       => "JSON",
                "documentHash" => $hash,
                "codeNumber"   => $invoiceNo,
                "document"     => $b64
            ]]
        ];

        $result = $this->api->submit_invoice($payload);
        
        if (!$result) {
            return false;
        }

        $data = $result['data'];
        $uuid = $data['acceptedDocuments'][0]['uuid'] ?? null;

        if ($uuid) {
            LHDN_Logger::log("UUID recorded: {$uuid}");

            $this->db::update_invoice($invoiceNo, [
                'uuid'        => $uuid,
                'response'    => $result['body'],
                'status'      => 'submitted',
                'code'        => $result['code'],
                'retry_count' => 0,
            ]);

            return $uuid;
        } else {
            $this->db::update_invoice($invoiceNo, [
                'uuid'       => $uuid,
                'response'   => $result['body'],
                'status'     => 'retry',
                'code'       => $result['code'],
            ]);

            LHDN_Logger::log("UUID missing - submission failed");
            return false;
        }
    }

    /**
     * Sync document status
     */
    public function sync_status($uuid) {
        $result = $this->api->get_document_status($uuid);
        
        if (!$result) {
            return;
        }

        $data = $result['data'];
        $status = strtolower($data['status'] ?? '');
        $allowed = ['valid','invalid','submitted','cancelled'];
        $status  = in_array($status, $allowed, true) ? $status : 'unknown';

        if (!$status) {
            LHDN_Logger::log("Status missing in response");
            return;
        }

        $longid = $data['longId'] ?? '';

        // Get invoice record to find invoice_no and check if order_id needs updating
        global $wpdb;
        $invoice_record = $wpdb->get_row($wpdb->prepare(
            "SELECT invoice_no, order_id FROM {$wpdb->prefix}lhdn_myinvoice WHERE uuid = %s LIMIT 1",
            $uuid
        ));

        $update_data = [
            'status'     => strtolower($status),
            'code'       => $result['code'],
            'response'   => $result['body'],
            'longid'     => $longid,
        ];

        // Update order_id if missing and invoice is not a test invoice
        if ($invoice_record && !str_starts_with($invoice_record->invoice_no, 'TEST-')) {
            // If order_id is missing or 0, try to find it from invoice_no
            if (empty($invoice_record->order_id) || $invoice_record->order_id == 0) {
                if (class_exists('WC_Order')) {
                    // Try to get order by invoice_no (order number)
                    $orders = wc_get_orders([
                        'limit' => 1,
                        'orderby' => 'date',
                        'order' => 'DESC',
                        'meta_query' => [
                            [
                                'key' => '_order_number',
                                'value' => $invoice_record->invoice_no,
                                'compare' => '='
                            ]
                        ]
                    ]);

                    if (empty($orders)) {
                        // Try searching by get_order_number() method
                        $orders = wc_get_orders([
                            'limit' => 100,
                            'orderby' => 'date',
                            'order' => 'DESC',
                        ]);
                        foreach ($orders as $test_order) {
                            if ($test_order->get_order_number() === $invoice_record->invoice_no) {
                                $update_data['order_id'] = $test_order->get_id();
                                break;
                            }
                        }
                    } else {
                        $update_data['order_id'] = $orders[0]->get_id();
                    }
                }
            }
        }

        $this->db::update_invoice_by_uuid($uuid, $update_data);

        LHDN_Logger::log("Status updated > {$status}");
    }

    /**
     * Cancel document
     */
    public function cancel($uuid) {
        LHDN_Logger::log("Attempting cancel for {$uuid}");

        $result = $this->api->cancel_document($uuid);
        
        if (!$result || !$result['success']) {
            LHDN_Logger::log("Cancel rejected by LHDN");
            return false;
        }

        $this->db::update_invoice_by_uuid($uuid, [
            'status'     => 'cancelled',
            'code'       => $result['code'],
            'response'   => $result['body'],
        ]);

        LHDN_Logger::log("Document marked as cancelled locally");
        return true;
    }

    /**
     * Submit WooCommerce order
     */
    public function submit_wc_order(WC_Order $order) {
        // Skip wallet payment orders if setting is enabled
        if (LHDN_Settings::get('exclude_wallet', '1') === '1') {
            $payment_method = strtolower($order->get_payment_method());
            if (strpos($payment_method, 'wallet') !== false) {
                LHDN_Logger::log("WC Order #{$order->get_id()} submission skipped (wallet payment method)");
                return false;
            }
        }

        $user_id = $order->get_user_id();

        $is_foreign = ($order->get_billing_country() !== 'MY');
        $tin     = $is_foreign ? 'EI00000000020' : 'EI00000000010';
        $id_type = $is_foreign ? 'PASSPORT' : 'NRIC';
        $id_value = 'NA';

        $item_classification_code = $is_foreign ? '008' : '004';

        if ($user_id) {
            $status = get_user_meta($user_id, 'lhdn_tin_validation', true);
            if($status === 'valid'){
                $tin      = get_user_meta($user_id, 'lhdn_tin', true) ?: $tin;
                $id_type  = get_user_meta($user_id, 'lhdn_id_type', true) ?: $id_type;
                $id_value = get_user_meta($user_id, 'lhdn_id_value', true) ?: $id_value;
                $item_classification_code = '008';
            }
        }

        $lines = [];
        $total = 0;
        $tax_amount = 0;
        $i = 1;

        foreach ($order->get_items() as $item) {
            $qty   = (float) $item->get_quantity();
            $price = (float) $item->get_total() / max($qty, 1);

            $lines[] = [
                'id'          => $i++,
                'qty'         => $qty,
                'unit_price'  => round($price, 2),
                'desc'        => $item->get_name(),
            ];

            $total += $item->get_total();
            $tax_amount += $item->get_total_tax();
        }

        foreach ($order->get_items('shipping') as $shipping) {
            $shippingTotal = (float) $shipping->get_total();

            if ($shippingTotal <= 0) {
                continue;
            }

            $lines[] = [
                'id'         => $i++,
                'qty'        => 1,
                'unit_price' => round($shippingTotal, 2),
                'desc'       => $shipping->get_name() ?: 'Shipping Fee',
            ];

            $total += $shippingTotal;
        }

        // Add order fees (e.g., COD fees, payment gateway fees)
        foreach ($order->get_fees() as $fee) {
            $feeTotal = (float) $fee->get_total();

            if ($feeTotal <= 0) {
                continue;
            }

            $lines[] = [
                'id'         => $i++,
                'qty'        => 1,
                'unit_price' => round($feeTotal, 2),
                'desc'       => $fee->get_name() ?: 'Fee',
            ];

            $total += $feeTotal;
            $tax_amount += $fee->get_total_tax();
        }

        // Get and clean phone number (billing first, fallback to shipping)
        $phone = $this->clean_phone_number($order->get_billing_phone());
        if (empty($phone)) {
            $phone = $this->clean_phone_number($order->get_shipping_phone());
        }
        // If still empty, use a default placeholder (LHDN requires phone number)
        if (empty($phone)) {
            $phone = '60123456789'; // Default Malaysian phone number format
            LHDN_Logger::log("WC Order #{$order->get_id()}: Phone number missing, using default");
        }

        // Get billing city, fallback to country if city is not available
        $billing_city = $order->get_billing_city();
        if (empty($billing_city)) {
            $billing_city = $order->get_billing_country();
        }

        return $this->submit([
            'invoice_no' => $order->get_order_number(),
            'order_id' => $order->get_id(),
            'item_classification_code' => $item_classification_code,
            'buyer' => [
                'tin'            => $tin,
                'id_type'        => $id_type,
                'id_value'       => $id_value,
                'name'           => $order->get_billing_company() ?: $order->get_formatted_billing_full_name(),
                'phone'          => $phone,
                'email'          => $order->get_billing_email(),
                'address'    => [
                        'city'       => $billing_city,
                        'postcode'   => $order->get_billing_postcode(),
                        'state_code' => LHDN_Helpers::wc_state_to_lhdn($order->get_billing_state()),
                        'line1'      => trim($order->get_billing_address_1() . ' ' . $order->get_billing_address_2()),
                        'country'    => LHDN_Helpers::country_iso2_to_iso3($order->get_billing_country()),
                ]
            ],
            'seller_address' => [
                'city'       => LHDN_SELLER_ADDRESS_CITY,
                'postcode'   => LHDN_SELLER_ADDRESS_POSTCODE,
                'state_code' => LHDN_Helpers::wc_state_to_lhdn(LHDN_SELLER_ADDRESS_STATE),
                'line1'      => LHDN_SELLER_ADDRESS_LINE1,
                'country'    => LHDN_SELLER_ADDRESS_COUNTRY,
            ],
            'lines' => $lines,
            'total' => $total,
            'tax_amount' => $tax_amount,
        ]);
    }

    /**
     * Submit test invoice
     */
    public function submit_test() {
        $invoiceNo = 'TEST-' . wp_date('Ymd-His') . '-' . strtoupper(bin2hex(random_bytes(3)));

        $lines = [
            ['id'=>1,'qty'=>1,'unit_price'=>1100,'desc'=>'Consultation Service'],
            ['id'=>2,'qty'=>2,'unit_price'=>1234.50,'desc'=>'Follow-up Service'],
        ];

        $total = array_reduce($lines, fn($t,$l) => $t + ($l['qty'] * $l['unit_price']), 0);
        $tax_amount = 0;

        return $this->submit([
            'invoice_no' => $invoiceNo,
            'order_id' => 0, // Dummy order_id for test invoices
            'item_classification_code' => '004',
            'buyer' => [
                'tin'            => 'EI00000000010',
                'id_type'        => 'NRIC',
                'id_value'       => 'NA',
                'name'           => 'Test Buyer Name',
                'phone'          => '60123456789',
                'email'          => 'test_buyer@example.com',
                'address'        => [
                      'city'       => 'Kuala Lumpur',
                      'postcode'   => '50480',
                      'state_code' => LHDN_Helpers::wc_state_to_lhdn('WP'),
                      'line1'      => 'Lot 77, Jalan Test',
                      'country'    => 'MYS',
                ]
            ],
            'seller_address' => [
                'city'       => LHDN_SELLER_ADDRESS_CITY,
                'postcode'   => LHDN_SELLER_ADDRESS_POSTCODE,
                'state_code' => LHDN_Helpers::wc_state_to_lhdn(LHDN_SELLER_ADDRESS_STATE),
                'line1'      => LHDN_SELLER_ADDRESS_LINE1,
                'country'    => LHDN_SELLER_ADDRESS_COUNTRY,
            ],
            'lines' => $lines,
            'total' => $total,
            'tax_amount' => $tax_amount,
        ]);
    }

    /**
     * Resubmit WooCommerce order
     */
    public function resubmit_wc_order($ordernum) {
        $order = null;
        
        LHDN_Logger::log("Resubmit: Searching for order with invoice number: {$ordernum}");
        
        // Get order_id from database (most reliable)
        global $wpdb;
        $invoice_record = $wpdb->get_row($wpdb->prepare(
            "SELECT order_id FROM {$wpdb->prefix}lhdn_myinvoice WHERE invoice_no = %s LIMIT 1",
            $ordernum
        ));
        
        if ($invoice_record && !empty($invoice_record->order_id) && $invoice_record->order_id > 0) {
            $order = wc_get_order((int) $invoice_record->order_id);
            if ($order) {
                LHDN_Logger::log("Resubmit: Found order by stored order_id: {$invoice_record->order_id} for invoice {$ordernum}");
            }
        }
        
        if (!$order) {
            LHDN_Logger::log("Resubmit failed: WC order {$ordernum} not found. Order ID not stored in database or invalid.");
            return false;
        }

        // Skip wallet payment orders if setting is enabled
        if (LHDN_Settings::get('exclude_wallet', '1') === '1') {
            $payment_method = strtolower($order->get_payment_method());
            if (strpos($payment_method, 'wallet') !== false) {
                LHDN_Logger::log("WC Order #{$order->get_id()} resubmission skipped (wallet payment method)");
                return;
            }
        }

        $user_id = $order->get_user_id();

        $is_foreign = ($order->get_billing_country() !== 'MY');
        $tin     = $is_foreign ? 'EI00000000020' : 'EI00000000010';
        $id_type = $is_foreign ? 'PASSPORT' : 'NRIC';
        $id_value = 'NA';

        $item_classification_code = $is_foreign ? '008' : '004';

        if ($user_id) {
            $status = get_user_meta($user_id, 'lhdn_tin_validation', true);
            if($status === 'valid'){
                $tin      = get_user_meta($user_id, 'lhdn_tin', true) ?: $tin;
                $id_type  = get_user_meta($user_id, 'lhdn_id_type', true) ?: $id_type;
                $id_value = get_user_meta($user_id, 'lhdn_id_value', true) ?: $id_value;
                $item_classification_code = '008';
            }
        }

        $lines = [];
        $total = 0;
        $tax_amount = 0;
        $i = 1;

        foreach ($order->get_items() as $item) {
            $qty   = (float) $item->get_quantity();
            $price = (float) $item->get_total() / max($qty, 1);

            $lines[] = [
                'id'          => $i++,
                'qty'         => $qty,
                'unit_price'  => round($price, 2),
                'desc'        => $item->get_name(),
            ];

            $total += $item->get_total();
            $tax_amount += $item->get_total_tax();
        }

        foreach ($order->get_items('shipping') as $shipping) {
            $shippingTotal = (float) $shipping->get_total();

            if ($shippingTotal <= 0) {
                continue;
            }

            $lines[] = [
                'id'         => $i++,
                'qty'        => 1,
                'unit_price' => round($shippingTotal, 2),
                'desc'       => $shipping->get_name() ?: 'Shipping Fee',
            ];

            $total += $shippingTotal;
        }

        // Add order fees (e.g., COD fees, payment gateway fees)
        foreach ($order->get_fees() as $fee) {
            $feeTotal = (float) $fee->get_total();

            if ($feeTotal <= 0) {
                continue;
            }

            $lines[] = [
                'id'         => $i++,
                'qty'        => 1,
                'unit_price' => round($feeTotal, 2),
                'desc'       => $fee->get_name() ?: 'Fee',
            ];

            $total += $feeTotal;
            $tax_amount += $fee->get_total_tax();
        }

        LHDN_Logger::log("Re-submitting WC Order {$ordernum}");

        // Get and clean phone number (billing first, fallback to shipping)
        $phone = $this->clean_phone_number($order->get_billing_phone());
        if (empty($phone)) {
            $phone = $this->clean_phone_number($order->get_shipping_phone());
        }
        // If still empty, use a default placeholder (LHDN requires phone number)
        if (empty($phone)) {
            $phone = '60123456789'; // Default Malaysian phone number format
            LHDN_Logger::log("WC Order #{$order->get_id()} resubmit: Phone number missing, using default");
        }

        // Get billing city, fallback to country if city is not available
        $billing_city = $order->get_billing_city();
        if (empty($billing_city)) {
            $billing_city = $order->get_billing_country();
        }

        return $this->submit([
            'invoice_no' => $ordernum,
            'order_id' => $order->get_id(),
            'item_classification_code' => $item_classification_code,
            'buyer' => [
                'tin'   => $tin,
                'id_type' => $id_type,
                'id_value' => $id_value,
                'name'  => $order->get_billing_company() ?: $order->get_formatted_billing_full_name(),
                'phone' => $phone,
                'email' => $order->get_billing_email(),
                'address' => [
                    'city'       => $billing_city,
                    'postcode'   => $order->get_billing_postcode(),
                    'state_code' => LHDN_Helpers::wc_state_to_lhdn($order->get_billing_state()),
                    'line1'      => trim($order->get_billing_address_1() . ' ' . $order->get_billing_address_2()),
                    'country'    => LHDN_Helpers::country_iso2_to_iso3($order->get_billing_country()),
                ]
            ],
            'seller_address' => [
                'city'       => LHDN_SELLER_ADDRESS_CITY,
                'postcode'   => LHDN_SELLER_ADDRESS_POSTCODE,
                'state_code' => LHDN_Helpers::wc_state_to_lhdn(LHDN_SELLER_ADDRESS_STATE),
                'line1'      => LHDN_SELLER_ADDRESS_LINE1,
                'country'    => LHDN_SELLER_ADDRESS_COUNTRY,
            ],
            'lines' => $lines,
            'total' => $total,
            'tax_amount' => $tax_amount,
        ]);
    }

    /**
     * Resubmit test invoice
     */
    public function resubmit_test($ordernum) {
        $invoiceNo = $ordernum;

        $lines = [
            ['id'=>1,'qty'=>1,'unit_price'=>1100,'desc'=>'Consultation Service'],
            ['id'=>2,'qty'=>2,'unit_price'=>1234.50,'desc'=>'Follow-up Service'],
        ];

        $total = array_reduce($lines, fn($t,$l) => $t + ($l['qty'] * $l['unit_price']), 0);
        $tax_amount = 0;

        return $this->submit([
            'invoice_no' => $invoiceNo,
            'order_id' => 0, // Dummy order_id for test invoices
            'item_classification_code' => '004',
            'buyer' => [
                'tin'   => 'EI00000000010',
                'id_type' => 'NRIC',
                'id_value' => 'NA',
                'name'  => 'Test Buyer Name',
                'phone' => '60123456789',
                'email' => 'test_buyer@example.com',
                'address' => [
                    'city'       => 'Kuala Lumpur',
                    'postcode'   => '50480',
                    'state_code' => LHDN_Helpers::wc_state_to_lhdn('WP'),
                    'line1'      => 'Lot 77, Jalan Test',
                    'country'    => 'MYS',
                ]
            ],
            'seller_address' => [
                'city'       => LHDN_SELLER_ADDRESS_CITY,
                'postcode'   => LHDN_SELLER_ADDRESS_POSTCODE,
                'state_code' => LHDN_Helpers::wc_state_to_lhdn(LHDN_SELLER_ADDRESS_STATE),
                'line1'      => LHDN_SELLER_ADDRESS_LINE1,
                'country'    => LHDN_SELLER_ADDRESS_COUNTRY,
            ],
            'lines' => $lines,
            'total' => $total,
            'tax_amount' => $tax_amount,
        ]);
    }
}

