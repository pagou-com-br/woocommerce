<?php
/**
 * Transições de estado do pedido a partir de fatos da Pagou.
 *
 * Webhook, conciliação, tela de pagamento e cartão chamam estas funções. Todas rodam com
 * a trava do pedido e são idempotentes: o mesmo fato aplicado duas vezes não tem efeito
 * na segunda.
 *
 * @package Pagou
 */

namespace Pagou\WooCommerce\Order;

use Pagou\WooCommerce\Logger;
use WC_Order;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Payments {

	const META_EVENTOS = '_pagou_eventos_processados';

	/**
	 * Confirma o pagamento de um pedido.
	 *
	 * @param WC_Order    $order
	 * @param float|null  $valor_pago  Em reais. Null quando a origem não informa valor.
	 * @param string      $origem      Texto para a nota do pedido.
	 * @param string      $chave       Identidade do fato (dedupe).
	 * @param string      $transacao   ID da transação, gravado no pedido.
	 * @return string 'confirmado', 'ja_pago', 'valor_menor', 'ocupado' ou 'ignorado'.
	 */
	public static function confirmar( WC_Order $order, $valor_pago, $origem, $chave, $transacao = '' ) {
		$order_id = $order->get_id();

		$resultado = Lock::com_trava(
			$order_id,
			function () use ( $order_id, $valor_pago, $origem, $chave, $transacao ) {
				// Relê o pedido dentro da trava: outro processo pode ter acabado de confirmá-lo.
				$order = wc_get_order( $order_id );

				if ( ! $order ) {
					return 'ignorado';
				}

				if ( self::processado( $order, $chave ) ) {
					return 'ja_pago';
				}

				if ( $order->is_paid() || $order->has_status( array( 'refunded' ) ) ) {
					self::registrar( $order, $chave );
					return 'ja_pago';
				}

				if ( null !== $valor_pago && ! self::valor_suficiente( $order, $valor_pago ) ) {
					$order->update_status(
						'on-hold',
						sprintf(
							/* translators: 1: origem, 2: valor recebido, 3: valor esperado */
							__( '%1$s: pagamento recebido com valor menor que o total do pedido (recebido %2$s, esperado %3$s). Confira manualmente.', 'pagou-payments-for-woocommerce' ),
							$origem,
							wp_strip_all_tags( wc_price( $valor_pago ) ),
							wp_strip_all_tags( wc_price( $order->get_total() ) )
						)
					);
					self::registrar( $order, $chave );
					return 'valor_menor';
				}

				if ( '' !== $transacao ) {
					$order->update_meta_data( '_pagou_transacao_id', sanitize_text_field( $transacao ) );
				}

				$order->payment_complete( '' !== $transacao ? sanitize_text_field( $transacao ) : '' );

				$nota = $origem;
				if ( null !== $valor_pago && (float) $valor_pago > ( (float) $order->get_total() + 0.01 ) ) {
					$nota .= ' ' . sprintf(
						/* translators: %s: valor pago */
						__( 'Valor pago com acréscimo de multa/juros: %s.', 'pagou-payments-for-woocommerce' ),
						wp_strip_all_tags( wc_price( $valor_pago ) )
					);
				}
				$order->add_order_note( $nota );

				self::registrar( $order, $chave );

				return 'confirmado';
			}
		);

		return null === $resultado ? 'ocupado' : $resultado;
	}

	/**
	 * Registra um estorno confirmado pela Pagou.
	 *
	 * Estornos pedidos pelo admin já criam o reembolso no WooCommerce; aqui só anotamos.
	 * Quando a soma devolvida cobre o pedido inteiro, o status vira "reembolsado".
	 *
	 * @param WC_Order   $order
	 * @param float|null $valor  Em reais; null = integral.
	 * @param string     $origem
	 * @param string     $chave
	 * @return string
	 */
	public static function estornado( WC_Order $order, $valor, $origem, $chave ) {
		$order_id = $order->get_id();

		$resultado = Lock::com_trava(
			$order_id,
			function () use ( $order_id, $valor, $origem, $chave ) {
				$order = wc_get_order( $order_id );

				if ( ! $order || self::processado( $order, $chave ) ) {
					return 'ignorado';
				}

				$texto = null === $valor
					? $origem
					: sprintf(
						/* translators: 1: origem, 2: valor */
						__( '%1$s Valor: %2$s.', 'pagou-payments-for-woocommerce' ),
						$origem,
						wp_strip_all_tags( wc_price( $valor ) )
					);

				$integral = null === $valor || (float) $valor >= ( (float) $order->get_total() - 0.01 )
					|| (float) $order->get_total_refunded() >= ( (float) $order->get_total() - 0.01 );

				if ( $integral && ! $order->has_status( 'refunded' ) ) {
					$order->update_status( 'refunded', $texto );
				} else {
					$order->add_order_note( $texto );
				}

				self::registrar( $order, $chave );

				return 'estornado';
			}
		);

		return null === $resultado ? 'ocupado' : $resultado;
	}

	/**
	 * Cancela um pedido ainda não pago.
	 *
	 * @param WC_Order $order
	 * @param string   $nota
	 * @param string   $chave
	 * @return string
	 */
	public static function cancelar( WC_Order $order, $nota, $chave ) {
		$order_id = $order->get_id();

		$resultado = Lock::com_trava(
			$order_id,
			function () use ( $order_id, $nota, $chave ) {
				$order = wc_get_order( $order_id );

				if ( ! $order || self::processado( $order, $chave ) ) {
					return 'ignorado';
				}

				// Um evento atrasado de cancelamento nunca desfaz um pagamento.
				if ( $order->is_paid() ) {
					$order->add_order_note( $nota . ' ' . __( '(ignorado: o pedido já está pago)', 'pagou-payments-for-woocommerce' ) );
				} else {
					$order->update_status( 'cancelled', $nota );
				}

				self::registrar( $order, $chave );

				return 'cancelado';
			}
		);

		return null === $resultado ? 'ocupado' : $resultado;
	}

	/**
	 * Aceita valor igual ou maior que o total (boleto em atraso vem com multa e juros).
	 * Tolerância de 1 centavo para arredondamento.
	 *
	 * @param WC_Order $order
	 * @param float    $valor
	 * @return bool
	 */
	public static function valor_suficiente( WC_Order $order, $valor ) {
		return (float) $valor >= ( (float) $order->get_total() - 0.01 );
	}

	/**
	 * @param WC_Order $order
	 * @param string   $chave
	 * @return bool
	 */
	public static function processado( WC_Order $order, $chave ) {
		if ( '' === $chave ) {
			return false;
		}

		$lista = $order->get_meta( self::META_EVENTOS );

		return is_array( $lista ) && in_array( $chave, $lista, true );
	}

	/**
	 * @param WC_Order $order
	 * @param string   $chave
	 */
	public static function registrar( WC_Order $order, $chave ) {
		if ( '' === $chave ) {
			$order->save();
			return;
		}

		$lista   = $order->get_meta( self::META_EVENTOS );
		$lista   = is_array( $lista ) ? $lista : array();
		$lista[] = $chave;

		// Guarda só os últimos 50 eventos: o histórico completo está nas notas.
		$order->update_meta_data( self::META_EVENTOS, array_slice( array_values( array_unique( $lista ) ), -50 ) );
		$order->save();

		Logger::log( 'Evento aplicado ao pedido.', array( 'pedido' => $order->get_id(), 'evento' => $chave ) );
	}
}
