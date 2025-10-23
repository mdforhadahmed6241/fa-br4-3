<?php
/**
 * Plugin Name:       Business Report
 * Plugin URI:        https://example.com/
 * Description:       A comprehensive reporting tool for WooCommerce.
 * Version:           1.5.7
 * Author:            Your Name
 * Author URI:        https://yourwebsite.com/
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       business-report
 * Domain Path:       /languages
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

// Add initial log to confirm file loading
error_log("BR Log: business-report.php main file started loading.");

// Bumping the version number to 1.5.7 for logging additions.
define( 'BR_PLUGIN_VERSION', '1.5.7' );
define( 'BR_PLUGIN_DIR', plugin_dir_path( __FILE__ ) ); // Define constants early
define( 'BR_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * The core plugin class.
 */
final class Business_Report {

	private static $instance;

	public static function instance() {
		error_log("BR Log: Business_Report::instance() called."); // Log instance creation
		if ( ! isset( self::$instance ) && ! ( self::$instance instanceof Business_Report ) ) {
			self::$instance = new Business_Report();
			self::$instance->includes(); // Include files early
			self::$instance->hooks();
			error_log("BR Log: Business_Report instance created and hooks initialized."); // Log successful init
		} else {
            error_log("BR Log: Business_Report instance already exists.");
        }
		return self::$instance;
	}

	private function includes() {
        error_log("BR Log: includes() method started.");
        $required_files = [
            'includes/settings-page.php',
            'includes/cogs-management.php',
            'includes/meta-ads.php',
            'includes/expense-management.php',
            'includes/order-report.php'
        ];
        foreach ($required_files as $file) {
            $path = BR_PLUGIN_DIR . $file;
            if (file_exists($path)) {
                require_once $path;
                error_log("BR Log: Successfully included {$file}");
            } else {
                error_log("BR Log Error: Failed to include {$file} - File not found at {$path}");
            }
        }
        error_log("BR Log: includes() method finished.");
	}


