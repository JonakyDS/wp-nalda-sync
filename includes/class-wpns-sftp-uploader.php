<?php
/**
 * SFTP Uploader class for WP Nalda Sync
 *
 * @package WP_Nalda_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * SFTP Uploader class
 */
class WPNS_SFTP_Uploader {

    /**
     * Logger instance
     *
     * @var WPNS_Logger
     */
    private $logger;

    /**
     * SFTP connection resource
     *
     * @var resource
     */
    private $connection;

    /**
     * SFTP subsystem resource
     *
     * @var resource
     */
    private $sftp;

    /**
     * Constructor
     *
     * @param WPNS_Logger $logger Logger instance.
     */
    public function __construct( $logger ) {
        $this->logger = $logger;
    }

    /**
     * Check if SSH2 extension is available
     *
     * @return bool
     */
    public function is_ssh2_available() {
        return function_exists( 'ssh2_connect' );
    }

    /**
     * Get SFTP settings
     *
     * @return array
     */
    private function get_settings() {
        $settings = get_option( 'wpns_settings', array() );

        return array(
            'host'     => $settings['sftp_host'] ?? '',
            'port'     => absint( $settings['sftp_port'] ?? 22 ),
            'username' => $settings['sftp_username'] ?? '',
            'password' => WPNS_Admin::decrypt_password( $settings['sftp_password'] ?? '' ),
            'path'     => $settings['sftp_path'] ?? '/',
        );
    }

    /**
     * Test SFTP connection
     *
     * @return array Result with success status and message.
     */
    public function test_connection() {
        // Check SSH2 extension
        if ( ! $this->is_ssh2_available() ) {
            return array(
                'success' => false,
                'message' => __( 'SSH2 PHP extension is not installed. Please install php-ssh2 extension on your server.', 'wp-nalda-sync' ),
            );
        }

        $settings = $this->get_settings();

        // Validate settings
        if ( empty( $settings['host'] ) ) {
            return array(
                'success' => false,
                'message' => __( 'SFTP host is not configured.', 'wp-nalda-sync' ),
            );
        }

        if ( empty( $settings['username'] ) ) {
            return array(
                'success' => false,
                'message' => __( 'SFTP username is not configured.', 'wp-nalda-sync' ),
            );
        }

        if ( empty( $settings['password'] ) ) {
            return array(
                'success' => false,
                'message' => __( 'SFTP password is not configured.', 'wp-nalda-sync' ),
            );
        }

        try {
            // Attempt connection
            $result = $this->connect();

            if ( ! $result['success'] ) {
                return $result;
            }

            // Test directory access
            $remote_path = rtrim( $settings['path'], '/' );
            $stat = @ssh2_sftp_stat( $this->sftp, $remote_path );

            if ( ! $stat ) {
                $this->disconnect();
                return array(
                    'success' => false,
                    'message' => sprintf(
                        __( 'Remote path "%s" does not exist or is not accessible.', 'wp-nalda-sync' ),
                        $settings['path']
                    ),
                );
            }

            // Check if it's a directory
            if ( ! ( $stat['mode'] & 0040000 ) ) {
                $this->disconnect();
                return array(
                    'success' => false,
                    'message' => sprintf(
                        __( 'Remote path "%s" is not a directory.', 'wp-nalda-sync' ),
                        $settings['path']
                    ),
                );
            }

            $this->disconnect();

            return array(
                'success' => true,
                'message' => sprintf(
                    __( 'Successfully connected to %s:%d. Remote directory is accessible.', 'wp-nalda-sync' ),
                    $settings['host'],
                    $settings['port']
                ),
            );

        } catch ( Exception $e ) {
            $this->disconnect();
            return array(
                'success' => false,
                'message' => sprintf(
                    __( 'Connection failed: %s', 'wp-nalda-sync' ),
                    $e->getMessage()
                ),
            );
        }
    }

