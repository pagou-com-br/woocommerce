<?php
/**
 * Boleto bancário (com Pix embutido, quando a conta oferece).
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

class Boleto extends AbstractGateway {

	const ID = 'pagou_boleto';

	public function __construct() {
		$this->id                 = self::ID;
		$this->icon               = PAGOU_WC_URL . 'assets/img/boleto.svg';
		$this->has_fields         = false;
		$this->method_title       = 'Boleto';
		$this->method_description = __( 'Boleto bancário registrado pela Pagou, com Pix embutido quando disponível.', 'pagou-payments-for-woocommerce' );
		$this->supports           = array( 'products', 'refunds' );

		$this->iniciar_gateway();

		add_action( 'woocommerce_before_thankyou', array( $this, 'exibir_pagamento' ) );
		add_action( 'woocommerce_view_order', array( $this, 'exibir_pagamento' ), 5 );
	}

	protected function titulo_padrao() {
		return __( 'Boleto Bancário', 'pagou-payments-for-woocommerce' );
	}

	protected function descricao_padrao() {
		return __( 'Ao finalizar a compra, o boleto é gerado para pagamento em qualquer banco ou aplicativo.', 'pagou-payments-for-woocommerce' );
	}

	protected function campos_especificos() {
		return array(
			'grace_period' => array(
				'title'       => __( 'Prazo para vencimento (dias)', 'pagou-payments-for-woocommerce' ),
				'type'        => 'number',
				'description' => __( 'Dias até o vencimento do boleto, contados da compra.', 'pagou-payments-for-woocommerce' ),
				'default'     => '5',
				'desc_tip'    => true,
				'custom_attributes' => array( 'min' => 1 ),
			),
			'fine'         => array(
				'title'       => __( 'Multa por atraso (%)', 'pagou-payments-for-woocommerce' ),
				'type'        => 'number',
				'description' => __( 'Percentual de multa cobrado após o vencimento.', 'pagou-payments-for-woocommerce' ),
				'default'     => '0',
				'desc_tip'    => true,
				'custom_attributes' => array(
					'min'  => 0,
					'step' => '0.01',
				),
			),
			'interest'     => array(
				'title'       => __( 'Juros por atraso (% ao dia)', 'pagou-payments-for-woocommerce' ),
				'type'        => 'number',
				'description' => __( 'Percentual de juros por dia de atraso.', 'pagou-payments-for-woocommerce' ),
				'default'     => '0',
				'desc_tip'    => true,
				'custom_attributes' => array(
					'min'  => 0,
					'step' => '0.01',
				),
			),
		);
	}

	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return $this->falhar( __( 'Pedido não encontrado.', 'pagou-payments-for-woocommerce' ) );
		}

		$erro = $this->validar_pagador( $order, true );
		if ( '' !== $erro ) {
			return $this->falhar( $erro );
		}

		if ( $order->get_meta( '_pagou_boleto_id' ) ) {
			return $this->aguardar_pagamento( $order, __( 'Aguardando pagamento do boleto.', 'pagou-payments-for-woocommerce' ) );
		}

		$documento = Payer::documento( $order );
		$endereco  = Payer::endereco( $order );
		$prazo     = max( 1, (int) $this->get_option( 'grace_period', 5 ) );

		$corpo = array(
			'amount'           => round( (float) $order->get_total(), 2 ),
			'notification_url' => self::url_webhook(),
			'external_id'      => (string) $order->get_id(),
			/* translators: %s: número do pedido */
			'description'      => sprintf( __( 'Pedido %s', 'pagou-payments-for-woocommerce' ), $order->get_order_number() ),
			'grace_period'     => $prazo,
			'due_date'         => gmdate( 'Y-m-d', time() + ( $prazo * DAY_IN_SECONDS ) ),
			'fine'             => (float) str_replace( ',', '.', (string) $this->get_option( 'fine', 0 ) ),
			'interest'         => (float) str_replace( ',', '.', (string) $this->get_option( 'interest', 0 ) ),
			'payer'            => array(
				'document'     => $documento,
				'name'         => Payer::nome( $order, $documento ),
				'street'       => $endereco['street'],
				'number'       => $endereco['number'],
				'neighborhood' => $endereco['neighborhood'],
				'city'         => $endereco['city'],
				'state'        => $endereco['state'],
				'zip'          => $endereco['zip_code'],
			),
		);

		$resposta = Plugin::criar_cobranca( $this->api(), '/v1/charges', $corpo, $order, 'reais' );

		if ( ! $resposta->ok() || empty( $resposta->body['id'] ) ) {
			return $this->falhar_api( $order, $resposta, __( 'Não foi possível gerar o boleto. Tente novamente.', 'pagou-payments-for-woocommerce' ) );
		}

		self::salvar_cobranca( $order, $resposta->body );

		return $this->aguardar_pagamento( $order, __( 'Aguardando pagamento do boleto.', 'pagou-payments-for-woocommerce' ) );
	}

	/**
	 * @param WC_Order $order
	 * @param array    $cobranca
	 */
	public static function salvar_cobranca( WC_Order $order, array $cobranca ) {
		unset( $cobranca['payload']['image'], $cobranca['payer'] );

		if ( ! empty( $cobranca['id'] ) ) {
			$order->update_meta_data( '_pagou_boleto_id', sanitize_text_field( $cobranca['id'] ) );
		}

		$anterior = $order->get_meta( '_pagou_boleto_response' );
		$anterior = is_array( $anterior ) ? $anterior : array();

		// Mescla: o webhook charge.created traz os dados de registro sem os demais campos.
		$order->update_meta_data( '_pagou_boleto_response', array_replace_recursive( $anterior, $cobranca ) );
		$order->save();
	}

	/**
	 * Dados do boleto do pedido, consultando a Pagou quando o registro ainda não chegou.
	 *
	 * @param WC_Order $order
	 * @return array
	 */
	private function dados_boleto( WC_Order $order ) {
		$dados = $order->get_meta( '_pagou_boleto_response' );
		$dados = is_array( $dados ) ? $dados : array();

		if ( empty( $dados['payload']['bar_code'] ) && ! empty( $dados['id'] ) ) {
			$consulta = $this->api()->get( '/v1/charges/' . rawurlencode( $dados['id'] ) );

			if ( $consulta->ok() && ! empty( $consulta->body['payload']['bar_code'] ) ) {
				self::salvar_cobranca( $order, $consulta->body );
				$dados = $order->get_meta( '_pagou_boleto_response' );
			}
		}

		return $dados;
	}

	/**
	 * Formata a linha digitável em blocos.
	 *
	 * @param string $linha
	 * @return string
	 */
	public static function formatar_linha( $linha ) {
		$linha = preg_replace( '/\D/', '', (string) $linha );

		if ( 47 !== strlen( $linha ) ) {
			return $linha;
		}

		return substr( $linha, 0, 5 ) . '.' . substr( $linha, 5, 5 ) . ' ' .
			substr( $linha, 10, 5 ) . '.' . substr( $linha, 15, 6 ) . ' ' .
			substr( $linha, 21, 5 ) . '.' . substr( $linha, 26, 6 ) . ' ' .
			substr( $linha, 32, 1 ) . ' ' . substr( $linha, 33 );
	}

	/**
	 * @param int $order_id
	 */
	public function exibir_pagamento( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( ! $order || $order->get_payment_method() !== $this->id ) {
			return;
		}

		Plugin::enfileirar_tela_pagamento( false );

		if ( $order->is_paid() ) {
			Plugin::template( 'pagamento-confirmado.php', array(
				'order'    => $order,
				'titulo'   => __( 'Pagamento do boleto confirmado', 'pagou-payments-for-woocommerce' ),
				'desconto' => Discounts::desconto_do_pedido( $order ),
			) );
			return;
		}

		if ( ! $order->has_status( array( 'on-hold', 'pending' ) ) ) {
			return;
		}

		$dados = $this->dados_boleto( $order );

		if ( empty( $dados['payload']['bar_code'] ) ) {
			Plugin::template( 'boleto-processando.php', array( 'order' => $order ) );
			return;
		}

		Plugin::template( 'boleto.php', array(
			'order'      => $order,
			'boleto_id'  => (string) $dados['id'],
			'barras'     => (string) $dados['payload']['bar_code'],
			'linha'      => (string) $dados['payload']['line'],
			'linha_fmt'  => self::formatar_linha( $dados['payload']['line'] ),
			'pix'        => isset( $dados['payload']['data'] ) ? (string) $dados['payload']['data'] : '',
			'vencimento' => isset( $dados['due_date'] ) ? (string) $dados['due_date'] : ( isset( $dados['due_at'] ) ? (string) $dados['due_at'] : '' ),
			'banco'      => isset( $dados['payload']['bank'] ) && is_array( $dados['payload']['bank'] ) ? $dados['payload']['bank'] : array(),
			'desconto'   => Discounts::desconto_do_pedido( $order ),
		) );
	}

	protected function texto_aguardando() {
		return __( 'Recebemos seu pedido. Para concluir, pague o boleto abaixo.', 'pagou-payments-for-woocommerce' );
	}

	/**
	 * @param int        $order_id
	 * @param float|null $amount
	 * @param string     $reason
	 * @return bool|WP_Error
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		$order     = wc_get_order( $order_id );
		$boleto_id = $order ? (string) $order->get_meta( '_pagou_boleto_id' ) : '';

		if ( '' === $boleto_id ) {
			return new WP_Error( 'pagou_refund', __( 'Não encontramos o boleto deste pedido para estornar.', 'pagou-payments-for-woocommerce' ) );
		}

		$valor = null === $amount ? (float) $order->get_total() : (float) $amount;

		$resposta = $this->api()->delete(
			'/v1/charges/' . rawurlencode( $boleto_id ) . '/refund',
			array(
				'amount'      => round( $valor, 2 ),
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
				__( 'Estorno do boleto solicitado à Pagou: %s.', 'pagou-payments-for-woocommerce' ),
				wp_strip_all_tags( wc_price( $valor ) )
			)
		);

		return true;
	}
}
