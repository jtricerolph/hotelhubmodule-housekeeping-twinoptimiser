/**
 * Twin Optimiser Module JavaScript
 */

(function($) {
    'use strict';

    /**
     * Initialize the twin optimiser module
     */
    function initTwinOptimiser() {
        const datePicker = $('#hhtm-start-date');

        if (!datePicker.length) {
            return;
        }

        // Date picker change handler
        datePicker.on('change', function() {
            const startDate = $(this).val();
            const days = $(this).data('days') || 14;

            if (startDate) {
                refreshTable(startDate, days);
            }
        });

        // Initialize task modal handlers
        initTaskModal();

        console.log('Twin Optimiser module initialized');
    }

    /**
     * Initialize task modal click handlers
     */
    function initTaskModal() {
        const modal = $('#hhtm-task-modal');
        const closeBtn = $('#hhtm-modal-close');

        // Delegate click event for task cells (works with dynamically loaded content)
        $(document).on('click', '.hhtm-task-content', function(e) {
            e.preventDefault();
            const $task = $(this);

            // Get task data from data attributes
            const taskType = $task.data('task-type');
            const description = $task.data('task-description');
            const icon = $task.data('task-icon');
            const color = $task.data('task-color');

            // Populate modal
            $('#hhtm-modal-task-type').text(taskType);
            $('#hhtm-modal-description').text(description || 'No description available');
            $('#hhtm-modal-icon').text(icon);

            // Set icon wrapper colors
            $('#hhtm-modal-icon-wrapper').css({
                'background-color': color,
                'border-color': color
            });

            // Show modal
            modal.addClass('active');
        });

        // Close modal on close button click
        closeBtn.on('click', function() {
            modal.removeClass('active');
        });

        // Close modal on overlay click
        modal.on('click', function(e) {
            if ($(e.target).is('.hhtm-modal-overlay')) {
                modal.removeClass('active');
            }
        });

        // Close modal on ESC key
        $(document).on('keydown', function(e) {
            if (e.key === 'Escape' && modal.hasClass('active')) {
                modal.removeClass('active');
            }
        });
    }

    /**
     * Refresh table via AJAX
     *
     * @param {string} startDate - Start date in Y-m-d format
     * @param {number} days - Number of days to display
     */
    function refreshTable(startDate, days) {
        const container = $('.hhtm-container');
        const contentDiv = $('#hhtm-table-content');

        if (!container.length || !contentDiv.length) {
            console.error('Table container not found');
            return;
        }

        // Add loading state
        container.addClass('loading');

        // Make AJAX request
        $.ajax({
            url: hhtmData.ajaxUrl,
            type: 'POST',
            data: {
                action: 'hhtm_refresh_table',
                nonce: hhtmData.nonce,
                start_date: startDate,
                days: days
            },
            success: function(response) {
                if (response.success && response.data.html) {
                    // Update table content
                    contentDiv.html(response.data.html);
                    console.log('Table refreshed successfully');
                } else {
                    console.error('Failed to refresh table:', response.data?.message || 'Unknown error');
                    alert('Failed to refresh table. Please try again.');
                }
            },
            error: function(xhr, status, error) {
                console.error('AJAX error:', error);
                alert('An error occurred while refreshing the table. Please try again.');
            },
            complete: function() {
                // Remove loading state
                container.removeClass('loading');
            }
        });
    }

    /**
     * Initialize on document ready
     */
    $(document).ready(function() {
        initTwinOptimiser();
    });

})(jQuery);
