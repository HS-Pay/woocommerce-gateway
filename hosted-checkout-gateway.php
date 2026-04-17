<?php
// Declare HPOS (High-Performance Order Storage) compatibility
// HPOS compatibility: declare plugin compatibility (does not toggle site feature)
add_action( 'before_woocommerce_init', function() {
    if ( class_exists( '\\Automattic\\WooCommerce\\Utilities\\FeaturesUtil' ) ) {
    \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
    }
} );
/**
 * QUICK CONFIG (defaults shown in the Settings page)
 * If you prefer not to hardcode anything, leave these blank and configure in WooCommerce > Settings > Payments.
 */
define('KC_BRAND_NAME_FALLBACK', 'Payment Gateway');
define('KC_DEFAULT_SECRET_KEY', '');
define('KC_DEFAULT_VENDOR_ID', '');
/** Your store's public base URL (used to prefill success/return/cancel). */
define('KC_DEFAULT_STORE_DOMAIN', 'https://yourdomain.com');

if ( ! function_exists( 'kc_get_brand_name' ) ) {
    function kc_get_brand_name() {
        $cached = get_option( 'kc_brand_cache', array() );
        return ! empty( $cached['name'] ) ? $cached['name'] : KC_BRAND_NAME_FALLBACK;
    }
}
if ( ! function_exists( 'kc_get_brand_logo' ) ) {
    function kc_get_brand_logo() {
        $cached = get_option( 'kc_brand_cache', array() );
        return ! empty( $cached['logo'] ) ? $cached['logo'] : '';
    }
}
if ( ! function_exists( 'kc_get_api_domain' ) ) {
    function kc_get_api_domain() {
        $settings = get_option( 'woocommerce_hcwc_settings', array() );
        $domain = ! empty( $settings['api_domain'] ) ? $settings['api_domain'] : '';
        if ( strpos( $domain, ':' ) !== false || strpos( $domain, 'localhost' ) === 0 || strpos( $domain, 'host.docker.internal' ) === 0 ) {
            return $domain;
        }
        return 'api.' . $domain;
    }
}
if ( ! function_exists( 'kc_get_api_url' ) ) {
    function kc_get_api_url() {
        $host = kc_get_api_domain();
        $scheme = ( strpos( $host, 'localhost' ) === 0 || strpos( $host, 'host.docker.internal' ) === 0 ) ? 'http' : 'https';
        return $scheme . '://' . $host . '/v1';
    }
}

/**
 * Plugin Name: Hosted Checkout for WooCommerce
 * Plugin URI:  https://github.com/HS-Pay/woocommerce-gateway
 * Description: Hosted checkout gateway for WooCommerce with refunds, Blocks support, and easy settings. Brand auto-detected from API.
 * Author:      HS-Pay
 * Author URI:  https://github.com/HS-Pay
 * Version:     1.6.3
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * WC requires at least: 7.0
 * WC tested up to: 9.2
 * License:     GPL-3.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: hcwc
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'KC_WC_VERSION', '1.6.3' );
define( 'KC_WC_PLUGIN_FILE', __FILE__ );
define( 'KC_WC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'KC_WC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Initialize Plugin Update Checker for auto-updates from GitHub
 */
require_once KC_WC_PLUGIN_DIR . 'vendor/plugin-update-checker/plugin-update-checker.php';
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$kc_update_checker = PucFactory::buildUpdateChecker(
    'https://github.com/HS-Pay/woocommerce-gateway/',
    __FILE__,
    'hosted-checkout-gateway'
);
$kc_update_checker->setBranch('master');

// Use the main checker for backward compatibility, but both branches will be checked
// WordPress will show updates from whichever branch has the newer version

// Optional: Set authentication for private repos (not needed for public repos)
// $kc_update_checker->setAuthentication('your-token-here');

/**
 * Make sure WooCommerce is active.
 */
