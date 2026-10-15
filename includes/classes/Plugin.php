<?php
/**
 * Inicialização do plugin.
 *
 * @package Pagou
 */

namespace Pagou\WooCommerce;

use Pagou\WooCommerce\Api\Client;
use Pagou\WooCommerce\Api\Response;
use WC_Order;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Plugin {

	const OPCAO_AVISOS = 'pagou_wc_avisos';

	/**
	 * Gateways do plugin (id => classe).
	 *
	 * @return array
	 */
	public static function gateways() {
		return array(
			'pagou_pix'    => Gateway\Pix::class,
			'pagou_boleto' => Gateway\Boleto::class,
			'pagou_cartao' => Gateway\Card::class,
		);
	}

	public static function iniciar() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( __CLASS__, 'aviso_sem_woocommerce' ) );
			return;
		}

		Upgrade::rodar();

		add_filter( 'woocommerce_payment_gateways', array( __CLASS__, 'registrar_gateways' ) );
		// O WooCommerce dispara woocommerce_blocks_loaded no plugins_loaded de prioridade 10,
		// antes deste init: nesse caso registra direto.
		if ( did_action( 'woocommerce_blocks_loaded' ) ) {
			self::registrar_blocos();
		} else {
			add_action( 'woocommerce_blocks_loaded', array( __CLASS__, 'registrar_blocos' ) );
		}
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'registrar_assets' ), 5 );
		add_filter( 'plugin_action_links_' . plugin_basename( PAGOU_WC_FILE ), array( __CLASS__, 'links_plugin' ) );

		Checkout\BrazilianFields::init();
		Discounts::init();
		Emails::init();
		Webhook\Controller::init();
		Cron\Reconciliation::init();
		Cron\CardSync::init();
		Card\Tokens::init();
		Split\Integration::init();
		Frontend\StatusEndpoint::init();
		Compat\AddressBlocks::init();

		if ( is_admin() ) {
			Admin\SettingsPage::init();
			Admin\Notices::init();
			Admin\OrderPanel::init();
		}
	}

	public static function ativar() {
		update_option( 'pagou_wc_ativado_em', time(), false );
	}

	public static function desativar() {
		wp_clear_scheduled_hook( Cron\Reconciliation::HOOK );
		wp_clear_scheduled_hook( Cron\CardSync::HOOK_CHARGEBACKS );
		wp_clear_scheduled_hook( 'acppfw_pagou_conciliar' );
	}

	public static function aviso_sem_woocommerce() {
		echo '<div class="notice notice-error"><p><strong>Pagou</strong> ' . esc_html__( 'requer o WooCommerce instalado e ativo.', 'pagou-payments-for-woocommerce' ) . '</p></div>';
	}

	/**
	 * @param array $gateways
	 * @return array
	 */
	public static function registrar_gateways( $gateways ) {
		foreach ( self::gateways() as $classe ) {
			$gateways[] = $classe;
		}

		return $gateways;
	}

	public static function registrar_blocos() {
		if ( ! class_exists( '\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
			return;
		}

		add_action(
			'woocommerce_blocks_payment_method_type_registration',
			function ( $registro ) {
				$registro->register( new Blocks\Pix() );
				$registro->register( new Blocks\Boleto() );
				$registro->register( new Blocks\Card() );
			}
		);
	}

	/**
	 * @param array $links
	 * @return array
	 */
	public static function links_plugin( $links ) {
		array_unshift(
			$links,
			'<a href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=pagou' ) ) . '">' . esc_html__( 'Configurações', 'pagou-payments-for-woocommerce' ) . '</a>'
		);

		return $links;
	}

	/**
	 * Versão de um arquivo para cache do navegador (versão do plugin + data do arquivo).
	 *
	 * @param string $relativo
	 * @return string
	 */
	public static function versao_asset( $relativo ) {
		$arquivo = PAGOU_WC_DIR . $relativo;

		return file_exists( $arquivo ) ? PAGOU_WC_VERSION . '.' . filemtime( $arquivo ) : PAGOU_WC_VERSION;
	}

	public static function registrar_assets() {
		wp_register_style( 'pagou-wc', PAGOU_WC_URL . 'assets/css/style.css', array(), self::versao_asset( 'assets/css/style.css' ) );

		// No checkout o estilo dimensiona os ícones dos meios de pagamento.
		if ( function_exists( 'is_checkout' ) && is_checkout() ) {
			wp_enqueue_style( 'pagou-wc' );
		}

		wp_register_script( 'pagou-wc-qrcode', PAGOU_WC_URL . 'assets/js/vendor/qrcode.js', array(), '1.4.4', true );
		wp_register_script( 'pagou-wc-barcode', PAGOU_WC_URL . 'assets/js/vendor/JsBarcode.all.min.js', array(), '3.11.6', true );

		wp_register_script(
			'pagou-wc-pagamento',
			PAGOU_WC_URL . 'assets/js/pagamento.js',
			array(),
			self::versao_asset( 'assets/js/pagamento.js' ),
			true
		);

		wp_localize_script(
			'pagou-wc-pagamento',
			'pagouPagamento',
			array(
				'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
				'copiado'     => __( 'Código copiado', 'pagou-payments-for-woocommerce' ),
				'erroCopia'   => __( 'Não foi possível copiar', 'pagou-payments-for-woocommerce' ),
				'expirado'    => __( 'Código expirado', 'pagou-payments-for-woocommerce' ),
				'confirmado'  => __( 'Pagamento confirmado. Atualizando...', 'pagou-payments-for-woocommerce' ),
				'qrAlt'       => __( 'QR Code para pagamento via Pix', 'pagou-payments-for-woocommerce' ),
			)
		);
	}

	/**
	 * Enfileira os recursos da tela de pagamento.
	 *
	 * @param bool $pix True para incluir o gerador de QR Code.
	 */
	public static function enfileirar_tela_pagamento( $pix ) {
		wp_enqueue_style( 'pagou-wc' );
		wp_enqueue_script( 'pagou-wc-pagamento' );

		if ( $pix ) {
			wp_enqueue_script( 'pagou-wc-qrcode' );
		} else {
			wp_enqueue_script( 'pagou-wc-barcode' );
			wp_enqueue_script( 'pagou-wc-qrcode' );
		}
	}

	/**
	 * Carrega um template, permitindo que o tema o sobrescreva em
	 * wp-content/themes/SEU-TEMA/woocommerce/pagou/.
	 *
	 * @param string $nome
	 * @param array  $args
	 */
	public static function template( $nome, $args = array() ) {
		wc_get_template( $nome, $args, 'woocommerce/pagou/', PAGOU_WC_DIR . 'templates/' );
	}

	/**
	 * Cria uma cobrança, aplicando o split configurado.
	 *
	 * Se a Pagou recusar o split (recebedor revogado, margem de tarifa insuficiente, plano
	 * sem split), a venda não é perdida: a cobrança é recriada sem split e o lojista é
	 * avisado no painel e no pedido.
	 *
	 * @param Client      $api
	 * @param string      $caminho
	 * @param array       $corpo
	 * @param WC_Order    $order
	 * @param string      $unidade      'reais' (Pix/boleto) ou 'centavos' (cartão).
	 * @param string|null $idempotencia
	 * @return Response
	 */
	public static function criar_cobranca( Client $api, $caminho, array $corpo, WC_Order $order, $unidade, $idempotencia = null ) {
		/**
		 * Permite acrescentar dados ao corpo da cobrança (o split é aplicado aqui).
		 *
		 * @param array    $corpo
		 * @param WC_Order $order
		 * @param string   $unidade
		 */
		$com_extras = apply_filters( 'pagou_wc_corpo_cobranca', $corpo, $order, $unidade );

		$resposta = $api->post( $caminho, $com_extras, $idempotencia );

		if ( empty( $com_extras['split'] ) || $resposta->ok() || $resposta->incerto() || ! self::erro_de_split( $resposta ) ) {
			return $resposta;
		}

		/**
		 * Split recusado na criação da cobrança.
		 *
		 * @param WC_Order $order
		 * @param Response $resposta
		 * @param array    $split
		 */
		do_action( 'pagou_wc_split_recusado', $order, $resposta, $com_extras['split'] );

		unset( $com_extras['split'] );

		// Corpo diferente exige chave de idempotência diferente.
		$nova_chave = $idempotencia ? Client::nova_chave_idempotencia() : null;
		if ( $nova_chave ) {
			$order->update_meta_data( '_pagou_idempotencia', $nova_chave );
			$order->save();
		}

		return $api->post( $caminho, $com_extras, $nova_chave );
	}

	/**
	 * @param Response $resposta
	 * @return bool True se o erro foi causado pelo split.
	 */
	private static function erro_de_split( Response $resposta ) {
		if ( ! in_array( $resposta->http, array( 400, 404, 422 ), true ) ) {
			return false;
		}

		$texto = strtolower( $resposta->erro() . ' ' . implode( ' ', $resposta->erros_validacao() ) );

		return false !== strpos( $texto, 'split' ) || false !== strpos( $texto, 'allocation' );
	}

	/**
	 * Registra um aviso persistente para o admin (exibido até ser dispensado).
	 *
	 * @param string $chave    Avisos com a mesma chave não se acumulam.
	 * @param string $mensagem
	 * @param string $tipo     error, warning, info.
	 */
	public static function avisar_admin( $chave, $mensagem, $tipo = 'error' ) {
		$avisos = get_option( self::OPCAO_AVISOS, array() );
		$avisos = is_array( $avisos ) ? $avisos : array();

		$avisos[ sanitize_key( $chave ) ] = array(
			'mensagem' => $mensagem,
			'tipo'     => $tipo,
			'quando'   => time(),
		);

		update_option( self::OPCAO_AVISOS, $avisos, false );
	}
}
