<?php
/**
 * LHDN Admin Interface
 */

if (!defined('ABSPATH')) exit;

if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Base class for invoice tables with common functionality
 */
abstract class LHDN_Base_Invoice_Table extends WP_List_Table {

    protected $table_id = '';

    public function __construct($args = []) {
        $args['singular'] = isset($args['singular']) ? $args['singular'] : 'invoice';
        $args['plural'] = isset($args['plural']) ? $args['plural'] : 'invoices';
        parent::__construct($args);
        $this->table_id = $this->get_table_id();
    }

    abstract protected function get_table_id();
    abstract protected function get_status_filter();
    abstract protected function get_per_page();

    public function get_columns() {
        return [
            'invoice_no'  => 'Invoice',
            'order_id'    => 'Order ID',
            'status'      => 'Status',
            'code'        => 'HTTP',
            'uuid'        => 'UUID',
            'longid'      => 'Long ID',
            'item_class'  => 'Item Class',
            'actions'     => 'Actions',
        ];
    }

    protected function get_current_page() {
        $table_id = $this->get_table_id();
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- GET request for pagination, no data modification
        $pagenum = isset($_REQUEST['paged_' . $table_id]) ? absint(wp_unslash($_REQUEST['paged_' . $table_id])) : 0;
        return max(1, $pagenum);
    }

    protected function get_search_query() {
        $table_id = $this->get_table_id();
        $search_key = 's_' . $table_id;
        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- GET/POST request for search, no data modification
        if (isset($_POST[$search_key]) && !empty($_POST[$search_key])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- GET/POST request for search, no data modification
            return sanitize_text_field(wp_unslash($_POST[$search_key]));
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- GET request for search, no data modification
        } elseif (isset($_GET[$search_key]) && !empty($_GET[$search_key])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- GET request for search, no data modification
            return sanitize_text_field(wp_unslash($_GET[$search_key]));
        }
        return '';
    }

    public function prepare_items() {
        global $wpdb;

        $per_page = $this->get_per_page();
        $current_page = $this->get_current_page();
        $search = $this->get_search_query();

        // Build WHERE clause with status filter
        $where_clause = '';
        $where_values = [];
        
        $status_filter = $this->get_status_filter();
        if (!empty($status_filter)) {
            $placeholders = implode(',', array_fill(0, count($status_filter), '%s'));
            $where_clause = "WHERE status IN ($placeholders)";
            $where_values = $status_filter;
        } else {
            $where_clause = "WHERE 1=1";
        }
        
        // Add search filter
        if ($search) {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where_clause .= " AND (invoice_no LIKE %s OR uuid LIKE %s OR status LIKE %s)";
            $where_values = array_merge($where_values, [$like, $like, $like]);
        }

        // Get total count
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $wpdb->prefix is safe, user input is prepared
        $total_items_query = "SELECT COUNT(*) FROM {$wpdb->prefix}lhdn_myinvoice " . $where_clause;
        if (!empty($where_values)) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table name from $wpdb->prefix is safe, user input is prepared via $where_values
            $total_items_query = $wpdb->prepare($total_items_query, $where_values);
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Query is prepared above, table name from $wpdb->prefix is safe
        $total_items = (int) $wpdb->get_var($total_items_query);

        // Get items for current page
        $offset = ($current_page - 1) * $per_page;
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $wpdb->prefix is safe, user input is prepared
        $items_query = "SELECT * FROM {$wpdb->prefix}lhdn_myinvoice " . $where_clause . " ORDER BY id DESC LIMIT %d OFFSET %d";
        $query_values = array_merge($where_values, [$per_page, $offset]);
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table name from $wpdb->prefix is safe, user input is prepared via $query_values
        $items_query = $wpdb->prepare($items_query, $query_values);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Query is prepared above, table name from $wpdb->prefix is safe
        $this->items = $wpdb->get_results($items_query);

        $this->_column_headers = [$this->get_columns(), [], []];

        $this->set_pagination_args([
            'total_items' => $total_items,
            'per_page'    => $per_page,
            'total_pages' => ceil($total_items / $per_page),
        ]);
    }

    public function pagination($which) {
        if (empty($this->_pagination_args)) {
            return;
        }

        $total_items = $this->_pagination_args['total_items'];
        $total_pages = $this->_pagination_args['total_pages'];
        $infinite_scroll = false;
        if (isset($this->_pagination_args['infinite_scroll'])) {
            $infinite_scroll = $this->_pagination_args['infinite_scroll'];
        }

        if ('top' === $which && $total_pages > 1) {
            $this->screen->render_screen_reader_content('heading_pagination');
        }

        // translators: %s is the number of items
        $output = '<span class="displaying-num">' . sprintf(_n('%s item', '%s items', $total_items, 'myinvoice-sync'), number_format_i18n($total_items)) . '</span>';

        $current = $this->get_current_page();
        $table_id = $this->get_table_id();
        $removable_query_args = wp_removable_query_args();

        $http_host = isset($_SERVER['HTTP_HOST']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST'])) : '';
        $request_uri = isset($_SERVER['REQUEST_URI']) ? esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])) : '';
        $current_url = set_url_scheme('http://' . $http_host . $request_uri);
        $current_url = remove_query_arg($removable_query_args, $current_url);

        $page_links = [];

        $total_pages_before = '<span class="paging-input">';
        $total_pages_after  = '</span></span>';

        $disable_first = $current == 1;
        $disable_last  = $current == $total_pages;
        $disable_prev  = $current == 1;
        $disable_next  = $current == $total_pages;

        if ($disable_first) {
            $page_links[] = '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">&laquo;</span>';
        } else {
            $page_links[] = sprintf(
                "<a class='first-page button' href='%s'><span class='screen-reader-text'>%s</span><span aria-hidden='true'>%s</span></a>",
                esc_url(remove_query_arg('paged_' . $table_id, $current_url)),
                __('First page', 'myinvoice-sync'),
                '&laquo;'
            );
        }

        if ($disable_prev) {
            $page_links[] = '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">&lsaquo;</span>';
        } else {
            $page_links[] = sprintf(
                "<a class='prev-page button' href='%s'><span class='screen-reader-text'>%s</span><span aria-hidden='true'>%s</span></a>",
                esc_url(add_query_arg('paged_' . $table_id, max(1, $current - 1), $current_url)),
                __('Previous page', 'myinvoice-sync'),
                '&lsaquo;'
            );
        }

        if ('bottom' === $which) {
            $html_current_page  = $current;
            $total_pages_before = '<span class="screen-reader-text">' . __('Current Page', 'myinvoice-sync') . '</span><span id="table-paging-' . esc_attr($table_id) . '" class="paging-input"><span class="tablenav-paging-text">';
        } else {
            $html_current_page = sprintf(
                "%s<input class='current-page' id='current-page-selector-%s' type='text' name='paged_%s' value='%s' size='%d' aria-describedby='table-paging-%s' /><span class='tablenav-paging-text'>",
                '<label for="current-page-selector-' . esc_attr($table_id) . '" class="screen-reader-text">' . __('Current Page', 'myinvoice-sync') . '</label>',
                esc_attr($table_id),
                esc_attr($table_id),
                $current,
                strlen($total_pages),
                esc_attr($table_id)
            );
        }
        $html_total_pages = sprintf("<span class='total-pages'>%s</span>", number_format_i18n($total_pages));
        // translators: %1$s is the current page number, %2$s is the total number of pages
        $page_links[] = $total_pages_before . sprintf(_x('%1$s of %2$s', 'paging', 'myinvoice-sync'), $html_current_page, $html_total_pages) . $total_pages_after;

        if ($disable_next) {
            $page_links[] = '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">&rsaquo;</span>';
        } else {
            $page_links[] = sprintf(
                "<a class='next-page button' href='%s'><span class='screen-reader-text'>%s</span><span aria-hidden='true'>%s</span></a>",
                esc_url(add_query_arg('paged_' . $table_id, min($total_pages, $current + 1), $current_url)),
                __('Next page', 'myinvoice-sync'),
                '&rsaquo;'
            );
        }

        if ($disable_last) {
            $page_links[] = '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">&raquo;</span>';
        } else {
            $page_links[] = sprintf(
                "<a class='last-page button' href='%s'><span class='screen-reader-text'>%s</span><span aria-hidden='true'>%s</span></a>",
                esc_url(add_query_arg('paged_' . $table_id, $total_pages, $current_url)),
                __('Last page', 'myinvoice-sync'),
                '&raquo;'
            );
        }

        $pagination_links_class = 'pagination-links';
        if (!empty($infinite_scroll)) {
            $pagination_links_class = ' hide-if-js';
        }
        $output .= "\n<span class='$pagination_links_class'>" . join("\n", $page_links) . '</span>';

        if ($total_pages) {
            $page_class = $total_pages < 2 ? ' one-page' : '';
        } else {
            $page_class = ' no-pages';
        }
        $this->_pagination = "<div class='tablenav-pages{$page_class}'>$output</div>";

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $output is built from WordPress functions that already escape
        echo $this->_pagination;
    }

    public function search_box($text, $input_id) {
        $table_id = $this->get_table_id();
        $search_key = 's_' . $table_id;
        
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- GET request for search, no data modification
        if (empty($_REQUEST[$search_key]) && !$this->has_items()) {
            return;
        }

        $input_id = $input_id . '_' . $table_id;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- GET request for search, no data modification, value is sanitized with esc_attr
        $search_value = isset($_REQUEST[$search_key]) ? esc_attr(wp_unslash($_REQUEST[$search_key])) : '';

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- GET request for sorting, no data modification
        if (!empty($_REQUEST['orderby'])) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Value is sanitized with esc_attr and wp_unslash
            $orderby = esc_attr(wp_unslash($_REQUEST['orderby']));
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $orderby is already escaped with esc_attr() above
            echo '<input type="hidden" name="orderby" value="' . $orderby . '" />';
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- GET request for sorting, no data modification
        if (!empty($_REQUEST['order'])) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Value is sanitized with esc_attr and wp_unslash
            $order = esc_attr(wp_unslash($_REQUEST['order']));
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $order is already escaped with esc_attr() above
            echo '<input type="hidden" name="order" value="' . $order . '" />';
        }
        ?>
        <form method="get" action="">
            <input type="hidden" name="page" value="myinvoice-sync-invoices">
            <?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- GET request for sorting, no data modification ?>
            <?php if (!empty($_REQUEST['orderby'])): ?>
                <?php // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Value is sanitized with esc_attr and wp_unslash ?>
                <input type="hidden" name="orderby" value="<?php echo esc_attr(wp_unslash($_REQUEST['orderby'])); ?>" />
            <?php endif; ?>
            <?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- GET request for sorting, no data modification ?>
            <?php if (!empty($_REQUEST['order'])): ?>
                <?php // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Value is sanitized with esc_attr and wp_unslash ?>
                <input type="hidden" name="order" value="<?php echo esc_attr(wp_unslash($_REQUEST['order'])); ?>" />
            <?php endif; ?>
            <p class="search-box">
                <label class="screen-reader-text" for="<?php echo esc_attr($input_id); ?>"><?php echo esc_html($text); ?>:</label>
                <input type="search" id="<?php echo esc_attr($input_id); ?>" name="<?php echo esc_attr($search_key); ?>" value="<?php echo esc_attr($search_value); ?>" />
                <?php submit_button($text, '', '', false, ['id' => 'search-submit-' . $table_id]); ?>
            </p>
        </form>
        <?php
    }

    public function column_default($item, $column_name) {
        switch ($column_name) {
            case 'invoice_no':
                // Try to find WooCommerce order and create link
                $order = null;
                $invoice_no = $item->invoice_no;
                
                // Check if WooCommerce is active
                if (class_exists('WC_Order')) {
                    // Try to get order by ID if invoice_no is numeric
                    if (is_numeric($invoice_no)) {
                        $order = wc_get_order((int) $invoice_no);
                    }
                    
                    // If not found, search by order number
                    if (!$order) {
                        $orders = wc_get_orders([
                            'limit' => 1,
                            'orderby' => 'date',
                            'order' => 'DESC',
                            'meta_query' => [
                                [
                                    'key' => '_order_number',
                                    'value' => $invoice_no,
                                    'compare' => '='
                                ]
                            ]
                        ]);
                        
                        if (empty($orders)) {
                            // Try searching by order number method
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
                        } else {
                            $order = $orders[0];
                        }
                    }
                }
                
                // If order found, create link to edit page
                if ($order) {
                    $order_id = $order->get_id();
                    $edit_url = admin_url('post.php?post=' . $order_id . '&action=edit');
                    return sprintf(
                        '<a href="%s" target="_blank">%s</a>',
                        esc_url($edit_url),
                        esc_html($invoice_no)
                    );
                }
                
                // If no order found (test invoices, etc.), just display the number
                return esc_html($invoice_no);
            
            case 'order_id':
                $order_id = isset($item->order_id) ? $item->order_id : '';
                
                if (empty($order_id)) {
                    return '<span style="color: #999;">—</span>';
                }
                
                // Check if WooCommerce is active and create link to order
                if (class_exists('WC_Order')) {
                    $order = wc_get_order((int) $order_id);
                    if ($order) {
                        $edit_url = admin_url('post.php?post=' . $order_id . '&action=edit');
                        return sprintf(
                            '<a href="%s" target="_blank">#%s</a>',
                            esc_url($edit_url),
                            esc_html($order_id)
                        );
                    }
                }
                
                // If order not found, just display the ID
                return '#' . esc_html($order_id);
            
            case 'status':
                if ($item->status === 'queued') {
                    $day = preg_match('/^q(\d+)(\d{6})$/', $item->queue_status, $matches) ? $matches[1] : '';
                    $remaining_days = '';
                    if ($day && $item->queue_date) {
                        $queue_date = strtotime($item->queue_date);
                        $target_date = strtotime('+' . $day . ' days', $queue_date);
                        $current_date = current_time('timestamp');
                        $remaining = ceil(($target_date - $current_date) / DAY_IN_SECONDS);
                        $remaining_days = max(0, $remaining); // Ensure non-negative
                    }
                    return 'queued (' . $remaining_days . 'd)';
                }
                if ($item->status === 'retry') {
                    $retry_count = isset($item->retry_count) ? (int) $item->retry_count : 0;
                    return esc_html($item->status . '-' . $retry_count);
                }
                return esc_html($item->status);
            
            case 'code':
            case 'item_class':
            case 'uuid':
                return esc_html($item->$column_name);

            case 'longid':
                if (!$item->longid) return '';
                return sprintf(
                    '<a href="%s" target="_blank">%s</a>',
                    esc_url(LHDN_HOST . "/" . $item->uuid . "/share/" . $item->longid),
                    esc_html($item->longid)
                );
            case 'actions':
                return $this->render_actions($item);
        }
    }

    private function render_actions($item) {
        ob_start();
        ?>

        <div style="display: flex; gap: 4px; flex-wrap: wrap; align-items: center;">
        <?php if ($item->uuid && $item->status !== 'cancelled' && $item->status !== 'invalid' && $item->status !== 'failed'): ?>
            <form method="post" action="" style="display:inline; margin: 0;">
                <input type="hidden" name="page" value="myinvoice-sync-invoices">
                <?php wp_nonce_field('lhdn_cancel_action', 'lhdn_cancel_nonce'); ?>
                <input type="hidden" name="cancel_uuid" value="<?php echo esc_attr($item->uuid); ?>">
                <button class="button button-small button-secondary" type="submit">Cancel</button>
            </form>
        <?php endif; ?>

        <?php if ($item->uuid && in_array($item->status, ['submitted', 'valid', 'cancelled'], true)): ?>
            <form method="post" action="" style="display:inline; margin: 0;">
                <input type="hidden" name="page" value="myinvoice-sync-invoices">
                <?php wp_nonce_field('lhdn_sync_action', 'lhdn_sync_nonce'); ?>
                <input type="hidden" name="sync_uuid" value="<?php echo esc_attr($item->uuid); ?>">
                <button class="button button-small" type="submit">Sync</button>
            </form>
        <?php endif; ?>

        <?php if (!$item->uuid || $item->status === 'queued' || $item->status === 'retry' || $item->status === 'failed' || $item->status === 'invalid' || $item->status === 'processing'): ?>
            <form method="post" action="" style="display:inline; margin: 0;">
                <input type="hidden" name="page" value="myinvoice-sync-invoices">
                <?php wp_nonce_field('lhdn_resubmit_action', 'lhdn_resubmit_nonce'); ?>
                <input type="hidden" name="resubmit_invoice_no" value="<?php echo esc_attr($item->invoice_no); ?>">
                <input type="hidden" name="is_test_invoice" value="<?php echo str_starts_with($item->invoice_no, 'TEST-') ? 1 : 0; ?>">
                <button class="button button-small" type="submit">Process</button>
            </form>
        <?php endif; ?>

        <?php if (in_array($item->status, ['retry', 'failed', 'invalid', 'cancelled'], true)): ?>
            <form method="post" action="" style="display:inline; margin: 0;" onsubmit="return confirm('Are you sure you want to delete this invoice record? This action cannot be undone.');">
                <input type="hidden" name="page" value="myinvoice-sync-invoices">
                <?php wp_nonce_field('lhdn_delete_invoice_action', 'lhdn_delete_invoice_nonce'); ?>
                <input type="hidden" name="delete_invoice_id" value="<?php echo esc_attr($item->id); ?>">
                <button class="button button-small button-link-delete" type="submit" style="color: #b32d2e;">Delete</button>
            </form>
        <?php endif; ?>
        </div>

        <?php
        return ob_get_clean();
    }
}

