<?php
/**
 * Order Report Functionality for Business Report Plugin
 *
 * @package BusinessReport
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

// Function to safely require dependencies only once
function br_require_dependency_once($file_path) {
    if (file_exists($file_path)) {
        require_once $file_path;
        return true;
    } else {
        error_log("Business Report Error: Required file {$file_path} not found.");
        return false;
    }
}


/**
 * =================================================================================
 * 1. ADMIN PAGE & ASSETS
 * =================================================================================
 */

 function br_order_report_page_html() {
    if ( ! current_user_can( 'manage_woocommerce' ) ) return;
    $active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'summary';
    ?>
    <div class="wrap br-wrap">
        <div class="br-header">
            <h1><?php _e( 'Order Report', 'business-report' ); ?></h1>
        </div>

        <h2 class="nav-tab-wrapper">
            <a href="?page=br-order-report&tab=summary" class="nav-tab <?php echo $active_tab == 'summary' ? 'nav-tab-active' : ''; ?>"><?php _e( 'Summary', 'business-report' ); ?></a>
            <!-- Add more tabs here in the future -->
        </h2>
        <div class="br-page-content">
        <?php
        switch ( $active_tab ) {
            case 'summary':
            default:
                br_order_summary_tab_html();
                break;
        }
        ?>
        </div>
        <?php
        // Add modal for custom date filtering
        br_order_custom_range_filter_modal_html();
        ?>
    </div>
    <?php
}

function br_order_report_admin_enqueue_scripts( $hook ) {
    // Enqueue script only on the order report page
	if ( 'business-report_page_br-order-report' !== $hook ) {
		return;
	}
    wp_enqueue_script( 'jquery-ui-datepicker' );
    wp_enqueue_style( 'wp-jquery-ui-dialog' ); // Styles needed for datepicker

	// Ensure the JS file exists before trying to enqueue
    $js_file_path = plugin_dir_path( __FILE__ ) . '../assets/js/admin-order-report.js';
    if ( file_exists( $js_file_path ) ) {
        $js_version = filemtime( $js_file_path );
        wp_enqueue_script(
            'br-order-report-admin-js',
            plugin_dir_url( __FILE__ ) . '../assets/js/admin-order-report.js',
            [ 'jquery', 'jquery-ui-datepicker' ],
            $js_version,
            true
        );
    } else {
        error_log("Business Report Error: admin-order-report.js not found at $js_file_path");
    }
}
add_action( 'admin_enqueue_scripts', 'br_order_report_admin_enqueue_scripts' );


/**
 * =================================================================================
 * 2. SUMMARY TAB & DATE FILTERS
 * =================================================================================
 */

