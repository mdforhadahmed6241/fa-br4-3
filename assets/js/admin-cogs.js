jQuery(document).ready(function($) {
    'use strict';

    /**
     * Handle adding new dynamic rule rows.
     */
    $('#br-add-rule-btn').on('click', function() {
        var tableBody = $('#br-dynamic-rules-body');
        var rowCount = tableBody.find('.br-rule-row').length;

        var newRow = `
            <tr class="br-rule-row">
                <td><input type="number" step="0.01" name="br_cogs_settings[dynamic_rules][${rowCount}][min]" placeholder="0.00"></td>
                <td><input type="number" step="0.01" name="br_cogs_settings[dynamic_rules][${rowCount}][max]" placeholder="100.00"></td>
                <td>
                    <select name="br_cogs_settings[dynamic_rules][${rowCount}][type]">
                        <option value="fixed">Fixed Amount</option>
                        <option value="percentage">Percentage (%)</option>
                    </select>
                </td>
                <td><input type="number" step="0.01" name="br_cogs_settings[dynamic_rules][${rowCount}][value]"></td>
                <td><button type="button" class="button button-secondary br-remove-rule-btn">Remove</button></td>
            </tr>
        `;
        tableBody.append(newRow);
    });

    /**
     * Handle removing dynamic rule rows.
     * Use event delegation to handle dynamically added rows.
     */
    $('#br-dynamic-rules-table').on('click', '.br-remove-rule-btn', function() {
        $(this).closest('tr').remove();
        // After removing, we should re-index the rows to ensure keys are sequential on save.
        $('#br-dynamic-rules-body .br-rule-row').each(function(index) {
            $(this).find('input, select').each(function() {
                var name = $(this).attr('name');
                if (name) {
                    var newName = name.replace(/\[\d+\]/, '[' + index + ']');
                    $(this).attr('name', newName);
                }
            });
        });
    });

    /**
     * Handle the "Apply Rules to Existing Products" button click via AJAX.
     */
    $('#br-apply-rules-btn').on('click', function() {
        var button = $(this);
        var spinner = $('#br-apply-rules-spinner');
        var feedback = $('#br-apply-rules-feedback');

        // Show loading state
        button.prop('disabled', true);
        spinner.addClass('is-active');
        feedback.text('Processing... Please do not close this window.').css('color', 'blue');

        $.ajax({
            url: br_cogs_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'br_apply_rules_to_existing',
                nonce: br_cogs_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    feedback.text(response.data.message).css('color', 'green');
                } else {
                    feedback.text('An error occurred. Please try again.').css('color', 'red');
                }
            },
            error: function() {
                feedback.text('A server error occurred. Please try again.').css('color', 'red');
            },
            complete: function() {
                // Restore button state
                button.prop('disabled', false);
                spinner.removeClass('is-active');
            }
        });
    });

});