add_action( 'plugins_loaded', function() {
    if ( ! class_exists( 'WooCommerce' ) || ! class_exists( 'WC_Payment_Gateway' ) ) {
        add_action( 'admin_notices', function() {
            echo '<div class="notice notice-error"><p><strong>' . esc_html( kc_get_brand_name() ) . ' for WooCommerce</strong> requires WooCommerce to be active.</p></div>';
        } );
        return;
    }

    /**
     * Register gateway.
     */
    add_filter( 'woocommerce_payment_gateways', function( $gateways ) {
        $gateways[] = 'WC_Gateway_HCWC';
        return $gateways;
    } );

    /**
     * Gateway class.
     */
    class WC_Gateway_HCWC extends WC_Payment_Gateway {
        // Declare properties to fix PHP 8.2+ deprecation warnings
        public $secret_key;
        public $vendor_id;
        public $store_domain;
        public $api_base;
        public $mode;
        public $payment_type;
        public $logging;

        public function __construct() {
            $this->id                 = 'hcwc';
            // Keep basic icon for settings page, we'll enhance the checkout display with get_icon()
            $brand_logo = kc_get_brand_logo();
            $this->icon = $brand_logo ? $brand_logo : '';
            // No on-page fields; we redirect to hosted checkout
            $this->has_fields = false;
            $this->method_title       = kc_get_brand_name();
            $this->method_description = kc_get_brand_name() . ' hosted checkout. Accept eCheck payments via Plaid.';
            $this->supports           = array( 'products', 'refunds' );

            $this->init_form_fields();
            $this->init_settings();

            // Settings values with top-of-file defaults as fallback
            $this->enabled        = $this->get_option( 'enabled', 'no' );
            $this->testmode       = false;
            $this->payment_type   = $this->get_option( 'payment_type', 'echeck' );

            $default_title = __( 'Pay via Plaid', 'hcwc' );
            $default_desc  = __( 'Pay securely via bank account (eCheck). After payment you will be redirected back to our website.', 'hcwc' );
            $this->title          = $this->get_option( 'title', $default_title );
            $this->description    = $this->get_option( 'description', $default_desc );
            $this->secret_key     = $this->get_option( 'secret_key', KC_DEFAULT_SECRET_KEY );
            $this->vendor_id      = $this->get_option( 'vendor_id', KC_DEFAULT_VENDOR_ID );
            $this->store_domain   = rtrim( $this->get_option( 'store_domain', untrailingslashit( home_url() ) ), '/' );
            $this->api_base       = '';
            $this->mode           = 'payment';
            $this->logging        = 'yes' === $this->get_option( 'logging', 'no' );
            // Logo toggle deprecated; always text + optional card icons

            add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
            add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'fetch_brand_info' ), 20 );

            // Webhook endpoint disabled for simplified setup
            // add_action( 'woocommerce_api_wc_gateway_hcwc', array( $this, 'handle_webhook' ) );
            add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'kc_capture_transaction_on_return' ), 10, 1 );

            // Enqueue our custom CSS for enhanced payment icons
            add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_payment_icons_css' ) );

            // Render description with bold lead in Classic checkout without requiring HTML in settings
            add_filter( 'woocommerce_gateway_description', array( $this, 'filter_gateway_description' ), 10, 2 );
        }

        /**
         * Enqueue custom CSS for enhanced payment icons display
         */
        public function enqueue_payment_icons_css() {
            if ( is_checkout() ) {
                wp_enqueue_style( 
                    'hcwc-payment-icons', 
                    KC_WC_PLUGIN_URL . 'assets/css/hcwc-payment-icons.css', 
                    array(), 
                    KC_WC_VERSION 
                );
            }
        }

        public function get_title() {
            // Only show custom title on the checkout page. Elsewhere, return simple identifier.
            if ( function_exists( 'is_checkout' ) && is_checkout() ) {
                $custom_title = $this->get_option( 'title', __( 'Pay via Plaid', 'hcwc' ) );
                return apply_filters( 'woocommerce_gateway_title', esc_html( $custom_title ), $this->id );
            }
            // Non-checkout contexts (admin, order pages, emails): simple identifier
            return apply_filters( 'woocommerce_gateway_title', strtolower( kc_get_brand_name() ), $this->id );
        }

        /**
         * Override method title to ensure consistent display
         */
        public function get_method_title() {
            return kc_get_brand_name();
        }

        /**
         * Override the icon display to show just the card brands, as the logo is now in the title.
         */
        public function get_icon() {
            $icon_html = '<span class="hcwc-card-icons">';
            $icon_html .= '<img class="hcwc-card-icon" src="' . esc_url( KC_WC_PLUGIN_URL . 'assets/images/bank.svg' ) . '" alt="eCheck" />';
            $icon_html .= '</span>';
            return apply_filters( 'woocommerce_gateway_icon', $icon_html, $this->id );
        }

        public function init_form_fields() {
            $this->form_fields = array(
                'enabled' => array(
                    'title'   => __( 'Enable/Disable', 'hcwc' ),
                    'type'    => 'checkbox',
                    'label'   => 'Enable ' . kc_get_brand_name() . ' Pay',
                    'default' => 'no',
                ),
                'payment_type' => array(
                    'title'       => __( 'Payment Type', 'hcwc' ),
                    'type'        => 'select',
                    'description' => __( 'Select the payment method type offered on the hosted checkout page.', 'hcwc' ),
                    'default'     => 'echeck',
                    'options'     => array(
                        'echeck' => __( 'eCheck via Plaid', 'hcwc' ),
                    ),
                ),
                'title' => array(
                    'title'       => __( 'Title', 'hcwc' ),
                    'type'        => 'text',
                    'default'     => __( 'Pay via Plaid', 'hcwc' ),
                    'description' => __( 'Leave blank to use the default based on Payment Type.', 'hcwc' ),
                    'desc_tip'    => true,
                ),
                'description' => array(
                    'title'       => __( 'Description', 'hcwc' ),
                    'type'        => 'textarea',
                    'default'     => __( 'Pay securely via bank account (eCheck). After payment you will be redirected back to our website.', 'hcwc' ),
                    'description' => __( 'This text will be displayed to customers during checkout. Leave blank to use the default based on Payment Type.', 'hcwc' ),
                ),
                'secret_key' => array(
                    'title'       => __( 'Secret Key', 'hcwc' ),
                    'type'        => 'password',
                    'default'     => KC_DEFAULT_SECRET_KEY,
                ),
                'vendor_id' => array(
                    'title'       => __( 'Vendor ID', 'hcwc' ),
                    'type'        => 'text',
                    'default'     => KC_DEFAULT_VENDOR_ID,
                    'description' => 'Provided by ' . kc_get_brand_name() . '.',
                ),
                'api_domain' => array(
                    'title'       => __( 'API Domain', 'hcwc' ),
                    'type'        => 'text',
                    'default'     => '',
                    'description' => __( 'Your payment platform domain provided by your payment provider (e.g. clickbrickco.com). This is NOT your store domain. Brand info and API URLs are derived from this. Required.', 'hcwc' ),
                    'placeholder' => 'clickbrickco.com',
                ),
                'store_domain' => array(
                    'title'       => __( 'Your Store Domain', 'hcwc' ),
                    'type'        => 'text',
                    'default'     => untrailingslashit( home_url() ),
                    'description' => __( 'Auto-detected from your site. Used for success/return/cancel URLs.', 'hcwc' ),
                    'placeholder' => untrailingslashit( home_url() ),
                ),
                // Removed show_logo setting (logo deprecated)
                'logging' => array(
                    'title'       => __( 'Debug log', 'hcwc' ),
                    'type'        => 'checkbox',
                    'label'       => __( 'Enable logging (WooCommerce > Status > Logs)', 'hcwc' ),
                    'default'     => 'no',
                ),
            );
        }

        /**
         * Format gateway description for Classic checkout: bold first line, rest normal.
         */
        public function filter_gateway_description( $description, $payment_id ) {
            if ( $payment_id !== $this->id ) {
                return $description;
            }
            
            // Use saved setting, but fall back to the default if it's empty.
            $plain = (string) $this->get_option( 'description' );
            if ( trim( $plain ) === '' ) {
                $plain = $this->form_fields['description']['default'];
            }

            $html  = $this->format_description_html( $plain );
            return $html;
        }

        /**
         * Build safe HTML with <strong> for the first line and normal text for the rest.
         */
        private function format_description_html( $text ) {
            // Strip any HTML tags the user might have entered, as we handle formatting.
            $text  = is_string( $text ) ? wp_strip_all_tags( $text ) : '';
            
            $parts = preg_split( "/\r?\n+/", trim( $text ), 2 );
            
            if ( ! is_array( $parts ) || empty( $parts ) || trim($parts[0]) === '' ) {
                return '';
            }

            $lead = esc_html( trim( $parts[0] ) );
            $rest = isset( $parts[1] ) ? esc_html( trim( $parts[1] ) ) : '';

            $html = '<strong>' . $lead . '</strong>';
            if ( $rest !== '' ) {
                $html .= '<br />' . $rest;
            }
            
            return $html;
        }

        protected function get_api_base() {
            if ( ! empty( $this->api_base ) ) return untrailingslashit( $this->api_base );
            return kc_get_api_url();
        }

        public function fetch_brand_info() {
            $this->init_settings();
            $key = $this->get_option( 'secret_key' );
            if ( empty( $key ) ) return;

            $url = kc_get_api_url() . '/checkout/brand';

            $resp = wp_remote_get( $url, array(
                'headers' => array( 'X-API-KEY' => $key ),
                'timeout' => 10,
            ) );
            if ( is_wp_error( $resp ) ) return;

            $body = json_decode( wp_remote_retrieve_body( $resp ), true );
            if ( empty( $body['data'] ) ) return;

            $brand_data = array(
                'brand' => sanitize_text_field( $body['data']['brand'] ?? '' ),
                'name'  => sanitize_text_field( $body['data']['name'] ?? '' ),
                'logo'  => esc_url_raw( $body['data']['logo'] ?? '' ),
            );
            update_option( 'kc_brand_cache', $brand_data );
            $this->log( 'Brand info fetched: ' . wp_json_encode( $brand_data ) );
        }

        protected function request( $method, $path, $body = null, $idempotency_key = null ) {
            // Basic rate limiting check
            $rate_limit_key = 'hcwc_api_requests_' . md5( $this->secret_key );
            $current_count = get_transient( $rate_limit_key );
            if ( $current_count === false ) {
                set_transient( $rate_limit_key, 1, 60 ); // 1 minute window
            } else {
                $current_count = intval( $current_count );
                if ( $current_count >= 100 ) { // 100 requests per minute limit
                    $this->log( 'Rate limit exceeded: too many API requests', 'warning' );
                    return array( 'code' => 429, 'body' => array( 'error' => 'Rate limit exceeded' ), 'headers' => array() );
                }
                set_transient( $rate_limit_key, $current_count + 1, 60 );
            }
            
            $this->log( 'API Request - Method: ' . $method . ', Path: ' . $path . ', Has body: ' . ( $body ? 'yes' : 'no' ) );
            
            $headers = array(
                'Content-Type' => 'application/json',
                'X-API-KEY'    => $this->secret_key,
                'User-Agent'   => kc_get_brand_name() . '-WooCommerce/' . KC_WC_VERSION,
            );
            if ( $idempotency_key && in_array( strtoupper( $method ), array('POST','PUT','PATCH','DELETE'), true ) ) {
                $headers['Idempotency-Key'] = $idempotency_key;
                $this->log( 'API Request - Using idempotency key: ' . substr( $idempotency_key, 0, 8 ) . '...' );
            }

            // Validate configuration
            if ( empty( $this->secret_key ) ) {
                $this->log( 'API Request warning: No secret key configured', 'warning' );
            }

            $args = array(
                'timeout' => 45,
                'headers' => $headers,
                'method'  => $method,
            );
            if ( $body !== null ) { 
                $args['body'] = wp_json_encode( $body );
                $masked_body = $this->mask_sensitive_data( $body );
                $this->log( 'API Request body (masked): ' . wp_json_encode( $masked_body ) );
            }

            $url = trailingslashit( $this->get_api_base() ) . ltrim( $path, '/' );
            $this->log( 'API Request URL: ' . $url );

            $start_time = microtime( true );
            $res = wp_remote_request( $url, $args );
            $duration = round( ( microtime( true ) - $start_time ) * 1000, 2 );
            
            if ( is_wp_error( $res ) ) {
                $error_message = $res->get_error_message();
                $this->log( 'API Request failed - Error: ' . $error_message . ', Duration: ' . $duration . 'ms', 'error' );
                return array( 'code' => 0, 'body' => null, 'headers' => array() );
            }
            
            $code = wp_remote_retrieve_response_code( $res );
            $response_body = wp_remote_retrieve_body( $res );
            $body_decoded = json_decode( $response_body, true );
            $hdrs = wp_remote_retrieve_headers( $res );
            
            $this->log( 'API Response - Code: ' . $code . ', Duration: ' . $duration . 'ms, Body size: ' . strlen( $response_body ) . ' bytes' );
            
            if ( $code >= 400 ) {
                $masked_response = $this->mask_sensitive_data( $body_decoded );
                $this->log( 'API Error response (masked): ' . wp_json_encode( $masked_response ), 'error' );
            } else {
                // Log successful responses at debug level (less verbose)
                if ( $this->logging ) {
                    $this->log( 'API Success - Response structure: ' . $this->get_response_structure( $body_decoded ), 'debug' );
                }
            }
            
            return array( 'code' => $code, 'body' => $body_decoded, 'headers' => $hdrs );
        }

        /**
         * Get a summary of response structure for logging (without sensitive data)
         */
        private function get_response_structure( $data ) {
            if ( ! is_array( $data ) ) {
                return gettype( $data );
            }
            
            $structure = array();
            foreach ( $data as $key => $value ) {
                if ( is_array( $value ) ) {
                    $structure[$key] = 'array(' . count( $value ) . ')';
                } else {
                    $structure[$key] = gettype( $value );
                }
            }
            
            return wp_json_encode( $structure );
        }

        public function kc_capture_transaction_on_return( $order_id ) {
            // Input validation
            if ( ! is_numeric( $order_id ) || $order_id <= 0 ) {
                $this->log( 'Transaction capture failed: Invalid order ID format', 'error' );
                return;
            }
            
            $this->log( 'Transaction capture initiated for order #' . $order_id );
            
            $order = wc_get_order( $order_id );
            if ( ! $order || $order->get_payment_method() !== $this->id ) {
                if ( ! $order ) {
                    $this->log( 'Transaction capture failed: Invalid order #' . $order_id, 'error' );
                } else {
                    $this->log( 'Transaction capture skipped: Order #' . $order_id . ' uses different payment method (' . $order->get_payment_method() . ')' );
                }
                return;
            }

            $this->log( 'Order found - Status: ' . $order->get_status() . ', Total: ' . $order->get_total() );

            // Ensure we have the session id (meta or ?sessionID= on the return URL)
            $session_id = $order->get_meta( '_hcwc_session_id' );
            if ( ! $session_id && isset( $_GET['sessionID'] ) ) {
                $session_id = sanitize_text_field( wp_unslash( $_GET['sessionID'] ) );
                // Validate session ID format (basic check for expected format)
                if ( ! preg_match( '/^[a-zA-Z0-9_-]+$/', $session_id ) ) {
                    $this->log( 'Invalid session ID format from URL parameter', 'error' );
                    return;
                }
                // Check if this session ID is already used by another order
                if ( $this->is_session_id_used_by_another_order( $session_id, $order->get_id() ) ) {
                    $this->log( 'Session ID from URL already used by another order: ' . $session_id, 'error' );
                    $order->add_order_note( kc_get_brand_name() . ': Session ID conflict detected. Please contact support.' );
                    return;
                }
                $order->update_meta_data( '_hcwc_session_id', $session_id );
                $this->log( 'Session ID obtained from URL parameter: ' . $session_id );
            }
            
            if ( ! $session_id ) { 
                $this->log( 'Transaction capture failed: Missing session ID', 'error' );
                $order->add_order_note( kc_get_brand_name() . ': missing session id on return.' ); 
                return; 
            }

            $this->log( 'Starting polling for session: ' . $session_id );

            $poll_delays = ( $this->payment_type === 'echeck' )
                ? array( 0, 1.0, 2.0, 3.0, 4.0, 5.0 )
                : array( 0, 0.5, 1.5, 3.0 );
            foreach ( $poll_delays as $attempt => $delay ) {
                if ( $delay ) { 
                    usleep( (int) ( $delay * 1e6 ) );
                    $this->log( 'Polling attempt ' . ($attempt + 1) . ' after ' . $delay . 's delay' );
                } else {
                    $this->log( 'Polling attempt 1 (immediate)' );
                }

                $res   = $this->request( 'GET', 'checkout/sessions/' . rawurlencode( $session_id ) );
                $body  = is_array( $res['body'] ) ? $res['body'] : array();
                $sess  = $body['data']['session'] ?? array();
                $txn   = $body['data']['transaction'] ?? array();

                $status   = strtolower( $sess['paymentStatus'] ?? '' );
                $txn_id   = $txn['_id'] ?? '';
                $last4    = $txn['paymentMethod']['last4']   ?? '';
                $network  = $txn['paymentMethod']['network'] ?? '';
                $amount_i = $txn['amount'] ?? null;

                $this->log( 'Polling result - Status: ' . ( $status ?: 'none' ) . ', Transaction ID: ' . ( $txn_id ?: 'none' ) . ', Amount: ' . ( $amount_i !== null ? $amount_i : 'none' ) );

                if ( $txn_id ) {
                    $order->update_meta_data( '_hcwc_payment_id', sanitize_text_field( $txn_id ) );
                    if ( $last4 && preg_match( '/^\d{4}$/', $last4 ) ) {   
                        $order->update_meta_data( '_hcwc_last4', sanitize_text_field( $last4 ) ); 
                    }
                    if ( $network && in_array( strtolower( $network ), array( 'visa', 'mastercard', 'amex', 'discover', 'jcb', 'diners' ), true ) ) { 
                        $order->update_meta_data( '_hcwc_network', sanitize_text_field( $network ) ); 
                    }
                    if ( ! is_null( $amount_i ) && is_numeric( $amount_i ) && $amount_i >= 0 ) { 
                        $order->update_meta_data( '_hcwc_tx_amount', floatval( $amount_i ) ); 
                    }
                    
                    $this->log( 'Transaction metadata updated - Payment ID: ' . $txn_id . ', Last4: ' . ( $last4 ?: 'none' ) . ', Network: ' . ( $network ?: 'none' ) );
                }

                if ( in_array( $status, array( 'paid', 'succeeded' ), true ) ) {
                    $this->log( 'Payment successful - Completing order' );
                    $order->payment_complete( $txn_id ?: '' );
                    $order->add_order_note( kc_get_brand_name() . ': paid (verified via session).' );
                    $order->save();
                    return;
                } else if ( in_array( $status, array( 'failed', 'cancelled', 'expired' ), true ) ) {
                    $this->log( 'Payment failed with status: ' . $status, 'warning' );
                    $order->add_order_note( kc_get_brand_name() . ': payment ' . $status . ' (verified via session).' );
                    if ( $status === 'failed' ) {
                        $order->update_status( 'failed', kc_get_brand_name() . ' payment failed.' );
                    }
                    $order->save();
                    return;
                }
            }

            $this->log( 'Polling completed - Payment status still pending after 4 attempts', 'warning' );
            $order->add_order_note( kc_get_brand_name() . ': session not yet paid after return; order left pending.' );
        }

        /** Public: Sync a single order from the payment gateway (session/transaction/refunds). */
        public function sync_order_from_gateway( WC_Order $order ): void {
            // Security validation for admin operations
            if ( is_admin() && ! $this->validate_admin_operation( 'hcwc_sync' ) ) {
                $this->log( 'Sync operation blocked: Security validation failed', 'error' );
                return;
            }
            
            $this->log( 'Starting order sync for order #' . $order->get_id() );
            
            if ( $order->get_payment_method() !== $this->id ) {
                $this->log( 'Order sync skipped: Order #' . $order->get_id() . ' uses different payment method (' . $order->get_payment_method() . ')' );
                return;
            }

            $this->log( 'Order sync validated - Status: ' . $order->get_status() . ', Total: ' . $order->get_total() );

            // 1) Ensure we know the session id
            $session_id = $order->get_meta('_hcwc_session_id');
            if ( ! $session_id && isset($_GET['sessionID']) ) {
                $session_id = sanitize_text_field( wp_unslash($_GET['sessionID']) );
                // Validate session ID format
                if ( ! preg_match( '/^[a-zA-Z0-9_-]+$/', $session_id ) ) {
                    $this->log( 'Invalid session ID format from URL parameter', 'error' );
                    return;
                }
                // Check if this session ID is already used by another order
                if ( $this->is_session_id_used_by_another_order( $session_id, $order->get_id() ) ) {
                    $this->log( 'Session ID from URL already used by another order: ' . $session_id, 'error' );
                    $order->add_order_note( kc_get_brand_name() . ': Session ID conflict detected. Please contact support.' );
                    return;
                }
                $order->update_meta_data('_hcwc_session_id', $session_id);
                $this->log( 'Session ID obtained from URL parameter: ' . $session_id );
            }

            if ( ! $session_id ) {
                $this->log( 'Order sync failed: No session ID found', 'warning' );
                return;
            }

            $this->log( 'Syncing with session ID: ' . $session_id );

            // 2) If we have a session, hydrate latest session + transaction
            $session = $tx = array();
            if ( $session_id ) {
                $r  = $this->request('GET', 'checkout/sessions/' . rawurlencode($session_id));
                $session = $r['body']['data']['session'] ?? array();
                $tx      = $r['body']['data']['transaction'] ?? array();
                
                $this->log( 'Session data retrieved - Status: ' . ( $session['paymentStatus'] ?? 'none' ) . ', Has transaction: ' . ( ! empty( $tx ) ? 'yes' : 'no' ) );
            }

            // 3) If no tx yet, try by stored payment_id or by listing transaction directly
            $txn_id = $order->get_meta('_hcwc_payment_id') ?: ($tx['_id'] ?? '');
            $this->log( 'Transaction ID resolution - From order meta: ' . ( $order->get_meta('_hcwc_payment_id') ?: 'none' ) . ', From session: ' . ( $tx['_id'] ?? 'none' ) );
            if ( ! $txn_id && ! empty($session['paymentIntent']) ) {
                // If your API uses paymentIntent as the canonical tx id (you showed it null earlier, so likely not)
                $txn_id = $session['paymentIntent'];
            }
            if ( ! $txn_id && ! empty($session['_id ']) ) {
                // Sometimes there's a way to filter by session: /transactions?sessionId=/
                $r2 = $this->request('GET', 'transactions?sessionId=' . rawurlencode($session['_id']));
                $list = $r2['body']['data']['transactions'] ?? $r2['body']['data'] ?? array();
                if ( is_array($list) && ! empty($list) && isset($list[0]['_id']) ) {
                    $tx = $list[0];
                    $txn_id = $tx['_id'];
                }
            }
            if ( $txn_id && empty($tx) ) {
                $r3 = $this->request('GET', 'transactions/' . rawurlencode($txn_id));
                $tx = $r3['body']['data']['transaction'] ?? $r3['body']['data'] ?? array();
            }

            // Save handy bits
            if ( $txn_id ) {
                $order->update_meta_data('_hcwc_payment_id', sanitize_text_field($txn_id));
                $this->log( 'Transaction ID saved to order meta: ' . $txn_id );
            }
            $status = strtolower( $session['paymentStatus'] ?? '' );
            if ( in_array($status, array('paid','succeeded'), true) && ! $order->has_status(array('processing','completed')) ) {
                $this->log( 'Payment confirmed - Completing order with status: ' . $status );
                $order->payment_complete( $txn_id ?: '' );
                $order->add_order_note(kc_get_brand_name() . ': synced → marked paid.');
            } else {
                $this->log( 'Payment status: ' . ( $status ?: 'none' ) . ', Order status: ' . $order->get_status() );
            }

            // 4) Sync refunds
            $this->log( 'Starting refund sync for order #' . $order->get_id() );
            $refunded_total_api = 0.0;
            $refunds = $tx['refunds'] ?? array(); // if present
            if ( is_array($refunds) && $refunds ) {
                foreach ( $refunds as $r ) {
                    $refunded_total_api += (float) ( $r['amount'] ?? 0 );
                }
            } else {
                // Try common shapes
                if ( isset($tx['refundedAmount']) ) {
                    $refunded_total_api = (float) $tx['refundedAmount'];
                } else if ( $txn_id ) {
                    // Last resort: GET /refunds?transactionId=...
                    $r4 = $this->request('GET', 'refunds?transactionId=' . rawurlencode($txn_id));
                    $refunds_list = $r4['body']['data']['refunds'] ?? $r4['body']['data'] ?? array();
                    if ( is_array($refunds_list) ) {
                        foreach ( $refunds_list as $r ) {
                            $refunded_total_api += (float) ( $r['amount'] ?? 0 );
                        }
                    }
                }
            }

            // Already reflected in Woo?
            $already_refunded = (float) wc_format_decimal( $order->get_total_refunded(), 2 );
            $delta = round( $refunded_total_api - $already_refunded, 2 );

            $this->log( 'Refund sync analysis - API refunded: ' . $refunded_total_api . ', WC refunded: ' . $already_refunded . ', Delta: ' . $delta );

            if ( $delta > 0 ) {
                $this->log( 'Creating WooCommerce refund for: ' . wc_price( $delta ) );
                $created = wc_create_refund( array(
                    'order_id'       => $order->get_id(),
                    'amount'         => $delta,
                    'reason'         => 'Synced from ' . kc_get_brand_name(),
                    'refund_payment' => false,      // do not call gateway again
                    'restock_items'  => true,       // or make this a setting
                ) );
                if ( is_wp_error($created) ) {
                    $this->log( 'Refund creation failed: ' . $created->get_error_message(), 'error' );
                    $order->add_order_note(kc_get_brand_name() . ': sync found ' . wc_price($delta) . ' refunded, but Woo refund creation failed.');
                } else {
                    $this->log( 'Refund created successfully: ' . wc_price( $delta ) );
                    $order->add_order_note(kc_get_brand_name() . ': synced refund ' . wc_price($delta) . '.');
                }
            } else if ( $delta < 0 ) {
                $this->log( 'WC has more refunds than API - Delta: ' . $delta, 'warning' );
            }

            $order->update_meta_data('_hcwc_last_synced_at', gmdate('c'));
            $order->save();
            $this->log( 'Order sync completed for order #' . $order->get_id() );
}
        public function is_available() {
            if ( 'yes' !== $this->enabled ) {
                return false;
            }

            if ( empty( $this->secret_key ) ) {
                return false;
            }

            $settings = get_option( 'woocommerce_hcwc_settings', array() );
            if ( empty( $settings['api_domain'] ) ) {
                return false;
            }

            return true;
        }

        /**
         * Build comprehensive line items including products, coupons, shipping, fees, and taxes
         */
        private function build_line_items( $order ) {
            $this->log( 'Using simplified single line item approach' );
            
            $order_total = floatval( $order->get_total() );
            $order_id = $order->get_id();
            
            // Create one simple line item with the total order amount
            $store_name = get_bloginfo( 'name' );
            $product_name = $store_name ? $store_name . ' Order Total' : 'Order Total';
            
            $line_items = array(
                array(
                    'productName' => $product_name,
                    'unitPrice'   => number_format( $order_total, 2, '.', '' ),
                    'quantity'    => 1,
                    'type'        => 'order'
                )
            );
            
            $this->log( 'Created single line item - Order #' . $order_id . ': $' . $order_total );
            
            return array(
                'line_items' => $line_items,
                'calculated_total' => $order_total
            );
        }

        public function process_payment( $order_id ) {
            // Input validation
            if ( ! is_numeric( $order_id ) || $order_id <= 0 ) {
                $this->log( 'Payment failed: Invalid order ID format', 'error' );
                wc_add_notice( __( 'Invalid order. Please try again.', 'hcwc' ), 'error' );
                return array( 'result' => 'fail' );
            }
            
            $this->log( 'Starting payment process for order #' . $order_id );
            
            $order = wc_get_order( $order_id );
            if ( ! $order ) {
                $this->log( 'Invalid order ID: ' . $order_id, 'error' );
                wc_add_notice( __( 'Invalid order. Please try again.', 'hcwc' ), 'error' );
                return array( 'result' => 'fail' );
            }

            // ** THE FIX **: Immediately set the correct title before any other processing.
            $order->set_payment_method_title( strtolower( kc_get_brand_name() ) );
            $order->save();

            $this->log( 'Order found - Currency: ' . $order->get_currency() . ', Total: ' . $order->get_total() );

            // Validate gateway configuration
            if ( empty( $this->secret_key ) ) {
                $this->log( 'Gateway misconfiguration: Secret key missing', 'error' );
                wc_add_notice( __( 'Payment gateway is not properly configured.', 'hcwc' ), 'error' );
                return array( 'result' => 'fail' );
            }

            if ( empty( $this->vendor_id ) ) {
                $this->log( 'Gateway misconfiguration: Vendor ID missing', 'error' );
                wc_add_notice( __( 'Payment gateway is not properly configured.', 'hcwc' ), 'error' );
                return array( 'result' => 'fail' );
            }

            // Build comprehensive lineItems from WC order including products, discounts, shipping, taxes
            $line_item_result = $this->build_line_items( $order );
            $line_items = $line_item_result['line_items'];
            $calculated_total = $line_item_result['calculated_total'];
            $order_total = floatval( $order->get_total() );
            
            $this->log( 'Testing empty line items approach - Line items count: ' . count( $line_items ) . ', WC Total: ' . $order_total );
            
            // Canonical WooCommerce URLs using proper helper methods
            $success = $this->get_return_url( $order );   // ✅ correct thank-you URL (after successful payment)
            $return  = wc_get_checkout_url();              // ✅ checkout page (for "back to store" button)
            $cancel  = $order->get_cancel_order_url();     // ✅ proper cancel URL (with key & id)

            $this->log( 'Generated URLs - Success: ' . $success . ', Return: ' . $return . ', Cancel: ' . $cancel );

            $customer_details = array();
            $billing_email = $order->get_billing_email();
            $billing_first = $order->get_billing_first_name();
            $billing_last  = $order->get_billing_last_name();
            if ( $billing_email && $billing_first && $billing_last ) {
                $customer_details = array(
                    'firstName' => $billing_first,
                    'lastName'  => $billing_last,
                    'email'     => $billing_email,
                );
                $billing_phone = $order->get_billing_phone();
                if ( $billing_phone ) {
                    $customer_details['phoneNumber'] = $billing_phone;
                }
                $billing_addr1 = $order->get_billing_address_1();
                $billing_city  = $order->get_billing_city();
                $billing_country = $order->get_billing_country();
                $billing_zip   = $order->get_billing_postcode();
                if ( $billing_addr1 && $billing_city && $billing_country && $billing_zip ) {
                    $customer_details['billingAddress'] = array(
                        'line1'   => $billing_addr1,
                        'line2'   => $order->get_billing_address_2(),
                        'city'    => $billing_city,
                        'region'  => $order->get_billing_state(),
                        'country' => $billing_country,
                        'zipcode' => $billing_zip,
                    );
                }
            }

            $payload = array(
                'vendor'      => $this->vendor_id ?: null,
                'env'         => 'prod',
                'mode'        => $this->mode ?: 'payment',
                'currency'    => strtolower( $order->get_currency() ),
                'amount'      => number_format( $order_total, 2, '.', '' ),
                'lineItems'   => $line_items,
                'successURL'  => $success,
                'returnURL'   => $return,
                'cancelURL'   => $cancel,
                'metadata'    => array(
                    'order_id'       => (string) $order->get_id(),
                    'site_url'       => home_url(),
                    'wc_total'       => $order_total,
                    'calculated_total' => $calculated_total,
                    'coupon_count'   => count( $order->get_coupons() ),
                    'has_shipping'   => count( $order->get_shipping_methods() ) > 0,
                ),
            );
            if ( ! empty( $customer_details ) ) {
                $payload['customerDetails'] = $customer_details;
            }

            $this->log( 'API payload prepared - Amount: ' . $order_total . ', Items: ' . count( $line_items ) . ' (products: ' . count( $order->get_items() ) . ', coupons: ' . count( $order->get_coupons() ) . ')' );

            // Idempotency key recommended
            add_filter( 'http_request_args', function( $args, $url ) {
                if ( ! isset( $args['headers']['Idempotency-Key'] ) ) {
                    $args['headers']['Idempotency-Key'] = wp_generate_uuid4();
                }
                return $args;
            }, 10, 2 );

            // Create hosted checkout session
            $idk = 'create_' . $order->get_id() . '_' . wp_generate_uuid4();
            $this->log( 'Making API request to create checkout session for order #' . $order_id );

            $res = $this->request( 'POST', 'checkout/sessions/create', $payload );
            $code = $res['code']; $body = $res['body'];

            $this->log( 'API response received - Code: ' . $code . ', Has paymentURL: ' . ( ! empty( $body['data']['paymentURL'] ) ? 'yes' : 'no' ) );

            if ( $code >= 200 && $code < 300 && ! empty( $body['data']['paymentURL'] ) ) {
                $session = isset( $body['data']['session'] ) ? $body['data']['session'] : array();
                $request_id = ! empty( $body['data']['requestID'] ) ? sanitize_text_field( $body['data']['requestID'] ) : '';
                $session_id = ! empty( $session['_id'] ) ? sanitize_text_field( $session['_id'] ) : '';
                
                $this->log( 'Session created successfully - RequestID: ' . ( $request_id ?: 'none' ) . ', SessionID: ' . ( $session_id ?: 'none' ) );
                
                // Check if this session ID is already used by another order (extra safety check)
                if ( $session_id && $this->is_session_id_used_by_another_order( $session_id, $order->get_id() ) ) {
                    $this->log( 'Session ID from API already used by another order: ' . $session_id, 'error' );
                    wc_add_notice( __( 'Payment gateway error. Please try again.', 'hcwc' ), 'error' );
                    return array( 'result' => 'fail' );
                }
                
                $order->update_meta_data( '_hcwc_request_id', $request_id );
                $order->update_meta_data( '_hcwc_session_id', $session_id );
                $order->update_meta_data( '_hcwc_api_base', esc_url_raw( $this->get_api_base() ) );
                $order->save();

                $payment_url = esc_url_raw( $body['data']['paymentURL'] );
                $this->log( 'Payment process successful - Redirecting to: ' . $payment_url );

                return array(
                    'result'   => 'success',
                    'redirect' => $payment_url,
                );
            }

            // Log detailed error information
            $error_details = array(
                'code' => $code,
                'body' => $this->mask_sensitive_data( $body ),
                'expected_keys' => array( 'data.paymentURL' )
            );
            $this->log( 'Payment process failed - ' . wp_json_encode( $error_details ), 'error' );

            wc_add_notice( 'Could not start ' . kc_get_brand_name() . ' checkout. Please try again.', 'error' );
            return array( 'result' => 'fail' );
        }

        /** Refund support */
        public function process_refund( $order_id, $amount = null, $reason = '' ) {
            // Input validation
            if ( ! is_numeric( $order_id ) || $order_id <= 0 ) {
                $this->log( 'Refund failed: Invalid order ID format', 'error' );
                return new WP_Error( 'invalid_order_id', 'Invalid order ID format' );
            }
            
            if ( $amount !== null && ( ! is_numeric( $amount ) || $amount <= 0 ) ) {
                $this->log( 'Refund failed: Invalid amount format', 'error' );
                return new WP_Error( 'invalid_amount', 'Invalid refund amount' );
            }
            
            // Sanitize reason
            $reason = sanitize_text_field( $reason );
            
            $this->log( 'Starting refund process for order #' . $order_id . ' - Amount: ' . ( $amount ?: 'N/A' ) . ', Reason: ' . ( $reason ?: 'No reason provided' ) );
            
            $order = wc_get_order( $order_id );
            if ( ! $order ) {
                $this->log( 'Refund failed: Invalid order ID #' . $order_id, 'error' );
                return new WP_Error( 'invalid_order', 'Order not found' );
            }

            $this->log( 'Order found - Payment method: ' . $order->get_payment_method() . ', Status: ' . $order->get_status() . ', Total: ' . $order->get_total() );

            $payment_id = $order->get_meta( '_hcwc_payment_id' );

            // Check if this order was paid with this gateway
            if ( $order->get_payment_method() !== $this->id ) {
                $this->log( 'Refund validation failed: Order not paid with this gateway (method: ' . $order->get_payment_method() . ')', 'error' );
                return new WP_Error( 'invalid_payment_gateway', 'Order was not paid with ' . kc_get_brand_name() );
            }

            // Check if gateway is properly configured
            if ( empty( $this->secret_key ) ) {
                $this->log( 'Refund validation failed: Gateway not configured (missing secret key)', 'error' );
                return new WP_Error( 'invalid_payment_gateway', kc_get_brand_name() . ' gateway is not properly configured' );
            }

            if ( empty( $payment_id ) ) {
                $this->log( 'Refund validation failed: Payment ID not found in order meta', 'error' );
                $this->log( 'Order meta keys: ' . implode( ', ', array_keys( $order->get_meta_data() ) ) );
                return new WP_Error( 'missing_payment_id', 'Payment ID not found - order may not be fully processed yet' );
            }

            $this->log( 'Refund validation passed - Payment ID: ' . $payment_id );

            // Enhanced debugging of order refund state
            $order_total = wc_format_decimal( $order->get_total(), 2 );
            $already_refunded = wc_format_decimal( $order->get_total_refunded(), 2 );
            $remaining_refundable = wc_format_decimal( $order_total - $already_refunded, 2 );
            $refund_amount = $amount ? wc_format_decimal( $amount, 2 ) : $remaining_refundable;

            // Debug existing refunds
            $existing_refunds = $order->get_refunds();
            $this->log( 'Refund debugging - Order has ' . count( $existing_refunds ) . ' existing refunds' );
            foreach ( $existing_refunds as $refund ) {
                $this->log( 'Existing refund: ID=' . $refund->get_id() . ', Amount=' . $refund->get_amount() . ', Reason="' . $refund->get_reason() . '"' );
            }

            // Enhanced logging with raw values for debugging
            $this->log( 'Refund amount validation (formatted) - Order total: ' . $order_total . ', Already refunded: ' . $already_refunded . ', Remaining: ' . $refund_amount );
            $this->log( 'Refund amount validation (raw) - Order total: ' . $order->get_total() . ', Already refunded: ' . $order->get_total_refunded() . ', Requested raw: ' . $amount );

            // Check if there are any existing refunds and handle this edge case
            if ( $already_refunded > 0 && count( $existing_refunds ) > 0 ) {
                $this->log( 'Detected existing refunds. This might be a retry of a failed refund attempt.', 'warning' );
                // Allow the refund to proceed if it's for the same amount, as it might be a retry
                if ( abs( $refund_amount - $already_refunded ) < 0.01 ) {
                    $this->log( 'Allowing refund retry for same amount - treating as API-only refund', 'info' );
                    // Skip WooCommerce validation since the refund record already exists
                    // We'll just try to send it to the payment API
                } else {
                    $this->log( 'Refund amount mismatch - requested: ' . $refund_amount . ', already refunded: ' . $already_refunded, 'error' );
                }
            }

            // Use a more generous tolerance (0.05) for floating point comparison and round both values
            $tolerance = 0.05;
            $remaining_rounded = round( (float) $remaining_refundable, 2 );
            $refund_rounded = round( (float) $refund_amount, 2 );
            $difference = $refund_rounded - $remaining_rounded;
            
            $this->log( 'Refund validation comparison - Remaining rounded: ' . $remaining_rounded . ', Refund rounded: ' . $refund_rounded . ', Difference: ' . $difference . ', Tolerance: ' . $tolerance );

            // Allow the refund if there are existing refunds and this is likely a retry
            $is_retry = ( $already_refunded > 0 && abs( $refund_amount - $already_refunded ) < 0.01 );
            
            if ( $difference > $tolerance && ! $is_retry ) {
                $this->log( 'Refund validation failed: Amount (' . $refund_amount . ') exceeds remaining refundable amount (' . $remaining_refundable . ') by ' . $difference . ' (tolerance: ' . $tolerance . ')', 'error' );
                return new WP_Error( 'invalid_refund_amount', 'Refund amount exceeds remaining refundable amount' );
            }

            if ( $refund_amount <= 0 ) {
                $this->log( 'Refund validation failed: Invalid refund amount (' . $refund_amount . ')', 'error' );
                return new WP_Error( 'invalid_refund_amount', 'Refund amount must be greater than zero' );
            }

            if ( $is_retry ) {
                $this->log( 'Processing as refund retry - WooCommerce refund already exists, sending to payment API only', 'info' );
            }

            // Validate payment ID format
            if ( ! preg_match( '/^[a-zA-Z0-9_-]+$/', $payment_id ) ) {
                $this->log( 'Refund validation failed: Invalid payment ID format', 'error' );
                return new WP_Error( 'invalid_payment_id', 'Invalid payment ID format' );
            }

            $payload = array(
                'payment_id' => sanitize_text_field( $payment_id ),
                'amount'     => number_format( $refund_amount, 2, '.', '' ),
                'reason'     => sanitize_text_field( $reason ),
            );

            $this->log( 'Refund payload prepared - Masked: ' . wp_json_encode( $this->mask_sensitive_data( $payload ) ) );

            // Use the known correct refund endpoint
            $endpoint = 'transactions/' . $payment_id . '/refund';
            $this->log( 'Making refund API request to endpoint: ' . $endpoint );
            
            $res = $this->request( 'POST', $endpoint, $payload );
            $code = $res['code'];
            $body = $res['body'];

            $this->log( 'Refund API response - Code: ' . $code . ', Body length: ' . strlen( wp_json_encode( $body ) ) . ' chars' );

            if ( $code >= 200 && $code < 300 ) {
                $this->log( 'Refund processed successfully via payment API' );
                if ( $is_retry ) {
                    $order->add_order_note( sprintf( '%s refund retry successful: %s. API confirmed refund processing. Reason: %s', kc_get_brand_name(), wc_price( $refund_amount ), $reason ) );
                } else {
                    $order->add_order_note( sprintf( '%s refund processed: %s. Reason: %s', kc_get_brand_name(), wc_price( $refund_amount ), $reason ) );
                }
                return true;
            } else {
                // Log the full response for debugging (with sensitive data masked)
                $masked_body = $this->mask_sensitive_data( $body );
                $this->log( 'Refund failed - Code: ' . $code . ', Body: ' . wp_json_encode( $masked_body ), 'error' );

                // Try different error message formats
                $error_msg = 'Unknown error';
                if ( isset( $body['error'] ) ) {
                    $error_msg = is_array( $body['error'] ) ? wp_json_encode( $body['error'] ) : $body['error'];
                } elseif ( isset( $body['message'] ) ) {
                    $error_msg = $body['message'];
                } elseif ( isset( $body['errors'] ) && is_array( $body['errors'] ) ) {
                    $error_msg = implode( ', ', $body['errors'] );
                } elseif ( is_string( $body ) ) {
                    $error_msg = $body;
                } elseif ( $code === 400 ) {
                    $error_msg = 'Invalid request data';
                } elseif ( $code === 401 ) {
                    $error_msg = 'Authentication failed - check gateway credentials';
                } elseif ( $code === 404 ) {
                    $error_msg = 'Payment not found - transaction may not exist';
                } elseif ( $code === 500 ) {
                    $error_msg = 'Server error - please try again later';
                }

                $this->log( 'Refund error message: ' . $error_msg, 'error' );
                $order->add_order_note( kc_get_brand_name() . ' refund failed: ' . $error_msg );
                
                return new WP_Error( 'refund_failed', 'Refund failed: ' . $error_msg );
            }
        }

    // Webhook endpoint removed in simplified plugin

        /**
         * Validate sensitive operations with proper security checks
         */
        private function validate_admin_operation( $action = 'hcwc_admin' ) {
            // Check if user has proper capabilities
            if ( ! current_user_can( 'manage_woocommerce' ) ) {
                $this->log( 'Security: Unauthorized access attempt for ' . $action, 'warning' );
                return false;
            }
            
            // For AJAX requests, verify nonce
            if ( wp_doing_ajax() && ! empty( $_POST['security'] ) ) {
                if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['security'] ) ), $action ) ) {
                    $this->log( 'Security: Invalid nonce for ' . $action, 'warning' );
                    return false;
                }
            }
            
            return true;
        }

        protected function log( $msg, $level = 'info', $context = array() ) {
            if ( ! $this->logging ) return;
            if ( function_exists( 'wc_get_logger' ) ) {
                $logger = wc_get_logger();
                $context['source'] = 'hcwc';
                $logger->log( $level, $msg, $context );
            }
        }

        /**
         * Mask sensitive data in logs
         */
        private function mask_sensitive_data( $data ) {
            if ( is_string( $data ) ) {
                return $data;
            }
            
            if ( ! is_array( $data ) ) {
                return $data;
            }
            
            $masked = $data;
            $sensitive_keys = array( 'vendor', 'secret_key', 'password', 'token', 'key', 'authorization' );
            
            foreach ( $sensitive_keys as $key ) {
                if ( isset( $masked[$key] ) ) {
                    $value = (string) $masked[$key];
                    if ( strlen( $value ) > 8 ) {
                        $masked[$key] = substr( $value, 0, 4 ) . '****' . substr( $value, -4 );
                    } else {
                        $masked[$key] = '****';
                    }
                }
            }
            
            // Recursively mask nested arrays
            foreach ( $masked as $key => $value ) {
                if ( is_array( $value ) ) {
                    $masked[$key] = $this->mask_sensitive_data( $value );
                }
            }
            
            return $masked;
        }

        /**
         * Check if a session ID is already used by another order
         */
        private function is_session_id_used_by_another_order( $session_id, $exclude_order_id = null ) {
            if ( empty( $session_id ) ) {
                return false;
            }

            $args = array(
                'limit'        => 1,
                'meta_key'     => '_hcwc_session_id',
                'meta_value'   => $session_id,
                'meta_compare' => '=',
                'return'       => 'ids',
            );

            if ( $exclude_order_id ) {
                $args['exclude'] = array( $exclude_order_id );
            }

            $orders = wc_get_orders( $args );
            return ! empty( $orders );
        }
    }

    /**
     * GLOBAL HOOK: Force payment method title to be consistent
     * This runs at a high priority to override any other title formatting
     */
    add_filter( 'woocommerce_order_get_payment_method_title', function( $title, $order ) {
        if ( is_object( $order ) && method_exists( $order, 'get_payment_method' ) && $order->get_payment_method() === 'hcwc' ) {
            return strtolower( kc_get_brand_name() );
        }
        return $title;
    }, 999, 2 ); // Very high priority

    /**
     * GLOBAL HOOK: Also override the order item totals display
     */
    add_filter( 'woocommerce_get_order_item_totals', function( $total_rows, $order ) {
        if ( is_object( $order ) && method_exists( $order, 'get_payment_method' ) && $order->get_payment_method() === 'hcwc' ) {
            if ( isset( $total_rows['payment_method'] ) ) {
                $total_rows['payment_method']['value'] = 'Payment via ' . strtolower( kc_get_brand_name() );
            }
        }
        return $total_rows;
    }, 999, 2 ); // Very high priority

    /**
     * Add Settings link on Plugins page.
     */
    add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), function( $links ) {
        $url = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=hcwc' );
        $links[] = '<a href="' . esc_url( $url ) . '">Settings</a>';
        return $links;
    } );

    /**
     * Enqueue redirect fallback script on checkout pages.
     */
    add_action( 'wp_enqueue_scripts', function() {
        if ( is_checkout() && ! is_wc_endpoint_url( 'order-received' ) ) {
            wp_enqueue_script(
                'hcwc-redirect-fallback',
                KC_WC_PLUGIN_URL . 'assets/js/hcwc-redirect-fallback.js',
                array(),
                KC_WC_VERSION,
                true
            );
            wp_localize_script( 'hcwc-redirect-fallback', 'kcGatewayData', array(
                'brandName' => kc_get_brand_name(),
            ) );
        }
    } );

    /** WooCommerce Blocks registration (simple; server-side processing handles the charge) */
    add_action( 'woocommerce_blocks_loaded', function() {
        // Static flag to prevent duplicate registration
        static $kc_blocks_registered = false;
        if ( $kc_blocks_registered ) {
            return;
        }
        
        if ( ! class_exists( '\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
            return;
        }
        
        require_once KC_WC_PLUGIN_DIR . 'includes/class-wc-gateway-hcwc-blocks.php';
        add_action( 'woocommerce_blocks_payment_method_type_registration', function( \Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $registry ) {
            // Additional check to prevent duplicate registration
            if ( $registry->is_registered( 'hcwc' ) ) {
                return;
            }
            $blocks_instance = new WC_Gateway_HCWC_Blocks();
            $registry->register( $blocks_instance );
        } );
        
        $kc_blocks_registered = true;
    } );

    // Fix payment method title when new orders are processed
    add_action( 'woocommerce_checkout_order_processed', function( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( $order && $order->get_payment_method() === 'hcwc' ) {
            $order->set_payment_method_title( strtolower( kc_get_brand_name() ) );
            $order->save();
        }
    }, 5 ); // Changed from 20 to 5 to run earlier

    // Additional hook: Fix title during order creation (even earlier)
    add_action( 'woocommerce_checkout_create_order', function( $order, $data ) {
        if ( $order && isset( $data['payment_method'] ) && $data['payment_method'] === 'hcwc' ) {
            $order->set_payment_method_title( strtolower( kc_get_brand_name() ) );
        }
    }, 5, 2 );

    // One-time migration from legacy 'konacash' gateway ID to 'hcwc'
    if ( ! get_option( 'hcwc_migrated_from_konacash' ) ) {
        $old = get_option( 'woocommerce_konacash_settings', array() );
        if ( ! empty( $old ) && empty( get_option( 'woocommerce_hcwc_settings', array() ) ) ) {
            update_option( 'woocommerce_hcwc_settings', $old );
        }
        $old_hours = get_option( 'konacash_auto_sync_hours' );
        if ( $old_hours !== false && get_option( 'hcwc_auto_sync_hours' ) === false ) {
            update_option( 'hcwc_auto_sync_hours', $old_hours );
        }
        global $wpdb;
        $wpdb->update( $wpdb->postmeta, array( 'meta_value' => 'hcwc' ), array( 'meta_key' => '_payment_method', 'meta_value' => 'konacash' ) );
        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}wc_orders'" ) ) {
            $wpdb->update( $wpdb->prefix . 'wc_orders', array( 'payment_method' => 'hcwc' ), array( 'payment_method' => 'konacash' ) );
        }
        update_option( 'hcwc_migrated_from_konacash', 1 );
    }
}, 1 );


