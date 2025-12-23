<?php
/**
 * Admin class for WP Nalda Sync
 *
 * @package WP_Nalda_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Admin class
 */
class WPNS_Admin {

    /**
     * Logger instance
     *
     * @var WPNS_Logger
     */
    private $logger;

    /**
     * CSV Generator instance
     *
     * @var WPNS_CSV_Generator
     */
    private $csv_generator;

    /**
     * SFTP Uploader instance
     *
     * @var WPNS_SFTP_Uploader
     */
    private $sftp_uploader;

    /**
     * Cron instance
     *
     * @var WPNS_Cron
     */
    private $cron;

    /**
     * Nalda API instance
     *
     * @var WPNS_Nalda_API
     */
    private $nalda_api;

    /**
     * Order Importer instance
     *
     * @var WPNS_Order_Importer
     */
    private $order_importer;

    /**
     * Constructor
     *
     * @param WPNS_Logger         $logger         Logger instance.
     * @param WPNS_CSV_Generator  $csv_generator  CSV Generator instance.
     * @param WPNS_SFTP_Uploader  $sftp_uploader  SFTP Uploader instance.
     * @param WPNS_Cron           $cron           Cron instance.
     * @param WPNS_Nalda_API      $nalda_api      Nalda API instance.
     * @param WPNS_Order_Importer $order_importer Order Importer instance.
     */
    public function __construct( $logger, $csv_generator, $sftp_uploader, $cron, $nalda_api = null, $order_importer = null ) {
        $this->logger         = $logger;
        $this->csv_generator  = $csv_generator;
        $this->sftp_uploader  = $sftp_uploader;
        $this->cron           = $cron;
        $this->nalda_api      = $nalda_api;
        $this->order_importer = $order_importer;

        $this->init_hooks();
    }

    /**
     * Initialize hooks
     */
    private function init_hooks() {
        add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

        // AJAX handlers
        add_action( 'wp_ajax_wpns_test_connection', array( $this, 'ajax_test_connection' ) );
        add_action( 'wp_ajax_wpns_run_sync', array( $this, 'ajax_run_sync' ) );
        add_action( 'wp_ajax_wpns_clear_logs', array( $this, 'ajax_clear_logs' ) );
        add_action( 'wp_ajax_wpns_get_logs', array( $this, 'ajax_get_logs' ) );
        add_action( 'wp_ajax_wpns_download_csv', array( $this, 'ajax_download_csv' ) );
        add_action( 'wp_ajax_wpns_test_nalda_api', array( $this, 'ajax_test_nalda_api' ) );
        add_action( 'wp_ajax_wpns_run_order_sync', array( $this, 'ajax_run_order_sync' ) );

        // Settings link on plugins page
        add_filter( 'plugin_action_links_' . WPNS_PLUGIN_BASENAME, array( $this, 'add_settings_link' ) );
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_menu_page(
            __( 'Nalda Sync', 'wp-nalda-sync' ),
            __( 'Nalda Sync', 'wp-nalda-sync' ),
            'manage_woocommerce',
            'wp-nalda-sync',
            array( $this, 'render_settings_page' ),
            'dashicons-update',
            58
        );

        add_submenu_page(
            'wp-nalda-sync',
            __( 'Settings', 'wp-nalda-sync' ),
            __( 'Settings', 'wp-nalda-sync' ),
            'manage_woocommerce',
            'wp-nalda-sync',
            array( $this, 'render_settings_page' )
        );

        add_submenu_page(
            'wp-nalda-sync',
            __( 'Order Sync', 'wp-nalda-sync' ),
            __( 'Order Sync', 'wp-nalda-sync' ),
            'manage_woocommerce',
            'wp-nalda-sync-orders',
            array( $this, 'render_orders_page' )
        );

        add_submenu_page(
            'wp-nalda-sync',
            __( 'Logs', 'wp-nalda-sync' ),
            __( 'Logs', 'wp-nalda-sync' ),
            'manage_woocommerce',
            'wp-nalda-sync-logs',
            array( $this, 'render_logs_page' )
        );
    }

