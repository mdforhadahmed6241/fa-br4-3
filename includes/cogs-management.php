<?php
/**
 * COGS Management Functionality for Business Report Plugin
 *
 * @package BusinessReport
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

// Include the List Table class from its new location.
require_once plugin_dir_path( __FILE__ ) . 'classes/class-br-cogs-list-table.php';

/**
 * =================================================================================
 * 1. ADMIN SUBMENU PAGE & ASSETS
 * =================================================================================
 */

function br_cogs_admin_submenu() {
	add_submenu_page(
		'business-report', // Parent slug
		__( 'COGS Management', 'business-report' ),
		__( 'COGS Management', 'business-report' ),
		'manage_woocommerce',
		'br-cogs-management',
		'br_cogs_management_page_html'
	);
}
add_action( 'admin_menu', 'br_cogs_admin_submenu' );


function br_cogs_admin_enqueue_scripts( $hook ) {
	if ( 'business-report_page_br-cogs-management' !== $hook && 'business-report_page_br-settings' !== $hook ) {
		return;
	}
    
    // Enqueue script if on COGS page or the Settings page (for the COGS tab)
	wp_enqueue_script(
		'br-cogs-admin-js',
		plugin_dir_url( __FILE__ ) . '../assets/js/admin-cogs.js',
		[ 'jquery' ],
		'1.0.1',
		true
	);
	wp_localize_script( 'br-cogs-admin-js', 'br_cogs_ajax', [
		'ajax_url' => admin_url( 'admin-ajax.php' ),
		'nonce'    => wp_create_nonce( 'br_apply_rules_nonce' ),
	] );
}
add_action( 'admin_enqueue_scripts', 'br_cogs_admin_enqueue_scripts' );


/**
 * =================================================================================
 * 2. HELPER FUNCTIONS (Unchanged)
 * =================================================================================
 */
function br_get_product_cost( $post_id ) {
	global $wpdb;
	$table_name = $wpdb->prefix . 'br_product_cogs';
	$cost = $wpdb->get_var( $wpdb->prepare( "SELECT cost FROM $table_name WHERE post_id = %d", $post_id ) );
	return $cost !== null ? $cost : '';
}

function br_update_product_cost( $post_id, $cost ) {
	global $wpdb;
	$table_name = $wpdb->prefix . 'br_product_cogs';
	$cost = is_numeric( $cost ) ? floatval( $cost ) : 0.00;
	$wpdb->replace( $table_name, [ 'post_id' => $post_id, 'cost' => $cost ], [ '%d', '%f' ] );
}

function br_calculate_cost_from_rules( $price, $rules ) {
	$calculated_cost = null;
	if ( ! empty( $rules['dynamic_rules'] ) ) {
		foreach ( $rules['dynamic_rules'] as $rule ) {
			if ( $price >= $rule['min'] && $price <= $rule['max'] ) {
				if ( $rule['type'] === 'fixed' ) {
					$calculated_cost = $price - $rule['value'];
				} elseif ( $rule['type'] === 'percentage' ) {
					$calculated_cost = $price - ( $price * $rule['value'] / 100 );
				}
				break;
			}
		}
	}
	if ( $calculated_cost === null && isset( $rules['general_mode'] ) && $rules['general_mode'] !== 'none' ) {
		$value = $rules['general_value'] ?? 0;
		if ( $rules['general_mode'] === 'fixed' ) {
			$calculated_cost = $price - $value;
		} elseif ( $rules['general_mode'] === 'percentage' ) {
			$calculated_cost = $price - ( $price * $value / 100 );
		}
	}
	return ( $calculated_cost !== null && $calculated_cost > 0 ) ? round( $calculated_cost, 2 ) : 0.00;
}


/**
 * =================================================================================
 * 3. WOOCOMMERCE INTEGRATION - ADD/SAVE COGS FIELD (Unchanged)
 * =================================================================================
 */
