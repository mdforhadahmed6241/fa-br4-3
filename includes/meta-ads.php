<?php
/**
 * Meta Ads Reporting Functionality for Business Report Plugin
 *
 * @package BusinessReport
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

// Include the List Table classes.
require_once plugin_dir_path( __FILE__ ) . 'classes/class-br-meta-summary-list-table.php';
require_once plugin_dir_path( __FILE__ ) . 'classes/class-br-meta-campaign-list-table.php';


/**
 * =================================================================================
 * 1. ADMIN SUBMENU PAGE & ASSETS
 * =================================================================================
 */

function br_meta_ads_admin_submenu() { add_submenu_page( 'business-report', __( 'Meta Ads', 'business-report' ), __( 'Meta Ads', 'business-report' ), 'manage_woocommerce', 'br-meta-ads', 'br_meta_ads_page_html' ); }
add_action( 'admin_menu', 'br_meta_ads_admin_submenu' );

function br_meta_ads_admin_enqueue_scripts( $hook ) {
    if ( 'business-report_page_br-meta-ads' !== $hook ) { return; }
    
    // Enqueue the core jQuery UI datepicker script and standard WP stylesheet for it.
    wp_enqueue_script('jquery-ui-datepicker');
    wp_enqueue_style( 'wp-jquery-ui-dialog' );

    $js_version = filemtime( plugin_dir_path( __FILE__ ) . '../assets/js/admin-meta-ads.js' );
    wp_enqueue_script( 'br-meta-ads-admin-js', plugin_dir_url( __FILE__ ) . '../assets/js/admin-meta-ads.js', [ 'jquery', 'jquery-ui-datepicker' ], $js_version, true );
    
    wp_localize_script( 'br-meta-ads-admin-js', 'br_meta_ads_ajax', [ 'ajax_url' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( 'br_meta_ads_nonce' ) ] );
}
add_action( 'admin_enqueue_scripts', 'br_meta_ads_admin_enqueue_scripts' );


/**
 * =================================================================================
 * 2. DATABASE & HELPER FUNCTIONS
 * =================================================================================
 */

function br_get_meta_accounts() { global $wpdb; return $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}br_meta_ad_accounts ORDER BY id DESC" ); }
function br_get_meta_account( $id ) { global $wpdb; return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}br_meta_ad_accounts WHERE id = %d", $id ) ); }
function br_get_date_range( $range = 'last_30_days', $start = null, $end = null ) { $tz = new DateTimeZone( 'Asia/Dhaka' ); if ($start && $end) { return [ 'start' => $start, 'end' => $end ]; } $end_date = new DateTime( 'now', $tz ); $start_date = new DateTime( 'now', $tz ); switch ($range) { case 'today': break; case 'yesterday': $start_date->modify('-1 day'); $end_date->modify('-1 day'); break; case 'this_month': $start_date->modify('first day of this month'); break; case 'this_year': $start_date->modify('first day of january this year'); break; case 'last_year': $start_date->modify('first day of january last year'); $end_date->modify('last day of december last year'); break; case 'lifetime': return [ 'start' => '2020-01-01', 'end' => $end_date->format('Y-m-d') ]; case 'last_7_days': $start_date->modify('-6 days'); break; case 'last_30_days': default: $start_date->modify('-29 days'); break; } return [ 'start' => $start_date->format('Y-m-d'), 'end'   => $end_date->format('Y-m-d') ]; }


/**
 * =================================================================================
 * 3. CORE API & DATA PROCESSING LOGIC
 * =================================================================================
 */