/**
 * Table for Failed Invoices (retry, failed, invalid)
 */
class LHDN_Failed_Invoices_Table extends LHDN_Base_Invoice_Table {
    
    protected function get_table_id() {
        return 'failed';
    }
    
    protected function get_status_filter() {
        return ['retry', 'failed', 'invalid'];
    }
    
    protected function get_per_page() {
        return 5;
    }
}

/**
 * Table for Submitted Invoices (all other statuses)
 */
class LHDN_Submitted_Invoices_Table extends LHDN_Base_Invoice_Table {
    
    protected function get_table_id() {
        return 'submitted';
    }
    
    protected function get_status_filter() {
        // Return empty array - we'll override prepare_items to use NOT IN
        return [];
    }
    
    protected function get_per_page() {
        return 10;
    }
    
    public function prepare_items() {
        global $wpdb;

        $per_page = $this->get_per_page();
        $current_page = $this->get_current_page();
        $search = $this->get_search_query();

        // Build WHERE clause - exclude failed statuses
        $where_clause = "WHERE status NOT IN ('retry', 'failed', 'invalid')";
        $where_values = [];
        
        // Add search filter
        if ($search) {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where_clause .= " AND (invoice_no LIKE %s OR uuid LIKE %s OR status LIKE %s)";
            $where_values = [$like, $like, $like];
        }

        // Get total count
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $wpdb->prefix is safe, user input is prepared
        $total_items_query = "SELECT COUNT(*) FROM {$wpdb->prefix}lhdn_myinvoice " . $where_clause;
        if (!empty($where_values)) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table name from $wpdb->prefix is safe, user input is prepared via $where_values
            $total_items_query = $wpdb->prepare($total_items_query, $where_values);
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Query is prepared above, table name from $wpdb->prefix is safe
        $total_items = (int) $wpdb->get_var($total_items_query);

        // Get items for current page
        $offset = ($current_page - 1) * $per_page;
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $wpdb->prefix is safe, user input is prepared
        $items_query = "SELECT * FROM {$wpdb->prefix}lhdn_myinvoice " . $where_clause . " ORDER BY id DESC LIMIT %d OFFSET %d";
        if (!empty($where_values)) {
            $query_values = array_merge($where_values, [$per_page, $offset]);
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table name from $wpdb->prefix is safe, user input is prepared via $query_values
            $items_query = $wpdb->prepare($items_query, $query_values);
        } else {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table name from $wpdb->prefix is safe, user input is prepared
            $items_query = $wpdb->prepare($items_query, $per_page, $offset);
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Query is prepared above, table name from $wpdb->prefix is safe
        $this->items = $wpdb->get_results($items_query);

        $this->_column_headers = [$this->get_columns(), [], []];

        $this->set_pagination_args([
            'total_items' => $total_items,
            'per_page'    => $per_page,
            'total_pages' => ceil($total_items / $per_page),
        ]);
    }
}

class LHDN_Admin {
    
    private $invoice;
    private $api;
    
    public function __construct() {
        $this->invoice = new LHDN_Invoice();
        $this->api = new LHDN_API();
    }

    /**
     * Add admin menu
     */
    public function add_menu() {
        add_menu_page(
            'MyInvoice Sync',
            'MyInvoice Sync',
            'manage_options',
            'myinvoice-sync',
            '__return_null',
            'dashicons-media-spreadsheet'
        );

        add_submenu_page(
            'myinvoice-sync',
            'Invoices',
            'Invoices',
            'manage_options',
            'myinvoice-sync-invoices',
            [$this, 'admin_page']
        );

        add_submenu_page(
            'myinvoice-sync',
            'Settings',
            'Settings',
            'manage_options',
            'myinvoice-sync-settings',
            [$this, 'settings_page']
        );

        remove_submenu_page('myinvoice-sync', 'myinvoice-sync');
    }

