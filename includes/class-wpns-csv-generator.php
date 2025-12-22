<?php
/**
 * CSV Generator class for WP Nalda Sync
 *
 * @package WP_Nalda_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * CSV Generator class
 */
class WPNS_CSV_Generator {

    /**
     * Logger instance
     *
     * @var WPNS_Logger
     */
    private $logger;

    /**
     * CSV Headers matching the template format
     *
     * @var array
     */
    private $headers = array(
        'gtin',
        'title',
        'country',
        'condition',
        'price',
        'tax',
        'currency',
        'delivery_time_days',
        'stock',
        'return_days',
        'main_image_url',
        'brand',
        'category',
        'google_category',
        'seller_category',
        'description',
        'length_mm',
        'width_mm',
        'height_mm',
        'weight_g',
        'shipping_length_mm',
        'shipping_width_mm',
        'shipping_height_mm',
        'shipping_weight_g',
        'volume_ml',
        'size',
        'colour',
        'image_2_url',
        'image_3_url',
        'image_4_url',
        'image_5_url',
        'delete_product',
        'author',
        'language',
        'format',
        'year',
        'publisher',
    );

    /**
     * WooCommerce settings cache
     *
     * @var array
     */
    private $wc_settings = array();

    /**
     * Plugin settings cache
     *
     * @var array
     */
    private $plugin_settings = array();

    /**
     * Products skipped count
     *
     * @var int
     */
    private $skipped_count = 0;

    /**
     * Skipped products details
     *
     * @var array
     */
    private $skipped_products = array();

    /**
     * Constructor
     *
     * @param WPNS_Logger $logger Logger instance.
     */
    public function __construct( $logger ) {
        $this->logger = $logger;
    }

