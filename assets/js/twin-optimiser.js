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

        // Initialize twin detection modal handlers
        initTwinModal();

        // Initialize global key handlers
        initGlobalKeyHandlers();

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

        // NOTE: ESC key handler is shared - see bottom of file
    }

    /**
     * Initialize twin detection modal click handlers
     */
    function initTwinModal() {
        const modal = $('#hhtm-twin-modal');
        const closeBtn = $('#hhtm-twin-modal-close');

        // Delegate click event for twin booking cells
        $(document).on('click', '.hhtm-cell-twin, .hhtm-cell-potential-twin', function(e) {
            const $cell = $(this);
            const twinType = $cell.data('twin-type');
            const detectionDetails = $cell.data('detection-details');

            if (!detectionDetails) {
                return;
            }

            // Hide all sections first
            $('#hhtm-twin-field-section').hide();
            $('#hhtm-twin-value-section').hide();
            $('#hhtm-twin-term-section').hide();
            $('#hhtm-twin-note-section').hide();

            if (twinType === 'twin') {
                // Confirmed twin - show custom field details
                $('#hhtm-twin-modal-title').text('Confirmed Twin Booking');
                $('#hhtm-twin-modal-icon').text('verified').css('color', '#4caf50');

                if (detectionDetails.field_name) {
                    $('#hhtm-twin-field-name').text(detectionDetails.field_name);
                    $('#hhtm-twin-field-section').show();
                }

                if (detectionDetails.field_value) {
                    $('#hhtm-twin-field-value').text(detectionDetails.field_value);
                    $('#hhtm-twin-value-section').show();
                }

                if (detectionDetails.matched_term) {
                    $('#hhtm-twin-matched-term').text(detectionDetails.matched_term);
                    $('#hhtm-twin-term-section').show();
                }
            } else if (twinType === 'potential_twin') {
                // Potential twin - show note details with highlighting
                $('#hhtm-twin-modal-title').text('Potential Twin Booking');
                $('#hhtm-twin-modal-icon').text('help').css('color', '#ff9800');

                if (detectionDetails.matched_term) {
                    $('#hhtm-twin-matched-term').text(detectionDetails.matched_term);
                    $('#hhtm-twin-term-section').show();
                }

                if (detectionDetails.note_content) {
                    const noteContent = detectionDetails.note_content;
                    const matchedTerm = detectionDetails.matched_term;

                    // Highlight matched term in note content
                    let highlightedNote = noteContent;
                    if (matchedTerm) {
                        const regex = new RegExp('(' + matchedTerm.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ')', 'gi');
                        highlightedNote = noteContent.replace(regex, '<mark style="background: #ffeb3b; padding: 2px 4px; border-radius: 2px;">$1</mark>');
                    }

                    $('#hhtm-twin-note-content').html(highlightedNote);
                    $('#hhtm-twin-note-section').show();
                }
            }

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

        // NOTE: ESC key handler is shared - see bottom of file
    }

    /**
     * Shared ESC key handler for all modals
     */
    function initGlobalKeyHandlers() {
        $(document).on('keydown', function(e) {
            if (e.key === 'Escape') {
                // Close any active modal
                $('.hhtm-modal-overlay.active').removeClass('active');
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