register_activation_hook( __FILE__, function() {
    // Migrate settings from legacy plugin if upgrading
    $old = get_option( 'woocommerce_konacash_settings', array() );
    $existing_settings = get_option( 'woocommerce_hcwc_settings', array() );
    if ( ! empty( $old ) && empty( $existing_settings ) ) {
        $existing_settings = $old;
        update_option( 'woocommerce_hcwc_settings', $existing_settings );
    }
    // Set default store domain if not already set
    if ( empty( $existing_settings['store_domain'] ) ) {
        $existing_settings['store_domain'] = untrailingslashit( home_url() );
        update_option( 'woocommerce_hcwc_settings', $existing_settings );
    }
} );
register_deactivation_hook( __FILE__, function() {} );

add_action( 'woocommerce_admin_order_data_after_order_details', 'kc_admin_order_panel', 10, 1 );

if ( ! function_exists( 'kc_admin_order_panel' ) ) {
function kc_admin_order_panel( $order ) {
    if ( ! $order instanceof WC_Order ) {
        return;
    }

    // Always expose the gateway for your admin JS
    echo '<input type="hidden" id="kc_order_gateway" value="' . esc_attr( $order->get_payment_method() ) . '"/>';

    // Only show gateway specifics for gateway orders
    if ( $order->get_payment_method() !== 'hcwc' ) {
        return;
    }

    $txn_id     = $order->get_meta('_hcwc_payment_id') ?: '—';
    $session_id = $order->get_meta('_hcwc_session_id') ?: '—';

    echo '<p><strong>' . esc_html( kc_get_brand_name() ) . ' Transaction:</strong> ' . esc_html( $txn_id ) . '</p>';
    echo '<p><strong>' . esc_html( kc_get_brand_name() ) . ' Session:</strong> ' . esc_html( $session_id ) . '</p>';
    
    // Add refund reason dropdown for gateway orders
    ?>
    <script>
    jQuery(function($){
        if ($('#kc_order_gateway').val() === 'hcwc') {
            
            function addRefundDropdown() {
                var $ta = $('#refund_reason, textarea[name="refund_reason"]');
                
                if ($ta.length && !$('#kc_refund_reason').length) {
                    var $wrap = $('<p class="form-field kc-refund-reason-field" style="margin: 10px 0;"></p>');
                    var $label = $('<label for="kc_refund_reason" style="font-weight: bold;"><?php echo esc_js( kc_get_brand_name() ); ?> refund reason</label>');
                    var $sel = $('<select id="kc_refund_reason" name="kc_refund_reason" style="width: 100%; margin-top: 5px;"></select>');
                    
                    $sel.append('<option value="requested_by_customer">Requested by customer</option>');
                    $sel.append('<option value="duplicate">Duplicate</option>');
                    $sel.append('<option value="fraudulent">Fraudulent</option>');
                    
                    $wrap.append($label).append('<br/>').append($sel);
                    $ta.after($wrap);
                    $ta.prop('readonly', true).attr('placeholder', '<?php echo esc_js( kc_get_brand_name() ); ?> requires a predefined reason; use the dropdown below.');
                    
                    var sync = function(){ $ta.val($sel.val()); };
                    $sel.on('change', sync);
                    sync();
                    
                    // Ensure refund amount fields remain editable
                    $('#refund_amount, input[name="refund_amount"], .refund_amount').prop('readonly', false).prop('disabled', false);
                }
            }
            
            // Ensure refund amount field is always editable for gateway orders
            function ensureRefundAmountEditable() {
                $('#refund_amount, input[name="refund_amount"], .refund_amount').each(function() {
                    $(this).prop('readonly', false).prop('disabled', false);
                });
            }
            
            // Watch for DOM changes to handle AJAX refreshes after refund failures
            function setupMutationObserver() {
                if (window.MutationObserver) {
                    var observer = new MutationObserver(function(mutations) {
                        var shouldCheck = false;
                        mutations.forEach(function(mutation) {
                            if (mutation.type === 'childList') {
                                mutation.addedNodes.forEach(function(node) {
                                    if (node.nodeType === 1) { // Element node
                                        if ($(node).find('#refund_reason, textarea[name="refund_reason"], #refund_amount, input[name="refund_amount"]').length ||
                                            $(node).is('#refund_reason, textarea[name="refund_reason"], #refund_amount, input[name="refund_amount"]')) {
                                            shouldCheck = true;
                                        }
                                    }
                                });
                            }
                        });
                        if (shouldCheck) {
                            setTimeout(function() {
                                addRefundDropdown();
                                ensureRefundAmountEditable();
                            }, 100);
                        }
                    });
                    
                    // Observe the entire order data area
                    var orderData = $('#order_data, .woocommerce-order-data, body').get(0);
                    if (orderData) {
                        observer.observe(orderData, {
                            childList: true,
                            subtree: true
                        });
                    }
                }
            }
            
            // Handle refund button clicks
            $(document).on('click', '.refund-items, .do-manual-refund, button[name="refund_amount"]', function() {
                setTimeout(function() {
                    addRefundDropdown();
                    ensureRefundAmountEditable();
                }, 100);
                setTimeout(function() {
                    addRefundDropdown();
                    ensureRefundAmountEditable();
                }, 500);
                setTimeout(function() {
                    addRefundDropdown();
                    ensureRefundAmountEditable();
                }, 1000);
            });
            
            // Watch for error messages that might indicate refund failure
            $(document).on('DOMNodeInserted', function(e) {
                var $target = $(e.target);
                if ($target.hasClass('notice') || $target.hasClass('error') || 
                    $target.hasClass('woocommerce-message') || $target.find('.notice, .error, .woocommerce-message').length) {
                    // When error/notice appears, refund UI might be refreshed
                    setTimeout(function() {
                        addRefundDropdown();
                        ensureRefundAmountEditable();
                    }, 500);
                    setTimeout(function() {
                        addRefundDropdown();
                        ensureRefundAmountEditable();
                    }, 1500);
                }
            });
            
            // Periodic check with longer intervals to catch edge cases
            var checkInterval = setInterval(function() {
                if ($('#refund_reason:visible, textarea[name="refund_reason"]:visible').length && !$('#kc_refund_reason').length) {
                    addRefundDropdown();
                }
                ensureRefundAmountEditable();
            }, 2000);
            
            // Initial setup
            addRefundDropdown();
            ensureRefundAmountEditable();
            setupMutationObserver();
            
            // Stop periodic checking after 5 minutes
            setTimeout(function() {
                clearInterval(checkInterval);
            }, 300000);
        }
    });
    </script>
    <?php
}
}