	private function hooks() {
        error_log("BR Log: hooks() method started.");
		register_activation_hook( __FILE__, [ $this, 'activate' ] );
        add_action( 'admin_init', [ $this, 'check_for_updates' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_styles_and_scripts' ] );
		add_action( 'admin_menu', [ $this, 'admin_menu' ] );
        add_action( 'admin_init', [ $this, 'remove_admin_notices' ] );
		add_action( 'br_daily_add_monthly_expenses_event', [ $this, 'execute_monthly_expense_cron' ] );

		// --- ORDER REPORT HOOKS ---
        add_action( 'save_post_shop_order', 'br_save_or_update_order_report_data_on_save', 40, 3 );
        add_action( 'oms_order_created', 'br_save_or_update_order_report_data', 10, 1 );
		add_action( 'woocommerce_order_status_changed', 'br_order_status_changed_update_report', 40, 4 );
		// --- END ORDER REPORT HOOKS ---
         error_log("BR Log: hooks() method finished.");
	}

    /**
     * Runs on plugin activation ONLY.
     */
    public function activate() {
        error_log("BR Log: Activation hook fired."); // Log activation
        $this->run_db_install(); // Create/update tables using dbDelta
        $this->force_add_missing_columns(); // Force add columns if needed
        $this->schedule_cron_jobs();
        // Set default options if they don't exist
        if ( get_option( 'br_converted_order_statuses', false ) === false ) {
			add_option( 'br_converted_order_statuses', ['completed'] );
            error_log("BR Log: Default converted statuses set."); // Log option set
		}
        // Set current version on activation
        update_option( 'br_plugin_version', BR_PLUGIN_VERSION );
        error_log("BR Log: Plugin version updated to " . BR_PLUGIN_VERSION); // Log version update
        flush_rewrite_rules(); // Good practice on activation
    }

    /**
     * Remove non-plugin admin notices from plugin pages.
     */
    public function remove_admin_notices() {
        // Only run if page is set and we are in admin area
        if ( ! is_admin() || ! isset( $_GET['page'] ) ) return;

        $page = sanitize_key($_GET['page']);
        if ( strpos( $page, 'br-' ) === 0 || $page === 'business-report' ) {
            // Use a hook that runs later to remove notices effectively
            add_action('in_admin_header', function() {
                 remove_all_actions( 'admin_notices' );
                 remove_all_actions( 'all_admin_notices' );
                 error_log("BR Log: Removed admin notices for page: " . sanitize_key($_GET['page']));
            }, 1000); // High priority
        }
    }


	public function admin_menu() {
        error_log("BR Log: admin_menu hook fired. Registering menus."); // Log menu registration
		$main_page_hook = add_menu_page(
            __( 'Business Report', 'business-report' ),
            __( 'Business Report', 'business-report' ),
            'manage_woocommerce', // Capability check
            'business-report', // Menu slug
            'br_dashboard_page_html', // Callback function
            'dashicons-chart-bar', // Icon
            56 // Position
        );

        // Check if main page was added successfully before adding submenus
        if ($main_page_hook) {
            add_submenu_page( 'business-report', __( 'Order Report', 'business-report' ), __( 'Order Report', 'business-report' ), 'manage_woocommerce', 'br-order-report', 'br_order_report_page_html' );
            add_submenu_page( 'business-report', __( 'Expense', 'business-report' ), __( 'Expense', 'business-report' ), 'manage_woocommerce', 'br-expense', 'br_expense_page_html' );
            add_submenu_page( 'business-report', __( 'Meta Ads', 'business-report' ), __( 'Meta Ads', 'business-report' ), 'manage_woocommerce', 'br-meta-ads', 'br_meta_ads_page_html' );
            add_submenu_page( 'business-report', __( 'COGS', 'business-report' ), __( 'COGS Management', 'business-report' ), 'manage_woocommerce', 'br-cogs-management', 'br_cogs_management_page_html' );
            add_submenu_page( 'business-report', __( 'Settings', 'business-report' ), __( 'Settings', 'business-report' ), 'manage_options', 'br-settings', 'br_settings_page_html' );
            error_log("BR Log: Submenus registered.");
        } else {
             error_log("BR Log Error: Failed to add main menu page 'business-report'. Submenus not added.");
        }
	}

	public function enqueue_styles_and_scripts( $hook ) {
        // Check if page is set before accessing $_GET['page']
        if ( ! isset( $_GET['page'] ) ) return;
        $page = sanitize_key($_GET['page']);
        // Only enqueue on plugin pages
        if (strpos($page, 'business-report') === false && strpos($page, 'br-') === false) {
             return;
        }
        error_log("BR Log: Enqueueing styles for hook: {$hook}");
		// Enqueue global styles
		$css_file_path = BR_PLUGIN_DIR . 'assets/css/admin-styles.css';
        if ( file_exists( $css_file_path ) ) {
            $css_version = filemtime( $css_file_path );
            wp_enqueue_style( 'br-admin-styles', BR_PLUGIN_URL . 'assets/css/admin-styles.css', [], $css_version );
            error_log("BR Log: Enqueued admin-styles.css version {$css_version}");
        } else {
             error_log("BR Log Error: admin-styles.css not found at {$css_file_path}");
        }

        // Specific JS enqueuing is handled within each module's file via admin_enqueue_scripts hook
	}

    /**
     * Check if plugin version changed and run updates.
     * Now includes the direct ALTER TABLE check.
     */
    public function check_for_updates() {
        $installed_version = get_option( 'br_plugin_version', '0.0.0' );
        if ( version_compare($installed_version, BR_PLUGIN_VERSION, '<') ) {
             error_log("BR Log: Updating plugin from version {$installed_version} to " . BR_PLUGIN_VERSION);
             $this->run_db_install(); // Run dbDelta first
             $this->force_add_missing_columns(); // Then force add columns
             $this->schedule_cron_jobs(); // Ensure cron is scheduled
             update_option( 'br_plugin_version', BR_PLUGIN_VERSION );
             error_log("BR Log: Update complete. New version: " . BR_PLUGIN_VERSION);
        }
    }

    /**
     * Force add missing columns using ALTER TABLE if dbDelta failed.
     */
    private function force_add_missing_columns() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'br_orders';
        $column_name = 'shipping_cost';
        $full_table_name = $wpdb->prefix . 'br_orders'; // Use full name for query

        // Check if the table itself exists first
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $full_table_name)) != $full_table_name) {
            error_log("BR Log Error: Table '{$full_table_name}' does not exist. Cannot add column.");
            return;
        }

        // Check if the column exists using DESCRIBE
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $column_exists_result = $wpdb->get_results($wpdb->prepare("DESCRIBE {$full_table_name} %s", $column_name));

        if (empty($column_exists_result)) {
            error_log("BR Log: Column '{$column_name}' not found in '{$full_table_name}'. Attempting ALTER TABLE.");
            // Determine where to add the column (e.g., after 'discount')
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $alter_result = $wpdb->query("ALTER TABLE `{$full_table_name}` ADD COLUMN `{$column_name}` DECIMAL(12,2) DEFAULT 0.00 AFTER `discount`");
            $wpdb->flush(); // Clear cache

            if (false === $alter_result) {
                 error_log("BR Log Error: Failed to add '{$column_name}' column via ALTER TABLE. DB Error: " . $wpdb->last_error);
            } else {
                 error_log("BR Log: Successfully added '{$column_name}' column via ALTER TABLE.");
            }
        } else {
            error_log("BR Log: Column '{$column_name}' already exists in '{$full_table_name}'. Skipping ALTER TABLE.");
        }
        // Add checks for other columns here if needed in the future
    }


    public function schedule_cron_jobs() {
        if ( ! wp_next_scheduled( 'br_daily_add_monthly_expenses_event' ) ) {
            // Schedule for 2 AM server time
            wp_schedule_event( strtotime('02:00:00'), 'daily', 'br_daily_add_monthly_expenses_event' );
            error_log("BR Log: Scheduled daily monthly expense cron job.");
        }
	}

	public function execute_monthly_expense_cron() {
        error_log("BR Log: Executing monthly expense cron job.");
        if ( function_exists('br_add_monthly_expenses_to_list') ) {
            br_add_monthly_expenses_to_list();
            error_log("BR Log: Monthly expense cron job finished.");
        } else {
             error_log("Business Report Cron Error: Function br_add_monthly_expenses_to_list not found.");
        }
    }


	public function run_db_install() {
        error_log("BR Log: Running run_db_install (dbDelta).");
        // ... (rest of dbDelta definitions remain the same) ...
        global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// COGS Table
		$cogs_table_name = $wpdb->prefix . 'br_product_cogs';
		$sql_cogs = "CREATE TABLE $cogs_table_name ( id mediumint(9) NOT NULL AUTO_INCREMENT, post_id bigint(20) UNSIGNED NOT NULL, cost decimal(10,2) NOT NULL DEFAULT '0.00', last_updated timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, PRIMARY KEY  (id), UNIQUE KEY post_id (post_id) ) $charset_collate;";
		dbDelta( $sql_cogs );

		// Meta Ads Accounts Table
		$accounts_table = $wpdb->prefix . 'br_meta_ad_accounts';
		$sql_accounts = "CREATE TABLE $accounts_table ( id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT, account_name VARCHAR(255) NOT NULL, app_id VARCHAR(255) DEFAULT NULL, app_secret TEXT DEFAULT NULL, access_token TEXT NOT NULL, ad_account_id VARCHAR(255) NOT NULL, usd_to_bdt_rate DECIMAL(10, 4) NOT NULL DEFAULT 0.0000, is_active TINYINT(1) NOT NULL DEFAULT 1, PRIMARY KEY (id) ) $charset_collate;";
		dbDelta($sql_accounts);

		// Meta Ads Summary Table
        $summary_table = $wpdb->prefix . 'br_meta_ad_summary';
        $sql_summary = "CREATE TABLE $summary_table ( id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT, account_fk_id BIGINT(20) UNSIGNED NOT NULL, report_date DATE NOT NULL, spend_usd DECIMAL(12, 2) DEFAULT 0.00, purchases INT(11) UNSIGNED DEFAULT 0, purchase_value DECIMAL(12, 2) DEFAULT 0.00, adds_to_cart INT(11) UNSIGNED DEFAULT 0, initiate_checkouts INT(11) UNSIGNED DEFAULT 0, impressions INT(11) UNSIGNED DEFAULT 0, clicks INT(11) UNSIGNED DEFAULT 0, PRIMARY KEY (id), UNIQUE KEY account_date (account_fk_id, report_date), KEY report_date (report_date) ) $charset_collate;";
		dbDelta($sql_summary);

		// Meta Ads Campaign Table
        $campaign_table = $wpdb->prefix . 'br_meta_campaign_data';
        $sql_campaign = "CREATE TABLE $campaign_table ( id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT, campaign_id VARCHAR(255) NOT NULL, campaign_name TEXT DEFAULT NULL, account_fk_id BIGINT(20) UNSIGNED NOT NULL, report_date DATE NOT NULL, objective VARCHAR(255) DEFAULT NULL, spend_usd DECIMAL(12, 2) DEFAULT 0.00, impressions INT(11) UNSIGNED DEFAULT 0, reach INT(11) UNSIGNED DEFAULT 0, frequency DECIMAL(10, 4) DEFAULT 0.0000, clicks INT(11) UNSIGNED DEFAULT 0, ctr DECIMAL(10, 4) DEFAULT 0.0000, cpc DECIMAL(10, 4) DEFAULT 0.0000, cpm DECIMAL(10, 4) DEFAULT 0.0000, roas DECIMAL(10, 4) DEFAULT 0.0000, purchases INT(11) UNSIGNED DEFAULT 0, adds_to_cart INT(11) UNSIGNED DEFAULT 0, initiate_checkouts INT(11) UNSIGNED DEFAULT 0, purchase_value DECIMAL(12, 2) DEFAULT 0.00, PRIMARY KEY (id), UNIQUE KEY campaign_date (campaign_id, report_date), KEY report_date (report_date), KEY account_fk_id (account_fk_id) ) $charset_collate;";
		dbDelta($sql_campaign);

		// Expense Categories Table
		$expense_cat_table = $wpdb->prefix . 'br_expense_categories';
		$sql_expense_cat = "CREATE TABLE $expense_cat_table ( id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT, name VARCHAR(191) NOT NULL, PRIMARY KEY (id), UNIQUE KEY name (name) ) $charset_collate;";
		dbDelta($sql_expense_cat);

		$uncategorized_exists = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $expense_cat_table WHERE name = %s", 'Uncategorized' ) );
		if ( ! $uncategorized_exists ) {
			$wpdb->insert( $expense_cat_table, [ 'name' => 'Uncategorized' ], [ '%s' ] );
		}

		// Expenses Table
		$expenses_table = $wpdb->prefix . 'br_expenses';
		$sql_expenses = "CREATE TABLE $expenses_table ( id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT, reason TEXT DEFAULT NULL, category_id BIGINT(20) UNSIGNED NOT NULL, amount DECIMAL(12, 2) NOT NULL DEFAULT 0.00, expense_date DATE NOT NULL, PRIMARY KEY (id), KEY category_id (category_id), KEY expense_date (expense_date) ) $charset_collate;";
		dbDelta($sql_expenses);

		// Monthly Expenses Table
		$monthly_expenses_table = $wpdb->prefix . 'br_monthly_expenses';
		$sql_monthly_expenses = "CREATE TABLE $monthly_expenses_table ( id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT, reason TEXT DEFAULT NULL, category_id BIGINT(20) UNSIGNED NOT NULL, amount DECIMAL(12, 2) NOT NULL DEFAULT 0.00, listed_date TINYINT UNSIGNED NOT NULL, last_added_month TINYINT UNSIGNED DEFAULT NULL, last_added_year SMALLINT UNSIGNED DEFAULT NULL, PRIMARY KEY (id), KEY category_id (category_id) ) $charset_collate;";
		dbDelta($sql_monthly_expenses);

		// Order Report table (definition including shipping_cost)
		$orders_table_name = $wpdb->prefix . 'br_orders';
		$sql_orders = "CREATE TABLE $orders_table_name (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			order_id BIGINT UNSIGNED NOT NULL,
			order_date DATETIME DEFAULT '0000-00-00 00:00:00' NOT NULL,
			customer_id BIGINT UNSIGNED DEFAULT NULL,
			customer_name VARCHAR(255) DEFAULT NULL,
			customer_phone VARCHAR(50) DEFAULT NULL,
			customer_email VARCHAR(191) DEFAULT NULL,
			total_items INT UNSIGNED DEFAULT 0,
			product_ids TEXT DEFAULT NULL,
			variation_ids TEXT DEFAULT NULL,
			category_ids TEXT DEFAULT NULL,
			total_order_value DECIMAL(12,2) DEFAULT 0.00,
			total_value DECIMAL(12,2) DEFAULT 0.00,
			cogs_total DECIMAL(12,2) DEFAULT 0.00,
			discount DECIMAL(12,2) DEFAULT 0.00,
			shipping_cost DECIMAL(12,2) DEFAULT 0.00,
			payment_method VARCHAR(100) DEFAULT NULL,
			order_status VARCHAR(50) DEFAULT NULL,
			is_converted TINYINT(1) DEFAULT 0,
			source VARCHAR(100) DEFAULT NULL,
			gross_profit DECIMAL(12,2) DEFAULT 0.00,
			net_profit DECIMAL(12,2) DEFAULT 0.00,
			profit_margin DECIMAL(6,2) DEFAULT 0.00,
			notes TEXT DEFAULT NULL,
			created_at DATETIME DEFAULT '0000-00-00 00:00:00' NOT NULL,
			updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY order_id (order_id),
            KEY order_date (order_date),
            KEY is_converted (is_converted),
            KEY customer_phone (customer_phone)
		) $charset_collate;";
		$delta_result = dbDelta($sql_orders);
        error_log("BR Log: dbDelta result for br_orders table: " . print_r($delta_result, true));

	}
}

