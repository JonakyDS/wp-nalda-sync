<?php
/**
 * Cron class for WP Nalda Sync
 *
 * @package WP_Nalda_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Cron class for scheduling and running sync tasks
 */
class WPNS_Cron {

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
     * Logger instance
     *
     * @var WPNS_Logger
     */
    private $logger;

    /**
     * Cron hook name
     *
     * @var string
     */
    const CRON_HOOK = 'wpns_sync_event';

    /**
     * Constructor
     *
     * @param WPNS_CSV_Generator $csv_generator CSV Generator instance.
     * @param WPNS_SFTP_Uploader $sftp_uploader SFTP Uploader instance.
     * @param WPNS_Logger        $logger        Logger instance.
     */
    public function __construct( $csv_generator, $sftp_uploader, $logger ) {
        $this->csv_generator = $csv_generator;
        $this->sftp_uploader = $sftp_uploader;
        $this->logger        = $logger;

        $this->init_hooks();
    }

    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // Register custom cron schedules
        add_filter( 'cron_schedules', array( $this, 'add_cron_schedules' ) );

        // Register cron event handler
        add_action( self::CRON_HOOK, array( $this, 'execute_sync' ) );

        // Schedule initial event if enabled
        add_action( 'init', array( $this, 'maybe_schedule_event' ) );
    }

    /**
     * Add custom cron schedules
     *
     * @param array $schedules Existing schedules.
     * @return array
     */
    public function add_cron_schedules( $schedules ) {
        // Add weekly schedule if not exists
        if ( ! isset( $schedules['weekly'] ) ) {
            $schedules['weekly'] = array(
                'interval' => WEEK_IN_SECONDS,
                'display'  => __( 'Once Weekly', 'wp-nalda-sync' ),
            );
        }

        return $schedules;
    }

    /**
     * Maybe schedule event on init
     */
    public function maybe_schedule_event() {
        $settings = get_option( 'wpns_settings', array() );

        if ( empty( $settings['enabled'] ) ) {
            return;
        }

        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            $this->schedule( $settings['schedule'] ?? 'daily' );
        }
    }

    /**
     * Schedule the sync event
     *
     * @param string $recurrence Schedule recurrence.
     * @return bool
     */
    public function schedule( $recurrence = 'daily' ) {
        // Clear any existing schedule
        $this->unschedule();

        // Schedule new event
        $result = wp_schedule_event( time(), $recurrence, self::CRON_HOOK );

        if ( $result ) {
            $this->logger->info(
                sprintf( __( 'Sync scheduled: %s', 'wp-nalda-sync' ), $recurrence )
            );
        }

        return $result !== false;
    }

    /**
     * Unschedule the sync event
     */
    public function unschedule() {
        $timestamp = wp_next_scheduled( self::CRON_HOOK );

        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, self::CRON_HOOK );
            $this->logger->info( __( 'Sync unscheduled', 'wp-nalda-sync' ) );
        }

        // Also clear all scheduled hooks with this name
        wp_clear_scheduled_hook( self::CRON_HOOK );
    }

    /**
     * Reschedule the sync event
     *
     * @param bool   $enabled    Whether sync is enabled.
     * @param string $recurrence Schedule recurrence.
     */
    public function reschedule( $enabled, $recurrence ) {
        if ( $enabled ) {
            $this->schedule( $recurrence );
        } else {
            $this->unschedule();
        }
    }

    /**
     * Execute the sync (cron handler)
     */
    public function execute_sync() {
        $this->run_sync( 'scheduled' );
    }

    /**
     * Run the sync process
     *
     * @param string $trigger How the sync was triggered (scheduled, manual).
     * @return array Result array.
     */
    public function run_sync( $trigger = 'manual' ) {
        $start_time = microtime( true );

        $this->logger->log_sync_start( $trigger );

        // Check if WooCommerce is active
        if ( ! class_exists( 'WooCommerce' ) ) {
            $message = __( 'WooCommerce is not active.', 'wp-nalda-sync' );
            $this->logger->log_sync_failure( $message );
            return array(
                'success' => false,
                'message' => $message,
            );
        }

        // Check settings
        $settings = get_option( 'wpns_settings', array() );

        if ( empty( $settings['sftp_host'] ) || empty( $settings['sftp_username'] ) ) {
            $message = __( 'SFTP settings are not configured.', 'wp-nalda-sync' );
            $this->logger->log_sync_failure( $message );
            return array(
                'success' => false,
                'message' => $message,
            );
        }

        // Generate CSV
        $this->logger->info( __( 'Generating CSV file...', 'wp-nalda-sync' ) );
        $csv_result = $this->csv_generator->generate();

        if ( ! $csv_result['success'] ) {
            $this->logger->log_sync_failure( $csv_result['message'] );
            $this->update_last_run( 'failed', 0 );
            return array(
                'success' => false,
                'message' => $csv_result['message'],
            );
        }

        // Upload to SFTP
        $this->logger->info( __( 'Uploading CSV to SFTP server...', 'wp-nalda-sync' ) );
        $upload_result = $this->sftp_uploader->upload( $csv_result['filepath'] );

        if ( ! $upload_result['success'] ) {
            $this->logger->log_sync_failure( $upload_result['message'] );
            $this->update_last_run( 'failed', $csv_result['exported_count'] ?? 0 );
            return array(
                'success' => false,
                'message' => $upload_result['message'],
            );
        }

        // Cleanup old exports
        $this->csv_generator->cleanup_old_exports( 5 );

        // Calculate duration
        $duration = round( microtime( true ) - $start_time, 2 );

        // Log success
        $stats = array(
            'trigger'          => $trigger,
            'products_exported' => $csv_result['exported_count'] ?? 0,
            'products_skipped'  => $csv_result['skipped_count'] ?? 0,
            'file_size'         => filesize( $csv_result['filepath'] ),
            'remote_path'       => $upload_result['remote_path'] ?? '',
            'duration_seconds'  => $duration,
        );

        $this->logger->log_sync_complete( $stats );

        // Update last run info
        $this->update_last_run( 'success', $csv_result['exported_count'] ?? 0, $stats );

        $message = sprintf(
            __( 'Sync completed successfully. Exported %d products in %s seconds.', 'wp-nalda-sync' ),
            $csv_result['exported_count'],
            $duration
        );

        return array(
            'success' => true,
            'message' => $message,
            'stats'   => $stats,
        );
    }

    /**
     * Update last run information
     *
     * @param string $status            Run status.
     * @param int    $products_exported Number of products exported.
     * @param array  $stats             Additional statistics.
     */
    private function update_last_run( $status, $products_exported, $stats = array() ) {
        $last_run = array(
            'time'              => time(),
            'status'            => $status,
            'products_exported' => $products_exported,
            'stats'             => $stats,
        );

        update_option( 'wpns_last_run', $last_run );
    }

    /**
     * Get last run information
     *
     * @return array
     */
    public function get_last_run() {
        return get_option( 'wpns_last_run', array() );
    }

    /**
     * Get next scheduled run time
     *
     * @return int|false Timestamp or false if not scheduled.
     */
    public function get_next_run() {
        return wp_next_scheduled( self::CRON_HOOK );
    }

    /**
     * Check if sync is currently running
     *
     * @return bool
     */
    public function is_running() {
        return get_transient( 'wpns_sync_running' ) === 'yes';
    }

    /**
     * Set sync running state
     *
     * @param bool $running Whether sync is running.
     */
    public function set_running( $running ) {
        if ( $running ) {
            set_transient( 'wpns_sync_running', 'yes', HOUR_IN_SECONDS );
        } else {
            delete_transient( 'wpns_sync_running' );
        }
    }

    /**
     * Get schedule options
     *
     * @return array
     */
    public static function get_schedule_options() {
        return array(
            'hourly'     => __( 'Hourly', 'wp-nalda-sync' ),
            'twicedaily' => __( 'Twice Daily', 'wp-nalda-sync' ),
            'daily'      => __( 'Daily', 'wp-nalda-sync' ),
            'weekly'     => __( 'Weekly', 'wp-nalda-sync' ),
        );
    }

    /**
     * Get schedule display name
     *
     * @param string $schedule Schedule key.
     * @return string
     */
    public static function get_schedule_display_name( $schedule ) {
        $options = self::get_schedule_options();
        return $options[ $schedule ] ?? $schedule;
    }

    /**
     * Get sync statistics for dashboard
     *
     * @return array
     */
    public function get_sync_stats() {
        $last_run = $this->get_last_run();
        $next_run = $this->get_next_run();
        $settings = get_option( 'wpns_settings', array() );

        return array(
            'enabled'        => ! empty( $settings['enabled'] ),
            'schedule'       => $settings['schedule'] ?? 'daily',
            'schedule_label' => self::get_schedule_display_name( $settings['schedule'] ?? 'daily' ),
            'last_run_time'  => $last_run['time'] ?? null,
            'last_run_status' => $last_run['status'] ?? null,
            'last_run_count' => $last_run['products_exported'] ?? 0,
            'next_run_time'  => $next_run,
            'is_running'     => $this->is_running(),
        );
    }
}