add_action( 'admin_enqueue_scripts', function( $hook ) {
    if ( $hook === 'post.php' || $hook === 'post-new.php' ) {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ( $screen && $screen->post_type === 'shop_order' ) {
            // Refund dropdown is now handled inline in kc_admin_order_panel()
            // No external JS file needed
        }
    }
} );

// Add the action to the dropdown in Order actions
add_filter('woocommerce_order_actions', function($actions){
    $actions['kc_sync_status'] = 'Sync ' . kc_get_brand_name() . ' status';
    return $actions;
});

// Handle it: fetch gateway instance and call the method above
add_action('woocommerce_order_action_kc_sync_status', function($order){
    if ( ! $order instanceof WC_Order ) return;
    $pgs = wc()->payment_gateways()->payment_gateways();
    if ( empty($pgs['hcwc']) ) { $order->add_order_note(kc_get_brand_name() . ': gateway not available.'); return; }
    /** @var WC_Gateway_HCWC $gw */
    $gw = $pgs['hcwc'];
    $gw->sync_order_from_gateway( $order );
});

// After class WC_Gateway_HCWC definition or near bottom of file add helper if not present
if ( ! function_exists( 'hcwc_batch_auto_sync_orders' ) ) {
    /**
     * Batch auto sync for gateway orders lacking a transaction id within configured window.
     * Runs silently on Orders list load. Uses transient lock to avoid repeated API hits.
     */
    function hcwc_batch_auto_sync_orders() {
        // Debug logging to see if function is called at all
        hcwc_log( 'Auto-sync: function called - checking conditions' );
        
        if ( ! is_admin() ) {
            hcwc_log( 'Auto-sync: not admin - exiting' );
            return;
        }
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            hcwc_log( 'Auto-sync: user cannot manage woocommerce - exiting' );
            return;
        }
        
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        
        // Support both classic and HPOS orders screens
        $valid_screens = array( 'edit-shop_order', 'woocommerce_page_wc-orders' );
        if ( ! $screen || ! in_array( $screen->id, $valid_screens, true ) ) {
            return;
        }

        // Check and log transient lock status
        $lock_exists = get_transient( 'kc_auto_sync_lock' );
        if ( $lock_exists ) {
            hcwc_log( 'Auto-sync: locked (transient exists) - exiting' );
            return;
        }
        
        hcwc_log( 'Auto-sync: setting lock and proceeding' );
        set_transient( 'kc_auto_sync_lock', 1, 15 );

        $hours = (int) get_option( 'hcwc_auto_sync_hours', 24 );
        if ( $hours < 1 ) { $hours = 1; }
        if ( $hours > 48 ) { $hours = 48; }
        $cutoff_gmt = gmdate( 'Y-m-d H:i:s', time() - ( $hours * 3600 ) );
        
        // $cutoff_gmt = gmdate( 'Y-m-d H:i:s', time() - ( $hours * 60 ) ); // Uses minutes instead of hours

        // Start log for visibility
        hcwc_log( 'Auto-sync: scan start (window=' . $hours . 'h, cutoff=' . $cutoff_gmt . ')' );

        // Query recent pending/on-hold orders with session id but no transaction id
        $args = array(
            'limit'        => 15, // hard cap per load
            'status'       => array( 'pending','on-hold' ),
            'orderby'      => 'date',
            'order'        => 'DESC',
            'payment_method' => 'hcwc',
            'date_created' => '>' . $cutoff_gmt,
            'return'       => 'objects',
        );
        
        hcwc_log( 'Auto-sync: querying orders with args - ' . wp_json_encode( array_merge( $args, array( 'date_created' => 'cutoff_applied' ) ) ) );
        
        $orders = wc_get_orders( $args );
        
        hcwc_log( 'Auto-sync: found ' . ( is_array( $orders ) ? count( $orders ) : 0 ) . ' total orders matching criteria' );
        
        // Collect eligible orders (session id present, no transaction id yet)
        $eligible = array();
        if ( ! empty( $orders ) ) {
            $used_sessions = array(); // Track sessions already processed
            foreach ( $orders as $order ) {
                $oid = $order->get_id();
                // Use WooCommerce order methods for HPOS compatibility
                $has_txn_id = $order->get_meta( '_hcwc_transaction_id' ) || $order->get_meta( '_hcwc_payment_id' );
                $session_id = $order->get_meta( '_hcwc_session_id' );
                
                hcwc_log( 'Auto-sync: evaluating order #' . $oid . ' - has_txn_id: ' . ( $has_txn_id ? 'yes' : 'no' ) . ', session_id: ' . ( $session_id ? substr( $session_id, 0, 8 ) . '...' : 'none' ) );
                
                if ( $has_txn_id ) { continue; }
                if ( ! $session_id ) { continue; }
                if ( in_array( $session_id, $used_sessions, true ) ) {
                    hcwc_log( 'Auto-sync: skipping order #' . $oid . ' - session ' . $session_id . ' already processed for another order' );
                    continue;
                }
                $eligible[] = array( 'order' => $order, 'session' => $session_id );
                $used_sessions[] = $session_id;
            }
        }

        if ( empty( $eligible ) ) {
            hcwc_log( 'Auto-sync: no eligible pending sessions within last ' . $hours . 'h window.' );
            return;
        }

        // Log summary of what we are about to process
        $log_list = array();
        foreach ( $eligible as $row ) {
            $log_list[] = '#' . $row['order']->get_id() . '(' . $row['session'] . ')';
            if ( count( $log_list ) >= 10 ) break; // limit verbosity
        }
        hcwc_log( 'Auto-sync: found ' . count( $eligible ) . ' eligible order(s) (showing up to 10): ' . implode( ', ', $log_list ) . ' | window=' . $hours . 'h' );

        foreach ( $eligible as $row ) {
            hcwc_auto_sync_single_order( $row['order'], $row['session'] );
        }
    }
}

