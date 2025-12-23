<?php
/**
 * Order Importer class for WP Nalda Sync
 *
 * @package WP_Nalda_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Order Importer class
 * 
 * Handles importing and syncing orders from Nalda Marketplace to WooCommerce
 */
class WPNS_Order_Importer {

    /**
     * Nalda API instance
     *
     * @var WPNS_Nalda_API
     */
    private $api;

    /**
     * Logger instance
     *
     * @var WPNS_Logger
     */
    private $logger;

    /**
     * Meta key for storing Nalda order ID
     *
     * @var string
     */
    const META_NALDA_ORDER_ID = '_wpns_nalda_order_id';

    /**
     * Meta key for storing Nalda sync timestamp
     *
     * @var string
     */
    const META_NALDA_SYNCED_AT = '_wpns_nalda_synced_at';

    /**
     * Meta key for storing Nalda delivery status
     *
     * @var string
     */
    const META_NALDA_DELIVERY_STATUS = '_wpns_nalda_delivery_status';

    /**
     * Meta key for storing Nalda payout status
     *
     * @var string
     */
    const META_NALDA_PAYOUT_STATUS = '_wpns_nalda_payout_status';

    /**
     * Meta key for storing Nalda commission
     *
     * @var string
     */
    const META_NALDA_COMMISSION = '_wpns_nalda_commission';

    /**
     * Meta key for storing Nalda fee
     *
     * @var string
     */
    const META_NALDA_FEE = '_wpns_nalda_fee';

    /**
     * Constructor
     *
     * @param WPNS_Nalda_API $api    Nalda API instance.
     * @param WPNS_Logger    $logger Logger instance.
     */
    public function __construct( $api, $logger ) {
        $this->api    = $api;
        $this->logger = $logger;
    }

    /**
     * Import orders from Nalda
     *
     * @param string      $range Date range for fetching orders.
     * @param string|null $from  From date for custom range.
     * @param string|null $to    To date for custom range.
     * @return array Import result with statistics.
     */
    public function import_orders( $range = 'today', $from = null, $to = null ) {
        $start_time = microtime( true );
        
        $stats = array(
            'fetched'  => 0,
            'imported' => 0,
            'updated'  => 0,
            'skipped'  => 0,
            'errors'   => 0,
        );

        if ( ! $this->api->is_configured() ) {
            $this->logger->error( __( 'Nalda API is not configured. Cannot import orders.', 'wp-nalda-sync' ) );
            return array(
                'success' => false,
                'message' => __( 'Nalda API key is not configured.', 'wp-nalda-sync' ),
                'stats'   => $stats,
            );
        }

        $this->logger->info( sprintf( __( 'Starting order import from Nalda (range: %s)', 'wp-nalda-sync' ), $range ) );

        // Fetch orders from Nalda API
        $result = $this->api->get_orders_with_items( $range, $from, $to );

        if ( is_wp_error( $result ) ) {
            $this->logger->error( __( 'Failed to fetch orders from Nalda API: ', 'wp-nalda-sync' ) . $result->get_error_message() );
            return array(
                'success' => false,
                'message' => $result->get_error_message(),
                'stats'   => $stats,
            );
        }

        $orders = $result['orders'] ?? array();
        $items  = $result['items'] ?? array();

        $stats['fetched'] = count( $orders );

        $this->logger->info( sprintf( __( 'Fetched %d orders and %d items from Nalda', 'wp-nalda-sync' ), count( $orders ), count( $items ) ) );

        if ( empty( $orders ) ) {
            return array(
                'success' => true,
                'message' => __( 'No orders found to import.', 'wp-nalda-sync' ),
                'stats'   => $stats,
            );
        }

        // Group items by order ID
        $items_by_order = array();
        foreach ( $items as $item ) {
            $order_id = $item['orderId'];
            if ( ! isset( $items_by_order[ $order_id ] ) ) {
                $items_by_order[ $order_id ] = array();
            }
            $items_by_order[ $order_id ][] = $item;
        }

        // Process each order
        foreach ( $orders as $nalda_order ) {
            $nalda_order_id = $nalda_order['orderId'];
            $order_items    = $items_by_order[ $nalda_order_id ] ?? array();

            $import_result = $this->process_order( $nalda_order, $order_items );

            if ( is_wp_error( $import_result ) ) {
                $stats['errors']++;
                $this->logger->error( sprintf( 
                    __( 'Failed to import order #%d: %s', 'wp-nalda-sync' ), 
                    $nalda_order_id,
                    $import_result->get_error_message() 
                ) );
                continue;
            }

            switch ( $import_result['action'] ) {
                case 'created':
                    $stats['imported']++;
                    break;
                case 'updated':
                    $stats['updated']++;
                    break;
                case 'skipped':
                    $stats['skipped']++;
                    break;
            }
        }

        $duration = round( microtime( true ) - $start_time, 2 );

        $message = sprintf(
            __( 'Order import completed. Fetched: %d, Imported: %d, Updated: %d, Skipped: %d, Errors: %d. Duration: %s seconds.', 'wp-nalda-sync' ),
            $stats['fetched'],
            $stats['imported'],
            $stats['updated'],
            $stats['skipped'],
            $stats['errors'],
            $duration
        );

        $this->logger->success( $message );

        return array(
            'success'  => true,
            'message'  => $message,
            'stats'    => $stats,
            'duration' => $duration,
        );
    }

