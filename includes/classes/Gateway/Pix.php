<?php
/**
 * Pix imediato.
 *
 * @package Pagou
 */

namespace Pagou\WooCommerce\Gateway;

use Pagou\WooCommerce\Checkout\Payer;
use Pagou\WooCommerce\Discounts;
use Pagou\WooCommerce\Plugin;
use WC_Order;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Pix extends AbstractGateway {

	const ID = 'pagou_pix';

	public function __construct() {
		$this->id                 = self::ID;
		$this->icon               = PAGOU_WC_URL . 'assets/img/pix.svg';
		$this->has_fields         = false;
		$this->method_title       = 'PIX';
		$this->method_description = __( 'Pix com QR Code e copia e cola, confirmado automaticamente pela Pagou.', 'pagou-payments-for-woocommerce' );
		$this->supports           = array( 'products', 'refunds' );

		$this->iniciar_gateway();

		add_action( 'woocommerce_before_thankyou', array( $this, 'exibir_pagamento' ) );
		add_action( 'woocommerce_view_order', array( $this, 'exibir_pagamento' ), 5 );
	}

	protected function titulo_padrao() {
		return __( 'Pix Instantâneo', 'pagou-payments-for-woocommerce' );
	}

	protected function descricao_padrao() {
		return __( 'Ao finalizar a compra, geramos o código Pix para pagamento. O pagamento é identificado automaticamente, sem precisar enviar comprovante.', 'pagou-payments-for-woocommerce' );
	}

	protected function campos_especificos() {
		return array(
			'expiration' => array(
				'title'       => __( 'Tempo para pagamento (em segundos)', 'pagou-payments-for-woocommerce' ),
				'type'        => 'number',
				'description' => __( 'Validade do código Pix. Padrão: 3600 (1 hora).', 'pagou-payments-for-woocommerce' ),
				'default'     => '3600',
				'desc_tip'    => true,
				'custom_attributes' => array(
					'min'  => 60,
					'step' => 60,
				),
			),
		);
	}

	/**
	 * @return int Validade do Pix em segundos.
	 */
	private function validade() {
		return max( 60, (int) $this->get_option( 'expiration', 3600 ) );
	}

	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return $this->falhar( __( 'Pedido não encontrado.', 'pagou-payments-for-woocommerce' ) );
		}

		$erro = $this->validar_pagador( $order, false );
		if ( '' !== $erro ) {
			return $this->falhar( $erro );
		}

		// Reaproveita a cobrança se o cliente voltou e reenviou o mesmo pedido.
		if ( $order->get_meta( '_pagou_pix_id' ) && $order->get_meta( '_pagou_pix_code' ) && (int) $order->get_meta( '_pagou_expira_em' ) > time() + 60 ) {
			return $this->aguardar_pagamento( $order, __( 'Aguardando pagamento via Pix.', 'pagou-payments-for-woocommerce' ) );
		}

		$documento = Payer::documento( $order );
		$validade  = $this->validade();

		$corpo = array(
			'amount'           => round( (float) $order->get_total(), 2 ),
			'notification_url' => self::url_webhook(),
			'external_id'      => (string) $order->get_id(),
			/* translators: %s: número do pedido */
			'description'      => sprintf( __( 'Pedido %s', 'pagou-payments-for-woocommerce' ), $order->get_order_number() ),
			'expiration'       => $validade,
			'payer'            => array(
				'document' => $documento,
				'name'     => Payer::nome( $order, $documento ),
			),
		);

		$resposta = Plugin::criar_cobranca( $this->api(), '/v1/pix', $corpo, $order, 'reais' );

		if ( ! $resposta->ok() || empty( $resposta->body['id'] ) || empty( $resposta->body['payload']['data'] ) ) {
			return $this->falhar_api( $order, $resposta, __( 'Não foi possível gerar o Pix. Tente novamente.', 'pagou-payments-for-woocommerce' ) );
		}

		self::salvar_cobranca( $order, $resposta->body, time() + $validade );

		return $this->aguardar_pagamento( $order, __( 'Aguardando pagamento via Pix.', 'pagou-payments-for-woocommerce' ) );
	}

	/**
	 * Grava no pedido só o necessário: id, código copia e cola, validade e a resposta sem a
	 * imagem base64 (que pesava ~29 KB por pedido; o QR é desenhado a partir do código).
	 *
	 * @param WC_Order $order
	 * @param array    $cobranca
	 * @param int      $expira_em
	 */
	public static function salvar_cobranca( WC_Order $order, array $cobranca, $expira_em ) {
		unset( $cobranca['payload']['image'], $cobranca['payer'] );

		$order->update_meta_data( '_pagou_pix_id', sanitize_text_field( $cobranca['id'] ) );
		$order->update_meta_data( '_pagou_pix_code', (string) $cobranca['payload']['data'] );
		$order->update_meta_data( '_pagou_expira_em', (int) $expira_em );
		$order->update_meta_data( '_pagou_pix_response', $cobranca );
		$order->save();
	}

	/**
	 * Código copia e cola do pedido (pedidos novos e da 1.x).
	 *
	 * @param WC_Order $order
	 * @return string
	 */
	public static function codigo( WC_Order $order ) {
		$codigo = (string) $order->get_meta( '_pagou_pix_code' );

		if ( '' === $codigo ) {
			$resposta = $order->get_meta( '_pagou_pix_response' );
			$codigo   = is_array( $resposta ) && isset( $resposta['payload']['data'] ) ? (string) $resposta['payload']['data'] : '';
		}

		return $codigo;
	}

	/**
	 * Momento de expiração (UTC), calculado pela data do pedido em pedidos antigos.
	 *
	 * @param WC_Order $order
	 * @return int
	 */
	public static function expira_em( WC_Order $order ) {
		$expira = (int) $order->get_meta( '_pagou_expira_em' );

		if ( $expira > 0 ) {
			return $expira;
		}

		$config    = get_option( 'woocommerce_pagou_pix_settings', array() );
		$validade  = isset( $config['expiration'] ) ? (int) $config['expiration'] : 3600;
		$criado_em = $order->get_date_created();

		return $criado_em && $validade > 0 ? $criado_em->getTimestamp() + $validade : 0;
	}

	/**
	 * Tela de pagamento (página de pedido recebido e Minha conta).
	 *
	 * @param int $order_id
	 */
	public function exibir_pagamento( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( ! $order || $order->get_payment_method() !== $this->id ) {
			return;
		}

		Plugin::enfileirar_tela_pagamento( true );

		if ( $order->is_paid() ) {
			Plugin::template( 'pagamento-confirmado.php', array(
				'order'    => $order,
				'titulo'   => __( 'Pagamento via Pix confirmado', 'pagou-payments-for-woocommerce' ),
				'desconto' => Discounts::desconto_do_pedido( $order ),
			) );
			return;
		}

		if ( ! $order->has_status( array( 'on-hold', 'pending' ) ) ) {
			return;
		}

		$codigo = self::codigo( $order );

		if ( '' === $codigo ) {
			echo '<p>' . esc_html__( 'Não encontramos o código Pix deste pedido. Fale com a loja.', 'pagou-payments-for-woocommerce' ) . '</p>';
			return;
		}

		$resposta = $order->get_meta( '_pagou_pix_response' );

		Plugin::template( 'pix.php', array(
			'order'     => $order,
			'codigo'    => $codigo,
			// Pedidos da 1.x ainda guardam a imagem; os novos desenham o QR no navegador.
			'imagem'    => is_array( $resposta ) && ! empty( $resposta['payload']['image'] ) ? (string) $resposta['payload']['image'] : '',
			'expira_em' => self::expira_em( $order ),
			'desconto'  => Discounts::desconto_do_pedido( $order ),
		) );
	}

	protected function texto_aguardando() {
		return __( 'Recebemos seu pedido. Para concluir, faça o pagamento do Pix abaixo.', 'pagou-payments-for-woocommerce' );
	}

	/**
	 * Devolução Pix, parcial ou integral.
	 *
	 * @param int        $order_id
	 * @param float|null $amount
	 * @param string     $reason
	 * @return bool|WP_Error
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		$order  = wc_get_order( $order_id );
		$pix_id = $order ? (string) $order->get_meta( '_pagou_pix_id' ) : '';

		if ( '' === $pix_id ) {
			return new WP_Error( 'pagou_refund', __( 'Não encontramos o Pix deste pedido para estornar.', 'pagou-payments-for-woocommerce' ) );
		}

		$valor = null === $amount ? (float) $order->get_total() : (float) $amount;

		$resposta = $this->api()->delete(
			'/v1/pix/' . rawurlencode( $pix_id ) . '/refund',
			array(
				'amount'      => round( $valor, 2 ),
				'reason'      => 1,
				'description' => $reason ? $reason : __( 'Estorno pelo WooCommerce.', 'pagou-payments-for-woocommerce' ),
			)
		);

		if ( ! $resposta->ok() ) {
			/* translators: %s: erro da API */
			return new WP_Error( 'pagou_refund', sprintf( __( 'A Pagou recusou o estorno: %s', 'pagou-payments-for-woocommerce' ), $resposta->erro() ) );
		}

		$order->add_order_note(
			sprintf(
				/* translators: %s: valor */
				__( 'Estorno do Pix solicitado à Pagou: %s. A devolução é confirmada pela Pagou em seguida.', 'pagou-payments-for-woocommerce' ),
				wp_strip_all_tags( wc_price( $valor ) )
			)
		);

		return true;
	}
}