    /**
     * Generate CSV file
     *
     * @return array Result array with success status, message, and file info.
     */
    public function generate() {
        $this->logger->info( __( 'Starting CSV generation', 'wp-nalda-sync' ) );

        // Reset counters
        $this->skipped_count    = 0;
        $this->skipped_products = array();

        // Load settings
        $this->load_settings();

        // Get products
        $products = $this->get_products();

        if ( empty( $products ) ) {
            $message = __( 'No products found to export.', 'wp-nalda-sync' );
            $this->logger->warning( $message );
            return array(
                'success' => false,
                'message' => $message,
            );
        }

        // Generate filename
        $filename = $this->generate_filename();
        
        // Try multiple temp directory options
        $temp_dirs = array(
            get_temp_dir(),
            sys_get_temp_dir(),
            WP_CONTENT_DIR . '/uploads/wpns-temp/',
        );

        $filepath = null;
        $handle   = null;
        $temp_dir = null;

        foreach ( $temp_dirs as $dir ) {
            // Ensure trailing slash
            $dir = trailingslashit( $dir );
            
            // Create directory if it doesn't exist (for uploads/wpns-temp)
            if ( ! is_dir( $dir ) ) {
                wp_mkdir_p( $dir );
            }

            if ( ! is_dir( $dir ) || ! is_writable( $dir ) ) {
                continue;
            }

            $test_filepath = $dir . $filename;
            
            // Suppress warnings and capture the error
            $handle = @fopen( $test_filepath, 'w' );
            if ( $handle ) {
                $filepath = $test_filepath;
                $temp_dir = $dir;
                break;
            }
        }

        if ( ! $handle ) {
            $message = __( 'Failed to create CSV file. Please check server permissions.', 'wp-nalda-sync' );
            $this->logger->error( $message, array(
                'filename'          => $filename,
                'attempted_dirs'    => $temp_dirs,
                'wp_temp_dir'       => get_temp_dir(),
                'sys_temp_dir'      => sys_get_temp_dir(),
                'upload_dir'        => WP_CONTENT_DIR . '/uploads/wpns-temp/',
                'open_basedir'      => ini_get( 'open_basedir' ),
                'last_error'        => error_get_last(),
            ) );
            return array(
                'success' => false,
                'message' => $message,
            );
        }

        // Add UTF-8 BOM for Excel compatibility
        fwrite( $handle, "\xEF\xBB\xBF" );

        // Write headers
        fputcsv( $handle, $this->headers );

        // Process products
        $exported_count = 0;
        $batch_size     = $this->plugin_settings['batch_size'] ?? 100;
        $total_products = count( $products );

        $this->logger->info(
            sprintf( __( 'Processing %d products', 'wp-nalda-sync' ), $total_products )
        );

        foreach ( $products as $product_id ) {
            $product = wc_get_product( $product_id );

            if ( ! $product ) {
                continue;
            }

            // Handle variable products - export each variation
            if ( $product->is_type( 'variable' ) ) {
                $variations = $product->get_available_variations();
                foreach ( $variations as $variation_data ) {
                    $variation = wc_get_product( $variation_data['variation_id'] );
                    if ( $variation ) {
                        $row = $this->prepare_product_row( $variation, $product );
                        if ( $row ) {
                            fputcsv( $handle, $row );
                            $exported_count++;
                        }
                    }
                }
            } else {
                $row = $this->prepare_product_row( $product );
                if ( $row ) {
                    fputcsv( $handle, $row );
                    $exported_count++;
                }
            }

            // Log progress every batch
            if ( $exported_count > 0 && $exported_count % $batch_size === 0 ) {
                $this->logger->info(
                    sprintf( __( 'Processed %d products...', 'wp-nalda-sync' ), $exported_count )
                );
            }
        }

        fclose( $handle );

        // Log skipped products (limit to first 20 to avoid performance issues)
        if ( $this->skipped_count > 0 ) {
            $sample_skipped = array_slice( $this->skipped_products, 0, 20 );
            $this->logger->warning(
                sprintf( __( 'Skipped %d products due to missing GTIN or price', 'wp-nalda-sync' ), $this->skipped_count ),
                array( 
                    'skipped_sample' => $sample_skipped,
                    'showing' => min( 20, count( $this->skipped_products ) ) . ' of ' . $this->skipped_count,
                )
            );
        }

        $message = sprintf(
            __( 'CSV generated successfully. Exported %d products, skipped %d products.', 'wp-nalda-sync' ),
            $exported_count,
            $this->skipped_count
        );

        $this->logger->success( $message, array(
            'filename'       => $filename,
            'exported_count' => $exported_count,
            'skipped_count'  => $this->skipped_count,
        ) );

        return array(
            'success'          => true,
            'message'          => $message,
            'filepath'         => $filepath,
            'filename'         => $filename,
            'exported_count'   => $exported_count,
            'skipped_count'    => $this->skipped_count,
        );
    }