if ( ! function_exists( 'hcwc_auto_sync_single_order' ) ) {
    /**
     * Sync a single order by its session id (no transaction id yet).
     */
    function hcwc_auto_sync_single_order( WC_Order $order, $session_id ) {
        // Retrieve secret key from gateway settings (matches process_payment usage)
        $settings = get_option( 'woocommerce_hcwc_settings', array() );
        $api_key = isset( $settings['secret_key'] ) ? $settings['secret_key'] : '';
        if ( ! $api_key ) {
            hcwc_log( 'Auto-sync: skipping order #' . $order->get_id() . ' (no secret key configured)' );
            return;
        }

        hcwc_log( 'Auto-sync: checking order #' . $order->get_id() . ' session ' . $session_id );

        $api_base = kc_get_api_url();
        $resp = wp_remote_get( "{$api_base}/checkout/sessions/$session_id", array(
            'headers' => array( 'X-API-KEY' => $api_key ),
            'timeout' => 15,
        ) );
        if ( is_wp_error( $resp ) ) return;
        $body = json_decode( wp_remote_retrieve_body( $resp ), true );
        if ( ! is_array( $body ) ) return;
        
        // Parse response structure like the manual sync does
        $sess = $body['data']['session'] ?? array();
        $txn = $body['data']['transaction'] ?? array();
        
        $status = strtolower( $sess['paymentStatus'] ?? '' );
        $txn_id = $txn['_id'] ?? '';
        
        hcwc_log( 'Auto-sync: API response - Status: ' . ( $status ?: 'none' ) . ', Transaction ID: ' . ( $txn_id ?: 'none' ) );

        if ( in_array( $status, array( 'paid','succeeded' ), true ) ) {
            if ( $txn_id ) {
                $order->update_meta_data( '_hcwc_transaction_id', $txn_id );
                $order->update_meta_data( '_hcwc_payment_id', $txn_id ); // Also save as payment_id for consistency
                $order->save();
                
                if ( ! $order->is_paid() ) {
                    $order->payment_complete( $txn_id );
                }
                hcwc_log( 'Auto-sync: order #' . $order->get_id() . ' session ' . $session_id . ' resolved to transaction ' . $txn_id . ' -> marked paid.' );
            } else {
                hcwc_log( 'Auto-sync: order #' . $order->get_id() . ' session ' . $session_id . ' shows paid status but no transaction ID - keeping pending until transaction appears.' );
            }
        } elseif ( in_array( $status, array( 'failed','canceled' ), true ) ) {
            if ( ! $order->has_status( 'failed' ) ) {
                $order->update_status( 'failed', kc_get_brand_name() . ' reported failure (auto sync)' );
            }
            hcwc_log( 'Auto-sync: order #' . $order->get_id() . ' session ' . $session_id . ' status=' . $status . ' -> marked failed.' );
        } else {
            hcwc_log( 'Auto-sync: order #' . $order->get_id() . ' session ' . $session_id . ' still pending (status=' . ( $status ?: 'none' ) . ').' );
        }
    }
}

