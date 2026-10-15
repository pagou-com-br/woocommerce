<?php
/**
 * Recebimento de webhooks da Pagou.
 *
 * Rota principal: POST /wp-json/pagou/v1/webhook (ou ?rest_route=/pagou/v1/webhook sem
 * permalink bonito). As rotas legadas wc-api/acppfw_pix e wc-api/acppfw_boleto continuam
 * respondendo, porque cobranças criadas na 1.x carregam essas URLs.
 *
 * Fluxo: autentica (HMAC sobre timestamp + corpo bruto, anti-replay de 5 minutos),
 * localiza o pedido, aplica o efeito uma única vez e responde exatamente 200.
 *
 * @package Pagou
 */

namespace Pagou\WooCommerce\Webhook;

use Pagou\WooCommerce\Gateway\Boleto;
use Pagou\WooCommerce\Logger;
use Pagou\WooCommerce\Order\Payments;
use Pagou\WooCommerce\Settings;
use WC_Order;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Controller {

	const JANELA_SEGUNDOS = 300;

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'registrar_rota' ) );
		add_action( 'woocommerce_api_acppfw_pix', array( __CLASS__, 'legado' ) );
		add_action( 'woocommerce_api_acppfw_boleto', array( __CLASS__, 'legado' ) );
	}

	public static function registrar_rota() {
		register_rest_route(
			'pagou/v1',
			'/webhook',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'rest' ),
				// A autenticação é a assinatura HMAC, conferida dentro do callback.
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public static function rest( WP_REST_Request $request ) {
		list( $codigo, $mensagem ) = self::processar(
			$request->get_body(),
			(string) $request->get_header( 'x_pagou_signature' ),
			(string) $request->get_header( 'x_pagou_timestamp' )
		);

		return new WP_REST_Response( array( 'message' => $mensagem ), $codigo );
	}

	/**
	 * Endpoints wc-api da 1.x.
	 */
	public static function legado() {
		$corpo      = file_get_contents( 'php://input' );
		$assinatura = isset( $_SERVER['HTTP_X_PAGOU_SIGNATURE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_PAGOU_SIGNATURE'] ) ) : '';
		$timestamp  = isset( $_SERVER['HTTP_X_PAGOU_TIMESTAMP'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_PAGOU_TIMESTAMP'] ) ) : '';

		list( $codigo, $mensagem ) = self::processar( (string) $corpo, $assinatura, $timestamp );

		wp_send_json( array( 'message' => $mensagem ), $codigo );
	}

	/**
	 * @param string $corpo
	 * @param string $assinatura
	 * @param string $timestamp
	 * @return array{0:int,1:string}
	 */
	public static function processar( $corpo, $assinatura, $timestamp ) {
		if ( ! self::autenticado( $corpo, $assinatura, $timestamp ) ) {
			Logger::aviso( 'Webhook rejeitado: assinatura inválida ou fora da janela de tempo.', array( 'inicio' => substr( $corpo, 0, 200 ) ) );
			return array( 401, 'Assinatura inválida.' );
		}

		$evento = json_decode( $corpo, true );
		$nome   = is_array( $evento ) && isset( $evento['name'] ) ? (string) $evento['name'] : '';

		// A documentação antiga citava event_name; o payload real usa name.
		if ( '' === $nome && is_array( $evento ) && isset( $evento['event_name'] ) ) {
			$nome = (string) $evento['event_name'];
		}

		if ( '' === $nome || ! isset( $evento['data'] ) || ! is_array( $evento['data'] ) ) {
			return array( 400, 'Evento inválido.' );
		}

		$dados = $evento['data'];
		$tipo  = 0 === strpos( $nome, 'qrcode.' ) ? 'pix' : ( 0 === strpos( $nome, 'charge.' ) ? 'boleto' : '' );

		if ( '' === $tipo ) {
			return array( 200, 'Evento ignorado.' );
		}

		$order = self::localizar_pedido( $dados, $tipo );

		if ( ! $order ) {
			Logger::aviso( 'Webhook sem pedido correspondente.', array( 'evento' => $nome, 'id' => isset( $dados['id'] ) ? $dados['id'] : '' ) );
			return array( 404, 'Pedido não encontrado.' );
		}

		Logger::log( 'Webhook autenticado.', array( 'evento' => $nome, 'pedido' => $order->get_id() ) );

		$resultado = self::aplicar( $order, $nome, $dados );

		if ( 'ocupado' === $resultado ) {
			// Outro processo está atualizando o pedido; a Pagou reenvia.
			return array( 503, 'Pedido em processamento, tente novamente.' );
		}

		return array( 200, 'OK' );
	}

	/**
	 * @param WC_Order $order
	 * @param string   $nome
	 * @param array    $dados
	 * @return string
	 */
	private static function aplicar( WC_Order $order, $nome, array $dados ) {
		$chave = self::identidade( $nome, $dados );

		switch ( $nome ) {
			case 'qrcode.completed':
				if ( ! empty( $dados['e2e_id'] ) ) {
					$order->update_meta_data( '_pagou_e2e_id', sanitize_text_field( $dados['e2e_id'] ) );
					$order->save();
				}
				return Payments::confirmar(
					$order,
					isset( $dados['amount'] ) && is_numeric( $dados['amount'] ) ? (float) $dados['amount'] : null,
					__( 'Pagamento confirmado via Pix (Pagou).', 'pagou-payments-for-woocommerce' ),
					$chave,
					isset( $dados['transaction_id'] ) ? (string) $dados['transaction_id'] : ''
				);

			case 'charge.paid':
				$valor = null;
				if ( isset( $dados['amount']['paid'] ) && is_numeric( $dados['amount']['paid'] ) ) {
					$valor = (float) $dados['amount']['paid'];
				} elseif ( isset( $dados['amount'] ) && is_numeric( $dados['amount'] ) ) {
					$valor = (float) $dados['amount'];
				}
				$via_pix = isset( $dados['paid_in'] ) && 'pix' === $dados['paid_in'];
				return Payments::confirmar(
					$order,
					$valor,
					$via_pix
						? __( 'Boleto pago via Pix embutido (Pagou).', 'pagou-payments-for-woocommerce' )
						: __( 'Pagamento do boleto confirmado (Pagou).', 'pagou-payments-for-woocommerce' ),
					$chave,
					isset( $dados['transaction_id'] ) ? (string) $dados['transaction_id'] : ''
				);

			case 'charge.created':
				// Dados de registro do boleto: guarda para exibir sem consultar a API.
				if ( isset( $dados['payload'] ) && is_array( $dados['payload'] ) ) {
					$payload = $dados['payload'];
					if ( ! isset( $payload['bank'] ) ) {
						$payload['bank'] = array_filter(
							array(
								'issuer'   => isset( $payload['bank_emissor'] ) ? $payload['bank_emissor'] : '',
								'number'   => isset( $payload['bank_number'] ) ? $payload['bank_number'] : '',
								'agency'   => isset( $payload['bank_agency'] ) ? $payload['bank_agency'] : '',
								'account'  => isset( $payload['bank_account'] ) ? $payload['bank_account'] : '',
								'assignor' => isset( $payload['bank_assignor'] ) ? $payload['bank_assignor'] : '',
							)
						);
					}
					Boleto::salvar_cobranca( $order, array( 'payload' => $payload ) );
				}
				return 'registrado';

			case 'qrcode.refunded':
			case 'charge.refunded':
				return Payments::estornado(
					$order,
					isset( $dados['amount'] ) && is_numeric( $dados['amount'] ) ? (float) $dados['amount'] : null,
					__( 'Devolução confirmada pela Pagou.', 'pagou-payments-for-woocommerce' ),
					$chave
				);

			case 'qrcode.canceled':
			case 'charge.canceled':
				return Payments::cancelar( $order, __( 'Cobrança cancelada na Pagou.', 'pagou-payments-for-woocommerce' ), $chave );
		}

		return 'ignorado';
	}

	/**
	 * Identidade do evento para dedupe: nome + cobrança + transação.
	 *
	 * @param string $nome
	 * @param array  $dados
	 * @return string
	 */
	public static function identidade( $nome, array $dados ) {
		$partes = array(
			$nome,
			isset( $dados['id'] ) ? (string) $dados['id'] : '',
			isset( $dados['transaction_id'] ) ? (string) $dados['transaction_id'] : '',
		);

		return implode( ':', $partes );
	}

	/**
	 * Localiza o pedido pelo ID da cobrança salvo no pedido; na falta, pelo external_id,
	 * desde que o pedido seja do gateway correspondente.
	 *
	 * @param array  $dados
	 * @param string $tipo 'pix' ou 'boleto'.
	 * @return WC_Order|false
	 */
	public static function localizar_pedido( array $dados, $tipo ) {
		$meta    = 'pix' === $tipo ? '_pagou_pix_id' : '_pagou_boleto_id';
		$gateway = 'pix' === $tipo ? 'pagou_pix' : 'pagou_boleto';

		if ( ! empty( $dados['id'] ) && is_scalar( $dados['id'] ) ) {
			$ids = wc_get_orders(
				array(
					'limit'      => 1,
					'return'     => 'ids',
					// Único vínculo confiável entre a cobrança e o pedido.
					'meta_key'   => $meta, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value' => sanitize_text_field( (string) $dados['id'] ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				)
			);

			if ( ! empty( $ids ) ) {
				return wc_get_order( $ids[0] );
			}
		}

		if ( isset( $dados['external_id'] ) && is_numeric( $dados['external_id'] ) ) {
			$order = wc_get_order( (int) $dados['external_id'] );
			if ( $order && $gateway === $order->get_payment_method() ) {
				return $order;
			}
		}

		return false;
	}

	/**
	 * @param string $corpo
	 * @param string $assinatura
	 * @param string $timestamp
	 * @return bool
	 */
	public static function autenticado( $corpo, $assinatura, $timestamp ) {
		if ( '' === $assinatura || '' === $timestamp || ! is_numeric( $timestamp ) ) {
			return false;
		}

		if ( abs( time() - (int) $timestamp ) > self::JANELA_SEGUNDOS ) {
			return false;
		}

		// A chave atual e as que existiam antes da migração para a 2.0 (cobranças antigas).
		$chaves = array_merge( array( Settings::api_key() ), (array) Settings::get( 'chaves_antigas', array() ) );

		foreach ( array_unique( array_filter( $chaves ) ) as $chave ) {
			if ( hash_equals( hash_hmac( 'sha256', $timestamp . $corpo, $chave ), $assinatura ) ) {
				return true;
			}
		}

		return false;
	}
}
