<?php
/**
 * Conciliação automática.
 *
 * Rede de segurança para webhooks que não chegam: a cada 15 minutos consulta na Pagou os
 * pedidos que ainda aguardam pagamento. Para o cartão, que não tem webhook, é o caminho
 * principal de atualização.
 *
 * @package Pagou
 */

namespace Pagou\WooCommerce\Cron;

use Pagou\WooCommerce\Api\Client;
use Pagou\WooCommerce\Logger;
use Pagou\WooCommerce\Order\Payments;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Reconciliation {

	const HOOK = 'pagou_wc_conciliar';

	/**
	 * Status numéricos de Pix e boleto (sem tabela oficial; observados em produção).
	 */
	const PAGO      = 4;
	const ESTORNADO = 5;

	const JANELA_HORAS = 72;
	const LOTE         = 30;

	/**
	 * Verificadores por gateway (id => callable que recebe o WC_Order).
	 *
	 * @var array
	 */
	private static $verificadores = array();

	public static function init() {
		add_filter( 'cron_schedules', array( __CLASS__, 'intervalo' ) );
		add_action( self::HOOK, array( __CLASS__, 'executar' ) );
		add_action( 'init', array( __CLASS__, 'agendar' ) );

		self::registrar( 'pagou_pix', array( __CLASS__, 'verificar_pix_boleto' ) );
		self::registrar( 'pagou_boleto', array( __CLASS__, 'verificar_pix_boleto' ) );
	}

	/**
	 * Permite que outros meios (cartão) registrem como seus pedidos são conciliados.
	 *
	 * @param string   $gateway_id
	 * @param callable $verificador
	 */
	public static function registrar( $gateway_id, $verificador ) {
		self::$verificadores[ $gateway_id ] = $verificador;
	}

	/**
	 * @param array $schedules
	 * @return array
	 */
	public static function intervalo( $schedules ) {
		$schedules['pagou_wc_15min'] = array(
			'interval' => 15 * MINUTE_IN_SECONDS,
			'display'  => __( 'A cada 15 minutos (Pagou)', 'pagou-payments-for-woocommerce' ),
		);

		return $schedules;
	}

	public static function agendar() {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'pagou_wc_15min', self::HOOK );
		}
	}

	public static function executar() {
		$ids = wc_get_orders(
			array(
				'limit'          => self::LOTE,
				'status'         => array( 'on-hold', 'pending' ),
				'payment_method' => array_keys( self::$verificadores ),
				'date_created'   => '>' . ( time() - ( self::JANELA_HORAS * HOUR_IN_SECONDS ) ),
				'orderby'        => 'date',
				'order'          => 'ASC',
				'return'         => 'ids',
			)
		);

		if ( empty( $ids ) ) {
			return;
		}

		Logger::log( 'Conciliação: ' . count( $ids ) . ' pedido(s) aguardando.' );

		foreach ( $ids as $order_id ) {
			self::verificar_pedido( $order_id );
		}
	}

	/**
	 * Consulta a Pagou e atualiza um pedido.
	 *
	 * @param int $order_id
	 */
	public static function verificar_pedido( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( ! $order || $order->is_paid() ) {
			return;
		}

		$gateway = $order->get_payment_method();

		if ( isset( self::$verificadores[ $gateway ] ) ) {
			call_user_func( self::$verificadores[ $gateway ], $order );
		}
	}

	/**
	 * @param \WC_Order $order
	 */
	public static function verificar_pix_boleto( $order ) {
		$is_pix = 'pagou_pix' === $order->get_payment_method();
		$id     = (string) $order->get_meta( $is_pix ? '_pagou_pix_id' : '_pagou_boleto_id' );

		if ( '' === $id ) {
			return;
		}

		$resposta = ( new Client() )->get( ( $is_pix ? '/v1/pix/' : '/v1/charges/' ) . rawurlencode( $id ) );

		if ( ! $resposta->ok() || ! isset( $resposta->body['status'] ) ) {
			return;
		}

		$status = (int) $resposta->body['status'];

		if ( self::PAGO !== $status ) {
			return;
		}

		$valor = null;
		$bruto = isset( $resposta->body['amount'] ) ? $resposta->body['amount'] : null;
		if ( is_array( $bruto ) && isset( $bruto['paid'] ) && is_numeric( $bruto['paid'] ) ) {
			$valor = (float) $bruto['paid'];
		} elseif ( is_numeric( $bruto ) ) {
			$valor = (float) $bruto;
		}

		$resultado = Payments::confirmar(
			$order,
			$valor,
			__( 'Pagamento confirmado pela conciliação automática (consulta à Pagou).', 'pagou-payments-for-woocommerce' ),
			( $is_pix ? 'qrcode.completed' : 'charge.paid' ) . ':' . $id . ':conciliacao'
		);

		Logger::log( 'Conciliação aplicada.', array( 'pedido' => $order->get_id(), 'resultado' => $resultado ) );
	}
}
