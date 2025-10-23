<?php
/**
 * Expense Management Functionality for Business Report Plugin
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

// Include the List Table classes.
require_once plugin_dir_path( __FILE__ ) . 'classes/class-br-expense-list-table.php';
require_once plugin_dir_path( __FILE__ ) . 'classes/class-br-monthly-expense-list-table.php';
require_once plugin_dir_path( __FILE__ ) . 'classes/class-br-expense-category-list-table.php';

/**
 * =================================================================================
 * 1. ADMIN PAGE & ASSETS
 * =================================================================================
 */

function br_expense_page_html() {
    if (!current_user_can('manage_woocommerce')) return;
    $active_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'expense_list';
    ?>
    <div class="wrap br-wrap">
        <div class="br-header">
            <h1><?php _e('Expense Management', 'business-report'); ?></h1>
        </div>

        <h2 class="nav-tab-wrapper">
            <a href="?page=br-expense&tab=expense_list" class="nav-tab <?php echo $active_tab == 'expense_list' ? 'nav-tab-active' : ''; ?>"><?php _e('Expense List', 'business-report'); ?></a>
            <a href="?page=br-expense&tab=monthly_expense" class="nav-tab <?php echo $active_tab == 'monthly_expense' ? 'nav-tab-active' : ''; ?>"><?php _e('Monthly Expense', 'business-report'); ?></a>
            <a href="?page=br-expense&tab=expense_category" class="nav-tab <?php echo $active_tab == 'expense_category' ? 'nav-tab-active' : ''; ?>"><?php _e('Expense Category', 'business-report'); ?></a>
        </h2>
        <div class="br-page-content">
        <?php
        switch ($active_tab) {
            case 'monthly_expense':
                br_monthly_expense_tab_html();
                break;
            case 'expense_category':
                br_expense_category_tab_html();
                break;
            default:
                br_expense_list_tab_html();
                break;
        }
        ?>
        </div>
        <?php 
        br_expense_add_expense_modal_html();
        br_expense_add_category_modal_html();
        br_expense_add_monthly_expense_modal_html();
        br_expense_custom_range_filter_modal_html();
        ?>
    </div>
    <?php
}

function br_expense_admin_enqueue_scripts( $hook ) {
	if ( 'business-report_page_br-expense' !== $hook ) {
		return;
	}
    wp_enqueue_script('jquery-ui-datepicker');
    wp_enqueue_style('wp-jquery-ui-dialog');

	$js_version = filemtime( plugin_dir_path( __FILE__ ) . '../assets/js/admin-expenses.js' );
	wp_enqueue_script(
		'br-expenses-admin-js',
		plugin_dir_url( __FILE__ ) . '../assets/js/admin-expenses.js',
		[ 'jquery', 'jquery-ui-datepicker' ],
		$js_version,
		true
	);
	wp_localize_script( 'br-expenses-admin-js', 'br_expense_ajax', [
		'ajax_url' => admin_url( 'admin-ajax.php' ),
		'nonce'    => wp_create_nonce( 'br_expense_nonce' ),
	] );
}
add_action( 'admin_enqueue_scripts', 'br_expense_admin_enqueue_scripts' );


/**
 * =================================================================================
 * 2. TAB RENDERING FUNCTIONS
 * =================================================================================
 */

function br_expense_list_tab_html() {
    $expense_list_table = new BR_Expense_List_Table();
    $expense_list_table->prepare_items();
    br_expense_render_date_filters_html('expense_list', $expense_list_table);
    ?>
    <form id="br-expense-list-form" method="post">
        <?php $expense_list_table->display(); ?>
    </form>
    <?php
}

function br_monthly_expense_tab_html() {
    $monthly_list_table = new BR_Monthly_Expense_List_Table();
    $monthly_list_table->prepare_items();
    ?>
    <div class="br-table-top-actions">
        <div></div> <?php // Spacer ?>
        <button id="br-add-monthly-expense-btn" class="button button-primary"><?php _e('Add Monthly Expense', 'business-report'); ?></button>
    </div>
    <form id="br-monthly-expense-list-form" method="post">
        <?php $monthly_list_table->display(); ?>
    </form>
    <?php
}