    /**
     * Register settings
     */
    public function register_settings() {
        register_setting(
            'wpns_settings_group',
            'wpns_settings',
            array( $this, 'sanitize_settings' )
        );

        // SFTP Settings Section
        add_settings_section(
            'wpns_sftp_section',
            __( 'SFTP Connection Settings', 'wp-nalda-sync' ),
            array( $this, 'render_sftp_section' ),
            'wp-nalda-sync'
        );

        add_settings_field(
            'sftp_host',
            __( 'SFTP Host', 'wp-nalda-sync' ),
            array( $this, 'render_text_field' ),
            'wp-nalda-sync',
            'wpns_sftp_section',
            array(
                'field'       => 'sftp_host',
                'placeholder' => 'sftp.example.com',
                'description' => __( 'The hostname or IP address of your SFTP server.', 'wp-nalda-sync' ),
            )
        );

        add_settings_field(
            'sftp_port',
            __( 'SFTP Port', 'wp-nalda-sync' ),
            array( $this, 'render_text_field' ),
            'wp-nalda-sync',
            'wpns_sftp_section',
            array(
                'field'       => 'sftp_port',
                'placeholder' => '22',
                'description' => __( 'Default is 22.', 'wp-nalda-sync' ),
                'type'        => 'number',
            )
        );

        add_settings_field(
            'sftp_username',
            __( 'Username', 'wp-nalda-sync' ),
            array( $this, 'render_text_field' ),
            'wp-nalda-sync',
            'wpns_sftp_section',
            array(
                'field'       => 'sftp_username',
                'placeholder' => __( 'Enter username', 'wp-nalda-sync' ),
            )
        );

        add_settings_field(
            'sftp_password',
            __( 'Password', 'wp-nalda-sync' ),
            array( $this, 'render_password_field' ),
            'wp-nalda-sync',
            'wpns_sftp_section',
            array(
                'field'       => 'sftp_password',
                'description' => __( 'Password is stored encrypted.', 'wp-nalda-sync' ),
            )
        );

        add_settings_field(
            'sftp_path',
            __( 'Remote Path', 'wp-nalda-sync' ),
            array( $this, 'render_text_field' ),
            'wp-nalda-sync',
            'wpns_sftp_section',
            array(
                'field'       => 'sftp_path',
                'placeholder' => '/uploads/',
                'description' => __( 'The directory path on the remote server where files will be uploaded.', 'wp-nalda-sync' ),
            )
        );

        // Export Settings Section
        add_settings_section(
            'wpns_export_section',
            __( 'Export Settings', 'wp-nalda-sync' ),
            array( $this, 'render_export_section' ),
            'wp-nalda-sync'
        );

        add_settings_field(
            'schedule',
            __( 'Sync Schedule', 'wp-nalda-sync' ),
            array( $this, 'render_schedule_field' ),
            'wp-nalda-sync',
            'wpns_export_section'
        );

        add_settings_field(
            'filename_pattern',
            __( 'Filename Pattern', 'wp-nalda-sync' ),
            array( $this, 'render_text_field' ),
            'wp-nalda-sync',
            'wpns_export_section',
            array(
                'field'       => 'filename_pattern',
                'placeholder' => 'products_{date}.csv',
                'description' => __( 'Available placeholders: {date}, {datetime}, {timestamp}', 'wp-nalda-sync' ),
            )
        );

        add_settings_field(
            'batch_size',
            __( 'Batch Size', 'wp-nalda-sync' ),
            array( $this, 'render_text_field' ),
            'wp-nalda-sync',
            'wpns_export_section',
            array(
                'field'       => 'batch_size',
                'placeholder' => '100',
                'type'        => 'number',
                'description' => __( 'Number of products to process per batch. Lower values use less memory.', 'wp-nalda-sync' ),
            )
        );

        // Product Settings Section
        add_settings_section(
            'wpns_product_section',
            __( 'Product Default Settings', 'wp-nalda-sync' ),
            array( $this, 'render_product_section' ),
            'wp-nalda-sync'
        );

        add_settings_field(
            'delivery_time',
            __( 'Default Delivery Time (days)', 'wp-nalda-sync' ),
            array( $this, 'render_text_field' ),
            'wp-nalda-sync',
            'wpns_product_section',
            array(
                'field'       => 'delivery_time',
                'placeholder' => '3',
                'type'        => 'number',
                'description' => __( 'Default delivery time in days if not set per product.', 'wp-nalda-sync' ),
            )
        );

        add_settings_field(
            'return_days',
            __( 'Return Period (days)', 'wp-nalda-sync' ),
            array( $this, 'render_text_field' ),
            'wp-nalda-sync',
            'wpns_product_section',
            array(
                'field'       => 'return_days',
                'placeholder' => '14',
                'type'        => 'number',
                'description' => __( 'Default return period in days.', 'wp-nalda-sync' ),
            )
        );

        // Enable/Disable Section
        add_settings_section(
            'wpns_enable_section',
            __( 'Sync Status', 'wp-nalda-sync' ),
            array( $this, 'render_enable_section' ),
            'wp-nalda-sync'
        );

        add_settings_field(
            'enabled',
            __( 'Enable Automatic Sync', 'wp-nalda-sync' ),
            array( $this, 'render_checkbox_field' ),
            'wp-nalda-sync',
            'wpns_enable_section',
            array(
                'field'       => 'enabled',
                'description' => __( 'Enable or disable automatic scheduled sync.', 'wp-nalda-sync' ),
            )
        );

        // Nalda API Settings Section
        add_settings_section(
            'wpns_nalda_api_section',
            __( 'Nalda API Settings', 'wp-nalda-sync' ),
            array( $this, 'render_nalda_api_section' ),
            'wp-nalda-sync'
        );

        add_settings_field(
            'nalda_api_key',
            __( 'Nalda API Key', 'wp-nalda-sync' ),
            array( $this, 'render_password_field' ),
            'wp-nalda-sync',
            'wpns_nalda_api_section',
            array(
                'field'       => 'nalda_api_key',
                'description' => __( 'API key obtained from the Nalda Seller Portal (Orders → Settings).', 'wp-nalda-sync' ),
            )
        );

        add_settings_field(
            'nalda_api_url',
            __( 'Nalda API URL', 'wp-nalda-sync' ),
            array( $this, 'render_text_field' ),
            'wp-nalda-sync',
            'wpns_nalda_api_section',
            array(
                'field'       => 'nalda_api_url',
                'placeholder' => 'https://api.nalda.com',
                'description' => __( 'Leave default unless instructed otherwise.', 'wp-nalda-sync' ),
            )
        );

        // Order Sync Settings Section
        add_settings_section(
            'wpns_order_sync_section',
            __( 'Order Sync Settings', 'wp-nalda-sync' ),
            array( $this, 'render_order_sync_section' ),
            'wp-nalda-sync'
        );

        add_settings_field(
            'order_sync_enabled',
            __( 'Enable Order Sync', 'wp-nalda-sync' ),
            array( $this, 'render_checkbox_field' ),
            'wp-nalda-sync',
            'wpns_order_sync_section',
            array(
                'field'       => 'order_sync_enabled',
                'description' => __( 'Enable automatic order import from Nalda Marketplace.', 'wp-nalda-sync' ),
            )
        );

        add_settings_field(
            'order_sync_schedule',
            __( 'Order Sync Schedule', 'wp-nalda-sync' ),
            array( $this, 'render_order_sync_schedule_field' ),
            'wp-nalda-sync',
            'wpns_order_sync_section'
        );

        add_settings_field(
            'order_sync_range',
            __( 'Order Sync Range', 'wp-nalda-sync' ),
            array( $this, 'render_order_sync_range_field' ),
            'wp-nalda-sync',
            'wpns_order_sync_section'
        );

        add_settings_field(
            'order_import_mode',
            __( 'Import Mode', 'wp-nalda-sync' ),
            array( $this, 'render_order_import_mode_field' ),
            'wp-nalda-sync',
            'wpns_order_sync_section'
        );
    }

    /**
     * Sanitize settings
     *
     * @param array $input Input settings.
     * @return array
     */
    public function sanitize_settings( $input ) {
        $sanitized = array();
        $old_settings = get_option( 'wpns_settings', array() );

        $sanitized['sftp_host']        = sanitize_text_field( $input['sftp_host'] ?? '' );
        $sanitized['sftp_port']        = absint( $input['sftp_port'] ?? 22 );
        $sanitized['sftp_username']    = sanitize_text_field( $input['sftp_username'] ?? '' );
        
        // Handle SFTP password - encrypt if changed
        if ( ! empty( $input['sftp_password'] ) ) {
            $sanitized['sftp_password'] = $this->encrypt_password( $input['sftp_password'] );
        } else {
            $sanitized['sftp_password'] = $old_settings['sftp_password'] ?? '';
        }

        $sanitized['sftp_path']        = sanitize_text_field( $input['sftp_path'] ?? '/' );
        $sanitized['schedule']         = sanitize_key( $input['schedule'] ?? 'daily' );
        
        // Handle custom interval minutes (1-43200 minutes = 1 min to 30 days)
        $custom_minutes = absint( $input['custom_interval_minutes'] ?? 60 );
        $sanitized['custom_interval_minutes'] = max( 1, min( 43200, $custom_minutes ) );
        
        $sanitized['filename_pattern'] = sanitize_file_name( $input['filename_pattern'] ?? 'products_{date}.csv' );
        $sanitized['batch_size']       = absint( $input['batch_size'] ?? 100 );
        $sanitized['delivery_time']    = absint( $input['delivery_time'] ?? 3 );
        $sanitized['return_days']      = absint( $input['return_days'] ?? 14 );
        $sanitized['enabled']          = ! empty( $input['enabled'] );

        // Nalda API settings
        if ( ! empty( $input['nalda_api_key'] ) ) {
            $sanitized['nalda_api_key'] = $this->encrypt_password( $input['nalda_api_key'] );
        } else {
            $sanitized['nalda_api_key'] = $old_settings['nalda_api_key'] ?? '';
        }
        $sanitized['nalda_api_url'] = esc_url_raw( $input['nalda_api_url'] ?? 'https://api.nalda.com' );

        // Order sync settings
        $sanitized['order_sync_enabled']  = ! empty( $input['order_sync_enabled'] );
        $sanitized['order_sync_schedule'] = sanitize_key( $input['order_sync_schedule'] ?? 'hourly' );
        $sanitized['order_sync_range']    = sanitize_key( $input['order_sync_range'] ?? 'today' );
        $sanitized['order_import_mode']   = sanitize_key( $input['order_import_mode'] ?? 'all' );

        // Update product sync cron schedule if changed
        $schedule_changed = $sanitized['enabled'] !== ( $old_settings['enabled'] ?? false ) ||
                           $sanitized['schedule'] !== ( $old_settings['schedule'] ?? 'daily' ) ||
                           ( $sanitized['schedule'] === 'wpns_custom' && 
                             $sanitized['custom_interval_minutes'] !== ( $old_settings['custom_interval_minutes'] ?? 60 ) );

        if ( $schedule_changed ) {
            $this->cron->reschedule( $sanitized['enabled'], $sanitized['schedule'] );
        }

        // Update order sync cron schedule if changed
        $order_sync_changed = $sanitized['order_sync_enabled'] !== ( $old_settings['order_sync_enabled'] ?? false ) ||
                              $sanitized['order_sync_schedule'] !== ( $old_settings['order_sync_schedule'] ?? 'hourly' );

        if ( $order_sync_changed ) {
            $this->cron->reschedule_order_sync( $sanitized['order_sync_enabled'], $sanitized['order_sync_schedule'] );
        }

        $this->logger->info( __( 'Settings updated', 'wp-nalda-sync' ) );

        return $sanitized;
    }

