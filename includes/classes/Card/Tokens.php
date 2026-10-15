<?php
/**
 * Cartões salvos: exclusão sincronizada com a Pagou e separação por ambiente.
 *
 * @package Pagou
 */

namespace Pagou\WooCommerce\Card;

use Pagou\WooCommerce\Api\Client;
use Pagou\WooCommerce\Gateway\Card;
use Pagou\WooCommerce\Logger;
use Pagou\WooCommerce\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Tokens {

	public static function init() {
		add_action( 'woocommerce_payment_token_deleted', array( __CLASS__, 'excluido' ), 10, 2 );
		add_filter( 'woocommerce_get_customer_payment_tokens', array( __CLASS__, 'filtrar_ambiente' ), 10, 3 );
	}

	/**
	 * Remove o cartão também na Pagou quando o cliente o exclui da conta.
	 *
	 * @param int               $token_id
	 * @param \WC_Payment_Token $token
	 */
	public static function excluido( $token_id, $token ) {
		if ( ! $token || Card::ID !== $token->get_gateway_id() ) {
			return;
		}

		// Cartão de outro ambiente não existe na conta configurada agora.
		if ( $token->get_meta( 'pagou_ambiente' ) && $token->get_meta( 'pagou_ambiente' ) !== Settings::ambiente() ) {
			return;
		}

		$resposta = ( new Client() )->delete( '/v1/creditcard/cards/' . rawurlencode( $token->get_token() ) );

		if ( ! $resposta->ok() && 404 !== $resposta->http ) {
			Logger::aviso( 'Não foi possível excluir o cartão na Pagou.', array( 'token' => $token_id, 'http' => $resposta->http ) );
		}
	}

	/**
	 * Esconde cartões salvos em outro ambiente (sandbox x produção).
	 *
	 * @param array  $tokens
	 * @param int    $user_id
	 * @param string $gateway_id
	 * @return array
	 */
	public static function filtrar_ambiente( $tokens, $user_id, $gateway_id ) {
		$ambiente = Settings::ambiente();

		foreach ( $tokens as $chave => $token ) {
			if ( Card::ID === $token->get_gateway_id() && $token->get_meta( 'pagou_ambiente' ) && $token->get_meta( 'pagou_ambiente' ) !== $ambiente ) {
				unset( $tokens[ $chave ] );
			}
		}

		return $tokens;
	}
}