add_action( 'current_screen', 'hcwc_batch_auto_sync_orders' );

// Add setting field for auto sync window hours inside gateway form_fields hook via filter if needed
add_filter( 'woocommerce_settings_api_form_fields_hcwc', function( $fields ) {
    // Append new field
    $fields['auto_sync_hours'] = array(
        'title'       => __( 'Auto Sync Window (hours)', 'hcwc' ),
        'type'        => 'number',
        'description' => 'On Orders page load, pending ' . kc_get_brand_name() . ' orders with a session but no transaction ID created within this window will be refreshed automatically.',
        'default'     => 24,
        'desc_tip'    => true,
        'custom_attributes' => array(
            'min' => 1,
            'max' => 48,
            'step' => 1,
        ),
    );
    return $fields;
} );

// Persist option manually if gateway does not automatically store custom numeric field name
add_action( 'woocommerce_update_options_payment_gateways_hcwc', function() {
    if ( isset( $_POST['woocommerce_hcwc_auto_sync_hours'] ) ) {
        $raw = intval( $_POST['woocommerce_hcwc_auto_sync_hours'] );
        if ( $raw < 1 ) $raw = 1; if ( $raw > 48 ) $raw = 48;
        update_option( 'hcwc_auto_sync_hours', $raw );
    }
} );

// Provide backward compatible retrieval in constructor (if needed elsewhere) via helper
if ( ! function_exists( 'hcwc_get_auto_sync_hours' ) ) {
    function hcwc_get_auto_sync_hours() {
        $h = (int) get_option( 'hcwc_auto_sync_hours', 24 );
        if ( $h < 1 ) $h = 1; if ( $h > 48 ) $h = 48; return $h;
    }
}

// Logging helper outside the gateway class context
if ( ! function_exists( 'hcwc_logging_enabled' ) ) {
    function hcwc_logging_enabled() : bool {
        $settings = get_option( 'woocommerce_hcwc_settings', array() );
        if ( isset( $settings['logging'] ) ) {
            return $settings['logging'] === 'yes';
        }
        // Fallback: assume enabled by default
        return true;
    }
}

if ( ! function_exists( 'hcwc_log' ) ) {
    function hcwc_log( $message, $level = 'info' ) {
        if ( ! hcwc_logging_enabled() ) return;
        if ( ! function_exists( 'wc_get_logger' ) ) return;
        $logger = wc_get_logger();
        $logger->log( $level, $message, array( 'source' => 'hcwc' ) );
    }
}