    /**
     * Upload file to SFTP server
     *
     * @param string $local_file  Local file path.
     * @param string $remote_file Remote file name (optional, uses local filename if not provided).
     * @return array Result with success status and message.
     */
    public function upload( $local_file, $remote_file = null ) {
        // Check if local file exists
        if ( ! file_exists( $local_file ) ) {
            return array(
                'success' => false,
                'message' => sprintf(
                    __( 'Local file does not exist: %s', 'wp-nalda-sync' ),
                    $local_file
                ),
            );
        }

        // Check SSH2 extension
        if ( ! $this->is_ssh2_available() ) {
            return $this->upload_fallback( $local_file, $remote_file );
        }

        $settings = $this->get_settings();

        // Validate settings
        $validation = $this->validate_settings( $settings );
        if ( ! $validation['success'] ) {
            return $validation;
        }

        // Determine remote filename
        if ( empty( $remote_file ) ) {
            $remote_file = basename( $local_file );
        }

        try {
            // Connect
            $result = $this->connect();
            if ( ! $result['success'] ) {
                return $result;
            }

            // Build full remote path
            $remote_path = rtrim( $settings['path'], '/' ) . '/' . $remote_file;

            $this->logger->info(
                sprintf( __( 'Uploading file to %s', 'wp-nalda-sync' ), $remote_path )
            );

            // Upload file using stream
            $sftp_stream = @fopen( "ssh2.sftp://{$this->sftp}{$remote_path}", 'w' );

            if ( ! $sftp_stream ) {
                $this->disconnect();
                return array(
                    'success' => false,
                    'message' => sprintf(
                        __( 'Failed to open remote file for writing: %s', 'wp-nalda-sync' ),
                        $remote_path
                    ),
                );
            }

            $local_stream = @fopen( $local_file, 'r' );
            if ( ! $local_stream ) {
                fclose( $sftp_stream );
                $this->disconnect();
                return array(
                    'success' => false,
                    'message' => sprintf(
                        __( 'Failed to open local file for reading: %s', 'wp-nalda-sync' ),
                        $local_file
                    ),
                );
            }

            // Copy file contents
            $bytes_written = stream_copy_to_stream( $local_stream, $sftp_stream );

            fclose( $local_stream );
            fclose( $sftp_stream );
            $this->disconnect();

            if ( $bytes_written === false ) {
                return array(
                    'success' => false,
                    'message' => __( 'Failed to write file contents to remote server.', 'wp-nalda-sync' ),
                );
            }

            $file_size = filesize( $local_file );

            $this->logger->success(
                sprintf(
                    __( 'File uploaded successfully: %s (%s bytes)', 'wp-nalda-sync' ),
                    $remote_file,
                    number_format( $bytes_written )
                )
            );

            return array(
                'success'       => true,
                'message'       => sprintf(
                    __( 'File uploaded successfully to %s (%s bytes)', 'wp-nalda-sync' ),
                    $remote_path,
                    number_format( $bytes_written )
                ),
                'remote_path'   => $remote_path,
                'bytes_written' => $bytes_written,
            );

        } catch ( Exception $e ) {
            $this->disconnect();
            return array(
                'success' => false,
                'message' => sprintf(
                    __( 'Upload failed: %s', 'wp-nalda-sync' ),
                    $e->getMessage()
                ),
            );
        }
    }

