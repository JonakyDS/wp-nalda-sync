<?php
/**
 * Product Settings class for WP Nalda Sync
 *
 * Handles per-product Nalda sync settings (checkbox to include/exclude products)
 *
 * @package WP_Nalda_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Product Settings class
 */
class WPNS_Product_Settings {

    /**
     * Meta key for Nalda sync setting
     *
     * @var string
     */
    const META_NALDA_SYNC = '_wpns_sync_to_nalda';

    /**
     * Constructor
     */
    public function __construct() {
        $this->init_hooks();
    }

    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // Add checkbox to product general tab
        add_action( 'woocommerce_product_options_general_product_data', array( $this, 'add_sync_checkbox' ) );
        add_action( 'woocommerce_process_product_meta', array( $this, 'save_sync_checkbox' ) );

        // Add checkbox to quick edit
        add_action( 'woocommerce_product_quick_edit_end', array( $this, 'add_quick_edit_checkbox' ) );
        add_action( 'woocommerce_product_quick_edit_save', array( $this, 'save_quick_edit_checkbox' ) );

        // Add column to products list
        add_filter( 'manage_edit-product_columns', array( $this, 'add_nalda_column' ), 20 );
        add_action( 'manage_product_posts_custom_column', array( $this, 'render_nalda_column' ), 10, 2 );

        // Bulk action to enable/disable sync
        add_filter( 'bulk_actions-edit-product', array( $this, 'add_bulk_actions' ) );
        add_filter( 'handle_bulk_actions-edit-product', array( $this, 'handle_bulk_actions' ), 10, 3 );

