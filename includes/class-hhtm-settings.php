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
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_post_hhtm_save_settings', array($this, 'save_settings'));
    }

    /**
     * Register settings.
     */
    public function register_settings() {
        register_setting('hhtm_settings', 'hhtm_location_settings');
    }

    /**
     * Render settings page.
     *
     * Called by main plugin class via Hotel Hub admin menu system.
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

                    <table class="form-table hhtm-settings-table">
                        <thead>
                            <tr>
                                <th scope="col" style="width: 60px;"><?php _e('Enabled', 'hhtm'); ?></th>
                                <th scope="col"><?php _e('Location', 'hhtm'); ?></th>
                                <th scope="col"><?php _e('Twin Detection & Display Settings', 'hhtm'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($locations as $location): ?>
                                <?php
                                $location_id = $location->workforce_id;
                                $is_enabled = isset($location_settings[$location_id]['enabled']) ? (bool) $location_settings[$location_id]['enabled'] : false;
                                $custom_field = isset($location_settings[$location_id]['custom_field']) ? $location_settings[$location_id]['custom_field'] : 'Bed Type';
                                $custom_field_names = isset($location_settings[$location_id]['custom_field_names']) ? $location_settings[$location_id]['custom_field_names'] : '';
                                $custom_field_values = isset($location_settings[$location_id]['custom_field_values']) ? $location_settings[$location_id]['custom_field_values'] : '';
                                $notes_search_terms = isset($location_settings[$location_id]['notes_search_terms']) ? $location_settings[$location_id]['notes_search_terms'] : '';
                                $normal_booking_color = isset($location_settings[$location_id]['normal_booking_color']) ? $location_settings[$location_id]['normal_booking_color'] : '#ce93d8';
                                $twin_booking_color = isset($location_settings[$location_id]['twin_booking_color']) ? $location_settings[$location_id]['twin_booking_color'] : '#81c784';
                                ?>
                                <tr>
                                    <td style="text-align: center;">
                                        <input
                                            type="checkbox"
                                            name="hhtm_location_settings[<?php echo esc_attr($location_id); ?>][enabled]"
                                            value="1"
                                            <?php checked($is_enabled, true); ?>
                                        >
                                    </td>
                                    <td>
                                        <strong><?php echo esc_html($location->name); ?></strong>
                                    </td>
                                    <td>
                                        <div class="hhtm-setting-row">
                                            <label><strong><?php _e('Display Colors', 'hhtm'); ?></strong></label>
                                            <div class="hhtm-color-pickers">
                                                <div class="hhtm-color-field">
                                                    <label><?php _e('Normal Booking Color:', 'hhtm'); ?></label>
                                                    <input
                                                        type="color"
                                                        name="hhtm_location_settings[<?php echo esc_attr($location_id); ?>][normal_booking_color]"
                                                        value="<?php echo esc_attr($normal_booking_color); ?>"
                                                        class="hhtm-color-picker"
                                                    >
                                                    <span class="hhtm-color-value"><?php echo esc_html($normal_booking_color); ?></span>
                                                </div>
                                                <div class="hhtm-color-field">
                                                    <label><?php _e('Twin Booking Color:', 'hhtm'); ?></label>
                                                    <input
                                                        type="color"
                                                        name="hhtm_location_settings[<?php echo esc_attr($location_id); ?>][twin_booking_color]"
                                                        value="<?php echo esc_attr($twin_booking_color); ?>"
                                                        class="hhtm-color-picker"
                                                    >
                                                    <span class="hhtm-color-value"><?php echo esc_html($twin_booking_color); ?></span>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="hhtm-setting-row">
                                            <label><strong><?php _e('Legacy Custom Field (Deprecated)', 'hhtm'); ?></strong></label>
                                            <input
                                                type="text"
                                                name="hhtm_location_settings[<?php echo esc_attr($location_id); ?>][custom_field]"
                                                value="<?php echo esc_attr($custom_field); ?>"
                                                class="regular-text"
                                                placeholder="Bed Type"
                                            >
                                            <p class="description">
                                                <?php _e('Legacy field - use the enhanced detection fields below instead.', 'hhtm'); ?>
                                            </p>
                                        </div>

                                        <div class="hhtm-setting-row">
                                            <label><strong><?php _e('Custom Field Names (CSV)', 'hhtm'); ?></strong></label>
                                            <input
                                                type="text"
                                                name="hhtm_location_settings[<?php echo esc_attr($location_id); ?>][custom_field_names]"
                                                value="<?php echo esc_attr($custom_field_names); ?>"
                                                class="large-text"
                                                placeholder="Bed Type, Room Configuration, Bed Preference"
                                            >
                                            <p class="description">
                                                <?php _e('Comma-separated list of custom field names to check for twin indicators.', 'hhtm'); ?>
                                            </p>
                                        </div>

                                        <div class="hhtm-setting-row">
                                            <label><strong><?php _e('Custom Field Values to Detect (CSV)', 'hhtm'); ?></strong></label>
                                            <input
                                                type="text"
                                                name="hhtm_location_settings[<?php echo esc_attr($location_id); ?>][custom_field_values]"
                                                value="<?php echo esc_attr($custom_field_values); ?>"
                                                class="large-text"
                                                placeholder="twin, 2 x single, 2x single, two singles"
                                            >
                                            <p class="description">
                                                <?php _e('Comma-separated list of values to search for in the custom fields (case-insensitive partial match).', 'hhtm'); ?>
                                            </p>
                                        </div>

                                        <div class="hhtm-setting-row">
                                            <label><strong><?php _e('Notes Search Terms (CSV)', 'hhtm'); ?></strong></label>
                                            <input
                                                type="text"
                                                name="hhtm_location_settings[<?php echo esc_attr($location_id); ?>][notes_search_terms]"
                                                value="<?php echo esc_attr($notes_search_terms); ?>"
                                                class="large-text"
                                                placeholder="twin bed, two single beds, separate beds"
                                            >
                                            <p class="description">
                                                <?php _e('Comma-separated list of terms to search for in booking notes content (case-insensitive partial match).', 'hhtm'); ?>
                                            </p>
                                        </div>
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
                    <p><?php _e('The Twin Optimiser uses multiple methods to identify twin room opportunities:', 'hhtm'); ?></p>
                    <ul>
                        <li><strong><?php _e('Custom Fields:', 'hhtm'); ?></strong> <?php _e('Checks specified custom field names for any of the configured values (partial, case-insensitive match)', 'hhtm'); ?></li>
                        <li><strong><?php _e('Booking Notes:', 'hhtm'); ?></strong> <?php _e('Searches all booking note content for configured search terms (partial, case-insensitive match)', 'hhtm'); ?></li>
                        <li><strong><?php _e('Legacy Detection:', 'hhtm'); ?></strong> <?php _e('Falls back to checking the legacy custom field for "twin", "2 x single", or "2x single" if enhanced detection is not configured', 'hhtm'); ?></li>
                    </ul>
                    <p><?php _e('Twin bookings are highlighted using your configured color in the booking grid. Normal bookings use the normal booking color.', 'hhtm'); ?></p>
                </div>
            <?php endif; ?>
        </div>

        <style>
            .hhtm-settings-table {
                border-collapse: collapse;
                width: 100%;
                margin-top: 20px;
            }

            .hhtm-settings-table thead th {
                font-weight: 600;
                padding: 12px 10px;
                background: #f0f0f1;
                border-bottom: 2px solid #ddd;
                text-align: left;
            }

            .hhtm-settings-table tbody td {
                padding: 15px 10px;
                border-bottom: 1px solid #e0e0e0;
                vertical-align: top;
            }

            .hhtm-settings-table tbody tr:hover {
                background: #f9f9f9;
            }

            .hhtm-settings-table input[type="checkbox"] {
                width: 20px;
                height: 20px;
                cursor: pointer;
            }

            .hhtm-setting-row {
                margin-bottom: 20px;
            }

            .hhtm-setting-row:last-child {
                margin-bottom: 0;
            }

            .hhtm-setting-row label {
                display: block;
                margin-bottom: 8px;
            }

            .hhtm-color-pickers {
                display: flex;
                gap: 20px;
                margin-top: 8px;
            }

            .hhtm-color-field {
                display: flex;
                align-items: center;
                gap: 10px;
            }

            .hhtm-color-field label {
                margin: 0;
                font-weight: normal;
            }

            .hhtm-color-picker {
                width: 60px;
                height: 35px;
                border: 1px solid #ddd;
                border-radius: 3px;
                cursor: pointer;
            }

            .hhtm-color-value {
                font-family: monospace;
                font-size: 13px;
                color: #666;
            }

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
                'enabled'              => isset($settings['enabled']) ? (bool) $settings['enabled'] : false,
                'custom_field'         => sanitize_text_field($settings['custom_field']),
                'custom_field_names'   => sanitize_text_field($settings['custom_field_names']),
                'custom_field_values'  => sanitize_text_field($settings['custom_field_values']),
                'notes_search_terms'   => sanitize_text_field($settings['notes_search_terms']),
                'normal_booking_color' => sanitize_hex_color($settings['normal_booking_color']),
                'twin_booking_color'   => sanitize_hex_color($settings['twin_booking_color']),
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

    /**
     * Check if Twin Optimiser is enabled for a location.
     *
     * @param int $location_id Workforce location ID.
     * @return bool True if enabled, false otherwise.
     */
    public static function is_location_enabled($location_id) {
        $location_settings = get_option('hhtm_location_settings', array());

        if (isset($location_settings[$location_id]['enabled'])) {
            return (bool) $location_settings[$location_id]['enabled'];
        }

        // Default to disabled
        return false;
    }

    /**
     * Get all twin detection settings for a location.
     *
     * @param int $location_id Workforce location ID.
     * @return array Settings array.
     */
    public static function get_location_settings($location_id) {
        $location_settings = get_option('hhtm_location_settings', array());

        if (isset($location_settings[$location_id])) {
            return $location_settings[$location_id];
        }

        // Return defaults
        return array(
            'enabled'              => false,
            'custom_field'         => 'Bed Type',
            'custom_field_names'   => '',
            'custom_field_values'  => '',
            'notes_search_terms'   => '',
            'normal_booking_color' => '#ce93d8',
            'twin_booking_color'   => '#81c784',
        );
    }

    /**
     * Get normal booking color for a location.
     *
     * @param int $location_id Workforce location ID.
     * @return string Hex color code.
     */
    public static function get_normal_booking_color($location_id) {
        $settings = self::get_location_settings($location_id);
        return $settings['normal_booking_color'];
    }

    /**
     * Get twin booking color for a location.
     *
     * @param int $location_id Workforce location ID.
     * @return string Hex color code.
     */
    public static function get_twin_booking_color($location_id) {
        $settings = self::get_location_settings($location_id);
        return $settings['twin_booking_color'];
    }
}
