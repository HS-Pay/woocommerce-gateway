<?php
defined( 'ABSPATH' ) || exit;
use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

class WC_Gateway_HCWC_Blocks extends AbstractPaymentMethodType {
    protected $name = 'hcwc';
    protected $settings = array();

    public function initialize() {
        $this->settings = get_option( 'woocommerce_hcwc_settings', array() );
    }

    public function is_active() {
        return ( ! empty( $this->settings['enabled'] ) && $this->settings['enabled'] === 'yes' );
    }

    public function get_payment_method_script_handles() {
        $handle   = 'wc-hcwc-blocks';
        $rel_path = 'assets/blocks/index.js';
        $file     = KC_WC_PLUGIN_DIR . $rel_path;
        $url      = KC_WC_PLUGIN_URL . $rel_path;
        if ( ! file_exists( $file ) ) {
            return array();
        }
        wp_register_script(
            $handle,
            $url,
            array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities' ),
            KC_WC_VERSION,
            true
        );
        return array( $handle );
    }

    public function get_payment_method_data() {
        // Instantiate gateway only to read defaults (safe: no API call here)
        $gateway = new WC_Gateway_HCWC();
        $default_title       = $gateway->form_fields['title']['default'];
        $default_description = $gateway->form_fields['description']['default'];

        $description = $this->get_setting( 'description' );
        if ( trim( (string) $description ) === '' ) {
            $description = $default_description;
        }

        return array(
            'title'       => $this->get_setting( 'title', $default_title ),
            'description' => $description,
            'pluginUrl'   => esc_url( KC_WC_PLUGIN_URL ),
            'paymentType' => $this->get_setting( 'payment_type', 'echeck' ),
            'brandName'   => kc_get_brand_name(),
            'supports'    => array( 'products' ),
        );
    }

    public function get_supported_features() {
        return array( 'products' );
    }
}
