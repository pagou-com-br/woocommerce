<?php
/**
 * Consulta de status usada pela tela de pagamento para detectar o pagamento sem o cliente
 * recarregar a página.
 *
 * Sem nonce de propósito: quem consulta pode ser um visitante que voltou pelo link do
 * e-mail (nonce expirado). A autorização é a chave do pedido, comparada com hash_equals,
 * o mesmo mecanismo que o WooCommerce usa para mostrar o pedido a quem não está logado.
 * O endpoint é somente leitura.
 *
 * @package Pagou
 */

namespace Pagou\WooCommerce\Frontend;

use Pagou\WooCommerce\Cron\Reconciliation;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class StatusEndpoint {

	public static function init() {
		add_action( 'wp_ajax_pagou_wc_status', array( __CLASS__, 'responder' ) );
		add_action( 'wp_ajax_nopriv_pagou_wc_status', array( __CLASS__, 'responder' ) );
	}

	public static function responder() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- autorização pela chave do pedido (ver cabeçalho).
		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
		$chave    = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
		$profundo = isset( $_GET['deep'] ) && '1' === $_GET['deep'];
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$order = $order_id ? wc_get_order( $order_id ) : false;

		if ( ! $order || ! hash_equals( (string) $order->get_order_key(), $chave ) ) {
			wp_send_json_error( array( 'message' => 'Pedido não encontrado.' ), 403 );
		}

		// Consulta direta à Pagou como rede de segurança, limitada a uma por pedido a cada
		// 25 segundos: sem esse freio, o link do pedido viraria um amplificador de requisições.
		if ( $profundo && ! $order->is_paid() ) {
			$freio = 'pagou_wc_deep_' . $order_id;

			if ( false === get_transient( $freio ) ) {
				set_transient( $freio, 1, 25 );
				Reconciliation::verificar_pedido( $order_id );
				$order = wc_get_order( $order_id );
			}
		}

		wp_send_json_success(
			array(
				'pago'     => $order->is_paid(),
				'status'   => $order->get_status(),
				'redirect' => $order->is_paid() ? $order->get_checkout_order_received_url() : '',
			)
		);
	}
}