    /**
     * Admin init
     */
    public function admin_init() {
        register_setting('lhdn_settings_group', 'lhdn_settings', array(
            'sanitize_callback' => array($this, 'sanitize_settings')
        ));

        if (!current_user_can('manage_options')) {
            return;
        }

        // Handle plugin activation/deactivation
        if (isset($_POST['lhdn_activate_plugin'])) {
            check_admin_referer('lhdn_activate_plugin');
            
            // Check test results
            $token_test_result = LHDN_Settings::get('lhdn_test_token_result', null);
            $tin_test_result = LHDN_Settings::get('lhdn_test_tin_result', null);
            
            $token_failed = ($token_test_result === 'error' || $token_test_result === 'failed');
            $tin_failed = ($tin_test_result === 'invalid' || $tin_test_result === 'error' || $tin_test_result === 'failed' || $tin_test_result === 'missing');
            $token_passed = ($token_test_result === 'success');
            $tin_passed = ($tin_test_result === 'valid');
            
            // Require both tests to be run
            if ($token_test_result === null || $tin_test_result === null) {
                wp_safe_redirect(add_query_arg(['settings-updated' => 'tests-required'], admin_url('admin.php?page=myinvoice-sync-settings')));
                exit;
            }
            
            // Prevent activation if both tests have failed
            if ($token_failed && $tin_failed) {
                wp_safe_redirect(add_query_arg(['settings-updated' => 'activation-blocked'], admin_url('admin.php?page=myinvoice-sync-settings')));
                exit;
            }
            
            // Only allow activation if both tests have passed
            if (!$token_passed || !$tin_passed) {
                wp_safe_redirect(add_query_arg(['settings-updated' => 'activation-blocked'], admin_url('admin.php?page=myinvoice-sync-settings')));
                exit;
            }
            
            // Both tests passed, allow activation
            LHDN_Settings::set('plugin_active', '1');
            $cron = new LHDN_Cron();
            $cron->schedule_events();
            LHDN_Logger::log('Process activated via admin settings');
            wp_safe_redirect(add_query_arg(['settings-updated' => 'activated'], admin_url('admin.php?page=myinvoice-sync-settings')));
            exit;
        }

        if (isset($_POST['lhdn_deactivate'])) {
            check_admin_referer('lhdn_deactivate');
            LHDN_Settings::set('plugin_active', '0');
            $cron = new LHDN_Cron();
            $cron->clear_events();
            LHDN_Logger::log('Process deactivated via admin settings');
            wp_safe_redirect(add_query_arg(['settings-updated' => 'deactivated'], admin_url('admin.php?page=myinvoice-sync-settings')));
            exit;
        }

        // Handle test functions (work even when plugin is inactive)
        if (isset($_POST['lhdn_test_token'])) {
            check_admin_referer('lhdn_test_token');
            $token = $this->api->get_token(true);
            if ($token) {
                LHDN_Settings::set('lhdn_test_token_result', 'success');
                wp_safe_redirect(add_query_arg(['test-result' => 'token-success'], admin_url('admin.php?page=myinvoice-sync-settings')));
            } else {
                LHDN_Settings::set('lhdn_test_token_result', 'error');
                wp_safe_redirect(add_query_arg(['test-result' => 'token-error'], admin_url('admin.php?page=myinvoice-sync-settings')));
            }
            exit;
        }

        if (isset($_POST['lhdn_test_validate_tin'])) {
            check_admin_referer('lhdn_test_validate_tin');
            $seller_tin = LHDN_Settings::get('seller_tin', '');
            $seller_id_type = LHDN_Settings::get('seller_id_type', 'BRN');
            $seller_id_value = LHDN_Settings::get('seller_id_value', '');
            
            if (empty($seller_tin) || empty($seller_id_type) || empty($seller_id_value)) {
                LHDN_Settings::set('lhdn_test_tin_result', 'missing');
                wp_safe_redirect(add_query_arg(['test-result' => 'tin-missing'], admin_url('admin.php?page=myinvoice-sync-settings')));
            } else {
                $result = $this->api->validate_tin($seller_tin, $seller_id_type, $seller_id_value);
                LHDN_Settings::set('lhdn_test_tin_result', $result['status']);
                wp_safe_redirect(add_query_arg(['test-result' => 'tin-' . $result['status']], admin_url('admin.php?page=myinvoice-sync-settings')));
            }
            exit;
        }

        if (isset($_GET['run_lhdn_cron']) && current_user_can('manage_options')) {
            LHDN_Logger::log('Manual cron trigger');
            if (get_transient('lhdn_manual_lock')) return;
            set_transient('lhdn_manual_lock', 1, 60);

            do_action('lhdn_sync_submitted_invoices');
            do_action('lhdn_retry_err_invoices');
        }

        // Handle database backup (export to CSV) - must be early to prevent HTML output
        if (isset($_POST['backup_database'])) {
            check_admin_referer('lhdn_backup_database');
            $this->export_database_to_csv();
            exit; // Exit after sending file
        }
    }

    /**
     * Sanitize settings
     */
    public function sanitize_settings($input) {
        if (!is_array($input)) {
            return array();
        }
        
        $sanitized = array();
        foreach ($input as $key => $value) {
            $sanitized[$key] = sanitize_text_field(wp_unslash($value));
        }
        
        return $sanitized;
    }

    /**
     * Validate settings before saving
     * Returns array with 'errors' (array of error messages) and 'invalid_fields' (array of field keys)
     */
    private function validate_settings($data) {
        $errors = [];
        $invalid_fields = [];

        // Required fields
        $required_fields = [
            'client_id' => 'Client ID',
            'client_secret1' => 'Client Secret 1',
            'client_secret2' => 'Client Secret 2',
            'seller_tin' => 'Seller TIN',
            'seller_id_value' => 'Seller ID Value',
            'seller_name' => 'Seller Name',
            'seller_email' => 'Seller Email',
            'seller_phone' => 'Seller Phone',
            'seller_city' => 'Seller City',
            'seller_postcode' => 'Seller Postcode',
            'seller_address1' => 'Seller Address',
        ];

        // Check required fields
        foreach ($required_fields as $field_key => $field_label) {
            $value = isset($data[$field_key]) ? trim($data[$field_key]) : '';
            if (empty($value)) {
                $errors[] = sprintf('%s is required.', $field_label);
                $invalid_fields[] = $field_key;
            }
        }

        // Validate Seller Name - maximum 300 characters
        if (isset($data['seller_name']) && !empty(trim($data['seller_name']))) {
            $seller_name = trim($data['seller_name']);
            if (mb_strlen($seller_name) > 300) {
                $errors[] = 'Seller Name cannot exceed 300 characters.';
                if (!in_array('seller_name', $invalid_fields)) {
                    $invalid_fields[] = 'seller_name';
                }
            }
        }

        // Validate Seller Postcode - must be 5 digits
        if (isset($data['seller_postcode']) && !empty(trim($data['seller_postcode']))) {
            $postcode = trim($data['seller_postcode']);
            if (!preg_match('/^\d{5}$/', $postcode)) {
                $errors[] = 'Seller Postcode must be exactly 5 digits (Malaysia postcode format).';
                if (!in_array('seller_postcode', $invalid_fields)) {
                    $invalid_fields[] = 'seller_postcode';
                }
            }
        }

        // Validate Seller Email - valid format (RFC 5321 & RFC 5322), max 320 characters, no spaces, can be blank
        if (isset($data['seller_email']) && !empty(trim($data['seller_email']))) {
            $email = trim($data['seller_email']);
            // Check for spaces
            if (strpos($email, ' ') !== false) {
                $errors[] = 'Seller Email cannot contain spaces.';
                if (!in_array('seller_email', $invalid_fields)) {
                    $invalid_fields[] = 'seller_email';
                }
            }
            // Check maximum length
            if (mb_strlen($email) > 320) {
                $errors[] = 'Seller Email cannot exceed 320 characters.';
                if (!in_array('seller_email', $invalid_fields)) {
                    $invalid_fields[] = 'seller_email';
                }
            }
            // Validate email format
            if (!is_email($email)) {
                $errors[] = 'Seller Email must be a valid email address (RFC 5321 & RFC 5322 format).';
                if (!in_array('seller_email', $invalid_fields)) {
                    $invalid_fields[] = 'seller_email';
                }
            }
        }

        // Validate Seller Phone - minimum 8 and maximum 20 characters, optional + at front, no spaces
        if (isset($data['seller_phone']) && !empty(trim($data['seller_phone']))) {
            $phone = trim($data['seller_phone']);
            // Check for spaces
            if (strpos($phone, ' ') !== false) {
                $errors[] = 'Seller Phone cannot contain spaces.';
                if (!in_array('seller_phone', $invalid_fields)) {
                    $invalid_fields[] = 'seller_phone';
                }
            }
            // Check length (8-20 characters)
            $phone_length = mb_strlen($phone);
            if ($phone_length < 8 || $phone_length > 20) {
                $errors[] = 'Seller Phone must be between 8 and 20 characters.';
                if (!in_array('seller_phone', $invalid_fields)) {
                    $invalid_fields[] = 'seller_phone';
                }
            }
            // Validate format: optional + at front, then only digits
            if (!preg_match('/^\+?\d+$/', $phone)) {
                $errors[] = 'Seller Phone must contain only numbers with an optional plus (+) symbol at the front.';
                if (!in_array('seller_phone', $invalid_fields)) {
                    $invalid_fields[] = 'seller_phone';
                }
            }
        }

        // Validate Seller Address - maximum 150 characters
        if (isset($data['seller_address1']) && !empty(trim($data['seller_address1']))) {
            $seller_address = trim($data['seller_address1']);
            if (mb_strlen($seller_address) > 150) {
                $errors[] = 'Seller Address cannot exceed 150 characters.';
                if (!in_array('seller_address1', $invalid_fields)) {
                    $invalid_fields[] = 'seller_address1';
                }
            }
        }

        // Validate Seller City - maximum 50 characters
        if (isset($data['seller_city']) && !empty(trim($data['seller_city']))) {
            $seller_city = trim($data['seller_city']);
            if (mb_strlen($seller_city) > 50) {
                $errors[] = 'Seller City cannot exceed 50 characters.';
                if (!in_array('seller_city', $invalid_fields)) {
                    $invalid_fields[] = 'seller_city';
                }
            }
        }

        // Validate Seller SST Number - maximum 35 characters, only dash (-) and semicolon (;), up to 2 SST numbers separated by semicolon, allows 'NA'
        if (isset($data['seller_sst_number']) && !empty(trim($data['seller_sst_number']))) {
            $sst_number = trim($data['seller_sst_number']);
            // Allow "NA" (case-insensitive)
            if (strtoupper($sst_number) !== 'NA') {
                // Check maximum length
                if (mb_strlen($sst_number) > 35) {
                    $errors[] = 'Seller SST Number cannot exceed 35 characters.';
                    if (!in_array('seller_sst_number', $invalid_fields)) {
                        $invalid_fields[] = 'seller_sst_number';
                    }
                }
                // Check for invalid characters (only allow alphanumeric, dash, and semicolon)
                if (!preg_match('/^[A-Z0-9\-\;]+$/i', $sst_number)) {
                    $errors[] = 'Seller SST Number can only contain letters, numbers, dash (-), and semicolon (;) characters.';
                    if (!in_array('seller_sst_number', $invalid_fields)) {
                        $invalid_fields[] = 'seller_sst_number';
                    }
                }
                // Check that there are at most 2 SST numbers separated by semicolon
                $sst_parts = explode(';', $sst_number);
                if (count($sst_parts) > 2) {
                    $errors[] = 'Seller SST Number can contain up to 2 SST numbers separated by semicolon (;).';
                    if (!in_array('seller_sst_number', $invalid_fields)) {
                        $invalid_fields[] = 'seller_sst_number';
                    }
                }
            }
        }

        // Validate Seller TTX Number - maximum 17 characters, only dash (-) special character, allows 'NA'
        if (isset($data['seller_ttx_number']) && !empty(trim($data['seller_ttx_number']))) {
            $ttx_number = trim($data['seller_ttx_number']);
            // Allow "NA" (case-insensitive)
            if (strtoupper($ttx_number) !== 'NA') {
                // Check maximum length
                if (mb_strlen($ttx_number) > 17) {
                    $errors[] = 'Seller TTX Number cannot exceed 17 characters.';
                    if (!in_array('seller_ttx_number', $invalid_fields)) {
                        $invalid_fields[] = 'seller_ttx_number';
                    }
                }
                // Check for invalid characters (only allow alphanumeric and dash)
                if (!preg_match('/^[A-Z0-9\-]+$/i', $ttx_number)) {
                    $errors[] = 'Seller TTX Number can only contain letters, numbers, and dash (-) characters.';
                    if (!in_array('seller_ttx_number', $invalid_fields)) {
                        $invalid_fields[] = 'seller_ttx_number';
                    }
                }
            }
        }

        return [
            'errors' => $errors,
            'invalid_fields' => $invalid_fields
        ];
    }