    /**
     * Generate CSV for download/preview (saves to web-accessible uploads directory)
     *
     * @return array Result array with success status, message, file_url, and file info.
     */
    public function generate_for_download() {
        $this->logger->info( __( 'Starting CSV generation for download', 'wp-nalda-sync' ) );

        // Reset counters
        $this->skipped_count    = 0;
        $this->skipped_products = array();

        // Load settings
        $this->load_settings();

        // Get products
        $products = $this->get_products();

        if ( empty( $products ) ) {
            $message = __( 'No products found to export.', 'wp-nalda-sync' );
            $this->logger->warning( $message );
            return array(
                'success' => false,
                'message' => $message,
            );
        }

        // Generate filename
        $filename = $this->generate_filename();
        
        // Get WordPress uploads directory
        $upload_dir = wp_upload_dir();
        $wpns_dir   = trailingslashit( $upload_dir['basedir'] ) . 'wpns-downloads/';
        $wpns_url   = trailingslashit( $upload_dir['baseurl'] ) . 'wpns-downloads/';

        // Create directory if it doesn't exist
        if ( ! is_dir( $wpns_dir ) ) {
            wp_mkdir_p( $wpns_dir );
            // Add index.php for security
            file_put_contents( $wpns_dir . 'index.php', '<?php // Silence is golden' );
            // Add .htaccess to prevent directory listing
            file_put_contents( $wpns_dir . '.htaccess', 'Options -Indexes' );
        }

        // Clean up old download files (older than 1 hour)
        $this->cleanup_old_downloads( $wpns_dir );

        $filepath = $wpns_dir . $filename;
        $file_url = $wpns_url . $filename;

        $handle = @fopen( $filepath, 'w' );
        if ( ! $handle ) {
            $message = __( 'Failed to create CSV file for download. Please check server permissions.', 'wp-nalda-sync' );
            $this->logger->error( $message, array(
                'filepath'   => $filepath,
                'upload_dir' => $wpns_dir,
            ) );
            return array(
                'success' => false,
                'message' => $message,
            );
        }

        // Add UTF-8 BOM for Excel compatibility
        fwrite( $handle, "\xEF\xBB\xBF" );

        // Write headers
        fputcsv( $handle, $this->headers );

        // Process products
        $exported_count = 0;

        foreach ( $products as $product_id ) {
            $product = wc_get_product( $product_id );

            if ( ! $product ) {
                continue;
            }

            // Handle variable products - export each variation
            if ( $product->is_type( 'variable' ) ) {
                $variations = $product->get_available_variations();
                foreach ( $variations as $variation_data ) {
                    $variation = wc_get_product( $variation_data['variation_id'] );
                    if ( $variation ) {
                        $row = $this->prepare_product_row( $variation, $product );
                        if ( $row ) {
                            fputcsv( $handle, $row );
                            $exported_count++;
                        }
                    }
                }
            } else {
                $row = $this->prepare_product_row( $product );
                if ( $row ) {
                    fputcsv( $handle, $row );
                    $exported_count++;
                }
            }
        }

        fclose( $handle );

        $message = sprintf(
            __( 'CSV generated successfully. Exported %d products, skipped %d products.', 'wp-nalda-sync' ),
            $exported_count,
            $this->skipped_count
        );

        $this->logger->success( $message );

        return array(
            'success'        => true,
            'message'        => $message,
            'filepath'       => $filepath,
            'filename'       => $filename,
            'file_url'       => $file_url,
            'exported_count' => $exported_count,
            'skipped_count'  => $this->skipped_count,
        );
    }

    /**
     * Clean up old download files
     *
     * @param string $directory Directory to clean.
     */
    private function cleanup_old_downloads( $directory ) {
        $files = glob( $directory . 'nalda-products-*.csv' );
        if ( ! $files ) {
            return;
        }

        $one_hour_ago = time() - HOUR_IN_SECONDS;

        foreach ( $files as $file ) {
            if ( filemtime( $file ) < $one_hour_ago ) {
                @unlink( $file );
            }
        }
    }

    /**
     * Delete temporary CSV file after upload
     *
     * @param string $filepath Path to the temp file.
     */
    public function cleanup_temp_file( $filepath ) {
        if ( ! file_exists( $filepath ) ) {
            return;
        }

        // Check if file is in an allowed temp directory
        $allowed_dirs = array(
            get_temp_dir(),
            sys_get_temp_dir(),
            WP_CONTENT_DIR . '/uploads/wpns-temp/',
        );

        foreach ( $allowed_dirs as $dir ) {
            $dir = trailingslashit( $dir );
            if ( strpos( $filepath, $dir ) === 0 ) {
                @unlink( $filepath );
                return;
            }
        }
    }

