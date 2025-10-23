/**
 * Business Report - Order Report Admin JS
 */
jQuery(function($) {
    'usestrict';

    const modals = {
        customRange: $('#br-order-custom-range-filter-modal')
    };

    function openModal(modal) {
        const datepicker = modal.find('.br-datepicker');
        if (datepicker.length && !datepicker.hasClass('hasDatepicker')) {
            datepicker.datepicker({
                dateFormat: 'yy-mm-dd',
                changeMonth: true,
                changeYear: true,
                onSelect: function(dateText) {
                    $(this).val(dateText);
                }
            });
        }
        modal.fadeIn(200);
    }

    function closeModal(modal) {
        modal.fadeOut(200);
    }

    // --- Event Delegation ---
    const wrapper = $('.br-wrap');

    // Close modal on background click or close button
    wrapper.on('click', '.br-modal', function(e) {
        if ($(e.target).is('.br-modal') || $(e.target).is('.br-modal-close') || $(e.target).is('.br-modal-cancel')) {
            closeModal($(this));
        }
    });
    
    // Dropdown Toggle
    wrapper.on('click', '.br-dropdown-toggle', function(e) {
        e.preventDefault();
        $(this).next('.br-dropdown-menu').fadeToggle(100);
    });

    // Open Custom Range Filter Modal
    wrapper.on('click', '#br-order-custom-range-trigger', function(e) {
        e.preventDefault();
        const activeTabLink = $('.nav-tab-wrapper .nav-tab-active').attr('href');
        let currentTab = 'summary'; // Default
        if (activeTabLink) {
            const urlParams = new URLSearchParams(activeTabLink.split('?')[1]);
            if (urlParams.has('tab')) {
                currentTab = urlParams.get('tab');
            }
        }
        modals.customRange.find('input[name="tab"]').val(currentTab);
        $('.br-dropdown-menu').fadeOut(100); // Close dropdown
        openModal(modals.customRange);
    });

});