function br_fetch_and_process_meta_data($account, $start_date, $end_date) {
    global $wpdb;
    $api_url = sprintf( 'https://graph.facebook.com/v20.0/act_%s/insights?fields=date_start,campaign_id,campaign_name,objective,spend,impressions,reach,frequency,clicks,ctr,cpc,cpm,website_purchase_roas,actions,action_values&time_increment=1&level=campaign&time_range={\'since\':\'%s\',\'until\':\'%s\'}&access_token=%s&limit=500', $account->ad_account_id, $start_date, $end_date, $account->access_token );
    $response = wp_remote_get($api_url, ['timeout' => 120]);

    if (is_wp_error($response)) { return ['success' => false, 'message' => 'WP Error: ' . $response->get_error_message()]; }
    $body = wp_remote_retrieve_body($response); $data = json_decode($body, true);
    if (isset($data['error'])) { return ['success' => false, 'message' => 'API Error: ' . $data['error']['message']]; }
    if (empty($data['data'])) { return ['success' => true, 'message' => 'No campaign data returned for this period.']; }

    $campaign_table = $wpdb->prefix . 'br_meta_campaign_data';
    foreach ($data['data'] as $row) {
        $campaign_data = [
            'campaign_id'   => $row['campaign_id'], 'campaign_name' => $row['campaign_name'], 'account_fk_id' => $account->id, 'report_date' => $row['date_start'], 'objective' => $row['objective'] ?? '', 'spend_usd' => $row['spend'] ?? 0.00, 'impressions' => $row['impressions'] ?? 0, 'reach' => $row['reach'] ?? 0, 'frequency' => $row['frequency'] ?? 0, 'clicks' => $row['clicks'] ?? 0, 'ctr' => $row['ctr'] ?? 0, 'cpc' => $row['cpc'] ?? 0, 'cpm' => $row['cpm'] ?? 0, 'roas' => $row['website_purchase_roas'][0]['value'] ?? 0,
            'purchases' => 0, 'adds_to_cart' => 0, 'initiate_checkouts' => 0, 'purchase_value' => 0.00,
        ];

        if (!empty($row['actions'])) { foreach ($row['actions'] as $action) {
            if ($action['action_type'] === 'offsite_conversion.fb_pixel_purchase') { $campaign_data['purchases'] = $action['value']; }
            if ($action['action_type'] === 'offsite_conversion.fb_pixel_add_to_cart') { $campaign_data['adds_to_cart'] = $action['value']; }
            if ($action['action_type'] === 'offsite_conversion.fb_pixel_initiate_checkout') { $campaign_data['initiate_checkouts'] = $action['value']; }
        }}
        if (!empty($row['action_values'])) { foreach ($row['action_values'] as $action) {
            if ($action['action_type'] === 'offsite_conversion.fb_pixel_purchase') { $campaign_data['purchase_value'] = $action['value']; }
        }}
        
        $wpdb->replace($campaign_table, $campaign_data);
    }
    br_aggregate_campaign_data_to_summary($account->id, $start_date, $end_date);
    return ['success' => true, 'message' => count($data['data']) . ' records processed.'];
}

function br_aggregate_campaign_data_to_summary($account_id, $start_date, $end_date) {
    global $wpdb;
    $summary_table = $wpdb->prefix . 'br_meta_ad_summary'; $campaign_table = $wpdb->prefix . 'br_meta_campaign_data';
    $query = $wpdb->prepare( "INSERT INTO {$summary_table} (account_fk_id, report_date, spend_usd, purchases, purchase_value, adds_to_cart, initiate_checkouts, impressions, clicks) SELECT account_fk_id, report_date, SUM(spend_usd), SUM(purchases), SUM(purchase_value), SUM(adds_to_cart), SUM(initiate_checkouts), SUM(impressions), SUM(clicks) FROM {$campaign_table} WHERE account_fk_id = %d AND report_date BETWEEN %s AND %s GROUP BY account_fk_id, report_date ON DUPLICATE KEY UPDATE spend_usd = VALUES(spend_usd), purchases = VALUES(purchases), purchase_value = VALUES(purchase_value), adds_to_cart = VALUES(adds_to_cart), initiate_checkouts = VALUES(initiate_checkouts), impressions = VALUES(impressions), clicks = VALUES(clicks)", $account_id, $start_date, $end_date );
    $wpdb->query($query);
}

/**
 * =================================================================================
 * 4. AJAX HANDLERS
 * =================================================================================
 */
