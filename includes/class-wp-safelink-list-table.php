<?php
/**
 * SafeLink List Table Class
 * 
 * Creates a WordPress admin table to display and manage SafeLinks
 */

// Exit if accessed directly
if (!defined('WPINC')) {
    die;
}

// Load WP_List_Table if not loaded
if (!class_exists('WP_List_Table')) {
    require_once(ABSPATH . 'wp-admin/includes/class-wp-list-table.php');
}

class WP_SafeLink_List_Table extends WP_List_Table {

    /**
     * Constructor
     */
    public function __construct() {
        parent::__construct(array(
            'singular' => 'safelink',
            'plural'   => 'safelinks',
            'ajax'     => false
        ));
    }

    /**
     * Get table columns
     */
    public function get_columns() {
        return array(
            'cb'              => '<input type="checkbox" />',
            'title'           => __('Title', 'wp-safelink'),
            'destination_url' => __('Destination URL', 'wp-safelink'),
            'safelink_url'    => __('SafeLink URL', 'wp-safelink'),
            'clicks'          => __('Clicks', 'wp-safelink'),
            'created'         => __('Created', 'wp-safelink'),
            'status'          => __('Status', 'wp-safelink')
        );
    }

    /**
     * Get sortable columns
     */
    public function get_sortable_columns() {
        return array(
            'title'     => array('title', false),
            'clicks'    => array('clicks', false),
            'created'   => array('created', true),
            'status'    => array('status', false)
        );
    }

    /**
     * Get bulk actions
     */
    public function get_bulk_actions() {
        return array(
            'activate'   => __('Activate', 'wp-safelink'),
            'deactivate' => __('Deactivate', 'wp-safelink'),
            'delete'     => __('Delete', 'wp-safelink')
        );
    }

    /**
     * Process bulk actions
     */
    public function process_bulk_action() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'safelinks';
        
        // Security check
        if (isset($_POST['_wpnonce']) && !empty($_POST['_wpnonce'])) {
            $nonce = filter_input(INPUT_POST, '_wpnonce', FILTER_SANITIZE_STRING);
            
            if (!wp_verify_nonce($nonce, 'bulk-' . $this->_args['plural'])) {
                wp_die('Security check failed!');
            }
        }
        
        $action = $this->current_action();
        
