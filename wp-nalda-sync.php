<?php
/**
 * Plugin Name: WP Nalda Sync
 * Plugin URI: https://github.com/JonakyDS/wp-nalda-sync
 * Description: Automatically generates product CSV feeds from WooCommerce and uploads them to SFTP servers.
 * Version: 1.0.1
 * Author: Jonaky Adhikary
 * Author URI: https://jonakyds.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wp-nalda-sync
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 8.0
 *
 * @package WP_Nalda_Sync
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Plugin constants
define( 'WPNS_VERSION', '1.0.1' );
define( 'WPNS_PLUGIN_FILE', __FILE__ );
define( 'WPNS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPNS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WPNS_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Decrypt password - global helper function available in all contexts
 *
 * @param string $encrypted_password Encrypted password.
 * @return string Decrypted password.
 */
function wpns_decrypt_password( $encrypted_password ) {
    if ( empty( $encrypted_password ) ) {
        return '';
    }

    $key = wp_salt( 'auth' );
    $iv  = substr( hash( 'sha256', wp_salt( 'secure_auth' ) ), 0, 16 );

    return openssl_decrypt( base64_decode( $encrypted_password ), 'AES-256-CBC', $key, 0, $iv );
}

/**
 * Main plugin class
 */
final class WP_Nalda_Sync {

    /**
     * Single instance of the class
     *
     * @var WP_Nalda_Sync
     */
    private static $instance = null;

    /**
     * Plugin components
     */
    public $admin;
    public $csv_generator;
    public $sftp_uploader;
    public $logger;
    public $cron;