function br_add_cogs_field_simple() {
	global $post;
	echo '<div class="options_group show_if_simple">';
	woocommerce_wp_text_input( [ 'id' => '_br_cost_price', 'label' => __( 'Cost of Goods (' . get_woocommerce_currency_symbol() . ')', 'business-report' ), 'placeholder' => '0.00', 'desc_tip' => 'true', 'description' => __( 'Enter the cost price for this product.', 'business-report' ), 'data_type' => 'price', 'value' => br_get_product_cost( $post->ID ) ] );
	echo '</div>';
}
add_action( 'woocommerce_product_options_general_product_data', 'br_add_cogs_field_simple' );

function br_add_cogs_field_variable( $loop, $variation_data, $variation ) {
	woocommerce_wp_text_input( [ 'id' => "_br_variable_cost_price[{$loop}]", 'label' => __( 'Cost of Goods (' . get_woocommerce_currency_symbol() . ')', 'business-report' ), 'placeholder' => '0.00', 'desc_tip' => 'true', 'description' => __( 'Enter the cost price for this variation.', 'business-report' ), 'value' => br_get_product_cost( $variation->ID ), 'data_type' => 'price', 'wrapper_class' => 'form-row form-row-full' ] );
}
add_action( 'woocommerce_product_after_variable_attributes', 'br_add_cogs_field_variable', 10, 3 );

function br_calculate_and_save_cost_for_product( $post_id, $product_obj ) {
	if ( ! $product_obj ) { return; }
	$existing_cost = br_get_product_cost( $post_id );
	if ( $existing_cost !== '' && is_numeric( $existing_cost ) && floatval( $existing_cost ) > 0 ) {
		return;
	}
	$rules = get_option( 'br_cogs_settings', [] );
	if ( empty( $rules ) || ( isset( $rules['general_mode'] ) && $rules['general_mode'] === 'none' && empty( $rules['dynamic_rules'] ) ) ) { return; }
	$price = $product_obj->get_price();
	if ( ! is_numeric( $price ) || $price <= 0 ) { return; }
	$calculated_cost = br_calculate_cost_from_rules( $price, $rules );
	if ( $calculated_cost >= 0 ) {
		br_update_product_cost( $post_id, $calculated_cost );
	}
}

function br_save_cogs_logic_simple( $post_id ) {
	if ( isset( $_POST['_br_cost_price'] ) && $_POST['_br_cost_price'] !== '' ) {
		br_update_product_cost( $post_id, wc_clean( wp_unslash( $_POST['_br_cost_price'] ) ) );
	} else {
		$product = wc_get_product( $post_id );
		br_calculate_and_save_cost_for_product( $post_id, $product );
	}
}
add_action( 'woocommerce_process_product_meta_simple', 'br_save_cogs_logic_simple' );

function br_save_cogs_logic_variable( $variation_id, $i ) {
	if ( isset( $_POST['_br_variable_cost_price'][$i] ) && $_POST['_br_variable_cost_price'][$i] !== '' ) {
		br_update_product_cost( $variation_id, wc_clean( wp_unslash( $_POST['_br_variable_cost_price'][$i] ) ) );
	} else {
		$variation = wc_get_product( $variation_id );
		br_calculate_and_save_cost_for_product( $variation_id, $variation );
	}
}
add_action( 'woocommerce_save_product_variation', 'br_save_cogs_logic_variable', 10, 2 );


/**
 * =================================================================================
 * 4. ADMIN PAGE RENDERING & SETTINGS API (Updated Layout)
 * =================================================================================
 */
function br_cogs_management_page_html() {
    // This page is now just for the product list.
    // Settings are moved to the new settings page.
    if ( ! current_user_can( 'manage_woocommerce' ) ) return;
    ?>
    <div class="wrap br-wrap">
        <div class="br-header">
            <h1><?php _e('Cost of Goods Sold (COGS)', 'business-report'); ?></h1>
            <a href="<?php echo admin_url('admin.php?page=br-settings&tab=cogs'); ?>" class="button"><?php _e('COGS Settings', 'business-report'); ?></a>
        </div>

        <div class="br-page-content">
            <?php br_cogs_product_list_tab_html(); ?>
        </div>
    </div>
    <?php
}

