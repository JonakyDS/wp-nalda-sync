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
     * Constructor
     */
    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'wpns_logs';
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
                'timestamp' => current_time( 'mysql' ),
                'level'     => $level,
                'message'   => $message,
                'context'   => $context_json,
            ),
            array( '%s', '%s', '%s', '%s' )
        );

        // Also write to WordPress debug log if enabled
        if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
            $log_message = sprintf(
                '[WP Nalda Sync] [%s] %s',
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
     * Get logs from database
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

        return $counts;
    }

    /**
     * Get logs for a specific date range
     *
     * @param string $start_date Start date (Y-m-d format).
     * @param string $end_date   End date (Y-m-d format).
     * @param string $level      Filter by log level (optional).
     * @return array
     */
    public function get_logs_by_date_range( $start_date, $end_date, $level = '' ) {
        global $wpdb;

        if ( ! $this->table_exists() ) {
            return array();
        }

        $where = array(
            $wpdb->prepare( 'DATE(timestamp) >= %s', $start_date ),
            $wpdb->prepare( 'DATE(timestamp) <= %s', $end_date ),
        );

        if ( ! empty( $level ) ) {
            $where[] = $wpdb->prepare( 'level = %s', $level );
        }

        $query = "SELECT * FROM {$this->table_name} WHERE " . implode( ' AND ', $where );
        $query .= ' ORDER BY timestamp DESC';

        return $wpdb->get_results( $query );
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

        $this->info( __( 'Logs cleared', 'wp-nalda-sync' ) );
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

        $deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$this->table_name} WHERE timestamp < %s",
                $cutoff_date
            )
        );

        if ( $deleted > 0 ) {
            $this->info(
                sprintf( __( 'Deleted %d old log entries', 'wp-nalda-sync' ), $deleted )
            );
        }
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
     * Export logs to CSV
     *
     * @param string $start_date Start date (optional).
     * @param string $end_date   End date (optional).
     * @return string|false File path or false on failure.
     */
    public function export_logs_to_csv( $start_date = null, $end_date = null ) {
        if ( $start_date && $end_date ) {
            $logs = $this->get_logs_by_date_range( $start_date, $end_date );
        } else {
            $logs = $this->get_logs( 10000 );
        }

        if ( empty( $logs ) ) {
            return false;
        }

        $filename = 'wpns-logs-' . date( 'Y-m-d-H-i-s' ) . '.csv';
        $filepath = WP_Nalda_Sync::get_logs_dir() . '/' . $filename;

        $handle = fopen( $filepath, 'w' );
        if ( ! $handle ) {
            return false;
        }

        // Add UTF-8 BOM
        fwrite( $handle, "\xEF\xBB\xBF" );

        // Write headers
        fputcsv( $handle, array( 'Timestamp', 'Level', 'Message', 'Context' ) );

        // Write log entries
        foreach ( $logs as $log ) {
            fputcsv( $handle, array(
                $log->timestamp,
                $log->level,
                $log->message,
                $log->context,
            ) );
        }

        fclose( $handle );

        return $filepath;
    }

    /**
     * Get sync history summary
     *
     * @param int $limit Number of sync runs to retrieve.
     * @return array
     */
    public function get_sync_history( $limit = 10 ) {
        global $wpdb;

        if ( ! $this->table_exists() ) {
            return array();
        }

        // Get sync start entries
        $query = $wpdb->prepare(
            "SELECT * FROM {$this->table_name} 
             WHERE message LIKE %s 
             ORDER BY timestamp DESC 
             LIMIT %d",
            '%Starting scheduled sync%',
            $limit
        );

        return $wpdb->get_results( $query );
    }

    /**
     * Log sync start
     *
     * @param string $trigger How the sync was triggered (scheduled, manual).
     */
    public function log_sync_start( $trigger = 'scheduled' ) {
        $this->info(
            sprintf( __( 'Starting %s sync', 'wp-nalda-sync' ), $trigger ),
            array( 'trigger' => $trigger )
        );
    }

    /**
     * Log sync complete
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
     * Log sync failure
     *
     * @param string $reason Failure reason.
     * @param array  $context Additional context.
     */
    public function log_sync_failure( $reason, $context = array() ) {
        $this->error(
            sprintf( __( 'Sync failed: %s', 'wp-nalda-sync' ), $reason ),
            $context
        );
    }
}
