<?php
/**
 * Backward Compatibility Functions
 * These functions maintain compatibility with any external code
 * that might be calling the old procedural function names
 */

if (!defined('ABSPATH')) exit;

// Settings compatibility
if (!function_exists('lhdn_get_setting')) {
    function lhdn_get_setting($key, $default = null) {
        return LHDN_Settings::get($key, $default);
    }
}

if (!function_exists('lhdn_set_setting')) {
    function lhdn_set_setting($key, $value) {
        return LHDN_Settings::set($key, $value);
    }
}

// Logger compatibility
if (!function_exists('lhdn_log')) {
    function lhdn_log($msg) {
        LHDN_Logger::log($msg);
    }
}

// Helper functions compatibility
if (!function_exists('lhdn_canonical_json')) {
    function lhdn_canonical_json($data) {
        return LHDN_Helpers::canonical_json($data);
    }
}

if (!function_exists('lhdn_get_state_options')) {
    function lhdn_get_state_options() {
        return LHDN_Helpers::get_state_options();
    }
}

if (!function_exists('lhdn_get_seller_id_type_options')) {
    function lhdn_get_seller_id_type_options() {
        return LHDN_Helpers::get_seller_id_type_options();
    }
}

if (!function_exists('lhdn_country_iso2_to_iso3')) {
    function lhdn_country_iso2_to_iso3($iso2) {
        return LHDN_Helpers::country_iso2_to_iso3($iso2);
    }
}

if (!function_exists('lhdn_wc_state_to_lhdn')) {
    function lhdn_wc_state_to_lhdn($wc_state) {
        return LHDN_Helpers::wc_state_to_lhdn($wc_state);
    }
}

// API functions compatibility
if (!function_exists('lhdn_get_token')) {
    function lhdn_get_token() {
        $api = new LHDN_API();
        return $api->get_token();
    }
}

if (!function_exists('lhdn_validate_tin')) {
    function lhdn_validate_tin($tin_number, $id_type, $id_value) {
        $api = new LHDN_API();
        return $api->validate_tin($tin_number, $id_type, $id_value);
    }
}

// Invoice functions compatibility
if (!function_exists('lhdn_submit_invoice')) {
    function lhdn_submit_invoice(array $args) {
        $invoice = new LHDN_Invoice();
        return $invoice->submit($args);
    }
}

if (!function_exists('lhdn_submit_wc_order')) {
    function lhdn_submit_wc_order(WC_Order $order) {
        $invoice = new LHDN_Invoice();
        return $invoice->submit_wc_order($order);
    }
}

if (!function_exists('lhdn_submit_test')) {
    function lhdn_submit_test() {
        $invoice = new LHDN_Invoice();
        return $invoice->submit_test();
    }
}

if (!function_exists('lhdn_resubmit_wc_order')) {
    function lhdn_resubmit_wc_order($ordernum) {
        $invoice = new LHDN_Invoice();
        return $invoice->resubmit_wc_order($ordernum);
    }
}

if (!function_exists('lhdn_resubmit_test')) {
    function lhdn_resubmit_test($ordernum) {
        $invoice = new LHDN_Invoice();
        return $invoice->resubmit_test($ordernum);
    }
}

if (!function_exists('lhdn_sync_document_status')) {
    function lhdn_sync_document_status($uuid) {
        $invoice = new LHDN_Invoice();
        return $invoice->sync_status($uuid);
    }
}

if (!function_exists('lhdn_cancel_document')) {
    function lhdn_cancel_document($uuid) {
        $invoice = new LHDN_Invoice();
        return $invoice->cancel($uuid);
    }
}

// WooCommerce compatibility
if (!function_exists('lhdn_submit_from_wc_order')) {
    function lhdn_submit_from_wc_order($order_id) {
        $wc = new LHDN_WooCommerce();
        return $wc->submit_from_wc_order($order_id);
    }
}