    /**
     * Encrypt password
     *
     * @param string $password Password to encrypt.
     * @return string
     */
    private function encrypt_password( $password ) {
        if ( empty( $password ) ) {
            return '';
        }

        $key = wp_salt( 'auth' );
        $iv  = substr( hash( 'sha256', wp_salt( 'secure_auth' ) ), 0, 16 );

        return base64_encode( openssl_encrypt( $password, 'AES-256-CBC', $key, 0, $iv ) );
    }

    /**
     * Decrypt password
     *
     * @param string $encrypted_password Encrypted password.
     * @return string
     */
    public static function decrypt_password( $encrypted_password ) {
        // Use global helper function
        return wpns_decrypt_password( $encrypted_password );
    }

    /**
     * Enqueue admin assets
     *
     * @param string $hook Current admin page hook.
     */
    public function enqueue_assets( $hook ) {
        if ( strpos( $hook, 'wp-nalda-sync' ) === false ) {
            return;
        }

        wp_enqueue_style(
            'wpns-admin',
            WPNS_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            WPNS_VERSION
        );

        wp_enqueue_script(
            'wpns-admin',
            WPNS_PLUGIN_URL . 'assets/js/admin.js',
            array( 'jquery' ),
            WPNS_VERSION,
            true
        );

        wp_localize_script( 'wpns-admin', 'wpns_admin', array(
            'ajax_url'          => admin_url( 'admin-ajax.php' ),
            'nonce'             => wp_create_nonce( 'wpns_admin_nonce' ),
            'strings'           => array(
                'testing'           => __( 'Testing connection...', 'wp-nalda-sync' ),
                'syncing'           => __( 'Running sync...', 'wp-nalda-sync' ),
                'success'           => __( 'Success!', 'wp-nalda-sync' ),
                'error'             => __( 'Error:', 'wp-nalda-sync' ),
                'confirm_clear'     => __( 'Are you sure you want to clear all logs?', 'wp-nalda-sync' ),
                'clearing'          => __( 'Clearing logs...', 'wp-nalda-sync' ),
                'cleared'           => __( 'Logs cleared successfully.', 'wp-nalda-sync' ),
                'loading'           => __( 'Loading...', 'wp-nalda-sync' ),
                'importing_orders'  => __( 'Importing orders from Nalda...', 'wp-nalda-sync' ),
                'testing_api'       => __( 'Testing Nalda API...', 'wp-nalda-sync' ),
            ),
        ) );
    }

    /**
     * Add settings link on plugins page
     *
     * @param array $links Plugin links.
     * @return array
     */
    public function add_settings_link( $links ) {
        $settings_link = sprintf(
            '<a href="%s">%s</a>',
            admin_url( 'admin.php?page=wp-nalda-sync' ),
            __( 'Settings', 'wp-nalda-sync' )
        );
        array_unshift( $links, $settings_link );
        return $links;
    }

    /**
     * Render settings page
     */
    public function render_settings_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        $settings     = get_option( 'wpns_settings', array() );
        $wc_settings  = $this->get_woocommerce_settings();
        $next_run     = wp_next_scheduled( 'wpns_sync_event' );
        $last_run     = get_option( 'wpns_last_run', array() );
        ?>
        <div class="wrap wpns-admin-wrap">
            <h1>
                <span class="dashicons dashicons-update"></span>
                <?php esc_html_e( 'WP Nalda Sync Settings', 'wp-nalda-sync' ); ?>
            </h1>