function br_ajax_save_meta_account() {
    check_ajax_referer('br_meta_ads_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) { wp_send_json_error(['message' => 'Permission denied.']); }
    
    global $wpdb;
    $table_name = $wpdb->prefix . 'br_meta_ad_accounts';
    
    // Sanitize and prepare data
    $data = [
        'account_name'    => sanitize_text_field($_POST['account_name']),
        'access_token'    => wp_unslash($_POST['access_token']),
        'ad_account_id'   => sanitize_text_field($_POST['ad_account_id']),
        'usd_to_bdt_rate' => floatval($_POST['usd_to_bdt_rate']),
        'is_active'       => isset($_POST['is_active']) && $_POST['is_active'] === 'true' ? 1 : 0
    ];

    if (empty($data['account_name']) || empty($data['ad_account_id']) || empty($data['access_token'])) {
        wp_send_json_error(['message' => 'Account Name, Ad Account ID, and Access Token are required.']);
    }
    
    $account_id = isset($_POST['account_id']) ? intval($_POST['account_id']) : 0;

    if ($account_id > 0) {
        $result = $wpdb->update($table_name, $data, ['id' => $account_id]);
    } else {
        $result = $wpdb->insert($table_name, $data);
    }
    
    if ($result === false) {
        wp_send_json_error(['message' => 'Database error: ' . $wpdb->last_error]);
    } else {
        wp_send_json_success(['message' => 'Account saved successfully!']);
    }
}
add_action('wp_ajax_br_save_meta_account', 'br_ajax_save_meta_account');


function br_ajax_get_meta_account_details() { check_ajax_referer('br_meta_ads_nonce','nonce');if(!current_user_can('manage_woocommerce')){wp_send_json_error(['message'=>'Permission denied.']);}$account=br_get_meta_account(intval($_POST['account_id']));if($account){wp_send_json_success($account);}else{wp_send_json_error(['message'=>'Account not found.']);}}
add_action('wp_ajax_br_get_meta_account_details','br_ajax_get_meta_account_details');
function br_ajax_delete_meta_account() { check_ajax_referer('br_meta_ads_nonce','nonce');if(!current_user_can('manage_woocommerce')){wp_send_json_error(['message'=>'Permission denied.']);}global $wpdb;$result=$wpdb->delete($wpdb->prefix.'br_meta_ad_accounts',['id'=>intval($_POST['account_id'])]);if($result){wp_send_json_success(['message'=>'Account deleted.']);}else{wp_send_json_error(['message'=>'Could not delete account.']);}}
add_action('wp_ajax_br_delete_meta_account','br_ajax_delete_meta_account');
function br_ajax_toggle_account_status() { check_ajax_referer('br_meta_ads_nonce','nonce');if(!current_user_can('manage_woocommerce')){wp_send_json_error(['message'=>'Permission denied.']);}global $wpdb;$result=$wpdb->update($wpdb->prefix.'br_meta_ad_accounts',['is_active'=>$_POST['is_active']==='true'?1:0],['id'=>intval($_POST['account_id'])]);if($result!==false){wp_send_json_success(['message'=>'Status updated.']);}else{wp_send_json_error(['message'=>'Failed to update status.']);}}
add_action('wp_ajax_br_toggle_account_status','br_ajax_toggle_account_status');

function br_ajax_sync_meta_data() {
    check_ajax_referer('br_meta_ads_nonce', 'nonce'); if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_send_json_error( [ 'message' => 'Permission denied.' ] ); }
    $start_date_str = isset($_POST['start_date'])?sanitize_text_field($_POST['start_date']):(new DateTime('yesterday'))->format('Y-m-d'); $end_date_str = isset($_POST['end_date'])?sanitize_text_field($_POST['end_date']):$start_date_str; $account_ids = isset($_POST['account_ids'])?(array)array_map('intval',$_POST['account_ids']):[];
    $accounts_to_sync = [];
    if(empty($account_ids)){$accounts_to_sync=array_filter(br_get_meta_accounts(),fn($acc)=>$acc->is_active);}else{foreach($account_ids as $id){if($account=br_get_meta_account($id)){$accounts_to_sync[]=$account;}}}
    if(empty($accounts_to_sync)){wp_send_json_error(['message' => 'No active accounts found to sync.']);}

    $messages = []; $overall_success = true;
    $start_date = new DateTime($start_date_str); $end_date = new DateTime($end_date_str);
    $chunk_size = 30; // Sync in 30-day chunks to be safe with API limits.

    foreach ($accounts_to_sync as $account) {
        if (!$account || !$account->is_active) continue;
        $current_start_date = clone $start_date;
        while ($current_start_date <= $end_date) {
            $current_end_date = clone $current_start_date; $current_end_date->add(new DateInterval('P'.($chunk_size-1).'D'));
            if($current_end_date > $end_date){$current_end_date = clone $end_date;}
            $result = br_fetch_and_process_meta_data($account, $current_start_date->format('Y-m-d'), $current_end_date->format('Y-m-d'));
            if(!$result['success']){ $overall_success = false; }
            $messages[] = "<strong>Account '{$account->account_name}' ({$current_start_date->format('Y-m-d')} to {$current_end_date->format('Y-m-d')})</strong>: " . $result['message'];
            $current_start_date->add(new DateInterval('P'.$chunk_size.'D'));
        }
    }
    $final_message = "Sync process finished for range $start_date_str to $end_date_str.<br><br>" . implode("<br>", $messages);
    if($overall_success){ wp_send_json_success(['message' => $final_message]); } else { wp_send_json_error(['message' => $final_message]); }
}
add_action('wp_ajax_br_sync_meta_data', 'br_ajax_sync_meta_data');

