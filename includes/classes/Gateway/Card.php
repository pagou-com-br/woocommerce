<?php
/**
 * Cartão de crédito, à vista ou parcelado, com cartão salvo.
 *
 * Número e CVV nunca chegam ao servidor da loja: o navegador gera o token com a biblioteca
 * da Pagou e o formulário envia apenas token, portador, validade e parcelas.
 *
 * O cartão não tem webhook: o resultado vem na resposta da criação e, quando incerto, é
 * resolvido pela conciliação, que consulta a Pagou.
 *
 * @package Pagou
 */

namespace Pagou\WooCommerce\Gateway;

use Pagou\WooCommerce\Api\Client;
use Pagou\WooCommerce\Api\Errors;
use Pagou\WooCommerce\Api\Response;
use Pagou\WooCommerce\Card\Installments;
use Pagou\WooCommerce\Checkout\Payer;
use Pagou\WooCommerce\Document;
use Pagou\WooCommerce\Logger;
use Pagou\WooCommerce\Money;
use Pagou\WooCommerce\Order\Lock;
use Pagou\WooCommerce\Order\Payments;
use Pagou\WooCommerce\Plugin;
use Pagou\WooCommerce\Settings;
use WC_Order;
use WC_Order_Item_Fee;
use WC_Payment_Token_CC;
use WC_Payment_Tokens;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Card extends AbstractGateway {

	const ID = 'pagou_cartao';

	/**
	 * Tentativas recusadas permitidas por pedido e por IP (proteção contra teste de cartões).
	 */
	const MAX_FALHAS_PEDIDO = 5;
	const MAX_FALHAS_IP     = 10;

	protected $aceita_desconto = false;

	public function __construct() {
		$this->id                 = self::ID;
		$this->icon               = PAGOU_WC_URL . 'assets/img/cartao.svg';
		$this->has_fields         = true;
		$this->method_title       = __( 'Cartão de crédito', 'pagou-payments-for-woocommerce' );
		$this->method_description = __( 'Cartão de crédito à vista ou parcelado pela Pagou, com opção de salvar o cartão.', 'pagou-payments-for-woocommerce' );
		$this->supports           = array( 'products', 'refunds' );

		$this->iniciar_gateway();

		if ( 'yes' === $this->get_option( 'saved_cards', 'yes' ) ) {
			$this->supports[] = 'tokenization';
			$this->supports[] = 'add_payment_method';
		}

		add_action( 'wp_enqueue_scripts', array( $this, 'scripts' ) );
		add_action( 'woocommerce_before_thankyou', array( $this, 'exibir_resultado' ) );
		add_action( 'woocommerce_view_order', array( $this, 'exibir_resultado' ), 5 );
	}

	protected function titulo_padrao() {
		return __( 'Cartão de crédito', 'pagou-payments-for-woocommerce' );
	}

	protected function descricao_padrao() {
		return __( 'Pague com cartão de crédito à vista ou parcelado.', 'pagou-payments-for-woocommerce' );
	}

	protected function campos_especificos() {
		$parcelas = array();
		for ( $n = 1; $n <= 12; $n++ ) {
			$parcelas[ $n ] = (string) $n;
		}

		return array(
			'parcelamento'      => array(
				'title'       => __( 'Parcelamento', 'pagou-payments-for-woocommerce' ),
				'type'        => 'title',
				'description' => __( 'A Pagou repassa o valor de cada parcela conforme o seu plano. Os juros configurados aqui são cobrados do comprador e entram no pedido como "Juros do parcelamento".', 'pagou-payments-for-woocommerce' ),
			),
			'installments_max'  => array(
				'title'   => __( 'Parcelas no máximo', 'pagou-payments-for-woocommerce' ),
				'type'    => 'select',
				'default' => '12',
				'options' => $parcelas,
			),
			'installments_min'  => array(
				'title'       => __( 'Valor mínimo da parcela (R$)', 'pagou-payments-for-woocommerce' ),
				'type'        => 'text',
				'default'     => '5',
				'description' => __( 'Parcelas abaixo desse valor não são oferecidas.', 'pagou-payments-for-woocommerce' ),
				'desc_tip'    => true,
			),
			'installments_free' => array(
				'title'       => __( 'Sem juros até', 'pagou-payments-for-woocommerce' ),
				'type'        => 'select',
				'default'     => '1',
				'options'     => $parcelas,
				'description' => __( 'Até quantas parcelas o comprador não paga juros. Use 1 para cobrar juros em todo parcelamento.', 'pagou-payments-for-woocommerce' ),
				'desc_tip'    => true,
			),
			'installments_rate' => array(
				'title'       => __( 'Juros ao mês (%)', 'pagou-payments-for-woocommerce' ),
				'type'        => 'text',
				'default'     => '0',
				'description' => __( 'Taxa mensal aplicada acima das parcelas sem juros (tabela Price). Use 0 para nunca cobrar juros.', 'pagou-payments-for-woocommerce' ),
				'desc_tip'    => true,
			),
			'opcoes_cartao'     => array(
				'title' => __( 'Opções', 'pagou-payments-for-woocommerce' ),
				'type'  => 'title',
			),
			'saved_cards'       => array(
				'title'   => __( 'Cartão salvo', 'pagou-payments-for-woocommerce' ),
				'label'   => __( 'Permitir que clientes com conta salvem o cartão para as próximas compras', 'pagou-payments-for-woocommerce' ),
				'type'    => 'checkbox',
				'default' => 'yes',
			),
			'chargeback_status' => array(
				'title'       => __( 'Pedido com chargeback', 'pagou-payments-for-woocommerce' ),
				'type'        => 'select',
				'default'     => 'on-hold',
				'options'     => array(
					'on-hold'  => __( 'Mudar para "Aguardando"', 'pagou-payments-for-woocommerce' ),
					'nota'     => __( 'Só registrar nota e avisar no painel', 'pagou-payments-for-woocommerce' ),
					'refunded' => __( 'Mudar para "Reembolsado"', 'pagou-payments-for-woocommerce' ),
				),
				'description' => __( 'O que fazer quando o comprador contesta a compra com o banco.', 'pagou-payments-for-woocommerce' ),
				'desc_tip'    => true,
			),
		);
	}

	/**
	 * @return Installments
	 */
	public function parcelamento() {
		return Installments::de_config( $this->settings );
	}

	/**
	 * Total, em centavos, sobre o qual as parcelas são calculadas no checkout.
	 *
	 * @return int
	 */
	private function total_checkout() {
		$order_id = absint( get_query_var( 'order-pay' ) );

		if ( $order_id ) {
			$order = wc_get_order( $order_id );
			return $order ? Money::centavos( $order->get_total() - self::juros_no_pedido( $order ) ) : 0;
		}

		return WC()->cart ? Money::centavos( WC()->cart->get_total( 'edit' ) ) : 0;
	}

	/**
	 * Configuração entregue ao JavaScript (clássico e blocos).
	 *
	 * @return array
	 */
	public function dados_js() {
		return array(
			'producao'  => ! Settings::sandbox(),
			'parcelas'  => $this->parcelamento()->para_js(),
			'moeda'     => array(
				'simbolo'  => html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ),
				'decimal'  => wc_get_price_decimal_separator(),
				'milhar'   => wc_get_price_thousand_separator(),
			),
			'textos'    => array(
				'indisponivel' => __( 'Pagamento com cartão indisponível no momento. Recarregue a página ou escolha outro meio de pagamento.', 'pagou-payments-for-woocommerce' ),
				'confira'      => __( 'Confira os dados do cartão e tente novamente.', 'pagou-payments-for-woocommerce' ),
				'numero'       => __( 'Número do cartão', 'pagou-payments-for-woocommerce' ),
				'nome'         => __( 'Nome impresso no cartão', 'pagou-payments-for-woocommerce' ),
				'validade'     => __( 'Validade (MM/AA)', 'pagou-payments-for-woocommerce' ),
				'cvv'          => __( 'CVV', 'pagou-payments-for-woocommerce' ),
				'parcelas'     => __( 'Parcelas', 'pagou-payments-for-woocommerce' ),
				/* translators: %s: valor */
				'avista'       => __( 'À vista, %s', 'pagou-payments-for-woocommerce' ),
				/* translators: 1: parcelas, 2: valor da parcela */
				'semJuros'     => __( '%1$dx de %2$s sem juros', 'pagou-payments-for-woocommerce' ),
				/* translators: 1: parcelas, 2: valor da parcela, 3: total */
				'comJuros'     => __( '%1$dx de %2$s (total %3$s)', 'pagou-payments-for-woocommerce' ),
			),
		);
	}

	/**
	 * Bibliotecas de tokenização e script do formulário clássico.
	 */
	public function scripts() {
		if ( 'yes' !== $this->enabled || ! ( is_checkout() || is_add_payment_method_page() ) ) {
			return;
		}

		self::registrar_tokenizacao();

		wp_enqueue_script(
			'pagou-wc-cartao',
			PAGOU_WC_URL . 'assets/js/cartao.js',
			array( 'jquery', 'pagou-wc-cartao-comum' ),
			Plugin::versao_asset( 'assets/js/cartao.js' ),
			true
		);
		wp_localize_script( 'pagou-wc-cartao', 'pagouCartao', $this->dados_js() );
		wp_enqueue_style( 'pagou-wc' );
	}

	/**
	 * Registra node-forge (empacotado) e a biblioteca da Pagou (remota: carrega a chave
	 * pública de criptografia, por isso não pode ser empacotada).
	 */
	public static function registrar_tokenizacao() {
		if ( ! wp_script_is( 'pagou-wc-forge', 'registered' ) ) {
			wp_register_script( 'pagou-wc-forge', PAGOU_WC_URL . 'assets/js/vendor/forge.min.js', array(), '1.0.0', true );
		}

		if ( ! wp_script_is( 'pagou-wc-tokenizacao', 'registered' ) ) {
			// phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- arquivo versionado pela própria Pagou.
			wp_register_script( 'pagou-wc-tokenizacao', 'https://js.cel.cash/checkout-v2.min.js', array( 'pagou-wc-forge' ), null, true );
		}

		if ( ! wp_script_is( 'pagou-wc-cartao-comum', 'registered' ) ) {
			wp_register_script( 'pagou-wc-cartao-comum', PAGOU_WC_URL . 'assets/js/cartao-comum.js', array( 'pagou-wc-tokenizacao' ), Plugin::versao_asset( 'assets/js/cartao-comum.js' ), true );
		}
	}

	/**
	 * Formulário no checkout clássico. Os campos de cartão não têm "name": não são enviados.
	 */
	public function payment_fields() {
		if ( $this->description ) {
			echo '<p>' . wp_kses_post( $this->description ) . '</p>';
		}

		$tokenizacao = $this->supports( 'tokenization' ) && is_checkout() && is_user_logged_in();

		if ( $tokenizacao ) {
			$this->tokenization_script();
			$this->saved_payment_methods();
		}

		$opcoes = $this->parcelamento()->opcoes( $this->total_checkout() );
		?>
		<fieldset id="wc-pagou_cartao-cc-form" class="pagou-cartao wc-credit-card-form wc-payment-form">
			<div class="pagou-cartao__campos">
				<p class="form-row form-row-wide">
					<label for="pagou-cartao-numero"><?php esc_html_e( 'Número do cartão', 'pagou-payments-for-woocommerce' ); ?> <span class="required">*</span></label>
					<input id="pagou-cartao-numero" class="input-text" type="text" inputmode="numeric" autocomplete="cc-number" placeholder="0000 0000 0000 0000" maxlength="23" data-pagou-cartao="numero" />
				</p>
				<p class="form-row form-row-wide">
					<label for="pagou-cartao-nome"><?php esc_html_e( 'Nome impresso no cartão', 'pagou-payments-for-woocommerce' ); ?> <span class="required">*</span></label>
					<input id="pagou-cartao-nome" class="input-text" type="text" autocomplete="cc-name" data-pagou-cartao="nome" />
				</p>
				<p class="form-row form-row-first">
					<label for="pagou-cartao-validade"><?php esc_html_e( 'Validade', 'pagou-payments-for-woocommerce' ); ?> <span class="required">*</span></label>
					<input id="pagou-cartao-validade" class="input-text" type="text" inputmode="numeric" autocomplete="cc-exp" placeholder="MM/AA" maxlength="7" data-pagou-cartao="validade" />
				</p>
				<p class="form-row form-row-last">
					<label for="pagou-cartao-cvv"><?php esc_html_e( 'CVV', 'pagou-payments-for-woocommerce' ); ?> <span class="required">*</span></label>
					<input id="pagou-cartao-cvv" class="input-text" type="password" inputmode="numeric" autocomplete="cc-csc" placeholder="123" maxlength="4" data-pagou-cartao="cvv" />
				</p>
			</div>
			<p class="form-row form-row-wide">
				<label for="pagou-cartao-parcelas"><?php esc_html_e( 'Parcelas', 'pagou-payments-for-woocommerce' ); ?></label>
				<select id="pagou-cartao-parcelas" name="pagou_installments">
					<?php foreach ( $opcoes as $opcao ) : ?>
						<option value="<?php echo esc_attr( $opcao['n'] ); ?>"><?php echo esc_html( Installments::rotulo( $opcao ) ); ?></option>
					<?php endforeach; ?>
				</select>
			</p>
			<input type="hidden" name="pagou_card_token" value="" />
			<input type="hidden" name="pagou_card_holder" value="" />
			<input type="hidden" name="pagou_card_expiry" value="" />
			<div class="clear"></div>
		</fieldset>
		<?php

		if ( $tokenizacao ) {
			$this->save_payment_method_checkbox();
		}
	}

	/**
	 * @param string $chave
	 * @return string
	 */
	private static function post( $chave ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- o checkout do WooCommerce (clássico e Store API) já verificou o nonce.
		return isset( $_POST[ $chave ] ) ? sanitize_text_field( wp_unslash( $_POST[ $chave ] ) ) : '';
	}

	/**
	 * Valida os dados do cartão novo recebidos do navegador.
	 *
	 * @return array|string Objeto card da API, ou mensagem de erro.
	 */
	private function cartao_do_formulario() {
		$token    = self::post( 'pagou_card_token' );
		$portador = trim( self::post( 'pagou_card_holder' ) );
		$validade = self::post( 'pagou_card_expiry' );

		if ( strlen( $token ) < 64 || strlen( $token ) > 16384 || ! preg_match( '#^[A-Za-z0-9+/]+={0,2}$#', $token ) ) {
			return __( 'Não foi possível validar os dados do cartão. Recarregue a página e tente novamente.', 'pagou-payments-for-woocommerce' );
		}

		if ( strlen( $portador ) < 2 ) {
			return __( 'Informe o nome impresso no cartão.', 'pagou-payments-for-woocommerce' );
		}

		if ( ! preg_match( '/^(\d{4})-(0[1-9]|1[0-2])$/', $validade, $m ) || gmdate( 'Y-m' ) > $validade ) {
			return __( 'A validade do cartão é inválida ou já passou.', 'pagou-payments-for-woocommerce' );
		}

		return array(
			'card_token' => $token,
			'holder'     => $portador,
			'expires_at' => $validade,
		);
	}

	/**
	 * Cartão salvo escolhido no checkout, se houver e pertencer ao usuário.
	 *
	 * @return WC_Payment_Token_CC|null
	 */
	private function token_escolhido() {
		$escolha = self::post( 'wc-' . $this->id . '-payment-token' );

		if ( '' === $escolha || 'new' === $escolha ) {
			return null;
		}

		$token = WC_Payment_Tokens::get( absint( $escolha ) );

		if ( ! $token || $token->get_user_id() !== get_current_user_id() || $token->get_gateway_id() !== $this->id ) {
			return null;
		}

		if ( $token->get_meta( 'pagou_ambiente' ) && $token->get_meta( 'pagou_ambiente' ) !== Settings::ambiente() ) {
			return null;
		}

		return $token;
	}

	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return $this->falhar( __( 'Pedido não encontrado.', 'pagou-payments-for-woocommerce' ) );
		}

		if ( $order->is_paid() ) {
			return array(
				'result'   => 'success',
				'redirect' => $this->get_return_url( $order ),
			);
		}

		$bloqueio = $this->bloqueado_por_tentativas( $order );
		if ( '' !== $bloqueio ) {
			return $this->falhar( $bloqueio );
		}

		$erro = $this->validar_pagador( $order, true, true );
		if ( '' !== $erro ) {
			return $this->falhar( $erro );
		}

		$token_salvo = $this->token_escolhido();
		$cartao      = null;

		if ( ! $token_salvo ) {
			$cartao = $this->cartao_do_formulario();
			if ( is_string( $cartao ) ) {
				return $this->falhar( $cartao );
			}
		}

		$resultado = Lock::com_trava(
			$order->get_id(),
			function () use ( $order_id, $token_salvo, $cartao ) {
				return $this->cobrar( wc_get_order( $order_id ), $token_salvo, $cartao );
			},
			'cartao'
		);

		if ( null === $resultado ) {
			return $this->falhar( __( 'Já estamos processando o pagamento deste pedido. Aguarde alguns segundos.', 'pagou-payments-for-woocommerce' ) );
		}

		return $resultado;
	}

	/**
	 * Cria a cobrança e trata o resultado.
	 *
	 * @param WC_Order                 $order
	 * @param WC_Payment_Token_CC|null $token_salvo
	 * @param array|null               $cartao
	 * @return array
	 */
	private function cobrar( WC_Order $order, $token_salvo, $cartao ) {
		$n      = max( 1, absint( self::post( 'pagou_installments' ) ) );
		$base   = Money::centavos( $order->get_total() - self::juros_no_pedido( $order ) );
		$opcoes = $this->parcelamento()->opcoes( $base );

		if ( ! isset( $opcoes[ $n ] ) ) {
			return $this->falhar( __( 'Essa quantidade de parcelas não está disponível para este valor.', 'pagou-payments-for-woocommerce' ) );
		}

		$opcao = $opcoes[ $n ];
		self::aplicar_juros( $order, $opcao['juros'] );

		$documento = Payer::documento( $order );

		$corpo = array(
			'value'        => Money::centavos( $order->get_total() ),
			'installments' => $n,
			'payday'       => gmdate( 'Y-m-d' ),
			/* translators: 1: número do pedido, 2: nome da loja */
			'description'  => mb_substr( sprintf( __( 'Pedido %1$s - %2$s', 'pagou-payments-for-woocommerce' ), $order->get_order_number(), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ), 0, 255 ),
			// O IP do cabeçalho configurado pelo lojista vale mais que o gravado pelo WooCommerce,
			// que aceita cabeçalhos de proxy que o próprio cliente pode forjar.
			'antifraud'    => array( 'ip' => '' !== Settings::ip_cliente() ? Settings::ip_cliente() : $order->get_customer_ip_address() ),
		);

		if ( $token_salvo ) {
			$corpo['customer_id'] = (string) $token_salvo->get_meta( 'pagou_customer_id' );
			$corpo['card_id']     = (string) $token_salvo->get_token();
		} else {
			$corpo['customer'] = array(
				'document'      => $documento,
				'document_type' => Document::tipo( $documento ),
				'name'          => Payer::nome( $order, $documento ),
				'email'         => $order->get_billing_email(),
				'phone'         => Payer::telefone( $order ),
				'address'       => Payer::endereco( $order ),
			);
			$corpo['card']     = $cartao;
		}

		// Nova tentativa = nova chave de idempotência, gravada antes do envio.
		$chave = Client::nova_chave_idempotencia();
		$order->update_meta_data( '_pagou_idempotencia', $chave );
		$order->update_meta_data( '_pagou_cartao_parcelas', $n );
		$order->update_meta_data( '_pagou_cartao_tentativa_em', time() );
		$order->save();

		$resposta = Plugin::criar_cobranca( $this->api(), '/v1/creditcard/charges', $corpo, $order, 'centavos', $chave );
		$order    = wc_get_order( $order->get_id() );

		if ( $resposta->incerto() ) {
			return $this->resultado_incerto( $order, $resposta );
		}

		if ( ! $resposta->ok() || empty( $resposta->body['id'] ) ) {
			$this->registrar_falha( $order );
			return $this->falhar_api( $order, $resposta, __( 'O pagamento não foi aprovado. Confira os dados ou use outro cartão.', 'pagou-payments-for-woocommerce' ) );
		}

		self::salvar_cobranca( $order, $resposta->body );

		$situacao = self::aplicar_status( $order, $resposta->body, __( 'Pagamento com cartão aprovado (Pagou).', 'pagou-payments-for-woocommerce' ) );

		if ( 'falhou' === $situacao ) {
			$this->registrar_falha( $order );
			$motivo = isset( $resposta->body['failure_reason'] ) ? (string) $resposta->body['failure_reason'] : '';
			$order->add_order_note(
				sprintf(
					/* translators: %s: motivo */
					__( 'Pagou: cartão recusado (%s).', 'pagou-payments-for-woocommerce' ),
					'' !== $motivo ? $motivo : __( 'sem motivo informado', 'pagou-payments-for-woocommerce' )
				)
			);
			return $this->falhar( __( 'O pagamento não foi aprovado pelo banco emissor. Confira os dados ou use outro cartão.', 'pagou-payments-for-woocommerce' ) );
		}

		if ( 'cancelado' === $situacao ) {
			return $this->falhar( __( 'O pagamento foi cancelado. Tente novamente.', 'pagou-payments-for-woocommerce' ) );
		}

		if ( ! $token_salvo && 'pago' === $situacao && self::post( 'wc-' . $this->id . '-new-payment-method' ) ) {
			$this->salvar_cartao( $order, $resposta->body );
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
	 * Timeout, 5xx ou idempotência em andamento: a cobrança pode ter acontecido.
	 * Nunca recria: o pedido aguarda e a conciliação descobre o resultado.
	 *
	 * @param WC_Order $order
	 * @param Response $resposta
	 * @return array
	 */
	private function resultado_incerto( WC_Order $order, Response $resposta ) {
		if ( ! empty( $resposta->body['id'] ) ) {
			self::salvar_cobranca( $order, $resposta->body );
		}

		$order->update_meta_data( '_pagou_cartao_incerto', time() );
		$order->update_status(
			'on-hold',
			sprintf(
				/* translators: %s: erro */
				__( 'Pagou: resultado do cartão incerto (%s). O pedido será conferido automaticamente na Pagou; não cobre de novo antes disso.', 'pagou-payments-for-woocommerce' ),
				$resposta->erro()
			)
		);

		if ( function_exists( 'WC' ) && WC()->cart ) {
			WC()->cart->empty_cart();
		}

		return array(
			'result'   => 'success',
			'redirect' => $this->get_return_url( $order ),
		);
	}

	/**
	 * Aplica ao pedido o status de uma cobrança de cartão.
	 *
	 * @param WC_Order $order
	 * @param array    $cobranca
	 * @param string   $origem
	 * @return string pago, aguardando, falhou, cancelado, estornado, chargeback ou desconhecido.
	 */
	public static function aplicar_status( WC_Order $order, array $cobranca, $origem ) {
		$status = isset( $cobranca['status'] ) ? (string) $cobranca['status'] : '';
		$id     = isset( $cobranca['id'] ) ? (string) $cobranca['id'] : '';

		$order->update_meta_data( '_pagou_cartao_status', sanitize_key( $status ) );
		$order->save();

		switch ( $status ) {
			case 'captured':
			case 'settled':
				Payments::confirmar( $order, null, $origem, 'creditcard.captured:' . $id, $id );
				return 'pago';

			case 'authorized':
				// Autorizada sem captura: captura uma única vez (idempotente pela chave).
				$captura = ( new Client() )->put( '/v1/creditcard/charges/' . rawurlencode( $id ) . '/capture', null, 'capture-' . $id );
				if ( $captura->ok() && isset( $captura->body['status'] ) && in_array( $captura->body['status'], array( 'captured', 'settled' ), true ) ) {
					self::salvar_cobranca( $order, $captura->body );
					Payments::confirmar( $order, null, $origem, 'creditcard.captured:' . $id, $id );
					return 'pago';
				}
				self::aguardar( $order, __( 'Pagou: cartão autorizado, aguardando a captura.', 'pagou-payments-for-woocommerce' ) );
				return 'aguardando';

			case 'pending':
			case 'unknown':
				self::aguardar( $order, __( 'Pagou: pagamento com cartão em análise.', 'pagou-payments-for-woocommerce' ) );
				return 'aguardando';

			case 'failed':
				return 'falhou';

			case 'canceled':
				return 'cancelado';

			case 'reversed':
				Payments::estornado( $order, null, __( 'Estorno do cartão confirmado pela Pagou.', 'pagou-payments-for-woocommerce' ), 'creditcard.reversed:' . $id );
				return 'estornado';

			case 'charged_back':
				self::chargeback( $order, $id );
				return 'chargeback';
		}

		return 'desconhecido';
	}

	/**
	 * @param WC_Order $order
	 * @param string   $nota
	 */
	private static function aguardar( WC_Order $order, $nota ) {
		if ( ! $order->is_paid() && ! $order->has_status( 'on-hold' ) ) {
			$order->update_status( 'on-hold', $nota );
		}
	}

	/**
	 * Registra um chargeback (uma única vez por cobrança).
	 *
	 * @param WC_Order $order
	 * @param string   $cobranca_id
	 */
	public static function chargeback( WC_Order $order, $cobranca_id ) {
		$chave = 'creditcard.charged_back:' . $cobranca_id;

		if ( Payments::processado( $order, $chave ) ) {
			return;
		}

		$config = get_option( 'woocommerce_' . self::ID . '_settings', array() );
		$acao   = isset( $config['chargeback_status'] ) ? $config['chargeback_status'] : 'on-hold';
		$nota   = __( 'Pagou: o comprador contestou esta compra com o banco (chargeback). Envie a defesa pelo painel da Pagou dentro do prazo.', 'pagou-payments-for-woocommerce' );

		if ( 'nota' === $acao ) {
			$order->add_order_note( $nota );
		} else {
			$order->update_status( 'refunded' === $acao ? 'refunded' : 'on-hold', $nota );
		}

		Payments::registrar( $order, $chave );

		Plugin::avisar_admin(
			'chargeback_' . $order->get_id(),
			sprintf(
				/* translators: 1: link do pedido, 2: número do pedido */
				__( 'Chargeback no <a href="%1$s">pedido #%2$s</a>. Envie a defesa pelo painel da Pagou dentro do prazo.', 'pagou-payments-for-woocommerce' ),
				esc_url( $order->get_edit_order_url() ),
				esc_html( $order->get_order_number() )
			),
			'warning'
		);
	}

	/**
	 * @param WC_Order $order
	 * @param array    $cobranca
	 */
	public static function salvar_cobranca( WC_Order $order, array $cobranca ) {
		if ( ! empty( $cobranca['id'] ) ) {
			$order->update_meta_data( '_pagou_cartao_id', sanitize_text_field( $cobranca['id'] ) );
		}
		if ( isset( $cobranca['status'] ) ) {
			$order->update_meta_data( '_pagou_cartao_status', sanitize_key( $cobranca['status'] ) );
		}

		unset( $cobranca['customer'], $cobranca['card'] );
		$order->update_meta_data( '_pagou_cartao_response', $cobranca );
		$order->save();
	}

	/**
	 * Soma dos juros de parcelamento já lançados no pedido (em reais).
	 *
	 * @param WC_Order $order
	 * @return float
	 */
	public static function juros_no_pedido( WC_Order $order ) {
		$total = 0.0;

		foreach ( $order->get_items( 'fee' ) as $item ) {
			if ( 'yes' === $item->get_meta( '_pagou_juros' ) ) {
				$total += (float) $item->get_total();
			}
		}

		return $total;
	}

	/**
	 * Substitui os juros do pedido pelos da opção escolhida.
	 *
	 * @param WC_Order $order
	 * @param int      $juros_centavos
	 */
	public static function aplicar_juros( WC_Order $order, $juros_centavos ) {
		foreach ( $order->get_items( 'fee' ) as $item_id => $item ) {
			if ( 'yes' === $item->get_meta( '_pagou_juros' ) ) {
				$order->remove_item( $item_id );
			}
		}

		if ( $juros_centavos > 0 ) {
			$taxa = new WC_Order_Item_Fee();
			$taxa->set_name( __( 'Juros do parcelamento', 'pagou-payments-for-woocommerce' ) );
			$taxa->set_amount( Money::reais( $juros_centavos ) );
			$taxa->set_total( Money::reais( $juros_centavos ) );
			$taxa->set_tax_status( 'none' );
			$taxa->add_meta_data( '_pagou_juros', 'yes', true );
			$order->add_item( $taxa );
		}

		$order->calculate_totals( false );
		$order->save();
	}

	/**
	 * @param WC_Order $order
	 * @return string Mensagem de bloqueio, ou ''.
	 */
	private function bloqueado_por_tentativas( WC_Order $order ) {
		if ( (int) $order->get_meta( '_pagou_cartao_falhas' ) >= self::MAX_FALHAS_PEDIDO ) {
			return __( 'Muitas tentativas recusadas neste pedido. Use outro meio de pagamento ou fale com a loja.', 'pagou-payments-for-woocommerce' );
		}

		$ip = Settings::ip_cliente();
		if ( '' !== $ip && (int) get_transient( 'pagou_wc_falhas_' . md5( $ip ) ) >= self::MAX_FALHAS_IP ) {
			return __( 'Muitas tentativas recusadas. Aguarde uma hora ou use outro meio de pagamento.', 'pagou-payments-for-woocommerce' );
		}

		return '';
	}

	/**
	 * @param WC_Order $order
	 */
	private function registrar_falha( WC_Order $order ) {
		$order->update_meta_data( '_pagou_cartao_falhas', (int) $order->get_meta( '_pagou_cartao_falhas' ) + 1 );
		$order->save();

		$ip = Settings::ip_cliente();
		if ( '' !== $ip ) {
			$chave = 'pagou_wc_falhas_' . md5( $ip );
			set_transient( $chave, (int) get_transient( $chave ) + 1, HOUR_IN_SECONDS );
		}
	}

	/**
	 * Guarda o cartão aprovado como método de pagamento do cliente.
	 *
	 * @param WC_Order $order
	 * @param array    $cobranca
	 */
	private function salvar_cartao( WC_Order $order, array $cobranca ) {
		$user_id = $order->get_user_id();

		if ( ! $user_id || empty( $cobranca['card_id'] ) || empty( $cobranca['customer_id'] ) ) {
			return;
		}

		$this->criar_token( $user_id, (string) $cobranca['customer_id'], (string) $cobranca['card_id'] );
	}

	/**
	 * Cria o token do WooCommerce a partir de um cartão cadastrado na Pagou.
	 *
	 * @param int    $user_id
	 * @param string $customer_id
	 * @param string $card_id
	 * @return WC_Payment_Token_CC|null
	 */
	private function criar_token( $user_id, $customer_id, $card_id ) {
		$dados = $this->api()->get( '/v1/creditcard/cards/' . rawurlencode( $card_id ) );

		if ( ! $dados->ok() ) {
			Logger::aviso( 'Cartão aprovado, mas não foi possível consultá-lo para salvar.', array( 'card_id' => $card_id ) );
			return null;
		}

		$validade = isset( $dados->body['expires_at'] ) ? (string) $dados->body['expires_at'] : '';
		$partes   = explode( '-', $validade );
		$bandeira = isset( $dados->body['brand']['name'] ) ? (string) $dados->body['brand']['name'] : ( isset( $dados->body['brand'] ) && is_string( $dados->body['brand'] ) ? $dados->body['brand'] : 'card' );

		$token = new WC_Payment_Token_CC();
		$token->set_token( $card_id );
		$token->set_gateway_id( $this->id );
		$token->set_user_id( $user_id );
		$token->set_last4( isset( $dados->body['last4'] ) ? (string) $dados->body['last4'] : '0000' );
		$token->set_expiry_year( isset( $partes[0] ) ? $partes[0] : gmdate( 'Y' ) );
		$token->set_expiry_month( isset( $partes[1] ) ? $partes[1] : '12' );
		$token->set_card_type( sanitize_title( $bandeira ) );
		$token->add_meta_data( 'pagou_customer_id', $customer_id, true );
		$token->add_meta_data( 'pagou_ambiente', Settings::ambiente(), true );
		$token->save();

		update_user_meta( $user_id, '_pagou_customer_id_' . Settings::ambiente(), $customer_id );

		return $token;
	}

	/**
	 * Minha conta > Métodos de pagamento > Adicionar.
	 *
	 * @return array
	 */
	public function add_payment_method() {
		$user_id = get_current_user_id();
		$cartao  = $this->cartao_do_formulario();

		if ( is_string( $cartao ) ) {
			wc_add_notice( $cartao, 'error' );
			return array( 'result' => 'failure' );
		}

		$customer_id = (string) get_user_meta( $user_id, '_pagou_customer_id_' . Settings::ambiente(), true );

		if ( '' === $customer_id ) {
			$customer_id = $this->criar_pagador_do_usuario( $user_id );
			if ( '' === $customer_id ) {
				wc_add_notice( __( 'Para salvar um cartão, complete seus dados de cobrança (com CPF/CNPJ, telefone e endereço) ou salve o cartão durante uma compra.', 'pagou-payments-for-woocommerce' ), 'error' );
				return array( 'result' => 'failure' );
			}
		}

		$resposta = $this->api()->post(
			'/v1/creditcard/cards',
			array_merge( array( 'customer_id' => $customer_id ), $cartao ),
			Client::nova_chave_idempotencia()
		);

		if ( ! $resposta->ok() || empty( $resposta->body['id'] ) ) {
			wc_add_notice( Errors::para_cliente( $resposta, __( 'Não foi possível salvar o cartão.', 'pagou-payments-for-woocommerce' ) ), 'error' );
			return array( 'result' => 'failure' );
		}

		$this->criar_token( $user_id, $customer_id, (string) $resposta->body['id'] );

		return array(
			'result'   => 'success',
			'redirect' => wc_get_endpoint_url( 'payment-methods' ),
		);
	}

	/**
	 * Cadastra o usuário como pagador na Pagou a partir dos dados de cobrança da conta.
	 *
	 * @param int $user_id
	 * @return string customer_id ou ''.
	 */
	private function criar_pagador_do_usuario( $user_id ) {
		$cliente   = new \WC_Customer( $user_id );
		$documento = '';

		foreach ( array( '_wc_other/pagou/document', 'billing_pagou_document', 'billing_cpf', 'billing_cnpj' ) as $meta ) {
			$valor = (string) get_user_meta( $user_id, $meta, true );
			if ( Document::valido( $valor ) ) {
				$documento = Document::digitos( $valor );
				break;
			}
		}

		$telefone = Document::digitos( $cliente->get_billing_phone() );
		$numero   = (string) get_user_meta( $user_id, '_wc_billing/pagou/number', true );
		$numero   = '' !== $numero ? $numero : (string) get_user_meta( $user_id, 'billing_number', true );
		$bairro   = (string) get_user_meta( $user_id, '_wc_billing/pagou/neighborhood', true );
		$bairro   = '' !== $bairro ? $bairro : (string) get_user_meta( $user_id, 'billing_neighborhood', true );
		$nome     = trim( $cliente->get_billing_first_name() . ' ' . $cliente->get_billing_last_name() );

		if ( '' === $documento || strlen( $telefone ) < 10 || '' === $bairro || strlen( $nome ) < 3 ) {
			return '';
		}

		$resposta = $this->api()->post(
			'/v1/creditcard/customers',
			array(
				'document'      => $documento,
				'document_type' => Document::tipo( $documento ),
				'name'          => $nome,
				'email'         => $cliente->get_billing_email() ? $cliente->get_billing_email() : $cliente->get_email(),
				'phone'         => $telefone,
				'address'       => array(
					'zip_code'     => Document::digitos( $cliente->get_billing_postcode() ),
					'street'       => $cliente->get_billing_address_1(),
					'number'       => '' !== $numero ? $numero : 'S/N',
					'neighborhood' => $bairro,
					'city'         => $cliente->get_billing_city(),
					'state'        => $cliente->get_billing_state(),
				),
			),
			Client::nova_chave_idempotencia()
		);

		return $resposta->ok() && ! empty( $resposta->body['id'] ) ? (string) $resposta->body['id'] : '';
	}

	/**
	 * Estorno: a API do cartão só aceita estorno integral.
	 *
	 * @param int        $order_id
	 * @param float|null $amount
	 * @param string     $reason
	 * @return bool|WP_Error
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		$order       = wc_get_order( $order_id );
		$cobranca_id = $order ? (string) $order->get_meta( '_pagou_cartao_id' ) : '';

		if ( '' === $cobranca_id ) {
			return new WP_Error( 'pagou_refund', __( 'Não encontramos a cobrança de cartão deste pedido.', 'pagou-payments-for-woocommerce' ) );
		}

		$restante = (float) $order->get_total() - (float) $order->get_total_refunded() + ( null === $amount ? 0 : (float) $amount );

		if ( null !== $amount && abs( (float) $amount - $restante ) > 0.01 ) {
			return new WP_Error( 'pagou_refund', __( 'O cartão aceita apenas estorno do valor total. Para devolver parte do valor, combine com o cliente outra forma (Pix, por exemplo).', 'pagou-payments-for-woocommerce' ) );
		}

		$resposta = $this->api()->put(
			'/v1/creditcard/charges/' . rawurlencode( $cobranca_id ) . '/reverse',
			array( 'reason' => mb_substr( $reason ? $reason : __( 'Estorno pelo WooCommerce.', 'pagou-payments-for-woocommerce' ), 0, 255 ) ),
			Client::nova_chave_idempotencia()
		);

		if ( ! $resposta->ok() ) {
			/* translators: %s: erro da API */
			return new WP_Error( 'pagou_refund', sprintf( __( 'A Pagou recusou o estorno: %s', 'pagou-payments-for-woocommerce' ), $resposta->erro() ) );
		}

		self::salvar_cobranca( $order, $resposta->body );
		$order->add_order_note( __( 'Estorno integral do cartão solicitado à Pagou.', 'pagou-payments-for-woocommerce' ) );

		return true;
	}

	/**
	 * Resumo do pagamento na página do pedido.
	 *
	 * @param int $order_id
	 */
	public function exibir_resultado( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( ! $order || $order->get_payment_method() !== $this->id ) {
			return;
		}

		Plugin::enfileirar_tela_pagamento( false );

		if ( $order->is_paid() ) {
			Plugin::template( 'pagamento-confirmado.php', array(
				'order'    => $order,
				'titulo'   => __( 'Pagamento com cartão aprovado', 'pagou-payments-for-woocommerce' ),
				'desconto' => 0,
			) );
			return;
		}

		if ( $order->has_status( 'on-hold' ) ) {
			Plugin::template( 'cartao-analise.php', array( 'order' => $order ) );
		}
	}

	protected function texto_aguardando() {
		return __( 'Recebemos seu pedido. Estamos confirmando o pagamento com o cartão.', 'pagou-payments-for-woocommerce' );
	}
}
