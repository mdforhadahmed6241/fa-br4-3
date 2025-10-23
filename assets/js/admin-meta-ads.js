/**
 * Business Report - Meta Ads Admin JS
 *
 * @version 1.3.6
 */
jQuery(function($) {
    'use strict';

    console.log('Business Report Meta Ads JS Loaded Successfully! Version 1.3.6');

    try {
        const wrapper = $('.br-wrap');

        // --- Reusable Feedback Function ---
        function showFeedback(message, isError = false) {
            const feedbackEl = $('#br-sync-feedback');
            feedbackEl.html(message).removeClass('error success').addClass(isError ? 'error' : 'success').fadeIn();
            setTimeout(() => feedbackEl.fadeOut(), 15000);
        }

        // --- Modal Handling ---
        const addAccountModal = $('#br-add-account-modal');
        const customSyncModal = $('#br-custom-sync-modal');
        const customRangeModal = $('#br-custom-range-filter-modal');

        function openModal(modal) {
            const datepickers = modal.find('.br-datepicker');
            if (!datepickers.hasClass('hasDatepicker')) {
                datepickers.datepicker({
                    dateFormat: 'yy-mm-dd',
                    changeMonth: true,
                    changeYear: true
                });
            }
            modal.fadeIn(200);
        }

        function closeModal(modal) {
            modal.fadeOut(200);
        }

        $(window).on('click', function(e) {
            if ($(e.target).is('.br-modal')) {
                closeModal($(e.target));
            }
        });

        $('.br-modal').on('click', '.br-modal-close, .br-modal-cancel', function() {
            closeModal($(this).closest('.br-modal'));
        });

        // --- Event Delegation from the main wrapper ---

        // Dropdown Toggle
        wrapper.on('click', '.br-dropdown-toggle', function(e) {
            e.preventDefault();
            $(this).next('.br-dropdown-menu').fadeToggle(100);
        });

        // Open Custom Range Filter Modal
        wrapper.on('click', '#br-custom-range-trigger', function(e) {
            e.preventDefault();
            const activeTabLink = $('.nav-tab-wrapper .nav-tab-active').attr('href');
            let currentTab = 'summary';
            if (activeTabLink) {
                const urlParams = new URLSearchParams(activeTabLink.split('?')[1]);
                if (urlParams.has('tab')) {
                    currentTab = urlParams.get('tab');
                }
            }
            customRangeModal.find('input[name="tab"]').val(currentTab);
            $('.br-dropdown-menu').fadeOut(100);
            openModal(customRangeModal);
        });
        
        // Open Custom Sync Modal
        wrapper.on('click', '#br-custom-sync-btn', function() {
            openModal(customSyncModal);
        });

        // Open Add/Edit Account Modals
        wrapper.on('click', '#br-add-account-btn, .br-edit-account-btn', function(e) {
            e.preventDefault();
            const card = $(this).closest('.br-ad-account-card');
            const accountId = card.length ? card.data('account-id') : '';
            const form = $('#br-add-account-form');
            const submitButton = form.find('button[type="submit"]');
            
            form[0].reset();
            form.find('#account_id').val('');
            submitButton.prop('disabled', false);

            if (accountId) {
                form.find('#br-modal-title').text('Edit Meta Ads Account');
                $.post(br_meta_ads_ajax.ajax_url, { action: 'br_get_meta_account_details', nonce: br_meta_ads_ajax.nonce, account_id: accountId })
                    .done(function(response) {
                        if (response.success) {
                            const acc = response.data;
                            form.find('#account_id').val(acc.id);
                            form.find('#account_name').val(acc.account_name);
                            form.find('#ad_account_id').val(acc.ad_account_id);
                            form.find('#usd_to_bdt_rate').val(acc.usd_to_bdt_rate);
                            form.find('#is_active').prop('checked', acc.is_active == 1);
                        } else {
                            alert(response.data.message);
                        }
                    });
            } else {
                form.find('#br-modal-title').text('Add Meta Ads Account');
            }
            openModal(addAccountModal);
        });

        // --- Sync Logic & Buttons ---
        function triggerSync(data) {
            const spinner = $('#br-sync-spinner');
            const buttons = $('.br-sync-actions .button');
            buttons.prop('disabled', true);
            spinner.addClass('is-active');
            showFeedback('Syncing... This may take a moment.');

            data.action = 'br_sync_meta_data';
            data.nonce = br_meta_ads_ajax.nonce;

            $.post(br_meta_ads_ajax.ajax_url, data)
                .done(function(response) {
                    const message = response.data ? response.data.message.replace(/\n/g, '<br>') : 'An unknown error occurred.';
                    showFeedback(message, !response.success);
                    if (response.success) {
                        setTimeout(() => window.location.reload(), 3000);
                    }
                })
                .fail(function() {
                    showFeedback('An AJAX error occurred. Check the browser console for details.', true);
                })
                .always(function() {
                    spinner.removeClass('is-active');
                    buttons.prop('disabled', false);
                });
        }
        
        function getLocalDateString(date) {
            return date.getFullYear() + '-' + ('0' + (date.getMonth() + 1)).slice(-2) + '-' + ('0' + date.getDate()).slice(-2);
        }
        
        wrapper.on('click', '#br-sync-today-btn', function() {
            const today = getLocalDateString(new Date());
            triggerSync({ start_date: today, end_date: today });
        });
        
        wrapper.on('click', '#br-sync-7-days-btn', function() {
            const endDate = new Date();
            const startDate = new Date();
            startDate.setDate(endDate.getDate() - 6);
            triggerSync({ start_date: getLocalDateString(startDate), end_date: getLocalDateString(endDate) });
        });

        // --- FORM SUBMISSIONS ---
        wrapper.on('submit', '#br-custom-sync-form', function(e) {
            e.preventDefault();
            const data = {
                start_date: $(this).find('#sync_start_date').val(),
                end_date: $(this).find('#sync_end_date').val(),
                account_ids: $(this).find('input[name="account_ids[]"]:checked').map((_, el) => $(el).val()).get()
            };
            if (!data.start_date || !data.end_date) return alert('Please select a start and end date.');
            if (data.account_ids.length === 0) return alert('Please select at least one account to sync.');
            triggerSync(data);
        });

        wrapper.on('submit', '#br-add-account-form', function(e) {
            e.preventDefault();
            const form = $(this);
            const submitButton = form.find('button[type="submit"]');
            const data = {
                action: 'br_save_meta_account',
                nonce: br_meta_ads_ajax.nonce,
                account_id: form.find('#account_id').val(),
                account_name: form.find('#account_name').val(),
                access_token: form.find('#access_token').val(),
                ad_account_id: form.find('#ad_account_id').val(),
                usd_to_bdt_rate: form.find('#usd_to_bdt_rate').val(),
                is_active: form.find('#is_active').is(':checked') ? 'true' : 'false'
            };
            
            submitButton.prop('disabled', true);

            $.post(br_meta_ads_ajax.ajax_url, data)
                .done(function(response) {
                    alert(response.data.message);
                    if (response.success) {
                        window.location.reload();
                    } else {
                        submitButton.prop('disabled', false);
                    }
                })
                .fail(function() {
                    alert('An AJAX error occurred. Please try again.');
                    submitButton.prop('disabled', false);
                });
        });

        // --- OTHER ACTIONS ---
        $('#br-select-all-accounts').on('click', () => $('#br-custom-sync-form .br-checklist input').prop('checked', true));
        $('#br-deselect-all-accounts').on('click', () => $('#br-custom-sync-form .br-checklist input').prop('checked', false));

        wrapper.on('click', '.br-delete-account-btn', function(e) {
            e.preventDefault();
            if (!confirm('Are you sure?')) return;
            const accountId = $(this).closest('.br-ad-account-card').data('account-id');
            $.post(br_meta_ads_ajax.ajax_url, { action: 'br_delete_meta_account', nonce: br_meta_ads_ajax.nonce, account_id: accountId })
                .done(function(response) {
                    alert(response.data.message);
                    if (response.success) window.location.reload();
                });
        });

        wrapper.on('change', '.br-status-toggle', function() {
            const card = $(this).closest('.br-ad-account-card');
            $.post(br_meta_ads_ajax.ajax_url, {
                action: 'br_toggle_account_status',
                nonce: br_meta_ads_ajax.nonce,
                account_id: card.data('account-id'),
                is_active: $(this).is(':checked')
            });
        });

        // **FIX:** Correctly delegate the click event for the delete button from the main wrapper.
        wrapper.on('click', '.br-delete-summary-btn', function(e) {
            e.preventDefault();
            if (!confirm('Are you sure you want to delete this entry? This cannot be undone.')) {
                return;
            }

            const button = $(this);
            const row = button.closest('tr');
            const entryId = button.data('id');

            button.prop('disabled', true);

            $.post(br_meta_ads_ajax.ajax_url, {
                action: 'br_delete_summary_entry',
                nonce: br_meta_ads_ajax.nonce,
                entry_id: entryId
            })
            .done(function(response) {
                if (response.success) {
                    row.css('background-color', '#FFBABA').fadeOut(400, function() {
                        $(this).remove();
                    });
                } else {
                    alert(response.data.message || 'Could not delete the entry.');
                    button.prop('disabled', false);
                }
            })
            .fail(function() {
                alert('An AJAX error occurred. Please try again.');
                button.prop('disabled', false);
            });
        });

    } catch (error) {
        console.error("Business Report JS Error:", error);
    }
});

