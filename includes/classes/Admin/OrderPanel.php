<?php
/**
 * Dados da Pagou na tela do pedido (admin).
 *
 * @package Pagou
 */

namespace Pagou\WooCommerce\Admin;

use Pagou\WooCommerce\Plugin;
use WC_Order;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OrderPanel {

	public static function init() {
		add_action( 'woocommerce_admin_order_data_after_order_details', array( __CLASS__, 'exibir' ) );
	}

	/**
	 * Linhas (rótulo => valor) exibidas para o pedido. Outros meios acrescentam pelo filtro.
	 *
	 * @param WC_Order $order
	 * @return array
	 */
	public static function linhas( WC_Order $order ) {
		$linhas = array();

		$mapa = array(
			'_pagou_pix_id'       => __( 'ID do Pix', 'pagou-payments-for-woocommerce' ),
			'_pagou_boleto_id'    => __( 'ID do boleto', 'pagou-payments-for-woocommerce' ),
			'_pagou_transacao_id' => __( 'Transação', 'pagou-payments-for-woocommerce' ),
			'_pagou_e2e_id'       => __( 'E2E (Pix)', 'pagou-payments-for-woocommerce' ),
			'_pagou_cartao_id'        => __( 'ID da cobrança de cartão', 'pagou-payments-for-woocommerce' ),
			'_pagou_cartao_status'    => __( 'Status do cartão na Pagou', 'pagou-payments-for-woocommerce' ),
			'_pagou_cartao_parcelas'  => __( 'Parcelas', 'pagou-payments-for-woocommerce' ),
			'_pagou_chargeback_prazo' => __( 'Prazo da defesa de chargeback', 'pagou-payments-for-woocommerce' ),
		);

		foreach ( $mapa as $meta => $rotulo ) {
			$valor = $order->get_meta( $meta );
			if ( is_scalar( $valor ) && '' !== (string) $valor ) {
				$linhas[ $rotulo ] = (string) $valor;
			}
		}

		/**
		 * @param array    $linhas
		 * @param WC_Order $order
		 */
		return apply_filters( 'pagou_wc_painel_pedido', $linhas, $order );
	}

	/**
	 * @param WC_Order $order
	 */
	public static function exibir( $order ) {
		if ( ! $order instanceof WC_Order || ! array_key_exists( $order->get_payment_method(), Plugin::gateways() ) ) {
			return;
		}

		$linhas = self::linhas( $order );

		echo '<div class="form-field form-field-wide pagou-painel-pedido" style="margin-top:16px;">';
		echo '<h3>' . esc_html__( 'Pagou', 'pagou-payments-for-woocommerce' ) . '</h3>';

		if ( empty( $linhas ) ) {
			echo '<p>' . esc_html__( 'Nenhuma cobrança criada para este pedido.', 'pagou-payments-for-woocommerce' ) . '</p>';
		} else {
			echo '<p style="word-break:break-all;">';
			foreach ( $linhas as $rotulo => $valor ) {
				if ( 0 === strpos( $valor, admin_url() ) ) {
					echo '<a href="' . esc_url( $valor ) . '">' . esc_html( $rotulo ) . '</a><br>';
					continue;
				}
				echo '<strong>' . esc_html( $rotulo ) . ':</strong> ' . esc_html( $valor ) . '<br>';
			}
			echo '</p>';
		}

		echo '<p><a href="' . esc_url( $order->get_checkout_order_received_url() ) . '" target="_blank" rel="noopener">' . esc_html__( 'Ver a tela de pagamento do cliente', 'pagou-payments-for-woocommerce' ) . '</a></p>';
		echo '</div>';
	}
}