function br_expense_category_tab_html() {
    $category_list_table = new BR_Expense_Category_List_Table();
    $category_list_table->prepare_items();
    br_expense_render_date_filters_html('expense_category');
    ?>
    <form id="br-expense-category-list-form" method="post">
        <?php $category_list_table->display(); ?>
    </form>
    <?php
}

function br_expense_render_date_filters_html($current_tab = 'expense_list', $list_table_object = null) {
    $current_range_key = isset($_GET['range']) ? sanitize_key($_GET['range']) : 'this_month';
    $start_date_get = isset($_GET['start_date']) ? sanitize_text_field($_GET['start_date']) : null;
    $end_date_get = isset($_GET['end_date']) ? sanitize_text_field($_GET['end_date']) : null;
    $is_custom_range = !empty($start_date_get) && !empty($end_date_get);

    $filters_main = ['today' => 'Today', 'yesterday' => 'Yesterday', 'last_7_days' => '7D', 'last_30_days' => '30D'];
    $filters_dropdown = ['this_month' => 'This Month', 'this_year' => 'This Year', 'lifetime' => 'Lifetime', 'custom' => 'Custom Range'];
    ?>
    <div class="br-filters">
        <div class="br-date-filters">
            <?php
            foreach($filters_main as $key => $label) {
                $is_active = ($current_range_key === $key) && !$is_custom_range;
                echo sprintf('<a href="?page=br-expense&tab=%s&range=%s" class="button %s">%s</a>', esc_attr($current_tab), esc_attr($key), $is_active ? 'active' : '', esc_html($label));
            }
            ?>
            <div class="br-dropdown">
                <button class="button br-dropdown-toggle <?php echo ($is_custom_range || in_array($current_range_key, array_keys($filters_dropdown))) ? 'active' : ''; ?>">...</button>
                <div class="br-dropdown-menu">
                    <?php
                    foreach($filters_dropdown as $key => $label) {
                        if ($key === 'custom') {
                            echo sprintf('<a href="#" id="br-expense-custom-range-trigger">%s</a>', esc_html($label));
                        } else {
                            echo sprintf('<a href="?page=br-expense&tab=%s&range=%s">%s</a>', esc_attr($current_tab), esc_attr($key), esc_html($label));
                        }
                    }
                    ?>
                </div>
            </div>
        </div>
        <?php if ($current_tab === 'expense_list' && $list_table_object): ?>
        <div class="br-expense-actions">
            <?php $list_table_object->search_box(__('Search Expenses', 'business-report'), 'expense'); ?>
            <button id="br-add-category-btn" class="button"><?php _e('Add Category', 'business-report'); ?></button>
            <button id="br-add-expense-btn" class="button button-primary"><?php _e('Add Expense', 'business-report'); ?></button>
        </div>
        <?php endif; ?>
    </div>
    <?php
}


/**
 * =================================================================================
 * 3. MODAL HTML FUNCTIONS
 * =================================================================================
 */

function br_expense_add_expense_modal_html() {
    $categories = br_get_expense_categories();
    ?>
    <div id="br-add-expense-modal" class="br-modal" style="display: none;">
        <div class="br-modal-content">
            <button class="br-modal-close">&times;</button>
            <h3 id="br-expense-modal-title"><?php _e('Add New Expense', 'business-report'); ?></h3>
            <form id="br-add-expense-form">
                <input type="hidden" id="expense_id" name="expense_id" value="">
                
                <label for="expense_reason"><?php _e('Reason', 'business-report'); ?></label>
                <textarea id="expense_reason" name="expense_reason" rows="3"></textarea>
                
                <div class="form-row">
                    <div>
                        <label for="expense_date"><?php _e('Date', 'business-report'); ?></label>
                        <input type="text" id="expense_date" name="expense_date" class="br-datepicker" autocomplete="off" required>
                    </div>
                    <div>
                        <label for="expense_category_id"><?php _e('Category', 'business-report'); ?></label>
                        <select id="expense_category_id" name="expense_category_id" required>
                            <option value="">-- Select Category --</option>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?php echo esc_attr($category->id); ?>"><?php echo esc_html($category->name); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <label for="expense_amount"><?php _e('Amount', 'business-report'); ?></label>
                <input type="number" step="0.01" id="expense_amount" name="expense_amount" required>

                <div class="form-footer">
                    <div></div>
                    <div>
                        <button type="button" class="button br-modal-cancel"><?php _e('Cancel', 'business-report'); ?></button>
                        <button type="submit" class="button button-primary"><?php _e('Save Expense', 'business-report'); ?></button>
                    </div>
                </div>
            </form>
        </div>
    </div>
    <?php
}

