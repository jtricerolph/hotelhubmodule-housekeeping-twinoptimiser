<?php
/**
 * Frontend - Handles frontend display of twin optimiser module.
 *
 * Renders the booking grid and handles AJAX refresh requests.
 *
 * @package HotelHub_Module_Twin_Optimiser
 */

if (!defined('ABSPATH')) {
    exit;
}

class HHTM_Frontend {

    /**
     * Constructor.
     */
    public function __construct() {
        add_action('wp_ajax_hhtm_refresh_table', array($this, 'ajax_refresh_table'));
    }

    /**
     * Render module content.
     */
    public function render_module_content() {
        // Enqueue styles and scripts inline for AJAX-loaded modules
        $this->enqueue_inline_assets();

        // Get current hotel
        $hotel_id = hha()->auth->get_current_hotel_id();

        if (!$hotel_id) {
            echo '<div class="hhtm-no-hotel">';
            echo '<p>' . __('Please select a hotel first.', 'hhtm') . '</p>';
            echo '</div>';
            return;
        }

        // Get hotel details
        $hotel = hha()->hotels->get($hotel_id);

        if (!$hotel) {
            echo '<div class="hhtm-error">';
            echo '<p>' . __('Hotel not found.', 'hhtm') . '</p>';
            echo '</div>';
            return;
        }

        // Check if hotel has a location assigned
        if (empty($hotel->location_id)) {
            echo '<div class="hhtm-error">';
            echo '<p>' . __('This hotel does not have a workforce location assigned. Please contact your administrator.', 'hhtm') . '</p>';
            echo '</div>';
            return;
        }

        // Check if module is enabled for this location
        if (!HHTM_Settings::is_location_enabled($hotel->location_id)) {
            echo '<div class="hhtm-error">';
            echo '<p>' . __('Twin Optimiser is not enabled for this location. Please contact your administrator.', 'hhtm') . '</p>';
            echo '</div>';
            return;
        }

        // Default parameters
        $start_date = isset($_GET['start_date']) ? sanitize_text_field($_GET['start_date']) : date('Y-m-d');
        $days = 14;

        ?>
        <div class="hhtm-container">
            <div class="hhtm-header">
                <h2><?php _e('Twin Optimiser', 'hhtm'); ?></h2>
                <div class="hhtm-date-picker-container">
                    <label for="hhtm-start-date"><?php _e('Start Date:', 'hhtm'); ?></label>
                    <input
                        type="date"
                        id="hhtm-start-date"
                        class="hhtm-date-picker"
                        value="<?php echo esc_attr($start_date); ?>"
                        data-days="<?php echo esc_attr($days); ?>"
                    >
                </div>
            </div>

            <div class="hhtm-table-wrapper">
                <div id="hhtm-table-content">
                    <?php $this->render_booking_grid($hotel, $start_date, $days); ?>
                </div>
            </div>
        </div>

        <!-- Task Detail Modal -->
        <div id="hhtm-task-modal" class="hhtm-modal-overlay">
            <div class="hhtm-modal">
                <div class="hhtm-modal-header">
                    <div class="hhtm-modal-title">
                        <div class="hhtm-modal-icon" id="hhtm-modal-icon-wrapper">
                            <span class="material-icons" id="hhtm-modal-icon"></span>
                        </div>
                        <span id="hhtm-modal-task-type"></span>
                    </div>
                    <button class="hhtm-modal-close" id="hhtm-modal-close">&times;</button>
                </div>
                <div class="hhtm-modal-body">
                    <div class="hhtm-modal-section">
                        <div class="hhtm-modal-label">Description</div>
                        <div class="hhtm-modal-value" id="hhtm-modal-description"></div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render booking grid.
     *
     * @param object $hotel      Hotel object.
     * @param string $start_date Start date (Y-m-d).
     * @param int    $days       Number of days to display.
     */
    private function render_booking_grid($hotel, $start_date, $days) {
        // Get hotel integration details
        $integration = hha()->integrations->get_settings($hotel->id, 'newbook');

        if (!$integration) {
            echo '<div class="hhtm-no-integration">';
            echo '<p>' . __('NewBook integration not configured for this hotel.', 'hhtm') . '</p>';
            echo '</div>';
            return;
        }

        // Create NewBook API client
        require_once HHA_PLUGIN_DIR . 'includes/class-hha-newbook-api.php';
        $api = new HHA_NewBook_API($integration);

        // Calculate date range
        $period_from = $start_date;
        $period_to = date('Y-m-d', strtotime($start_date . ' + ' . ($days - 1) . ' days'));

        // Get bookings
        $response = $api->get_bookings($period_from, $period_to, 'staying');

        if (!$response['success']) {
            echo '<div class="hhtm-error">';
            echo '<p>' . sprintf(__('Error fetching bookings: %s', 'hhtm'), esc_html($response['message'])) . '</p>';
            echo '</div>';
            return;
        }

        $bookings = isset($response['data']) ? $response['data'] : array();

        // Get task types configuration
        $task_types = isset($integration['task_types']) ? $integration['task_types'] : array();

        // Fetch tasks if task types are configured
        $tasks = array();
        if (!empty($task_types)) {
            // Get all task type IDs
            $task_type_ids = array_map(function($type) {
                return $type['id'];
            }, $task_types);

            // Fetch all tasks (completed and uncompleted) with fresh data
            // Parameters: period_from, period_to, task_type_ids, show_uncomplete=false, created_when=null, force_refresh=true
            $tasks_response = $api->get_tasks($period_from, $period_to, $task_type_ids, false, null, true);

            if ($tasks_response['success']) {
                $tasks = isset($tasks_response['data']) ? $tasks_response['data'] : array();
            }
        }

        if (empty($bookings) && empty($tasks)) {
            echo '<div class="hhtm-no-results">';
            echo '<p>' . __('No bookings or tasks found for the selected date range.', 'hhtm') . '</p>';
            echo '</div>';
            return;
        }

        // Debug: Log booking structure
        error_log('HHTM: Found ' . count($bookings) . ' bookings and ' . count($tasks) . ' tasks');
        if (!empty($bookings)) {
            error_log('HHTM: First booking structure: ' . print_r($bookings[0], true));
        }

        // Get custom field name for this location
        $custom_field_name = HHTM_Settings::get_location_custom_field($hotel->location_id);

        // Get categories sort configuration
        $categories_sort = isset($integration['categories_sort']) ? $integration['categories_sort'] : array();

        // Process bookings and tasks into grid structure
        $grid_data = $this->process_bookings($bookings, $tasks, $start_date, $days, $custom_field_name, $categories_sort, $task_types, $hotel);

        // Check if processing resulted in any rooms
        if (empty($grid_data['rooms'])) {
            echo '<div class="hhtm-error">';
            echo '<p>' . __('No rooms found in bookings. Please check that bookings have room assignments.', 'hhtm') . '</p>';
            echo '<p style="font-size: 12px; color: #666;">' . sprintf(__('Found %d bookings but could not extract room information.', 'hhtm'), count($bookings)) . '</p>';
            echo '</div>';
            return;
        }

        // Get location colors
        $normal_color = HHTM_Settings::get_normal_booking_color($hotel->location_id);
        $twin_color = HHTM_Settings::get_twin_booking_color($hotel->location_id);
        $potential_twin_color = HHTM_Settings::get_potential_twin_color($hotel->location_id);

        // Inject custom CSS for colors
        ?>
        <style>
            .hhtm-cell-booked .hhtm-booking-content {
                background: <?php echo esc_attr($normal_color); ?> !important;
                border-color: <?php echo esc_attr($this->adjust_color_brightness($normal_color, -20)); ?> !important;
            }
            .hhtm-cell-twin .hhtm-booking-content {
                background: <?php echo esc_attr($twin_color); ?> !important;
                border-color: <?php echo esc_attr($this->adjust_color_brightness($twin_color, -20)); ?> !important;
            }
            .hhtm-cell-potential-twin .hhtm-booking-content {
                background: <?php echo esc_attr($potential_twin_color); ?> !important;
                border-color: <?php echo esc_attr($this->adjust_color_brightness($potential_twin_color, -20)); ?> !important;
            }
        </style>
        <?php

        // Render grid
        $this->render_grid_table($grid_data, $start_date, $days);
    }

    /**
     * Process bookings and tasks into grid structure.
     *
     * @param array  $bookings         Array of booking objects.
     * @param array  $tasks            Array of task objects.
     * @param string $start_date       Start date (Y-m-d).
     * @param int    $days             Number of days.
     * @param string $custom_field_name Custom field name for twin detection.
     * @param array  $categories_sort  Categories and sites sort configuration.
     * @param array  $task_types       Task types configuration with colors and icons.
     * @param object $hotel            Hotel object with location_id.
     * @return array Processed grid data.
     */
    private function process_bookings($bookings, $tasks, $start_date, $days, $custom_field_name, $categories_sort = array(), $task_types = array(), $hotel = null) {
        $grid = array();
        $rooms = array();

        // Create date range
        $dates = array();
        for ($i = 0; $i < $days; $i++) {
            $dates[] = date('Y-m-d', strtotime($start_date . ' + ' . $i . ' days'));
        }

        // Build site-to-category map and exclusion list (using IDs for matching)
        $site_to_category = array();
        $excluded_sites = array();
        $excluded_categories = array();
        $site_order_map = array();

        if (!empty($categories_sort)) {
            foreach ($categories_sort as $cat_index => $category) {
                $category_id = isset($category['id']) ? $category['id'] : null;
                $category_name = isset($category['name']) ? $category['name'] : '';

                if (!empty($category['excluded'])) {
                    if ($category_id) {
                        $excluded_categories[] = $category_id;
                    }
                    continue;
                }

                if (isset($category['sites'])) {
                    foreach ($category['sites'] as $site_index => $site) {
                        $site_id = isset($site['site_id']) ? $site['site_id'] : '';

                        if ($site_id) {
                            $site_to_category[$site_id] = array(
                                'category_id'   => $category_id,
                                'category_name' => $category_name,
                            );
                            $site_order_map[$site_id] = array(
                                'category_order' => $cat_index,
                                'site_order'     => $site_index,
                            );

                            if (!empty($site['excluded'])) {
                                $excluded_sites[] = $site_id;
                            }
                        }
                    }
                }
            }
        }

        // Process each booking
        foreach ($bookings as $booking) {
            // NewBook API uses 'site_name' for room name
            $room_name = isset($booking['site_name']) ? $booking['site_name'] : '';
            $room_site_id = isset($booking['site_id']) ? $booking['site_id'] : '';

            // Skip bookings without room assignment or site_id
            if (empty($room_name) || empty($room_site_id)) {
                continue;
            }

            // Skip excluded sites (using site_id for matching)
            if (in_array($room_site_id, $excluded_sites)) {
                continue;
            }

            // Skip sites from excluded categories (using site_id and category_id for matching)
            if (isset($site_to_category[$room_site_id])) {
                $site_category_id = $site_to_category[$room_site_id]['category_id'];
                if (in_array($site_category_id, $excluded_categories)) {
                    continue;
                }
            }

            // Use site_id as grid key to consolidate bookings and tasks for same site
            $grid_key = $room_site_id;

            // Initialize room if not exists
            if (!isset($grid[$grid_key])) {
                // Determine category name (use configured category if site_id matches, otherwise Uncategorized)
                $category_name = 'Uncategorized';
                if (isset($site_to_category[$room_site_id])) {
                    $category_name = $site_to_category[$room_site_id]['category_name'];
                }

                $grid[$grid_key] = array(
                    'category'  => $category_name,
                    'site_id'   => $room_site_id,
                    'site_name' => $room_name,
                );
                $rooms[] = $grid_key;
            }

            // Get booking dates (NewBook API field names)
            $checkin = date('Y-m-d', strtotime($booking['booking_arrival']));
            $checkout = date('Y-m-d', strtotime($booking['booking_departure']));

            // Get bed type
            $bed_type = $this->get_bed_type($booking, $custom_field_name);

            // Get location settings for enhanced detection
            $location_settings = HHTM_Settings::get_location_settings($hotel->location_id);
            $booking_type = $this->is_twin_booking($booking, $bed_type, $location_settings);

            // Check for locked booking
            $is_locked = isset($booking['booking_locked']) && $booking['booking_locked'] == '1';

            // Check for early check-in (before 15:00:00)
            $is_early_checkin = false;
            $arrival_time = isset($booking['booking_arrival']) ? $booking['booking_arrival'] : '';
            $eta_time = isset($booking['booking_eta']) ? $booking['booking_eta'] : '';

            // Check booking_arrival time
            if (!empty($arrival_time)) {
                $arrival_datetime = strtotime($arrival_time);
                $arrival_hour = (int)date('H', $arrival_datetime);
                $arrival_minute = (int)date('i', $arrival_datetime);
                $arrival_time_minutes = ($arrival_hour * 60) + $arrival_minute;
                if ($arrival_time_minutes < (15 * 60)) { // Before 15:00:00
                    $is_early_checkin = true;
                }
            }

            // Check booking_eta time if arrival didn't trigger early
            if (!$is_early_checkin && !empty($eta_time)) {
                $eta_datetime = strtotime($eta_time);
                $eta_hour = (int)date('H', $eta_datetime);
                $eta_minute = (int)date('i', $eta_datetime);
                $eta_time_minutes = ($eta_hour * 60) + $eta_minute;
                if ($eta_time_minutes < (15 * 60)) { // Before 15:00:00
                    $is_early_checkin = true;
                }
            }

            // Fill in grid for booking dates
            foreach ($dates as $date) {
                if ($date >= $checkin && $date < $checkout) {
                    if (!isset($grid[$grid_key][$date])) {
                        $grid[$grid_key][$date] = array(
                            'booking_id'      => $booking['booking_id'],
                            'booking_ref'     => $booking['booking_reference_id'],
                            'bed_type'        => $bed_type,
                            'booking_type'    => $booking_type,
                            'checkin'         => $checkin,
                            'checkout'        => $checkout,
                            'is_locked'       => $is_locked,
                            'is_early_checkin' => $is_early_checkin,
                        );
                    }
                }
            }
        }

        // Process tasks
        if (!empty($tasks) && !empty($task_types)) {
            // Build task types map for quick lookup
            $task_types_map = array();
            foreach ($task_types as $task_type) {
                if (isset($task_type['id'])) {
                    $task_types_map[$task_type['id']] = $task_type;
                }
            }

            // Process each task
            foreach ($tasks as $task) {
                // Skip tasks that don't occupy a location
                if (empty($task['task_location_occupy']) || $task['task_location_occupy'] != 1) {
                    continue;
                }

                // Determine room/site information
                $room_name = '';
                $room_site_id = '';

                // First try task_location_id (direct site assignment)
                if (!empty($task['task_location_id'])) {
                    $room_site_id = $task['task_location_id'];
                    $room_name = isset($task['task_location_name']) ? $task['task_location_name'] : '';
                }

                // Fallback to booking site info
                if (empty($room_site_id) && !empty($task['booking_site_id'])) {
                    $room_site_id = $task['booking_site_id'];
                    $room_name = isset($task['booking_site_name']) ? $task['booking_site_name'] : '';
                }

                // Skip tasks without site_id
                if (empty($room_site_id)) {
                    continue;
                }

                // Skip excluded sites (using site_id for matching)
                if (in_array($room_site_id, $excluded_sites)) {
                    continue;
                }

                // Skip sites from excluded categories (using site_id and category_id for matching)
                if (isset($site_to_category[$room_site_id])) {
                    $site_category_id = $site_to_category[$room_site_id]['category_id'];
                    if (in_array($site_category_id, $excluded_categories)) {
                        continue;
                    }
                }

                // Use site_id as grid key to consolidate with bookings for same site
                $grid_key = $room_site_id;

                // Initialize room if not exists
                if (!isset($grid[$grid_key])) {
                    // Determine category name (use configured category if site_id matches, otherwise Uncategorized)
                    $category_name = 'Uncategorized';
                    if (isset($site_to_category[$room_site_id])) {
                        $category_name = $site_to_category[$room_site_id]['category_name'];
                    }

                    $grid[$grid_key] = array(
                        'category'  => $category_name,
                        'site_id'   => $room_site_id,
                        'site_name' => $room_name,
                    );
                    $rooms[] = $grid_key;
                }

                // Get task dates - handle both single-day and multi-day tasks
                $task_dates = array();

                if (isset($task['task_when_date']) && !empty($task['task_when_date'])) {
                    // Single-day task
                    $task_dates[] = date('Y-m-d', strtotime($task['task_when_date']));
                } elseif (isset($task['task_period_from']) && isset($task['task_period_to'])) {
                    // Multi-day task - create array of all dates in range
                    $period_from = date('Y-m-d', strtotime($task['task_period_from']));
                    $period_to = date('Y-m-d', strtotime($task['task_period_to']));

                    $current_date = $period_from;
                    while ($current_date < $period_to) {
                        $task_dates[] = $current_date;
                        $current_date = date('Y-m-d', strtotime($current_date . ' + 1 day'));
                    }
                }

                if (empty($task_dates)) {
                    continue;
                }

                // Get task type configuration
                $task_type_id = isset($task['task_type_id']) ? $task['task_type_id'] : '';
                $task_type_config = isset($task_types_map[$task_type_id]) ? $task_types_map[$task_type_id] : null;

                // Add task to grid for each date it spans
                foreach ($task_dates as $task_date) {
                    // Check if task date is within our date range
                    if (!in_array($task_date, $dates)) {
                        continue;
                    }

                    // Add task to grid
                    if (!isset($grid[$grid_key][$task_date])) {
                        $grid[$grid_key][$task_date] = array();
                    }

                    // Store task info
                    $task_info = array(
                        'type'        => 'task',
                        'task_id'     => isset($task['task_id']) ? $task['task_id'] : '',
                        'task_type_id' => $task_type_id,
                        'description' => isset($task['task_description']) ? $task['task_description'] : '',
                        'date'        => $task_date,
                    );

                    // Add task type styling if configured
                    if ($task_type_config) {
                        $task_info['color'] = isset($task_type_config['color']) ? $task_type_config['color'] : '#9e9e9e';
                        $task_info['icon'] = isset($task_type_config['icon']) ? $task_type_config['icon'] : 'task';
                        $task_info['task_type_name'] = isset($task_type_config['name']) ? $task_type_config['name'] : '';
                    }

                    // If there's already a booking on this date, store task separately
                    if (isset($grid[$grid_key][$task_date]['booking_id'])) {
                        // Initialize tasks array if it doesn't exist
                        if (!isset($grid[$grid_key][$task_date]['tasks'])) {
                            $grid[$grid_key][$task_date]['tasks'] = array();
                        }
                        $grid[$grid_key][$task_date]['tasks'][] = $task_info;
                    } else {
                        // No booking on this date, task takes the cell
                        $grid[$grid_key][$task_date] = $task_info;
                    }
                }
            }
        }

        // Sort rooms by category and site order (using site_id for matching)
        if (!empty($site_order_map)) {
            usort($rooms, function($a, $b) use ($site_order_map, $grid) {
                // Get site_id for each room
                $a_site_id = isset($grid[$a]['site_id']) ? $grid[$a]['site_id'] : '';
                $b_site_id = isset($grid[$b]['site_id']) ? $grid[$b]['site_id'] : '';

                // Get order based on site_id
                $a_order = ($a_site_id && isset($site_order_map[$a_site_id])) ? $site_order_map[$a_site_id] : array('category_order' => 9999, 'site_order' => 9999);
                $b_order = ($b_site_id && isset($site_order_map[$b_site_id])) ? $site_order_map[$b_site_id] : array('category_order' => 9999, 'site_order' => 9999);

                // First sort by category order
                if ($a_order['category_order'] !== $b_order['category_order']) {
                    return $a_order['category_order'] - $b_order['category_order'];
                }

                // Then sort by site order within category
                if ($a_order['site_order'] !== $b_order['site_order']) {
                    return $a_order['site_order'] - $b_order['site_order'];
                }

                // Fallback to alphabetical
                return strcasecmp($a, $b);
            });
        } else {
            // Default alphabetical sort
            sort($rooms);
        }

        return array(
            'grid'  => $grid,
            'rooms' => $rooms,
            'dates' => $dates,
        );
    }

    /**
     * Get bed type from booking custom fields.
     *
     * @param array  $booking          Booking data.
     * @param string $custom_field_name Custom field name to check.
     * @return string Bed type or empty string.
     */
    private function get_bed_type($booking, $custom_field_name) {
        if (!isset($booking['custom_fields']) || !is_array($booking['custom_fields'])) {
            return '';
        }

        foreach ($booking['custom_fields'] as $field) {
            if (isset($field['label']) && $field['label'] === $custom_field_name) {
                return isset($field['value']) ? $field['value'] : '';
            }
        }

        return '';
    }

    /**
     * Check if booking is a twin using enhanced detection.
     *
     * @param array  $booking          Full booking data.
     * @param string $bed_type         Bed type value from legacy field.
     * @param array  $location_settings Location settings with detection rules.
     * @return string Detection type: 'twin' (confirmed), 'potential_twin', or 'normal'.
     */
    private function is_twin_booking($booking, $bed_type, $location_settings) {
        // Enhanced custom field detection
        $custom_field_names = !empty($location_settings['custom_field_names']) ?
            array_map('trim', explode(',', $location_settings['custom_field_names'])) : array();
        $custom_field_values = !empty($location_settings['custom_field_values']) ?
            array_map('trim', explode(',', $location_settings['custom_field_values'])) : array();

        // Check configured custom fields - this is PRIMARY/CONFIRMED detection
        if (!empty($custom_field_names) && !empty($custom_field_values)) {
            foreach ($custom_field_names as $field_name) {
                if (empty($field_name)) {
                    continue;
                }

                // Get custom field value from booking
                $field_value = '';
                if (isset($booking['booking_custom_fields']) && is_array($booking['booking_custom_fields'])) {
                    foreach ($booking['booking_custom_fields'] as $custom_field) {
                        if (isset($custom_field['name']) && $custom_field['name'] === $field_name) {
                            $field_value = isset($custom_field['value']) ? $custom_field['value'] : '';
                            break;
                        }
                    }
                }

                if (!empty($field_value)) {
                    $field_value_lower = strtolower($field_value);

                    // Check against configured values
                    foreach ($custom_field_values as $search_value) {
                        if (empty($search_value)) {
                            continue;
                        }

                        $search_value_lower = strtolower($search_value);
                        if (strpos($field_value_lower, $search_value_lower) !== false) {
                            return 'twin'; // Confirmed twin via custom fields
                        }
                    }
                }
            }
        }

        // Legacy detection (fallback for confirmed twin)
        if (!empty($bed_type)) {
            $bed_type_lower = strtolower($bed_type);

            // Check for twin indicators
            if (strpos($bed_type_lower, 'twin') !== false) {
                return 'twin'; // Confirmed twin via legacy field
            }

            if (preg_match('/2\s*x?\s*single/i', $bed_type_lower)) {
                return 'twin'; // Confirmed twin via legacy field
            }
        }

        // Enhanced notes search - this is POTENTIAL detection (not confirmed)
        $notes_search_terms = !empty($location_settings['notes_search_terms']) ?
            array_map('trim', explode(',', $location_settings['notes_search_terms'])) : array();

        if (!empty($notes_search_terms)) {
            if (isset($booking['notes']) && is_array($booking['notes'])) {
                foreach ($booking['notes'] as $note) {
                    $note_content = isset($note['content']) ? $note['content'] : '';
                    if (empty($note_content)) {
                        continue;
                    }

                    $note_content_lower = strtolower($note_content);

                    // Check against configured search terms
                    foreach ($notes_search_terms as $search_term) {
                        if (empty($search_term)) {
                            continue;
                        }

                        $search_term_lower = strtolower($search_term);
                        if (strpos($note_content_lower, $search_term_lower) !== false) {
                            return 'potential_twin'; // Potential twin - notes suggest but fields don't confirm
                        }
                    }
                }
            }
        }

        return 'normal';
    }

    /**
     * Render grid table HTML.
     *
     * @param array  $grid_data  Grid data structure.
     * @param string $start_date Start date (Y-m-d).
     * @param int    $days       Number of days.
     */
    private function render_grid_table($grid_data, $start_date, $days) {
        $grid = $grid_data['grid'];
        $rooms = $grid_data['rooms'];
        $dates = $grid_data['dates'];

        ?>
        <table class="hhtm-booking-grid">
            <thead>
                <tr>
                    <th class="hhtm-room-header"><?php _e('Room', 'hhtm'); ?></th>
                    <?php foreach ($dates as $date): ?>
                        <th class="hhtm-date-header">
                            <div class="hhtm-date-label">
                                <span class="hhtm-day"><?php echo date('D', strtotime($date)); ?></span>
                                <span class="hhtm-date"><?php echo date('d/m', strtotime($date)); ?></span>
                            </div>
                        </th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php
                // Group rooms by category for rendering
                $current_category = null;
                $column_count = count($dates) + 1; // +1 for room column

                foreach ($rooms as $room):
                    // Get category for this room
                    $room_category = isset($grid[$room]['category']) ? $grid[$room]['category'] : 'Uncategorized';

                    // Render category header if category changed
                    if ($current_category !== $room_category):
                        $current_category = $room_category;
                        ?>
                        <tr class="hhtm-category-header">
                            <td colspan="<?php echo esc_attr($column_count); ?>">
                                <strong><?php echo esc_html($room_category); ?></strong>
                            </td>
                        </tr>
                    <?php endif; ?>
                    <tr>
                        <td class="hhtm-room-cell"><?php echo esc_html(isset($grid[$room]['site_name']) ? $grid[$room]['site_name'] : $room); ?></td>
                        <?php
                        $previous_booking = null;
                        $previous_task = null;
                        foreach ($dates as $date):
                            $cell_data = isset($grid[$room][$date]) ? $grid[$room][$date] : null;

                            // Determine cell type
                            $is_booking = $cell_data && isset($cell_data['booking_id']);
                            $is_task = $cell_data && isset($cell_data['type']) && $cell_data['type'] === 'task';
                            $has_tasks = $cell_data && isset($cell_data['tasks']) && !empty($cell_data['tasks']);

                            // Skip if this is a continuation of the previous booking
                            if ($previous_booking && $is_booking && $cell_data['booking_id'] === $previous_booking['booking_id']) {
                                continue;
                            }

                            // Skip if this is a continuation of the previous task
                            if ($previous_task && $is_task && $cell_data['task_id'] === $previous_task['task_id']) {
                                continue;
                            }

                            if ($is_booking):
                                // Booking cell (may have tasks overlay)
                                $booking = $cell_data;

                                // Calculate colspan
                                $colspan = 1;
                                $next_date = $date;
                                for ($i = 1; $i < count($dates); $i++) {
                                    $next_date = date('Y-m-d', strtotime($date . ' + ' . $i . ' days'));
                                    if (isset($grid[$room][$next_date]) && isset($grid[$room][$next_date]['booking_id']) && $grid[$room][$next_date]['booking_id'] === $booking['booking_id']) {
                                        $colspan++;
                                    } else {
                                        break;
                                    }
                                }

                                // Determine cell class based on booking type
                                $booking_type = isset($booking['booking_type']) ? $booking['booking_type'] : 'normal';
                                if ($booking_type === 'twin') {
                                    $cell_class = 'hhtm-cell-twin';
                                } elseif ($booking_type === 'potential_twin') {
                                    $cell_class = 'hhtm-cell-potential-twin';
                                } else {
                                    $cell_class = 'hhtm-cell-booked';
                                }

                                // Build tooltip text
                                $tooltip = sprintf(
                                    'Ref: %s | %s-%s',
                                    $booking['booking_ref'],
                                    date('d/m', strtotime($booking['checkin'])),
                                    date('d/m', strtotime($booking['checkout']))
                                );
                                if (!empty($booking['bed_type'])) {
                                    $tooltip .= ' | ' . $booking['bed_type'];
                                }

                                // Add tasks to tooltip if present
                                if ($has_tasks) {
                                    $tooltip .= "\n\nTasks:";
                                    foreach ($cell_data['tasks'] as $task) {
                                        $task_name = isset($task['task_type_name']) ? $task['task_type_name'] : 'Task';
                                        $task_desc = isset($task['description']) ? $task['description'] : '';
                                        $tooltip .= "\n- " . $task_name;
                                        if ($task_desc) {
                                            $tooltip .= ': ' . $task_desc;
                                        }
                                    }
                                }
                                // Check for early check-in and locked status
                                $is_early_checkin = isset($booking['is_early_checkin']) ? $booking['is_early_checkin'] : false;
                                $is_locked = isset($booking['is_locked']) ? $booking['is_locked'] : false;
                                ?>
                                <td class="hhtm-booking-cell <?php echo esc_attr($cell_class); ?>"
                                    colspan="<?php echo esc_attr($colspan); ?>"
                                    title="<?php echo esc_attr($tooltip); ?>">
                                    <div class="hhtm-booking-content">
                                        <?php if ($is_early_checkin || $is_locked): ?>
                                            <div class="hhtm-booking-indicators">
                                                <?php if ($is_early_checkin): ?>
                                                    <span class="material-icons hhtm-booking-icon hhtm-early-checkin-icon" title="Early Check-in">acute</span>
                                                <?php endif; ?>
                                                <?php if ($is_locked): ?>
                                                    <span class="material-icons hhtm-booking-icon hhtm-locked-icon" title="Locked to Room">lock</span>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                        <span class="hhtm-booking-ref"><?php echo esc_html($booking['booking_ref']); ?></span>
                                        <?php if (!empty($booking['bed_type'])): ?>
                                            <span class="hhtm-bed-type"><?php echo esc_html($booking['bed_type']); ?></span>
                                        <?php endif; ?>
                                        <?php if ($has_tasks): ?>
                                            <div class="hhtm-task-indicators">
                                                <?php foreach ($cell_data['tasks'] as $task): ?>
                                                    <?php
                                                    $task_color = isset($task['color']) ? $task['color'] : '#9e9e9e';
                                                    $task_icon = isset($task['icon']) ? $task['icon'] : 'task';
                                                    ?>
                                                    <span class="hhtm-task-indicator material-icons" style="color: <?php echo esc_attr($task_color); ?>;">
                                                        <?php echo esc_html($task_icon); ?>
                                                    </span>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <?php
                                $previous_booking = $booking;
                                $previous_task = null;
                            elseif ($is_task):
                                // Task-only cell
                                $task = $cell_data;

                                // Calculate colspan for task
                                $colspan = 1;
                                $next_date = $date;
                                for ($i = 1; $i < count($dates); $i++) {
                                    $next_date = date('Y-m-d', strtotime($date . ' + ' . $i . ' days'));
                                    if (isset($grid[$room][$next_date]) && isset($grid[$room][$next_date]['task_id']) && $grid[$room][$next_date]['task_id'] === $task['task_id']) {
                                        $colspan++;
                                    } else {
                                        break;
                                    }
                                }

                                $task_color = isset($task['color']) ? $task['color'] : '#9e9e9e';
                                $task_icon = isset($task['icon']) ? $task['icon'] : 'task';
                                $task_name = isset($task['task_type_name']) ? $task['task_type_name'] : 'Task';
                                $task_desc = isset($task['description']) ? $task['description'] : '';
                                ?>
                                <td class="hhtm-booking-cell hhtm-cell-task"
                                    colspan="<?php echo esc_attr($colspan); ?>">
                                    <div class="hhtm-task-content"
                                         style="background-color: <?php echo esc_attr($task_color); ?>; border-color: <?php echo esc_attr($task_color); ?>;"
                                         data-task-type="<?php echo esc_attr($task_name); ?>"
                                         data-task-description="<?php echo esc_attr($task_desc); ?>"
                                         data-task-icon="<?php echo esc_attr($task_icon); ?>"
                                         data-task-color="<?php echo esc_attr($task_color); ?>">
                                        <span class="material-icons hhtm-task-icon" style="color: #fff;">
                                            <?php echo esc_html($task_icon); ?>
                                        </span>
                                    </div>
                                </td>
                                <?php
                                $previous_booking = null;
                                $previous_task = $task;
                            else:
                                // Vacant cell
                                ?>
                                <td class="hhtm-booking-cell hhtm-cell-vacant" title="Vacant"></td>
                                <?php
                                $previous_booking = null;
                                $previous_task = null;
                            endif;
                        endforeach;
                        ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    /**
     * AJAX handler to refresh table.
     */
    public function ajax_refresh_table() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'hhtm-twin-optimiser')) {
            wp_send_json_error(array('message' => __('Security check failed.', 'hhtm')));
        }

