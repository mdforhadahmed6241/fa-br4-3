/**
 * Business Report - Expense Management Admin JS
 */
jQuery(function($) {
    'use strict';

    // --- Reusable Modal Functions ---
    const modals = {
        expense: $('#br-add-expense-modal'),
        category: $('#br-add-category-modal'),
        monthly: $('#br-add-monthly-expense-modal'),
        customRange: $('#br-expense-custom-range-filter-modal') // NEW
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

    // NEW: Open Custom Range Filter Modal
    wrapper.on('click', '#br-expense-custom-range-trigger', function(e) {
        e.preventDefault();
        const activeTabLink = $('.nav-tab-wrapper .nav-tab-active').attr('href');
        let currentTab = 'expense_list'; // Default
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


    // --- "Add" Button Click Handlers ---
    wrapper.on('click', '#br-add-expense-btn', function() {
        const form = modals.expense.find('form');
        form[0].reset();
        form.find('#expense_id').val('');
        form.find('#expense_date').val(new Date().toISOString().slice(0, 10));
        modals.expense.find('#br-expense-modal-title').text('Add New Expense');
        openModal(modals.expense);
    });

    wrapper.on('click', '#br-add-category-btn', function() {
        const form = modals.category.find('form');
        form[0].reset();
        form.find('#category_id').val('');
        modals.category.find('#br-category-modal-title').text('Add New Category');
        openModal(modals.category);
    });

    wrapper.on('click', '#br-add-monthly-expense-btn', function() {
        const form = modals.monthly.find('form');
        form[0].reset();
        form.find('#monthly_expense_id').val('');
        modals.monthly.find('#br-monthly-expense-modal-title').text('Add Monthly Expense');
        openModal(modals.monthly);
    });


    // --- Form Submission Handlers ---
    wrapper.on('submit', '#br-add-category-form, #br-add-expense-form, #br-add-monthly-expense-form', function(e) {
        e.preventDefault();
        const form = $(this);
        const button = form.find('button[type="submit"]');
        button.prop('disabled', true);
        
        let action = '';
        if (form.is('#br-add-category-form')) action = 'br_save_expense_category';
        if (form.is('#br-add-expense-form')) action = 'br_save_expense';
        if (form.is('#br-add-monthly-expense-form')) action = 'br_save_monthly_expense';

        const postData = form.serialize() + '&action=' + action + '&nonce=' + br_expense_ajax.nonce;

        $.post(br_expense_ajax.ajax_url, postData).done(response => {
            if (response.success) {
                window.location.reload();
            } else {
                alert(response.data.message || 'An error occurred.');
                button.prop('disabled', false);
            }
        }).fail(() => {
             alert('A server error occurred.');
             button.prop('disabled', false);
        });
    });


    // --- "Edit" Button Click Handlers (Event Delegation) ---
    wrapper.on('click', '.br-edit-expense-btn', function(e) {
        e.preventDefault();
        const id = $(this).data('id');
        $.post(br_expense_ajax.ajax_url, { action: 'br_get_expense_details', nonce: br_expense_ajax.nonce, id: id })
            .done(response => {
                if (response.success) {
                    const d = response.data;
                    const form = modals.expense.find('form');
                    form.find('#expense_id').val(d.id);
                    form.find('#expense_reason').val(d.reason);
                    form.find('#expense_date').val(d.expense_date);
                    form.find('#expense_category_id').val(d.category_id);
                    form.find('#expense_amount').val(d.amount);
                    modals.expense.find('#br-expense-modal-title').text('Edit Expense');
                    openModal(modals.expense);
                } else {
                    alert(response.data.message);
                }
            });
    });

    wrapper.on('click', '.br-edit-monthly-expense-btn', function(e) {
        e.preventDefault();
        const id = $(this).data('id');
        $.post(br_expense_ajax.ajax_url, { action: 'br_get_monthly_expense_details', nonce: br_expense_ajax.nonce, id: id })
            .done(response => {
                if (response.success) {
                    const d = response.data;
                    const form = modals.monthly.find('form');
                    form.find('#monthly_expense_id').val(d.id);
                    form.find('#monthly_expense_reason').val(d.reason);
                    form.find('#monthly_expense_listed_date').val(d.listed_date);
                    form.find('#monthly_expense_category_id').val(d.category_id);
                    form.find('#monthly_expense_amount').val(d.amount);
                    modals.monthly.find('#br-monthly-expense-modal-title').text('Edit Monthly Expense');
                    openModal(modals.monthly);
                } else {
                    alert(response.data.message);
                }
            });
    });


    // --- "Delete" Button Click Handlers (Event Delegation) ---
    wrapper.on('click', '.br-delete-expense-btn, .br-delete-monthly-expense-btn', function(e) {
        e.preventDefault();
        
        let confirm_message = 'Are you sure you want to delete this item?';
        let action = '';
        if ($(this).is('.br-delete-expense-btn')) {
            action = 'br_delete_expense';
        } else {
            action = 'br_delete_monthly_expense';
        }
        
        if (!confirm(confirm_message)) return;

        const button = $(this);
        const id = button.data('id');
        button.prop('disabled', true);

        $.post(br_expense_ajax.ajax_url, { action: action, nonce: br_expense_ajax.nonce, id: id })
            .done(() => button.closest('tr').fadeOut(300, function() { $(this).remove(); }));
    });
});