function br_ajax_delete_summary_entry() { check_ajax_referer('br_meta_ads_nonce','nonce');if(!current_user_can('manage_woocommerce')){wp_send_json_error(['message'=>'Permission denied.']);}global $wpdb;$entry_id=isset($_POST['entry_id'])?intval($_POST['entry_id']):0;if($entry_id<=0){wp_send_json_error(['message'=>'Invalid entry ID.']);}if($wpdb->delete($wpdb->prefix.'br_meta_ad_summary',['id'=>$entry_id],['%d'])){wp_send_json_success(['message'=>'Entry deleted.']);}else{wp_send_json_error(['message'=>'Could not delete entry.']);}}
add_action('wp_ajax_br_delete_summary_entry','br_ajax_delete_summary_entry');

/**
 * =================================================================================
 * 5. ADMIN PAGE RENDERING
 * =================================================================================
 */

/**
 * Reusable function to render the date filter component for any tab.
 *
 * @param string $current_tab The slug of the current tab (e.g., 'summary', 'campaign').
 */
function br_render_date_filters_html($current_tab = 'summary') {
    $current_range_key = isset($_GET['range']) ? sanitize_key($_GET['range']) : 'last_30_days';
    $start_date_get = isset($_GET['start_date']) ? sanitize_text_field($_GET['start_date']) : null;
    $end_date_get = isset($_GET['end_date']) ? sanitize_text_field($_GET['end_date']) : null;
    $is_custom_range = !empty($start_date_get) && !empty($end_date_get);

    $filters_main = ['today' => 'Today', 'yesterday' => 'Yesterday', 'last_7_days' => '7D', 'last_30_days' => '30D'];
    $filters_dropdown = ['this_month' => 'This Month', 'this_year' => 'This Year', 'last_year' => 'Last Year', 'lifetime' => 'Lifetime', 'custom' => 'Custom Range'];
    ?>
    <div class="br-filters">
        <div class="br-date-filters">
            <?php
            foreach($filters_main as $key => $label) {
                $is_active = ($current_range_key === $key) && !$is_custom_range;
                echo sprintf('<a href="?page=br-meta-ads&tab=%s&range=%s" class="button %s">%s</a>', esc_attr($current_tab), esc_attr($key), $is_active ? 'active' : '', esc_html($label));
            }
            ?>
            <div class="br-dropdown">
                <button class="button br-dropdown-toggle <?php echo ($is_custom_range || in_array($current_range_key, array_keys($filters_dropdown))) ? 'active' : ''; ?>">...</button>
                <div class="br-dropdown-menu">
                    <?php
                    foreach($filters_dropdown as $key => $label) {
                        if ($key === 'custom') {
                            echo sprintf('<a href="#" id="br-custom-range-trigger">%s</a>', esc_html($label));
                        } else {
                            echo sprintf('<a href="?page=br-meta-ads&tab=%s&range=%s">%s</a>', esc_attr($current_tab), esc_attr($key), esc_html($label));
                        }
                    }
                    ?>
                </div>
            </div>
        </div>
        <?php if ($current_tab === 'summary'): // Only show sync buttons on the summary tab ?>
        <div class="br-sync-actions">
            <button id="br-sync-today-btn" class="button"><span class="dashicons dashicons-update"></span> Sync Today</button>
            <button id="br-sync-7-days-btn" class="button"><span class="dashicons dashicons-update"></span> Sync Last 7 Days</button>
            <button id="br-custom-sync-btn" class="button"><span class="dashicons dashicons-calendar-alt"></span> Custom Sync</button>
            <span id="br-sync-spinner" class="spinner"></span>
            <div id="br-sync-feedback" class="last-sync-time"></div>
        </div>
        <?php endif; ?>
    </div>
    <?php
}

