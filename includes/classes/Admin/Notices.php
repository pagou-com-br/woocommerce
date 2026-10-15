<?php
/**
 * Avisos persistentes do plugin no painel (chave recusada, split recusado, migração).
 *
 * @package Pagou
 */

namespace Pagou\WooCommerce\Admin;

use Pagou\WooCommerce\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Notices {

	public static function init() {
		add_action( 'admin_notices', array( __CLASS__, 'exibir' ) );
		add_action( 'admin_post_pagou_wc_dispensar', array( __CLASS__, 'dispensar' ) );
	}

	public static function exibir() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$avisos = get_option( Plugin::OPCAO_AVISOS, array() );

		if ( empty( $avisos ) || ! is_array( $avisos ) ) {
			return;
		}

		foreach ( $avisos as $chave => $aviso ) {
			$tipo = in_array( $aviso['tipo'], array( 'error', 'warning', 'info', 'success' ), true ) ? $aviso['tipo'] : 'info';
			$url  = wp_nonce_url(
				admin_url( 'admin-post.php?action=pagou_wc_dispensar&aviso=' . rawurlencode( $chave ) ),
				'pagou_wc_dispensar'
			);

			printf(
				'<div class="notice notice-%1$s"><p><strong>Pagou:</strong> %2$s <a href="%3$s">%4$s</a></p></div>',
				esc_attr( $tipo ),
				wp_kses_post( $aviso['mensagem'] ),
				esc_url( $url ),
				esc_html__( 'Dispensar', 'pagou-payments-for-woocommerce' )
			);
		}
	}

	public static function dispensar() {
		check_admin_referer( 'pagou_wc_dispensar' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Sem permissão.', 'pagou-payments-for-woocommerce' ) );
		}

		$chave  = isset( $_GET['aviso'] ) ? sanitize_key( wp_unslash( $_GET['aviso'] ) ) : '';
		$avisos = get_option( Plugin::OPCAO_AVISOS, array() );

		if ( is_array( $avisos ) && isset( $avisos[ $chave ] ) ) {
			unset( $avisos[ $chave ] );
			update_option( Plugin::OPCAO_AVISOS, $avisos, false );
		}

		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
		exit;
	}
}