        // Add variation sync checkbox
        add_action( 'woocommerce_product_after_variable_attributes', array( $this, 'add_variation_sync_checkbox' ), 10, 3 );
        add_action( 'woocommerce_save_product_variation', array( $this, 'save_variation_sync_checkbox' ), 10, 2 );
    }

    /**
     * Add sync checkbox to product general tab
     */
    public function add_sync_checkbox() {
        global $post;

        echo '<div class="options_group">';

        woocommerce_wp_checkbox(
            array(
                'id'            => self::META_NALDA_SYNC,
                'label'         => __( 'Sync to Nalda', 'wp-nalda-sync' ),
                'description'   => __( 'Include this product in Nalda Marketplace sync', 'wp-nalda-sync' ),
                'value'         => $this->is_product_synced( $post->ID ) ? 'yes' : 'no',
                'cbvalue'       => 'yes',
                'wrapper_class' => 'wpns-nalda-sync-checkbox',
            )
        );

        echo '</div>';
    }

    /**
     * Save sync checkbox
     *
     * @param int $post_id Post ID.
     */
    public function save_sync_checkbox( $post_id ) {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $sync_enabled = isset( $_POST[ self::META_NALDA_SYNC ] ) ? 'yes' : 'no';
        update_post_meta( $post_id, self::META_NALDA_SYNC, $sync_enabled );
    }

    /**
     * Add quick edit checkbox
     */
    public function add_quick_edit_checkbox() {
        ?>
        <div class="inline-edit-group">
            <label class="alignleft">
                <input type="checkbox" name="<?php echo esc_attr( self::META_NALDA_SYNC ); ?>" value="yes">
                <span class="checkbox-title"><?php esc_html_e( 'Sync to Nalda', 'wp-nalda-sync' ); ?></span>
            </label>
        </div>
        <?php
    }

    /**
     * Save quick edit checkbox
     *
     * @param WC_Product $product Product object.
     */
    public function save_quick_edit_checkbox( $product ) {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if ( isset( $_REQUEST[ self::META_NALDA_SYNC ] ) ) {
            $product->update_meta_data( self::META_NALDA_SYNC, 'yes' );
        } else {
            $product->update_meta_data( self::META_NALDA_SYNC, 'no' );
        }
    }

    /**
     * Add Nalda column to products list
     *
     * @param array $columns Existing columns.
     * @return array
     */
    public function add_nalda_column( $columns ) {
        $new_columns = array();
        
        foreach ( $columns as $key => $value ) {
            $new_columns[ $key ] = $value;
            
            // Add after the product name column
            if ( 'name' === $key ) {
                $new_columns['nalda_sync'] = '<span class="dashicons dashicons-store" title="' . esc_attr__( 'Nalda Sync', 'wp-nalda-sync' ) . '"></span>';
            }
        }
        
        return $new_columns;
    }

    /**
     * Render Nalda column content
     *
     * @param string $column  Column name.
     * @param int    $post_id Post ID.
     */
    public function render_nalda_column( $column, $post_id ) {
        if ( 'nalda_sync' !== $column ) {
            return;
        }

        if ( $this->is_product_synced( $post_id ) ) {
            echo '<span class="dashicons dashicons-yes-alt" style="color: #059669;" title="' . esc_attr__( 'Will sync to Nalda', 'wp-nalda-sync' ) . '"></span>';
        } else {
            echo '<span class="dashicons dashicons-minus" style="color: #9ca3af;" title="' . esc_attr__( 'Not syncing to Nalda', 'wp-nalda-sync' ) . '"></span>';
        }
    }

    /**
     * Add bulk actions
     *
     * @param array $actions Existing bulk actions.
     * @return array
     */
    public function add_bulk_actions( $actions ) {
        $actions['wpns_enable_nalda_sync']  = __( 'Enable Nalda Sync', 'wp-nalda-sync' );
        $actions['wpns_disable_nalda_sync'] = __( 'Disable Nalda Sync', 'wp-nalda-sync' );
        return $actions;
    }

    /**
     * Handle bulk actions
     *
     * @param string $redirect_url Redirect URL.
     * @param string $action       Action name.
     * @param array  $post_ids     Post IDs.
     * @return string
     */
    public function handle_bulk_actions( $redirect_url, $action, $post_ids ) {
        if ( 'wpns_enable_nalda_sync' === $action ) {
            foreach ( $post_ids as $post_id ) {
                update_post_meta( $post_id, self::META_NALDA_SYNC, 'yes' );
            }
            $redirect_url = add_query_arg( 'wpns_bulk_updated', count( $post_ids ), $redirect_url );
        }

        if ( 'wpns_disable_nalda_sync' === $action ) {
            foreach ( $post_ids as $post_id ) {
                update_post_meta( $post_id, self::META_NALDA_SYNC, 'no' );
            }
            $redirect_url = add_query_arg( 'wpns_bulk_updated', count( $post_ids ), $redirect_url );
        }

        return $redirect_url;
    }

    /**
     * Add variation sync checkbox
     *
     * @param int     $loop           Variation loop index.
     * @param array   $variation_data Variation data.
     * @param WP_Post $variation      Variation post object.
     */
    public function add_variation_sync_checkbox( $loop, $variation_data, $variation ) {
        woocommerce_wp_checkbox(
            array(
                'id'            => self::META_NALDA_SYNC . '_' . $loop,
                'name'          => self::META_NALDA_SYNC . '[' . $loop . ']',
                'label'         => __( 'Sync to Nalda', 'wp-nalda-sync' ),
                'description'   => __( 'Include this variation in Nalda sync', 'wp-nalda-sync' ),
                'value'         => $this->is_product_synced( $variation->ID ) ? 'yes' : 'no',
                'cbvalue'       => 'yes',
                'wrapper_class' => 'form-row form-row-full',
            )
        );
    }

    /**
     * Save variation sync checkbox
     *
     * @param int $variation_id Variation ID.
     * @param int $loop         Variation loop index.
     */
    public function save_variation_sync_checkbox( $variation_id, $loop ) {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $sync_enabled = isset( $_POST[ self::META_NALDA_SYNC ][ $loop ] ) ? 'yes' : 'no';
        update_post_meta( $variation_id, self::META_NALDA_SYNC, $sync_enabled );
    }

    /**
     * Check if a product is set to sync to Nalda
     *
     * @param int $product_id Product ID.
     * @return bool
     */
    public function is_product_synced( $product_id ) {
        $settings = get_option( 'wpns_settings', array() );
        $default_sync = isset( $settings['product_sync_default'] ) ? $settings['product_sync_default'] : 'all';

        $meta_value = get_post_meta( $product_id, self::META_NALDA_SYNC, true );

        // If no meta set, use default
        if ( '' === $meta_value ) {
            return 'all' === $default_sync;
        }

        return 'yes' === $meta_value;
    }

    /**
     * Get all product IDs that should sync to Nalda
     *
     * @return array
     */
    public function get_syncable_product_ids() {
        $settings = get_option( 'wpns_settings', array() );
        $default_sync = isset( $settings['product_sync_default'] ) ? $settings['product_sync_default'] : 'all';

        $args = array(
            'status'  => 'publish',
            'limit'   => -1,
            'return'  => 'ids',
            'orderby' => 'ID',
            'order'   => 'ASC',
        );

        $all_products = wc_get_products( $args );
        $syncable = array();

        foreach ( $all_products as $product_id ) {
            if ( $this->is_product_synced( $product_id ) ) {
                $syncable[] = $product_id;
            }
        }

        return $syncable;
    }

    /**
     * Enable Nalda sync for all products
     */
    public function enable_all_products() {
        $args = array(
            'status'  => 'publish',
            'limit'   => -1,
            'return'  => 'ids',
        );

        $products = wc_get_products( $args );
        
        foreach ( $products as $product_id ) {
            update_post_meta( $product_id, self::META_NALDA_SYNC, 'yes' );
        }

        return count( $products );
    }

    /**
     * Disable Nalda sync for all products
     */
    public function disable_all_products() {
        $args = array(
            'status'  => 'publish',
            'limit'   => -1,
            'return'  => 'ids',
        );

        $products = wc_get_products( $args );
        
        foreach ( $products as $product_id ) {
            update_post_meta( $product_id, self::META_NALDA_SYNC, 'no' );
        }

        return count( $products );
    }
}