function br_order_summary_tab_html() {
    global $wpdb;
    $orders_table = $wpdb->prefix . 'br_orders';

    if (!function_exists('br_get_date_range')) {
        if (!br_require_dependency_once(BR_PLUGIN_DIR . 'includes/meta-ads.php')) {
             echo '<div class="notice notice-error"><p>Error: The required Meta Ads module file is missing. Date filtering may not work.</p></div>';
            return;
        }
    }
    $current_range_key = isset($_GET['range']) ? sanitize_key($_GET['range']) : 'today';
    $start_date_get = isset($_GET['start_date']) ? sanitize_text_field($_GET['start_date']) : null;
    $end_date_get = isset($_GET['end_date']) ? sanitize_text_field($_GET['end_date']) : null;
    $is_custom_range = !empty($start_date_get) && !empty($end_date_get);

    $date_range = br_get_date_range($is_custom_range ? '' : $current_range_key, $start_date_get, $end_date_get);
    $start_date = $date_range['start'] . ' 00:00:00';
    $end_date = $date_range['end'] . ' 23:59:59';

    // Get stats for the selected period
    $current_stats_sql = $wpdb->prepare(
        "SELECT
            COUNT(id) AS total_orders,
            SUM(CASE WHEN is_converted = 1 THEN 1 ELSE 0 END) AS converted_orders,
            SUM(CASE WHEN is_converted = 1 THEN total_items ELSE 0 END) AS total_items,
            SUM(CASE WHEN is_converted = 1 THEN total_order_value ELSE 0 END) AS total_order_value,
            SUM(CASE WHEN is_converted = 1 THEN cogs_total ELSE 0 END) AS total_cogs
         FROM {$orders_table}
         WHERE order_date BETWEEN %s AND %s",
        $start_date, $end_date
    );
    $current_stats = $wpdb->get_row( $current_stats_sql );

    // Get stats for the previous period comparison
    $start_obj = new DateTime($date_range['start']);
    $end_obj = new DateTime($date_range['end']);
    $interval = $start_obj->diff($end_obj);
    $days = $interval->days + 1;

    $previous_end_obj = ( clone $start_obj )->modify( '-1 day' );
    $previous_start_obj = ( clone $previous_end_obj )->modify( '-' . ($days - 1) . ' days' );

    $previous_start = $previous_start_obj->format( 'Y-m-d 00:00:00' );
    $previous_end = $previous_end_obj->format( 'Y-m-d 23:59:59' );


     $previous_stats_sql = $wpdb->prepare(
        "SELECT
            COUNT(id) AS total_orders,
            SUM(CASE WHEN is_converted = 1 THEN 1 ELSE 0 END) AS converted_orders,
            SUM(CASE WHEN is_converted = 1 THEN total_items ELSE 0 END) AS total_items,
            SUM(CASE WHEN is_converted = 1 THEN total_order_value ELSE 0 END) AS total_order_value,
            SUM(CASE WHEN is_converted = 1 THEN cogs_total ELSE 0 END) AS total_cogs
         FROM {$orders_table}
         WHERE order_date BETWEEN %s AND %s",
        $previous_start, $previous_end
    );
    $previous_stats = $wpdb->get_row( $previous_stats_sql );

    br_order_render_date_filters_html('summary');
    ?>
    <div class="br-kpi-grid">
        <?php
        br_display_kpi_card( 'Total Orders', $current_stats->total_orders ?? 0, $previous_stats->total_orders ?? 0, 'number' );
        br_display_kpi_card( 'Converted Orders', $current_stats->converted_orders ?? 0, $previous_stats->converted_orders ?? 0, 'number' );
        br_display_kpi_card( 'Total Items Sold (Converted)', $current_stats->total_items ?? 0, $previous_stats->total_items ?? 0, 'number' );
        br_display_kpi_card( 'Total Selling Value (Converted)', $current_stats->total_order_value ?? 0, $previous_stats->total_order_value ?? 0, 'price' );
        br_display_kpi_card( 'Total Item Cost (Converted)', $current_stats->total_cogs ?? 0, $previous_stats->total_cogs ?? 0, 'price' );
        ?>
    </div>
    <?php
}

/**
 * Displays a single KPI card with comparison data.
 */
function br_display_kpi_card( $title, $current_value, $previous_value, $format = 'number' ) {
    $current_value = $current_value ?? 0;
    $previous_value = $previous_value ?? 0;

    if ( $format === 'price' ) {
        $display_value = function_exists('wc_price') ? wc_price( $current_value ) : number_format_i18n($current_value, 2);
    } else {
        $display_value = number_format_i18n( $current_value );
    }

    $percentage_change = 0;
    if ( $previous_value != 0 ) {
        $percentage_change = ( ( $current_value - $previous_value ) / $previous_value ) * 100;
    } elseif ( $current_value > 0 ) {
        $percentage_change = 100;
    } elseif ($current_value == 0 && $previous_value == 0) {
        $percentage_change = 0;
    }

    $comparison_class = $percentage_change > 0.01 ? 'increase' : ( $percentage_change < -0.01 ? 'decrease' : 'neutral' );
    $comparison_icon = $percentage_change > 0.01 ? 'dashicons-arrow-up-alt' : ( $percentage_change < -0.01 ? 'dashicons-arrow-down-alt' : '' );

    $current_range_key = isset($_GET['range']) ? sanitize_key($_GET['range']) : 'today';
    $comparison_period = 'vs. yesterday';
    if ($current_range_key !== 'today') {
        $comparison_period = 'vs. previous period';
    }

    ?>
    <div class="br-kpi-card">
        <h4><?php echo esc_html( $title ); ?></h4>
        <p><?php echo $display_value; ?></p>
        <div class="br-kpi-comparison <?php echo esc_attr( $comparison_class ); ?>">
            <?php if ( $comparison_icon ): ?>
                <span class="dashicons <?php echo esc_attr( $comparison_icon ); ?>"></span>
            <?php endif; ?>
            <strong><?php echo esc_html( number_format_i18n( $percentage_change, 2 ) ); ?>%</strong>
            <span><?php echo esc_html($comparison_period); ?></span>
        </div>
    </div>
    <?php
}