    /**
     * Settings page
     */
    public function settings_page() {
        // Handle PEM file upload
        if (isset($_POST['upload_pem_cert']) && isset($_FILES['pem_file'])) {
            check_admin_referer('lhdn_upload_pem');
            
            if (isset($_FILES['pem_file']['error']) && $_FILES['pem_file']['error'] === UPLOAD_ERR_OK) {
                // Validate file extension
                $file_name = isset($_FILES['pem_file']['name']) ? sanitize_file_name(wp_unslash($_FILES['pem_file']['name'])) : '';
                $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
                
                if ($file_ext !== 'pem') {
                    echo '<div class="error"><p>Only .pem files are accepted. Please upload a PEM certificate file.</p></div>';
                } else {
                    $tmp_name = isset($_FILES['pem_file']['tmp_name']) ? sanitize_text_field(wp_unslash($_FILES['pem_file']['tmp_name'])) : '';
                    if (empty($tmp_name) || !is_uploaded_file($tmp_name)) {
                        echo '<div class="error"><p>Invalid file upload. Please try again.</p></div>';
                    } else {
                        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
                        $file_content = file_get_contents($tmp_name);
                    
                    // Validate PEM content
                    if (strpos($file_content, '-----BEGIN') === false || strpos($file_content, '-----END') === false) {
                        echo '<div class="error"><p>Invalid PEM file format. The file must contain a valid PEM certificate.</p></div>';
                        } elseif ($this->save_pem_certificate($file_content)) {
                            echo '<div class="updated"><p>PEM certificate uploaded and parsed successfully. You can now select UBL 1.1 in the settings above.</p></div>';
                        } else {
                            echo '<div class="error"><p>Failed to upload or parse PEM certificate. Please check the file format.</p></div>';
                        }
                    }
                }
            } else {
                echo '<div class="error"><p>Error uploading file. Please try again.</p></div>';
            }
        }

        // Handle reset certificate
        if (isset($_POST['reset_certificate'])) {
            check_admin_referer('lhdn_reset_cert');
            
            if ($this->reset_certificate()) {
                echo '<div class="updated"><p>All PEM certificate records have been deleted. UBL version has been set back to 1.0.</p></div>';
            } else {
                echo '<div class="error"><p>Failed to reset certificate.</p></div>';
            }
        }

        // Handle database structure fix
        if (isset($_POST['fix_database_structure'])) {
            check_admin_referer('lhdn_fix_database');
            LHDN_Database::create_tables();
            echo '<div class="updated"><p>Database structure has been fixed. All missing tables and columns have been created.</p></div>';
        }

        // Handle database clear
        if (isset($_POST['clear_database'])) {
            check_admin_referer('lhdn_clear_database');
            
            if ($this->clear_database()) {
                echo '<div class="updated"><p>Database table has been cleared successfully. All invoice records have been deleted.</p></div>';
                LHDN_Logger::log('Database cleared via admin settings');
            } else {
                echo '<div class="error"><p>Failed to clear database.</p></div>';
            }
        }

        // Handle database restore (import from CSV)
        if (isset($_POST['restore_database']) && isset($_FILES['backup_file'])) {
            check_admin_referer('lhdn_restore_database');
            
            if (isset($_FILES['backup_file']['error']) && $_FILES['backup_file']['error'] === UPLOAD_ERR_OK) {
                // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- File is validated and sanitized in import_database_from_csv() method
                $backup_file = isset($_FILES['backup_file']) ? $_FILES['backup_file'] : array();
                $result = $this->import_database_from_csv($backup_file);
                if ($result['success']) {
                    echo '<div class="updated"><p>' . esc_html($result['message']) . '</p></div>';
                    LHDN_Logger::log('Database restored via admin settings: ' . $result['message']);
                } else {
                    echo '<div class="error"><p>' . esc_html($result['message']) . '</p></div>';
                }
            } else {
                echo '<div class="error"><p>Error uploading file. Please try again.</p></div>';
            }
        }

        if (isset($_POST['save_lhdn_settings'])) {
            check_admin_referer('lhdn_settings_save');

            // Ensure database tables exist before saving
            global $wpdb;
            $settings_table = $wpdb->prefix . 'lhdn_settings';
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            if ($wpdb->get_var("SHOW TABLES LIKE '{$settings_table}'") !== $settings_table) {
                LHDN_Database::create_tables();
                LHDN_Settings::init_defaults();
            }

            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Data is sanitized in validate_settings() and sanitize_settings() methods
            $data = isset($_POST['lhdn']) ? wp_unslash($_POST['lhdn']) : [];

            // Validate settings before saving
            $validation_result = $this->validate_settings($data);
            $validation_errors = $validation_result['errors'];
            $invalid_fields = $validation_result['invalid_fields'];

            // Show validation errors for invalid fields
            if (!empty($validation_errors)) {
                foreach ($validation_errors as $error) {
                    echo '<div class="error"><p>' . esc_html($error) . '</p></div>';
                }
            }

            // Handle environment change first
            $old_environment = LHDN_Settings::get('environment', 'sandbox');
            $environment_changed_to_production = false;
            if (isset($data['environment'])) {
                $new_environment = sanitize_text_field($data['environment']);
                if ($old_environment !== $new_environment) {
                    // If changing to Production, deactivate plugin and clear tests
                    if ($new_environment === 'production' && $old_environment !== 'production') {
                        $environment_changed_to_production = true;
                        // Deactivate plugin
                        LHDN_Settings::set('plugin_active', '0');
                        // Clear cron events
                        $cron = new LHDN_Cron();
                        $cron->clear_events();
                        // Clear test results
                        LHDN_Settings::set('lhdn_test_token_result', null);
                        LHDN_Settings::set('lhdn_test_tin_result', null);
                        LHDN_Logger::log('Plugin deactivated due to environment change to Production');
                    }
                }
                LHDN_Settings::set_environment($new_environment);
            }

            // Handle UBL version change - validate PEM certificate exists for 1.1
            if (isset($data['ubl_version'])) {
                $requested_version = sanitize_text_field($data['ubl_version']);
                
                // If trying to set 1.1, check if PEM certificate exists
                if ($requested_version === '1.1') {
                    $pem_content = LHDN_MyInvoice_Plugin::get_pem_from_database();
                    if (empty($pem_content)) {
                        echo '<div class="error"><p>Cannot set UBL 1.1: No valid PEM certificate found in database. Please upload a certificate first.</p></div>';
                        $requested_version = '1.0'; // Force to 1.0
                    }
                }
                
                LHDN_Settings::set('ubl_version', $requested_version);
            }

            LHDN_Settings::set('debug_enabled', isset($data['debug_enabled']) ? '1' : '0');
            LHDN_Settings::set('show_tin_badge', isset($data['show_tin_badge']) ? '1' : '0');
            LHDN_Settings::set('show_receipt', isset($data['show_receipt']) ? '1' : '0');
            LHDN_Settings::set('exclude_wallet', isset($data['exclude_wallet']) ? '1' : '0');
            LHDN_Settings::set('tin_enforce', isset($data['tin_enforce']) ? '1' : '0');
            
            // Save custom order statuses (comma-separated) with validation
            if (isset($data['custom_order_statuses'])) {
                $custom_statuses = sanitize_text_field($data['custom_order_statuses']);
                // Clean up: remove spaces, convert to lowercase, remove empty values
                $statuses = array_filter(array_map('trim', explode(',', strtolower($custom_statuses))));
                
                // Validate each status slug format
                $valid_statuses = [];
                $invalid_statuses = [];
                
                foreach ($statuses as $status) {
                    // WooCommerce order status slug format: lowercase, alphanumeric, hyphens, underscores only
                    // Must start with a letter, 1-20 characters
                    if (preg_match('/^[a-z][a-z0-9_-]{0,19}$/', $status)) {
                        $valid_statuses[] = $status;
                    } else {
                        $invalid_statuses[] = $status;
                    }
                }
                
                if (!empty($invalid_statuses)) {
                    add_settings_error(
                        'lhdn_custom_order_statuses',
                        'invalid_status_format',
                        sprintf(
                            'Invalid order status slug(s): %s. Status slugs must be lowercase, start with a letter, and contain only letters, numbers, hyphens, and underscores (max 20 characters).',
                            implode(', ', $invalid_statuses)
                        ),
                        'error'
                    );
                }
                
                LHDN_Settings::set('custom_order_statuses', implode(',', $valid_statuses));
            } else {
                LHDN_Settings::set('custom_order_statuses', '');
            }

            // Always set seller_country to MYS
            LHDN_Settings::set('seller_country', 'MYS');

            // Save valid fields only (exclude invalid fields)
            $test_relevant_settings = ['client_id', 'client_secret1', 'client_secret2', 'seller_tin', 'seller_id_type', 'seller_id_value'];
            $settings_changed = false;
            $critical_settings_changed = false;

            // First, check for critical settings changes BEFORE saving
            foreach ($data as $k => $v) {
                // Skip invalid fields
                if (in_array($k, $invalid_fields)) continue;
                
                // Skip fields that are always saved separately
                if (in_array($k, ['environment', 'api_host', 'host', 'seller_country', 'ubl_version', 'debug_enabled', 'show_tin_badge', 'show_receipt', 'exclude_wallet', 'tin_enforce', 'custom_order_statuses'])) continue;
                
                // Check if this is a critical setting and if it changed
                if (in_array($k, $test_relevant_settings)) {
                    $old_value = LHDN_Settings::get($k, '');
                    $new_value = sanitize_text_field($v);
                    
                    // If the value changed and there was a previous value set, mark as critical change
                    if ($old_value !== $new_value && !empty($old_value)) {
                        $critical_settings_changed = true;
                        $settings_changed = true;
                    } elseif ($old_value !== $new_value) {
                        $settings_changed = true;
                    }
                }
            }

            // Now save the fields
            foreach ($data as $k => $v) {
                // Skip invalid fields (don't save them)
                if (in_array($k, $invalid_fields)) continue;
                
                // Skip fields that are always saved separately (environment, api_host, etc.)
                if (in_array($k, ['environment', 'api_host', 'host', 'seller_country', 'ubl_version', 'debug_enabled'])) continue;
                
                // Convert empty SST and TTX numbers to "NA"
                $value = sanitize_text_field($v);
                if (in_array($k, ['seller_sst_number', 'seller_ttx_number']) && empty(trim($value))) {
                    $value = 'NA';
                }
                
                // Save valid field
                LHDN_Settings::set($k, $value);
            }

            // If critical settings changed, deactivate plugin and clear tests
            if ($critical_settings_changed && !$environment_changed_to_production) {
                // Deactivate plugin
                LHDN_Settings::set('plugin_active', '0');
                // Clear cron events
                $cron = new LHDN_Cron();
                $cron->clear_events();
                // Clear test results
                LHDN_Settings::set('lhdn_test_token_result', null);
                LHDN_Settings::set('lhdn_test_tin_result', null);
                LHDN_Logger::log('Plugin deactivated due to critical settings change (Client ID, Client Secret, or Seller TIN/ID)');
            } elseif ($settings_changed && !$environment_changed_to_production) {
                // Clear test results if other test-relevant settings changed
                LHDN_Settings::set('lhdn_test_token_result', null);
                LHDN_Settings::set('lhdn_test_tin_result', null);
            }

            // Show success/error messages
            if (!empty($validation_errors)) {
                echo '<div class="updated"><p><strong>Valid settings saved.</strong> Please correct the errors above and save again to update invalid fields.</p></div>';
            } elseif ($environment_changed_to_production) {
                echo '<div class="updated"><p><strong>Settings saved.</strong></p></div>';
                echo '<div class="notice notice-warning is-dismissible"><p><strong>Environment Changed to Production:</strong> The plugin has been deactivated for safety. Please run both API tests (Get Token and Validate TIN) with your Production credentials before reactivating the plugin.</p></div>';
            } elseif ($critical_settings_changed) {
                echo '<div class="updated"><p><strong>Settings saved.</strong></p></div>';
                echo '<div class="notice notice-warning is-dismissible"><p><strong>Critical Settings Changed:</strong> Changes to Client ID, Client Secret 1, Client Secret 2, Seller TIN, Seller ID Type, or Seller ID Value require reactivation. The plugin has been deactivated and all test results have been cleared. Please run both API tests (Get Token and Validate TIN) with your new credentials before reactivating the plugin.</p></div>';
            } else {
                echo '<div class="updated"><p>Settings saved.</p></div>';
            }
        }

        $fields = [
            'debug_enabled'     => 'Enable Debug Logging',
            'show_tin_badge'    => 'Show TIN Badge',
            'show_receipt'      => 'Show Receipt',
            'exclude_wallet'    => 'Exclude Wallet',
            'tin_enforce'       => 'TIN Enforce',
            'custom_order_statuses' => 'Custom Order Statuses',
            'ubl_version'       => 'UBL Version',
            'billing_circle'    => 'Billing Circle',
            'tax_category_id'   => 'Tax Category ID',
            'industry_classification_code' => 'Industry Classification Code (MSIC)',
            'host'              => 'Portal Host',
            'api_host'          => 'API Host',
            'client_id'         => 'Client ID',
            'client_secret1'    => 'Client Secret 1',
            'client_secret2'    => 'Client Secret 2',
            'seller_tin'        => 'Seller TIN',
            'seller_id_type'    => 'Seller ID Type',
            'seller_id_value'   => 'Seller ID Value',
            'seller_sst_number' => 'Seller SST Number',
            'seller_ttx_number' => 'Seller TTX Number',
            'seller_name'       => 'Seller Name',
            'seller_email'      => 'Seller Email',
            'seller_phone'      => 'Seller Phone',
            'seller_city'       => 'Seller City',
            'seller_postcode'   => 'Seller Postcode',
            'seller_state'      => 'Seller State',
            'seller_address1'   => 'Seller Address',
            'seller_country'    => 'Seller Country',
        ];
        
        $current_environment = LHDN_Settings::get('environment', 'sandbox');
        $current_api_host = LHDN_Settings::get_api_host();
        $current_portal_host = LHDN_Settings::get_portal_host();
        $is_plugin_active = LHDN_Settings::is_plugin_active();
        
        // Show success messages
        if (isset($_GET['settings-updated'])) {
            $settings_updated = sanitize_text_field(wp_unslash($_GET['settings-updated']));
            if ($settings_updated === 'activated') {
                echo '<div class="notice notice-success is-dismissible"><p>Process has been activated. All functions are now running.</p></div>';
            } elseif ($settings_updated === 'deactivated') {
                echo '<div class="notice notice-warning is-dismissible"><p>Process has been deactivated. All functions are now on hold.</p></div>';
            } elseif ($settings_updated === 'activation-blocked') {
                echo '<div class="notice notice-error is-dismissible"><p><strong>Activation Blocked:</strong> Both API tests (Get Token and Validate TIN) have failed. Please fix your API credentials and test again before activating the plugin.</p></div>';
            } elseif ($settings_updated === 'tests-required') {
                echo '<div class="notice notice-warning is-dismissible"><p><strong>Tests Required:</strong> Please run both API tests (Get Token and Validate TIN) before activating the plugin.</p></div>';
            }
        }

        // Show test result messages
        if (isset($_GET['test-result'])) {
            $test_result = sanitize_text_field(wp_unslash($_GET['test-result']));
            if ($test_result === 'token-success') {
                echo '<div class="notice notice-success is-dismissible"><p><strong>Token Test:</strong> Successfully obtained OAuth token from LHDN API.</p></div>';
            } elseif ($test_result === 'token-error') {
                echo '<div class="notice notice-error is-dismissible"><p><strong>Token Test:</strong> Failed to obtain OAuth token. Please check your Client ID and Client Secret 1 settings.</p></div>';
            } elseif ($test_result === 'tin-valid') {
                echo '<div class="notice notice-success is-dismissible"><p><strong>TIN Validation Test:</strong> TIN validated successfully.</p></div>';
            } elseif ($test_result === 'tin-invalid') {
                echo '<div class="notice notice-error is-dismissible"><p><strong>TIN Validation Test:</strong> TIN not found or mismatched. Please verify your Seller TIN, ID Type, and ID Value.</p></div>';
            } elseif ($test_result === 'tin-error') {
                echo '<div class="notice notice-error is-dismissible"><p><strong>TIN Validation Test:</strong> Error occurred during validation. Please check your settings and try again.</p></div>';
            } elseif ($test_result === 'tin-missing') {
                echo '<div class="notice notice-warning is-dismissible"><p><strong>TIN Validation Test:</strong> Please fill in Seller TIN, ID Type, and ID Value before testing.</p></div>';
            }
        }
        ?>
        <div class="wrap">
            <h2>Settings</h2>
            
            <div class="card" style="max-width: 800px; padding: 15px; margin: 20px 0; border-left: 4px solid <?php echo $is_plugin_active ? '#46b450' : '#dc3232'; ?>;">
                <h3 style="margin-top: 0;">Status</h3>
                <p>
                    <strong>Current Status:</strong> 
                    <span style="color: <?php echo $is_plugin_active ? '#46b450' : '#dc3232'; ?>; font-weight: bold;">
                        <?php echo $is_plugin_active ? '✓ Active' : '✗ Inactive'; ?>
                    </span>
                </p>
                <p class="description">
                    <?php if ($is_plugin_active): ?>
                        The status is currently active. All functions including cron jobs, invoice submissions, and user profile validations are running.
                    <?php else: ?>
                        The status is currently inactive. All functions including cron jobs, invoice submissions upon billing circle, and user profile validations are on hold.
                        <br><strong>Note:</strong> You can test your API credentials (Token and TIN validation) before activating the plugin using the test buttons below.
                    <?php endif; ?>
                </p>
                <form method="post" style="margin-top: 15px;">
                    <?php if ($is_plugin_active): ?>
                        <?php wp_nonce_field('lhdn_deactivate'); ?>
                        <button type="submit" name="lhdn_deactivate" class="button button-secondary" 
                                onclick="return confirm('Are you sure you want to deactivate? This will stop all cron jobs, invoice submissions, and user profile validations.');">
                            Deactivate
                        </button>
                    <?php else: ?>
                        <?php
                        $token_test_result = LHDN_Settings::get('lhdn_test_token_result', null);
                        $tin_test_result = LHDN_Settings::get('lhdn_test_tin_result', null);
                        $token_failed = ($token_test_result === 'error' || $token_test_result === 'failed');
                        $tin_failed = ($tin_test_result === 'invalid' || $tin_test_result === 'error' || $tin_test_result === 'failed' || $tin_test_result === 'missing');
                        $token_passed = ($token_test_result === 'success');
                        $tin_passed = ($tin_test_result === 'valid');
                        $both_failed = ($token_test_result !== null && $tin_test_result !== null && $token_failed && $tin_failed);
                        $both_passed = ($token_passed && $tin_passed);
                        $tests_not_run = ($token_test_result === null || $tin_test_result === null);
                        ?>
                        <?php wp_nonce_field('lhdn_activate_plugin'); ?>
                        <?php if ($tests_not_run): ?>
                            <div style="background: #fff3cd; border: 1px solid #ffc107; padding: 10px; margin-bottom: 10px; border-radius: 4px;">
                                <strong style="color: #856404;">⚠ Warning:</strong> Please run both API tests (Get Token and Validate TIN) before activating the plugin.
                            </div>
                            <button type="submit" name="lhdn_activate_plugin" class="button button-primary" disabled style="opacity: 0.6; cursor: not-allowed;" title="Cannot activate: Both API tests must be run first">
                                Activate (Tests Required)
                            </button>
                        <?php elseif ($both_failed): ?>
                            <div style="background: #fff3cd; border: 1px solid #ffc107; padding: 10px; margin-bottom: 10px; border-radius: 4px;">
                                <strong style="color: #856404;">⚠ Warning:</strong> Both API tests have failed. Please fix your API credentials and test again before activating.
                            </div>
                            <button type="submit" name="lhdn_activate_plugin" class="button button-primary" disabled style="opacity: 0.6; cursor: not-allowed;" title="Cannot activate: Both API tests failed">
                                Activate (Blocked)
                            </button>
                        <?php elseif (!$both_passed): ?>
                            <div style="background: #fff3cd; border: 1px solid #ffc107; padding: 10px; margin-bottom: 10px; border-radius: 4px;">
                                <strong style="color: #856404;">⚠ Warning:</strong> Both API tests must pass before activating. <?php 
                                    if ($token_failed) echo 'Get Token test failed. ';
                                    if ($tin_failed) echo 'Validate TIN test failed. ';
                                ?>Please fix your API credentials and test again.
                            </div>
                            <button type="submit" name="lhdn_activate_plugin" class="button button-primary" disabled style="opacity: 0.6; cursor: not-allowed;" title="Cannot activate: Both API tests must pass">
                                Activate (Blocked)
                            </button>
                        <?php else: ?>
                            <button type="submit" name="lhdn_activate_plugin" class="button button-primary">
                                Activate
                            </button>
                        <?php endif; ?>
                    <?php endif; ?>
                </form>
            </div>

            <div class="card" style="max-width: 800px; padding: 15px; margin: 20px 0; border-left: 4px solid #2271b1;">
                <h3 style="margin-top: 0;">Test API Connection</h3>
                <p class="description">
                    <?php if (!$is_plugin_active): ?>
                        Test your API credentials before activating the plugin. These tests will verify your Client ID, Client Secret, and Seller TIN settings.
                        <strong>Note:</strong> Both tests must pass before you can activate the plugin.
                    <?php else: ?>
                        Test your API credentials to verify your Client ID, Client Secret, and Seller TIN settings are working correctly.
                    <?php endif; ?>
                </p>
                
                <?php
                $token_test_result = LHDN_Settings::get('lhdn_test_token_result', null);
                $tin_test_result = LHDN_Settings::get('lhdn_test_tin_result', null);
                ?>
                
                <div style="margin: 15px 0; padding: 10px; background: #f9f9f9; border-radius: 4px;">
                    <strong>Test Status:</strong><br>
                    <span style="margin-right: 20px;">
                        <strong>Get Token:</strong> 
                        <?php if ($token_test_result === null): ?>
                            <span style="color: #666;">Not tested</span>
                        <?php elseif ($token_test_result === 'success'): ?>
                            <span style="color: #46b450;">✓ Passed</span>
                        <?php else: ?>
                            <span style="color: #dc3232;">✗ Failed</span>
                        <?php endif; ?>
                    </span>
                    <span>
                        <strong>Validate TIN:</strong> 
                        <?php if ($tin_test_result === null): ?>
                            <span style="color: #666;">Not tested</span>
                        <?php elseif ($tin_test_result === 'valid'): ?>
                            <span style="color: #46b450;">✓ Passed</span>
                        <?php else: ?>
                            <span style="color: #dc3232;">✗ Failed</span>
                        <?php endif; ?>
                    </span>
                </div>
                
                <form method="post" style="margin-top: 15px;">
                    <?php wp_nonce_field('lhdn_test_token'); ?>
                    <button type="submit" name="lhdn_test_token" class="button">
                        Get Token
                    </button>
                    <span class="description" style="margin-left: 10px;">Test OAuth token retrieval using Client ID , Client Secret 1 or Client Secret 2</span>
                </form>
                <form method="post" style="margin-top: 15px;">
                    <?php wp_nonce_field('lhdn_test_validate_tin'); ?>
                    <button type="submit" name="lhdn_test_validate_tin" class="button">
                        Validate TIN
                    </button>
                    <span class="description" style="margin-left: 10px;">Test TIN validation using Seller TIN, ID Type, and ID Value</span>
                </form>
            </div>
            
            <form method="post">
                <?php wp_nonce_field('lhdn_settings_save'); ?>
                <table class="form-table">
                    <tr>
                        <th><label for="lhdn_environment">Environment</label></th>
                        <td>
                            <select name="lhdn[environment]" id="lhdn_environment">
                                <option value="sandbox" <?php echo selected($current_environment, 'sandbox', false); ?>>
                                    Sandbox (Pre-Production)
                                </option>
                                <option value="production" <?php echo selected($current_environment, 'production', false); ?>>
                                    Production
                                </option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th>API Host</th>
                        <td>
                            <input type="text"
                                   value="<?php echo esc_attr($current_api_host); ?>"
                                   class="regular-text"
                                   readonly
                                   style="background-color: #f0f0f0;">
                            <p class="description">Automatically updated based on environment selection</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Portal Host</th>
                        <td>
                            <input type="text"
                                   value="<?php echo esc_attr($current_portal_host); ?>"
                                   class="regular-text"
                                   readonly
                                   style="background-color: #f0f0f0;">
                            <p class="description">Automatically updated based on environment selection</p>
                        </td>
                    </tr>
                    <?php foreach ($fields as $key => $label): ?>
                        <?php if (in_array($key, ['host', 'api_host'])) continue; ?>
                        <tr>
                            <th><?php echo esc_html($label); ?></th>
                            <td>
                            <?php if ($key === 'ubl_version'): ?>
                                <?php
                                    $ubl_versions = [
                                        '1.0' => 'UBL 1.0 (No Digital Signature)',
                                        '1.1' => 'UBL 1.1 (With Digital Signature)',
                                    ];
                                    $current = LHDN_Settings::get('ubl_version', '1.0');
                                    $has_pem = !empty(LHDN_MyInvoice_Plugin::get_pem_from_database());
                                ?>
                                <select name="lhdn[ubl_version]" id="lhdn_ubl_version" <?php echo !$has_pem && $current !== '1.1' ? '' : ''; ?>>
                                    <option value="1.0" <?php echo selected($current, '1.0', false); ?>>
                                        UBL 1.0 (No Digital Signature)
                                    </option>
                                    <option value="1.1" <?php echo selected($current, '1.1', false); ?> <?php echo !$has_pem ? 'disabled' : ''; ?>>
                                        UBL 1.1 (With Digital Signature)<?php echo !$has_pem ? ' - Certificate Required' : ''; ?>
                                    </option>
                                </select>
                                <?php if (!$has_pem): ?>
                                    <p class="description" style="color: #d63638;">
                                        <strong>UBL 1.1 requires a valid PEM certificate.</strong> Please upload a certificate below to enable UBL 1.1.
                                    </p>
                                <?php else: ?>
                                    <p class="description">UBL 1.1 is available. Certificate is active and ready for digital signatures.</p>
                                <?php endif; ?>
                            <?php elseif ($key === 'billing_circle'): ?>
                                <?php
                                    $billing_circles = [
                                        'on_completed' => 'On Completed Order',
                                        'on_processed' => 'On Processed Order',
                                        'after_1_day' => 'After 1 Day',
                                        'after_2_days' => 'After 2 Days',
                                        'after_3_days' => 'After 3 Days',
                                        'after_4_days' => 'After 4 Days',
                                        'after_5_days' => 'After 5 Days',
                                        'after_6_days' => 'After 6 Days',
                                        'after_7_days' => 'After 7 Days',
                                    ];
                                    $current = LHDN_Settings::get('billing_circle', 'on_completed');
                                ?>
                                <select name="lhdn[billing_circle]">
                                    <?php foreach ($billing_circles as $value => $label): ?>
                                        <option value="<?php echo esc_attr($value); ?>"
                                            <?php echo selected($current, $value, false); ?>>
                                            <?php echo esc_html($label); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description">When to submit invoices to LHDN MyInvoice</p>
                            <?php elseif ($key === 'tax_category_id'): ?>
                                <?php
                                    $tax_categories = LHDN_Helpers::get_tax_category_options();
                                    $current = LHDN_Settings::get('tax_category_id', 'E');
                                ?>
                                <select name="lhdn[tax_category_id]">
                                    <?php foreach ($tax_categories as $code => $description): ?>
                                        <option value="<?php echo esc_attr($code); ?>"
                                            <?php echo selected($current, $code, false); ?>>
                                            <?php echo esc_html($code); ?> - <?php echo esc_html($description); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            <?php elseif ($key === 'industry_classification_code'): ?>
                                <?php
                                    $msic_codes = LHDN_Helpers::get_msic_codes_array();
                                    $current = LHDN_Settings::get('industry_classification_code', '86909');
                                ?>
                                <select name="lhdn[industry_classification_code]" style="width: 100%; max-width: 600px;">
                                    <?php foreach ($msic_codes as $code => $description): ?>
                                        <option value="<?php echo esc_attr($code); ?>"
                                            <?php echo selected($current, $code, false); ?>>
                                            <?php echo esc_html($code); ?> - <?php echo esc_html($description); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description">
                                    MSIC (Malaysian Standard Industrial Classification) code for your business activity.
                                    <a href="https://sdk.myinvois.hasil.gov.my/codes/msic-codes/" target="_blank">View full list</a>
                                </p>
                            <?php elseif ($key === 'seller_state'): ?>
                                <?php
                                    $states  = LHDN_Helpers::get_state_options();
                                    $current = LHDN_Settings::get('seller_state', 'SL');
                                ?>
                                <select name="lhdn[seller_state]">
                                    <?php foreach ($states as $code => $state): ?>
                                        <option value="<?php echo esc_attr($code); ?>"
                                            <?php echo selected($current, $code, false); ?>>
                                            <?php echo esc_html($state['label']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                              <?php elseif ($key === 'seller_id_type'):
                                  $id_type = LHDN_Helpers::get_seller_id_type_options();
                                  $current = LHDN_Settings::get('seller_id_type', 'BRN');
                              ?>
                                <select name="lhdn[seller_id_type]">
                                    <?php foreach ($id_type as $value => $label): ?>
                                        <option value="<?php echo esc_attr($value); ?>"
                                            <?php echo selected($current, $value, false); ?>>
                                            <?php echo esc_html($label); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <?php elseif ($key === 'debug_enabled'): ?>
                                    <label>
                                        <input type="checkbox"
                                               name="lhdn[debug_enabled]"
                                               value="1"
                                               <?php echo checked(LHDN_Settings::get('debug_enabled', '0'), '1', false); ?>>
                                        Enable live debug logging
                                    </label>
                                <?php elseif ($key === 'show_tin_badge'): ?>
                                    <label>
                                        <input type="checkbox"
                                               name="lhdn[show_tin_badge]"
                                               value="1"
                                               <?php echo checked(LHDN_Settings::get('show_tin_badge', '1'), '1', false); ?>>
                                        Show TIN status badge during checkout
                                    </label>
                                <?php elseif ($key === 'show_receipt'): ?>
                                    <label>
                                        <input type="checkbox"
                                               name="lhdn[show_receipt]"
                                               value="1"
                                               <?php echo checked(LHDN_Settings::get('show_receipt', '1'), '1', false); ?>>
                                        Show MyInvois Receipt column in My Account orders
                                    </label>
                                <?php elseif ($key === 'exclude_wallet'): ?>
                                    <label>
                                        <input type="checkbox"
                                               name="lhdn[exclude_wallet]"
                                               value="1"
                                               <?php echo checked(LHDN_Settings::get('exclude_wallet', '1'), '1', false); ?>>
                                        Exclude orders with wallet payment method from LHDN submission
                                    </label>
                                <?php elseif ($key === 'tin_enforce'): ?>
                                    <label>
                                        <input type="checkbox"
                                               name="lhdn[tin_enforce]"
                                               value="1"
                                               <?php echo checked(LHDN_Settings::get('tin_enforce', '0'), '1', false); ?>>
                                        Require valid TIN verification before checkout
                                    </label>
                                    <p class="description">When enabled, customers must complete TIN verification in their profile before they can checkout.</p>
                                <?php elseif ($key === 'custom_order_statuses'): ?>
                                    <textarea name="lhdn[custom_order_statuses]"
                                              rows="3"
                                              class="regular-text"
                                              placeholder="custom-status1, custom-status2"><?php echo esc_textarea(LHDN_Settings::get('custom_order_statuses', '')); ?></textarea>
                                    <p class="description">
                                        Enter custom WooCommerce order status slugs (comma-separated). These statuses will be treated like "completed" status.<br>
                                        <strong>Format requirements:</strong> Must start with a letter, lowercase only, can contain letters, numbers, hyphens (-), and underscores (_), max 20 characters.<br>
                                        Example: <code>custom-status1</code>, <code>custom-status2</code>
                                    </p>
                                    <?php settings_errors('lhdn_custom_order_statuses'); ?>
                                <?php elseif ($key === 'seller_country'): ?>
                                    <input type="text"
                                           name="lhdn[seller_country]"
                                           value="MYS"
                                           class="regular-text"
                                           readonly
                                           style="background-color: #f0f0f0;">
                                    <p class="description">Country is fixed to Malaysia (MYS)</p>
                                <?php else: ?>
                                    <input type="text"
                                           name="lhdn[<?php echo esc_attr($key); ?>]"
                                           value="<?php echo esc_attr(LHDN_Settings::get($key)); ?>"
                                           class="regular-text">
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </table>

                <?php
                $last_sync  = get_option('lhdn_last_sync_cron_run');
                $last_retry = get_option('lhdn_last_retry_cron_run');
                $last_delayed = get_option('lhdn_last_delayed_cron_run');
                $next       = wp_next_scheduled('lhdn_sync_submitted_invoices');

                echo '<p><strong>Last Sync Cron:</strong> ' .
                     esc_html($last_sync ? wp_date('Y-m-d H:i:s', $last_sync) : 'Never') .
                     '</p>';

                echo '<p><strong>Last Retry Cron:</strong> ' .
                     esc_html($last_retry ? wp_date('Y-m-d H:i:s', $last_retry) : 'Never') .
                     '</p>';

                echo '<p><strong>Last Delayed Invoice Cron:</strong> ' .
                     esc_html($last_delayed ? wp_date('Y-m-d H:i:s', $last_delayed) : 'Never') .
                     '</p>';

                ?>
                <p>
                    <button class="button button-primary" name="save_lhdn_settings">
                        Save Settings
                    </button>
                </p>
            </form>

            <hr>

            <h2>PEM Certificate Management</h2>
            <p class="description">Upload a PEM certificate file for UBL 1.1 digital signatures. The certificate details will be automatically extracted and stored.</p>
            
            <?php
            global $wpdb;
            $cert_table = $wpdb->prefix . 'lhdn_cert';
            $current_cert = null;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            if ($wpdb->get_var("SHOW TABLES LIKE '{$cert_table}'") === $cert_table) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $current_cert = $wpdb->get_row(
                    "SELECT * FROM {$cert_table} WHERE is_active = 1 ORDER BY created_at DESC LIMIT 1"
                );
            }
            ?>

            <?php if ($current_cert): ?>
                <div class="card" style="max-width: 800px; padding: 15px; margin: 20px 0;">
                    <h3>Current Active Certificate</h3>
                    <table class="form-table">
                        <tr>
                            <th>Organization</th>
                            <td><?php echo esc_html($current_cert->organization ?: 'N/A'); ?></td>
                        </tr>
                        <tr>
                            <th>Organization Identifier</th>
                            <td><?php echo esc_html($current_cert->organization_identifier ?: 'N/A') ?></td>
                        </tr>
                        <tr>
                            <th>CN (Common Name)</th>
                            <td><?php echo esc_html($current_cert->cn ?: 'N/A') ?></td>
                        </tr>
                        <tr>
                            <th>Serial Number</th>
                            <td><?php echo esc_html($current_cert->serial_number ?: 'N/A') ?></td>
                        </tr>
                        <tr>
                            <th>Email Address</th>
                            <td><?php echo esc_html($current_cert->email_address ?: 'N/A') ?></td>
                        </tr>
                        <tr>
                            <th>Issuer</th>
                            <td><?php echo esc_html($current_cert->issuer ?: 'N/A') ?></td>
                        </tr>
                        <tr>
                            <th>Valid From</th>
                            <td><?php echo $current_cert->valid_from ? esc_html(wp_date('Y-m-d H:i:s', strtotime($current_cert->valid_from))) : 'N/A'; ?></td>
                        </tr>
                        <tr>
                            <th>Valid To</th>
                            <td><?php echo $current_cert->valid_to ? esc_html(wp_date('Y-m-d H:i:s', strtotime($current_cert->valid_to))) : 'N/A'; ?></td>
                        </tr>
                        <tr>
                            <th>Uploaded</th>
                            <td><?php echo $current_cert->created_at ? esc_html(wp_date('Y-m-d H:i:s', strtotime($current_cert->created_at))) : 'N/A'; ?></td>
                        </tr>
                    </table>
                </div>
            <?php else: ?>
                <div class="notice notice-warning inline">
                    <p>No active certificate found. Please upload a PEM certificate for UBL 1.1 digital signatures.</p>
                </div>
            <?php endif; ?>

            <form method="post" enctype="multipart/form-data">
                <?php wp_nonce_field('lhdn_upload_pem'); ?>
                <table class="form-table">
                    <tr>
                        <th><label for="pem_file">Upload PEM Certificate</th>
                        <td>
                            <input type="file" name="pem_file" id="pem_file" accept=".pem" required>
                            <p class="description">Select a PEM format certificate file (.pem only)</p>
                        </td>
                    </tr>
                </table>
                <p>
                    <button type="submit" class="button button-primary" name="upload_pem_cert">
                        Upload Certificate
                    </button>
                    <?php if ($current_cert): ?>
                        <button type="button" class="button button-secondary" 
                                onclick="document.getElementById('reset_cert_form').submit(); return false;"
                                style="margin-left: 10px;">
                            Reset Certificate
                        </button>
                    <?php endif; ?>
                </p>
            </form>

            <?php if ($current_cert): ?>
                <form method="post" id="reset_cert_form" style="display: none;">
                    <?php wp_nonce_field('lhdn_reset_cert'); ?>
                    <input type="hidden" name="reset_certificate" value="1">
                </form>
                <script>
                document.addEventListener('DOMContentLoaded', function() {
                    var resetBtn = document.querySelector('button[onclick*="reset_cert_form"]');
                    if (resetBtn) {
                        resetBtn.onclick = function() {
                            if (confirm('Are you sure you want to reset the certificate? This will delete all PEM certificate records and set UBL version back to 1.0.')) {
                                document.getElementById('reset_cert_form').submit();
                            }
                            return false;
                        };
                    }
                });
                </script>
            <?php endif; ?>

            <hr>

            <h2>Database Management</h2>
            <p class="description">Manage the invoice database: clear all records, backup to CSV, or restore from backup.</p>
            
            <?php
            global $wpdb;
            $table = $wpdb->prefix . 'lhdn_myinvoice';
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $record_count = $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
            ?>
            
            <div class="card" style="max-width: 800px; padding: 15px; margin: 20px 0;">
                <h3>Database Information</h3>
                <p><strong>Total Records:</strong> <?php echo esc_html(number_format($record_count)); ?></p>
                <p class="description">Current number of invoice records in the database.</p>
                
                <?php
                // Validate database structure
                $validation = LHDN_Database::validate_database_structure();
                $is_valid = $validation['valid'];
                $issues = $validation['issues'];
                ?>
                
                <div style="margin-top: 20px; padding-top: 20px; border-top: 1px solid #ddd;">
                    <h4>Database Structure Validation</h4>
                    <?php if ($is_valid): ?>
                        <p style="color: #46b450; font-weight: bold;">
                            ✓ All tables and columns are valid
                        </p>
                        <p class="description">All required database tables and columns exist and are properly configured.</p>
                    <?php else: ?>
                        <p style="color: #dc3232; font-weight: bold;">
                            ✗ Database structure issues found
                        </p>
                        <p class="description">The following issues were detected:</p>
                        <ul style="margin-left: 20px; color: #dc3232;">
                            <?php foreach ($issues as $issue): ?>
                                <li><?php echo esc_html($issue['message']); ?></li>
                            <?php endforeach; ?>
                        </ul>
                        <p class="description">
                            <strong>Solution:</strong> Click the button below to fix the database structure automatically.
                        </p>
                        <form method="post" style="margin-top: 10px;">
                            <?php wp_nonce_field('lhdn_fix_database'); ?>
                            <button type="submit" name="fix_database_structure" class="button button-primary">
                                Fix Database Structure
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card" style="max-width: 800px; padding: 15px; margin: 20px 0; border-left: 4px solid #dc3232;">
                <h3 style="margin-top: 0; color: #dc3232;">Clear Database</h3>
                <p class="description">
                    <strong>Warning:</strong> This will permanently delete ALL invoice records from the database. This action cannot be undone. 
                    Make sure you have a backup before proceeding.
                </p>
                <form method="post" id="clear_database_form">
                    <?php wp_nonce_field('lhdn_clear_database'); ?>
                    <input type="hidden" name="clear_database" value="1">
                    <button type="submit" class="button button-secondary" 
                            onclick="return confirm('WARNING: This will permanently delete ALL invoice records!\n\nAre you sure you want to proceed?\n\nType OK in the next prompt to confirm.');">
                        Clear Database
                    </button>
                </form>
                <script>
                document.addEventListener('DOMContentLoaded', function() {
                    var clearForm = document.getElementById('clear_database_form');
                    if (clearForm) {
                        clearForm.addEventListener('submit', function(e) {
                            var recordCount = <?php echo (int)$record_count; ?>;
                            var confirmation = prompt('FINAL WARNING: This will DELETE ALL ' + recordCount + ' invoice records!\n\nType "DELETE ALL" (in uppercase) to confirm:');
                            if (confirmation !== 'DELETE ALL') {
                                e.preventDefault();
                                alert('Database clear cancelled.');
                                return false;
                            }
                        });
                    }
                });
                </script>
            </div>

            <div class="card" style="max-width: 800px; padding: 15px; margin: 20px 0; border-left: 4px solid #2271b1;">
                <h3 style="margin-top: 0;">Backup Database</h3>
                <p class="description">Export all invoice records to a CSV file for backup purposes.</p>
                <form method="post">
                    <?php wp_nonce_field('lhdn_backup_database'); ?>
                    <input type="hidden" name="backup_database" value="1">
                    <button type="submit" class="button button-primary">
                        Export to CSV
                    </button>
                </form>
            </div>

            <div class="card" style="max-width: 800px; padding: 15px; margin: 20px 0; border-left: 4px solid #46b450;">
                <h3 style="margin-top: 0;">Restore Database</h3>
                <p class="description">
                    Import invoice records from a previously exported CSV backup file. 
                    The CSV file will be validated before import to ensure it matches the correct format.
                </p>
                <form method="post" enctype="multipart/form-data">
                    <?php wp_nonce_field('lhdn_restore_database'); ?>
                    <table class="form-table">
                        <tr>
                            <th><label for="backup_file">Upload CSV Backup File</label></th>
                            <td>
                                <input type="file" name="backup_file" id="backup_file" accept=".csv" required>
                                <p class="description">Select a CSV file that was previously exported from this system.</p>
                            </td>
                        </tr>
                    </table>
                    <p>
                        <button type="submit" class="button button-primary" name="restore_database">
                            Restore from CSV
                        </button>
                    </p>
                </form>
            </div>
        </div>
        <?php
    }