function br_meta_ads_page_html() {
    if(!current_user_can('manage_woocommerce')){return;}
    $active_tab=isset($_GET['tab'])?sanitize_key($_GET['tab']):'summary';
    ?>
    <div class="wrap br-wrap">
        <div class="br-header"><h1><?php _e('Meta Ads Report','business-report');?></h1></div>
        <h2 class="nav-tab-wrapper">
            <a href="?page=br-meta-ads&tab=summary" class="nav-tab <?php echo $active_tab=='summary'?'nav-tab-active':'';?>"><?php _e('Summary','business-report');?></a>
            <a href="?page=br-meta-ads&tab=campaign" class="nav-tab <?php echo $active_tab=='campaign'?'nav-tab-active':'';?>"><?php _e('Campaign','business-report');?></a>
            <a href="?page=br-meta-ads&tab=settings" class="nav-tab <?php echo $active_tab=='settings'?'nav-tab-active':'';?>"><?php _e('Settings','business-report');?></a>
        </h2>
        <div class="br-page-content">
            <?php 
            switch($active_tab){
                case 'campaign':br_meta_ads_campaign_tab_html();break;
                case 'settings':br_meta_ads_settings_tab_html();break;
                default:br_meta_ads_summary_tab_html();break;
            }
            ?>
        </div>
        <?php 
        // **FIX:** Moved modals inside the main wrapper div to ensure JS event delegation works.
        br_meta_ads_custom_sync_modal_html();
        br_meta_ads_account_modal_html();
        br_meta_ads_custom_range_filter_modal_html();
        ?>
    </div>
    <?php 
}

function br_meta_ads_summary_tab_html() { 
    global $wpdb; 
    $summary_table_name = $wpdb->prefix . 'br_meta_ad_summary';
    $accounts_table_name = $wpdb->prefix . 'br_meta_ad_accounts';

    $current_range_key = isset($_GET['range']) ? sanitize_key($_GET['range']) : 'last_30_days';
    $start_date_get = isset($_GET['start_date']) ? sanitize_text_field($_GET['start_date']) : null;
    $end_date_get = isset($_GET['end_date']) ? sanitize_text_field($_GET['end_date']) : null;
    
    $is_custom_range = !empty($start_date_get) && !empty($end_date_get);
    $date_range = br_get_date_range($is_custom_range ? '' : $current_range_key, $start_date_get, $end_date_get);
    $start_date = $date_range['start']; 
    $end_date = $date_range['end'];
    
    // **FIX:** KPI card queries now only include data from active accounts.
    $totals = $wpdb->get_row($wpdb->prepare(
        "SELECT SUM(s.spend_usd) as spend, SUM(s.purchase_value) as value, SUM(s.purchases) as purchases 
         FROM {$summary_table_name} s 
         JOIN {$accounts_table_name} a ON s.account_fk_id = a.id 
         WHERE a.is_active = 1 AND s.report_date BETWEEN %s AND %s", 
        $start_date, $end_date
    ));
    $total_expense_bdt = $wpdb->get_var($wpdb->prepare(
        "SELECT SUM(s.spend_usd * a.usd_to_bdt_rate) 
         FROM {$summary_table_name} s 
         JOIN {$accounts_table_name} a ON s.account_fk_id = a.id 
         WHERE a.is_active = 1 AND s.report_date BETWEEN %s AND %s", 
        $start_date, $end_date
    ));

    $accounts_count = $wpdb->get_var("SELECT COUNT(id) FROM $accounts_table_name WHERE is_active = 1");
    $roas = ($totals->spend > 0) ? ($totals->value / $totals->spend) : 0;
    
    br_render_date_filters_html('summary');
    ?>
    <div class="br-kpi-grid">
        <div class="br-kpi-card"><h4>Total Expense (USD)</h4><p>$<?php echo esc_html(number_format($totals->spend ?? 0, 2)); ?></p></div>
        <div class="br-kpi-card"><h4>Total Expense (BDT)</h4><p>৳<?php echo esc_html(number_format($total_expense_bdt ?? 0, 2)); ?></p></div>
        <div class="br-kpi-card"><h4>Purchases</h4><p><?php echo esc_html(number_format_i18n($totals->purchases ?? 0)); ?></p></div>
        <div class="br-kpi-card"><h4>ROAS</h4><p><?php echo esc_html(number_format($roas, 2)); ?></p></div>
        <div class="br-kpi-card"><h4>Active Accounts</h4><p><?php echo esc_html($accounts_count ?? 0); ?></p></div>
    </div>
    <div class="br-data-table-wrapper">
        <h3>Meta Ads Expenses Summary</h3>
        <?php $summary_table = new BR_Meta_Summary_List_Table(); $summary_table->prepare_items(); $summary_table->display(); ?>
    </div>
    <?php 
}

