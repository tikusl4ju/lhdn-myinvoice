<?php
/**
 * Plugin Name: MyInvoice Sync
 * Plugin URI: https://github.com/tikusl4ju/myinvoice-sync
 * Description: LHDN MyInvois Auto Submission
 * Author: TikusL4ju
 * Version: 2.0.9
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: myinvoice-sync
 */

if (!defined('ABSPATH')) exit;

// Load main plugin class
require_once plugin_dir_path(__FILE__) . 'includes/class-lhdn-plugin.php';

// Initialize plugin
LHDN_MyInvoice_Plugin::get_instance();
