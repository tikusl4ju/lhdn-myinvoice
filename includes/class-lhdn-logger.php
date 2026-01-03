<?php
/**
 * LHDN Logger
 */

if (!defined('ABSPATH')) exit;

class LHDN_Logger {
    
    /**
     * Log message
     */
    public static function log($msg) {
        if (!LHDN_Settings::get('debug_enabled', '0')) {
            return;
        }

        if (is_array($msg) || is_object($msg)) {
            $msg = json_encode(
                $msg,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
            );
        }

        $logs = get_option('lhdn_logs', []);
        $logs[] = wp_date('H:i:s') . " | " . $msg;

        update_option('lhdn_logs', array_slice($logs, -300), false);
    }
}

