=== Hosted Checkout for WooCommerce ===
Contributors: your-company
Tags: payments, checkout, woocommerce, hosted-checkout, blocks, refunds
Requires at least: 6.0
Tested up to: 6.6
Stable tag: 1.6.1
License: GPL-3.0-or-later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Hosted checkout gateway for WooCommerce with refunds and Blocks support. Brand auto-detected from API.

== Description ==
- **Complete Order Integration**: Forwards all order components including products, coupon discounts, shipping, fees, and taxes
- **Automatic Order Sync**: Pending orders are automatically refreshed when visiting the Orders page - no manual sync required
- **Enhanced Payment Flow**: Robust redirect fallback system handles plugin conflicts and ensures reliable checkout experience
- **Auto-Configuration**: Store URLs are automatically detected and configured to prevent setup errors
- **Comprehensive Logging**: Detailed diagnostic logging with sensitive data protection for easy troubleshooting
- **Settings page includes**: Secret Key, Vendor ID, Auto-sync window (1-48 hours), and Debug logging
- **Hosted Checkout**: Creates a hosted checkout session and redirects customers to the secure payment page
- **Full Refund Support**: Process refunds directly from the WooCommerce order screen with automatic synchronization
- **WooCommerce Blocks**: Full compatibility with modern WooCommerce block-based checkout
- **Professional Card Icons**: Protected CSS ensures card brand icons display properly in all themes and page builders

Requirements and tips:
- WooCommerce Cart, Checkout, and My Account pages should exist and permalinks should be set to Postname WordPress->Settings->Permalinks
- **Automatic Order Sync**: Orders with pending payments are automatically refreshed when you visit WooCommerce->Orders (configurable 1-48 hour window)
- **Auto-Configuration**: Store domain URLs are automatically detected - no manual setup required for return/success pages
- **Enhanced Debugging**: Enable debug logging for comprehensive diagnostics including order totals, coupon processing, and API communications
- **Troubleshooting**: Visit WooCommerce->Status->Logs for detailed logs with automatic sensitive data masking
- **Plugin Compatibility**: Built-in fallback system handles conflicts with other plugins (WhatsApp widgets, etc.)
- **Payment Flow**: The plugin redirects users during checkout - the enhanced fallback system ensures reliable redirects even with plugin interference
- **Order Processing**: Complete order details including all discounts and fees are automatically forwarded
- **Post-Payment**: Customers are redirected back to your site's order received page using auto-detected Store Domain
- **Refund Management**: Orders can be refunded directly by pressing the Refund button on the Order page with real-time synchronization
- **Order Synchronization**: Auto-sync handles most cases automatically, or manually click Actions->Sync on any order

== Installation ==
1. Upload the ZIP via Plugins > Add New > Upload Plugin and click activate.

== Configuration ==
In the payment portal:
- Click Developer
- Click New App. You can name it whatever you would like.
- Locate your App ID (Should be directly below "Manage App") - this will be entered into the plugin's Vendor ID field.
- Click API Keys and note your production API key - this will be entered into the plugin's Secret Key field.