        // Check user permissions
        if (!wfa_user_can('hhtm_access_twin_optimiser')) {
            wp_send_json_error(array('message' => __('You do not have permission to access this module.', 'hhtm')));
        }

        // Get parameters
        $start_date = isset($_POST['start_date']) ? sanitize_text_field($_POST['start_date']) : date('Y-m-d');
        $days = isset($_POST['days']) ? absint($_POST['days']) : 14;

        // Get current hotel
        $hotel_id = hha()->auth->get_current_hotel_id();

        if (!$hotel_id) {
            wp_send_json_error(array('message' => __('No hotel selected.', 'hhtm')));
        }

        // Get hotel
        $hotel = hha()->hotels->get($hotel_id);

        if (!$hotel) {
            wp_send_json_error(array('message' => __('Hotel not found.', 'hhtm')));
        }

        // Render table
        ob_start();
        $this->render_booking_grid($hotel, $start_date, $days);
        $html = ob_get_clean();

        wp_send_json_success(array('html' => $html));
    }

    /**
     * Enqueue assets inline for AJAX-loaded modules.
     */
    private function enqueue_inline_assets() {
        // Output Material Icons stylesheet
        echo '<link rel="stylesheet" href="https://fonts.googleapis.com/icon?family=Material+Icons">';

        // Output module CSS
        echo '<link rel="stylesheet" href="' . esc_url(HHTM_PLUGIN_URL . 'assets/css/twin-optimiser.css?ver=' . HHTM_VERSION) . '">';

        // Output module JavaScript
        ?>
        <script>
            var hhtmData = {
                ajaxUrl: '<?php echo esc_js(admin_url('admin-ajax.php')); ?>',
                nonce: '<?php echo esc_js(wp_create_nonce('hhtm-twin-optimiser')); ?>'
            };
        </script>
        <script src="<?php echo esc_url(HHTM_PLUGIN_URL . 'assets/js/twin-optimiser.js?ver=' . HHTM_VERSION); ?>"></script>
        <?php
    }

    /**
     * Adjust color brightness for borders.
     *
     * @param string $hex_color Hex color code.
     * @param int    $percent   Percent to adjust (-100 to 100).
     * @return string Adjusted hex color.
     */
    private function adjust_color_brightness($hex_color, $percent) {
        // Remove # if present
        $hex_color = ltrim($hex_color, '#');

        // Convert to RGB
        $r = hexdec(substr($hex_color, 0, 2));
        $g = hexdec(substr($hex_color, 2, 2));
        $b = hexdec(substr($hex_color, 4, 2));

        // Adjust
        $r = max(0, min(255, $r + ($r * $percent / 100)));
        $g = max(0, min(255, $g + ($g * $percent / 100)));
        $b = max(0, min(255, $b + ($b * $percent / 100)));

        // Convert back to hex
        return sprintf('#%02x%02x%02x', $r, $g, $b);
    }
}