    /**
     * Save and parse PEM certificate
     */
    private function save_pem_certificate($pem_content) {
        global $wpdb;
        $table = $wpdb->prefix . 'lhdn_cert';
        
        // Validate PEM content
        if (empty($pem_content) || strpos($pem_content, '-----BEGIN') === false) {
            return false;
        }
        
        // Parse certificate
        $cert_data = $this->parse_pem_certificate($pem_content);
        
        if (!$cert_data) {
            return false;
        }
        
        // Deactivate all existing certificates
        $wpdb->update(
            $table,
            ['is_active' => 0],
            ['is_active' => 1]
        );
        
        // Insert new certificate
        $result = $wpdb->insert(
            $table,
            [
                'pem_content' => $pem_content,
                'organization' => $cert_data['organization'] ?? '',
                'organization_identifier' => $cert_data['organization_identifier'] ?? '',
                'cn' => $cert_data['cn'] ?? '',
                'serial_number' => $cert_data['serial_number'] ?? '',
                'email_address' => $cert_data['email_address'] ?? '',
                'issuer' => $cert_data['issuer'] ?? '',
                'valid_from' => $cert_data['valid_from'] ?? null,
                'valid_to' => $cert_data['valid_to'] ?? null,
                'is_active' => 1,
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            ]
        );
        
        return $result !== false;
    }

