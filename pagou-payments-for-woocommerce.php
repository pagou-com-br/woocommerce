<?php
/**
 * Plugin Name: Pagou - Payments for WooCommerce
 * Description: Integration with the Pagou payment gateway: Pix, Boleto and credit card, with payment split, as payment methods in WooCommerce.
 * Author: Pagou
 * Text Domain: pagou-payments-for-woocommerce
 * Domain Path: /languages
 * Author URI: https://pagou.com.br/
 * Version: 2.0.1
 * Requires at least: 6.6
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * WC requires at least: 9.0
 * License: GPLv2 or later
 *
 * @package Pagou
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PAGOU_WC_VERSION', '2.0.1' );
define( 'PAGOU_WC_FILE', __FILE__ );
define( 'PAGOU_WC_DIR', plugin_dir_path( __FILE__ ) );
define( 'PAGOU_WC_URL', plugin_dir_url( __FILE__ ) );

// Autoload das classes do plugin (namespace Pagou\WooCommerce, PSR-4 em includes/classes).
spl_autoload_register(
	function ( $classe ) {
		$prefixo = 'Pagou\\WooCommerce\\';

		if ( 0 !== strpos( $classe, $prefixo ) ) {
			return;
		}

		$relativo = str_replace( '\\', '/', substr( $classe, strlen( $prefixo ) ) );
		$arquivo  = PAGOU_WC_DIR . 'includes/classes/' . $relativo . '.php';

		if ( is_readable( $arquivo ) ) {
			require $arquivo;
		}
	}
);

register_activation_hook( __FILE__, array( 'Pagou\\WooCommerce\\Plugin', 'ativar' ) );
register_deactivation_hook( __FILE__, array( 'Pagou\\WooCommerce\\Plugin', 'desativar' ) );

// Declara compatibilidade com HPOS e checkout em blocos antes do WooCommerce iniciar.
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', PAGOU_WC_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', PAGOU_WC_FILE, true );
		}
	}
);

add_action( 'plugins_loaded', array( 'Pagou\\WooCommerce\\Plugin', 'iniciar' ), 11 );