    /**
     * Load WooCommerce and plugin settings
     */
    private function load_settings() {
        // WooCommerce settings
        $country_code = WC()->countries->get_base_country();
        
        // Get tax rate
        $tax_rate = 0;
        $tax_rates = WC_Tax::get_base_tax_rates();
        if ( ! empty( $tax_rates ) ) {
            $first_rate = reset( $tax_rates );
            $tax_rate   = $first_rate['rate'] ?? 0;
        }

        $this->wc_settings = array(
            'country_code' => $country_code,
            'currency'     => get_woocommerce_currency(),
            'tax_rate'     => round( floatval( $tax_rate ), 2 ),
        );

        // Plugin settings
        $this->plugin_settings = get_option( 'wpns_settings', array() );
    }

    /**
     * Get all publishable products
     *
     * @return array Array of product IDs.
     */
    private function get_products() {
        $args = array(
            'status'  => 'publish',
            'limit'   => -1,
            'return'  => 'ids',
            'orderby' => 'ID',
            'order'   => 'ASC',
        );

        return wc_get_products( $args );
    }

    /**
     * Prepare product row for CSV
     *
     * @param WC_Product      $product        Product object.
     * @param WC_Product|null $parent_product Parent product for variations.
     * @return array|false Row data array or false if product should be skipped.
     */
    private function prepare_product_row( $product, $parent_product = null ) {
        // Get GTIN (check common meta keys)
        $gtin = $this->get_product_gtin( $product, $parent_product );

        // Get price
        $price = $product->get_price();

        // Skip products without GTIN or price
        if ( empty( $gtin ) || empty( $price ) || floatval( $price ) <= 0 ) {
            $this->skipped_count++;
            $this->skipped_products[] = array(
                'id'     => $product->get_id(),
                'name'   => $product->get_name(),
                'reason' => empty( $gtin ) ? 'missing_gtin' : 'missing_price',
            );
            return false;
        }

        // Get tax rate (the CSV expects the tax rate value, not calculated amount)
        $tax_rate = $this->wc_settings['tax_rate'];

        // Get product dimensions (convert to mm if needed)
        $length = $this->convert_to_mm( $product->get_length() );
        $width  = $this->convert_to_mm( $product->get_width() );
        $height = $this->convert_to_mm( $product->get_height() );
        $weight = $this->convert_to_grams( $product->get_weight() );

        // Calculate shipping dimensions (add 10% for packaging)
        $shipping_length = $length ? round( $length * 1.1, 2 ) : '';
        $shipping_width  = $width ? round( $width * 1.1, 2 ) : '';
        $shipping_height = $height ? round( $height * 1.1, 2 ) : '';
        $shipping_weight = $weight ? round( $weight * 1.1, 2 ) : '';

        // Get images
        $images = $this->get_product_images( $product, $parent_product );

        // Get categories
        $categories       = $this->get_product_categories( $product, $parent_product );
        $google_category  = $this->get_google_category( $product, $parent_product );
        $seller_category  = $this->get_seller_category( $product, $parent_product );

        // Get condition
        $condition = $this->get_product_condition( $product );

        // Get attributes
        $brand     = $this->get_product_meta( $product, $parent_product, array( '_brand', 'brand', '_yoast_wpseo_brand' ) );
        $author    = $this->get_product_meta( $product, $parent_product, array( '_author', 'author', 'book_author' ) );
        $publisher = $this->get_product_meta( $product, $parent_product, array( '_publisher', 'publisher' ) );
        $language  = $this->get_product_meta( $product, $parent_product, array( '_language', 'language' ) ) ?: 'ger';
        $format    = $this->get_product_meta( $product, $parent_product, array( '_format', 'format' ) );
        $year      = $this->get_product_meta( $product, $parent_product, array( '_year', 'year', 'publication_year' ) );
        $volume    = $this->get_product_meta( $product, $parent_product, array( '_volume_ml', 'volume_ml' ) );

        // Get product attributes for size and colour
        $size   = $this->get_product_attribute( $product, array( 'size', 'pa_size' ) );
        $colour = $this->get_product_attribute( $product, array( 'color', 'colour', 'pa_color', 'pa_colour' ) );

        // Prepare row
        $row = array(
            $gtin,                                                          // gtin
            $product->get_name(),                                           // title
            $this->wc_settings['country_code'],                            // country
            $condition,                                                     // condition
            round( floatval( $price ), 2 ),                                // price
            $tax_rate,                                                      // tax (tax rate percentage)
            $this->wc_settings['currency'],                                // currency
            $this->plugin_settings['delivery_time'] ?? 3,                  // delivery_time_days
            $product->get_stock_quantity() ?: 0,                           // stock
            $this->plugin_settings['return_days'] ?? 14,                   // return_days
            $images['main'] ?? '',                                          // main_image_url
            $brand,                                                         // brand
            $categories,                                                    // category
            $google_category,                                               // google_category
            $seller_category,                                               // seller_category
            $this->clean_description( $product->get_description() ),       // description
            $length,                                                        // length_mm
            $width,                                                         // width_mm
            $height,                                                        // height_mm
            $weight,                                                        // weight_g
            $shipping_length,                                               // shipping_length_mm
            $shipping_width,                                                // shipping_width_mm
            $shipping_height,                                               // shipping_height_mm
            $shipping_weight,                                               // shipping_weight_g
            $volume,                                                        // volume_ml
            $size,                                                          // size
            $colour,                                                        // colour
            $images['image_2'] ?? '',                                       // image_2_url
            $images['image_3'] ?? '',                                       // image_3_url
            $images['image_4'] ?? '',                                       // image_4_url
            $images['image_5'] ?? '',                                       // image_5_url
            '',                                                             // delete_product
            $author,                                                        // author
            $language,                                                      // language
            $format,                                                        // format
            $year,                                                          // year
            $publisher,                                                     // publisher
        );

        return $row;
    }