        if ($action) {
            $link_ids = isset($_POST['safelink']) ? $_POST['safelink'] : array();
            
            if (!is_array($link_ids)) {
                $link_ids = array($link_ids);
            }
            
            $link_ids = array_map('intval', $link_ids);
            
            if (empty($link_ids)) {
                return;
            }
            
            // Process based on action
            switch ($action) {
                case 'activate':
                    foreach ($link_ids as $link_id) {
                        $wpdb->update(
                            $table_name,
                            array('status' => 'active'),
                            array('id' => $link_id),
                            array('%s'),
                            array('%d')
                        );
                    }
                    break;
                    
                case 'deactivate':
                    foreach ($link_ids as $link_id) {
                        $wpdb->update(
                            $table_name,
                            array('status' => 'inactive'),
                            array('id' => $link_id),
                            array('%s'),
                            array('%d')
                        );
                    }
                    break;
                    
                case 'delete':
                    foreach ($link_ids as $link_id) {
                        $wpdb->delete(
                            $table_name,
                            array('id' => $link_id),
                            array('%d')
                        );
                    }
                    break;
            }
        }
    }

    /**
     * Checkbox column
     */
    public function column_cb($item) {
        return sprintf(
            '<input type="checkbox" name="safelink[]" value="%s" />',
            $item['id']
        );
    }

    /**
     * Title column with row actions
     */
    public function column_title($item) {
        $actions = array(
            'edit'   => sprintf('<a href="%s">Edit</a>', admin_url('admin.php?page=wp-safelink-add&edit=' . $item['id'])),
            'view'   => sprintf('<a href="%s" target="_blank">View</a>', home_url('go/' . $item['slug'])),
            'delete' => sprintf('<a href="#" class="delete-link" data-id="%s">Delete</a>', $item['id'])
        );
        
        return sprintf(
            '<strong>%1$s</strong> %2$s',
            $item['title'],
            $this->row_actions($actions)
        );
    }

    /**
     * SafeLink URL column
     */
    public function column_safelink_url($item) {
        $url = home_url('go/' . $item['slug']);
        
        return sprintf(
            '<div class="safelink-url-wrapper">
                <code>%s</code>
                <button type="button" class="button button-small copy-url" data-url="%s">Copy</button>
            </div>',
            esc_url($url),
            esc_attr($url)
        );
    }

    /**
     * Default column display
     */
    public function column_default($item, $column_name) {
        switch ($column_name) {
            case 'destination_url':
                $max_length = 50;
                $url = $item[$column_name];
                $display_url = strlen($url) > $max_length ? substr($url, 0, $max_length) . '...' : $url;
                
                return sprintf(
                    '<a href="%s" target="_blank" title="%s">%s</a>',
                    esc_url($url),
                    esc_attr($url),
                    esc_html($display_url)
                );
                
            case 'clicks':
                return number_format($item[$column_name]);
                
            case 'created':
                return date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($item[$column_name]));
                
            case 'status':
                $status_class = $item[$column_name] === 'active' ? 'status-active' : 'status-inactive';
                return sprintf('<span class="status-indicator %s">%s</span>', $status_class, ucfirst($item[$column_name]));
                
            default:
                return $item[$column_name];
        }
    }

    /**
     * Prepare items
     */
    public function prepare_items() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'safelinks';
        
        // Process bulk actions
        $this->process_bulk_action();
        
        // Set column headers
        $columns = $this->get_columns();
        $hidden = array();
        $sortable = $this->get_sortable_columns();
        $this->_column_headers = array($columns, $hidden, $sortable);
        
        // Pagination settings
        $per_page = 20;
        $current_page = $this->get_pagenum();
        $offset = ($current_page - 1) * $per_page;
        
        // Search functionality
        $search = isset($_REQUEST['s']) ? sanitize_text_field($_REQUEST['s']) : '';
        $search_condition = '';
        
        if (!empty($search)) {
            $search_condition = $wpdb->prepare(
                " AND (title LIKE %s OR destination_url LIKE %s OR slug LIKE %s)",
                '%' . $wpdb->esc_like($search) . '%',
                '%' . $wpdb->esc_like($search) . '%',
                '%' . $wpdb->esc_like($search) . '%'
            );
        }
        
        // Get sorting parameters
        $orderby = isset($_REQUEST['orderby']) ? sanitize_text_field($_REQUEST['orderby']) : 'created';
        $order = isset($_REQUEST['order']) ? sanitize_text_field($_REQUEST['order']) : 'desc';
        
        // Validate and sanitize order and orderby parameters
        $valid_orderby = array('title', 'clicks', 'created', 'status');
        $valid_order = array('asc', 'desc');
        
        $orderby = in_array($orderby, $valid_orderby) ? $orderby : 'created';
        $order = in_array(strtolower($order), $valid_order) ? strtolower($order) : 'desc';
        
        // Get total items
        $total_items = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE 1=1" . $search_condition);
        
        // Get items
        $query = $wpdb->prepare(
            "SELECT * FROM $table_name WHERE 1=1 $search_condition ORDER BY $orderby $order LIMIT %d OFFSET %d",
            $per_page,
            $offset
        );
        
        $this->items = $wpdb->get_results($query, ARRAY_A);
        
        // Set pagination args
        $this->set_pagination_args(array(
            'total_items' => $total_items,
            'per_page'    => $per_page,
            'total_pages' => ceil($total_items / $per_page)
        ));
    }

    /**
     * Display no items message
     */
    public function no_items() {
        if (isset($_REQUEST['s']) && $_REQUEST['s']) {
            _e('No SafeLinks found matching your search criteria.', 'wp-safelink');
        } else {
            _e('No SafeLinks found. Create your first one!', 'wp-safelink');
        }
    }

    /**
     * Extra table navigation
     */
    public function extra_tablenav($which) {
        if ($which === 'top') {
            echo '<div class="alignleft actions">';
            // You can add filters here if needed
            echo '</div>';
        }
    }
}