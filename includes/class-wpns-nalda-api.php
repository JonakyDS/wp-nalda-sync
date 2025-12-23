<?php
/**
 * Nalda API Client class for WP Nalda Sync
 *
 * @package WP_Nalda_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Nalda API Client class
 * 
 * Handles all API communication with the Nalda Marketplace API
 */
class WPNS_Nalda_API {

    /**
     * API Base URL
     *
     * @var string
     */
    private $api_base_url = 'https://api.nalda.com';

    /**
     * API Key
     *
     * @var string
     */
    private $api_key;

    /**
     * Logger instance
     *
     * @var WPNS_Logger
     */
    private $logger;

    /**
     * Request timeout in seconds
     *
     * @var int
     */
    private $timeout = 30;

    /**
     * Available date ranges for API queries
     *
     * @var array
     */
    const DATE_RANGES = array(
        '3m',
        '6m',
        '12m',
        '24m',
        'today',
        'yesterday',
        'current-month',
        'current-year',
        'custom',
    );

    /**
     * Delivery status values
     *
     * @var array
     */
    const DELIVERY_STATUSES = array(
        'IN_PREPARATION',
        'IN_DELIVERY',
        'DELIVERED',
        'UNDELIVERABLE',
        'CANCELLED',
        'READY_TO_COLLECT',
        'COLLECTED',
        'NOT_PICKED_UP',
        'RETURNED',
        'DISPUTE',
    );

    /**
     * Payout status values
     *
     * @var array
     */
    const PAYOUT_STATUSES = array(
        'OPEN',
        'PAID_OUT',
        'PARTIALLY_PAID_OUT',
        'ERROR',
    );

    /**
     * Constructor
     *
     * @param WPNS_Logger $logger Logger instance.
     */
    public function __construct( $logger = null ) {
        $this->logger = $logger;
        $this->load_settings();
    }

    /**
     * Load API settings from WordPress options
     */
    private function load_settings() {
        $settings = get_option( 'wpns_settings', array() );
        
        // Decrypt API key if stored encrypted
        if ( ! empty( $settings['nalda_api_key'] ) ) {
            $this->api_key = wpns_decrypt_password( $settings['nalda_api_key'] );
        }

        // Allow custom API URL (for testing/staging)
        if ( ! empty( $settings['nalda_api_url'] ) ) {
            $this->api_base_url = rtrim( $settings['nalda_api_url'], '/' );
        }
    }

    /**
     * Set API key
     *
     * @param string $api_key API key.
     */
    public function set_api_key( $api_key ) {
        $this->api_key = $api_key;
    }

    /**
     * Set API base URL
     *
     * @param string $url API base URL.
     */
    public function set_api_url( $url ) {
        $this->api_base_url = rtrim( $url, '/' );
    }

    /**
     * Check if API is configured
     *
     * @return bool
     */
    public function is_configured() {
        return ! empty( $this->api_key );
    }

    /**
     * Make an API request
     *
     * @param string $endpoint API endpoint.
     * @param string $method   HTTP method (GET, POST).
     * @param array  $data     Request data for POST requests.
     * @return array|WP_Error Response array or WP_Error on failure.
     */
    private function request( $endpoint, $method = 'GET', $data = array() ) {
        if ( ! $this->is_configured() ) {
            return new WP_Error( 'api_not_configured', __( 'Nalda API key is not configured.', 'wp-nalda-sync' ) );
        }

        $url = $this->api_base_url . '/' . ltrim( $endpoint, '/' );

        $args = array(
            'method'  => $method,
            'timeout' => $this->timeout,
            'headers' => array(
                'X-API-KEY'    => $this->api_key,
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
            ),
        );

        if ( 'POST' === $method && ! empty( $data ) ) {
            $args['body'] = wp_json_encode( $data );
        }

        $this->log( 'info', sprintf( 'API Request: %s %s', $method, $endpoint ), array( 'data' => $data ) );

        $response = wp_remote_request( $url, $args );

        if ( is_wp_error( $response ) ) {
            $this->log( 'error', 'API request failed: ' . $response->get_error_message() );
            return $response;
        }

        $response_code = wp_remote_retrieve_response_code( $response );
        $response_body = wp_remote_retrieve_body( $response );
        $decoded_body  = json_decode( $response_body, true );

        if ( $response_code >= 400 ) {
            $error_message = isset( $decoded_body['message'] ) 
                ? $decoded_body['message'] 
                : sprintf( __( 'API error: HTTP %d', 'wp-nalda-sync' ), $response_code );
            
            $this->log( 'error', $error_message, array( 'response_code' => $response_code ) );
            return new WP_Error( 'api_error', $error_message, array( 'status' => $response_code ) );
        }

        $this->log( 'info', sprintf( 'API Response: HTTP %d', $response_code ) );

        return $decoded_body;
    }

    /**
     * Health check - test API connectivity
     *
     * @return array|WP_Error
     */
    public function health_check() {
        return $this->request( 'health-check', 'GET' );
    }

    /**
     * Test API connection
     *
     * @return array Result with success status and message.
     */
    public function test_connection() {
        $result = $this->health_check();

        if ( is_wp_error( $result ) ) {
            return array(
                'success' => false,
                'message' => $result->get_error_message(),
            );
        }

        if ( isset( $result['success'] ) && $result['success'] ) {
            return array(
                'success' => true,
                'message' => $result['message'] ?? __( 'Connection successful!', 'wp-nalda-sync' ),
            );
        }

        return array(
            'success' => false,
            'message' => $result['message'] ?? __( 'Unknown error occurred.', 'wp-nalda-sync' ),
        );
    }

