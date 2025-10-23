<?php
/**
 * Creates the WP_List_Table for displaying expenses.
 */

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class BR_Expense_List_Table extends WP_List_Table {

	public function __construct() {
		parent::__construct( [
			'singular' => 'Expense',
			'plural'   => 'Expenses',
			'ajax'     => false,
		] );
	}

	public function get_columns() {
		return [
			'reason'    => __( 'Reason', 'business-report' ),
			'category'  => __( 'Category', 'business-report' ),
			'amount'    => __( 'Amount', 'business-report' ),
			'date'      => __( 'Date', 'business-report' ),
			'actions'   => __( 'Actions', 'business-report' ),
		];
	}

	public function prepare_items() {
		global $wpdb;
		$this->_column_headers = [ $this->get_columns(), [], [] ];
        
        $current_range_key = isset($_GET['range']) ? sanitize_key($_GET['range']) : 'this_month';
        $start_date_get = isset($_GET['start_date']) ? sanitize_text_field($_GET['start_date']) : null;
		$end_date_get = isset($_GET['end_date']) ? sanitize_text_field($_GET['end_date']) : null;
		$is_custom_range = !empty($start_date_get) && !empty($end_date_get);
		$date_range = br_get_date_range($is_custom_range ? '' : $current_range_key, $start_date_get, $end_date_get);
        
        $expenses_table = $wpdb->prefix . 'br_expenses';
        $categories_table = $wpdb->prefix . 'br_expense_categories';

		$sql = "SELECT e.id, e.reason, e.amount, e.expense_date, c.name AS category_name
                FROM {$expenses_table} e
                LEFT JOIN {$categories_table} c ON e.category_id = c.id";

        $where = [];
        $where[] = $wpdb->prepare("e.expense_date BETWEEN %s AND %s", $date_range['start'], $date_range['end']);

        $search_term = isset($_REQUEST['s']) ? sanitize_text_field(wp_unslash($_REQUEST['s'])) : '';
        if (!empty($search_term)) {
            $where[] = $wpdb->prepare("(e.reason LIKE %s OR c.name LIKE %s)", '%' . $wpdb->esc_like($search_term) . '%', '%' . $wpdb->esc_like($search_term) . '%');
        }

        if (!empty($where)) {
            $sql .= " WHERE " . implode(' AND ', $where);
        }

        $sql .= " ORDER BY e.expense_date DESC";

		$this->items = $wpdb->get_results($sql, ARRAY_A);
	}

	public function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'reason':
				return !empty($item['reason']) ? esc_html($item['reason']) : '–';
			case 'category':
				return esc_html( $item['category_name'] );
			case 'amount':
				return wc_price( $item['amount'] );
			case 'date':
				return ( new DateTime( $item['expense_date'] ) )->format( 'M j, Y' );
			case 'actions':
                // FIX: Wrap buttons in a div for styling
                return sprintf(
                    '<div class="br-action-buttons"><button class="button br-edit-expense-btn" data-id="%1$d">Edit</button><button class="button-link-delete br-delete-expense-btn" data-id="%1$d"><span class="dashicons dashicons-trash"></span></button></div>',
                    $item['id']
                );
			default:
				return '';
		}
	}

    public function no_items() {
        _e('No expenses found for this period.', 'business-report');
    }
}

