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

        if (empty($bookings)) {
            echo '<div class="hhtm-no-results">';
            echo '<p>' . __('No bookings found for the selected date range.', 'hhtm') . '</p>';
            echo '</div>';
            return;
        }

        // Get custom field name for this location
        $custom_field_name = HHTM_Settings::get_location_custom_field($hotel->location_id);

        // Process bookings into grid structure
        $grid_data = $this->process_bookings($bookings, $start_date, $days, $custom_field_name);

        // Render grid
        $this->render_grid_table($grid_data, $start_date, $days);
    }

    /**
     * Process bookings into grid structure.
     *
     * @param array  $bookings         Array of booking objects.
     * @param string $start_date       Start date (Y-m-d).
     * @param int    $days             Number of days.
     * @param string $custom_field_name Custom field name for twin detection.
     * @return array Processed grid data.
     */
    private function process_bookings($bookings, $start_date, $days, $custom_field_name) {
        $grid = array();
        $rooms = array();

        // Create date range
        $dates = array();
        for ($i = 0; $i < $days; $i++) {
            $dates[] = date('Y-m-d', strtotime($start_date . ' + ' . $i . ' days'));
        }

        // Process each booking
        foreach ($bookings as $booking) {
            $room_name = $booking['room_name'];

            // Initialize room if not exists
            if (!isset($grid[$room_name])) {
                $grid[$room_name] = array();
                $rooms[] = $room_name;
            }

            // Get booking dates
            $checkin = date('Y-m-d', strtotime($booking['arrival_date']));
            $checkout = date('Y-m-d', strtotime($booking['departure_date']));

            // Get bed type
            $bed_type = $this->get_bed_type($booking, $custom_field_name);
            $is_twin = $this->is_twin_booking($bed_type);

            // Fill in grid for booking dates
            foreach ($dates as $date) {
                if ($date >= $checkin && $date < $checkout) {
                    if (!isset($grid[$room_name][$date])) {
                        $grid[$room_name][$date] = array(
                            'booking_id'  => $booking['id'],
                            'booking_ref' => $booking['booking_reference'],
                            'bed_type'    => $bed_type,
                            'is_twin'     => $is_twin,
                            'checkin'     => $checkin,
                            'checkout'    => $checkout,
                        );
                    }
                }
            }
        }

        // Sort rooms
        sort($rooms);

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
                <?php foreach ($rooms as $room): ?>
                    <tr>
                        <td class="hhtm-room-cell"><?php echo esc_html($room); ?></td>
                        <?php
                        $previous_booking = null;
                        foreach ($dates as $date):
                            $booking = isset($grid[$room][$date]) ? $grid[$room][$date] : null;

                            // Skip if this is a continuation of the previous booking
                            if ($previous_booking && $booking && $booking['booking_id'] === $previous_booking['booking_id']) {
                                continue;
                            }

                            if ($booking):
                                // Calculate colspan
                                $colspan = 1;
                                $next_date = $date;
                                for ($i = 1; $i < count($dates); $i++) {
                                    $next_date = date('Y-m-d', strtotime($date . ' + ' . $i . ' days'));
                                    if (isset($grid[$room][$next_date]) && $grid[$room][$next_date]['booking_id'] === $booking['booking_id']) {
                                        $colspan++;
                                    } else {
                                        break;
                                    }
                                }

                                $cell_class = 'hhtm-cell-booked';
                                if ($booking['is_twin']) {
                                    $cell_class = 'hhtm-cell-twin';
                                }
                                ?>
                                <td class="hhtm-booking-cell <?php echo esc_attr($cell_class); ?>" colspan="<?php echo esc_attr($colspan); ?>">
                                    <div class="hhtm-booking-content">
                                        <span class="hhtm-booking-ref"><?php echo esc_html($booking['booking_ref']); ?></span>
                                        <?php if (!empty($booking['bed_type'])): ?>
                                            <span class="hhtm-bed-type"><?php echo esc_html($booking['bed_type']); ?></span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <?php
                                $previous_booking = $booking;
                            else:
                                ?>
                                <td class="hhtm-booking-cell hhtm-cell-vacant"></td>
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