function br_cogs_product_list_tab_html() {
    $cogs_list_table = new BR_COGS_List_Table();
    $cogs_list_table->prepare_items();
    ?>
    <div class="br-table-top-controls">
        <a href="<?php echo admin_url('post-new.php?post_type=product'); ?>" class="page-title-action br-add-product-btn">
            <?php _e('Add Product', 'business-report'); ?>
        </a>
        <?php $cogs_list_table->search_box(__('Search Products', 'business-report'), 'product'); ?>
    </div>
    <form id="br-cogs-list-form" method="post">
        <?php
        $cogs_list_table->display();
        ?>
    </form>
    <?php
}

// All settings functions below are kept as they are CALLED by the new settings-page.php
// but they are no longer registered here.

function br_cogs_general_rules_section_callback(){echo'<p class="settings-section-description">'.__('This general rule will be applied to new products if they do not match any dynamic rules below.','business-report').'</p>';}
function br_cogs_dynamic_rules_section_callback(){echo'<p class="settings-section-description">'.__('Add rules to set costs based on the product\'s selling price. Rules are checked in order from top to bottom.','business-report').'</p>';}
function br_cogs_apply_rules_section_callback(){echo'<p class="settings-section-description">'.__('Use this to automatically add costs to all existing products that do not currently have a cost price set. It will use the rules you have saved above.','business-report').'</p>';echo'<button type="button" id="br-apply-rules-btn" class="button button-secondary">'.__('Apply Cost Rules to Existing Products','business-report').'</button>';echo'<span id="br-apply-rules-spinner" class="spinner" style="float: none; vertical-align: middle; margin-left: 5px;"></span>';echo'<div id="br-apply-rules-feedback" style="margin-top: 10px;"></div>';}
function br_cogs_field_general_mode_html(){$options=get_option('br_cogs_settings',[]);$mode=$options['general_mode']??'none';?><select name="br_cogs_settings[general_mode]"><option value="none" <?php selected($mode,'none');?>><?php _e('Disabled','business-report');?></option><option value="fixed" <?php selected($mode,'fixed');?>><?php _e('Selling Price - Fixed Amount','business-report');?></option><option value="percentage" <?php selected($mode,'percentage');?>><?php _e('Selling Price - Percentage (%)','business-report');?></option></select><?php }
function br_cogs_field_general_value_html(){$options=get_option('br_cogs_settings',[]);$value=$options['general_value']??'';?><input type="number" step="0.01" name="br_cogs_settings[general_value]" value="<?php echo esc_attr($value);?>"/><?php }
function br_cogs_field_dynamic_rules_html(){$options=get_option('br_cogs_settings',[]);$rules=$options['dynamic_rules']??[];?><table id="br-dynamic-rules-table" class="wp-list-table widefat striped"><thead><tr><th><?php _e('Min Price','business-report');?></th><th><?php _e('Max Price','business-report');?></th><th><?php _e('Type','business-report');?></th><th><?php _e('Value','business-report');?></th><th>&nbsp;</th></tr></thead><tbody id="br-dynamic-rules-body"><?php if(!empty($rules)){foreach($rules as $i=>$rule){?><tr class="br-rule-row"><td><input type="number" step="0.01" name="br_cogs_settings[dynamic_rules][<?php echo $i;?>][min]" value="<?php echo esc_attr($rule['min']);?>" placeholder="0.00"></td><td><input type="number" step="0.01" name="br_cogs_settings[dynamic_rules][<?php echo $i;?>][max]" value="<?php echo esc_attr($rule['max']);?>" placeholder="100.00"></td><td><select name="br_cogs_settings[dynamic_rules][<?php echo $i;?>][type]"><option value="fixed" <?php selected($rule['type'],'fixed');?>><?php _e('Fixed Amount','business-report');?></option><option value="percentage" <?php selected($rule['type'],'percentage');?>><?php _e('Percentage (%)','business-report');?></option></select></td><td><input type="number" step="0.01" name="br_cogs_settings[dynamic_rules][<?php echo $i;?>][value]" value="<?php echo esc_attr($rule['value']);?>"></td><td><button type="button" class="button button-secondary br-remove-rule-btn">Remove</button></td></tr><?php }}?></tbody></table><button type="button" id="br-add-rule-btn" class="button button-secondary" style="margin-top: 10px;"><?php _e('Add Rule','business-report');?></button><?php }
function br_cogs_settings_sanitize($input){$sanitized_input=[];$sanitized_input['general_mode']=isset($input['general_mode'])?sanitize_key($input['general_mode']):'none';$sanitized_input['general_value']=isset($input['general_value'])&&is_numeric($input['general_value'])?floatval($input['general_value']):'';if(!empty($input['dynamic_rules'])&&is_array($input['dynamic_rules'])){foreach($input['dynamic_rules']as $rule){if(!is_numeric($rule['min'])||!is_numeric($rule['max'])||!is_numeric($rule['value']))continue;$sanitized_input['dynamic_rules'][]=['min'=>floatval($rule['min']),'max'=>floatval($rule['max']),'type'=>sanitize_key($rule['type']),'value'=>floatval($rule['value']),];}}return $sanitized_input;}


