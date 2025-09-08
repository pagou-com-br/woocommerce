<?php

/**
* Plugin Name: Pagou - Payments for WooCommerce
* Description: Integration with the Pagou payment gateway, allowing the use of PIX and Boleto as payment methods in WooCommerce.
* Author: Pagou
* Text Domain: pagou-payments-for-woocommerce
* Domain Path: /languages
* Author URI: https://pagou.com.br/
* Version: 1.1.2
* Requires at least: 6.6
* Requires PHP: 7.4
* Requires Plugins: woocommerce, woocommerce-extra-checkout-fields-for-brazil
* License: GPLv2 or later
*/

if ( ! defined( 'ABSPATH' ) ) { exit; }

if (
    in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins'))) && 
    in_array('woocommerce-extra-checkout-fields-for-brazil/woocommerce-extra-checkout-fields-for-brazil.php', apply_filters('active_plugins', get_option('active_plugins'))) &&
    !class_exists('ACPPFW_PAGOU')
) {
    include_once 'global.php';
    include_once ACPPFW_PAGOU_PATH_SERVER.'/includes/class/start.php';
    add_action('plugins_loaded', array('ACPPFW_PAGOU', 'init'));
} else {
    add_action('admin_notices', function() {
        if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
            echo '<div class="error"><p><strong>' . esc_html__('Pagou', 'pagou-payments-for-woocommerce') . '</strong> ' . esc_html__('requer o WooCommerce instalado e ativo para funcionar. Por favor, instale e ative o WooCommerce.', 'pagou-payments-for-woocommerce') . '</p></div>';
        } elseif (!in_array('woocommerce-extra-checkout-fields-for-brazil/woocommerce-extra-checkout-fields-for-brazil.php', apply_filters('active_plugins', get_option('active_plugins')))) {
            echo '<div class="error"><p><strong>' . esc_html__('Pagou', 'pagou-payments-for-woocommerce') . '</strong> ' . esc_html__('requer o plugin <strong>Brazilian Market on WooCommerce</strong> instalado e ativo para funcionar. Por favor, instale e ative o <a href="https://wordpress.org/plugins/woocommerce-extra-checkout-fields-for-brazil/" target="_blank">Brazilian Market on WooCommerce</a>.</p></div>', 'pagou-payments-for-woocommerce') . '</p></div>';
        }
    });
}



/*
╔════════════════════════════════════════════════════════════════════════════════════════╗
║░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░║
║░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░< DESENVOLVIDO POR />░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░║
║░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░║
║░░░░██████████║░░░███║░░░░░░░░███║░░░██████║░░░██████║░░░░███████║░░░░██████║░░░███║░░░░║
║░░░░███║░░░███║░░░███║░░░░░░░░███║░░░██║░░░░░░░██║░░░░░░░██║░░░░██║░░░███║███║░░███║░░░░║
║░░░░███║░░░███║░░░███║░░░░░░░░███║░░░██║░░░░░░░██║░░░░░░░██║░░░░██║░░░███║░███║░███║░░░░║
║░░░░██████████║░░░███║░░░░░░░░███║░░░██████║░░░██████║░░░██║░░░░██║░░░███║░███║░███║░░░░║
║░░░░███║░░░███║░░░███║░░░░░░░░███║░░░░░░░██║░░░░░░░██║░░░██║░░░░██║░░░███║░███║░███║░░░░║
║░░░░███║░░░███║░░░███║░░░░░░░░███║░░░░░░░██║░░░░░░░██║░░░██║░░░░██║░░░███║░███║░███║░░░░║
║░░░░███║░░░███║░░░████████║░░░███║░░░██████║░░░██████║░░░░███████║░░░░███║░░██████║░░░░░║
║░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░║
║░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░║
║░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░.:░WWW.DEVALISSON.COM░:.░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░║
║░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░║
╚════════════════════════════════════════════════════════════════════════════════════════╝
*/