/**
 * Renders the date filter buttons and dropdown.
 */
function br_order_render_date_filters_html($current_tab = 'summary') {
    $current_range_key = isset($_GET['range']) ? sanitize_key($_GET['range']) : 'today';
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
                echo sprintf('<a href="?page=br-order-report&tab=%s&range=%s" class="button %s">%s</a>', esc_attr($current_tab), esc_attr($key), $is_active ? 'active' : '', esc_html($label));
            }
            ?>
            <div class="br-dropdown">
                <button class="button br-dropdown-toggle <?php echo ($is_custom_range || in_array($current_range_key, array_keys($filters_dropdown))) ? 'active' : ''; ?>">...</button>
                <div class="br-dropdown-menu">
                    <?php
                    foreach($filters_dropdown as $key => $label) {
                        if ($key === 'custom') {
                            echo sprintf('<a href="#" id="br-order-custom-range-trigger">%s</a>', esc_html($label));
                        } else {
                            echo sprintf('<a href="?page=br-order-report&tab=%s&range=%s">%s</a>', esc_attr($current_tab), esc_attr($key), esc_html($label));
                        }
                    }
                    ?>
                </div>
            </div>
        </div>
    </div>
    <?php
}

/**
 * Renders the HTML for the custom date range modal.
 */
function br_order_custom_range_filter_modal_html() {
    $start_date = isset($_GET['start_date']) ? sanitize_text_field($_GET['start_date']) : '';
    $end_date = isset($_GET['end_date']) ? sanitize_text_field($_GET['end_date']) : '';
    ?>
    <div id="br-order-custom-range-filter-modal" class="br-modal" style="display: none;">
        <div class="br-modal-content">
            <button class="br-modal-close">&times;</button>
            <h3><?php _e('Select Custom Date Range', 'business-report'); ?></h3>
            <p><?php _e('Filter the report by a specific date range.', 'business-report'); ?></p>
            <form id="br-order-custom-range-filter-form" method="GET">
                <input type="hidden" name="page" value="br-order-report">
                <input type="hidden" name="tab" value="summary">
                <div class="form-row">
                    <div>
                        <label for="br_order_filter_start_date"><?php _e('Start Date', 'business-report'); ?></label>
                        <input type="text" id="br_order_filter_start_date" name="start_date" class="br-datepicker" value="<?php echo esc_attr($start_date); ?>" autocomplete="off" required>
                    </div>
                    <div>
                        <label for="br_order_filter_end_date"><?php _e('End Date', 'business-report'); ?></label>
                        <input type="text" id="br_order_filter_end_date" name="end_date" class="br-datepicker" value="<?php echo esc_attr($end_date); ?>" autocomplete="off" required>
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
 * 3. WOOCOMMERCE ORDER HOOKS & DATABASE LOGIC
 * =================================================================================
 */

/**
 * Checks if an order status is considered "converted" based on settings.
 */
function br_check_is_converted( $order_status ) {
    $converted_statuses = get_option( 'br_converted_order_statuses', ['completed'] );
    if ( ! is_array( $converted_statuses ) ) {
        $converted_statuses = ['completed'];
    }
    $status_without_prefix = str_replace( 'wc-', '', $order_status );
    return in_array( $status_without_prefix, $converted_statuses ) ? 1 : 0;
}

/**
 * Calculates the total cost of goods for a given order.
 */
function br_get_order_cogs_total( $order ) {
    $total_cogs = 0;
    if ( ! function_exists( 'br_get_product_cost' ) ) {
        if (!br_require_dependency_once(BR_PLUGIN_DIR . 'includes/cogs-management.php')) {
            return 0;
        }
         if ( ! function_exists( 'br_get_product_cost' ) ) {
            error_log('Business Report Error: Function br_get_product_cost still not found after requiring file in br_get_order_cogs_total.');
            return 0;
        }
    }
    if (!is_a($order, 'WC_Order')) {
         error_log('Business Report Error: Invalid order object passed to br_get_order_cogs_total.');
        return 0;
    }
    foreach ( $order->get_items() as $item_key => $item ) {
        if (!is_a($item, 'WC_Order_Item_Product')) continue;
        $product_id = $item->get_product_id();
        $variation_id = $item->get_variation_id();
        $post_id_to_check = $variation_id > 0 ? $variation_id : $product_id;
        $cost = br_get_product_cost( $post_id_to_check );
        if ( is_numeric( $cost ) ) {
            $total_cogs += floatval($cost) * $item->get_quantity();
        }
    }
    return $total_cogs;
}

/**
 * Wrapper function for the save_post_shop_order hook.
 */
function br_save_or_update_order_report_data_on_save( $post_id, $post, $update ) {
    // Check if it's an autosave
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
        error_log("BR Log (save_post): Skipping autosave for Post ID {$post_id}");
        return;
    }

    // Check post type
    if ( !isset($post->post_type) || $post->post_type !== 'shop_order' ) {
         error_log("BR Log (save_post): Skipping non-shop_order Post ID {$post_id}, Type: " . ($post->post_type ?? 'N/A'));
        return;
    }

    // Check user permissions or if it's a cron job
    if ( ! current_user_can( 'edit_post', $post_id ) && ! wp_doing_cron() ) {
        error_log("BR Log (save_post): Skipping due to permissions for Post ID {$post_id}");
        return;
    }


     // Avoid infinite loops
    remove_action( 'save_post_shop_order', 'br_save_or_update_order_report_data_on_save', 40 );

    error_log("BR Log (save_post): Hook triggered for Order ID {$post_id}. Update flag: " . ($update ? 'Yes' : 'No'));

    // Call the main function
    br_save_or_update_order_report_data( $post_id );

    // Re-hook after processing
    add_action( 'save_post_shop_order', 'br_save_or_update_order_report_data_on_save', 40, 3 );
    error_log("BR Log (save_post): Re-hooked save_post_shop_order for Order ID {$post_id}");

}