In WordPress (https://yoursite/wp-admin):
- Click Plugins and locate the Hosted Checkout plugin and make sure it's activated
- Navigate to WooCommerce -> Settings -> Payments and locate the payment gateway
- Paste your production key into the "Secret Key" field
- Paste your app ID into the "Vendor ID" field
- Set the **API Domain** to your payment platform's domain
- **Store Domain** is automatically detected (shows your site URL) - only change if using a different domain
- Set **Auto Sync Window** to desired hours (1-48, default 24h) for automatic order refresh frequency
- **Debugging**: Check "Enable debugging" for comprehensive logging including auto-sync operations (recommended during setup)
- Check the "Enable" checkbox at the top to activate the payment method
- Save changes to enable the gateway

== Changelog ==

= 1.6.1 =
* **LICENSE**: Switched from MIT to GPL-3.0-or-later for WordPress ecosystem compatibility
* **IMPROVED**: Payment type label updated to "eCheck via Plaid"
* **CLEANUP**: Removed dead card payment code paths (eCheck-only gateway)

= 1.6.0 =
* **MAJOR**: Rebranded to Hosted Checkout Gateway (hcwc) with migration from legacy gateway ID

= 1.5.6 =
* **CRITICAL FIX**: Fixed "back to store" button on payment portal - now correctly returns to checkout page instead of order received page
* **IMPROVED**: Separated returnURL (checkout page) from successURL (thank-you page) for proper user flow
* **ENHANCED**: Better distinction between payment success redirect and store navigation
* **RELIABILITY**: Customers can now properly return to checkout if they need to change payment details

= 1.5.5 =
* **CRITICAL FIX**: Removed duplicate WooCommerce Blocks registration that was causing WordPress notices and header warnings
* **CLEANUP**: Eliminated duplicate payment method title fixing hooks outside the main plugins_loaded action
* **IMPROVED**: Cleaner, more maintainable code structure with reduced redundancy
* **STABILITY**: Resolved "already registered" error and cascading header modification warnings
* **PERFORMANCE**: Streamlined plugin loading process with proper duplicate prevention

= 1.5.4 =
* **CRITICAL FIX**: Added comprehensive duplicate registration protection for WooCommerce Blocks integration
* **NEW**: Static flag system prevents multiple WooCommerce Blocks registration attempts during WordPress initialization
* **NEW**: Registry check validation ensures payment method isn't registered multiple times
* **ENHANCED**: Two-layer protection against duplicate registration: static flag + registry validation
* **FIX**: Resolved WordPress notices about duplicate payment method registration
* **IMPROVED**: More robust WooCommerce Blocks integration with proper error prevention

= 1.5.3 =
* **CRITICAL FIX**: Corrected WooCommerce Blocks class name
* **FIX**: Resolved fatal PHP error that prevented WordPress from loading when plugin was activated before WooCommerce
* **IMPROVED**: WooCommerce Blocks integration now properly instantiates the correct class
* **STABILITY**: Plugin now loads gracefully regardless of activation order relative to WooCommerce

= 1.5.2 =
* **CRITICAL FIX**: Consolidated all WooCommerce-dependent code inside plugins_loaded action with proper safety checks
* **SECURITY**: Added validation to ensure WooCommerce classes exist before attempting to extend them
* **IMPROVED**: Plugin now handles activation in any order relative to WooCommerce without fatal errors
* **ENHANCED**: Better error messaging when WooCommerce is not active - shows admin notice instead of fatal error
* **STABILITY**: Eliminated PHP fatal errors when WooCommerce is deactivated or not installed

= 1.5.1 =
* **CRITICAL FIX**: Fixed return URL redirect issue that was sending customers back to checkout instead of order confirmation page
* **IMPROVED**: Now uses WooCommerce's built-in `get_return_url()` and `get_cancel_order_url()` helper methods for proper URL generation
* **ENHANCED**: URLs are now future-proof and work correctly with any permalink structure or WooCommerce endpoint configuration
* **RELIABILITY**: Customers will now properly land on the "Thank you for your order" page after completing payment

= 1.5.0 =
* **REFACTOR**: Simplified auto-update mechanism to check only the `master` branch, removing multi-environment logic.
* **REFACTOR**: Removed temporary debugging code related to the Plugin Update Checker.
* **MAINTENANCE**: Updated plugin version to 1.5.0 for new release.

= 1.4.9 =
* **NEW**: Automatic plugin updates via GitHub integration
* **NEW**: Staging branch support for testing updates before production release
* **SECURITY**: Enhanced session ID validation - prevents duplicate session IDs across multiple orders
* **SECURITY**: Session ID conflict detection with proper error logging and admin notifications
* **IMPROVED**: Comprehensive protection against session ID manipulation vulnerabilities

= 1.4.8 =
* **NEW**: Automatic order synchronization - pending orders are automatically refreshed when visiting Orders page
* **NEW**: Configurable auto-sync time window (1-48 hours, default 24h) with settings integration
* **NEW**: Smart transient locking prevents duplicate API calls during auto-sync operations
* **NEW**: Auto-detection of site URLs for return/success/cancel URLs
* **NEW**: Enhanced protective CSS for card brand icons
* **ENHANCED**: Auto-sync only processes orders with session IDs but missing transaction IDs
* **FIX**: Card brand icons now properly constrained in theme builders

= 1.4.7 =
* ENHANCED: Classic and Blocks checkout both respect logo toggle
* FIX: Order admin page now always shows plain text payment method
* FIX: Orders list Billing column consistently shows payment method

= 1.4.6 =
* **MAJOR**: Fixed payment method display inconsistency across admin and order pages
* **FIX**: Orders page now consistently shows correct payment method
* **ENHANCED**: Simplified title logic - checkout shows enhanced branding, all other contexts show brand name

= 1.4.5 =
* **MAJOR**: Enhanced refund system with robust error handling and retry logic
* **NEW**: Smart refund retry detection
* **NEW**: Comprehensive refund debugging
* **NEW**: Floating point precision handling with tolerance-based amount validation
* **NEW**: Enhanced refund UI
* **NEW**: Custom refund reason dropdown

= 1.4.4 =
* **NEW**: Enhanced checkout UI with professional card brand icons (Visa, Mastercard, Amex, Discover)
* **NEW**: Dynamic payment method branding
* **NEW**: Context-aware title display
* **NEW**: Professional SVG card icons

= 1.4.3 =
* **MAJOR**: Simplified line items to single branded order total entry

= 1.4.2 =
* **FIX**: Fixed line item display issue

= 1.4.1 =
* **FIX**: Fixed "back to store" button - now correctly returns to checkout page

= 1.4.0 =
* **MAJOR**: Fixed coupon discounts not being forwarded - now sends complete order breakdown
* **NEW**: Comprehensive line items now include products, coupon discounts, shipping, fees, and taxes

= 1.3.0 =
* **NEW**: Comprehensive logging system
* **NEW**: Sensitive data masking in logs

= 1.2.4 =
* **MAJOR**: Enhanced redirect fallback system for improved payment flow reliability

= 1.2.1 =
* **FIX**: PHP 8.2 compatibility

= 1.2.0 =
* Simplified setup (Secret Key, Vendor ID, Store Domain). Added refund support and Blocks integration.