function br_expense_add_category_modal_html() { ?>
    <div id="br-add-category-modal" class="br-modal" style="display: none;">
        <div class="br-modal-content">
            <button class="br-modal-close">&times;</button>
            <h3 id="br-category-modal-title"><?php _e('Add New Category', 'business-report'); ?></h3>
            <form id="br-add-category-form">
                <input type="hidden" id="category_id" name="category_id" value="">
                <label for="category_name"><?php _e('Category Name', 'business-report'); ?></label>
                <input type="text" id="category_name" name="category_name" required>
                <div class="form-footer">
                    <div></div>
                    <div>
                        <button type="button" class="button br-modal-cancel"><?php _e('Cancel', 'business-report'); ?></button>
                        <button type="submit" class="button button-primary"><?php _e('Save Category', 'business-report'); ?></button>
                    </div>
                </div>
            </form>
        </div>
    </div>
<?php }

function br_expense_add_monthly_expense_modal_html() {
    $categories = br_get_expense_categories();
    ?>
    <div id="br-add-monthly-expense-modal" class="br-modal" style="display: none;">
        <div class="br-modal-content">
            <button class="br-modal-close">&times;</button>
            <h3 id="br-monthly-expense-modal-title"><?php _e('Add Monthly Expense', 'business-report'); ?></h3>
            <form id="br-add-monthly-expense-form">
                <input type="hidden" id="monthly_expense_id" name="monthly_expense_id" value="">

                <label for="monthly_expense_reason"><?php _e('Reason', 'business-report'); ?></label>
                <textarea id="monthly_expense_reason" name="monthly_expense_reason" rows="3"></textarea>

                <label for="monthly_expense_category_id"><?php _e('Category', 'business-report'); ?></label>
                <select id="monthly_expense_category_id" name="monthly_expense_category_id" required>
                    <option value="">-- Select Category --</option>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?php echo esc_attr($category->id); ?>"><?php echo esc_html($category->name); ?></option>
                    <?php endforeach; ?>
                </select>

                <div class="form-row">
                    <div>
                        <label for="monthly_expense_amount"><?php _e('Amount', 'business-report'); ?></label>
                        <input type="number" step="0.01" id="monthly_expense_amount" name="monthly_expense_amount" required>
                    </div>
                    <div>
                        <label for="monthly_expense_listed_date"><?php _e('Day of Month to Add', 'business-report'); ?></label>
                        <input type="number" id="monthly_expense_listed_date" name="monthly_expense_listed_date" min="1" max="31" required>
                    </div>
                </div>
                
                <div class="form-footer">
                    <div></div>
                    <div>
                        <button type="button" class="button br-modal-cancel"><?php _e('Cancel', 'business-report'); ?></button>
                        <button type="submit" class="button button-primary"><?php _e('Save Monthly Expense', 'business-report'); ?></button>
                    </div>
                </div>
            </form>
        </div>
    </div>
<?php }