    /**
     * Parse PEM certificate and extract details
     */
    private function parse_pem_certificate($pem_content) {
        // Try to parse using OpenSSL
        if (!function_exists('openssl_x509_read')) {
            LHDN_Logger::log('OpenSSL extension not available for certificate parsing');
            return false;
        }
        
        $cert = @openssl_x509_read($pem_content);
        if (!$cert) {
            LHDN_Logger::log('Failed to parse PEM certificate: ' . openssl_error_string());
            return false;
        }
        
        $cert_info = @openssl_x509_parse($cert);
        if (!$cert_info) {
            return false;
        }
        
        $data = [];
        
        // Extract subject information
        $subject = $cert_info['subject'] ?? [];
        $data['cn'] = $subject['CN'] ?? '';
        $data['organization'] = $subject['O'] ?? '';
        $data['organization_identifier'] = $subject['organizationIdentifier'] ?? $subject['OU'] ?? '';
        $data['email_address'] = $subject['emailAddress'] ?? '';
        
        // Extract serial number
        if (isset($cert_info['serialNumber'])) {
            $serial = $cert_info['serialNumber'];
            // Handle both string and integer serial numbers
            if (is_string($serial)) {
                // If it's already a hex string, use it directly
                if (ctype_xdigit($serial)) {
                    $data['serial_number'] = strtoupper($serial);
                } else {
                    // Try to convert if it's numeric string
                    $data['serial_number'] = is_numeric($serial) ? strtoupper(dechex((int)$serial)) : $serial;
                }
            } else {
                // Integer serial number
                $data['serial_number'] = strtoupper(dechex((int)$serial));
            }
        } else {
            $data['serial_number'] = '';
        }
        
        // Extract issuer
        $issuer = $cert_info['issuer'] ?? [];
        $issuer_parts = [];
        if (!empty($issuer['CN'])) $issuer_parts[] = 'CN=' . $issuer['CN'];
        if (!empty($issuer['O'])) $issuer_parts[] = 'O=' . $issuer['O'];
        if (!empty($issuer['C'])) $issuer_parts[] = 'C=' . $issuer['C'];
        $data['issuer'] = implode(', ', $issuer_parts);
        
        // Extract validity dates
        if (isset($cert_info['validFrom_time_t'])) {
            $data['valid_from'] = wp_date('Y-m-d H:i:s', $cert_info['validFrom_time_t']);
        }
        if (isset($cert_info['validTo_time_t'])) {
            $data['valid_to'] = wp_date('Y-m-d H:i:s', $cert_info['validTo_time_t']);
        }
        
        return $data;
    }

