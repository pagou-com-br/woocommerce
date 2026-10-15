<?php
/**
 * Base comum dos meios de pagamento Pagou.
 *
 * Chave de API, ambiente e modo debug ficam na aba WooCommerce > Configurações > Pagou e
 * valem para todos os meios. Cada gateway só define o que é dele.
 *
 * @package Pagou
 */

namespace Pagou\WooCommerce\Gateway;

use Pagou\WooCommerce\Api\Client;
use Pagou\WooCommerce\Api\Errors;
use Pagou\WooCommerce\Api\Response;
use Pagou\WooCommerce\Checkout\Payer;
use Pagou\WooCommerce\Discounts;
use Pagou\WooCommerce\Logger;
use Pagou\WooCommerce\Plugin;
use Pagou\WooCommerce\Settings;
use WC_Order;
use WC_Payment_Gateway;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class AbstractGateway extends WC_Payment_Gateway {

	/**
	 * @var bool Se o meio aceita desconto configurável.
	 */
	protected $aceita_desconto = true;

	/**
	 * Título padrão exibido ao cliente.
	 *
	 * @return string
	 */
	abstract protected function titulo_padrao();

	/**
	 * Descrição padrão exibida ao cliente.
	 *
	 * @return string
	 */
	abstract protected function descricao_padrao();

	/**
	 * Campos de configuração específicos do meio.
	 *
	 * @return array
	 */
	protected function campos_especificos() {
		return array();
	}

	/**
	 * Inicialização comum. Chamada pelo construtor de cada gateway.
	 */
	protected function iniciar_gateway() {
		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title' ) ? $this->get_option( 'title' ) : $this->titulo_padrao();
		$this->description = $this->get_option( 'description' ) ? $this->get_option( 'description' ) : $this->descricao_padrao();
		$this->enabled     = $this->get_option( 'enabled', 'no' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_filter( 'woocommerce_thankyou_order_received_text', array( $this, 'texto_pedido_recebido' ), 10, 2 );
	}

	public function init_form_fields() {
		$campos = array(
			'enabled'     => array(
				'title'   => __( 'Ativar/Desativar', 'pagou-payments-for-woocommerce' ),
				/* translators: %s: nome do meio de pagamento */
				'label'   => sprintf( __( 'Ativar %s pela Pagou', 'pagou-payments-for-woocommerce' ), $this->method_title ),
				'type'    => 'checkbox',
				'default' => 'no',
			),
			'credenciais' => array(
				'title'       => __( 'Conta Pagou', 'pagou-payments-for-woocommerce' ),
				'type'        => 'title',
				'description' => $this->descricao_credenciais(),
			),
			'title'       => array(
				'title'       => __( 'Título', 'pagou-payments-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Nome do meio de pagamento exibido ao cliente no checkout.', 'pagou-payments-for-woocommerce' ),
				'default'     => $this->titulo_padrao(),
				'desc_tip'    => true,
			),
			'description' => array(
				'title'       => __( 'Descrição', 'pagou-payments-for-woocommerce' ),
				'type'        => 'textarea',
				'description' => __( 'Texto exibido quando o cliente seleciona este meio de pagamento.', 'pagou-payments-for-woocommerce' ),
				'default'     => $this->descricao_padrao(),
			),
		);

		$campos = array_merge( $campos, $this->campos_especificos() );

		if ( $this->aceita_desconto ) {
			$campos = array_merge( $campos, Discounts::campos_configuracao( $this->method_title ) );
		}

		$this->form_fields = $campos;
	}

	/**
	 * Aviso, nas configurações do gateway, de onde ficam chave e ambiente.
	 *
	 * @return string
	 */
	private function descricao_credenciais() {
		$link = admin_url( 'admin.php?page=wc-settings&tab=pagou' );

		if ( '' === Settings::api_key() ) {
			return sprintf(
				/* translators: %s: link para a aba de configurações da Pagou */
				__( '<strong>Chave de API não configurada.</strong> Informe a chave em <a href="%s">WooCommerce &gt; Configurações &gt; Pagou</a>; ela vale para todos os meios de pagamento.', 'pagou-payments-for-woocommerce' ),
				esc_url( $link )
			);
		}

		return sprintf(
			/* translators: 1: ambiente, 2: link */
			__( 'Ambiente: <strong>%1$s</strong>. Chave de API, ambiente e logs ficam em <a href="%2$s">WooCommerce &gt; Configurações &gt; Pagou</a>.', 'pagou-payments-for-woocommerce' ),
			Settings::sandbox() ? esc_html__( 'Sandbox (testes)', 'pagou-payments-for-woocommerce' ) : esc_html__( 'Produção', 'pagou-payments-for-woocommerce' ),
			esc_url( $link )
		);
	}

	/**
	 * Só aparece no checkout com chave configurada e loja em real.
	 *
	 * @return bool
	 */
	public function is_available() {
		if ( ! parent::is_available() ) {
			return false;
		}

		if ( '' === Settings::api_key() ) {
			return false;
		}

		return 'BRL' === get_woocommerce_currency();
	}

	/**
	 * @return Client
	 */
	protected function api() {
		return new Client();
	}

	/**
	 * URL de notificação enviada à Pagou (rota REST, funciona sem permalink bonito).
	 *
	 * @return string
	 */
	public static function url_webhook() {
		return rest_url( 'pagou/v1/webhook' );
	}

	/**
	 * Valida os dados do pagador e devolve a mensagem de erro, ou '' quando está tudo certo.
	 *
	 * @param WC_Order $order
	 * @param bool     $exige_endereco
	 * @param bool     $exige_contato
	 * @return string
	 */
	protected function validar_pagador( WC_Order $order, $exige_endereco, $exige_contato = false ) {
		$faltando = Payer::faltando( $order, $exige_endereco, $exige_contato );

		if ( empty( $faltando ) ) {
			return '';
		}

		Logger::aviso( 'Pagamento bloqueado: dados do pagador incompletos.', array( 'pedido' => $order->get_id(), 'gateway' => $this->id, 'faltando' => $faltando ) );

		return sprintf(
			/* translators: 1: meio de pagamento, 2: lista de campos */
			__( 'Para pagar com %1$s, preencha: %2$s.', 'pagou-payments-for-woocommerce' ),
			$this->title,
			Errors::listar( $faltando )
		);
	}

	/**
	 * Falha no checkout com mensagem ao cliente.
	 *
	 * @param string $mensagem
	 * @return array
	 */
	protected function falhar( $mensagem ) {
		wc_add_notice( $mensagem, 'error' );

		return array(
			'result'   => 'failure',
			'redirect' => '',
		);
	}

	/**
	 * Falha a partir de uma resposta de erro da API.
	 *
	 * @param WC_Order $order
	 * @param Response $resposta
	 * @param string   $padrao
	 * @return array
	 */
	protected function falhar_api( WC_Order $order, Response $resposta, $padrao ) {
		if ( 401 === $resposta->http ) {
			Plugin::avisar_admin( 'chave_invalida', __( 'A Pagou recusou a chave de API configurada (chave inválida para o ambiente escolhido). Os pagamentos estão falhando até a chave ser corrigida.', 'pagou-payments-for-woocommerce' ) );
		}

		$order->add_order_note(
			sprintf(
				/* translators: 1: meio de pagamento, 2: erro */
				__( 'Pagou: falha ao criar a cobrança de %1$s (%2$s).', 'pagou-payments-for-woocommerce' ),
				$this->method_title,
				$resposta->erro()
			)
		);

		return $this->falhar( Errors::para_cliente( $resposta, $padrao ) );
	}

	/**
	 * Sucesso no checkout: aguarda pagamento e leva para a tela do pedido.
	 *
	 * @param WC_Order $order
	 * @param string   $nota
	 * @return array
	 */
	protected function aguardar_pagamento( WC_Order $order, $nota ) {
		if ( ! $order->is_paid() ) {
			$order->update_status( 'on-hold', $nota );
		}

		if ( function_exists( 'WC' ) && WC()->cart ) {
			WC()->cart->empty_cart();
		}

		return array(
			'result'   => 'success',
			'redirect' => $this->get_return_url( $order ),
		);
	}

	/**
	 * Texto da página "pedido recebido" enquanto o pagamento não entra.
	 *
	 * @param string   $texto
	 * @param WC_Order $order
	 * @return string
	 */
	public function texto_pedido_recebido( $texto, $order ) {
		if ( ! $order instanceof WC_Order || $order->get_payment_method() !== $this->id ) {
			return $texto;
		}

		if ( $order->is_paid() ) {
			return __( 'Pagamento confirmado. Obrigado pela compra!', 'pagou-payments-for-woocommerce' );
		}

		return $this->texto_aguardando();
	}

	/**
	 * @return string
	 */
	protected function texto_aguardando() {
		return __( 'Recebemos seu pedido. Para concluir, faça o pagamento abaixo.', 'pagou-payments-for-woocommerce' );
	}
}
