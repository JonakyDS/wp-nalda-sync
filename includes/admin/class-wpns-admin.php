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
     * Constructor
     *
     * @param WPNS_Logger        $logger        Logger instance.
     * @param WPNS_CSV_Generator $csv_generator CSV Generator instance.
     * @param WPNS_SFTP_Uploader $sftp_uploader SFTP Uploader instance.
     * @param WPNS_Cron          $cron          Cron instance.
     */
    public function __construct( $logger, $csv_generator, $sftp_uploader, $cron ) {
        $this->logger        = $logger;
        $this->csv_generator = $csv_generator;
        $this->sftp_uploader = $sftp_uploader;
        $this->cron          = $cron;

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
        
        // Handle password - encrypt if changed
        if ( ! empty( $input['sftp_password'] ) ) {
            $sanitized['sftp_password'] = $this->encrypt_password( $input['sftp_password'] );
        } else {
            $sanitized['sftp_password'] = $old_settings['sftp_password'] ?? '';
        }

        $sanitized['sftp_path']        = sanitize_text_field( $input['sftp_path'] ?? '/' );
        $sanitized['schedule']         = sanitize_key( $input['schedule'] ?? 'daily' );
        $sanitized['filename_pattern'] = sanitize_file_name( $input['filename_pattern'] ?? 'products_{date}.csv' );
        $sanitized['batch_size']       = absint( $input['batch_size'] ?? 100 );
        $sanitized['delivery_time']    = absint( $input['delivery_time'] ?? 3 );
        $sanitized['return_days']      = absint( $input['return_days'] ?? 14 );
        $sanitized['enabled']          = ! empty( $input['enabled'] );

        // Update cron schedule if changed
        if ( $sanitized['enabled'] !== ( $old_settings['enabled'] ?? false ) ||
             $sanitized['schedule'] !== ( $old_settings['schedule'] ?? 'daily' ) ) {
            $this->cron->reschedule( $sanitized['enabled'], $sanitized['schedule'] );
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
        if ( empty( $encrypted_password ) ) {
            return '';
        }

        $key = wp_salt( 'auth' );
        $iv  = substr( hash( 'sha256', wp_salt( 'secure_auth' ) ), 0, 16 );

        return openssl_decrypt( base64_decode( $encrypted_password ), 'AES-256-CBC', $key, 0, $iv );
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
                'testing'       => __( 'Testing connection...', 'wp-nalda-sync' ),
                'syncing'       => __( 'Running sync...', 'wp-nalda-sync' ),
                'success'       => __( 'Success!', 'wp-nalda-sync' ),
                'error'         => __( 'Error:', 'wp-nalda-sync' ),
                'confirm_clear' => __( 'Are you sure you want to clear all logs?', 'wp-nalda-sync' ),
                'clearing'      => __( 'Clearing logs...', 'wp-nalda-sync' ),
                'cleared'       => __( 'Logs cleared successfully.', 'wp-nalda-sync' ),
                'loading'       => __( 'Loading...', 'wp-nalda-sync' ),
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

        $logs = $this->logger->get_logs( 100 );
        ?>
        <div class="wrap wpns-admin-wrap">
            <h1>
                <span class="dashicons dashicons-list-view"></span>
                <?php esc_html_e( 'Sync Logs', 'wp-nalda-sync' ); ?>
            </h1>

            <div class="wpns-logs-header">
                <div class="wpns-logs-filters">
                    <select id="wpns-log-filter" class="wpns-log-filter">
                        <option value=""><?php esc_html_e( 'All Levels', 'wp-nalda-sync' ); ?></option>
                        <option value="info"><?php esc_html_e( 'Info', 'wp-nalda-sync' ); ?></option>
                        <option value="success"><?php esc_html_e( 'Success', 'wp-nalda-sync' ); ?></option>
                        <option value="warning"><?php esc_html_e( 'Warning', 'wp-nalda-sync' ); ?></option>
                        <option value="error"><?php esc_html_e( 'Error', 'wp-nalda-sync' ); ?></option>
                    </select>
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

            <div class="wpns-logs-container">
                <table class="wp-list-table widefat fixed striped wpns-logs-table">
                    <thead>
                        <tr>
                            <th class="column-time"><?php esc_html_e( 'Time', 'wp-nalda-sync' ); ?></th>
                            <th class="column-level"><?php esc_html_e( 'Level', 'wp-nalda-sync' ); ?></th>
                            <th class="column-message"><?php esc_html_e( 'Message', 'wp-nalda-sync' ); ?></th>
                            <th class="column-context"><?php esc_html_e( 'Context', 'wp-nalda-sync' ); ?></th>
                        </tr>
                    </thead>
                    <tbody id="wpns-logs-body">
                        <?php if ( empty( $logs ) ) : ?>
                            <tr class="no-items">
                                <td colspan="4"><?php esc_html_e( 'No logs found.', 'wp-nalda-sync' ); ?></td>
                            </tr>
                        <?php else : ?>
                            <?php foreach ( $logs as $log ) : ?>
                                <tr class="wpns-log-row" data-level="<?php echo esc_attr( $log->level ); ?>">
                                    <td class="column-time">
                                        <?php echo esc_html( wp_date( 'Y-m-d H:i:s', strtotime( $log->timestamp ) ) ); ?>
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
                                                <?php esc_html_e( 'View', 'wp-nalda-sync' ); ?>
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Context Modal -->
            <div id="wpns-context-modal" class="wpns-modal" style="display: none;">
                <div class="wpns-modal-content">
                    <div class="wpns-modal-header">
                        <h2><?php esc_html_e( 'Log Context', 'wp-nalda-sync' ); ?></h2>
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
        $schedules = array(
            'hourly'     => __( 'Hourly', 'wp-nalda-sync' ),
            'twicedaily' => __( 'Twice Daily', 'wp-nalda-sync' ),
            'daily'      => __( 'Daily', 'wp-nalda-sync' ),
            'weekly'     => __( 'Weekly', 'wp-nalda-sync' ),
        );
        ?>
        <select id="wpns_schedule" name="wpns_settings[schedule]" class="regular-text">
            <?php foreach ( $schedules as $value => $label ) : ?>
                <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $schedule, $value ); ?>>
                    <?php echo esc_html( $label ); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <p class="description"><?php esc_html_e( 'How often should the sync run automatically.', 'wp-nalda-sync' ); ?></p>
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
            echo '<tr class="no-items"><td colspan="4">' . esc_html__( 'No logs found.', 'wp-nalda-sync' ) . '</td></tr>';
        } else {
            foreach ( $logs as $log ) {
                ?>
                <tr class="wpns-log-row" data-level="<?php echo esc_attr( $log->level ); ?>">
                    <td class="column-time">
                        <?php echo esc_html( wp_date( 'Y-m-d H:i:s', strtotime( $log->timestamp ) ) ); ?>
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
                                <?php esc_html_e( 'View', 'wp-nalda-sync' ); ?>
                            </button>
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

        $result = $this->csv_generator->generate();

        if ( $result['success'] ) {
            wp_send_json_success( array(
                'message'  => $result['message'],
                'file_url' => $result['file_url'],
            ) );
        } else {
            wp_send_json_error( $result['message'] );
        }
    }
}
