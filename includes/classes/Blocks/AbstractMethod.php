<?php
/**
 * Integração de um meio de pagamento com o checkout em blocos.
 *
 * O pagamento em si é processado pelo próprio gateway (process_payment): o Store API do
 * WooCommerce repassa os dados do método em $_POST. Aqui só entregamos ao navegador o
 * que ele precisa para desenhar o método.
 *
 * @package Pagou
 */

namespace Pagou\WooCommerce\Blocks;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;
use Pagou\WooCommerce\Discounts;
use Pagou\WooCommerce\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class AbstractMethod extends AbstractPaymentMethodType {

	/**
	 * @var \WC_Payment_Gateway|null
	 */
	protected $gateway = null;

	/**
	 * Handle e arquivo do script do método.
	 *
	 * @return array{0:string,1:string}
	 */
	protected function script() {
		return array( 'pagou-wc-blocos', 'assets/js/blocks.js' );
	}

	public function initialize() {
		$this->settings = get_option( 'woocommerce_' . $this->name . '_settings', array() );
	}

	/**
	 * @return \WC_Payment_Gateway|null
	 */
	protected function gateway() {
		if ( null === $this->gateway && function_exists( 'WC' ) && WC()->payment_gateways() ) {
			$todos         = WC()->payment_gateways()->payment_gateways();
			$this->gateway = isset( $todos[ $this->name ] ) ? $todos[ $this->name ] : null;
		}

		return $this->gateway;
	}

	public function is_active() {
		$gateway = $this->gateway();

		return $gateway ? $gateway->is_available() : false;
	}

	public function get_payment_method_script_handles() {
		list( $handle, $arquivo ) = $this->script();

		if ( ! wp_script_is( $handle, 'registered' ) ) {
			wp_register_script(
				$handle,
				PAGOU_WC_URL . $arquivo,
				array( 'wc-blocks-registry', 'wp-element', 'wp-html-entities', 'wp-i18n' ),
				Plugin::versao_asset( $arquivo ),
				true
			);
			wp_set_script_translations( $handle, 'pagou-payments-for-woocommerce', PAGOU_WC_DIR . 'languages' );
		}

		return array( $handle );
	}

	public function get_payment_method_data() {
		$gateway = $this->gateway();

		return array(
			'title'       => $gateway ? $gateway->title : '',
			'description' => $gateway ? $gateway->description : '',
			'icon'        => $gateway ? $gateway->icon : '',
			'discount'    => Discounts::rotulo( $this->name ),
			'supports'    => $gateway ? array_values( array_filter( $gateway->supports, array( $gateway, 'supports' ) ) ) : array( 'products' ),
		);
	}
}
