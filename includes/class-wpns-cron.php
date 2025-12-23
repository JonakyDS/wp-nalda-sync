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
     * Order Importer instance
     *
     * @var WPNS_Order_Importer
     */
    private $order_importer;

    /**
     * Product sync cron hook name
     *
     * @var string
     */
    const CRON_HOOK = 'wpns_sync_event';

    /**
     * Order sync cron hook name
     *
     * @var string
     */
    const ORDER_SYNC_HOOK = 'wpns_order_sync_event';

    /**
     * Constructor
     *
     * @param WPNS_CSV_Generator  $csv_generator  CSV Generator instance.
     * @param WPNS_SFTP_Uploader  $sftp_uploader  SFTP Uploader instance.
     * @param WPNS_Logger         $logger         Logger instance.
     * @param WPNS_Order_Importer $order_importer Order Importer instance (optional).
     */
    public function __construct( $csv_generator, $sftp_uploader, $logger, $order_importer = null ) {
        $this->csv_generator  = $csv_generator;
        $this->sftp_uploader  = $sftp_uploader;
        $this->logger         = $logger;
        $this->order_importer = $order_importer;

        $this->init_hooks();
    }

    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // Register custom cron schedules
        add_filter( 'cron_schedules', array( $this, 'add_cron_schedules' ) );

        // Register cron event handlers
        add_action( self::CRON_HOOK, array( $this, 'execute_sync' ) );
        add_action( self::ORDER_SYNC_HOOK, array( $this, 'execute_order_sync' ) );

        // Schedule initial events if enabled
        add_action( 'init', array( $this, 'maybe_schedule_event' ) );
        add_action( 'init', array( $this, 'maybe_schedule_order_sync' ) );
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

        // Add custom interval schedule
        $settings = get_option( 'wpns_settings', array() );
        $custom_minutes = isset( $settings['custom_interval_minutes'] ) ? absint( $settings['custom_interval_minutes'] ) : 0;
        
        if ( $custom_minutes > 0 ) {
            $schedules['wpns_custom'] = array(
                'interval' => $custom_minutes * MINUTE_IN_SECONDS,
                'display'  => sprintf( __( 'Every %d minutes', 'wp-nalda-sync' ), $custom_minutes ),
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

        // Schedule new event starting 2 minutes from now
        $first_run = time() + ( 2 * MINUTE_IN_SECONDS );
        $result = wp_schedule_event( $first_run, $recurrence, self::CRON_HOOK );

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
     * Maybe schedule order sync on init
     */
    public function maybe_schedule_order_sync() {
        $settings = get_option( 'wpns_settings', array() );

        if ( empty( $settings['order_sync_enabled'] ) ) {
            return;
        }

        if ( ! wp_next_scheduled( self::ORDER_SYNC_HOOK ) ) {
            $this->schedule_order_sync( $settings['order_sync_schedule'] ?? 'hourly' );
        }
    }

    /**
     * Schedule the order sync event
     *
     * @param string $recurrence Schedule recurrence.
     * @return bool
     */
    public function schedule_order_sync( $recurrence = 'hourly' ) {
        // Clear any existing schedule
        $this->unschedule_order_sync();

        // Schedule new event starting 1 minute from now
        $first_run = time() + MINUTE_IN_SECONDS;
        $result = wp_schedule_event( $first_run, $recurrence, self::ORDER_SYNC_HOOK );

        if ( $result ) {
            $this->logger->info(
                sprintf( __( 'Order sync scheduled: %s', 'wp-nalda-sync' ), $recurrence )
            );
        }

        return $result !== false;
    }

    /**
     * Unschedule the order sync event
     */
    public function unschedule_order_sync() {
        $timestamp = wp_next_scheduled( self::ORDER_SYNC_HOOK );

        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, self::ORDER_SYNC_HOOK );
            $this->logger->info( __( 'Order sync unscheduled', 'wp-nalda-sync' ) );
        }

        // Also clear all scheduled hooks with this name
        wp_clear_scheduled_hook( self::ORDER_SYNC_HOOK );
    }

    /**
     * Reschedule the order sync event
     *
     * @param bool   $enabled    Whether order sync is enabled.
     * @param string $recurrence Schedule recurrence.
     */
    public function reschedule_order_sync( $enabled, $recurrence ) {
        if ( $enabled ) {
            $this->schedule_order_sync( $recurrence );
        } else {
            $this->unschedule_order_sync();
        }
    }

    /**
     * Execute the order sync (cron handler)
     */
    public function execute_order_sync() {
        // Increase execution time for scheduled runs
        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 300 ); // 5 minutes
        }

        // Increase memory limit if possible
        if ( function_exists( 'wp_raise_memory_limit' ) ) {
            wp_raise_memory_limit( 'cron' );
        }

        // Disable user abort for cron
        if ( function_exists( 'ignore_user_abort' ) ) {
            @ignore_user_abort( true );
        }

        $this->run_order_sync( 'scheduled' );
    }

    /**
     * Run the order sync process
     *
     * @param string $trigger How the sync was triggered (scheduled, manual).
     * @return array Result array.
     */
    public function run_order_sync( $trigger = 'manual' ) {
        $start_time = microtime( true );

        // Start a new run and get the run ID
        $run_id = $this->logger->start_run( $trigger . '_order_sync' );

        try {
            // Check if WooCommerce is active
            if ( ! class_exists( 'WooCommerce' ) ) {
                $message = __( 'WooCommerce is not active.', 'wp-nalda-sync' );
                $this->logger->log_sync_failure( $message );
                $this->logger->end_run( 'failed', array( 'reason' => $message ) );
                return array(
                    'success' => false,
                    'message' => $message,
                );
            }

            // Check if order importer is available
            if ( ! $this->order_importer ) {
                $message = __( 'Order importer is not initialized.', 'wp-nalda-sync' );
                $this->logger->log_sync_failure( $message );
                $this->logger->end_run( 'failed', array( 'reason' => $message ) );
                return array(
                    'success' => false,
                    'message' => $message,
                );
            }

            // Get settings
            $settings = get_option( 'wpns_settings', array() );

            if ( empty( $settings['nalda_api_key'] ) ) {
                $message = __( 'Nalda API key is not configured.', 'wp-nalda-sync' );
                $this->logger->log_sync_failure( $message );
                $this->logger->end_run( 'failed', array( 'reason' => $message ) );
                return array(
                    'success' => false,
                    'message' => $message,
                );
            }

            // Get date range setting
            $range = $settings['order_sync_range'] ?? 'today';

            // Run the import
            $this->logger->info( sprintf( __( 'Starting order sync from Nalda (range: %s)...', 'wp-nalda-sync' ), $range ) );
            $result = $this->order_importer->import_orders( $range );

            // Calculate duration
            $duration = round( microtime( true ) - $start_time, 2 );

            if ( ! $result['success'] ) {
                $this->logger->log_sync_failure( $result['message'] );
                $this->update_last_order_sync( 'failed', $result['stats'] ?? array() );
                $this->logger->end_run( 'failed', array( 'reason' => $result['message'] ) );
                return $result;
            }

            // Log success
            $stats = array_merge( $result['stats'] ?? array(), array(
                'trigger'          => $trigger,
                'duration_seconds' => $duration,
                'range'            => $range,
            ) );

            // Update last run info
            $this->update_last_order_sync( 'success', $stats );

            // Save import stats
            $this->order_importer->save_import_stats( $stats );

            // End the run with success
            $this->logger->end_run( 'success', $stats );

            return array(
                'success' => true,
                'message' => $result['message'],
                'stats'   => $stats,
            );

        } catch ( Exception $e ) {
            $message = sprintf( __( 'Order sync failed with exception: %s', 'wp-nalda-sync' ), $e->getMessage() );
            $this->logger->error( $message, array(
                'exception' => $e->getMessage(),
                'file'      => $e->getFile(),
                'line'      => $e->getLine(),
            ) );
            $this->logger->end_run( 'failed', array( 'reason' => $message ) );
            $this->update_last_order_sync( 'failed', array() );
            return array(
                'success' => false,
                'message' => $message,
            );
        } catch ( Error $e ) {
            $message = sprintf( __( 'Order sync failed with error: %s', 'wp-nalda-sync' ), $e->getMessage() );
            $this->logger->error( $message, array(
                'error' => $e->getMessage(),
                'file'  => $e->getFile(),
                'line'  => $e->getLine(),
            ) );
            $this->logger->end_run( 'failed', array( 'reason' => $message ) );
            $this->update_last_order_sync( 'failed', array() );
            return array(
                'success' => false,
                'message' => $message,
            );
        }
    }

    /**
     * Update last order sync information
     *
     * @param string $status Run status.
     * @param array  $stats  Additional statistics.
     */
    private function update_last_order_sync( $status, $stats = array() ) {
        $last_sync = array(
            'time'   => time(),
            'status' => $status,
            'stats'  => $stats,
        );

        update_option( 'wpns_last_order_sync', $last_sync );
    }

    /**
     * Get last order sync information
     *
     * @return array
     */
    public function get_last_order_sync() {
        return get_option( 'wpns_last_order_sync', array() );
    }

    /**
     * Get next scheduled order sync time
     *
     * @return int|false Timestamp or false if not scheduled.
     */
    public function get_next_order_sync() {
        return wp_next_scheduled( self::ORDER_SYNC_HOOK );
    }

    /**
     * Execute the sync (cron handler)
     */
    public function execute_sync() {
        // Increase execution time for scheduled runs
        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 300 ); // 5 minutes
        }

        // Increase memory limit if possible
        if ( function_exists( 'wp_raise_memory_limit' ) ) {
            wp_raise_memory_limit( 'cron' );
        }

        // Disable user abort for cron
        if ( function_exists( 'ignore_user_abort' ) ) {
            @ignore_user_abort( true );
        }

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

        // Start a new run and get the run ID
        $run_id = $this->logger->start_run( $trigger );

        try {
            // Check if WooCommerce is active
            if ( ! class_exists( 'WooCommerce' ) ) {
                $message = __( 'WooCommerce is not active.', 'wp-nalda-sync' );
                $this->logger->log_sync_failure( $message );
                $this->logger->end_run( 'failed', array( 'reason' => $message ) );
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
                $this->logger->end_run( 'failed', array( 'reason' => $message ) );
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
                $this->logger->end_run( 'failed', array( 'reason' => $csv_result['message'] ) );
                return array(
                    'success' => false,
                    'message' => $csv_result['message'],
                );
            }

            // Upload to SFTP
            $this->logger->info( __( 'Uploading CSV to SFTP server...', 'wp-nalda-sync' ) );
            $upload_result = $this->sftp_uploader->upload( $csv_result['filepath'] );

            // Get file size before cleanup
            $file_size = file_exists( $csv_result['filepath'] ) ? filesize( $csv_result['filepath'] ) : 0;

            // Cleanup temp file after upload (regardless of success/failure)
            $this->csv_generator->cleanup_temp_file( $csv_result['filepath'] );

            if ( ! $upload_result['success'] ) {
                $this->logger->log_sync_failure( $upload_result['message'] );
                $this->update_last_run( 'failed', $csv_result['exported_count'] ?? 0 );
                $this->logger->end_run( 'failed', array( 'reason' => $upload_result['message'] ) );
                return array(
                    'success' => false,
                    'message' => $upload_result['message'],
                );
            }

            // Calculate duration
            $duration = round( microtime( true ) - $start_time, 2 );

            // Log success
            $stats = array(
                'trigger'          => $trigger,
                'products_exported' => $csv_result['exported_count'] ?? 0,
                'products_skipped'  => $csv_result['skipped_count'] ?? 0,
                'file_size'         => $file_size,
                'remote_path'       => $upload_result['remote_path'] ?? '',
                'duration_seconds'  => $duration,
            );

            // Update last run info
            $this->update_last_run( 'success', $csv_result['exported_count'] ?? 0, $stats );

            // End the run with success
            $this->logger->end_run( 'success', $stats );

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

        } catch ( Exception $e ) {
            $message = sprintf( __( 'Sync failed with exception: %s', 'wp-nalda-sync' ), $e->getMessage() );
            $this->logger->error( $message, array(
                'exception' => $e->getMessage(),
                'file'      => $e->getFile(),
                'line'      => $e->getLine(),
            ) );
            $this->logger->end_run( 'failed', array( 'reason' => $message ) );
            $this->update_last_run( 'failed', 0 );
            return array(
                'success' => false,
                'message' => $message,
            );
        } catch ( Error $e ) {
            $message = sprintf( __( 'Sync failed with error: %s', 'wp-nalda-sync' ), $e->getMessage() );
            $this->logger->error( $message, array(
                'error' => $e->getMessage(),
                'file'  => $e->getFile(),
                'line'  => $e->getLine(),
            ) );
            $this->logger->end_run( 'failed', array( 'reason' => $message ) );
            $this->update_last_run( 'failed', 0 );
            return array(
                'success' => false,
                'message' => $message,
            );
        }
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

    /**
     * Get order sync statistics for dashboard
     *
     * @return array
     */
    public function get_order_sync_stats() {
        $last_sync = $this->get_last_order_sync();
        $next_sync = $this->get_next_order_sync();
        $settings  = get_option( 'wpns_settings', array() );

        return array(
            'enabled'          => ! empty( $settings['order_sync_enabled'] ),
            'schedule'         => $settings['order_sync_schedule'] ?? 'hourly',
            'schedule_label'   => self::get_schedule_display_name( $settings['order_sync_schedule'] ?? 'hourly' ),
            'range'            => $settings['order_sync_range'] ?? 'today',
            'last_sync_time'   => $last_sync['time'] ?? null,
            'last_sync_status' => $last_sync['status'] ?? null,
            'last_sync_stats'  => $last_sync['stats'] ?? array(),
            'next_sync_time'   => $next_sync,
        );
    }
}