/**
 * Central function to save or update order data in the custom table.
 */
function br_save_or_update_order_report_data( $order_id ) {

    // --- Start Logging ---
    $current_hook = current_action();
    error_log("BR Log: br_save_or_update_order_report_data called for Order ID: {$order_id}. Triggered by hook: {$current_hook}");

    // --- Loop Prevention Check ---
    static $processing = [];
    if (isset($processing[$order_id])) {
        error_log("BR Log: LOOP PREVENTION - Skipping Order ID {$order_id} triggered by {$current_hook}, already processing.");
        return;
    }
    $processing[$order_id] = true;
    error_log("BR Log: LOOP PREVENTION - Entering processing lock for Order ID {$order_id} triggered by {$current_hook}.");
    // --- End Loop Prevention Check ---


    $order = wc_get_order( $order_id );
    if ( ! $order ) {
        error_log("BR Log Error: Could not get WC_Order object for ID {$order_id}.");
        unset($processing[$order_id]); // Release lock
        return;
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'br_orders';
    $now = current_time( 'mysql' );

    // --- Detailed Data Logging ---
    $order_status = $order->get_status();
    $customer_id = $order->get_customer_id();
    $phone = $order->get_billing_phone();
    $email = $order->get_billing_email();
    $total_items_calc = $order->get_item_count();
    $cogs_total_calc = br_get_order_cogs_total( $order );
    $total_value_calc = $order->get_total(); // Grand total
    $total_order_value_calc = $order->get_subtotal(); // Subtotal (products only)
    $shipping_cost_calc = $order->get_shipping_total();
    $discount_calc = $order->get_discount_total();
    $payment_method_calc = $order->get_payment_method_title();
    $created_via_calc = $order->get_created_via();
    $customer_note_calc = $order->get_customer_note();
    $date_created_obj = $order->get_date_created();
    // Use current time if date_created is null (can happen with programmatically created orders before save)
    $order_date_str_calc = $date_created_obj ? $date_created_obj->date( 'Y-m-d H:i:s' ) : $now;


    error_log("BR Log Data Check (Order ID: {$order_id}): Status={$order_status}, CustID={$customer_id}, Phone={$phone}, Email={$email}, Items={$total_items_calc}, COGS={$cogs_total_calc}, Subtotal={$total_order_value_calc}, Total={$total_value_calc}, Shipping={$shipping_cost_calc}, Discount={$discount_calc}, Payment={$payment_method_calc}, Via={$created_via_calc}, Date={$order_date_str_calc}");
    // --- End Detailed Data Logging ---

    // Collect item data (IDs, categories)
    $total_items = 0; // Recalculate precisely
    $product_ids = [];
    $variation_ids = [];
    $category_ids = [];

    foreach ( $order->get_items() as $item_key => $item ) {
        if (!is_a($item, 'WC_Order_Item_Product')) continue;
        $quantity = $item->get_quantity();
        $total_items += $quantity;
        $product_id = $item->get_product_id();
        $product_ids[] = $product_id;
        if ( $item->get_variation_id() > 0 ) {
            $variation_ids[] = $item->get_variation_id();
        }
        $term_ids = wp_get_post_terms( $product_id, 'product_cat', ['fields' => 'ids'] );
        if ( ! is_wp_error( $term_ids ) && !empty($term_ids) ) {
            $category_ids = array_merge( $category_ids, $term_ids );
        }
    }
    if ($total_items !== $total_items_calc) {
        error_log("BR Log Data Check (Order ID: {$order_id}): Item count discrepancy. get_item_count()={$total_items_calc}, manual sum={$total_items}");
    }

    $cogs_total = $cogs_total_calc;
    $total_order_value = $total_order_value_calc; // Subtotal
    $total_value = $total_value_calc; // Grand Total
    $discount = $discount_calc;
    $shipping_cost = $shipping_cost_calc;

    $gross_profit = $total_order_value - $cogs_total;
    $net_profit = $gross_profit - $discount;
    // Prevent division by zero
    $profit_margin = ( $total_order_value != 0 ) ? round( ( $net_profit / $total_order_value ) * 100, 2) : 0;

    $order_date_string = $order_date_str_calc;

    $data = [
        'order_id'          => $order->get_id(),
        'order_date'        => $order_date_string,
        'customer_id'       => $customer_id ?: null, // Use null if 0
        'customer_name'     => trim($order->get_formatted_billing_full_name()) ?: null,
        'customer_phone'    => $phone ?: null,
        'customer_email'    => $email ?: null,
        'total_items'       => $total_items, // Use manually summed items
        'product_ids'       => !empty($product_ids) ? implode( ',', array_unique( $product_ids ) ) : null,
        'variation_ids'     => !empty($variation_ids) ? implode( ',', array_unique( $variation_ids ) ) : null,
        'category_ids'      => !empty($category_ids) ? implode( ',', array_unique( $category_ids ) ) : null,
        'total_order_value' => wc_format_decimal($total_order_value, 2),
        'total_value'       => wc_format_decimal($total_value, 2),
        'cogs_total'        => wc_format_decimal($cogs_total, 2),
        'discount'          => wc_format_decimal($discount, 2),
        'shipping_cost'     => wc_format_decimal($shipping_cost, 2),
        'payment_method'    => $payment_method_calc ?: null,
        'order_status'      => $order_status ?: null,
        'is_converted'      => br_check_is_converted( $order_status ),
        'source'            => $created_via_calc ?: null,
        'gross_profit'      => wc_format_decimal($gross_profit, 2),
        'net_profit'        => wc_format_decimal($net_profit, 2),
        'profit_margin'     => $profit_margin,
        'notes'             => $customer_note_calc ?: null,
        // updated_at handled by MySQL
    ];
     // Define formats matching the $data array keys for INSERT/UPDATE
     $formats = [ // 23 columns corresponding to $data keys above
        '%d', // order_id
        '%s', // order_date
        '%d', // customer_id
        '%s', // customer_name
        '%s', // customer_phone
        '%s', // customer_email
        '%d', // total_items
        '%s', // product_ids
        '%s', // variation_ids
        '%s', // category_ids
        '%f', // total_order_value
        '%f', // total_value
        '%f', // cogs_total
        '%f', // discount
        '%f', // shipping_cost
        '%s', // payment_method
        '%s', // order_status
        '%d', // is_converted
        '%s', // source
        '%f', // gross_profit
        '%f', // net_profit
        '%f', // profit_margin
        '%s', // notes
    ];


    $existing_id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table_name WHERE order_id = %d", $order_id ) );

    $db_result = false;
    try {
        if ( $existing_id ) {
            error_log("BR Log: Updating existing record ID {$existing_id} for Order ID {$order_id} in {$table_name}.");
            // Use same $data (associative array) and $formats for update
            $db_result = $wpdb->update( $table_name, $data, [ 'id' => $existing_id ], $formats, ['%d'] );
        } else {
            error_log("BR Log: Inserting new record for Order ID {$order_id} into {$table_name}.");
            // Add created_at specifically for insert
            $insert_data = $data;
            $insert_data['created_at'] = $order_date_string; // Add created_at for insert
            $insert_formats = $formats;
            $insert_formats[] = '%s'; // Format for created_at

            $db_result = $wpdb->insert( $table_name, $insert_data, $insert_formats );
        }
    } catch (Exception $e) {
         error_log("BR Log DB Exception (Order ID {$order_id}): " . $e->getMessage());
         $db_result = false;
    }

    // Check $wpdb->last_error ONLY if $db_result is explicitly false
    if ($db_result === false) {
         error_log("BR Log DB Error (Order ID {$order_id}): Failed. DB Error: " . $wpdb->last_error);
    } else {
         // $db_result is 1 for successful insert, or number of rows affected for update (can be 0 if data didn't change)
         error_log("BR Log DB Success (Order ID {$order_id}): Saved/Updated. Result (rows affected/inserted): " . $db_result);
    }

    // --- Release Lock ---
    unset($processing[$order_id]);
    error_log("BR Log: LOOP PREVENTION - Releasing lock for Order ID {$order_id} triggered by {$current_hook}.");
    // --- End Release Lock ---
}


/**
 * Update report when order status changes (minimal update + trigger full update).
 */
function br_order_status_changed_update_report( $order_id, $old_status, $new_status, $order ) {

    error_log("BR Log Status Change: Triggered for Order ID {$order_id} from {$old_status} to {$new_status}.");

    // --- Loop Prevention Check ---
    static $processing_status_change = [];
    if (isset($processing_status_change[$order_id])) {
        error_log("BR Log Status Change: LOOP PREVENTION - Skipping Order ID {$order_id}, already processing status change.");
        return;
    }
    $processing_status_change[$order_id] = true;
    error_log("BR Log Status Change: LOOP PREVENTION - Entering lock for Order ID {$order_id}.");
    // --- End Loop Prevention Check ---


    if (!is_a($order, 'WC_Order')) {
         $order = wc_get_order($order_id);
         if (!is_a($order, 'WC_Order')) {
             error_log("BR Log Status Change Error: Invalid order object for ID $order_id.");
             unset($processing_status_change[$order_id]); // Release lock
            return;
         }
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'br_orders';

    $is_converted = br_check_is_converted( $new_status );
    $now_time = current_time( 'mysql' );

    error_log("BR Log Status Change: Performing minimal update for Order ID {$order_id}. New Status: {$new_status}, Is Converted: {$is_converted}.");

    // Temporarily remove save_post hook to prevent loops during minimal update
    remove_action( 'save_post_shop_order', 'br_save_or_update_order_report_data_on_save', 40 );

    $update_result = $wpdb->update(
        $table_name,
        [
            'order_status' => $new_status,
            'is_converted' => $is_converted,
            // updated_at handled by MySQL
        ],
        [ 'order_id' => $order_id ], // Where condition
        [ '%s', '%d' ], // Format for data
        [ '%d' ]        // Format for where
    );

    // Re-add save_post hook
    add_action( 'save_post_shop_order', 'br_save_or_update_order_report_data_on_save', 40, 3 );

    if ($update_result === false) {
         error_log("BR Log Status Change DB Error: Failed minimal update for Order ID $order_id. DB Error: " . $wpdb->last_error);
    } else {
         error_log("BR Log Status Change DB Success: Minimal update successful for Order ID {$order_id}. Rows affected: " . $update_result);
    }


    error_log("BR Log Status Change: Triggering full update via br_save_or_update_order_report_data for Order ID {$order_id}.");
    // Trigger a full update to ensure all data is consistent after status change
    br_save_or_update_order_report_data($order_id); // Loop prevention in main function should handle this

     // --- Release Lock ---
    unset($processing_status_change[$order_id]);
    error_log("BR Log Status Change: Finished processing Order ID {$order_id}. Lock released.");
    // --- End Release Lock ---
}