    /**
     * Get product GTIN from various meta keys
     *
     * @param WC_Product      $product        Product object.
     * @param WC_Product|null $parent_product Parent product for variations.
     * @return string
     */
    private function get_product_gtin( $product, $parent_product = null ) {
        $gtin_keys = array(
            '_gtin',
            'gtin',
            '_ean',
            'ean',
            '_isbn',
            'isbn',
            '_upc',
            'upc',
            '_barcode',
            'barcode',
            '_global_unique_id', // WooCommerce native GTIN field
            '_wpm_gtin_code',    // WooCommerce Product Manager
            'hwp_product_gtin',  // FLAVOR / flavor
        );

        foreach ( $gtin_keys as $key ) {
            $value = $product->get_meta( $key );
            if ( ! empty( $value ) ) {
                return $value;
            }
        }

        // Check parent product for variations
        if ( $parent_product ) {
            foreach ( $gtin_keys as $key ) {
                $value = $parent_product->get_meta( $key );
                if ( ! empty( $value ) ) {
                    return $value;
                }
            }
        }

        // Check SKU as fallback (some stores use EAN as SKU)
        $sku = $product->get_sku();
        if ( ! empty( $sku ) && is_numeric( $sku ) && strlen( $sku ) >= 8 ) {
            return $sku;
        }

        return '';
    }

    /**
     * Get product meta from various possible keys
     *
     * @param WC_Product      $product        Product object.
     * @param WC_Product|null $parent_product Parent product for variations.
     * @param array           $keys           Array of meta keys to check.
     * @return string
     */
    private function get_product_meta( $product, $parent_product, $keys ) {
        foreach ( $keys as $key ) {
            $value = $product->get_meta( $key );
            if ( ! empty( $value ) ) {
                return $value;
            }
        }

        // Check parent product for variations
        if ( $parent_product ) {
            foreach ( $keys as $key ) {
                $value = $parent_product->get_meta( $key );
                if ( ! empty( $value ) ) {
                    return $value;
                }
            }
        }

        return '';
    }

