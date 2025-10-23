<?php
if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class BR_Monthly_Expense_List_Table extends WP_List_Table {

    public function __construct() {
        parent::__construct( [
            'singular' => 'Monthly Expense',
            'plural'   => 'Monthly Expenses',
            'ajax'     => false,
        ] );
    }

    public function get_columns() {
        return [
            'reason'      => __('Reason', 'business-report'),
            'category_name' => __('Category', 'business-report'),
            'amount'      => __('Amount', 'business-report'),
            'listed_date' => __('Listed Date', 'business-report'),
            'actions'     => __('Actions', 'business-report'),
        ];
    }

    public function prepare_items() {
        global $wpdb;
        $this->_column_headers = [$this->get_columns(), [], []];

        $monthly_table = $wpdb->prefix . 'br_monthly_expenses';
        $categories_table = $wpdb->prefix . 'br_expense_categories';
        
        $query = "SELECT m.*, c.name as category_name FROM {$monthly_table} m LEFT JOIN {$categories_table} c ON m.category_id = c.id ORDER BY m.listed_date ASC";

        $this->items = $wpdb->get_results($query, ARRAY_A);
    }
    
    public function column_default($item, $column_name) {
        switch ($column_name) {
            case 'reason':
                return !empty($item['reason']) ? esc_html($item['reason']) : '–';
            case 'category_name':
                return '<strong>' . esc_html($item['category_name']) . '</strong>';
            case 'amount':
                return wc_price($item['amount']);
            case 'listed_date':
                return 'Day ' . $item['listed_date'] . ' of every month';
            case 'actions':
                 return sprintf(
                    '<button class="button br-edit-monthly-expense-btn" data-id="%d">Edit</button> <button class="button-link-delete br-delete-monthly-expense-btn" data-id="%d"><span class="dashicons dashicons-trash"></span></button>',
                    $item['id'], $item['id']
                );
            default:
                return '–';
        }
    }
}