    /**
     * Get single instance of the class
     *
     * @return WP_Nalda_Sync
     */
    public static function instance() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        $this->check_requirements();
        $this->includes();
        $this->init_hooks();
    }

    /**
     * Check plugin requirements
     */
    private function check_requirements() {
        // Check if WooCommerce is active
        add_action( 'admin_init', array( $this, 'check_woocommerce' ) );
    }

    /**
     * Check if WooCommerce is active
     */
    public function check_woocommerce() {
        if ( ! class_exists( 'WooCommerce' ) ) {
            add_action( 'admin_notices', array( $this, 'woocommerce_missing_notice' ) );
            deactivate_plugins( WPNS_PLUGIN_BASENAME );
            return;
        }
    }

    /**
     * WooCommerce missing notice
     */
    public function woocommerce_missing_notice() {
        ?>
        <div class="notice notice-error">
            <p>
                <strong><?php esc_html_e( 'WP Nalda Sync', 'wp-nalda-sync' ); ?></strong>
                <?php esc_html_e( 'requires WooCommerce to be installed and activated.', 'wp-nalda-sync' ); ?>
            </p>
        </div>
        <?php
    }

    /**
     * Include required files
     */
    private function includes() {
        // Core classes
        require_once WPNS_PLUGIN_DIR . 'includes/class-wpns-logger.php';
        require_once WPNS_PLUGIN_DIR . 'includes/class-wpns-csv-generator.php';
        require_once WPNS_PLUGIN_DIR . 'includes/class-wpns-sftp-uploader.php';
        require_once WPNS_PLUGIN_DIR . 'includes/class-wpns-cron.php';

        // Admin classes
        if ( is_admin() ) {
            require_once WPNS_PLUGIN_DIR . 'includes/admin/class-wpns-admin.php';
        }
    }

    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // Initialize components after plugins loaded
        add_action( 'plugins_loaded', array( $this, 'init_components' ), 20 );

        // Activation/Deactivation hooks
        register_activation_hook( WPNS_PLUGIN_FILE, array( $this, 'activate' ) );
        register_deactivation_hook( WPNS_PLUGIN_FILE, array( $this, 'deactivate' ) );

        // Load textdomain
        add_action( 'init', array( $this, 'load_textdomain' ) );

        // Declare HPOS compatibility
        add_action( 'before_woocommerce_init', array( $this, 'declare_hpos_compatibility' ) );
    }

    /**
     * Initialize plugin components
     */
    public function init_components() {
        if ( ! class_exists( 'WooCommerce' ) ) {
            return;
        }

        $this->logger        = new WPNS_Logger();
        $this->csv_generator = new WPNS_CSV_Generator( $this->logger );
        $this->sftp_uploader = new WPNS_SFTP_Uploader( $this->logger );
        $this->cron          = new WPNS_Cron( $this->csv_generator, $this->sftp_uploader, $this->logger );

        if ( is_admin() ) {
            $this->admin = new WPNS_Admin( $this->logger, $this->csv_generator, $this->sftp_uploader, $this->cron );
        }
    }

    /**
     * Plugin activation
     */
    public function activate() {
        // Set default options
        $default_options = array(
            'sftp_host'        => '',
            'sftp_port'        => '22',
            'sftp_username'    => '',
            'sftp_password'    => '',
            'sftp_path'        => '/',
            'schedule'         => 'daily',
            'filename_pattern' => 'products_{date}.csv',
            'batch_size'       => 100,
            'enabled'          => false,
            'delivery_time'    => 3,
            'return_days'      => 14,
        );

        if ( ! get_option( 'wpns_settings' ) ) {
            add_option( 'wpns_settings', $default_options );
        }

        // Create database table for logs
        $this->create_logs_table();

        // Flush rewrite rules
        flush_rewrite_rules();
    }

    /**
     * Create logs database table
     */
    private function create_logs_table() {
        global $wpdb;

        $table_name      = $wpdb->prefix . 'wpns_logs';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            run_id varchar(36) NOT NULL DEFAULT '',
            timestamp datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            level varchar(20) NOT NULL DEFAULT 'info',
            message text NOT NULL,
            context longtext,
            PRIMARY KEY (id),
            KEY run_id (run_id),
            KEY level (level),
            KEY timestamp (timestamp)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
        
        // Add run_id column if it doesn't exist (for upgrades)
        $column = $wpdb->get_results( "SHOW COLUMNS FROM {$table_name} LIKE 'run_id'" );
        if ( empty( $column ) ) {
            $wpdb->query( "ALTER TABLE {$table_name} ADD COLUMN run_id varchar(36) NOT NULL DEFAULT '' AFTER id" );
            $wpdb->query( "ALTER TABLE {$table_name} ADD INDEX run_id (run_id)" );
        }
    }

    /**
     * Plugin deactivation
     */
    public function deactivate() {
        // Clear scheduled cron events
        wp_clear_scheduled_hook( 'wpns_sync_event' );

        // Flush rewrite rules
        flush_rewrite_rules();
    }

    /**
     * Load plugin textdomain
     */
    public function load_textdomain() {
        load_plugin_textdomain(
            'wp-nalda-sync',
            false,
            dirname( WPNS_PLUGIN_BASENAME ) . '/languages/'
        );
    }

    /**
     * Declare HPOS compatibility
     */
    public function declare_hpos_compatibility() {
        if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
                'custom_order_tables',
                WPNS_PLUGIN_FILE,
                true
            );
        }
    }

    /**
     * Get plugin upload directory
     *
     * @return string
     */
    public static function get_upload_dir() {
        $upload_dir = wp_upload_dir();
        return $upload_dir['basedir'] . '/wp-nalda-sync';
    }

    /**
     * Get exports directory
     *
     * @return string
     */
    public static function get_exports_dir() {
        return self::get_upload_dir() . '/exports';
    }

    /**
     * Get logs directory
     *
     * @return string
     */
    public static function get_logs_dir() {
        return self::get_upload_dir() . '/logs';
    }

    /**
     * Prevent cloning
     */
    private function __clone() {}

    /**
     * Prevent unserializing
     */
    public function __wakeup() {
        throw new Exception( 'Cannot unserialize singleton' );
    }
}

/**
 * Returns the main instance of WP_Nalda_Sync
 *
 * @return WP_Nalda_Sync
 */
function wpns() {
    return WP_Nalda_Sync::instance();
}

// Initialize plugin
wpns();