function br_expense_custom_range_filter_modal_html() { 
    $start_date = isset($_GET['start_date']) ? sanitize_text_field($_GET['start_date']) : ''; 
    $end_date = isset($_GET['end_date']) ? sanitize_text_field($_GET['end_date']) : ''; 
    ?>
    <div id="br-expense-custom-range-filter-modal" class="br-modal" style="display: none;">
        <div class="br-modal-content">
            <button class="br-modal-close">&times;</button>
            <h3><?php _e('Select Custom Date Range', 'business-report'); ?></h3>
            <p><?php _e('Filter the report by a specific date range.', 'business-report'); ?></p>
            <form id="br-expense-custom-range-filter-form" method="GET">
                <input type="hidden" name="page" value="br-expense">
                <input type="hidden" name="tab" value="">
                <div class="form-row">
                    <div>
                        <label for="br_expense_filter_start_date"><?php _e('Start Date', 'business-report'); ?></label>
                        <input type="text" id="br_expense_filter_start_date" name="start_date" class="br-datepicker" value="<?php echo esc_attr($start_date); ?>" autocomplete="off" required>
                    </div>
                    <div>
                        <label for="br_expense_filter_end_date"><?php _e('End Date', 'business-report'); ?></label>
                        <input type="text" id="br_expense_filter_end_date" name="end_date" class="br-datepicker" value="<?php echo esc_attr($end_date); ?>" autocomplete="off" required>
                    </div>
                </div>
                <div class="form-footer">
                    <div></div>
                    <div>
                        <button type="button" class="button br-modal-cancel"><?php _e('Cancel', 'business-report'); ?></button>
                        <button type="submit" class="button button-primary"><?php _e('Apply Filter', 'business-report'); ?></button>
                    </div>
                </div>
            </form>
        </div>
    </div>
<?php }


/**
 * =================================================================================
 * 4. HELPER & DATABASE FUNCTIONS
 * =================================================================================
 */

function br_get_expense_categories() {
    global $wpdb;
    return $wpdb->get_results("SELECT * FROM {$wpdb->prefix}br_expense_categories ORDER BY name ASC");
}


/**
 * =================================================================================
 * 5. AJAX HANDLERS
 * =================================================================================
 */

