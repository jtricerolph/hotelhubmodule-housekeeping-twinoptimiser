<?php
/**
 * Settings - Admin settings page for Twin Optimiser module.
 *
 * Manages per-location configuration for twin detection.
 *
 * @package HotelHub_Module_Twin_Optimiser
 */

if (!defined('ABSPATH')) {
    exit;
}

class HHTM_Settings {

    /**
     * Constructor.
     */
    public function __construct() {
        add_action('admin_menu', array($this, 'add_settings_page'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_post_hhtm_save_settings', array($this, 'save_settings'));
    }

    /**
     * Add settings page to admin menu.
     */
    public function add_settings_page() {
        add_submenu_page(
            'hotel-hub',
            __('Twin Optimiser Settings', 'hhtm'),
            __('Twin Optimiser', 'hhtm'),
            'manage_options',
            'hhtm-settings',
            array($this, 'render_settings_page')
        );
    }

    /**
     * Register settings.
     */
    public function register_settings() {
        register_setting('hhtm_settings', 'hhtm_location_settings');
    }

    /**
     * Render settings page.
     */
    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        // Get workforce locations
        $locations = $this->get_workforce_locations();

        // Get existing settings
        $location_settings = get_option('hhtm_location_settings', array());

        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

            <?php if (empty($locations)): ?>
                <div class="notice notice-warning">
                    <p><?php _e('No workforce locations found. Please ensure the Workforce Authentication plugin is active and locations have been synced.', 'hhtm'); ?></p>
                </div>
            <?php else: ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="hhtm_save_settings">
                    <?php wp_nonce_field('hhtm_save_settings', 'hhtm_settings_nonce'); ?>

                    <table class="form-table">
                        <thead>
                            <tr>
                                <th scope="col"><?php _e('Location', 'hhtm'); ?></th>
                                <th scope="col"><?php _e('Custom Field Name for Twin Detection', 'hhtm'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($locations as $location): ?>
                                <?php
                                $location_id = $location->workforce_id;
                                $custom_field = isset($location_settings[$location_id]) ? $location_settings[$location_id]['custom_field'] : 'Bed Type';
                                ?>
                                <tr>
                                    <td>
                                        <strong><?php echo esc_html($location->name); ?></strong>
                                    </td>
                                    <td>
                                        <input
                                            type="text"
                                            name="hhtm_location_settings[<?php echo esc_attr($location_id); ?>][custom_field]"
                                            value="<?php echo esc_attr($custom_field); ?>"
                                            class="regular-text"
                                            placeholder="Bed Type"
                                        >
                                        <p class="description">
                                            <?php _e('Enter the NewBook custom field name that contains bed type information (e.g., "Bed Type", "Twin", "Room Configuration").', 'hhtm'); ?>
                                        </p>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <p class="submit">
                        <input type="submit" name="submit" id="submit" class="button button-primary" value="<?php _e('Save Settings', 'hhtm'); ?>">
                    </p>
                </form>

                <div class="hhtm-settings-info">
                    <h2><?php _e('How Twin Detection Works', 'hhtm'); ?></h2>
                    <p><?php _e('The Twin Optimiser checks the specified custom field in each booking to identify twin room opportunities. Bookings are considered twins when:', 'hhtm'); ?></p>
                    <ul>
                        <li><?php _e('The custom field value contains "twin" (case-insensitive)', 'hhtm'); ?></li>
                        <li><?php _e('The custom field value contains "2 x single" or "2x single" (case-insensitive)', 'hhtm'); ?></li>
                    </ul>
                    <p><?php _e('Twin bookings are highlighted in yellow in the booking grid to help you identify optimization opportunities.', 'hhtm'); ?></p>
                </div>
            <?php endif; ?>
        </div>

        <style>
            .hhtm-settings-info {
                margin-top: 30px;
                padding: 20px;
                background: #f8f9fa;
                border-left: 4px solid #2196f3;
            }

            .hhtm-settings-info h2 {
                margin-top: 0;
                font-size: 18px;
            }

            .hhtm-settings-info ul {
                margin-left: 20px;
            }

            .form-table thead th {
                font-weight: 600;
                padding: 10px;
                background: #f0f0f1;
            }

            .form-table tbody td {
                padding: 15px 10px;
            }
        </style>
        <?php
    }

    /**
     * Save settings.
     */
    public function save_settings() {
        // Check user permissions
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to access this page.', 'hhtm'));
        }

        // Verify nonce
        if (!isset($_POST['hhtm_settings_nonce']) || !wp_verify_nonce($_POST['hhtm_settings_nonce'], 'hhtm_save_settings')) {
            wp_die(__('Security check failed.', 'hhtm'));
        }

        // Get submitted settings
        $location_settings = isset($_POST['hhtm_location_settings']) ? $_POST['hhtm_location_settings'] : array();

        // Sanitize settings
        $sanitized_settings = array();
        foreach ($location_settings as $location_id => $settings) {
            $sanitized_settings[absint($location_id)] = array(
                'custom_field' => sanitize_text_field($settings['custom_field']),
            );
        }

        // Save settings
        update_option('hhtm_location_settings', $sanitized_settings);

        // Redirect back to settings page
        wp_safe_redirect(add_query_arg(
            array(
                'page'    => 'hhtm-settings',
                'updated' => 'true',
            ),
            admin_url('admin.php')
        ));
        exit;
    }

    /**
     * Get workforce locations from database.
     *
     * @return array Array of location objects.
     */
    private function get_workforce_locations() {
        global $wpdb;

        if (!defined('WFA_TABLE_PREFIX')) {
            return array();
        }

        $table_name = $wpdb->prefix . WFA_TABLE_PREFIX . 'locations';

        // Check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") != $table_name) {
            return array();
        }

        // Get locations from cached table
        $locations = $wpdb->get_results(
            "SELECT workforce_id, name
             FROM {$table_name}
             ORDER BY name ASC"
        );

        return $locations ? $locations : array();
    }

    /**
     * Get custom field name for a location.
     *
     * @param int $location_id Workforce location ID.
     * @return string Custom field name.
     */
    public static function get_location_custom_field($location_id) {
        $location_settings = get_option('hhtm_location_settings', array());

        if (isset($location_settings[$location_id]['custom_field'])) {
            return $location_settings[$location_id]['custom_field'];
        }

        // Default to 'Bed Type'
        return 'Bed Type';
    }
}
