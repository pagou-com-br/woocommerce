<?php
/**
 * Sincronização do cartão com a Pagou (o cartão não tem webhook).
 *
 * - Conciliação: pedidos aguardando são consultados pela conciliação geral, que chama
 *   conciliar() abaixo. Pedido com resultado incerto e sem ID de cobrança é localizado
 *   pela listagem (descrição e valor).
 * - Chargebacks: duas vezes ao dia, lista as contestações recentes e marca os pedidos.
 *
 * @package Pagou
 */

namespace Pagou\WooCommerce\Cron;

use Pagou\WooCommerce\Api\Client;
use Pagou\WooCommerce\Gateway\Card;
use Pagou\WooCommerce\Logger;
use Pagou\WooCommerce\Money;
use WC_Order;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CardSync {

	const HOOK_CHARGEBACKS = 'pagou_wc_chargebacks';

	public static function init() {
		Reconciliation::registrar( Card::ID, array( __CLASS__, 'conciliar' ) );

		add_action( self::HOOK_CHARGEBACKS, array( __CLASS__, 'chargebacks' ) );
		add_action( 'init', array( __CLASS__, 'agendar' ) );
	}

	public static function agendar() {
		if ( ! wp_next_scheduled( self::HOOK_CHARGEBACKS ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'twicedaily', self::HOOK_CHARGEBACKS );
		}
	}

	/**
	 * @param WC_Order $order
	 */
	public static function conciliar( $order ) {
		$api = new Client();
		$id  = (string) $order->get_meta( '_pagou_cartao_id' );

		if ( '' === $id ) {
			if ( ! $order->get_meta( '_pagou_cartao_incerto' ) ) {
				return;
			}
			$id = self::localizar( $api, $order );
			if ( '' === $id ) {
				return;
			}
			$order->update_meta_data( '_pagou_cartao_id', $id );
			$order->save();
		}

		$resposta = $api->get( '/v1/creditcard/charges/' . rawurlencode( $id ) );

		if ( ! $resposta->ok() || empty( $resposta->body['status'] ) ) {
			return;
		}

		Card::salvar_cobranca( $order, $resposta->body );
		$situacao = Card::aplicar_status( $order, $resposta->body, __( 'Pagamento com cartão confirmado pela conciliação (consulta à Pagou).', 'pagou-payments-for-woocommerce' ) );

		if ( in_array( $situacao, array( 'falhou', 'cancelado' ), true ) && $order->get_meta( '_pagou_cartao_incerto' ) ) {
			$order->delete_meta_data( '_pagou_cartao_incerto' );
			$order->update_status( 'failed', __( 'Pagou: a conferência mostrou que o pagamento com cartão não foi aprovado. O cliente pode tentar de novo pela página do pedido.', 'pagou-payments-for-woocommerce' ) );
		}

		Logger::log( 'Conciliação do cartão.', array( 'pedido' => $order->get_id(), 'situacao' => $situacao ) );
	}

	/**
	 * Procura a cobrança de um pedido cujo envio teve resultado incerto.
	 *
	 * @param Client   $api
	 * @param WC_Order $order
	 * @return string ID da cobrança ou ''.
	 */
	private static function localizar( Client $api, WC_Order $order ) {
		$tentativa = (int) $order->get_meta( '_pagou_cartao_tentativa_em' );

		if ( ! $tentativa ) {
			return '';
		}

		$resposta = $api->get(
			'/v1/creditcard/charges',
			array(
				'created_at_from' => gmdate( 'Y-m-d\TH:i:s\Z', $tentativa - 10 * MINUTE_IN_SECONDS ),
				'created_at_to'   => gmdate( 'Y-m-d\TH:i:s\Z', $tentativa + 30 * MINUTE_IN_SECONDS ),
				'limit'           => 100,
			)
		);

		if ( ! $resposta->ok() || empty( $resposta->body['items'] ) || ! is_array( $resposta->body['items'] ) ) {
			return '';
		}

		/* translators: 1: número do pedido, 2: nome da loja */
		$prefixo = sprintf( __( 'Pedido %1$s - %2$s', 'pagou-payments-for-woocommerce' ), $order->get_order_number(), '' );
		$valor   = Money::centavos( $order->get_total() );

		foreach ( $resposta->body['items'] as $cobranca ) {
			$descricao = isset( $cobranca['description'] ) ? (string) $cobranca['description'] : '';
			if ( 0 === strpos( $descricao, $prefixo ) && isset( $cobranca['value'] ) && (int) $cobranca['value'] === $valor && ! empty( $cobranca['id'] ) ) {
				return (string) $cobranca['id'];
			}
		}

		return '';
	}

	/**
	 * Marca pedidos com chargeback aberto nos últimos dias.
	 */
	public static function chargebacks() {
		$config = get_option( 'woocommerce_' . Card::ID . '_settings', array() );

		if ( ! isset( $config['enabled'] ) || 'yes' !== $config['enabled'] ) {
			return;
		}

		$resposta = ( new Client() )->get(
			'/v1/creditcard/chargebacks',
			array(
				'created_at_from' => gmdate( 'Y-m-d\TH:i:s\Z', time() - 3 * DAY_IN_SECONDS ),
				'created_at_to'   => gmdate( 'Y-m-d\TH:i:s\Z' ),
				'limit'           => 100,
			)
		);

		if ( ! $resposta->ok() || empty( $resposta->body['items'] ) || ! is_array( $resposta->body['items'] ) ) {
			return;
		}

		foreach ( $resposta->body['items'] as $disputa ) {
			if ( empty( $disputa['charge_id'] ) ) {
				continue;
			}

			$ids = wc_get_orders(
				array(
					'limit'      => 1,
					'return'     => 'ids',
					'meta_key'   => '_pagou_cartao_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value' => sanitize_text_field( (string) $disputa['charge_id'] ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				)
			);

			if ( empty( $ids ) ) {
				continue;
			}

			$order = wc_get_order( $ids[0] );

			if ( $order ) {
				if ( ! empty( $disputa['expire'] ) ) {
					$order->update_meta_data( '_pagou_chargeback_prazo', sanitize_text_field( (string) $disputa['expire'] ) );
					$order->save();
				}
				Card::chargeback( $order, (string) $disputa['charge_id'] );
			}
		}
	}
}
