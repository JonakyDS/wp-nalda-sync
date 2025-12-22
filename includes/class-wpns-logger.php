<?php
/**
 * Logger class for WP Nalda Sync
 *
 * @package WP_Nalda_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Logger class
 */
class WPNS_Logger {

    /**
     * Log levels
     */
    const LEVEL_INFO    = 'info';
    const LEVEL_SUCCESS = 'success';
    const LEVEL_WARNING = 'warning';
    const LEVEL_ERROR   = 'error';

    /**
     * Database table name
     *
     * @var string
     */
    private $table_name;

    /**
     * Current run ID
     *
     * @var string
     */
    private $current_run_id = '';

    /**
     * Constructor
     */
    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'wpns_logs';
    }

    /**
     * Start a new sync run
     *
     * @param string $trigger How the sync was triggered (scheduled, manual).
     * @return string The run ID
     */
    public function start_run( $trigger = 'manual' ) {
        $this->current_run_id = $this->generate_run_id();
        
        $this->info(
            sprintf( __( 'Starting %s sync', 'wp-nalda-sync' ), $trigger ),
            array( 'trigger' => $trigger )
        );

        return $this->current_run_id;
    }

    /**
     * End the current sync run
     *
     * @param string $status Run status (success, failed).
     * @param array  $stats  Run statistics.
     */
    public function end_run( $status = 'success', $stats = array() ) {
        if ( 'success' === $status ) {
            $this->success( __( 'Sync completed successfully', 'wp-nalda-sync' ), $stats );
        } else {
            $this->error( __( 'Sync failed', 'wp-nalda-sync' ), $stats );
        }
        $this->current_run_id = '';
    }

    /**
     * Generate a unique run ID
     *
     * @return string
     */
    private function generate_run_id() {
        return sprintf(
            '%s-%s',
            date( 'Ymd-His' ),
            substr( md5( uniqid( '', true ) ), 0, 8 )
        );
    }

    /**
     * Set current run ID (for resuming)
     *
     * @param string $run_id Run ID.
     */
    public function set_run_id( $run_id ) {
        $this->current_run_id = $run_id;
    }

    /**
     * Get current run ID
     *
     * @return string
     */
    public function get_run_id() {
        return $this->current_run_id;
    }

    /**
     * Log an info message
     *
     * @param string $message Log message.
     * @param array  $context Additional context data.
     */
    public function info( $message, $context = array() ) {
        $this->log( self::LEVEL_INFO, $message, $context );
    }

    /**
     * Log a success message
     *
     * @param string $message Log message.
     * @param array  $context Additional context data.
     */
    public function success( $message, $context = array() ) {
        $this->log( self::LEVEL_SUCCESS, $message, $context );
    }

    /**
     * Log a warning message
     *
     * @param string $message Log message.
     * @param array  $context Additional context data.
     */
    public function warning( $message, $context = array() ) {
        $this->log( self::LEVEL_WARNING, $message, $context );
    }

    /**
     * Log an error message
     *
     * @param string $message Log message.
     * @param array  $context Additional context data.
     */
    public function error( $message, $context = array() ) {
        $this->log( self::LEVEL_ERROR, $message, $context );
    }

    /**
     * Write log entry to database
     *
     * @param string $level   Log level.
     * @param string $message Log message.
     * @param array  $context Additional context data.
     */
    private function log( $level, $message, $context = array() ) {
        global $wpdb;

        // Ensure table exists
        if ( ! $this->table_exists() ) {
            return;
        }

        // Prepare context
        $context_json = ! empty( $context ) ? wp_json_encode( $context, JSON_PRETTY_PRINT ) : '';

        // Insert log entry
        $wpdb->insert(
            $this->table_name,
            array(
                'run_id'    => $this->current_run_id,
                'timestamp' => current_time( 'mysql' ),
                'level'     => $level,
                'message'   => $message,
                'context'   => $context_json,
            ),
            array( '%s', '%s', '%s', '%s', '%s' )
        );

        // Also write to WordPress debug log if enabled
        if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
            $log_message = sprintf(
                '[WP Nalda Sync] [%s] [%s] %s',
                $this->current_run_id ?: 'no-run',
                strtoupper( $level ),
                $message
            );

            if ( ! empty( $context ) ) {
                $log_message .= ' | Context: ' . wp_json_encode( $context );
            }

            error_log( $log_message );
        }

        // Cleanup old logs periodically
        $this->maybe_cleanup_old_logs();
    }

    /**
     * Get all sync runs with their summaries
     *
     * @param int $limit Number of runs to retrieve.
     * @return array
     */
    public function get_sync_runs( $limit = 20 ) {
        global $wpdb;

        if ( ! $this->table_exists() ) {
            return array();
        }

        // Get distinct run_ids with their first and last timestamps (including orphan logs as a special "run")
        $query = $wpdb->prepare(
            "SELECT 
                CASE WHEN run_id = '' THEN '__orphan__' ELSE run_id END as run_id,
                MIN(timestamp) as started_at,
                MAX(timestamp) as ended_at,
                COUNT(*) as log_count,
                SUM(CASE WHEN level = 'error' THEN 1 ELSE 0 END) as error_count,
                SUM(CASE WHEN level = 'warning' THEN 1 ELSE 0 END) as warning_count,
                SUM(CASE WHEN level = 'success' THEN 1 ELSE 0 END) as success_count,
                SUM(CASE WHEN level = 'info' THEN 1 ELSE 0 END) as info_count
            FROM {$this->table_name}
            GROUP BY CASE WHEN run_id = '' THEN '__orphan__' ELSE run_id END
            ORDER BY ended_at DESC
            LIMIT %d",
            $limit
        );

        $runs = $wpdb->get_results( $query );

        // Enhance each run with additional data
        foreach ( $runs as &$run ) {
            // Handle orphan logs specially
            $is_orphan = ( $run->run_id === '__orphan__' );
            
            // Determine overall status
            if ( $is_orphan ) {
                $run->status = 'orphan';
            } elseif ( $run->error_count > 0 ) {
                $run->status = 'failed';
            } elseif ( $run->success_count > 0 ) {
                $run->status = 'success';
            } else {
                $run->status = 'running';
            }

            // Calculate duration
            $start = strtotime( $run->started_at );
            $end   = strtotime( $run->ended_at );
            $run->duration = $end - $start;

            // Get the trigger type from the first log entry
            if ( $is_orphan ) {
                $run->trigger = 'system';
            } else {
                $first_log = $wpdb->get_row( $wpdb->prepare(
                    "SELECT context FROM {$this->table_name} WHERE run_id = %s ORDER BY timestamp ASC LIMIT 1",
                    $run->run_id
                ) );

                if ( $first_log && ! empty( $first_log->context ) ) {
                    $context = json_decode( $first_log->context, true );
                    $run->trigger = isset( $context['trigger'] ) ? $context['trigger'] : 'unknown';
                } else {
                    $run->trigger = 'unknown';
                }
            }

            // Get final stats from the last success log
            if ( $is_orphan ) {
                $run->final_stats = null;
            } else {
                $last_success = $wpdb->get_row( $wpdb->prepare(
                    "SELECT context FROM {$this->table_name} WHERE run_id = %s AND level = 'success' ORDER BY timestamp DESC LIMIT 1",
                    $run->run_id
                ) );

                if ( $last_success && ! empty( $last_success->context ) ) {
                    $run->final_stats = json_decode( $last_success->context, true );
                } else {
                    $run->final_stats = null;
                }
            }
        }

        return $runs;
    }

    /**
     * Get logs for a specific run
     *
     * @param string $run_id Run ID.
     * @return array
     */
    public function get_logs_for_run( $run_id ) {
        global $wpdb;

        if ( ! $this->table_exists() ) {
            return array();
        }

        // Handle orphan logs (those without a run_id)
        if ( $run_id === '__orphan__' ) {
            return $wpdb->get_results(
                "SELECT * FROM {$this->table_name} WHERE run_id = '' ORDER BY timestamp DESC"
            );
        }

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE run_id = %s ORDER BY timestamp ASC",
            $run_id
        ) );
    }

    /**
     * Get logs from database (legacy method)
     *
     * @param int    $limit Number of logs to retrieve.
     * @param string $level Filter by log level (optional).
     * @return array
     */
    public function get_logs( $limit = 100, $level = '' ) {
        global $wpdb;

        if ( ! $this->table_exists() ) {
            return array();
        }

        $query = "SELECT * FROM {$this->table_name}";
        $where = array();

        if ( ! empty( $level ) ) {
            $where[] = $wpdb->prepare( 'level = %s', $level );
        }

        if ( ! empty( $where ) ) {
            $query .= ' WHERE ' . implode( ' AND ', $where );
        }

        $query .= ' ORDER BY timestamp DESC';
        $query .= $wpdb->prepare( ' LIMIT %d', $limit );

        return $wpdb->get_results( $query );
    }

    /**
     * Get log counts by level
     *
     * @return array
     */
    public function get_log_counts() {
        global $wpdb;

        if ( ! $this->table_exists() ) {
            return array();
        }

        $query = "SELECT level, COUNT(*) as count FROM {$this->table_name} GROUP BY level";

        $results = $wpdb->get_results( $query );
        $counts  = array(
            'info'    => 0,
            'success' => 0,
            'warning' => 0,
            'error'   => 0,
            'total'   => 0,
        );

        foreach ( $results as $row ) {
            $counts[ $row->level ] = (int) $row->count;
            $counts['total']      += (int) $row->count;
        }

        // Count runs
        $counts['runs'] = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT run_id) FROM {$this->table_name} WHERE run_id != ''"
        );

        return $counts;
    }

    /**
     * Clear all logs
     */
    public function clear_logs() {
        global $wpdb;

        if ( ! $this->table_exists() ) {
            return;
        }

        $wpdb->query( "TRUNCATE TABLE {$this->table_name}" );
    }

    /**
     * Delete logs older than specified days
     *
     * @param int $days Number of days to keep.
     */
    public function delete_old_logs( $days = 30 ) {
        global $wpdb;

        if ( ! $this->table_exists() ) {
            return;
        }

        $cutoff_date = date( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );

        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$this->table_name} WHERE timestamp < %s",
                $cutoff_date
            )
        );
    }

    /**
     * Maybe cleanup old logs (runs periodically)
     */
    private function maybe_cleanup_old_logs() {
        $last_cleanup = get_option( 'wpns_last_log_cleanup', 0 );
        $day_in_seconds = DAY_IN_SECONDS;

        // Run cleanup once per day
        if ( time() - $last_cleanup > $day_in_seconds ) {
            $this->delete_old_logs( 30 );
            update_option( 'wpns_last_log_cleanup', time() );
        }
    }

    /**
     * Check if logs table exists
     *
     * @return bool
     */
    private function table_exists() {
        global $wpdb;

        $query = $wpdb->prepare(
            'SHOW TABLES LIKE %s',
            $wpdb->esc_like( $this->table_name )
        );

        return $wpdb->get_var( $query ) === $this->table_name;
    }

    /**
     * Log sync complete (convenience method)
     *
     * @param array $stats Sync statistics.
     */
    public function log_sync_complete( $stats ) {
        $this->success(
            __( 'Sync completed successfully', 'wp-nalda-sync' ),
            $stats
        );
    }

    /**
     * Log sync failure (convenience method)
     *
     * @param string $reason  Failure reason.
     * @param array  $context Additional context.
     */
    public function log_sync_failure( $reason, $context = array() ) {
        $this->error(
            sprintf( __( 'Sync failed: %s', 'wp-nalda-sync' ), $reason ),
            $context
        );
    }
}