    /**
     * Process a single order
     *
     * @param array $nalda_order Order data from Nalda.
     * @param array $order_items Order items data from Nalda.
     * @return array|WP_Error Result with action taken or error.
     */
    private function process_order( $nalda_order, $order_items ) {
        $nalda_order_id = $nalda_order['orderId'];

        // Check if order already exists
        $existing_order = $this->find_order_by_nalda_id( $nalda_order_id );

        if ( $existing_order ) {
            // Update existing order
            return $this->update_order( $existing_order, $nalda_order, $order_items );
        }

        // Check settings for import behavior
        $settings = get_option( 'wpns_settings', array() );
        $import_mode = $settings['order_import_mode'] ?? 'all';

        // If mode is 'sync_only', skip creating new orders
        if ( 'sync_only' === $import_mode ) {
            return array( 'action' => 'skipped', 'reason' => 'sync_only_mode' );
        }

        // Create new order
        return $this->create_order( $nalda_order, $order_items );
    }

    /**
     * Find WooCommerce order by Nalda order ID
     *
     * @param int $nalda_order_id Nalda order ID.
     * @return WC_Order|null
     */
    public function find_order_by_nalda_id( $nalda_order_id ) {
        $orders = wc_get_orders( array(
            'meta_key'   => self::META_NALDA_ORDER_ID,
            'meta_value' => $nalda_order_id,
            'limit'      => 1,
        ) );

        return ! empty( $orders ) ? $orders[0] : null;
    }

    /**
     * Create a new WooCommerce order from Nalda data
     *
     * @param array $nalda_order Nalda order data.
     * @param array $order_items Nalda order items.
     * @return array|WP_Error
     */
    private function create_order( $nalda_order, $order_items ) {
        try {
            $order = wc_create_order();

            if ( is_wp_error( $order ) ) {
                return $order;
            }

            // Set billing address
            $order->set_billing_first_name( $nalda_order['firstName'] ?? '' );
            $order->set_billing_last_name( $nalda_order['lastName'] ?? '' );
            $order->set_billing_email( $nalda_order['email'] ?? '' );
            $order->set_billing_address_1( $nalda_order['street1'] ?? '' );
            $order->set_billing_city( $nalda_order['city'] ?? '' );
            $order->set_billing_postcode( $nalda_order['postalCode'] ?? '' );
            $order->set_billing_country( $nalda_order['country'] ?? '' );

            // Set shipping address (same as billing)
            $order->set_shipping_first_name( $nalda_order['firstName'] ?? '' );
            $order->set_shipping_last_name( $nalda_order['lastName'] ?? '' );
            $order->set_shipping_address_1( $nalda_order['street1'] ?? '' );
            $order->set_shipping_city( $nalda_order['city'] ?? '' );
            $order->set_shipping_postcode( $nalda_order['postalCode'] ?? '' );
            $order->set_shipping_country( $nalda_order['country'] ?? '' );

            // Set order date
            if ( ! empty( $nalda_order['createdAt'] ) ) {
                $order->set_date_created( strtotime( $nalda_order['createdAt'] ) );
            }

            // Add order items
            foreach ( $order_items as $item_data ) {
                $this->add_order_item( $order, $item_data );
            }

            // Determine order status based on first item's delivery status
            $delivery_status = ! empty( $order_items[0]['deliveryStatus'] ) 
                ? $order_items[0]['deliveryStatus'] 
                : 'IN_PREPARATION';
            
            $wc_status = WPNS_Nalda_API::map_delivery_status_to_wc( $delivery_status );
            $order->set_status( $wc_status );

            // Store Nalda metadata
            $order->update_meta_data( self::META_NALDA_ORDER_ID, $nalda_order['orderId'] );
            $order->update_meta_data( self::META_NALDA_SYNCED_AT, current_time( 'mysql' ) );
            $order->update_meta_data( self::META_NALDA_DELIVERY_STATUS, $delivery_status );
            $order->update_meta_data( self::META_NALDA_PAYOUT_STATUS, $nalda_order['payoutStatus'] ?? '' );
            $order->update_meta_data( self::META_NALDA_COMMISSION, $nalda_order['commission'] ?? 0 );
            $order->update_meta_data( self::META_NALDA_FEE, $nalda_order['fee'] ?? 0 );

            // Add collection info if available
            if ( ! empty( $nalda_order['collectionId'] ) ) {
                $order->update_meta_data( '_wpns_nalda_collection_id', $nalda_order['collectionId'] );
                $order->update_meta_data( '_wpns_nalda_collection_name', $nalda_order['collectionName'] ?? '' );
            }

            // Add refund info if available
            if ( ! empty( $nalda_order['refund'] ) && $nalda_order['refund'] > 0 ) {
                $order->update_meta_data( '_wpns_nalda_refund', $nalda_order['refund'] );
            }

            // Calculate totals
            $order->calculate_totals();

            // Add order note
            $order->add_order_note( 
                sprintf( 
                    __( 'Order imported from Nalda Marketplace. Nalda Order ID: %d', 'wp-nalda-sync' ), 
                    $nalda_order['orderId'] 
                ),
                0, // Not customer note
                true // Added by system
            );

            $order->save();

            $this->logger->info( sprintf( 
                __( 'Created WooCommerce order #%d from Nalda order #%d', 'wp-nalda-sync' ), 
                $order->get_id(),
                $nalda_order['orderId']
            ) );

            return array(
                'action'   => 'created',
                'order_id' => $order->get_id(),
            );

        } catch ( Exception $e ) {
            return new WP_Error( 'order_creation_failed', $e->getMessage() );
        }
    }

