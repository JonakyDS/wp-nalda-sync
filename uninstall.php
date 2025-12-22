<?php
/**
 * Uninstall script for WP Nalda Sync
 *
 * This file is executed when the plugin is deleted from WordPress.
 * It removes all plugin data from the database.
 *
 * @package WP_Nalda_Sync
 */

// Exit if not uninstalling
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

// Delete options
delete_option( 'wpns_settings' );
delete_option( 'wpns_last_run' );
delete_option( 'wpns_last_log_cleanup' );

// Delete transients
delete_transient( 'wpns_sync_running' );

// Clear scheduled events
wp_clear_scheduled_hook( 'wpns_sync_event' );

// Drop custom tables
$table_name = $wpdb->prefix . 'wpns_logs';
$wpdb->query( "DROP TABLE IF EXISTS {$table_name}" );

// Optionally delete uploaded files (commented out by default to preserve data)
// Uncomment the following lines if you want to delete all generated files on uninstall
/*
$upload_dir = wp_upload_dir();
$wpns_dir = $upload_dir['basedir'] . '/wp-nalda-sync';

if ( is_dir( $wpns_dir ) ) {
    // Recursive delete function
    function wpns_delete_directory( $dir ) {
        if ( ! is_dir( $dir ) ) {
            return;
        }
        
        $files = array_diff( scandir( $dir ), array( '.', '..' ) );
        
        foreach ( $files as $file ) {
            $path = $dir . '/' . $file;
            if ( is_dir( $path ) ) {
                wpns_delete_directory( $path );
            } else {
                unlink( $path );
            }
        }
        
        rmdir( $dir );
    }
    
    wpns_delete_directory( $wpns_dir );
}
*/
