<?php
/**
 * Migrações entre versões.
 *
 * @package Pagou
 */

namespace Pagou\WooCommerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Upgrade {

	const OPCAO_VERSAO = 'pagou_wc_db_version';

	public static function rodar() {
		$instalada = (string) get_option( self::OPCAO_VERSAO, '' );

		if ( version_compare( $instalada, PAGOU_WC_VERSION, '>=' ) ) {
			return;
		}

		if ( '' === $instalada || version_compare( $instalada, '2.0.0', '<' ) ) {
			self::para_2_0_0();
		}

		update_option( self::OPCAO_VERSAO, PAGOU_WC_VERSION, false );
	}

	/**
	 * 1.x para 2.0.0: chave de API, ambiente e debug passam a ser únicos.
	 *
	 * As configurações antigas dos gateways não são apagadas: se alguém voltar para a
	 * 1.3.x, ela continua funcionando. As chaves antigas também ficam guardadas para
	 * validar webhooks de cobranças criadas antes da atualização.
	 */
	private static function para_2_0_0() {
		$pix    = get_option( 'woocommerce_pagou_pix_settings', array() );
		$boleto = get_option( 'woocommerce_pagou_boleto_settings', array() );
		$pix    = is_array( $pix ) ? $pix : array();
		$boleto = is_array( $boleto ) ? $boleto : array();

		$atuais = get_option( Settings::OPCAO, array() );
		$atuais = is_array( $atuais ) ? $atuais : array();

		$chave_pix    = isset( $pix['api_key'] ) ? trim( (string) $pix['api_key'] ) : '';
		$chave_boleto = isset( $boleto['api_key'] ) ? trim( (string) $boleto['api_key'] ) : '';

		// Prioriza o gateway ativo; entre dois ativos, o Pix.
		$pix_ativo    = isset( $pix['enabled'] ) && 'yes' === $pix['enabled'];
		$boleto_ativo = isset( $boleto['enabled'] ) && 'yes' === $boleto['enabled'];
		$origem       = ( '' !== $chave_pix && ( $pix_ativo || ! $boleto_ativo ) ) || '' === $chave_boleto ? $pix : $boleto;

		$novas = array();

		if ( empty( $atuais['api_key'] ) && ! empty( $origem['api_key'] ) ) {
			$novas['api_key'] = trim( (string) $origem['api_key'] );
		}

		if ( ! isset( $atuais['environment'] ) && ! empty( $origem['environment'] ) ) {
			$novas['environment'] = 'hom' === $origem['environment'] ? 'hom' : 'pro';
		}

		if ( ! isset( $atuais['debug'] ) ) {
			$novas['debug'] = ( isset( $pix['debug'] ) && 'yes' === $pix['debug'] ) || ( isset( $boleto['debug'] ) && 'yes' === $boleto['debug'] ) ? 'yes' : 'no';
		}

		$antigas = array_values( array_unique( array_filter( array( $chave_pix, $chave_boleto ) ) ) );
		if ( ! empty( $antigas ) ) {
			$novas['chaves_antigas'] = $antigas;
		}

		if ( ! empty( $novas ) ) {
			Settings::salvar( $novas );
		}

		if ( '' !== $chave_pix && '' !== $chave_boleto && $chave_pix !== $chave_boleto ) {
			Plugin::avisar_admin(
				'chaves_diferentes',
				__( 'Pagou 2.0: o Pix e o boleto usavam chaves de API diferentes. Agora a chave é única para todos os meios de pagamento; mantivemos a do meio que estava ativo. Confira em WooCommerce > Configurações > Pagou.', 'pagou-payments-for-woocommerce' ),
				'warning'
			);
		}

		if ( isset( $origem['environment'] ) && 'hom' === $origem['environment'] ) {
			Plugin::avisar_admin(
				'sandbox_novo',
				__( 'Pagou 2.0: o ambiente de testes da Pagou mudou de endereço (agora api.pagou.cloud). Se a sua chave de sandbox parar de funcionar, gere uma nova no painel de sandbox da Pagou.', 'pagou-payments-for-woocommerce' ),
				'info'
			);
		}

		// O agendamento da conciliação mudou de nome.
		wp_clear_scheduled_hook( 'acppfw_pagou_conciliar' );
	}
}
