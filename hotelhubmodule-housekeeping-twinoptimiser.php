<?php
/**
 * Plugin Name: Hotel Hub Module - Housekeeping - Twin Optimiser
 * Plugin URI: https://github.com/yourusername/hotelhubmodule-housekeeping-twinoptomiser
 * Description: Twin room optimisation module for Hotel Hub App - displays booking grid to identify twin opportunities
 * Version: 1.2.4
 * Author: Your Name
 * Author URI: https://yourwebsite.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: hhtm
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('HHTM_VERSION', '1.2.4');
define('HHTM_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('HHTM_PLUGIN_URL', plugin_dir_url(__FILE__));
define('HHTM_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * Main plugin class.
 */
class HotelHub_Module_Twin_Optimiser {

    /**
     * Singleton instance.
     *
     * @var HotelHub_Module_Twin_Optimiser
     */
    private static $instance = null;

    /**
     * Get singleton instance.
     *
     * @return HotelHub_Module_Twin_Optimiser
     */
    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Constructor.
     */
    private function __construct() {
        $this->load_dependencies();
        $this->init_hooks();
    }

    /**
     * Load plugin dependencies.
     */
    private function load_dependencies() {
        require_once HHTM_PLUGIN_DIR . 'includes/class-hhtm-settings.php';
        require_once HHTM_PLUGIN_DIR . 'includes/class-hhtm-frontend.php';
    }

    /**
     * Initialize WordPress hooks.
     */
    private function init_hooks() {
        // Register permission with workforce authentication
        add_action('init', array($this, 'register_permission'), 15);

        // Register module with Hotel Hub
        add_action('hha_register_modules', array($this, 'register_module'));

        // Enqueue assets
        add_action('wp_enqueue_scripts', array($this, 'enqueue_assets'));

        // Initialize components
        if (is_admin()) {
            new HHTM_Settings();
        }

        // Initialize AJAX handlers
        new HHTM_Frontend();
    }

    /**
     * Register permission with Workforce Authentication.
     */
    public function register_permission() {
        if (!function_exists('wfa')) {
            return;
        }

        wfa()->permissions->register_permission(
            'hhtm_access_twin_optimiser',
            __('Access Twin Optimiser', 'hhtm'),
            __('View and use the twin room optimiser module', 'hhtm'),
            'Twin Optimiser'
        );
    }

    /**
     * Register module with Hotel Hub.
     *
     * @param HHA_Modules $modules_manager Hotel Hub modules manager instance.
     */
    public function register_module($modules_manager) {
        $modules_manager->register_module($this);
    }

    /**
     * Get module configuration.
     *
     * Required by HHA_Modules.
     *
     * @return array Module configuration.
     */
    public function get_config() {
        return array(
            'id'             => 'twin_optimiser',
            'name'           => __('Twin Optimiser', 'hhtm'),
            'description'    => __('Identify twin room opportunities to optimize bookings', 'hhtm'),
            'department'     => 'housekeeping',
            'icon'           => 'dashicons-groups',
            'color'          => '#FFD700',
            'order'          => 10,
            'permissions'    => array('hhtm_access_twin_optimiser'),
            'integrations'   => array('newbook'),
            'settings_pages' => array(
                array(
                    'slug'       => 'hhtm-settings',
                    'title'      => __('Twin Optimiser Settings', 'hhtm'),
                    'menu_title' => __('Settings', 'hhtm'),
                    'callback'   => array($this, 'render_settings_page'),
                ),
            ),
        );
    }

    /**
     * Render settings page.
     *
     * Called by Hotel Hub admin menu system.
     *
     * @return void
     */
    public function render_settings_page() {
        // Get settings instance and render
        $settings = new HHTM_Settings();
        $settings->render_settings_page();
    }

    /**
     * Render module content.
     *
     * Required by HHA_Modules.
     *
     * @param array $params Optional parameters.
     * @return void
     */
    public function render($params = array()) {
        // Get frontend instance and render content
        $frontend = new HHTM_Frontend();
        $frontend->render_module_content();
    }

    /**
     * Enqueue frontend assets.
     */
    public function enqueue_assets() {
        // Only load on Hotel Hub pages
        if (!function_exists('hha')) {
            return;
        }

        // Check if we're on a Hotel Hub page
        $app_page_id = get_option('hha_app_page_id');
        if (!$app_page_id || !is_page($app_page_id)) {
            return;
        }

        // Enqueue Material Symbols (includes newer icons like 'acute')
        wp_enqueue_style(
            'material-symbols',
            'https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200',
            array(),
            null
        );

        // Enqueue styles
        wp_enqueue_style(
            'hhtm-twin-optimiser',
            HHTM_PLUGIN_URL . 'assets/css/twin-optimiser.css',
            array('material-symbols'),
            HHTM_VERSION
        );

        // Enqueue scripts
        wp_enqueue_script('jquery');

        wp_enqueue_script(
            'hhtm-twin-optimiser',
            HHTM_PLUGIN_URL . 'assets/js/twin-optimiser.js',
            array('jquery'),
            HHTM_VERSION,
            true
        );

        // Localize script
        wp_localize_script('hhtm-twin-optimiser', 'hhtmData', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('hhtm-twin-optimiser'),
        ));
    }

    /**
     * Get plugin version.
     *
     * @return string Version number.
     */
    public function get_version() {
        return HHTM_VERSION;
    }
}

/**
 * Initialize plugin.
 */
function hhtm() {
    return HotelHub_Module_Twin_Optimiser::instance();
}

// Initialize
hhtm();

/**
 * Activation hook.
 */
register_activation_hook(__FILE__, function() {
    // Register permission with workforce authentication
    if (function_exists('wfa')) {
        wfa()->permissions->register_permission(
            'hhtm_access_twin_optimiser',
            __('Access Twin Optimiser', 'hhtm'),
            __('View and use the twin room optimiser module', 'hhtm')
        );
    }

    // Flush rewrite rules
    flush_rewrite_rules();
});

/**
 * Deactivation hook.
 */
register_deactivation_hook(__FILE__, function() {
    // Flush rewrite rules
    flush_rewrite_rules();
});
