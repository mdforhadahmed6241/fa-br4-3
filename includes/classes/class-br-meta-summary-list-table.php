<?php
/**
 * Creates the WP_List_Table for displaying Meta Ads summary data.
 */

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class BR_Meta_Summary_List_Table extends WP_List_Table {

    /**
     * Holds the calculated totals for the entire dataset.
     * @var array
     */
    private $totals;

	public function __construct() {
		parent::__construct( [ 'singular' => 'Ad Spend Entry', 'plural' => 'Ad Spend Entries', 'ajax' => false ] );
	}

	public function get_columns() {
        return [
            'report_date'      => 'Date',
            'account_name'     => 'Account',
            'spend_usd'        => 'Spend (USD)',
            'spend_bdt'        => 'Spend (BDT)',
            'purchases'        => 'Purchases',
            'adds_to_cart'     => 'ATC',
            'initiate_checkouts' => 'IC',
            'impressions'      => 'Impr.',
            'clicks'           => 'Clicks',
            'actions'          => 'Actions',
        ];
    }

    /**
     * Calculate and store totals from the full dataset.
     * @param array $items The full dataset.
     */
    private function calculate_totals($items) {
        $this->totals = [
            'spend_usd'        => 0,
            'spend_bdt'        => 0,
            'purchases'        => 0,
            'adds_to_cart'     => 0,
            'initiate_checkouts' => 0,
            'impressions'      => 0,
            'clicks'           => 0,
        ];

        foreach ($items as $item) {
            foreach ($this->totals as $key => $value) {
                if (isset($item[$key])) {
                    $this->totals[$key] += (float) $item[$key];
                }
            }
        }
    }

    public function prepare_items() {
        global $wpdb;
        $this->_column_headers = [$this->get_columns(), [], []];
        $current_range_key = isset($_GET['range']) ? sanitize_key($_GET['range']) : 'last_30_days';
        $start_date_get = isset($_GET['start_date']) ? sanitize_text_field($_GET['start_date']) : null;
		$end_date_get = isset($_GET['end_date']) ? sanitize_text_field($_GET['end_date']) : null;
		$is_custom_range = !empty($start_date_get) && !empty($end_date_get);
		$date_range = br_get_date_range($is_custom_range ? '' : $current_range_key, $start_date_get, $end_date_get);

        $query = $wpdb->prepare(
            "SELECT s.*, a.account_name, (s.spend_usd * a.usd_to_bdt_rate) AS spend_bdt
             FROM {$wpdb->prefix}br_meta_ad_summary s
             JOIN {$wpdb->prefix}br_meta_ad_accounts a ON s.account_fk_id = a.id
             WHERE s.report_date BETWEEN %s AND %s
             ORDER BY s.report_date DESC",
             $date_range['start'], $date_range['end']
        );
        $all_items = $wpdb->get_results($query, ARRAY_A);

        // Calculate totals from the full result set
        $this->calculate_totals($all_items);

        // Set pagination arguments
        $per_page = 20;
        $current_page = $this->get_pagenum();
        $total_items = count($all_items);

        $this->set_pagination_args([
            'total_items' => $total_items,
            'per_page'    => $per_page,
        ]);

        // Slice the data for the current page
        $this->items = array_slice($all_items, (($current_page - 1) * $per_page), $per_page);
    }

    /**
     * Renders the table footer with a total row.
     */
    protected function tfoot() {
        // Render the default footer (column headers)
        parent::tfoot();
        ?>
        <tr class="br-total-row" style="font-weight: bold;">
            <?php // The first cell spans 2 columns ('Date' and 'Account') ?>
            <th scope="row" colspan="2" style="text-align: right; padding-right: 10px;"><?php _e('Total', 'business-report'); ?></th>
            <td><?php echo '$' . number_format($this->totals['spend_usd'], 2); ?></td>
            <td><?php echo '৳' . number_format($this->totals['spend_bdt'], 2); ?></td>
            <td><?php echo number_format_i18n($this->totals['purchases']); ?></td>
            <td><?php echo number_format_i18n($this->totals['adds_to_cart']); ?></td>
            <td><?php echo number_format_i18n($this->totals['initiate_checkouts']); ?></td>
            <td><?php echo number_format_i18n($this->totals['impressions']); ?></td>
            <td><?php echo number_format_i18n($this->totals['clicks']); ?></td>
            <td></td> <?php // Empty cell for the 'Actions' column ?>
        </tr>
        <?php
    }

    function column_default($item, $column_name) {
        switch ($column_name) {
            case 'report_date': return (new DateTime($item['report_date']))->format('M j, Y');
            case 'account_name': return esc_html($item['account_name']);
            case 'spend_usd': return '$' . number_format(floatval($item['spend_usd']), 2);
            case 'spend_bdt': return '৳' . number_format(floatval($item['spend_bdt']), 2);
            case 'purchases': case 'impressions': case 'clicks': case 'adds_to_cart': case 'initiate_checkouts': return number_format_i18n($item[$column_name]);
            case 'actions': return sprintf( '<button class="button-link-delete br-delete-summary-btn" data-id="%d"><span class="dashicons dashicons-trash"></span></button>', $item['id'] );
            default: return '';
        }
    }
}