            <!-- Status Cards -->
            <div class="wpns-status-cards">
                <div class="wpns-card wpns-card-status">
                    <h3><?php esc_html_e( 'Sync Status', 'wp-nalda-sync' ); ?></h3>
                    <div class="wpns-status-indicator <?php echo ! empty( $settings['enabled'] ) ? 'active' : 'inactive'; ?>">
                        <span class="status-dot"></span>
                        <span class="status-text">
                            <?php echo ! empty( $settings['enabled'] ) 
                                ? esc_html__( 'Active', 'wp-nalda-sync' ) 
                                : esc_html__( 'Inactive', 'wp-nalda-sync' ); ?>
                        </span>
                    </div>
                    <?php if ( $next_run ) : ?>
                        <p class="next-run">
                            <strong><?php esc_html_e( 'Next run:', 'wp-nalda-sync' ); ?></strong><br>
                            <?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $next_run ) ); ?>
                        </p>
                    <?php endif; ?>
                </div>

                <div class="wpns-card wpns-card-last-run">
                    <h3><?php esc_html_e( 'Last Run', 'wp-nalda-sync' ); ?></h3>
                    <?php if ( ! empty( $last_run ) ) : ?>
                        <p>
                            <strong><?php esc_html_e( 'Time:', 'wp-nalda-sync' ); ?></strong>
                            <?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last_run['time'] ?? 0 ) ); ?>
                        </p>
                        <p>
                            <strong><?php esc_html_e( 'Status:', 'wp-nalda-sync' ); ?></strong>
                            <span class="wpns-badge <?php echo esc_attr( $last_run['status'] ?? 'unknown' ); ?>">
                                <?php echo esc_html( ucfirst( $last_run['status'] ?? __( 'Unknown', 'wp-nalda-sync' ) ) ); ?>
                            </span>
                        </p>
                        <p>
                            <strong><?php esc_html_e( 'Products:', 'wp-nalda-sync' ); ?></strong>
                            <?php echo esc_html( $last_run['products_exported'] ?? 0 ); ?>
                        </p>
                    <?php else : ?>
                        <p class="no-data"><?php esc_html_e( 'No sync has been run yet.', 'wp-nalda-sync' ); ?></p>
                    <?php endif; ?>
                </div>

                <div class="wpns-card wpns-card-wc-settings">
                    <h3><?php esc_html_e( 'WooCommerce Settings', 'wp-nalda-sync' ); ?></h3>
                    <p>
                        <strong><?php esc_html_e( 'Country:', 'wp-nalda-sync' ); ?></strong>
                        <?php echo esc_html( $wc_settings['country'] ); ?>
                    </p>
                    <p>
                        <strong><?php esc_html_e( 'Currency:', 'wp-nalda-sync' ); ?></strong>
                        <?php echo esc_html( $wc_settings['currency'] ); ?>
                    </p>
                    <p>
                        <strong><?php esc_html_e( 'Tax Rate:', 'wp-nalda-sync' ); ?></strong>
                        <?php echo esc_html( $wc_settings['tax_rate'] ); ?>%
                    </p>
                    <p class="description">
                        <?php esc_html_e( 'These values are automatically imported from WooCommerce settings.', 'wp-nalda-sync' ); ?>
                    </p>
                </div>

                <div class="wpns-card wpns-card-actions">
                    <h3><?php esc_html_e( 'Quick Actions', 'wp-nalda-sync' ); ?></h3>
                    <button type="button" id="wpns-test-connection" class="button button-secondary">
                        <span class="dashicons dashicons-admin-plugins"></span>
                        <?php esc_html_e( 'Test SFTP Connection', 'wp-nalda-sync' ); ?>
                    </button>
                    <button type="button" id="wpns-run-sync" class="button button-primary">
                        <span class="dashicons dashicons-update"></span>
                        <?php esc_html_e( 'Run Sync Now', 'wp-nalda-sync' ); ?>
                    </button>
                    <button type="button" id="wpns-download-csv" class="button button-secondary">
                        <span class="dashicons dashicons-download"></span>
                        <?php esc_html_e( 'Download CSV', 'wp-nalda-sync' ); ?>
                    </button>
                    <div id="wpns-action-result" class="wpns-action-result"></div>
                </div>
            </div>

            <!-- Settings Form -->
            <form method="post" action="options.php" class="wpns-settings-form">
                <?php
                settings_fields( 'wpns_settings_group' );
                do_settings_sections( 'wp-nalda-sync' );
                submit_button( __( 'Save Settings', 'wp-nalda-sync' ) );
                ?>
            </form>
        </div>
        <?php
    }

    /**
     * Render logs page
     */
    public function render_logs_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        // Pagination settings
        $per_page     = 10;
        $current_page = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
        $offset       = ( $current_page - 1 ) * $per_page;

        $sync_runs   = $this->logger->get_sync_runs( $per_page, $offset );
        $total_runs  = $this->logger->get_sync_runs_count();
        $total_pages = ceil( $total_runs / $per_page );
        $log_counts  = $this->logger->get_log_counts();
        ?>
        <div class="wrap wpns-admin-wrap">
            <h1>
                <span class="dashicons dashicons-list-view"></span>
                <?php esc_html_e( 'Sync Logs', 'wp-nalda-sync' ); ?>
            </h1>

            <div class="wpns-logs-header">
                <div class="wpns-logs-filters">
                    <div class="wpns-logs-stats">
                        <span class="wpns-logs-stat runs">
                            <span class="count"><?php echo esc_html( $log_counts['runs'] ?? 0 ); ?></span> 
                            <?php esc_html_e( 'Runs', 'wp-nalda-sync' ); ?>
                        </span>
                        <span class="wpns-logs-stat success">
                            <span class="count"><?php echo esc_html( $log_counts['success'] ?? 0 ); ?></span> 
                            <?php esc_html_e( 'Success', 'wp-nalda-sync' ); ?>
                        </span>
                        <span class="wpns-logs-stat warning">
                            <span class="count"><?php echo esc_html( $log_counts['warning'] ?? 0 ); ?></span> 
                            <?php esc_html_e( 'Warnings', 'wp-nalda-sync' ); ?>
                        </span>
                        <span class="wpns-logs-stat error">
                            <span class="count"><?php echo esc_html( $log_counts['error'] ?? 0 ); ?></span> 
                            <?php esc_html_e( 'Errors', 'wp-nalda-sync' ); ?>
                        </span>
                    </div>
                    <button type="button" id="wpns-refresh-logs" class="button">
                        <span class="dashicons dashicons-update"></span>
                        <?php esc_html_e( 'Refresh', 'wp-nalda-sync' ); ?>
                    </button>
                </div>
                <button type="button" id="wpns-clear-logs" class="button button-secondary">
                    <span class="dashicons dashicons-trash"></span>
                    <?php esc_html_e( 'Clear All Logs', 'wp-nalda-sync' ); ?>
                </button>
            </div>

            <div class="wpns-sync-runs-container">
                <?php if ( empty( $sync_runs ) ) : ?>
                    <div class="wpns-no-logs">
                        <span class="dashicons dashicons-info-outline"></span>
                        <p><?php esc_html_e( 'No sync runs found. Run a sync to see activity here.', 'wp-nalda-sync' ); ?></p>
                    </div>
                <?php else : ?>
                    <?php foreach ( $sync_runs as $run ) : 
                        $run_logs = $this->logger->get_logs_for_run( $run->run_id );
                        $status_class = $run->status;
                        $is_orphan = ( $run->run_id === '__orphan__' );
                        
                        if ( $is_orphan ) {
                            $trigger_label = __( 'System', 'wp-nalda-sync' );
                            $trigger_icon = 'info-outline';
                        } elseif ( 'manual' === $run->trigger ) {
                            $trigger_label = __( 'Manual', 'wp-nalda-sync' );
                            $trigger_icon = 'admin-users';
                        } elseif ( 'scheduled' === $run->trigger ) {
                            $trigger_label = __( 'Scheduled', 'wp-nalda-sync' );
                            $trigger_icon = 'clock';
                        } else {
                            $trigger_label = __( 'Unknown', 'wp-nalda-sync' );
                            $trigger_icon = 'editor-help';
                        }
                    ?>
                        <div class="wpns-sync-run <?php echo esc_attr( $status_class ); ?>" data-run-id="<?php echo esc_attr( $run->run_id ); ?>">
                            <div class="wpns-run-header">
                                <div class="wpns-run-status">
                                    <span class="wpns-status-icon <?php echo esc_attr( $status_class ); ?>">
                                        <?php if ( 'success' === $status_class ) : ?>
                                            <span class="dashicons dashicons-yes-alt"></span>
                                        <?php elseif ( 'failed' === $status_class ) : ?>
                                            <span class="dashicons dashicons-dismiss"></span>
                                        <?php elseif ( 'orphan' === $status_class ) : ?>
                                            <span class="dashicons dashicons-info-outline"></span>
                                        <?php else : ?>
                                            <span class="dashicons dashicons-update"></span>
                                        <?php endif; ?>
                                    </span>
                                </div>
                                <div class="wpns-run-info">
                                    <div class="wpns-run-title">
                                        <strong>
                                            <?php 
                                            if ( 'success' === $status_class ) {
                                                esc_html_e( 'Sync Completed Successfully', 'wp-nalda-sync' );
                                            } elseif ( 'failed' === $status_class ) {
                                                esc_html_e( 'Sync Failed', 'wp-nalda-sync' );
                                            } elseif ( 'orphan' === $status_class ) {
                                                esc_html_e( 'System Logs (Not Associated with a Run)', 'wp-nalda-sync' );
                                            } else {
                                                esc_html_e( 'Sync In Progress', 'wp-nalda-sync' );
                                            }
                                            ?>
                                        </strong>
                                    </div>
                                    <div class="wpns-run-meta">
                                        <span class="wpns-run-trigger">
                                            <span class="dashicons dashicons-<?php echo esc_attr( $trigger_icon ); ?>"></span>
                                            <?php echo esc_html( $trigger_label ); ?>
                                        </span>
                                        <span class="wpns-run-time">
                                            <span class="dashicons dashicons-calendar-alt"></span>
                                            <?php 
                                            // For orphan logs, show the latest log time; for runs, show the start time
                                            $display_time = $is_orphan ? $run->ended_at : $run->started_at;
                                            ?>
                                            <?php echo esc_html( wp_date( 'M j, Y', strtotime( $display_time ) ) ); ?>
                                            <?php esc_html_e( 'at', 'wp-nalda-sync' ); ?>
                                            <?php echo esc_html( wp_date( 'H:i:s', strtotime( $display_time ) ) ); ?>
                                        </span>
                                        <?php if ( ! $is_orphan ) : ?>
                                        <span class="wpns-run-duration">
                                            <span class="dashicons dashicons-backup"></span>
                                            <?php 
                                            if ( $run->duration < 60 ) {
                                                printf( esc_html__( '%d sec', 'wp-nalda-sync' ), $run->duration );
                                            } else {
                                                printf( esc_html__( '%d min %d sec', 'wp-nalda-sync' ), floor( $run->duration / 60 ), $run->duration % 60 );
                                            }
                                            ?>
                                        </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="wpns-run-summary">
                                    <?php if ( $run->final_stats ) : ?>
                                        <div class="wpns-run-stats">
                                            <?php if ( isset( $run->final_stats['products_exported'] ) ) : ?>
                                                <span class="wpns-stat-item exported">
                                                    <span class="dashicons dashicons-upload"></span>
                                                    <?php printf( esc_html__( '%d exported', 'wp-nalda-sync' ), $run->final_stats['products_exported'] ); ?>
                                                </span>
                                            <?php endif; ?>
                                            <?php if ( isset( $run->final_stats['products_skipped'] ) && $run->final_stats['products_skipped'] > 0 ) : ?>
                                                <span class="wpns-stat-item skipped">
                                                    <span class="dashicons dashicons-dismiss"></span>
                                                    <?php printf( esc_html__( '%d skipped', 'wp-nalda-sync' ), $run->final_stats['products_skipped'] ); ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                    <div class="wpns-run-badges">
                                        <?php if ( $run->error_count > 0 ) : ?>
                                            <span class="wpns-badge error"><?php echo esc_html( $run->error_count ); ?> <?php esc_html_e( 'errors', 'wp-nalda-sync' ); ?></span>
                                        <?php endif; ?>
                                        <?php if ( $run->warning_count > 0 ) : ?>
                                            <span class="wpns-badge warning"><?php echo esc_html( $run->warning_count ); ?> <?php esc_html_e( 'warnings', 'wp-nalda-sync' ); ?></span>
                                        <?php endif; ?>
                                        <span class="wpns-badge info"><?php echo esc_html( $run->log_count ); ?> <?php esc_html_e( 'logs', 'wp-nalda-sync' ); ?></span>
                                    </div>
                                </div>
                                <button type="button" class="wpns-run-toggle" aria-expanded="false">
                                    <span class="dashicons dashicons-arrow-down-alt2"></span>
                                </button>
                            </div>
                            <div class="wpns-run-details" style="display: none;">
                                <table class="wpns-logs-table">
                                    <thead>
                                        <tr>
                                            <th class="column-time"><?php esc_html_e( 'Time', 'wp-nalda-sync' ); ?></th>
                                            <th class="column-level"><?php esc_html_e( 'Level', 'wp-nalda-sync' ); ?></th>
                                            <th class="column-message"><?php esc_html_e( 'Message', 'wp-nalda-sync' ); ?></th>
                                            <th class="column-context"><?php esc_html_e( 'Details', 'wp-nalda-sync' ); ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ( $run_logs as $log ) : 
                                            $timestamp = strtotime( $log->timestamp );
                                        ?>
                                            <tr class="wpns-log-row" data-level="<?php echo esc_attr( $log->level ); ?>">
                                                <td class="column-time">
                                                    <span class="log-time"><?php echo esc_html( wp_date( 'H:i:s', $timestamp ) ); ?></span>
                                                </td>
                                                <td class="column-level">
                                                    <span class="wpns-badge <?php echo esc_attr( $log->level ); ?>">
                                                        <?php echo esc_html( ucfirst( $log->level ) ); ?>
                                                    </span>
                                                </td>
                                                <td class="column-message"><?php echo esc_html( $log->message ); ?></td>
                                                <td class="column-context">
                                                    <?php if ( ! empty( $log->context ) ) : ?>
                                                        <button type="button" class="button button-small wpns-view-context" 
                                                                data-context="<?php echo esc_attr( $log->context ); ?>">
                                                            <span class="dashicons dashicons-visibility"></span>
                                                            <?php esc_html_e( 'View', 'wp-nalda-sync' ); ?>
                                                        </button>
                                                    <?php else : ?>
                                                        <span class="wpns-text-muted">—</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <?php if ( $total_pages > 1 ) : ?>
                <div class="wpns-pagination">
                    <span class="wpns-pagination-info">
                        <?php
                        printf(
                            /* translators: 1: current page, 2: total pages, 3: total runs */
                            esc_html__( 'Page %1$d of %2$d (%3$d total runs)', 'wp-nalda-sync' ),
                            $current_page,
                            $total_pages,
                            $total_runs
                        );
                        ?>
                    </span>
                    <div class="wpns-pagination-links">
                        <?php
                        $base_url = admin_url( 'admin.php?page=wp-nalda-sync-logs' );
                        
                        // First page link
                        if ( $current_page > 1 ) : ?>
                            <a href="<?php echo esc_url( $base_url ); ?>" class="wpns-page-link first" title="<?php esc_attr_e( 'First page', 'wp-nalda-sync' ); ?>">
                                <span class="dashicons dashicons-controls-skipback"></span>
                            </a>
                        <?php endif;
                        
                        // Previous page link
                        if ( $current_page > 1 ) : ?>
                            <a href="<?php echo esc_url( add_query_arg( 'paged', $current_page - 1, $base_url ) ); ?>" class="wpns-page-link prev" title="<?php esc_attr_e( 'Previous page', 'wp-nalda-sync' ); ?>">
                                <span class="dashicons dashicons-arrow-left-alt2"></span>
                            </a>
                        <?php endif;
                        
                        // Page numbers
                        $start_page = max( 1, $current_page - 2 );
                        $end_page   = min( $total_pages, $current_page + 2 );
                        
                        if ( $start_page > 1 ) {
                            echo '<span class="wpns-page-ellipsis">...</span>';
                        }
                        
                        for ( $i = $start_page; $i <= $end_page; $i++ ) :
                            if ( $i === $current_page ) : ?>
                                <span class="wpns-page-link current"><?php echo esc_html( $i ); ?></span>
                            <?php else : ?>
                                <a href="<?php echo esc_url( add_query_arg( 'paged', $i, $base_url ) ); ?>" class="wpns-page-link">
                                    <?php echo esc_html( $i ); ?>
                                </a>
                            <?php endif;
                        endfor;
                        
                        if ( $end_page < $total_pages ) {
                            echo '<span class="wpns-page-ellipsis">...</span>';
                        }
                        
                        // Next page link
                        if ( $current_page < $total_pages ) : ?>
                            <a href="<?php echo esc_url( add_query_arg( 'paged', $current_page + 1, $base_url ) ); ?>" class="wpns-page-link next" title="<?php esc_attr_e( 'Next page', 'wp-nalda-sync' ); ?>">
                                <span class="dashicons dashicons-arrow-right-alt2"></span>
                            </a>
                        <?php endif;
                        
                        // Last page link
                        if ( $current_page < $total_pages ) : ?>
                            <a href="<?php echo esc_url( add_query_arg( 'paged', $total_pages, $base_url ) ); ?>" class="wpns-page-link last" title="<?php esc_attr_e( 'Last page', 'wp-nalda-sync' ); ?>">
                                <span class="dashicons dashicons-controls-skipforward"></span>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Context Modal -->
            <div id="wpns-context-modal" class="wpns-modal" style="display: none;">
                <div class="wpns-modal-content">
                    <div class="wpns-modal-header">
                        <h2><?php esc_html_e( 'Log Details', 'wp-nalda-sync' ); ?></h2>
                        <button type="button" class="wpns-modal-close">&times;</button>
                    </div>
                    <div class="wpns-modal-body">
                        <pre id="wpns-context-content"></pre>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Get WooCommerce settings
     *
     * @return array
     */
    private function get_woocommerce_settings() {
        $country_code = WC()->countries->get_base_country();
        $country_name = WC()->countries->get_countries()[ $country_code ] ?? $country_code;
        $currency     = get_woocommerce_currency();
        
        // Get tax rate
        $tax_rate = 0;
        $tax_rates = WC_Tax::get_base_tax_rates();
        if ( ! empty( $tax_rates ) ) {
            $first_rate = reset( $tax_rates );
            $tax_rate   = $first_rate['rate'] ?? 0;
        }

        return array(
            'country'      => $country_name,
            'country_code' => $country_code,
            'currency'     => $currency,
            'tax_rate'     => round( floatval( $tax_rate ), 2 ),
        );
    }

    /**
     * Render SFTP section description
     */
    public function render_sftp_section() {
        echo '<p>' . esc_html__( 'Configure your SFTP server connection settings. Password-based authentication is used.', 'wp-nalda-sync' ) . '</p>';
    }

    /**
     * Render export section description
     */
    public function render_export_section() {
        echo '<p>' . esc_html__( 'Configure how and when the CSV export should be generated.', 'wp-nalda-sync' ) . '</p>';
    }

    /**
     * Render product section description
     */
    public function render_product_section() {
        echo '<p>' . esc_html__( 'Set default values for product export fields.', 'wp-nalda-sync' ) . '</p>';
    }

    /**
     * Render enable section description
     */
    public function render_enable_section() {
        echo '<p>' . esc_html__( 'Enable or disable the automatic sync feature.', 'wp-nalda-sync' ) . '</p>';
    }

    /**
     * Render text field
     *
     * @param array $args Field arguments.
     */
    public function render_text_field( $args ) {
        $settings = get_option( 'wpns_settings', array() );
        $field    = $args['field'];
        $type     = $args['type'] ?? 'text';
        $value    = $settings[ $field ] ?? '';
        ?>
        <input type="<?php echo esc_attr( $type ); ?>"
               id="wpns_<?php echo esc_attr( $field ); ?>"
               name="wpns_settings[<?php echo esc_attr( $field ); ?>]"
               value="<?php echo esc_attr( $value ); ?>"
               class="regular-text"
               placeholder="<?php echo esc_attr( $args['placeholder'] ?? '' ); ?>">
        <?php if ( ! empty( $args['description'] ) ) : ?>
            <p class="description"><?php echo esc_html( $args['description'] ); ?></p>
        <?php endif; ?>
        <?php
    }

    /**
     * Render password field
     *
     * @param array $args Field arguments.
     */
    public function render_password_field( $args ) {
        $settings = get_option( 'wpns_settings', array() );
        $field    = $args['field'];
        $has_pass = ! empty( $settings[ $field ] );
        ?>
        <div class="wpns-password-field">
            <input type="password"
                   id="wpns_<?php echo esc_attr( $field ); ?>"
                   name="wpns_settings[<?php echo esc_attr( $field ); ?>]"
                   value=""
                   class="regular-text"
                   placeholder="<?php echo $has_pass ? esc_attr__( '••••••••', 'wp-nalda-sync' ) : esc_attr__( 'Enter password', 'wp-nalda-sync' ); ?>">
            <button type="button" class="button wpns-toggle-password" data-target="wpns_<?php echo esc_attr( $field ); ?>">
                <span class="dashicons dashicons-visibility"></span>
            </button>
        </div>
        <?php if ( $has_pass ) : ?>
            <p class="description"><?php esc_html_e( 'Leave empty to keep the current password.', 'wp-nalda-sync' ); ?></p>
        <?php endif; ?>
        <?php if ( ! empty( $args['description'] ) ) : ?>
            <p class="description"><?php echo esc_html( $args['description'] ); ?></p>
        <?php endif; ?>
        <?php
    }

    /**
     * Render checkbox field
     *
     * @param array $args Field arguments.
     */
    public function render_checkbox_field( $args ) {
        $settings = get_option( 'wpns_settings', array() );
        $field    = $args['field'];
        $checked  = ! empty( $settings[ $field ] );
        ?>
        <label class="wpns-toggle-switch">
            <input type="checkbox"
                   id="wpns_<?php echo esc_attr( $field ); ?>"
                   name="wpns_settings[<?php echo esc_attr( $field ); ?>]"
                   value="1"
                   <?php checked( $checked ); ?>>
            <span class="wpns-toggle-slider"></span>
        </label>
        <?php if ( ! empty( $args['description'] ) ) : ?>
            <p class="description"><?php echo esc_html( $args['description'] ); ?></p>
        <?php endif; ?>
        <?php
    }

    /**
     * Render schedule field
     */
    public function render_schedule_field() {
        $settings = get_option( 'wpns_settings', array() );
        $schedule = $settings['schedule'] ?? 'daily';
        $custom_minutes = $settings['custom_interval_minutes'] ?? 60;
        $schedules = array(
            'hourly'      => __( 'Hourly', 'wp-nalda-sync' ),
            'twicedaily'  => __( 'Twice Daily (12 hours)', 'wp-nalda-sync' ),
            'daily'       => __( 'Daily (24 hours)', 'wp-nalda-sync' ),
            'weekly'      => __( 'Weekly', 'wp-nalda-sync' ),
            'wpns_custom' => __( 'Custom Interval', 'wp-nalda-sync' ),
        );
        ?>
        <select id="wpns_schedule" name="wpns_settings[schedule]" class="regular-text">
            <?php foreach ( $schedules as $value => $label ) : ?>
                <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $schedule, $value ); ?>>
                    <?php echo esc_html( $label ); ?>
                </option>
            <?php endforeach; ?>
        </select>
        
        <div id="wpns_custom_interval_wrapper" style="margin-top: 10px; <?php echo $schedule !== 'wpns_custom' ? 'display:none;' : ''; ?>">
            <label for="wpns_custom_interval_minutes">
                <?php esc_html_e( 'Run every', 'wp-nalda-sync' ); ?>
                <input type="number" 
                       id="wpns_custom_interval_minutes" 
                       name="wpns_settings[custom_interval_minutes]" 
                       value="<?php echo esc_attr( $custom_minutes ); ?>" 
                       min="1" 
                       max="43200" 
                       style="width: 80px;" />
                <?php esc_html_e( 'minutes', 'wp-nalda-sync' ); ?>
            </label>
            <p class="description" style="margin-top: 5px;">
                <?php esc_html_e( 'Examples: 30 = every 30 min, 60 = hourly, 1440 = daily', 'wp-nalda-sync' ); ?>
            </p>
        </div>
        
        <p class="description"><?php esc_html_e( 'How often should the sync run automatically.', 'wp-nalda-sync' ); ?></p>
        
        <script>
        jQuery(document).ready(function($) {
            $('#wpns_schedule').on('change', function() {
                if ($(this).val() === 'wpns_custom') {
                    $('#wpns_custom_interval_wrapper').show();
                } else {
                    $('#wpns_custom_interval_wrapper').hide();
                }
            });
        });
        </script>
        <?php
    }

    /**
     * AJAX: Test SFTP connection
     */
    public function ajax_test_connection() {
        check_ajax_referer( 'wpns_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( __( 'Permission denied.', 'wp-nalda-sync' ) );
        }

        $result = $this->sftp_uploader->test_connection();

        if ( $result['success'] ) {
            $this->logger->success( __( 'SFTP connection test successful', 'wp-nalda-sync' ) );
            wp_send_json_success( $result['message'] );
        } else {
            $this->logger->error( __( 'SFTP connection test failed', 'wp-nalda-sync' ), array( 'error' => $result['message'] ) );
            wp_send_json_error( $result['message'] );
        }
    }

    /**
     * AJAX: Run sync manually
     */
    public function ajax_run_sync() {
        check_ajax_referer( 'wpns_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( __( 'Permission denied.', 'wp-nalda-sync' ) );
        }

        $result = $this->cron->run_sync();

        if ( $result['success'] ) {
            wp_send_json_success( $result['message'] );
        } else {
            wp_send_json_error( $result['message'] );
        }
    }

    /**
     * AJAX: Clear logs
     */
    public function ajax_clear_logs() {
        check_ajax_referer( 'wpns_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( __( 'Permission denied.', 'wp-nalda-sync' ) );
        }

        $this->logger->clear_logs();
        wp_send_json_success( __( 'Logs cleared successfully.', 'wp-nalda-sync' ) );
    }

    /**
     * AJAX: Get logs
     */
    public function ajax_get_logs() {
        check_ajax_referer( 'wpns_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( __( 'Permission denied.', 'wp-nalda-sync' ) );
        }

        $level = sanitize_key( $_POST['level'] ?? '' );
        $logs  = $this->logger->get_logs( 100, $level );

        ob_start();
        if ( empty( $logs ) ) {
            echo '<tr class="no-items"><td colspan="4">' . esc_html__( 'No logs found. Run a sync to see activity here.', 'wp-nalda-sync' ) . '</td></tr>';
        } else {
            foreach ( $logs as $log ) {
                $timestamp = strtotime( $log->timestamp );
                ?>
                <tr class="wpns-log-row" data-level="<?php echo esc_attr( $log->level ); ?>">
                    <td class="column-time">
                        <span class="log-date"><?php echo esc_html( wp_date( 'M j, Y', $timestamp ) ); ?></span>
                        <span class="log-time"><?php echo esc_html( wp_date( 'H:i:s', $timestamp ) ); ?></span>
                    </td>
                    <td class="column-level">
                        <span class="wpns-badge <?php echo esc_attr( $log->level ); ?>">
                            <?php echo esc_html( ucfirst( $log->level ) ); ?>
                        </span>
                    </td>
                    <td class="column-message"><?php echo esc_html( $log->message ); ?></td>
                    <td class="column-context">
                        <?php if ( ! empty( $log->context ) ) : ?>
                            <button type="button" class="button button-small wpns-view-context" 
                                    data-context="<?php echo esc_attr( $log->context ); ?>">
                                <span class="dashicons dashicons-visibility"></span>
                                <?php esc_html_e( 'View', 'wp-nalda-sync' ); ?>
                            </button>
                        <?php else : ?>
                            <span class="wpns-text-muted">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php
            }
        }
        $html = ob_get_clean();

        wp_send_json_success( $html );
    }

    /**
     * AJAX: Download CSV preview
     */
    public function ajax_download_csv() {
        check_ajax_referer( 'wpns_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( __( 'Permission denied.', 'wp-nalda-sync' ) );
        }

        // Use generate_for_download which saves to a web-accessible location
        $result = $this->csv_generator->generate_for_download();

        if ( $result['success'] ) {
            wp_send_json_success( array(
                'message'  => $result['message'],
                'file_url' => $result['file_url'],
            ) );
        } else {
            wp_send_json_error( $result['message'] );
        }
    }

    /**
     * AJAX: Test Nalda API connection
     */
    public function ajax_test_nalda_api() {
        check_ajax_referer( 'wpns_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( __( 'Permission denied.', 'wp-nalda-sync' ) );
        }

        if ( ! $this->nalda_api ) {
            wp_send_json_error( __( 'Nalda API not initialized.', 'wp-nalda-sync' ) );
        }

        $result = $this->nalda_api->test_connection();

        if ( $result['success'] ) {
            $this->logger->success( __( 'Nalda API connection test successful', 'wp-nalda-sync' ) );
            wp_send_json_success( $result['message'] );
        } else {
            $this->logger->error( __( 'Nalda API connection test failed', 'wp-nalda-sync' ), array( 'error' => $result['message'] ) );
            wp_send_json_error( $result['message'] );
        }
    }

    /**
     * AJAX: Run order sync manually
     */
    public function ajax_run_order_sync() {
        check_ajax_referer( 'wpns_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( __( 'Permission denied.', 'wp-nalda-sync' ) );
        }

        $result = $this->cron->run_order_sync( 'manual' );

        if ( $result['success'] ) {
            wp_send_json_success( array(
                'message' => $result['message'],
                'stats'   => $result['stats'] ?? array(),
            ) );
        } else {
            wp_send_json_error( $result['message'] );
        }
    }

    /**
     * Render Nalda API section description
     */
    public function render_nalda_api_section() {
        echo '<p>' . esc_html__( 'Configure your Nalda Marketplace API settings. Get your API key from the Nalda Seller Portal (Orders → Settings).', 'wp-nalda-sync' ) . '</p>';
    }

    /**
     * Render order sync section description
     */
    public function render_order_sync_section() {
        echo '<p>' . esc_html__( 'Configure how orders are imported from Nalda Marketplace to your WooCommerce store.', 'wp-nalda-sync' ) . '</p>';
    }

    /**
     * Render order sync schedule field
     */
    public function render_order_sync_schedule_field() {
        $settings = get_option( 'wpns_settings', array() );
        $schedule = $settings['order_sync_schedule'] ?? 'hourly';
        $schedules = array(
            'hourly'      => __( 'Hourly', 'wp-nalda-sync' ),
            'twicedaily'  => __( 'Twice Daily', 'wp-nalda-sync' ),
            'daily'       => __( 'Daily', 'wp-nalda-sync' ),
        );
        ?>
        <select id="wpns_order_sync_schedule" name="wpns_settings[order_sync_schedule]" class="regular-text">
            <?php foreach ( $schedules as $value => $label ) : ?>
                <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $schedule, $value ); ?>>
                    <?php echo esc_html( $label ); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <p class="description"><?php esc_html_e( 'How often to check Nalda for new orders.', 'wp-nalda-sync' ); ?></p>
        <?php
    }

    /**
     * Render order sync range field
     */
    public function render_order_sync_range_field() {
        $settings = get_option( 'wpns_settings', array() );
        $range = $settings['order_sync_range'] ?? 'today';
        $ranges = WPNS_Nalda_API::get_date_range_options();
        ?>
        <select id="wpns_order_sync_range" name="wpns_settings[order_sync_range]" class="regular-text">
            <?php foreach ( $ranges as $value => $label ) : ?>
                <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $range, $value ); ?>>
                    <?php echo esc_html( $label ); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <p class="description"><?php esc_html_e( 'Date range for fetching orders from Nalda.', 'wp-nalda-sync' ); ?></p>
        <?php
    }

    /**
     * Render order import mode field
     */
    public function render_order_import_mode_field() {
        $settings = get_option( 'wpns_settings', array() );
        $mode = $settings['order_import_mode'] ?? 'all';
        $modes = array(
            'all'       => __( 'Import all orders (create new + update existing)', 'wp-nalda-sync' ),
            'sync_only' => __( 'Sync only (update existing orders only)', 'wp-nalda-sync' ),
        );
        ?>
        <select id="wpns_order_import_mode" name="wpns_settings[order_import_mode]" class="regular-text">
            <?php foreach ( $modes as $value => $label ) : ?>
                <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $mode, $value ); ?>>
                    <?php echo esc_html( $label ); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <p class="description"><?php esc_html_e( 'Choose whether to create new orders or only update existing ones.', 'wp-nalda-sync' ); ?></p>
        <?php
    }

    /**
     * Render orders page
     */
    public function render_orders_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        $settings        = get_option( 'wpns_settings', array() );
        $order_sync_stats = $this->cron->get_order_sync_stats();
        $last_import     = $this->order_importer ? $this->order_importer->get_last_import_stats() : array();
        $imported_count  = $this->order_importer ? $this->order_importer->count_imported_orders() : 0;
        $next_sync       = $this->cron->get_next_order_sync();
        ?>
        <div class="wrap wpns-admin-wrap">
            <h1>
                <span class="dashicons dashicons-cart"></span>
                <?php esc_html_e( 'Nalda Order Sync', 'wp-nalda-sync' ); ?>
            </h1>

            <!-- Status Cards -->
            <div class="wpns-status-cards">
                <div class="wpns-card wpns-card-status">
                    <h3><?php esc_html_e( 'Order Sync Status', 'wp-nalda-sync' ); ?></h3>
                    <div class="wpns-status-indicator <?php echo ! empty( $settings['order_sync_enabled'] ) ? 'active' : 'inactive'; ?>">
                        <span class="status-dot"></span>
                        <span class="status-text">
                            <?php echo ! empty( $settings['order_sync_enabled'] ) 
                                ? esc_html__( 'Active', 'wp-nalda-sync' ) 
                                : esc_html__( 'Inactive', 'wp-nalda-sync' ); ?>
                        </span>
                    </div>
                    <?php if ( $next_sync ) : ?>
                        <p class="next-run">
                            <strong><?php esc_html_e( 'Next sync:', 'wp-nalda-sync' ); ?></strong><br>
                            <?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $next_sync ) ); ?>
                        </p>
                    <?php endif; ?>
                    <p>
                        <strong><?php esc_html_e( 'Schedule:', 'wp-nalda-sync' ); ?></strong>
                        <?php echo esc_html( WPNS_Cron::get_schedule_display_name( $settings['order_sync_schedule'] ?? 'hourly' ) ); ?>
                    </p>
                </div>

                <div class="wpns-card wpns-card-last-run">
                    <h3><?php esc_html_e( 'Last Import', 'wp-nalda-sync' ); ?></h3>
                    <?php if ( ! empty( $last_import ) ) : ?>
                        <p>
                            <strong><?php esc_html_e( 'Time:', 'wp-nalda-sync' ); ?></strong>
                            <?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $last_import['timestamp'] ?? '' ) ) ); ?>
                        </p>
                        <?php if ( isset( $last_import['fetched'] ) ) : ?>
                            <p>
                                <strong><?php esc_html_e( 'Fetched:', 'wp-nalda-sync' ); ?></strong>
                                <?php echo esc_html( $last_import['fetched'] ); ?>
                            </p>
                        <?php endif; ?>
                        <?php if ( isset( $last_import['imported'] ) ) : ?>
                            <p>
                                <strong><?php esc_html_e( 'Imported:', 'wp-nalda-sync' ); ?></strong>
                                <?php echo esc_html( $last_import['imported'] ); ?>
                            </p>
                        <?php endif; ?>
                        <?php if ( isset( $last_import['updated'] ) ) : ?>
                            <p>
                                <strong><?php esc_html_e( 'Updated:', 'wp-nalda-sync' ); ?></strong>
                                <?php echo esc_html( $last_import['updated'] ); ?>
                            </p>
                        <?php endif; ?>
                    <?php else : ?>
                        <p class="no-data"><?php esc_html_e( 'No import has been run yet.', 'wp-nalda-sync' ); ?></p>
                    <?php endif; ?>
                </div>

                <div class="wpns-card wpns-card-stats">
                    <h3><?php esc_html_e( 'Statistics', 'wp-nalda-sync' ); ?></h3>
                    <p>
                        <strong><?php esc_html_e( 'Total Nalda Orders:', 'wp-nalda-sync' ); ?></strong>
                        <?php echo esc_html( $imported_count ); ?>
                    </p>
                    <p>
                        <strong><?php esc_html_e( 'Import Range:', 'wp-nalda-sync' ); ?></strong>
                        <?php 
                        $ranges = WPNS_Nalda_API::get_date_range_options();
                        echo esc_html( $ranges[ $settings['order_sync_range'] ?? 'today' ] ?? $settings['order_sync_range'] ?? 'Today' ); 
                        ?>
                    </p>
                    <p>
                        <strong><?php esc_html_e( 'Import Mode:', 'wp-nalda-sync' ); ?></strong>
                        <?php echo ( $settings['order_import_mode'] ?? 'all' ) === 'all' 
                            ? esc_html__( 'Import All', 'wp-nalda-sync' ) 
                            : esc_html__( 'Sync Only', 'wp-nalda-sync' ); ?>
                    </p>
                </div>

                <div class="wpns-card wpns-card-actions">
                    <h3><?php esc_html_e( 'Quick Actions', 'wp-nalda-sync' ); ?></h3>
                    <button type="button" id="wpns-test-nalda-api" class="button button-secondary">
                        <span class="dashicons dashicons-admin-plugins"></span>
                        <?php esc_html_e( 'Test API Connection', 'wp-nalda-sync' ); ?>
                    </button>
                    <button type="button" id="wpns-run-order-sync" class="button button-primary">
                        <span class="dashicons dashicons-download"></span>
                        <?php esc_html_e( 'Import Orders Now', 'wp-nalda-sync' ); ?>
                    </button>
                    <a href="<?php echo esc_url( admin_url( 'edit.php?post_type=shop_order&meta_key=_wpns_nalda_order_id&meta_compare=EXISTS' ) ); ?>" class="button button-secondary">
                        <span class="dashicons dashicons-visibility"></span>
                        <?php esc_html_e( 'View Nalda Orders', 'wp-nalda-sync' ); ?>
                    </a>
                    <div id="wpns-order-action-result" class="wpns-action-result"></div>
                </div>
            </div>

            <!-- API Configuration Info -->
            <div class="wpns-info-box">
                <h3><?php esc_html_e( 'How to Get Your Nalda API Key', 'wp-nalda-sync' ); ?></h3>
                <ol>
                    <li><?php esc_html_e( 'Visit the Nalda Seller Portal at', 'wp-nalda-sync' ); ?> <a href="https://sellers.nalda.com/" target="_blank">sellers.nalda.com</a></li>
                    <li><?php esc_html_e( 'Navigate to Orders/Bestellungen', 'wp-nalda-sync' ); ?></li>
                    <li><?php esc_html_e( 'Click the Settings icon in the top right corner', 'wp-nalda-sync' ); ?></li>
                    <li><?php esc_html_e( 'Generate or copy your API key', 'wp-nalda-sync' ); ?></li>
                </ol>
                <p>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-nalda-sync#wpns_nalda_api_section' ) ); ?>" class="button">
                        <?php esc_html_e( 'Configure API Settings', 'wp-nalda-sync' ); ?>
                    </a>
                </p>
            </div>

            <!-- Recent Imported Orders -->
            <?php if ( $this->order_importer && $imported_count > 0 ) : 
                $recent_orders = $this->order_importer->get_imported_orders( 10, 0 );
            ?>
            <div class="wpns-orders-list">
                <h3><?php esc_html_e( 'Recent Nalda Orders', 'wp-nalda-sync' ); ?></h3>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'WC Order', 'wp-nalda-sync' ); ?></th>
                            <th><?php esc_html_e( 'Nalda Order ID', 'wp-nalda-sync' ); ?></th>
                            <th><?php esc_html_e( 'Customer', 'wp-nalda-sync' ); ?></th>
                            <th><?php esc_html_e( 'Status', 'wp-nalda-sync' ); ?></th>
                            <th><?php esc_html_e( 'Delivery Status', 'wp-nalda-sync' ); ?></th>
                            <th><?php esc_html_e( 'Payout Status', 'wp-nalda-sync' ); ?></th>
                            <th><?php esc_html_e( 'Total', 'wp-nalda-sync' ); ?></th>
                            <th><?php esc_html_e( 'Synced At', 'wp-nalda-sync' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $recent_orders as $order ) : 
                            $nalda_order_id = $order->get_meta( WPNS_Order_Importer::META_NALDA_ORDER_ID );
                            $delivery_status = $order->get_meta( WPNS_Order_Importer::META_NALDA_DELIVERY_STATUS );
                            $payout_status = $order->get_meta( WPNS_Order_Importer::META_NALDA_PAYOUT_STATUS );
                            $synced_at = $order->get_meta( WPNS_Order_Importer::META_NALDA_SYNCED_AT );
                        ?>
                            <tr>
                                <td>
                                    <a href="<?php echo esc_url( $order->get_edit_order_url() ); ?>">
                                        #<?php echo esc_html( $order->get_id() ); ?>
                                    </a>
                                </td>
                                <td><?php echo esc_html( $nalda_order_id ); ?></td>
                                <td><?php echo esc_html( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ); ?></td>
                                <td>
                                    <span class="order-status status-<?php echo esc_attr( $order->get_status() ); ?>">
                                        <?php echo esc_html( wc_get_order_status_name( $order->get_status() ) ); ?>
                                    </span>
                                </td>
                                <td><?php echo esc_html( $delivery_status ); ?></td>
                                <td><?php echo esc_html( $payout_status ); ?></td>
                                <td><?php echo wp_kses_post( $order->get_formatted_order_total() ); ?></td>
                                <td><?php echo esc_html( $synced_at ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $synced_at ) ) : '-' ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php if ( $imported_count > 10 ) : ?>
                    <p>
                        <a href="<?php echo esc_url( admin_url( 'edit.php?post_type=shop_order&meta_key=_wpns_nalda_order_id&meta_compare=EXISTS' ) ); ?>">
                            <?php printf( esc_html__( 'View all %d Nalda orders →', 'wp-nalda-sync' ), $imported_count ); ?>
                        </a>
                    </p>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }
}
