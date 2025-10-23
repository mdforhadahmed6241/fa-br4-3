<?php
if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class BR_Meta_Campaign_List_Table extends WP_List_Table {

    public function __construct() {
		parent::__construct( [
			'singular' => 'Campaign',
			'plural'   => 'Campaigns',
			'ajax'     => false,
		] );
	}

    public function get_columns() {
        return [
			'campaign'    => 'Campaign',
			'report_date' => 'Date',
			'spend_usd'   => 'Spend (USD)',
			'purchases'   => 'Purch.',
			'purchase_value' => 'Value',
			'roas'        => 'ROAS',
            'adds_to_cart' => 'ATC',
            'initiate_checkouts' => 'IC',
			'impressions' => 'Impr.',
			'clicks'      => 'Clicks',
			'ctr'         => 'CTR',
			'cpc'         => 'CPC',
			'cpm'         => 'CPM',
        ];
    }

	public function get_sortable_columns() {
		return [
			'report_date' => ['report_date', false],
			'spend_usd'   => ['spend_usd', false],
			'purchases'   => ['purchases', false],
			'purchase_value' => ['purchase_value', false],
			'roas'        => ['roas', false],
			'impressions' => ['impressions', false],
			'clicks'      => ['clicks', false],
			'ctr'         => ['ctr', false],
			'cpc'         => ['cpc', false],
			'cpm'         => ['cpm', false],
		];
	}


    public function prepare_items() {
        global $wpdb;
        $this->_column_headers = [$this->get_columns(), [], $this->get_sortable_columns()];
        
		// Get date range from URL params, same as summary tab
		$current_range_key = isset($_GET['range']) ? sanitize_key($_GET['range']) : 'last_30_days';
		$start_date_get = isset($_GET['start_date']) ? sanitize_text_field($_GET['start_date']) : null;
		$end_date_get = isset($_GET['end_date']) ? sanitize_text_field($_GET['end_date']) : null;
		$is_custom_range = !empty($start_date_get) && !empty($end_date_get);
		$date_range = br_get_date_range($is_custom_range ? '' : $current_range_key, $start_date_get, $end_date_get);
		$start_date = $date_range['start'];
		$end_date = $date_range['end'];
		
        $table_name = $wpdb->prefix . 'br_meta_campaign_data';

		$query = $wpdb->prepare(
			"SELECT * FROM $table_name WHERE report_date BETWEEN %s AND %s ORDER BY report_date DESC, spend_usd DESC",
			$start_date,
			$end_date
		);

        $this->items = $wpdb->get_results($query, ARRAY_A);
    }
    
    public function column_default( $item, $column_name ) {
        switch ( $column_name ) {
            case 'campaign':
                return '<strong>' . esc_html($item['campaign_name']) . '</strong><br><small>ID: ' . esc_html($item['campaign_id']) . '</small>';
			case 'report_date':
				return (new DateTime($item['report_date']))->format('M j, Y');
            case 'spend_usd':
            case 'purchase_value':
            case 'cpc':
            case 'cpm':
                return wc_price($item[$column_name]);
            case 'purchases':
            case 'impressions':
            case 'clicks':
            case 'adds_to_cart':
            case 'initiate_checkouts':
                return number_format_i18n($item[$column_name]);
            case 'roas':
            case 'ctr':
				return number_format($item[$column_name], 2);
            default:
                return '–';
        }
    }
}