    /**
     * Get orders by filter
     *
     * @param string      $range Date range (3m, 6m, 12m, 24m, today, yesterday, current-month, current-year, custom).
     * @param string|null $from  From date (YYYY-MM-DD) - required if range is 'custom'.
     * @param string|null $to    To date (YYYY-MM-DD) - required if range is 'custom'.
     * @return array|WP_Error
     */
    public function get_orders( $range = '3m', $from = null, $to = null ) {
        $data = array( 'range' => $range );

        if ( 'custom' === $range ) {
            if ( empty( $from ) || empty( $to ) ) {
                return new WP_Error( 
                    'invalid_params', 
                    __( 'From and To dates are required for custom range.', 'wp-nalda-sync' ) 
                );
            }
            $data['from'] = $from;
            $data['to']   = $to;
        }

        return $this->request( 'orders', 'POST', $data );
    }

    /**
     * Get order by ID
     *
     * @param int $order_id Order ID.
     * @return array|WP_Error
     */
    public function get_order( $order_id ) {
        return $this->request( sprintf( 'orders/%d', $order_id ), 'GET' );
    }

    /**
     * Get order items by filter
     *
     * @param string      $range Date range.
     * @param string|null $from  From date (YYYY-MM-DD).
     * @param string|null $to    To date (YYYY-MM-DD).
     * @return array|WP_Error
     */
    public function get_order_items( $range = '3m', $from = null, $to = null ) {
        $data = array( 'range' => $range );

        if ( 'custom' === $range ) {
            if ( empty( $from ) || empty( $to ) ) {
                return new WP_Error( 
                    'invalid_params', 
                    __( 'From and To dates are required for custom range.', 'wp-nalda-sync' ) 
                );
            }
            $data['from'] = $from;
            $data['to']   = $to;
        }

        return $this->request( 'orders/items', 'POST', $data );
    }

    /**
     * Get order items by order ID
     *
     * @param int $order_id Order ID.
     * @return array|WP_Error
     */
    public function get_order_items_by_order( $order_id ) {
        return $this->request( sprintf( 'orders/%d/items', $order_id ), 'GET' );
    }

    /**
     * Get orders with items combined
     *
     * @param string      $range Date range.
     * @param string|null $from  From date.
     * @param string|null $to    To date.
     * @return array|WP_Error Array with 'orders' and 'items' keys.
     */
    public function get_orders_with_items( $range = '3m', $from = null, $to = null ) {
        $orders_result = $this->get_orders( $range, $from, $to );
        
        if ( is_wp_error( $orders_result ) ) {
            return $orders_result;
        }

        $items_result = $this->get_order_items( $range, $from, $to );
        
        if ( is_wp_error( $items_result ) ) {
            return $items_result;
        }

        return array(
            'success' => true,
            'orders'  => $orders_result['result'] ?? array(),
            'items'   => $items_result['result'] ?? array(),
        );
    }

    /**
     * Log a message
     *
     * @param string $level   Log level.
     * @param string $message Log message.
     * @param array  $context Additional context.
     */
    private function log( $level, $message, $context = array() ) {
        if ( $this->logger ) {
            $message = '[Nalda API] ' . $message;
            
            switch ( $level ) {
                case 'error':
                    $this->logger->error( $message, $context );
                    break;
                case 'warning':
                    $this->logger->warning( $message, $context );
                    break;
                case 'success':
                    $this->logger->success( $message, $context );
                    break;
                default:
                    $this->logger->info( $message, $context );
                    break;
            }
        }
    }

    /**
     * Get available date range options for display
     *
     * @return array
     */
    public static function get_date_range_options() {
        return array(
            'today'         => __( 'Today', 'wp-nalda-sync' ),
            'yesterday'     => __( 'Yesterday', 'wp-nalda-sync' ),
            'current-month' => __( 'Current Month', 'wp-nalda-sync' ),
            'current-year'  => __( 'Current Year', 'wp-nalda-sync' ),
            '3m'            => __( 'Last 3 Months', 'wp-nalda-sync' ),
            '6m'            => __( 'Last 6 Months', 'wp-nalda-sync' ),
            '12m'           => __( 'Last 12 Months', 'wp-nalda-sync' ),
            '24m'           => __( 'Last 24 Months', 'wp-nalda-sync' ),
        );
    }

    /**
     * Map Nalda delivery status to WooCommerce order status
     *
     * @param string $nalda_status Nalda delivery status.
     * @return string WooCommerce order status.
     */
    public static function map_delivery_status_to_wc( $nalda_status ) {
        $map = array(
            'IN_PREPARATION'   => 'processing',
            'IN_DELIVERY'      => 'processing',
            'DELIVERED'        => 'completed',
            'UNDELIVERABLE'    => 'failed',
            'CANCELLED'        => 'cancelled',
            'READY_TO_COLLECT' => 'processing',
            'COLLECTED'        => 'completed',
            'NOT_PICKED_UP'    => 'failed',
            'RETURNED'         => 'refunded',
            'DISPUTE'          => 'on-hold',
        );

        return $map[ $nalda_status ] ?? 'processing';
    }

    /**
     * Map WooCommerce order status to Nalda delivery status
     *
     * @param string $wc_status WooCommerce order status.
     * @return string Nalda delivery status.
     */
    public static function map_wc_status_to_delivery( $wc_status ) {
        $map = array(
            'pending'    => 'IN_PREPARATION',
            'processing' => 'IN_PREPARATION',
            'on-hold'    => 'DISPUTE',
            'completed'  => 'DELIVERED',
            'cancelled'  => 'CANCELLED',
            'refunded'   => 'RETURNED',
            'failed'     => 'UNDELIVERABLE',
        );

        return $map[ $wc_status ] ?? 'IN_PREPARATION';
    }
}
