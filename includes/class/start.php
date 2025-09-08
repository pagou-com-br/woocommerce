<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

use Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry;

class ACPPFW_PAGOU {

    public static function init() {

        // Actions
        add_action( 'plugins_loaded', array(__CLASS__, 'acppfw_pagou_load_textdomain') ); // Translations
        add_action( 'wp_enqueue_scripts', array(__CLASS__, 'acppfw_run_scripts'), 100 ); // Scripts
        add_filter( 'woocommerce_checkout_fields', array(__CLASS__, 'acppfw_custom_make_address_fields_required') ); // Fields Woocoomerce

        // Includes
        self::acppfw_includes();

        // Start Pagou
        add_filter( 'woocommerce_payment_gateways', array(__CLASS__, 'acppfw_register_pagou') );
        add_action( 'woocommerce_blocks_loaded', array(__CLASS__, 'acppfw_pagou_register_block_support') ); 

        // Reload rules
        register_activation_hook(__FILE__, 'acppfw_pagou_activate'); 
    }

    public static function acppfw_pagou_load_textdomain() {
        load_plugin_textdomain('acppfw_pagou', false, acppfw_PAGOU_PATH_SERVER.'/languages');
    }

    public static function acppfw_includes(){
        include_once( ACPPFW_PAGOU_PATH_SERVER.'includes/functions.php' );
        include_once( ACPPFW_PAGOU_PATH_SERVER.'includes/class/pagou_pix.php' );
        include_once( ACPPFW_PAGOU_PATH_SERVER.'includes/class/pagou_boleto.php' );
    }

    public static function acppfw_register_pagou($gateways) {
        $gateways[] = 'ACPPFW_Pagou_Pix';
        $gateways[] = 'ACPPFW_Pagou_Boleto';
        return $gateways;
    }

    public static function acppfw_pagou_register_block_support() {
        if (! is_admin() && class_exists('Automattic\\WooCommerce\\Blocks\\Payments\\Integrations\\AbstractPaymentMethodType') ) {

            require_once ACPPFW_PAGOU_PATH_SERVER . 'includes/class/blocks_support_pix.php';
            require_once ACPPFW_PAGOU_PATH_SERVER . 'includes/class/blocks_support_boleto.php';

            add_action(
                'woocommerce_blocks_payment_method_type_registration',
                function($payment_method_registry) {
                    $payment_method_registry->register( new ACPPFW_Pagou_Pix_Blocks_Support() );
                    $payment_method_registry->register( new ACPPFW_Pagou_Boleto_Blocks_Support() );
                }
            );
        }
    }

    public static function acppfw_custom_make_address_fields_required( $fields ) {
        $required_fields = [
            'billing_postcode',       
            'billing_state',        
            'billing_city',          
            'billing_address_1',     
            'billing_number',       
            'billing_neighborhood'  
        ];

        foreach ( $required_fields as $field_key ) {
            if ( isset( $fields['billing'][ $field_key ] ) ) {
                $fields['billing'][ $field_key ]['required'] = true;
            }
        }

        return $fields;
    }

    public static function acppfw_run_scripts() {

        wp_register_script(
            'acppfw_pagou_barcode',
            ACPPFW_PAGOU_PATH.'includes/js/JsBarcode.all.min.js',
            array('jquery'),
            ACPPFW_PAGOU_VERSION,
            true
        );

        wp_register_script(
            'acppfw_pagou_script',
            ACPPFW_PAGOU_PATH.'includes/js/script.js',
            array('jquery','acppfw_pagou_barcode'),
            ACPPFW_PAGOU_VERSION,
            true
        );

        wp_register_style(
            'acppfw_pagou_style',
            ACPPFW_PAGOU_PATH.'includes/css/style.css',
            '',
            ACPPFW_PAGOU_VERSION
        );
        
        wp_register_script(
            'wc-pagou-pix-blocks-integration',
            ACPPFW_PAGOU_PATH.'includes/js/pix.js',
            [ 'wc-blocks-registry', 'wp-element', 'wp-components' ], 
            ACPPFW_PAGOU_VERSION,
            true
        );

        wp_register_script(
            'wc-pagou-boleto-blocks-integration',
            ACPPFW_PAGOU_PATH.'includes/js/boleto.js',
            [ 'wc-blocks-registry', 'wp-element', 'wp-components' ], 
            ACPPFW_PAGOU_VERSION,
            true
        );

        wp_set_script_translations( 'wc-pagou-pix-blocks-integration', 'pagou-payments-for-woocommerce', plugin_dir_path( __FILE__ ) . 'languages/' );
        wp_set_script_translations( 'wc-pagou-boleto-blocks-integration', 'pagou-payments-for-woocommerce', plugin_dir_path( __FILE__ ) . 'languages/' );
    }
}