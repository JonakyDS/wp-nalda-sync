=== WP Nalda Sync ===
Contributors: yourname
Tags: woocommerce, csv, export, sftp, product feed, sync
Requires at least: 5.8
Tested up to: 6.4
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Automatically generates product CSV feeds from WooCommerce and uploads them to SFTP servers.

== Description ==

WP Nalda Sync is a powerful WordPress plugin that automatically exports your WooCommerce products to a CSV file and uploads it to an SFTP server. Perfect for integrating with marketplaces, price comparison websites, or any system that requires product data feeds.

= Features =

* **Automatic CSV Generation**: Creates CSV files with comprehensive product data including GTIN/EAN, pricing, dimensions, images, and more.
* **SFTP Upload**: Securely uploads generated CSV files to your SFTP server using password-based authentication.
* **WooCommerce Integration**: Automatically imports settings like country, currency, and tax rates from WooCommerce.
* **Scheduled Sync**: Run syncs hourly, twice daily, daily, or weekly.
* **Detailed Logging**: Track every sync operation with comprehensive logs.
* **Smart Filtering**: Automatically skips products without GTIN or price.
* **Variable Products Support**: Exports each variation as a separate row.
* **Manual Sync**: Run sync operations manually whenever needed.
* **Connection Testing**: Test your SFTP connection before scheduling syncs.
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

== Screenshots ==

1. Settings page with status cards showing sync status and WooCommerce settings
2. SFTP configuration options
3. Detailed sync logs with filtering options

== Changelog ==

= 1.0.0 =
* Initial release
* CSV generation from WooCommerce products
* SFTP upload with password authentication
* Scheduled sync support
* Comprehensive logging system
* Admin dashboard with status cards

== Upgrade Notice ==

= 1.0.0 =
Initial release of WP Nalda Sync.