function br_meta_ads_campaign_tab_html() {
    br_render_date_filters_html('campaign');
    ?>
    <div class="br-data-table-wrapper">
        <h3>Campaign Performance</h3>
        <?php 
        $campaign_table = new BR_Meta_Campaign_List_Table();
        $campaign_table->prepare_items();
        $campaign_table->display();
        ?>
    </div>
    <?php 
}

function br_meta_ads_settings_tab_html() { ?><div class="br-settings-header"><h3 id="br-settings-title"><?php _e('Meta Ads API Accounts','business-report');?></h3><button id="br-add-account-btn" class="button br-add-product-btn"><?php _e('+ Add New Account','business-report');?></button></div><p class="settings-section-description"><?php _e('Manage your Meta Ads API accounts.','business-report');?></p><div id="br-ad-accounts-list"><?php $accounts=br_get_meta_accounts();if(empty($accounts)){echo '<p>No ad accounts have been added yet.</p>';}else{echo br_render_account_cards($accounts);}?></div><?php }
function br_render_account_cards($accounts) { ob_start();foreach($accounts as $account){?><div class="br-ad-account-card" data-account-id="<?php echo esc_attr($account->id);?>"><div class="br-card-header"><strong><?php echo esc_html($account->account_name);?></strong><div class="br-card-actions"><a href="#" class="br-edit-account-btn" title="Edit"><span class="dashicons dashicons-edit"></span></a><a href="#" class="br-delete-account-btn" title="Delete"><span class="dashicons dashicons-trash"></span></a></div></div><p class="account-id">act_<?php echo esc_html($account->ad_account_id);?></p><div class="br-card-row"><span>USD Rate:</span><strong><?php echo esc_html($account->usd_to_bdt_rate);?> BDT</strong></div><div class="br-card-row"><span>Status:</span><span class="br-status-badge <?php echo $account->is_active?'active':'inactive';?>"><?php echo $account->is_active?'Active':'Inactive';?></span></div><div class="br-card-footer"><label class="br-switch"><input type="checkbox" class="br-status-toggle" <?php checked($account->is_active,1);?>><span class="br-slider"></span></label></div></div><?php }return ob_get_clean();}
function br_meta_ads_account_modal_html() { ?><div id="br-add-account-modal" class="br-modal" style="display: none;"><div class="br-modal-content"><button class="br-modal-close">&times;</button><h3 id="br-modal-title"><?php _e('Add Meta Ads Account','business-report');?></h3><p><?php _e('Enter your Meta Ads API credentials','business-report');?></p><form id="br-add-account-form"><input type="hidden" id="account_id" name="account_id" value=""><label for="account_name"><?php _e('Account Name','business-report');?></label><input type="text" id="account_name" name="account_name" required><label for="access_token"><?php _e('Long-Lived Access Token','business-report');?></label><input type="password" id="access_token" name="access_token" required><div class="form-row"><div><label for="ad_account_id"><?php _e('Ad Account ID (without act_)','business-report');?></label><input type="text" id="ad_account_id" name="ad_account_id" required></div><div><label for="usd_to_bdt_rate"><?php _e('USD to BDT Rate','business-report');?></label><input type="number" step="0.01" id="usd_to_bdt_rate" name="usd_to_bdt_rate" required></div></div><div class="form-row-flex"><label for="is_active"><?php _e('Active Status','business-report');?></label><label class="br-switch"><input type="checkbox" id="is_active" name="is_active" checked><span class="br-slider"></span></label></div><div class="form-footer"><div></div><div><button type="button" class="button br-modal-cancel"><?php _e('Cancel','business-report');?></button><button type="submit" class="button button-primary"><?php _e('Save Account','business-report');?></button></div></div></form></div></div><?php }
function br_meta_ads_custom_sync_modal_html() { $accounts=br_get_meta_accounts();?><div id="br-custom-sync-modal" class="br-modal" style="display: none;"><div class="br-modal-content"><button class="br-modal-close">&times;</button><h3><?php _e('Custom Synchronization','business-report');?></h3><p><?php _e('Sync Meta Ads data for specific accounts and date range','business-report');?></p><form id="br-custom-sync-form"><div class="form-row"><div><label for="sync_start_date"><?php _e('Start Date','business-report');?></label><input type="text" id="sync_start_date" name="sync_start_date" class="br-datepicker" autocomplete="off" required></div><div><label for="sync_end_date"><?php _e('End Date','business-report');?></label><input type="text" id="sync_end_date" name="sync_end_date" class="br-datepicker" autocomplete="off" required></div></div><div class="br-account-checklist"><label><?php _e('Select Accounts','business-report');?></label><div class="br-checklist-actions"><button type="button" class="button-link" id="br-select-all-accounts"><?php _e('Select All','business-report');?></button><button type="button" class="button-link" id="br-deselect-all-accounts"><?php _e('Deselect All','business-report');?></button></div><div class="br-checklist"><?php foreach($accounts as $account):if($account->is_active):?><label><input type="checkbox" name="account_ids[]" value="<?php echo esc_attr($account->id);?>" checked> <?php echo esc_html($account->account_name);?></label><?php endif;endforeach;?></div></div><div class="form-footer"><div></div><div><button type="button" class="button br-modal-cancel"><?php _e('Cancel','business-report');?></button><button type="submit" class="button button-primary"><?php _e('Sync Data','business-report');?></button></div></div></form></div></div><?php }
function br_meta_ads_custom_range_filter_modal_html() { $start_date = isset($_GET['start_date']) ? sanitize_text_field($_GET['start_date']) : ''; $end_date = isset($_GET['end_date']) ? sanitize_text_field($_GET['end_date']) : ''; ?><div id="br-custom-range-filter-modal" class="br-modal" style="display: none;"><div class="br-modal-content"><button class="br-modal-close">&times;</button><h3><?php _e('Select Custom Date Range','business-report');?></h3><p><?php _e('Filter the report by a specific date range.','business-report');?></p><form id="br-custom-range-filter-form" method="GET"><input type="hidden" name="page" value="br-meta-ads"><input type="hidden" name="tab" value=""><!-- JS will populate this --> <div class="form-row"><div><label for="br_filter_start_date"><?php _e('Start Date','business-report');?></label><input type="text" id="br_filter_start_date" name="start_date" class="br-datepicker" value="<?php echo esc_attr($start_date);?>" autocomplete="off" required></div><div><label for="br_filter_end_date"><?php _e('End Date','business-report');?></label><input type="text" id="br_filter_end_date" name="end_date" class="br-datepicker" value="<?php echo esc_attr($end_date);?>" autocomplete="off" required></div></div><div class="form-footer"><div></div><div><button type="button" class="button br-modal-cancel"><?php _e('Cancel','business-report');?></button><button type="submit" class="button button-primary"><?php _e('Apply Filter','business-report');?></button></div></div></form></div></div><?php }