    /**
     * Get product attribute value
     *
     * @param WC_Product $product Product object.
     * @param array      $names   Array of attribute names to check.
     * @return string
     */
    private function get_product_attribute( $product, $names ) {
        foreach ( $names as $name ) {
            $value = $product->get_attribute( $name );
            if ( ! empty( $value ) ) {
                return $value;
            }
        }
        return '';
    }

    /**
     * Get product condition
     *
     * @param WC_Product $product Product object.
     * @return string
     */
    private function get_product_condition( $product ) {
        $condition_keys = array( '_condition', 'condition', 'product_condition' );

        foreach ( $condition_keys as $key ) {
            $value = $product->get_meta( $key );
            if ( ! empty( $value ) ) {
                return strtolower( $value );
            }
        }

        return 'new';
    }

    /**
     * Get product images
     *
     * @param WC_Product      $product        Product object.
     * @param WC_Product|null $parent_product Parent product for variations.
     * @return array
     */
    private function get_product_images( $product, $parent_product = null ) {
        $images = array(
            'main'    => '',
            'image_2' => '',
            'image_3' => '',
            'image_4' => '',
            'image_5' => '',
        );

        // Get main image
        $image_id = $product->get_image_id();
        if ( ! $image_id && $parent_product ) {
            $image_id = $parent_product->get_image_id();
        }

        if ( $image_id ) {
            $images['main'] = wp_get_attachment_url( $image_id );
        }

        // Get gallery images
        $gallery_ids = $product->get_gallery_image_ids();
        if ( empty( $gallery_ids ) && $parent_product ) {
            $gallery_ids = $parent_product->get_gallery_image_ids();
        }

        $image_index = 2;
        foreach ( $gallery_ids as $gallery_id ) {
            if ( $image_index > 5 ) {
                break;
            }
            $images[ 'image_' . $image_index ] = wp_get_attachment_url( $gallery_id );
            $image_index++;
        }

        return $images;
    }

    /**
     * Get product categories
     *
     * @param WC_Product      $product        Product object.
     * @param WC_Product|null $parent_product Parent product for variations.
     * @return string
     */
    private function get_product_categories( $product, $parent_product = null ) {
        $product_id = $parent_product ? $parent_product->get_id() : $product->get_id();
        $terms = get_the_terms( $product_id, 'product_cat' );

        if ( ! $terms || is_wp_error( $terms ) ) {
            return '';
        }

        // Build category path for the deepest category
        $categories = array();
        foreach ( $terms as $term ) {
            $path      = array( $term->name );
            $ancestors = get_ancestors( $term->term_id, 'product_cat' );

            foreach ( array_reverse( $ancestors ) as $ancestor_id ) {
                $ancestor = get_term( $ancestor_id, 'product_cat' );
                if ( $ancestor && ! is_wp_error( $ancestor ) ) {
                    array_unshift( $path, $ancestor->name );
                }
            }

            $categories[ count( $path ) ] = implode( ' > ', $path );
        }

        // Return the deepest category path
        krsort( $categories );
        return reset( $categories ) ?: '';
    }

    /**
     * Get Google Product Category
     *
     * @param WC_Product      $product        Product object.
     * @param WC_Product|null $parent_product Parent product for variations.
     * @return string
     */
    private function get_google_category( $product, $parent_product = null ) {
        $keys = array(
            '_google_product_category',
            'google_product_category',
            '_wpseo_primary_product_cat',
        );

        $value = $this->get_product_meta( $product, $parent_product, $keys );

        return $value;
    }

    /**
     * Get Seller Category
     *
     * @param WC_Product      $product        Product object.
     * @param WC_Product|null $parent_product Parent product for variations.
     * @return string
     */
    private function get_seller_category( $product, $parent_product = null ) {
        $keys = array(
            '_seller_category',
            'seller_category',
        );

        return $this->get_product_meta( $product, $parent_product, $keys );
    }