    /**
     * Fallback upload method using phpseclib if SSH2 extension is not available
     *
     * @param string $local_file  Local file path.
     * @param string $remote_file Remote file name.
     * @return array Result with success status and message.
     */
    private function upload_fallback( $local_file, $remote_file = null ) {
        // Check if phpseclib is available
        if ( ! class_exists( 'phpseclib3\Net\SFTP' ) ) {
            // Try to include phpseclib from vendor if available
            $autoload_path = WPNS_PLUGIN_DIR . 'vendor/autoload.php';
            if ( file_exists( $autoload_path ) ) {
                require_once $autoload_path;
            }
        }

        // If still not available, return error with instructions
        if ( ! class_exists( 'phpseclib3\Net\SFTP' ) ) {
            return array(
                'success' => false,
                'message' => __( 'Neither SSH2 extension nor phpseclib library is available. Please install php-ssh2 extension or run "composer require phpseclib/phpseclib" in the plugin directory.', 'wp-nalda-sync' ),
            );
        }

        $settings = $this->get_settings();

        // Validate settings
        $validation = $this->validate_settings( $settings );
        if ( ! $validation['success'] ) {
            return $validation;
        }

        // Determine remote filename
        if ( empty( $remote_file ) ) {
            $remote_file = basename( $local_file );
        }

        try {
            $sftp = new \phpseclib3\Net\SFTP( $settings['host'], $settings['port'] );

            if ( ! $sftp->login( $settings['username'], $settings['password'] ) ) {
                return array(
                    'success' => false,
                    'message' => __( 'SFTP authentication failed. Please check your credentials.', 'wp-nalda-sync' ),
                );
            }

            // Build full remote path
            $remote_path = rtrim( $settings['path'], '/' ) . '/' . $remote_file;

            $this->logger->info(
                sprintf( __( 'Uploading file to %s (using phpseclib)', 'wp-nalda-sync' ), $remote_path )
            );

            // Upload file
            $result = $sftp->put( $remote_path, $local_file, \phpseclib3\Net\SFTP::SOURCE_LOCAL_FILE );

            if ( ! $result ) {
                return array(
                    'success' => false,
                    'message' => sprintf(
                        __( 'Failed to upload file to: %s', 'wp-nalda-sync' ),
                        $remote_path
                    ),
                );
            }

            $file_size = filesize( $local_file );

            $this->logger->success(
                sprintf(
                    __( 'File uploaded successfully: %s (%s bytes)', 'wp-nalda-sync' ),
                    $remote_file,
                    number_format( $file_size )
                )
            );

            return array(
                'success'       => true,
                'message'       => sprintf(
                    __( 'File uploaded successfully to %s (%s bytes)', 'wp-nalda-sync' ),
                    $remote_path,
                    number_format( $file_size )
                ),
                'remote_path'   => $remote_path,
                'bytes_written' => $file_size,
            );

        } catch ( Exception $e ) {
            return array(
                'success' => false,
                'message' => sprintf(
                    __( 'Upload failed: %s', 'wp-nalda-sync' ),
                    $e->getMessage()
                ),
            );
        }
    }

    /**
     * Validate SFTP settings
     *
     * @param array $settings SFTP settings.
     * @return array Result with success status and message.
     */
    private function validate_settings( $settings ) {
        if ( empty( $settings['host'] ) ) {
            return array(
                'success' => false,
                'message' => __( 'SFTP host is not configured.', 'wp-nalda-sync' ),
            );
        }

        if ( empty( $settings['username'] ) ) {
            return array(
                'success' => false,
                'message' => __( 'SFTP username is not configured.', 'wp-nalda-sync' ),
            );
        }

        if ( empty( $settings['password'] ) ) {
            return array(
                'success' => false,
                'message' => __( 'SFTP password is not configured.', 'wp-nalda-sync' ),
            );
        }

        return array( 'success' => true );
    }