    /**
     * Update an existing WooCommerce order with Nalda data
     *
     * @param WC_Order $order       Existing WooCommerce order.
     * @param array    $nalda_order Nalda order data.
     * @param array    $order_items Nalda order items.
     * @return array|WP_Error
     */
    private function update_order( $order, $nalda_order, $order_items ) {
        try {
            $changes = array();

            // Check and update delivery status
            $current_delivery_status = $order->get_meta( self::META_NALDA_DELIVERY_STATUS );
            $new_delivery_status = ! empty( $order_items[0]['deliveryStatus'] ) 
                ? $order_items[0]['deliveryStatus'] 
                : 'IN_PREPARATION';

            if ( $current_delivery_status !== $new_delivery_status ) {
                $order->update_meta_data( self::META_NALDA_DELIVERY_STATUS, $new_delivery_status );
                
                // Update WooCommerce order status
                $new_wc_status = WPNS_Nalda_API::map_delivery_status_to_wc( $new_delivery_status );
                $order->set_status( $new_wc_status );
                
                $changes[] = sprintf( 
                    __( 'Delivery status: %s → %s', 'wp-nalda-sync' ), 
                    $current_delivery_status, 
                    $new_delivery_status 
                );
            }

            // Check and update payout status
            $current_payout_status = $order->get_meta( self::META_NALDA_PAYOUT_STATUS );
            $new_payout_status = $nalda_order['payoutStatus'] ?? '';

            if ( $current_payout_status !== $new_payout_status ) {
                $order->update_meta_data( self::META_NALDA_PAYOUT_STATUS, $new_payout_status );
                $changes[] = sprintf( 
                    __( 'Payout status: %s → %s', 'wp-nalda-sync' ), 
                    $current_payout_status, 
                    $new_payout_status 
                );
            }

            // Update commission and fee
            $order->update_meta_data( self::META_NALDA_COMMISSION, $nalda_order['commission'] ?? 0 );
            $order->update_meta_data( self::META_NALDA_FEE, $nalda_order['fee'] ?? 0 );

            // Update refund if changed
            if ( ! empty( $nalda_order['refund'] ) && $nalda_order['refund'] > 0 ) {
                $current_refund = $order->get_meta( '_wpns_nalda_refund' );
                if ( floatval( $current_refund ) !== floatval( $nalda_order['refund'] ) ) {
                    $order->update_meta_data( '_wpns_nalda_refund', $nalda_order['refund'] );
                    $changes[] = sprintf( 
                        __( 'Refund amount: %s', 'wp-nalda-sync' ), 
                        wc_price( $nalda_order['refund'] ) 
                    );
                }
            }

            // Update sync timestamp
            $order->update_meta_data( self::META_NALDA_SYNCED_AT, current_time( 'mysql' ) );

            // Add order note if there were changes
            if ( ! empty( $changes ) ) {
                $order->add_order_note(
                    sprintf(
                        __( 'Order updated from Nalda sync. Changes: %s', 'wp-nalda-sync' ),
                        implode( '; ', $changes )
                    ),
                    0,
                    true
                );
            }

            $order->save();

            if ( ! empty( $changes ) ) {
                $this->logger->info( sprintf( 
                    __( 'Updated WooCommerce order #%d from Nalda order #%d', 'wp-nalda-sync' ), 
                    $order->get_id(),
                    $nalda_order['orderId']
                ) );
            }

            return array(
                'action'   => ! empty( $changes ) ? 'updated' : 'skipped',
                'order_id' => $order->get_id(),
                'changes'  => $changes,
            );

        } catch ( Exception $e ) {
            return new WP_Error( 'order_update_failed', $e->getMessage() );
        }
    }

