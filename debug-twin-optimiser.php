<?php
/**
 * Debug script for Twin Optimiser module
 *
 * Place this in your WordPress root directory and access it via:
 * https://n4admindev.pterois.co.uk/debug-twin-optimiser.php
 */

// Load WordPress
require_once('wp-load.php');

// Check if user is logged in
if (!is_user_logged_in()) {
    die('You must be logged in to view this page.');
}

echo "<h1>Twin Optimiser Debug</h1>";
echo "<pre>";

// Check if plugins are loaded
echo "=== PLUGIN CHECKS ===\n";
echo "Hotel Hub App active: " . (function_exists('hha') ? 'YES' : 'NO') . "\n";
echo "Workforce Auth active: " . (function_exists('wfa') ? 'YES' : 'NO') . "\n";
echo "Twin Optimiser active: " . (class_exists('HotelHub_Module_Twin_Optimiser') ? 'YES' : 'NO') . "\n\n";

// Check current hotel
echo "=== HOTEL INFO ===\n";
if (function_exists('hha')) {
    $hotel_id = hha()->auth->get_current_hotel_id();
    echo "Current Hotel ID: " . ($hotel_id ? $hotel_id : 'NONE') . "\n";

    if ($hotel_id) {
        $hotel = hha()->hotels->get($hotel_id);
        if ($hotel) {
            echo "Hotel Name: " . $hotel->name . "\n";
            echo "Location ID: " . ($hotel->location_id ? $hotel->location_id : 'NONE') . "\n";
            echo "Hotel Object: " . print_r($hotel, true) . "\n";
        } else {
            echo "ERROR: Hotel not found\n";
        }
    }
} else {
    echo "ERROR: hha() function not available\n";
}
echo "\n";

// Check module registration
echo "=== MODULE REGISTRATION ===\n";
if (function_exists('hha')) {
    $all_modules = hha()->modules->get_modules();
    echo "Total modules registered: " . count($all_modules) . "\n";

    if (isset($all_modules['twin_optimiser'])) {
        echo "Twin Optimiser registered: YES\n";
        echo "Config: " . print_r($all_modules['twin_optimiser'], true) . "\n";
    } else {
        echo "Twin Optimiser registered: NO\n";
        echo "Available modules: " . implode(', ', array_keys($all_modules)) . "\n";
    }
}
echo "\n";

// Check settings
echo "=== TWIN OPTIMISER SETTINGS ===\n";
$settings = get_option('hhtm_location_settings', array());
echo "Settings: " . print_r($settings, true) . "\n\n";

// Check if enabled for current hotel
if (function_exists('hha') && $hotel_id && $hotel) {
    echo "=== LOCATION STATUS ===\n";
    if ($hotel->location_id) {
        $is_enabled = HHTM_Settings::is_location_enabled($hotel->location_id);
        echo "Location ID: " . $hotel->location_id . "\n";
        echo "Module enabled: " . ($is_enabled ? 'YES' : 'NO') . "\n";
    } else {
        echo "Hotel has no location assigned\n";
    }
}
echo "\n";

// Check NewBook integration
if (function_exists('hha') && $hotel_id && $hotel) {
    echo "=== NEWBOOK INTEGRATION ===\n";
    $integration = hha()->integrations->get_settings($hotel->id, 'newbook');
    if ($integration) {
        echo "Integration configured: YES\n";
        echo "Has username: " . (isset($integration['username']) ? 'YES' : 'NO') . "\n";
        echo "Has password: " . (isset($integration['password']) ? 'YES' : 'NO') . "\n";
        echo "Has api_key: " . (isset($integration['api_key']) ? 'YES' : 'NO') . "\n";
        echo "Region: " . (isset($integration['region']) ? $integration['region'] : 'NOT SET') . "\n";
    } else {
        echo "Integration configured: NO\n";
    }
}
echo "\n";

// Check permissions
echo "=== PERMISSIONS ===\n";
$user_id = get_current_user_id();
echo "User ID: " . $user_id . "\n";
if (function_exists('wfa_user_can')) {
    echo "Has twin optimiser permission: " . (wfa_user_can('hhtm_access_twin_optimiser') ? 'YES' : 'NO') . "\n";
} else {
    echo "wfa_user_can function not available\n";
}
echo "\n";

// Try to load the module
echo "=== ATTEMPTING MODULE LOAD ===\n";
try {
    if (function_exists('hha') && $hotel_id) {
        ob_start();
        hha()->modules->render_module('twin_optimiser');
        $output = ob_get_clean();
        echo "Module output length: " . strlen($output) . " bytes\n";
        echo "First 500 chars:\n" . substr($output, 0, 500) . "\n";
    }
} catch (Exception $e) {
    echo "EXCEPTION: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}

echo "</pre>";
