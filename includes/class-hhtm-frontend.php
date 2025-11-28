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

            $tasks_response = $api->get_tasks($period_from, $period_to, $task_type_ids, true);

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
        $grid_data = $this->process_bookings($bookings, $tasks, $start_date, $days, $custom_field_name, $categories_sort, $task_types);

        // Check if processing resulted in any rooms
        if (empty($grid_data['rooms'])) {
            echo '<div class="hhtm-error">';
            echo '<p>' . __('No rooms found in bookings. Please check that bookings have room assignments.', 'hhtm') . '</p>';
            echo '<p style="font-size: 12px; color: #666;">' . sprintf(__('Found %d bookings but could not extract room information.', 'hhtm'), count($bookings)) . '</p>';
            echo '</div>';
            return;
        }

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
     * @return array Processed grid data.
     */
    private function process_bookings($bookings, $tasks, $start_date, $days, $custom_field_name, $categories_sort = array(), $task_types = array()) {
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

            // Skip bookings without room assignment
            if (empty($room_name)) {
                continue;
            }

            // Skip excluded sites (using site_id for matching)
            if ($room_site_id && in_array($room_site_id, $excluded_sites)) {
                continue;
            }

            // Skip sites from excluded categories (using site_id and category_id for matching)
            if ($room_site_id && isset($site_to_category[$room_site_id])) {
                $site_category_id = $site_to_category[$room_site_id]['category_id'];
                if (in_array($site_category_id, $excluded_categories)) {
                    continue;
                }
            }

            // Initialize room if not exists
            if (!isset($grid[$room_name])) {
                // Determine category name (use configured category if site_id matches, otherwise Uncategorized)
                $category_name = 'Uncategorized';
                if ($room_site_id && isset($site_to_category[$room_site_id])) {
                    $category_name = $site_to_category[$room_site_id]['category_name'];
                }

                $grid[$room_name] = array(
                    'category' => $category_name,
                    'site_id'  => $room_site_id,
                );
                $rooms[] = $room_name;
            }

            // Get booking dates (NewBook API field names)
            $checkin = date('Y-m-d', strtotime($booking['booking_arrival']));
            $checkout = date('Y-m-d', strtotime($booking['booking_departure']));

            // Get bed type
            $bed_type = $this->get_bed_type($booking, $custom_field_name);
            $is_twin = $this->is_twin_booking($bed_type);

            // Fill in grid for booking dates
            foreach ($dates as $date) {
                if ($date >= $checkin && $date < $checkout) {
                    if (!isset($grid[$room_name][$date])) {
                        $grid[$room_name][$date] = array(
                            'booking_id'  => $booking['booking_id'],
                            'booking_ref' => $booking['booking_reference_id'],
                            'bed_type'    => $bed_type,
                            'is_twin'     => $is_twin,
                            'checkin'     => $checkin,
                            'checkout'    => $checkout,
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
                if (empty($room_name) && !empty($task['booking_site_name'])) {
                    $room_name = $task['booking_site_name'];
                    $room_site_id = isset($task['booking_site_id']) ? $task['booking_site_id'] : '';
                }

                // Skip tasks without site assignment
                if (empty($room_name)) {
                    continue;
                }

                // Skip excluded sites (using site_id for matching)
                if ($room_site_id && in_array($room_site_id, $excluded_sites)) {
                    continue;
                }

                // Skip sites from excluded categories (using site_id and category_id for matching)
                if ($room_site_id && isset($site_to_category[$room_site_id])) {
                    $site_category_id = $site_to_category[$room_site_id]['category_id'];
                    if (in_array($site_category_id, $excluded_categories)) {
                        continue;
                    }
                }

                // Initialize room if not exists
                if (!isset($grid[$room_name])) {
                    // Determine category name (use configured category if site_id matches, otherwise Uncategorized)
                    $category_name = 'Uncategorized';
                    if ($room_site_id && isset($site_to_category[$room_site_id])) {
                        $category_name = $site_to_category[$room_site_id]['category_name'];
                    }

                    $grid[$room_name] = array(
                        'category' => $category_name,
                        'site_id'  => $room_site_id,
                    );
                    $rooms[] = $room_name;
                }

                // Get task date (NewBook API returns task_when_date as YYYY-MM-DD)
                $task_date = isset($task['task_when_date']) ? date('Y-m-d', strtotime($task['task_when_date'])) : '';

                if (empty($task_date)) {
                    continue;
                }

                // Check if task date is within our date range
                if (!in_array($task_date, $dates)) {
                    continue;
                }

                // Get task type configuration
                $task_type_id = isset($task['task_type_id']) ? $task['task_type_id'] : '';
                $task_type_config = isset($task_types_map[$task_type_id]) ? $task_types_map[$task_type_id] : null;

                // Add task to grid
                if (!isset($grid[$room_name][$task_date])) {
                    $grid[$room_name][$task_date] = array();
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
                if (isset($grid[$room_name][$task_date]['booking_id'])) {
                    // Initialize tasks array if it doesn't exist
                    if (!isset($grid[$room_name][$task_date]['tasks'])) {
                        $grid[$room_name][$task_date]['tasks'] = array();
                    }
                    $grid[$room_name][$task_date]['tasks'][] = $task_info;
                } else {
                    // No booking on this date, task takes the cell
                    $grid[$room_name][$task_date] = $task_info;
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
     * Check if booking is a twin based on bed type.
     *
     * @param string $bed_type Bed type value.
     * @return bool True if twin, false otherwise.
     */
    private function is_twin_booking($bed_type) {
        if (empty($bed_type)) {
            return false;
        }

        $bed_type_lower = strtolower($bed_type);

        // Check for twin indicators
        if (strpos($bed_type_lower, 'twin') !== false) {
            return true;
        }

        if (preg_match('/2\s*x?\s*single/i', $bed_type_lower)) {
            return true;
        }

        return false;
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
                        <td class="hhtm-room-cell"><?php echo esc_html($room); ?></td>
                        <?php
                        $previous_booking = null;
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

                                $cell_class = 'hhtm-cell-booked';
                                if ($booking['is_twin']) {
                                    $cell_class = 'hhtm-cell-twin';
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
                                ?>
                                <td class="hhtm-booking-cell <?php echo esc_attr($cell_class); ?>"
                                    colspan="<?php echo esc_attr($colspan); ?>"
                                    title="<?php echo esc_attr($tooltip); ?>">
                                    <div class="hhtm-booking-content">
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
                            elseif ($is_task):
                                // Task-only cell
                                $task = $cell_data;
                                $task_color = isset($task['color']) ? $task['color'] : '#9e9e9e';
                                $task_icon = isset($task['icon']) ? $task['icon'] : 'task';
                                $task_name = isset($task['task_type_name']) ? $task['task_type_name'] : 'Task';
                                $task_desc = isset($task['description']) ? $task['description'] : '';

                                // Build tooltip
                                $tooltip = $task_name;
                                if ($task_desc) {
                                    $tooltip .= "\n" . $task_desc;
                                }
                                ?>
                                <td class="hhtm-booking-cell hhtm-cell-task"
                                    style="background-color: <?php echo esc_attr($task_color); ?>33;"
                                    title="<?php echo esc_attr($tooltip); ?>">
                                    <div class="hhtm-task-content">
                                        <span class="material-icons hhtm-task-icon" style="color: <?php echo esc_attr($task_color); ?>;">
                                            <?php echo esc_html($task_icon); ?>
                                        </span>
                                        <span class="hhtm-task-name"><?php echo esc_html($task_name); ?></span>
                                    </div>
                                </td>
                                <?php
                                $previous_booking = null;
                            else:
                                // Vacant cell
                                ?>
                                <td class="hhtm-booking-cell hhtm-cell-vacant" title="Vacant"></td>
                                <?php
                                $previous_booking = null;
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
}
