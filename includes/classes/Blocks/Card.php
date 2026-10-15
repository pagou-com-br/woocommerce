<?php
/**
 * Cartão no checkout em blocos.
 *
 * @package Pagou
 */

namespace Pagou\WooCommerce\Blocks;

use Pagou\WooCommerce\Gateway\Card as CardGateway;
use Pagou\WooCommerce\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Card extends AbstractMethod {

	protected $name = 'pagou_cartao';

	public function get_payment_method_script_handles() {
		CardGateway::registrar_tokenizacao();

		if ( ! wp_script_is( 'pagou-wc-cartao-blocos', 'registered' ) ) {
			wp_register_script(
				'pagou-wc-cartao-blocos',
				PAGOU_WC_URL . 'assets/js/cartao-blocos.js',
				array( 'wc-blocks-registry', 'wp-element', 'wp-html-entities', 'wp-data', 'pagou-wc-cartao-comum' ),
				Plugin::versao_asset( 'assets/js/cartao-blocos.js' ),
				true
			);
		}

		return array( 'pagou-wc-cartao-blocos' );
	}

	public function get_payment_method_data() {
		$dados   = parent::get_payment_method_data();
		$gateway = $this->gateway();

		if ( $gateway instanceof CardGateway ) {
			$dados = array_merge( $dados, $gateway->dados_js() );
			$dados['salvarCartao'] = $gateway->supports( 'tokenization' ) && is_user_logged_in();
		}

		return $dados;
	}
}
