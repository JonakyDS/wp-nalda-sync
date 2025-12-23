=== WP Nalda Sync ===
Contributors: yourname
Tags: woocommerce, csv, export, sftp, product feed, sync, nalda, marketplace, orders
Requires at least: 5.8
Tested up to: 6.4
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sync your WooCommerce products to Nalda Marketplace via SFTP and import orders from Nalda to your store.

== Description ==

WP Nalda Sync is a powerful WordPress plugin that provides two-way synchronization with Nalda Marketplace:

1. **Product Export**: Automatically exports your WooCommerce products to a CSV file and uploads it to Nalda via SFTP.
2. **Order Import**: Automatically imports and syncs orders from Nalda Marketplace to your WooCommerce store.

= Features =

**Product Sync (Export)**
* **Automatic CSV Generation**: Creates CSV files with comprehensive product data including GTIN/EAN, pricing, dimensions, images, and more.
* **SFTP Upload**: Securely uploads generated CSV files to your SFTP server using password-based authentication.
* **WooCommerce Integration**: Automatically imports settings like country, currency, and tax rates from WooCommerce.
* **Scheduled Sync**: Run syncs hourly, twice daily, daily, or weekly.
* **Variable Products Support**: Exports each variation as a separate row.

**Order Sync (Import)** - NEW!
* **Automatic Order Import**: Import orders from Nalda Marketplace to WooCommerce.
* **Scheduled Syncing**: Configure hourly, twice daily, or daily order syncs.
* **Status Synchronization**: Automatically updates order status based on Nalda delivery status.
* **Flexible Date Ranges**: Sync orders from today, yesterday, current month, or custom ranges.
* **Two Import Modes**: Import all orders or sync existing orders only.
* **GTIN Product Matching**: Automatically matches order items to your WooCommerce products by GTIN/EAN.
* **Nalda Metadata**: Track Nalda order ID, delivery status, payout status, commission, and fees.

**General**
* **Detailed Logging**: Track every sync operation with comprehensive logs.
* **Manual Sync**: Run sync operations manually whenever needed.
* **Connection Testing**: Test both SFTP and Nalda API connections.
* **CSV Preview**: Download generated CSV files for review before uploading.

= CSV Format =

The generated CSV includes the following fields:
* GTIN/EAN/ISBN/UPC
* Title
* Country, Currency, Tax
* Condition (new/used)
* Price and Tax Amount
* Delivery Time and Return Days
* Stock Quantity
* Product Images (up to 5)
* Dimensions and Weight
* Shipping Dimensions
* Brand, Author, Publisher
* Categories (WooCommerce, Google, Seller)
* Description
* And more...

= Requirements =

* WordPress 5.8 or higher
* WooCommerce 5.0 or higher
* PHP 7.4 or higher
* PHP SSH2 extension (recommended) or phpseclib library

== Installation ==

1. Upload the `wp-nalda-sync` folder to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to 'Nalda Sync' in the admin menu to configure your settings
4. Enter your SFTP credentials and configure sync options
5. Test the SFTP connection
6. Enable automatic sync or run manually

= Installing SSH2 Extension =

For best performance, install the PHP SSH2 extension:

**Ubuntu/Debian:**
```bash
sudo apt-get install php-ssh2
sudo service apache2 restart
```

**CentOS/RHEL:**
```bash
sudo yum install php-pecl-ssh2
sudo systemctl restart httpd
```

= Alternative: Using phpseclib =

If you cannot install the SSH2 extension, run this command in the plugin directory:
```bash
composer require phpseclib/phpseclib
```

== Frequently Asked Questions ==

= What product identifiers are supported? =

The plugin looks for GTIN, EAN, ISBN, UPC, and barcode values in various meta fields commonly used by WooCommerce plugins.

= Why are some products skipped? =

Products without a valid GTIN/EAN or without a price are automatically skipped to ensure data quality.

= Can I customize the CSV format? =

The CSV format is designed to match the Nalda platform requirements. Custom format support may be added in future versions.

= How often can I run the sync? =

You can schedule syncs hourly, twice daily, daily, or weekly. Manual syncs can be run at any time.

= Is my SFTP password secure? =

Yes, SFTP passwords are encrypted before being stored in the database using WordPress salts.

= How do I get my Nalda API key? =

1. Visit the [Nalda Seller Portal](https://sellers.nalda.com/)
2. Navigate to Orders/Bestellungen
3. Click the Settings icon in the top right corner
4. Generate or copy your API key

= What order data is imported from Nalda? =

Orders imported from Nalda include: customer details, shipping address, order items with GTIN, pricing, delivery status, payout status, commission, and fees.

= How are Nalda delivery statuses mapped to WooCommerce? =

- IN_PREPARATION, IN_DELIVERY, READY_TO_COLLECT → Processing
- DELIVERED, COLLECTED → Completed
- CANCELLED → Cancelled
- RETURNED → Refunded
- UNDELIVERABLE, NOT_PICKED_UP → Failed
- DISPUTE → On Hold

== Screenshots ==

1. Settings page with status cards showing sync status and WooCommerce settings
2. SFTP configuration options
3. Order Sync page with import statistics and recent orders
4. Detailed sync logs with filtering options

== Changelog ==

= 1.1.0 =
* NEW: Order import from Nalda Marketplace
* NEW: Nalda API integration
* NEW: Order Sync admin page
* NEW: Automatic order status synchronization
* NEW: Scheduled order import (hourly, twice daily, daily)
* NEW: GTIN-based product matching for order items
* NEW: Nalda metadata stored on orders (order ID, delivery status, payout status, commission, fees)
* Improved admin interface with order management

= 1.0.0 =
* Initial release
* CSV generation from WooCommerce products
* SFTP upload with password authentication
* Scheduled sync support
* Comprehensive logging system
* Admin dashboard with status cards

== Upgrade Notice ==

= 1.1.0 =
Major update! Now includes order import from Nalda Marketplace. Configure your Nalda API key in Settings to start syncing orders.

= 1.0.0 =
Initial release of WP Nalda Sync.
