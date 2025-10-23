<?php
/**
 * Settings Page Functionality for Business Report Plugin
 *
 * @package BusinessReport
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * =================================================================================
 * 1. ADMIN PAGE & SETTINGS REGISTRATION
 * =================================================================================
 */

function br_settings_page_html() {
    if ( ! current_user_can( 'manage_woocommerce' ) ) return;
    
    // Move COGS settings registration here
    if (!function_exists('br_cogs_settings_sanitize')) {
        require_once BR_PLUGIN_DIR . 'includes/cogs-management.php';
    }

    $active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'order';
    ?>
    <div class="wrap br-wrap">
        <div class="br-header">
            <h1><?php _e( 'Plugin Settings', 'business-report' ); ?></h1>
        </div>

        <h2 class="nav-tab-wrapper">
            <a href="?page=br-settings&tab=order" class="nav-tab <?php echo $active_tab == 'order' ? 'nav-tab-active' : ''; ?>"><?php _e( 'Order', 'business-report' ); ?></a>
            <a href="?page=br-settings&tab=cogs" class="nav-tab <?php echo $active_tab == 'cogs' ? 'nav-tab-active' : ''; ?>"><?php _e( 'COGS', 'business-report' ); ?></a>
        </h2>
        <div class="br-page-content">
            <form class="br-settings-form" method="post" action="options.php">
                <?php
                if ( $active_tab == 'order' ) {
                    settings_fields( 'br_order_settings_group' );
                    do_settings_sections( 'br_order_settings_page' );
                } elseif ( $active_tab == 'cogs' ) {
                    settings_fields( 'br_cogs_settings_group' );
                    do_settings_sections( 'br_cogs_settings_page' );
                }
                submit_button();
                ?>
            </form>
        </div>
    </div>
    <?php
}

function br_register_all_settings() {
    // 1. Order Settings
    register_setting( 'br_order_settings_group', 'br_converted_order_statuses' );
    add_settings_section( 'br_order_status_section', __( 'Order Report Settings', 'business-report' ), 'br_order_status_section_callback', 'br_order_settings_page' );
    add_settings_field( 'converted_statuses', __( 'Converted Order Statuses', 'business-report' ), 'br_order_statuses_field_html', 'br_order_settings_page', 'br_order_status_section' );

    // 2. COGS Settings (Moved from cogs-management.php)
    register_setting('br_cogs_settings_group','br_cogs_settings','br_cogs_settings_sanitize');
    add_settings_section('br_cogs_general_rules_section',__('Set General Cost','business-report'),'br_cogs_general_rules_section_callback','br_cogs_settings_page');
    add_settings_field('general_mode',__('General Cost Mode','business-report'),'br_cogs_field_general_mode_html','br_cogs_settings_page','br_cogs_general_rules_section');
    add_settings_field('general_value',__('Value for Calculation','business-report'),'br_cogs_field_general_value_html','br_cogs_settings_page','br_cogs_general_rules_section');
    add_settings_section('br_cogs_dynamic_rules_section',__('Set Cost Dynamically by Price Range','business-report'),'br_cogs_dynamic_rules_section_callback','br_cogs_settings_page');
    add_settings_field('dynamic_rules',__('Conditional Rules','business-report'),'br_cogs_field_dynamic_rules_html','br_cogs_settings_page','br_cogs_dynamic_rules_section');
    add_settings_section('br_cogs_apply_rules_section',__('Apply Rules to Existing Products','business-report'),'br_cogs_apply_rules_section_callback','br_cogs_settings_page');
}
add_action( 'admin_init', 'br_register_all_settings' );


/**
 * =================================================================================
 * 2. ORDER SETTINGS CALLBACKS
 * =================================================================================
 */

function br_order_status_section_callback() {
    echo '<p class="settings-section-description">' . __( 'Select which WooCommerce order statuses should be counted as a "Converted" order in your reports.', 'business-report' ) . '</p>';
}

function br_order_statuses_field_html() {
    $saved_statuses = get_option( 'br_converted_order_statuses', ['completed'] );
    if ( ! is_array( $saved_statuses ) ) {
        $saved_statuses = ['completed'];
    }
    
    $wc_statuses = wc_get_order_statuses();
    ?>
    <select id="br_converted_order_statuses" name="br_converted_order_statuses[]" multiple="multiple" class="wc-enhanced-select" style="min-width: 300px;" data-placeholder="<?php _e( 'Select statuses...', 'business-report' ); ?>">
        <?php
        foreach ( $wc_statuses as $key => $label ) {
            // Remove 'wc-' prefix for comparison
            $status_key = str_replace( 'wc-', '', $key );
            $selected = in_array( $status_key, $saved_statuses ) ? 'selected' : '';
            echo '<option value="' . esc_attr( $status_key ) . '" ' . $selected . '>' . esc_html( $label ) . '</option>';
        }
        ?>
    </select>
    <p class="description">
        <?php _e( 'Hold CTRL (or CMD on Mac) to select multiple statuses. We recommend selecting "Completed".', 'business-report' ); ?>
    </p>
    <?php
    // Enqueue enhanced select scripts
    wp_enqueue_script( 'wc-enhanced-select' );
}
