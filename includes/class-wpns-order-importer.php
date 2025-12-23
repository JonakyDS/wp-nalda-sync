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
     * Meta key for storing order source
     *
     * @var string
     */
    const META_ORDER_SOURCE = '_wpns_order_source';

    /**
     * Constructor
     *
     * @param WPNS_Nalda_API $api    Nalda API instance.
     * @param WPNS_Logger    $logger Logger instance.
     */
    public function __construct( $api, $logger ) {
        $this->api    = $api;
        $this->logger = $logger;

        $this->init_hooks();
    }

    /**
     * Initialize hooks for order display customization
     */
    private function init_hooks() {
        // Add source column to orders list
        add_filter( 'manage_edit-shop_order_columns', array( $this, 'add_order_source_column' ) );
        add_filter( 'manage_woocommerce_page_wc-orders_columns', array( $this, 'add_order_source_column' ) );
        add_action( 'manage_shop_order_posts_custom_column', array( $this, 'render_order_source_column' ), 10, 2 );
        add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( $this, 'render_order_source_column_hpos' ), 10, 2 );

        // Add Nalda info to order details page
        add_action( 'woocommerce_admin_order_data_after_order_details', array( $this, 'display_nalda_order_info' ) );

        // Customize order emails for Nalda orders
        add_action( 'woocommerce_email_order_details', array( $this, 'add_nalda_info_to_email' ), 5, 4 );
        add_filter( 'woocommerce_email_subject_new_order', array( $this, 'customize_email_subject' ), 10, 2 );
        add_filter( 'woocommerce_email_subject_customer_processing_order', array( $this, 'customize_email_subject' ), 10, 2 );
        add_filter( 'woocommerce_email_subject_customer_completed_order', array( $this, 'customize_email_subject' ), 10, 2 );
    }

    /**
     * Add source column to orders list
     *
     * @param array $columns Existing columns.
     * @return array
     */
    public function add_order_source_column( $columns ) {
        $new_columns = array();
        
        foreach ( $columns as $key => $value ) {
            $new_columns[ $key ] = $value;
            
            // Add source column after order status
            if ( 'order_status' === $key ) {
                $new_columns['order_source'] = __( 'Source', 'wp-nalda-sync' );
            }
        }
        
        return $new_columns;
    }

    /**
     * Render source column content (legacy posts)
     *
     * @param string $column  Column name.
     * @param int    $post_id Post ID.
     */
    public function render_order_source_column( $column, $post_id ) {
        if ( 'order_source' !== $column ) {
            return;
        }

        $order = wc_get_order( $post_id );
        $this->output_source_badge( $order );
    }

    /**
     * Render source column content (HPOS)
     *
     * @param string   $column Column name.
     * @param WC_Order $order  Order object.
     */
    public function render_order_source_column_hpos( $column, $order ) {
        if ( 'order_source' !== $column ) {
            return;
        }

        $this->output_source_badge( $order );
    }

    /**
     * Output the source badge
     *
     * @param WC_Order $order Order object.
     */
    private function output_source_badge( $order ) {
        if ( ! $order ) {
            return;
        }

        $source = $order->get_meta( self::META_ORDER_SOURCE );
        $nalda_order_id = $order->get_meta( self::META_NALDA_ORDER_ID );

        if ( 'NALDA' === $source || ! empty( $nalda_order_id ) ) {
            echo '<span class="wpns-source-badge nalda" style="background: #6366f1; color: #fff; padding: 3px 8px; border-radius: 3px; font-size: 11px; font-weight: 600;">NALDA</span>';
        } else {
            echo '<span class="wpns-source-badge website" style="background: #e5e7eb; color: #374151; padding: 3px 8px; border-radius: 3px; font-size: 11px;">Website</span>';
        }
    }

    /**
     * Display Nalda order info in admin order details
     *
     * @param WC_Order $order Order object.
     */
    public function display_nalda_order_info( $order ) {
        $nalda_order_id = $order->get_meta( self::META_NALDA_ORDER_ID );
        
        if ( empty( $nalda_order_id ) ) {
            return;
        }

        $delivery_status = $order->get_meta( self::META_NALDA_DELIVERY_STATUS );
        $payout_status = $order->get_meta( self::META_NALDA_PAYOUT_STATUS );
        $commission = $order->get_meta( self::META_NALDA_COMMISSION );
        $fee = $order->get_meta( self::META_NALDA_FEE );
        ?>
        <div class="order_data_column" style="background: #f0f6fc; padding: 15px; margin-top: 15px; border-radius: 4px;">
            <h4 style="margin: 0 0 10px; color: #6366f1;">
                <span class="dashicons dashicons-store" style="margin-right: 5px;"></span>
                <?php esc_html_e( 'Nalda Marketplace Order', 'wp-nalda-sync' ); ?>
            </h4>
            <p><strong><?php esc_html_e( 'Nalda Order ID:', 'wp-nalda-sync' ); ?></strong> <?php echo esc_html( $nalda_order_id ); ?></p>
            <p><strong><?php esc_html_e( 'Delivery Status:', 'wp-nalda-sync' ); ?></strong> <?php echo esc_html( $delivery_status ); ?></p>
            <p><strong><?php esc_html_e( 'Payout Status:', 'wp-nalda-sync' ); ?></strong> <?php echo esc_html( $payout_status ); ?></p>
            <?php if ( $commission ) : ?>
                <p><strong><?php esc_html_e( 'Commission:', 'wp-nalda-sync' ); ?></strong> <?php echo wc_price( $commission ); ?></p>
            <?php endif; ?>
            <?php if ( $fee ) : ?>
                <p><strong><?php esc_html_e( 'Fee:', 'wp-nalda-sync' ); ?></strong> <?php echo wc_price( $fee ); ?></p>
            <?php endif; ?>
            <p style="margin-top: 10px; padding-top: 10px; border-top: 1px solid #c3c4c7; color: #059669; font-weight: 600;">
                <span class="dashicons dashicons-yes-alt"></span>
                <?php esc_html_e( 'Payment received via Nalda', 'wp-nalda-sync' ); ?>
            </p>
        </div>
        <?php
    }

    /**
     * Add Nalda info to customer emails
     *
     * @param WC_Order $order         Order object.
     * @param bool     $sent_to_admin Whether email is sent to admin.
     * @param bool     $plain_text    Whether email is plain text.
     * @param WC_Email $email         Email object.
     */
    public function add_nalda_info_to_email( $order, $sent_to_admin, $plain_text, $email ) {
        $nalda_order_id = $order->get_meta( self::META_NALDA_ORDER_ID );
        
        if ( empty( $nalda_order_id ) ) {
            return;
        }

        $shop_name = get_bloginfo( 'name' );

        if ( $plain_text ) {
            echo "\n\n";
            echo "========================================\n";
            echo sprintf( __( 'This order was placed at %s via NALDA Marketplace.', 'wp-nalda-sync' ), $shop_name ) . "\n";
            echo sprintf( __( 'Nalda Order Number: %s', 'wp-nalda-sync' ), $nalda_order_id ) . "\n";
            echo __( 'Payment Status: PAID (Payment processed via Nalda)', 'wp-nalda-sync' ) . "\n";
            echo "========================================\n\n";
        } else {
            ?>
            <div style="background: #f0f6fc; border: 1px solid #6366f1; border-radius: 6px; padding: 20px; margin: 20px 0; text-align: center;">
                <p style="margin: 0 0 10px; font-size: 14px; color: #374151;">
                    <?php printf( esc_html__( 'This order was placed at %s via', 'wp-nalda-sync' ), esc_html( $shop_name ) ); ?>
                    <strong style="color: #6366f1;">NALDA Marketplace</strong>
                </p>
                <p style="margin: 0 0 10px; font-size: 16px; font-weight: 600; color: #1f2937;">
                    <?php printf( esc_html__( 'Nalda Order Number: %s', 'wp-nalda-sync' ), esc_html( $nalda_order_id ) ); ?>
                </p>
                <p style="margin: 0; color: #059669; font-weight: 600;">
                    ✓ <?php esc_html_e( 'Payment Status: PAID', 'wp-nalda-sync' ); ?>
                </p>
                <p style="margin: 5px 0 0; font-size: 12px; color: #6b7280;">
                    <?php esc_html_e( '(Payment was processed securely via Nalda)', 'wp-nalda-sync' ); ?>
                </p>
            </div>
            <?php
        }
    }

    /**
     * Customize email subject for Nalda orders
     *
     * @param string   $subject Email subject.
     * @param WC_Order $order   Order object.
     * @return string
     */
    public function customize_email_subject( $subject, $order ) {
        $nalda_order_id = $order->get_meta( self::META_NALDA_ORDER_ID );
        
        if ( ! empty( $nalda_order_id ) ) {
            $subject = '[NALDA] ' . $subject;
        }
        
        return $subject;
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
            $order->update_meta_data( self::META_ORDER_SOURCE, 'NALDA' );
            $order->update_meta_data( self::META_NALDA_ORDER_ID, $nalda_order['orderId'] );
            $order->update_meta_data( self::META_NALDA_SYNCED_AT, current_time( 'mysql' ) );
            $order->update_meta_data( self::META_NALDA_DELIVERY_STATUS, $delivery_status );
            $order->update_meta_data( self::META_NALDA_PAYOUT_STATUS, $nalda_order['payoutStatus'] ?? '' );
            $order->update_meta_data( self::META_NALDA_COMMISSION, $nalda_order['commission'] ?? 0 );
            $order->update_meta_data( self::META_NALDA_FEE, $nalda_order['fee'] ?? 0 );
            $order->update_meta_data( '_paid_date', current_time( 'mysql' ) );

            // Set payment method info
            $order->set_payment_method( 'nalda' );
            $order->set_payment_method_title( __( 'Paid via Nalda Marketplace', 'wp-nalda-sync' ) );

            // Add collection info if available
            if ( ! empty( $nalda_order['collectionId'] ) ) {
                $order->update_meta_data( '_wpns_nalda_collection_id', $nalda_order['collectionId'] );
                $order->update_meta_data( '_wpns_nalda_collection_name', $nalda_order['collectionName'] ?? '' );
            }

            // Add refund info if available
            if ( ! empty( $nalda_order['refund'] ) && $nalda_order['refund'] > 0 ) {
                $order->update_meta_data( '_wpns_nalda_refund', $nalda_order['refund'] );
            }

            // Nalda prices already include tax, so we skip tax calculation
            // Just recalculate shipping and totals without adding tax
            $order->set_prices_include_tax( true );
            $order->calculate_totals( false ); // false = don't recalculate taxes

            // Add order note
            $shop_name = get_bloginfo( 'name' );
            $order->add_order_note( 
                sprintf( 
                    /* translators: 1: shop name, 2: Nalda order ID */
                    __( 'Order placed at %1$s via NALDA Marketplace. Nalda Order ID: %2$d. Payment Status: PAID (processed via Nalda).', 'wp-nalda-sync' ), 
                    $shop_name,
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

        $quantity = $item_data['quantity'] ?? 1;
        $price = $item_data['price'] ?? 0;

        // Nalda API returns prices with tax included, so we use the price as-is
        // and don't let WooCommerce add additional tax
        if ( $product ) {
            // Add as linked product
            $item = new WC_Order_Item_Product();
            $item->set_product( $product );
            $item->set_quantity( $quantity );
            $item->set_subtotal( $price );
            $item->set_total( $price );
        } else {
            // Add as custom line item
            $item = new WC_Order_Item_Product();
            $item->set_name( $item_data['title'] ?? __( 'Nalda Item', 'wp-nalda-sync' ) );
            $item->set_quantity( $quantity );
            $item->set_subtotal( $price );
            $item->set_total( $price );
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