    /**
     * Add an order item to the order
     *
     * @param WC_Order $order     WooCommerce order.
     * @param array    $item_data Item data from Nalda.
     */
    private function add_order_item( $order, $item_data ) {
        // Try to find matching product by GTIN/SKU
        $product = $this->find_product_by_gtin( $item_data['gtin'] ?? '' );

        if ( $product ) {
            // Add as linked product
            $item = new WC_Order_Item_Product();
            $item->set_product( $product );
            $item->set_quantity( $item_data['quantity'] ?? 1 );
            $item->set_subtotal( $item_data['price'] ?? 0 );
            $item->set_total( $item_data['price'] ?? 0 );
        } else {
            // Add as custom line item
            $item = new WC_Order_Item_Product();
            $item->set_name( $item_data['title'] ?? __( 'Nalda Item', 'wp-nalda-sync' ) );
            $item->set_quantity( $item_data['quantity'] ?? 1 );
            $item->set_subtotal( $item_data['price'] ?? 0 );
            $item->set_total( $item_data['price'] ?? 0 );
        }

        // Store Nalda item metadata
        $item->add_meta_data( '_nalda_gtin', $item_data['gtin'] ?? '' );
        $item->add_meta_data( '_nalda_condition', $item_data['condition'] ?? '' );
        $item->add_meta_data( '_nalda_delivery_status', $item_data['deliveryStatus'] ?? '' );
        $item->add_meta_data( '_nalda_delivery_date_planned', $item_data['deliveryDatePlanned'] ?? '' );

        $order->add_item( $item );
    }

    /**
     * Find product by GTIN (EAN/UPC)
     *
     * @param string $gtin GTIN to search for.
     * @return WC_Product|null
     */
    private function find_product_by_gtin( $gtin ) {
        if ( empty( $gtin ) ) {
            return null;
        }

        // Search by SKU first
        $product_id = wc_get_product_id_by_sku( $gtin );
        
        if ( $product_id ) {
            return wc_get_product( $product_id );
        }

        // Search by GTIN/EAN meta field (common meta keys)
        $meta_keys = array( '_gtin', '_ean', '_upc', '_global_unique_id', 'gtin', 'ean', 'upc' );
        
        foreach ( $meta_keys as $meta_key ) {
            $products = wc_get_products( array(
                'meta_key'   => $meta_key,
                'meta_value' => $gtin,
                'limit'      => 1,
            ) );

            if ( ! empty( $products ) ) {
                return $products[0];
            }
        }

        return null;
    }

    /**
     * Get import statistics from last sync
     *
     * @return array
     */
    public function get_last_import_stats() {
        return get_option( 'wpns_last_order_import', array() );
    }

    /**
     * Save import statistics
     *
     * @param array $stats Import statistics.
     */
    public function save_import_stats( $stats ) {
        $stats['timestamp'] = current_time( 'mysql' );
        update_option( 'wpns_last_order_import', $stats );
    }

    /**
     * Get all orders imported from Nalda
     *
     * @param int $limit  Number of orders to fetch.
     * @param int $offset Offset for pagination.
     * @return array WooCommerce orders.
     */
    public function get_imported_orders( $limit = 20, $offset = 0 ) {
        return wc_get_orders( array(
            'meta_key'   => self::META_NALDA_ORDER_ID,
            'meta_compare' => 'EXISTS',
            'limit'      => $limit,
            'offset'     => $offset,
            'orderby'    => 'date',
            'order'      => 'DESC',
        ) );
    }

    /**
     * Count all orders imported from Nalda
     *
     * @return int
     */
    public function count_imported_orders() {
        global $wpdb;

        // For HPOS compatibility
        if ( class_exists( 'Automattic\WooCommerce\Utilities\OrderUtil' ) 
            && Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
            
            $meta_table = $wpdb->prefix . 'wc_orders_meta';
            return (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(DISTINCT order_id) FROM {$meta_table} WHERE meta_key = %s",
                self::META_NALDA_ORDER_ID
            ) );
        }

        // Legacy post meta
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = %s",
            self::META_NALDA_ORDER_ID
        ) );
    }
}