    /**
     * Convert dimension to mm
     *
     * @param mixed $value Value to convert.
     * @return string
     */
    private function convert_to_mm( $value ) {
        if ( empty( $value ) ) {
            return '';
        }

        $value = floatval( $value );
        $unit  = get_option( 'woocommerce_dimension_unit', 'cm' );

        switch ( $unit ) {
            case 'm':
                $value = $value * 1000;
                break;
            case 'cm':
                $value = $value * 10;
                break;
            case 'in':
                $value = $value * 25.4;
                break;
            case 'yd':
                $value = $value * 914.4;
                break;
            // mm is default, no conversion needed
        }

        return round( $value, 2 );
    }

    /**
     * Convert weight to grams
     *
     * @param mixed $value Value to convert.
     * @return string
     */
    private function convert_to_grams( $value ) {
        if ( empty( $value ) ) {
            return '';
        }

        $value = floatval( $value );
        $unit  = get_option( 'woocommerce_weight_unit', 'kg' );

        switch ( $unit ) {
            case 'kg':
                $value = $value * 1000;
                break;
            case 'lbs':
                $value = $value * 453.592;
                break;
            case 'oz':
                $value = $value * 28.3495;
                break;
            // g is default, no conversion needed
        }

        return round( $value, 2 );
    }

    /**
     * Clean description text
     *
     * @param string $description Description to clean.
     * @return string
     */
    private function clean_description( $description ) {
        // Keep some HTML formatting but clean excessive whitespace
        $description = wp_kses( $description, array(
            'p'      => array(),
            'br'     => array(),
            'strong' => array(),
            'b'      => array(),
            'em'     => array(),
            'i'      => array(),
            'ul'     => array(),
            'ol'     => array(),
            'li'     => array(),
        ) );

        // Normalize whitespace
        $description = preg_replace( '/\s+/', ' ', $description );
        $description = trim( $description );

        return $description;
    }

    /**
     * Generate filename based on pattern
     *
     * @return string
     */
    private function generate_filename() {
        $pattern = $this->plugin_settings['filename_pattern'] ?? '';
        
        // Default pattern if empty or invalid
        if ( empty( $pattern ) || strpos( $pattern, '{' ) === false ) {
            $pattern = 'products_{date}.csv';
        }

        $replacements = array(
            '{date}'      => gmdate( 'Y-m-d' ),
            '{datetime}'  => gmdate( 'Y-m-d_H-i-s' ),
            '{timestamp}' => time(),
        );

        $filename = str_replace(
            array_keys( $replacements ),
            array_values( $replacements ),
            $pattern
        );

        // Ensure .csv extension
        if ( substr( $filename, -4 ) !== '.csv' ) {
            $filename .= '.csv';
        }

        return sanitize_file_name( $filename );
    }

    /**
     * Get last generated CSV file path
     *
     * @return string|false
     */
    public function get_last_export_file() {
        $exports_dir = WP_Nalda_Sync::get_exports_dir();
        $files       = glob( $exports_dir . '/*.csv' );

        if ( empty( $files ) ) {
            return false;
        }

        // Sort by modification time, newest first
        usort( $files, function( $a, $b ) {
            return filemtime( $b ) - filemtime( $a );
        } );

        return $files[0];
    }

    /**
     * Cleanup old export files
     *
     * @param int $keep_count Number of files to keep.
     */
    public function cleanup_old_exports( $keep_count = 5 ) {
        $exports_dir = WP_Nalda_Sync::get_exports_dir();
        $files       = glob( $exports_dir . '/*.csv' );

        if ( count( $files ) <= $keep_count ) {
            return;
        }

        // Sort by modification time, newest first
        usort( $files, function( $a, $b ) {
            return filemtime( $b ) - filemtime( $a );
        } );

        // Delete old files
        $files_to_delete = array_slice( $files, $keep_count );
        foreach ( $files_to_delete as $file ) {
            unlink( $file );
        }

        $this->logger->info(
            sprintf( __( 'Cleaned up %d old export files', 'wp-nalda-sync' ), count( $files_to_delete ) )
        );
    }
}