    /**
     * Connect to SFTP server
     *
     * @return array Result with success status and message.
     */
    private function connect() {
        $settings = $this->get_settings();

        // Connect to SSH server
        $this->connection = @ssh2_connect( $settings['host'], $settings['port'] );

        if ( ! $this->connection ) {
            return array(
                'success' => false,
                'message' => sprintf(
                    __( 'Could not connect to %s:%d. Please verify the host and port.', 'wp-nalda-sync' ),
                    $settings['host'],
                    $settings['port']
                ),
            );
        }

        // Authenticate
        $auth_result = @ssh2_auth_password(
            $this->connection,
            $settings['username'],
            $settings['password']
        );

        if ( ! $auth_result ) {
            return array(
                'success' => false,
                'message' => __( 'SFTP authentication failed. Please check your username and password.', 'wp-nalda-sync' ),
            );
        }

        // Initialize SFTP subsystem
        $this->sftp = @ssh2_sftp( $this->connection );

        if ( ! $this->sftp ) {
            return array(
                'success' => false,
                'message' => __( 'Could not initialize SFTP subsystem.', 'wp-nalda-sync' ),
            );
        }

        return array( 'success' => true );
    }

    /**
     * Disconnect from SFTP server
     */
    private function disconnect() {
        if ( $this->connection && function_exists( 'ssh2_disconnect' ) ) {
            @ssh2_disconnect( $this->connection );
        }

        $this->connection = null;
        $this->sftp       = null;
    }

    /**
     * List files in remote directory
     *
     * @param string $path Remote directory path.
     * @return array|WP_Error Array of files or WP_Error on failure.
     */
    public function list_files( $path = null ) {
        if ( ! $this->is_ssh2_available() ) {
            return new WP_Error( 'ssh2_missing', __( 'SSH2 extension is not available.', 'wp-nalda-sync' ) );
        }

        $settings = $this->get_settings();
        $path     = $path ?: $settings['path'];

        try {
            $result = $this->connect();
            if ( ! $result['success'] ) {
                return new WP_Error( 'connection_failed', $result['message'] );
            }

            $remote_path = rtrim( $path, '/' );
            $handle      = @opendir( "ssh2.sftp://{$this->sftp}{$remote_path}" );

            if ( ! $handle ) {
                $this->disconnect();
                return new WP_Error( 'dir_open_failed', __( 'Could not open remote directory.', 'wp-nalda-sync' ) );
            }

            $files = array();
            while ( ( $file = readdir( $handle ) ) !== false ) {
                if ( $file === '.' || $file === '..' ) {
                    continue;
                }

                $full_path = "{$remote_path}/{$file}";
                $stat      = @ssh2_sftp_stat( $this->sftp, $full_path );

                $files[] = array(
                    'name'     => $file,
                    'path'     => $full_path,
                    'size'     => $stat['size'] ?? 0,
                    'modified' => $stat['mtime'] ?? 0,
                    'is_dir'   => ( $stat['mode'] & 0040000 ) ? true : false,
                );
            }

            closedir( $handle );
            $this->disconnect();

            return $files;

        } catch ( Exception $e ) {
            $this->disconnect();
            return new WP_Error( 'exception', $e->getMessage() );
        }
    }

    /**
     * Delete remote file
     *
     * @param string $remote_file Remote file path.
     * @return array Result with success status and message.
     */
    public function delete_file( $remote_file ) {
        if ( ! $this->is_ssh2_available() ) {
            return array(
                'success' => false,
                'message' => __( 'SSH2 extension is not available.', 'wp-nalda-sync' ),
            );
        }

        try {
            $result = $this->connect();
            if ( ! $result['success'] ) {
                return $result;
            }

            $deleted = @ssh2_sftp_unlink( $this->sftp, $remote_file );
            $this->disconnect();

            if ( ! $deleted ) {
                return array(
                    'success' => false,
                    'message' => sprintf(
                        __( 'Failed to delete remote file: %s', 'wp-nalda-sync' ),
                        $remote_file
                    ),
                );
            }

            return array(
                'success' => true,
                'message' => sprintf(
                    __( 'File deleted successfully: %s', 'wp-nalda-sync' ),
                    $remote_file
                ),
            );

        } catch ( Exception $e ) {
            $this->disconnect();
            return array(
                'success' => false,
                'message' => sprintf(
                    __( 'Delete failed: %s', 'wp-nalda-sync' ),
                    $e->getMessage()
                ),
            );
        }
    }
}