function br_ajax_save_expense_category() {
    check_ajax_referer('br_expense_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) { wp_send_json_error(['message' => 'Permission denied.']); }

    $name = isset($_POST['category_name']) ? sanitize_text_field(stripslashes($_POST['category_name'])) : '';
    if (empty($name)) { wp_send_json_error(['message' => 'Category name cannot be empty.']); }

    global $wpdb;
    $table = $wpdb->prefix . 'br_expense_categories';
    $id = isset($_POST['category_id']) ? intval($_POST['category_id']) : 0;

    if ($id > 0) {
        $wpdb->update($table, ['name' => $name], ['id' => $id]);
    } else {
        $wpdb->insert($table, ['name' => $name]);
    }
    wp_send_json_success();
}
add_action('wp_ajax_br_save_expense_category', 'br_ajax_save_expense_category');

function br_ajax_save_expense() {
    check_ajax_referer('br_expense_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) { wp_send_json_error(['message' => 'Permission denied.']); }
    
    $id = isset($_POST['expense_id']) ? intval($_POST['expense_id']) : 0;
    $data = [
        'reason'       => isset($_POST['expense_reason']) ? sanitize_textarea_field(stripslashes($_POST['expense_reason'])) : '',
        'category_id'  => isset($_POST['expense_category_id']) ? intval($_POST['expense_category_id']) : 0,
        'amount'       => isset($_POST['expense_amount']) ? floatval($_POST['expense_amount']) : 0,
        'expense_date' => isset($_POST['expense_date']) ? sanitize_text_field($_POST['expense_date']) : date('Y-m-d'),
    ];

    if (empty($data['category_id']) || empty($data['amount']) || empty($data['expense_date'])) {
        wp_send_json_error(['message' => 'Amount, Category, and Date are required.']);
    }

    global $wpdb;
    $table = $wpdb->prefix . 'br_expenses';
    if ($id > 0) {
        $wpdb->update($table, $data, ['id' => $id]);
    } else {
        $wpdb->insert($table, $data);
    }
    wp_send_json_success();
}
add_action('wp_ajax_br_save_expense', 'br_ajax_save_expense');

function br_ajax_get_expense_details() {
    check_ajax_referer('br_expense_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) { wp_send_json_error(['message' => 'Permission denied.']); }
    
    global $wpdb;
    $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
    $expense = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}br_expenses WHERE id = %d", $id));
    if ($expense) {
        wp_send_json_success($expense);
    } else {
        wp_send_json_error(['message' => 'Expense not found.']);
    }
}
add_action('wp_ajax_br_get_expense_details', 'br_ajax_get_expense_details');

function br_ajax_delete_expense() {
    check_ajax_referer('br_expense_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) { wp_send_json_error(['message' => 'Permission denied.']); }
    
    global $wpdb;
    $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
    $wpdb->delete($wpdb->prefix . 'br_expenses', ['id' => $id]);
    wp_send_json_success();
}
add_action('wp_ajax_br_delete_expense', 'br_ajax_delete_expense');

function br_ajax_save_monthly_expense() {
    check_ajax_referer('br_expense_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) { wp_send_json_error(['message' => 'Permission denied.']); }
    
    $id = isset($_POST['monthly_expense_id']) ? intval($_POST['monthly_expense_id']) : 0;
    $data = [
        'reason'       => isset($_POST['monthly_expense_reason']) ? sanitize_textarea_field(stripslashes($_POST['monthly_expense_reason'])) : '',
        'category_id'  => isset($_POST['monthly_expense_category_id']) ? intval($_POST['monthly_expense_category_id']) : 0,
        'amount'       => isset($_POST['monthly_expense_amount']) ? floatval($_POST['monthly_expense_amount']) : 0,
        'listed_date'  => isset($_POST['monthly_expense_listed_date']) ? intval($_POST['monthly_expense_listed_date']) : 1,
    ];

    if (empty($data['category_id']) || empty($data['amount']) || $data['listed_date'] < 1 || $data['listed_date'] > 31) {
        wp_send_json_error(['message' => 'Invalid data provided.']);
    }

    global $wpdb;
    $table = $wpdb->prefix . 'br_monthly_expenses';
    if ($id > 0) {
        $wpdb->update($table, $data, ['id' => $id]);
    } else {
        $wpdb->insert($table, $data);
    }
    wp_send_json_success();
}
add_action('wp_ajax_br_save_monthly_expense', 'br_ajax_save_monthly_expense');

function br_ajax_get_monthly_expense_details() {
    check_ajax_referer('br_expense_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) { wp_send_json_error(['message' => 'Permission denied.']); }
    
    global $wpdb;
    $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
    $expense = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}br_monthly_expenses WHERE id = %d", $id));
    if ($expense) {
        wp_send_json_success($expense);
    } else {
        wp_send_json_error(['message' => 'Monthly expense not found.']);
    }
}
add_action('wp_ajax_br_get_monthly_expense_details', 'br_ajax_get_monthly_expense_details');

function br_ajax_delete_monthly_expense() {
    check_ajax_referer('br_expense_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) { wp_send_json_error(['message' => 'Permission denied.']); }
    
    global $wpdb;
    $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
    $wpdb->delete($wpdb->prefix . 'br_monthly_expenses', ['id' => $id]);
    wp_send_json_success();
}
add_action('wp_ajax_br_delete_monthly_expense', 'br_ajax_delete_monthly_expense');

/**
 * =================================================================================
 * 6. CRON JOB FUNCTIONALITY
 * =================================================================================
 */
function br_add_monthly_expenses_to_list() {
    global $wpdb;
    $monthly_table = $wpdb->prefix . 'br_monthly_expenses';
    $expenses_table = $wpdb->prefix . 'br_expenses';

    $current_day = (int) date('j');
    $current_month = (int) date('n');
    $current_year = (int) date('Y');

    $due_expenses = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$monthly_table} WHERE listed_date = %d", $current_day
    ));

    foreach ($due_expenses as $expense) {
        if ($expense->last_added_month == $current_month && $expense->last_added_year == $current_year) {
            continue;
        }

        $wpdb->insert($expenses_table, [
            'reason'       => $expense->reason,
            'category_id'  => $expense->category_id,
            'amount'       => $expense->amount,
            'expense_date' => date('Y-m-d'),
        ]);

        $wpdb->update($monthly_table,
            ['last_added_month' => $current_month, 'last_added_year' => $current_year],
            ['id' => $expense->id]
        );
    }
}