    /**
     * Reset certificate (delete all PEM records and set UBL version to 1.0)
     */
    private function reset_certificate() {
        global $wpdb;
        $table = $wpdb->prefix . 'lhdn_cert';
        
        // Check if table exists
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
            return false;
        }
        
        // Delete all certificate records
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $result = $wpdb->query("DELETE FROM {$table}");
        
        // Set UBL version back to 1.0
        LHDN_Settings::set('ubl_version', '1.0');
        
        return $result !== false;
    }

    /**
     * Clear database (truncate lhdn_myinvoice table)
     */
    private function clear_database() {
        global $wpdb;
        $table = $wpdb->prefix . 'lhdn_myinvoice';
        
        // Check if table exists
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
            return false;
        }
        
        // Truncate table (faster than DELETE and resets AUTO_INCREMENT)
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $result = $wpdb->query("TRUNCATE TABLE {$table}");
        
        return $result !== false;
    }

    /**
     * Export database to CSV
     */
    private function export_database_to_csv() {
        global $wpdb;
        $table = $wpdb->prefix . 'lhdn_myinvoice';
        
        // Check if table exists
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
            wp_die('Database table does not exist.');
        }
        
        // Get all records
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $records = $wpdb->get_results("SELECT * FROM {$table}", ARRAY_A);
        
        if (empty($records)) {
            wp_die('No records found to export.');
        }
        
        // Get column names and reorder to put order_id right after invoice_no
        $columns = array_keys($records[0]);
        
        // Define desired column order (order_id right after invoice_no)
        $desired_order = ['id', 'invoice_no', 'order_id'];
        $ordered_columns = [];
        
        // Add columns in desired order first
        foreach ($desired_order as $col) {
            if (in_array($col, $columns)) {
                $ordered_columns[] = $col;
            }
        }
        
        // Add remaining columns (excluding those already added)
        foreach ($columns as $col) {
            if (!in_array($col, $desired_order)) {
                $ordered_columns[] = $col;
            }
        }
        
        // Clear all output buffers to prevent HTML from being sent
        while (ob_get_level()) {
            ob_end_clean();
        }
        
        // Set headers for CSV download (must be before any output)
        $filename = 'lhdn_myinvoice_backup_' . gmdate('Y-m-d_His') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');
        header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
        
        // Open output stream (php://output cannot use WP_Filesystem)
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        $output = fopen('php://output', 'w');
        
        // Add BOM for UTF-8 (helps Excel recognize encoding)
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        
        // Write column headers
        fputcsv($output, $ordered_columns);
        
        // Write data rows (reordered to match column order)
        foreach ($records as $row) {
            $ordered_row = [];
            foreach ($ordered_columns as $col) {
                $ordered_row[$col] = isset($row[$col]) ? $row[$col] : '';
            }
            fputcsv($output, array_values($ordered_row));
        }
        
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        fclose($output);
        exit;
    }

    /**
     * Import database from CSV
     */
    private function import_database_from_csv($file) {
        global $wpdb;
        $table = $wpdb->prefix . 'lhdn_myinvoice';
        
        // Check if table exists
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
            return ['success' => false, 'message' => 'Database table does not exist.'];
        }
        
        // Validate file extension
        $file_name = isset($file['name']) ? sanitize_file_name(wp_unslash($file['name'])) : '';
        $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
        
        if ($file_ext !== 'csv') {
            return ['success' => false, 'message' => 'Invalid file type. Only CSV files are accepted.'];
        }
        
        // Open and read CSV file (tmp_name from uploaded file - cannot use WP_Filesystem)
        $tmp_name = isset($file['tmp_name']) ? $file['tmp_name'] : '';
        if (empty($tmp_name) || !is_uploaded_file($tmp_name)) {
            return ['success' => false, 'message' => 'Invalid file upload.'];
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        $handle = fopen($tmp_name, 'r');
        if ($handle === false) {
            return ['success' => false, 'message' => 'Failed to open uploaded file.'];
        }
        
        // Read first line (headers)
        $headers = fgetcsv($handle);
        if ($headers === false) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
            fclose($handle);
            return ['success' => false, 'message' => 'CSV file appears to be empty or invalid.'];
        }
        
        // Remove BOM if present
        $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', $headers[0]);
        
        // Expected columns (based on table structure)
        // Note: order_id is optional (may not exist in old backups)
        $expected_columns = [
            'id', 'invoice_no', 'order_id', 'uuid', 'longid', 'item_class', 'document_hash',
            'payload', 'status', 'code', 'response', 'retry_count', 'queue_status',
            'queue_date', 'created_at', 'updated_at'
        ];
        
        // Make order_id optional for backward compatibility with old backups
        $required_columns = [
            'id', 'invoice_no', 'uuid', 'longid', 'item_class', 'document_hash',
            'payload', 'status', 'code', 'response', 'retry_count', 'queue_status',
            'queue_date', 'created_at', 'updated_at'
        ];
        
        // Validate column headers
        $headers_trimmed = array_map('trim', $headers);
        $missing_columns = array_diff($required_columns, $headers_trimmed);
        $extra_columns = array_diff($headers_trimmed, $expected_columns);
        
        if (!empty($missing_columns)) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
            fclose($handle);
            return [
                'success' => false,
                'message' => 'CSV format error: Missing required columns: ' . implode(', ', $missing_columns)
            ];
        }
        
        if (!empty($extra_columns)) {
            // Extra columns are okay, we'll just ignore them
        }
        
        // Create column mapping (handle case-insensitive matching)
        $column_map = [];
        foreach ($expected_columns as $col) {
            foreach ($headers_trimmed as $idx => $header) {
                if (strtolower(trim($header)) === strtolower($col)) {
                    $column_map[$col] = $idx;
                    break;
                }
            }
        }
        
        // Validate all required columns are mapped (order_id is optional)
        $mapped_required = array_intersect_key($column_map, array_flip($required_columns));
        if (count($mapped_required) !== count($required_columns)) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
            fclose($handle);
            return [
                'success' => false,
                'message' => 'CSV format error: Could not map all required columns. Please check the CSV format.'
            ];
        }
        
        // Start transaction for data integrity
        $wpdb->query('START TRANSACTION');
        
        try {
            $imported = 0;
            $skipped = 0;
            $line_number = 1; // Start at 1 (header is line 1)
            
            // Read and import data rows
            while (($row = fgetcsv($handle)) !== false) {
                $line_number++;
                
                // Skip empty rows
                if (empty(array_filter($row))) {
                    continue;
                }
                
                // Map row data to columns
                $data = [];
                foreach ($expected_columns as $col) {
                    // Skip if column not in map (optional columns like order_id in old backups)
                    if (!isset($column_map[$col])) {
                        continue;
                    }
                    $idx = $column_map[$col];
                    $value = isset($row[$idx]) ? $row[$idx] : '';
                    
                    // Handle NULL values
                    if (strtoupper(trim($value)) === 'NULL' || $value === '') {
                        $data[$col] = null;
                    } else {
                        $data[$col] = $value;
                    }
                }
                
                // Skip id column for insert (will auto-increment)
                unset($data['id']);
                
                // Validate required fields
                if (empty($data['invoice_no'])) {
                    $skipped++;
                    continue;
                }
                
                // Use REPLACE to handle duplicates (based on invoice_no unique constraint)
                $result = $wpdb->replace($table, $data);
                
                if ($result !== false) {
                    $imported++;
                } else {
                    $skipped++;
                }
            }
            
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
            fclose($handle);
            
            // Commit transaction
            $wpdb->query('COMMIT');
            
            $message = sprintf(
                'Database restored successfully. Imported %d records%s.',
                $imported,
                $skipped > 0 ? ', skipped ' . $skipped . ' invalid records' : ''
            );
            
            return ['success' => true, 'message' => $message];
            
        } catch (Exception $e) {
            // Rollback on error
            $wpdb->query('ROLLBACK');
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
            fclose($handle);
            
            return [
                'success' => false,
                'message' => 'Error importing data: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Admin page
     */
    public function admin_page() {
        if (isset($_POST['submit_test'])) {
            check_admin_referer('lhdn_submit_test_action', 'lhdn_submit_test_nonce');
            $this->invoice->submit_test();
        }

        if (isset($_POST['clear_logs'])) {
            check_admin_referer('lhdn_clear_logs_action', 'lhdn_clear_logs_nonce');
            delete_option('lhdn_logs');
        }

        if (isset($_POST['refresh_token'])) {
            check_admin_referer('lhdn_refresh_token_action', 'lhdn_refresh_token_nonce');
            $token = $this->api->get_token(true);
            if ($token) {
                LHDN_Logger::log('Token refreshed successfully via admin button');
            } else {
                LHDN_Logger::log('Token refresh failed via admin button');
            }
        }

        if (isset($_POST['cancel_uuid'])) {
            check_admin_referer('lhdn_cancel_action', 'lhdn_cancel_nonce');
            $this->invoice->cancel(sanitize_text_field(wp_unslash($_POST['cancel_uuid'])));
        }

        if (isset($_POST['sync_uuid'])) {
            check_admin_referer('lhdn_sync_action', 'lhdn_sync_nonce');
            $this->invoice->sync_status(sanitize_text_field(wp_unslash($_POST['sync_uuid'])));
        }

        if (isset($_POST['resubmit_invoice_no'])) {
            check_admin_referer('lhdn_resubmit_action', 'lhdn_resubmit_nonce');

            $invoiceNo = sanitize_text_field(wp_unslash($_POST['resubmit_invoice_no']));
            $isTest    = !empty($_POST['is_test_invoice']);

            if ($isTest || str_starts_with($invoiceNo, 'TEST-')) {
                LHDN_Logger::log("Resubmitting TEST invoice {$invoiceNo}");
                $this->invoice->resubmit_test($invoiceNo);
            } else {
                LHDN_Logger::log("Resubmitting WC invoice {$invoiceNo}");
                $this->invoice->resubmit_wc_order($invoiceNo);
            }
        }

        if (isset($_POST['delete_invoice_id'])) {
            check_admin_referer('lhdn_delete_invoice_action', 'lhdn_delete_invoice_nonce');
            
            $invoice_id = isset($_POST['delete_invoice_id']) ? absint(wp_unslash($_POST['delete_invoice_id'])) : 0;
            if ($invoice_id > 0) {
                global $wpdb;
                $table = $wpdb->prefix . 'lhdn_myinvoice';
                
                // Get invoice details for logging
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name from $wpdb->prefix is safe
                $invoice = $wpdb->get_row($wpdb->prepare("SELECT invoice_no, status FROM {$table} WHERE id = %d", $invoice_id));
                
                if ($invoice) {
                    // Only allow deletion of failed or cancelled invoices
                    if (in_array($invoice->status, ['retry', 'failed', 'invalid', 'cancelled'], true)) {
                        $result = $wpdb->delete($table, ['id' => $invoice_id], ['%d']);
                        
                        if ($result !== false) {
                            LHDN_Logger::log("Deleted invoice record: {$invoice->invoice_no} (ID: {$invoice_id}, Status: {$invoice->status})");
                            echo '<div class="notice notice-success is-dismissible"><p>Invoice record deleted successfully.</p></div>';
                        } else {
                            echo '<div class="notice notice-error is-dismissible"><p>Failed to delete invoice record.</p></div>';
                        }
                    } else {
                        echo '<div class="notice notice-error is-dismissible"><p>Only failed invoices (retry, failed, invalid) or cancelled invoices can be deleted.</p></div>';
                    }
                } else {
                    echo '<div class="notice notice-error is-dismissible"><p>Invoice record not found.</p></div>';
                }
            }
        }
        ?>
        <div class="wrap">
            <h2>MyInvoice Sync</h2>

            <h3>Failed Invoices</h3>
            <p class="description">Invoices with status: retry, failed, or invalid</p>

            <?php
                $failed_table = new LHDN_Failed_Invoices_Table();
                $failed_table->prepare_items();
                $failed_table->search_box('Search failed invoices', 'lhdn-search-failed');
                $failed_table->display();
            ?>

            <hr style="margin: 30px 0;">

            <h3>Submitted Invoices</h3>
            <p class="description">All other invoices</p>

            <?php
                $submitted_table = new LHDN_Submitted_Invoices_Table();
                $submitted_table->prepare_items();
                $submitted_table->search_box('Search submitted invoices', 'lhdn-search-submitted');
                $submitted_table->display();
            ?>

            <?php if (LHDN_Settings::get('debug_enabled')): ?>
                <h3>Live Logs</h3>
                <div id="log" style="background:#111;color:#0f0;height:300px;overflow:auto;padding:10px;font-family:monospace"></div>
                <br />
                <span>
                  <form method="post">
                      <?php wp_nonce_field('lhdn_submit_test_action', 'lhdn_submit_test_nonce'); ?>
                      <?php wp_nonce_field('lhdn_clear_logs_action', 'lhdn_clear_logs_nonce'); ?>
                      <?php wp_nonce_field('lhdn_refresh_token_action', 'lhdn_refresh_token_nonce'); ?>

                      <button class="button button-primary" name="submit_test">Submit Test</button>
                      <button class="button" name="refresh_token">Refresh Token</button>
                      <button class="button" name="clear_logs">Clear Logs</button>
                  </form>
                </span>
                <script>
                function loadLhdnLogs() {
                    fetch(ajaxurl + '?action=lhdn_get_logs')
                        .then(r => r.json())
                        .then(d => {
                            document.getElementById('log').innerHTML = d.join('<br>');
                        });
                }

                loadLhdnLogs();
                setInterval(loadLhdnLogs, 5000);
                </script>
            <?php endif; ?>
        </div>
        <?php
    }
}

