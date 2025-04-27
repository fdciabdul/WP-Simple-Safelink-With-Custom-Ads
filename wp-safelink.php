<?php
/**
 * Plugin Name: Simple SafeLink with Ads
 * Plugin URI: https://github.com/fdciabdul/WP-Simple-Safelink-With-Custom-Ad
 * Description: A WordPress plugin that creates and manages safe links with AdSense integration
 * Version: 1.0.0
 * Author: Your Name
 * Author URI: https://imtaqin.id
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Plugin Icon: http://imtaqin.id/wp-content/uploads/2025/04/icon-256x256-1.png
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

class WP_SafeLink_AdSense {

    /**
     * Initialize the plugin
     */
    public function __construct() {
        // Define constants
        $this->define_constants();
        
        // Register activation and deactivation hooks
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
        
        // Add shortcode for safelinks
        add_shortcode('safelink', array($this, 'safelink_shortcode'));
        
        // Register admin menus
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        
        // Add redirect template
        add_action('template_redirect', array($this, 'handle_redirect'));
        
        // Add custom rewrite rules
        add_action('init', array($this, 'add_rewrite_rules'));
        
        // Enqueue scripts and styles
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('admin_enqueue_scripts', array($this, 'admin_enqueue_scripts'));
        
        // AJAX handlers for link management
        add_action('wp_ajax_safelink_add_link', array($this, 'ajax_add_link'));
        add_action('wp_ajax_safelink_delete_link', array($this, 'ajax_delete_link'));
        add_action('wp_ajax_safelink_update_link', array($this, 'ajax_update_link'));
    }
    
    /**
     * Define constants
     */
    private function define_constants() {
        define('WPSAFELINK_VERSION', '1.0.0');
        define('WPSAFELINK_PLUGIN_DIR', plugin_dir_path(__FILE__));
        define('WPSAFELINK_PLUGIN_URL', plugin_dir_url(__FILE__));
        define('WPSAFELINK_TABLE', 'wp_safelinks');
    }

    /**
     * Activate the plugin
     */
    public function activate() {
        // Add default options
        add_option('wp_safelink_adsense_code', '');
        add_option('wp_safelink_wait_time', 10);
        add_option('wp_safelink_page_title', 'Please wait, redirecting...');
        
        // Create database table
        $this->create_database_table();
        
        // Add rewrite rules
        $this->add_rewrite_rules();
        
        // Flush rewrite rules
        flush_rewrite_rules();
    }

    /**
     * Create the database table for storing links
     */
    private function create_database_table() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'safelinks';
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            title varchar(255) NOT NULL,
            destination_url text NOT NULL,
            slug varchar(255) NOT NULL,
            created timestamp DEFAULT CURRENT_TIMESTAMP NOT NULL,
            clicks int DEFAULT 0 NOT NULL,
            status varchar(20) DEFAULT 'active' NOT NULL,
            custom_title varchar(255) DEFAULT '',
            custom_wait_time int DEFAULT 0,
            PRIMARY KEY  (id),
            UNIQUE KEY slug (slug)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    /**
     * Deactivate the plugin
     */
    public function deactivate() {
        // Flush rewrite rules
        flush_rewrite_rules();
    }

    /**
     * Add rewrite rules
     */
    public function add_rewrite_rules() {
        add_rewrite_rule(
            'go/([^/]+)/?$',
            'index.php?safelink_redirect=true&safelink_slug=$matches[1]',
            'top'
        );
        
        add_filter('query_vars', function($vars) {
            $vars[] = 'safelink_redirect';
            $vars[] = 'safelink_slug';
            return $vars;
        });
    }

    /**
     * Enqueue frontend scripts and styles
     */
    public function enqueue_scripts() {
        if (get_query_var('safelink_redirect')) {
            wp_enqueue_style(
                'wp-safelink-style',
                WPSAFELINK_PLUGIN_URL . 'assets/css/safelink.css',
                array(),
                WPSAFELINK_VERSION
            );
            
            wp_enqueue_script(
                'wp-safelink-script',
                WPSAFELINK_PLUGIN_URL . 'assets/js/safelink.js',
                array('jquery'),
                WPSAFELINK_VERSION,
                true
            );
        }
    }
    
    /**
     * Enqueue admin scripts and styles
     */
    public function admin_enqueue_scripts($hook) {
        // Only enqueue on our plugin pages
        if (strpos($hook, 'safelink') === false) {
            return;
        }
        
        wp_enqueue_style(
            'wp-safelink-admin-style',
            WPSAFELINK_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            WPSAFELINK_VERSION
        );
        
        wp_enqueue_script(
            'wp-safelink-admin-script',
            WPSAFELINK_PLUGIN_URL . 'assets/js/admin.js',
            array('jquery'),
            WPSAFELINK_VERSION,
            true
        );
        
        wp_localize_script(
            'wp-safelink-admin-script',
            'wpSafelink',
            array(
                'ajaxurl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('wp-safelink-nonce'),
                'messages' => array(
                    'confirmDelete' => __('Are you sure you want to delete this link?', 'wp-safelink'),
                    'success' => __('Operation completed successfully', 'wp-safelink'),
                    'error' => __('An error occurred', 'wp-safelink')
                )
            )
        );
    }

    /**
     * Add admin menu items
     */
    public function add_admin_menu() {
        // Main menu
        add_menu_page(
            'SafeLink Manager',
            'SafeLink',
            'manage_options',
            'wp-safelink-manager',
            array($this, 'links_manager_page'),
            'dashicons-admin-links',
            30
        );
        
        // Links submenu
        add_submenu_page(
            'wp-safelink-manager',
            'Manage Links',
            'Manage Links',
            'manage_options',
            'wp-safelink-manager',
            array($this, 'links_manager_page')
        );
        
        // Add link submenu
        add_submenu_page(
            'wp-safelink-manager',
            'Add New Link',
            'Add New Link',
            'manage_options',
            'wp-safelink-add',
            array($this, 'add_link_page')
        );
        
        // Statistics submenu
        add_submenu_page(
            'wp-safelink-manager',
            'Statistics',
            'Statistics',
            'manage_options',
            'wp-safelink-stats',
            array($this, 'stats_page')
        );
        
        // Settings submenu
        add_submenu_page(
            'wp-safelink-manager',
            'Settings',
            'Settings',
            'manage_options',
            'wp-safelink-settings',
            array($this, 'settings_page')
        );
    }
    public function sanitize_adsense_code($input) {
        // Use wp_kses to allow only certain HTML elements and attributes
        return wp_kses($input, array(
            'script' => array(
                'async' => array(),
                'src' => array(),
                'data-ad-client' => array(),
                'data-ad-slot' => array(),
                'data-ad-format' => array(),
            ),
            'ins' => array(
                'class' => array(),
                'style' => array(),
                'data-ad-client' => array(),
                'data-ad-slot' => array(),
                'data-ad-format' => array(),
                'data-full-width-responsive' => array(),
            ),
        ));
    }
    
    /**
     * Register settings
     */
    public function register_settings() {
        register_setting('wp_safelink_settings', 'wp_safelink_adsense_code', array(
            'sanitize_callback' => array($this, 'sanitize_adsense_code')
        ));
        register_setting('wp_safelink_settings', 'wp_safelink_wait_time', array(
            'sanitize_callback' => 'absint'
        ));
        register_setting('wp_safelink_settings', 'wp_safelink_page_title', array(
            'sanitize_callback' => 'sanitize_text_field'
        ));
        add_settings_section(
            'wp_safelink_section',
            'SafeLink Settings',
            array($this, 'settings_section_callback'),
            'wp-safelink-settings'
        );
        
        add_settings_field(
            'wp_safelink_adsense_code',
            'AdSense Code',
            array($this, 'adsense_code_callback'),
            'wp-safelink-settings',
            'wp_safelink_section'
        );
        
        add_settings_field(
            'wp_safelink_wait_time',
            'Default Wait Time (seconds)',
            array($this, 'wait_time_callback'),
            'wp-safelink-settings',
            'wp_safelink_section'
        );
        
        add_settings_field(
            'wp_safelink_page_title',
            'Default Redirect Page Title',
            array($this, 'page_title_callback'),
            'wp-safelink-settings',
            'wp_safelink_section'
        );
    }

    /**
     * Settings section callback
     */
    public function settings_section_callback() {
        echo '<p>Configure your SafeLink with AdSense settings below.</p>';
    }

    /**
     * AdSense code field callback
     */
    public function adsense_code_callback() {
        $adsense_code = get_option('wp_safelink_adsense_code');
        echo '<textarea name="wp_safelink_adsense_code" rows="10" cols="50" class="large-text code">' . esc_textarea($adsense_code) . '</textarea>';
        echo '<p class="description">Paste your AdSense code here. This will be displayed on the redirect page.</p>';
    }

    /**
     * Wait time field callback
     */
    public function wait_time_callback() {
        $wait_time = get_option('wp_safelink_wait_time', 10);
        echo '<input type="number" name="wp_safelink_wait_time" value="' . esc_attr($wait_time) . '" min="5" max="60" />';
        echo '<p class="description">Set the default wait time in seconds before redirecting to the target URL.</p>';
    }

    /**
     * Page title field callback
     */
    public function page_title_callback() {
        $page_title = get_option('wp_safelink_page_title', 'Please wait, redirecting...');
        echo '<input type="text" name="wp_safelink_page_title" value="' . esc_attr($page_title) . '" class="regular-text" />';
        echo '<p class="description">Set the default title for the redirect page.</p>';
    }

    /**
     * Links manager page
     */
    public function links_manager_page() {
        // Create an instance of the links table
        require_once(WPSAFELINK_PLUGIN_DIR . 'includes/class-wp-safelink-list-table.php');
        $links_table = new WP_SafeLink_List_Table();
        $links_table->prepare_items();
        
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">SafeLink Manager</h1>
            <a href="<?php echo admin_url('admin.php?page=wp-safelink-add'); ?>" class="page-title-action">Add New</a>
            
            <form method="post">
                <?php $links_table->search_box('Search Links', 'search_id'); ?>
                <?php $links_table->display(); ?>
            </form>
        </div>
        <?php
    }

    /**
     * Add link page
     */
    public function add_link_page() {
        // Handle form submission
        $message = '';
        $link_id = isset($_GET['edit']) ? intval($_GET['edit']) : 0;
        
        if ($link_id > 0) {
            // Get link data for editing
            global $wpdb;
            $table_name = $wpdb->prefix . 'safelinks';
            $link = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $link_id));
            
            if (!$link) {
                wp_redirect(admin_url('admin.php?page=wp-safelink-manager'));
                exit;
            }
        }
        
        ?>
        <div class="wrap">
            <h1><?php echo $link_id ? 'Edit SafeLink' : 'Add New SafeLink'; ?></h1>
            
            <?php if ($message): ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php echo esc_html($message); ?></p>
                </div>
            <?php endif; ?>
            
            <form method="post" id="safelink-form">
                <?php wp_nonce_field('wp_safelink_add_edit', 'wp_safelink_nonce'); ?>
                <input type="hidden" name="link_id" value="<?php echo $link_id; ?>" />
                
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="link_title">Link Title</label></th>
                        <td>
                            <input type="text" name="link_title" id="link_title" class="regular-text" value="<?php echo isset($link) ? esc_attr($link->title) : ''; ?>" required />
                            <p class="description">Enter a name for this link (for your reference only)</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="destination_url">Destination URL</label></th>
                        <td>
                            <input type="url" name="destination_url" id="destination_url" class="regular-text" value="<?php echo isset($link) ? esc_url($link->destination_url) : ''; ?>" required />
                            <p class="description">Enter the target URL where users will be redirected</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="link_slug">Custom Slug</label></th>
                        <td>
                            <input type="text" name="link_slug" id="link_slug" class="regular-text" value="<?php echo isset($link) ? esc_attr($link->slug) : ''; ?>" <?php echo isset($link) ? 'readonly' : ''; ?> />
                            <p class="description">
                                Enter a custom slug for this link (optional). 
                                <?php if (!isset($link)): ?>
                                    If left blank, a random slug will be generated.
                                <?php else: ?>
                                    Slug cannot be changed after creation.
                                <?php endif; ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="custom_title">Custom Page Title</label></th>
                        <td>
                            <input type="text" name="custom_title" id="custom_title" class="regular-text" value="<?php echo isset($link) ? esc_attr($link->custom_title) : ''; ?>" />
                            <p class="description">Enter a custom title for the redirect page (optional). Leave blank to use the default title.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="custom_wait_time">Custom Wait Time</label></th>
                        <td>
                            <input type="number" name="custom_wait_time" id="custom_wait_time" min="0" max="60" value="<?php echo isset($link) ? intval($link->custom_wait_time) : '0'; ?>" />
                            <p class="description">Enter a custom wait time in seconds (optional). Set to 0 to use the default wait time.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="link_status">Status</label></th>
                        <td>
                            <select name="link_status" id="link_status">
                                <option value="active" <?php echo (isset($link) && $link->status == 'active') ? 'selected' : ''; ?>>Active</option>
                                <option value="inactive" <?php echo (isset($link) && $link->status == 'inactive') ? 'selected' : ''; ?>>Inactive</option>
                            </select>
                        </td>
                    </tr>
                </table>
                
                <div id="generated-link-preview" class="safelink-preview" style="display: none;">
                    <h3>Generated SafeLink</h3>
                    <div class="link-box">
                        <code id="safelink-url"></code>
                        <button type="button" class="button" id="copy-link">Copy Link</button>
                    </div>
                </div>
                
                <p class="submit">
                    <input type="submit" name="submit" id="submit" class="button button-primary" value="<?php echo $link_id ? 'Update Link' : 'Create Link'; ?>" />
                </p>
            </form>
        </div>
        
        <script>
            jQuery(document).ready(function($) {
                // Form submission via AJAX
                $('#safelink-form').on('submit', function(e) {
                    e.preventDefault();
                    
                    var formData = $(this).serialize();
                    var action = $('#link_id').val() ? 'safelink_update_link' : 'safelink_add_link';
                    
                    $.ajax({
                        type: 'POST',
                        url: ajaxurl,
                        data: {
                            action: action,
                            nonce: wpSafelink.nonce,
                            formData: formData
                        },
                        success: function(response) {
                            if (response.success) {
                                // Show success message
                                $('#safelink-form').before('<div class="notice notice-success is-dismissible"><p>' + response.data.message + '</p></div>');
                                
                                if (!$('#link_id').val()) {
                                    // Clear form for new entries
                                    $('#safelink-form')[0].reset();
                                    
                                    // Show the generated link
                                    $('#safelink-url').text(response.data.safelink_url);
                                    $('#generated-link-preview').show();
                                } else {
                                    // Redirect back to list
                                    setTimeout(function() {
                                        window.location.href = '<?php echo admin_url('admin.php?page=wp-safelink-manager'); ?>';
                                    }, 1000);
                                }
                            } else {
                                // Show error message
                                $('#safelink-form').before('<div class="notice notice-error is-dismissible"><p>' + response.data.message + '</p></div>');
                            }
                        }
                    });
                });
                
                // Copy link button
                $('#copy-link').on('click', function() {
                    var tempInput = $('<input>');
                    $('body').append(tempInput);
                    tempInput.val($('#safelink-url').text()).select();
                    document.execCommand('copy');
                    tempInput.remove();
                    
                    // Show copied message
                    $(this).text('Copied!');
                    setTimeout(function() {
                        $('#copy-link').text('Copy Link');
                    }, 2000);
                });
            });
        </script>
        <?php
    }

    /**
     * Statistics page
     */
    public function stats_page() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'safelinks';
        
        // Get some basic stats
        $total_links = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
        $total_clicks = $wpdb->get_var("SELECT SUM(clicks) FROM $table_name");
        $top_links = $wpdb->get_results("SELECT * FROM $table_name ORDER BY clicks DESC LIMIT 10");
        
        ?>
        <div class="wrap">
            <h1>SafeLink Statistics</h1>
            
            <div class="safelink-stats-overview">
                <div class="stat-box">
                    <h2>Total Links</h2>
                    <div class="stat-number"><?php echo number_format($total_links); ?></div>
                </div>
                
                <div class="stat-box">
                    <h2>Total Clicks</h2>
                    <div class="stat-number"><?php echo number_format($total_clicks); ?></div>
                </div>
                
                <div class="stat-box">
                    <h2>Average CTR</h2>
                    <div class="stat-number"><?php echo $total_links > 0 ? number_format($total_clicks / $total_links, 1) : '0'; ?></div>
                </div>
            </div>
            
            <h2>Top Performing Links</h2>
            
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Title</th>
                        <th>Destination</th>
                        <th>Clicks</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($top_links)): ?>
                        <tr>
                            <td colspan="4">No links found.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($top_links as $link): ?>
                            <tr>
                                <td><?php echo esc_html($link->title); ?></td>
                                <td><a href="<?php echo esc_url($link->destination_url); ?>" target="_blank"><?php echo esc_url($link->destination_url); ?></a></td>
                                <td><?php echo number_format($link->clicks); ?></td>
                                <td>
                                    <a href="<?php echo admin_url('admin.php?page=wp-safelink-add&edit=' . $link->id); ?>" class="button button-small">Edit</a>
                                    <a href="<?php echo home_url('go/' . $link->slug); ?>" target="_blank" class="button button-small">View</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**
     * Settings page
     */
    public function settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            <form action="options.php" method="post">
                <?php
                settings_fields('wp_safelink_settings');
                do_settings_sections('wp-safelink-settings');
                submit_button('Save Settings');
                ?>
            </form>
            
            <h2>How to Use</h2>
            <p>Create and manage your SafeLinks through the "Manage Links" menu. Each link you create will have its own unique URL.</p>
            <p>You can still use the shortcode: <code>[safelink id="123"]Click here[/safelink]</code> in your posts or pages.</p>
            <p>Where "123" is the ID of the link you've created in the Links Manager.</p>
        </div>
        <?php
    }

    /**
     * AJAX handler for adding a new link
     */
    public function ajax_add_link() {
        // Check nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'wp-safelink-nonce')) {
            wp_send_json_error(array('message' => 'Security check failed'));
        }
        
        // Parse form data
        parse_str($_POST['formData'], $form_data);
        
        // Validate data
        if (empty($form_data['link_title']) || empty($form_data['destination_url'])) {
            wp_send_json_error(array('message' => 'Title and URL are required'));
        }
        
        // Prepare data for insertion
        $title = sanitize_text_field($form_data['link_title']);
        $destination_url = esc_url_raw($form_data['destination_url']);
        $custom_title = sanitize_text_field($form_data['custom_title']);
        $custom_wait_time = intval($form_data['custom_wait_time']);
        $status = sanitize_text_field($form_data['link_status']);
        
        // Generate or use custom slug
        if (empty($form_data['link_slug'])) {
            $slug = $this->generate_random_slug();
        } else {
            $slug = sanitize_title($form_data['link_slug']);
            
            // Check if slug exists
            global $wpdb;
            $table_name = $wpdb->prefix . 'safelinks';
            $exists = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_name WHERE slug = %s", $slug));
            
            if ($exists) {
                wp_send_json_error(array('message' => 'Slug already exists. Please choose a different one.'));
            }
        }
        
        // Insert into database
        global $wpdb;
        $table_name = $wpdb->prefix . 'safelinks';
        
        $result = $wpdb->insert(
            $table_name,
            array(
                'title' => $title,
                'destination_url' => $destination_url,
                'slug' => $slug,
                'custom_title' => $custom_title,
                'custom_wait_time' => $custom_wait_time,
                'status' => $status
            ),
            array('%s', '%s', '%s', '%s', '%d', '%s')
        );
        
        if ($result === false) {
            wp_send_json_error(array('message' => 'Failed to create link: ' . $wpdb->last_error));
        }
        
        // Get the inserted ID
        $link_id = $wpdb->insert_id;
        
        // Generate the SafeLink URL
        $safelink_url = home_url('go/' . $slug);
        
        wp_send_json_success(array(
            'message' => 'Link created successfully!',
            'link_id' => $link_id,
            'safelink_url' => $safelink_url
        ));
    }

    /**
     * AJAX handler for updating a link
     */
    public function ajax_update_link() {
        // Check nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'wp-safelink-nonce')) {
            wp_send_json_error(array('message' => 'Security check failed'));
        }
        
        // Parse form data
        parse_str($_POST['formData'], $form_data);
        
        // Validate data
        if (empty($form_data['link_id']) || empty($form_data['link_title']) || empty($form_data['destination_url'])) {
            wp_send_json_error(array('message' => 'Missing required data'));
        }
        
        $link_id = intval($form_data['link_id']);
        
        // Prepare data for update
        $title = sanitize_text_field($form_data['link_title']);
        $destination_url = esc_url_raw($form_data['destination_url']);
        $custom_title = sanitize_text_field($form_data['custom_title']);
        $custom_wait_time = intval($form_data['custom_wait_time']);
        $status = sanitize_text_field($form_data['link_status']);
        
        // Update in database
        global $wpdb;
        $table_name = $wpdb->prefix . 'safelinks';
        
        $result = $wpdb->update(
            $table_name,
            array(
                'title' => $title,
                'destination_url' => $destination_url,
                'custom_title' => $custom_title,
                'custom_wait_time' => $custom_wait_time,
                'status' => $status
            ),
            array('id' => $link_id),
            array('%s', '%s', '%s', '%d', '%s'),
            array('%d')
        );
        
        if ($result === false) {
            wp_send_json_error(array('message' => 'Failed to update link: ' . $wpdb->last_error));
        }
        
        wp_send_json_success(array(
            'message' => 'Link updated successfully!'
        ));
    }

    /**
     * AJAX handler for deleting a link
     */