/**
 * =================================================================================
 * 5. AJAX HANDLER FOR APPLYING RULES (Unchanged)
 * =================================================================================
 */
function br_ajax_apply_rules_to_existing() {
	check_ajax_referer( 'br_apply_rules_nonce', 'nonce' );
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_send_json_error( [ 'message' => 'Permission denied.' ] );
	}
	global $wpdb;
	$cogs_table = $wpdb->prefix . 'br_product_cogs';
	$posts_table = $wpdb->prefix . 'posts';
	$product_ids_without_cost = $wpdb->get_col( "
        SELECT p.ID FROM {$posts_table} p
        LEFT JOIN {$cogs_table} c ON p.ID = c.post_id
        WHERE p.post_type IN ('product', 'product_variation')
        AND p.post_status = 'publish'
        AND (c.post_id IS NULL OR c.cost = 0.00)
    " );
	if ( empty( $product_ids_without_cost ) ) {
		wp_send_json_success( [
			'message'       => 'All products already have a cost price set.',
			'updated_count' => 0,
		] );
	}
	$rules = get_option( 'br_cogs_settings', [] );
	if ( empty( $rules ) || ( isset( $rules['general_mode'] ) && $rules['general_mode'] === 'none' && empty( $rules['dynamic_rules'] ) ) ) {
		wp_send_json_error( [ 'message' => 'No automatic cost rules are saved. Please save your settings first.' ] );
	}
	$updated_count = 0;
	foreach ( $product_ids_without_cost as $product_id ) {
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			continue;
		}
		$price = $product->get_price();
		if ( ! is_numeric( $price ) || $price <= 0 ) {
			continue;
		}
		$calculated_cost = br_calculate_cost_from_rules( $price, $rules );
		if ( $calculated_cost > 0 ) {
			br_update_product_cost( $product_id, $calculated_cost );
			$updated_count++;
		}
	}
	wp_send_json_success( [
		'message'       => sprintf(
			_n(
				'%d product had its cost price updated.',
				'%d products had their cost prices updated.',
				$updated_count,
				'business-report'
			),
			$updated_count
		),
		'updated_count' => $updated_count,
	] );
}
add_action( 'wp_ajax_br_apply_rules_to_existing', 'br_ajax_apply_rules_to_existing' );