/**
 * Renders the placeholder dashboard page.
 */
function br_dashboard_page_html() {
	?>
	<div class="wrap br-wrap">
		<h1><?php esc_html_e( 'Business Report Dashboard', 'business-report' ); ?></h1>
		<p><?php esc_html_e( 'Welcome! Select a report from the menu on the left.', 'business-report' ); ?></p>
        <p><i><?php esc_html_e( 'The main dashboard summary will be built here in a future version.', 'business-report' ); ?></i></p>
	</div>
	<?php
}


/**
 * Begins execution of the plugin. Checks for WooCommerce first.
 */
function business_report_init() {
    error_log("BR Log: business_report_init() called."); // Log initialization start
    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', function() {
            ?>
            <div class="notice notice-error is-dismissible">
                <p><?php _e( 'Business Report plugin requires WooCommerce to be installed and active.', 'business-report' ); ?></p>
            </div>
            <?php
        });
         error_log("BR Log Error: WooCommerce class not found. Business Report not initialized."); // Log WC missing
        return; // Stop initialization if WC is not active
    }
    error_log("BR Log: WooCommerce check passed. Initializing Business_Report instance."); // Log WC check passed
	return Business_Report::instance();
}
// Initialize after plugins are loaded, with slightly higher priority to ensure WC is loaded.
add_action( 'plugins_loaded', 'business_report_init', 11 );