/**
     * AJAX handler for deleting a link
     */
    public function ajax_delete_link() {
        // Check nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'wp-safelink-nonce')) {
            wp_send_json_error(array('message' => 'Security check failed'));
        }
        
        // Get link ID
        $link_id = isset($_POST['link_id']) ? intval($_POST['link_id']) : 0;
        
        if ($link_id <= 0) {
            wp_send_json_error(array('message' => 'Invalid link ID'));
        }
        
        // Delete from database
        global $wpdb;
        $table_name = $wpdb->prefix . 'safelinks';
        
        $result = $wpdb->delete(
            $table_name,
            array('id' => $link_id),
            array('%d')
        );
        
        if ($result === false) {
            wp_send_json_error(array('message' => 'Failed to delete link: ' . $wpdb->last_error));
        }
        
        wp_send_json_success(array(
            'message' => 'Link deleted successfully!'
        ));
    }
    
private function generate_random_slug($length = 8) {
        $chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
        $slug = '';
        
        for ($i = 0; $i < $length; $i++) {
            $slug .= $chars[rand(0, strlen($chars) - 1)];
        }
        
        // Check if slug exists
        global $wpdb;
        $table_name = $wpdb->prefix . 'safelinks';
        $exists = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_name WHERE slug = %s", $slug));
        
        if ($exists) {
            // Try again with a longer slug
            return $this->generate_random_slug($length + 1);
        }
        
        return $slug;
    }

    /**
     * SafeLink shortcode
     */
    public function safelink_shortcode($atts, $content = null) {
        $atts = shortcode_atts(
            array(
                'id' => 0,
                'url' => '',
                'title' => '',
            ),
            $atts,
            'safelink'
        );
        
        // If ID is provided, get the link from database
        if (!empty($atts['id'])) {
            global $wpdb;
            $table_name = $wpdb->prefix . 'safelinks';
            $link = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d AND status = 'active'", intval($atts['id'])));
            
            if (!$link) {
                return '<span style="color: red;">Error: SafeLink not found or inactive.</span>';
            }
            
            $safelink_url = home_url('go/' . $link->slug);
            
            // Return the HTML
            return '<a href="' . esc_url($safelink_url) . '" rel="nofollow" target="_blank">' . $content . '</a>';
        }
        
        // Legacy support for direct URL method
        if (empty($atts['url'])) {
            return '<span style="color: red;">Error: ID or URL is required for SafeLink shortcode.</span>';
        }
        
        // Generate a unique key for the URL
        $slug = $this->generate_random_slug();
        
        // Insert into database
        global $wpdb;
        $table_name = $wpdb->prefix . 'safelinks';
        
        $wpdb->insert(
            $table_name,
            array(
                'title' => 'Shortcode generated: ' . substr($atts['url'], 0, 50) . '...',
                'destination_url' => $atts['url'],
                'slug' => $slug,
                'custom_title' => $atts['title'],
                'status' => 'active'
            ),
            array('%s', '%s', '%s', '%s', '%s')
        );
        
        // Generate the safelink URL
        $safelink_url = home_url('go/' . $slug);
        
        // Return the HTML
        return '<a href="' . esc_url($safelink_url) . '" rel="nofollow" target="_blank">' . $content . '</a>';
    }

    /**
     * Handle redirect
     */
    public function handle_redirect() {
        // Check if this is a safelink redirect
        if (get_query_var('safelink_redirect')) {
            $slug = get_query_var('safelink_slug');
            
            if (!$slug) {
                wp_redirect(home_url());
                exit;
            }
            
            // Get the link from database
            global $wpdb;
            $table_name = $wpdb->prefix . 'safelinks';
            $link = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE slug = %s AND status = 'active'", $slug));
            
            if (!$link) {
                wp_redirect(home_url());
                exit;
            }
            
            // Update click count
            $wpdb->update(
                $table_name,
                array('clicks' => $link->clicks + 1),
                array('id' => $link->id),
                array('%d'),
                array('%d')
            );
            
            // Get settings
            $url = $link->destination_url;
            $title = !empty($link->custom_title) ? $link->custom_title : get_option('wp_safelink_page_title', 'Please wait, redirecting...');
            $wait_time = !empty($link->custom_wait_time) ? $link->custom_wait_time : get_option('wp_safelink_wait_time', 10);
            $adsense_code = get_option('wp_safelink_adsense_code', '');
            
            // Output the redirect page
            $this->output_redirect_page($url, $title, $wait_time, $adsense_code);
            exit;
        }
    }

    private function output_redirect_page($url, $title, $wait_time, $adsense_code) {
    $url = esc_url($url);
    
    $random_post = $this->get_random_post();
    
    ?>
    <!DOCTYPE html>
    <html <?php language_attributes(); ?>>
    <head>
        <meta charset="<?php bloginfo('charset'); ?>">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?php echo esc_html($title); ?></title>
        <style>
            :root {
                --bg-color: #121212;
                --text-color: #e0e0e0;
                --accent-color: #3d5afe;
                --accent-hover: #536dfe;
                --card-bg: #1e1e1e;
                --border-color: #333333;
                --secondary-bg: #212121;
            }
            
            * {
                margin: 0;
                padding: 0;
                box-sizing: border-box;
            }
            
            body {
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
                background-color: var(--bg-color);
                color: var(--text-color);
                line-height: 1.6;
            }
            
            .safelink-container {
                width: 100%;
                max-width: 1200px;
                margin: 0 auto;
                padding: 2rem;
            }
            
            .safelink-header {
                text-align: center;
                margin-bottom: 2rem;
                padding-bottom: 1.5rem;
                border-bottom: 1px solid var(--border-color);
            }
            
            .safelink-header h1 {
                font-size: 2.2rem;
                font-weight: 700;
                color: var(--accent-color);
            }
            
            .safelink-content {
                text-align: center;
                margin-bottom: 2rem;
                font-size: 1.1rem;
            }
            
            .safelink-countdown {
                text-align: center;
                font-size: 2rem;
                font-weight: bold;
                margin: 2rem 0;
                color: var(--accent-color);
            }
            
            .safelink-ads {
                margin: 2rem 0;
                padding: 1rem;
                background-color: var(--secondary-bg);
                border-radius: 8px;
                text-align: center;
            }
            
            #continue-button {
                display: none;
                text-align: center;
                margin: 2rem 0;
            }
            
            #continue-button a {
                display: inline-block;
                padding: 12px 28px;
                background-color: var(--accent-color);
                color: #fff;
                text-decoration: none;
                border-radius: 6px;
                font-weight: 600;
                font-size: 1.1rem;
                transition: all 0.3s ease;
                box-shadow: 0 4px 6px rgba(0,0,0,0.2);
            }
            
            #continue-button a:hover {
                background-color: var(--accent-hover);
                transform: translateY(-2px);
                box-shadow: 0 6px 8px rgba(0,0,0,0.3);
            }
            
            #scroll-notice {
                text-align: center;
                padding: 1rem;
                background-color: var(--secondary-bg);
                border-left: 4px solid var(--accent-color);
                margin: 2rem 0;
                border-radius: 0 8px 8px 0;
            }
            
            .article-container {
                margin-top: 3rem;
                background-color: var(--card-bg);
                border-radius: 10px;
                padding: 2rem;
                box-shadow: 0 6px 12px rgba(0,0,0,0.15);
            }
            
            .article-title {
                font-size: 2rem;
                margin-bottom: 1rem;
                color: var(--accent-color);
                line-height: 1.3;
            }
            
            .article-meta {
                display: flex;
                align-items: center;
                margin-bottom: 2rem;
                font-size: 0.9rem;
                color: #888;
            }
            
            .article-date {
                margin-right: 1rem;
            }
            
            .article-author {
                margin-right: 1rem;
            }
            
            .article-categories a {
                color: var(--accent-color);
                text-decoration: none;
            }
            
            .article-categories a:hover {
                text-decoration: underline;
            }
            
            .article-featured-image {
                width: 100%;
                max-height: 500px;
                object-fit: cover;
                border-radius: 8px;
                margin-bottom: 2rem;
            }
            
            .article-content {
                font-size: 1.1rem;
                line-height: 1.8;
                margin-bottom: 2rem;
            }
            
            .article-content p {
                margin-bottom: 1.5rem;
            }
            
            .article-content h2, 
            .article-content h3,
            .article-content h4 {
                margin-top: 2rem;
                margin-bottom: 1rem;
                color: var(--accent-color);
            }
            
            .article-content img {
                max-width: 100%;
                height: auto;
                border-radius: 6px;
                margin: 1.5rem 0;
            }
            
            .article-content ul,
            .article-content ol {
                margin-left: 2rem;
                margin-bottom: 1.5rem;
            }
            
            .article-content blockquote {
                border-left: 4px solid var(--accent-color);
                padding-left: 1.5rem;
                margin: 1.5rem 0;
                font-style: italic;
                color: #aaa;
            }
            
            .article-content pre {
                background-color: #252525;
                padding: 1rem;
                border-radius: 6px;
                overflow-x: auto;
                margin: 1.5rem 0;
            }
            
            .safelink-footer {
                margin-top: 3rem;
                text-align: center;
                padding-top: 1.5rem;
                border-top: 1px solid var(--border-color);
                color: #888;
                font-size: 0.9rem;
            }
            
            @media (max-width: 768px) {
                .safelink-container {
                    padding: 1rem;
                }
                
                .safelink-header h1 {
                    font-size: 1.8rem;
                }
                
                .article-title {
                    font-size: 1.6rem;
                }
                
                .article-content {
                    font-size: 1rem;
                }
                
                .article-container {
                    padding: 1.5rem;
                }
            }
        </style>
    </head>
    <body>
        <div class="safelink-container">
            <div class="safelink-header">
                <h1><?php echo esc_html($title); ?></h1>
            </div>
            
            <div class="safelink-content">
                <p>Please wait while we prepare your link. You will be redirected in <span id="countdown"><?php echo intval($wait_time); ?></span> seconds.</p>
            </div>
            
            <div class="safelink-ads">
                <?php echo $adsense_code; ?>
            </div>
            
            <div class="safelink-countdown">
                <span id="timer"><?php echo intval($wait_time); ?></span>
            </div>
            
            <div id="scroll-notice">
                Please scroll down to read the article and access your link at the bottom.
            </div>
            
            <?php if ($random_post) : ?>
                <div class="article-container">
                    <h2 class="article-title"><a href="<?php echo esc_url(get_permalink($post->ID)); ?>" target="_blank"><?php echo esc_html($random_post->post_title); ?></a></h2>
                    
                    <div class="article-meta">
                        <span class="article-date">
                            <?php echo get_the_date('', $random_post->ID); ?>
                        </span>
                        <span class="article-author">
                            <?php echo get_the_author_meta('display_name', $random_post->post_author); ?>
                        </span>
                        <span class="article-categories">
                            <?php
                            $categories = get_the_category($random_post->ID);
                            if ($categories) {
                                $output = '';
                                foreach ($categories as $category) {
                                    $output .= '<a href="' . esc_url(get_category_link($category->term_id)) . '">' 
                                            . esc_html($category->name) . '</a>, ';
                                }
                                echo trim($output, ', ');
                            }
                            ?>
                        </span>
                    </div>
                    
                    <?php 
                    if (has_post_thumbnail($random_post->ID)) {
                        echo get_the_post_thumbnail($random_post->ID, 'large', array('class' => 'article-featured-image'));
                    }
                    ?>
                    
                    <div class="article-content">
                        <?php echo apply_filters('the_content', $random_post->post_content); ?>
                    </div>
                    
                    <div id="continue-button">
                        <a href="<?php echo esc_url($url); ?>" id="continue-link">Continue to Destination</a>
                    </div>
                </div>
            <?php else : ?>
                <div class="article-container">
                    <div class="article-content">
                        <p>No articles found. Please try again later.</p>
                    </div>
                    
                    <div id="continue-button">
                        <a href="<?php echo esc_url($url); ?>" id="continue-link">Continue to Destination</a>
                    </div>
                </div>
            <?php endif; ?>
            
            <div class="safelink-footer">
                <p><a>Powered by WP SafeLink</a></p>
            </div>
        </div>
        
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                var timerElement = document.getElementById('timer');
                var continueButton = document.getElementById('continue-button');
                var seconds = parseInt(timerElement.textContent, 10);
                var interval;
                var hasScrolled = false;
                
                window.addEventListener('scroll', function() {
                    var scrollPosition = window.scrollY + window.innerHeight;
                    var documentHeight = document.documentElement.scrollHeight;
                    
                    if (scrollPosition >= documentHeight - 100) {
                        hasScrolled = true;
                        
                        if (seconds <= 0) {
                            continueButton.style.display = 'block';
                        }
                    }
                });
                
                interval = setInterval(function() {
                    seconds--;
                    timerElement.textContent = seconds;
                    
                    if (seconds <= 0) {
                        clearInterval(interval);
                        timerElement.parentElement.style.display = 'none';
                        
                        if (hasScrolled) {
                            continueButton.style.display = 'block';
                        }
                    }
                }, 1000);
            });
        </script>
    </body>
    </html>
    <?php
}

private function get_random_post() {
    $args = array(
        'post_type'      => 'post',
        'post_status'    => 'publish',
        'posts_per_page' => 1,
        'orderby'        => 'rand',
        'ignore_sticky_posts' => 1
    );
    
    $query = new WP_Query($args);
    
    if ($query->have_posts()) {
        return $query->posts[0];
    }
    
    return null;
}
    
}
// Initialize the plugin
new WP_SafeLink_AdSense();