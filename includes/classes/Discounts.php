<?php
/**
 * Desconto por método de pagamento.
 *
 * Permite ao lojista oferecer um desconto (percentual ou valor fixo) para quem paga
 * com PIX ou com Boleto. O desconto entra no carrinho como uma taxa negativa, então o
 * total do pedido (e, por consequência, o valor enviado à API da Pagou) já sai correto.
 *
 * @package Pagou
 */

namespace Pagou\WooCommerce;

use WC_Order;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Discounts {

	/**
	 * Gateways que suportam desconto.
	 *
	 * @var string[]
	 */
	private static $gateways = array( 'pagou_pix', 'pagou_boleto' );

	public static function init() {
		add_action( 'woocommerce_cart_calculate_fees', array( __CLASS__, 'aplicar_desconto' ), 20 );
		add_filter( 'woocommerce_gateway_title', array( __CLASS__, 'titulo_com_desconto' ), 10, 2 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enfileirar_script_checkout' ), 20 );

		// Checkout em blocos: recebe o método escolhido e recalcula o carrinho.
		if ( did_action( 'woocommerce_blocks_loaded' ) ) {
			self::registrar_callback_blocos();
		} else {
			add_action( 'woocommerce_blocks_loaded', array( __CLASS__, 'registrar_callback_blocos' ) );
		}

		// Rede de proteção: o pedido nunca pode sair com desconto de um meio de
		// pagamento diferente daquele que foi realmente usado.
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'validar_desconto_no_pedido' ), 99 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( __CLASS__, 'validar_desconto_no_pedido' ), 99 );
	}

	/**
	 * Remove do pedido qualquer desconto que não corresponda ao meio de pagamento usado.
	 *
	 * @param WC_Order $order
	 */
	public static function validar_desconto_no_pedido( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$metodo_do_pedido = $order->get_payment_method();
		$removeu          = false;

		foreach ( $order->get_items( 'fee' ) as $item_id => $item ) {
			$gateway_do_desconto = self::gateway_pelo_nome_da_taxa( $item->get_name() );

			if ( ! $gateway_do_desconto ) {
				continue; // Taxa que não é nossa.
			}

			if ( $gateway_do_desconto !== $metodo_do_pedido ) {
				$order->remove_item( $item_id );
				$removeu = true;
			}
		}

		if ( $removeu ) {
			$order->calculate_totals( false );
			Logger::aviso(
				'Desconto removido do pedido: o meio de pagamento usado não corresponde ao desconto aplicado.',
				array( 'pedido' => $order->get_id(), 'metodo' => $metodo_do_pedido )
			);
		}
	}

	/**
	 * Valor de desconto Pagou já aplicado a um pedido (sempre positivo).
	 *
	 * @param WC_Order $order
	 * @return float
	 */
	public static function desconto_do_pedido( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return 0.0;
		}

		$total = 0.0;

		foreach ( $order->get_items( 'fee' ) as $item ) {
			if ( self::gateway_pelo_nome_da_taxa( $item->get_name() ) ) {
				$total += abs( (float) $item->get_total() );
			}
		}

		return $total;
	}

	/**
	 * Descobre a qual gateway pertence uma linha de desconto, pelo nome gerado.
	 *
	 * @param string $nome_taxa
	 * @return string|false ID do gateway ou false se a taxa não for do plugin.
	 */
	private static function gateway_pelo_nome_da_taxa( $nome_taxa ) {
		$gateways = function_exists( 'WC' ) && WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array();

		foreach ( self::$gateways as $gateway_id ) {
			$titulo = isset( $gateways[ $gateway_id ] ) ? $gateways[ $gateway_id ]->get_method_title() : $gateway_id;
			$nome_esperado = sprintf(
				/* translators: %s: nome do meio de pagamento */
				__( 'Desconto %s', 'pagou-payments-for-woocommerce' ),
				$titulo
			);

			if ( $nome_taxa === $nome_esperado ) {
				return $gateway_id;
			}
		}

		return false;
	}

	/**
	 * Configuração de desconto de um gateway.
	 *
	 * @param string $gateway_id
	 * @return array{tipo:string, valor:float}
	 */
	public static function config( $gateway_id ) {
		$settings = get_option( 'woocommerce_' . $gateway_id . '_settings', array() );

		$tipo  = isset( $settings['discount_type'] ) ? $settings['discount_type'] : 'percentage';
		$valor = isset( $settings['discount_amount'] ) ? (float) str_replace( ',', '.', $settings['discount_amount'] ) : 0.0;

		// Percentual acima de 100 não faz sentido; valor negativo também não.
		if ( 'percentage' === $tipo ) {
			$valor = min( max( $valor, 0 ), 100 );
		} else {
			$valor = max( $valor, 0 );
		}

		return array(
			'tipo'  => $tipo,
			'valor' => $valor,
		);
	}

	/**
	 * Calcula o valor do desconto para um determinado montante base.
	 *
	 * @param string $gateway_id
	 * @param float  $base Valor sobre o qual o desconto incide.
	 * @return float Valor do desconto (sempre positivo).
	 */
	public static function calcular( $gateway_id, $base ) {
		$config = self::config( $gateway_id );

		if ( $config['valor'] <= 0 || $base <= 0 ) {
			return 0.0;
		}

		$desconto = 'percentage' === $config['tipo']
			? ( $base * $config['valor'] / 100 )
			: $config['valor'];

		// O desconto nunca pode ser maior que a base (não existe total negativo).
		$desconto = min( $desconto, $base );

		return round( $desconto, wc_get_price_decimals() );
	}

	/**
	 * Rótulo legível do desconto, ex.: "5% de desconto" ou "R$ 10,00 de desconto".
	 *
	 * @param string $gateway_id
	 * @return string Vazio se não houver desconto configurado.
	 */
	public static function rotulo( $gateway_id ) {
		$config = self::config( $gateway_id );

		if ( $config['valor'] <= 0 ) {
			return '';
		}

		if ( 'percentage' === $config['tipo'] ) {
			/* translators: %s: percentual de desconto */
			return sprintf( __( '%s%% de desconto', 'pagou-payments-for-woocommerce' ), self::formatar_percentual( $config['valor'] ) );
		}

		/* translators: %s: valor do desconto já formatado como moeda */
		return sprintf( __( '%s de desconto', 'pagou-payments-for-woocommerce' ), wp_strip_all_tags( wc_price( $config['valor'] ) ) );
	}

	/**
	 * Formata o percentual para exibição: inteiro sai sem casas decimais (10 vira "10"),
	 * fracionário sai com as casas necessárias (7,5 vira "7,5").
	 *
	 * Não use rtrim de zeros aqui: em um número inteiro isso comeria o zero do próprio
	 * valor e transformaria 10 em 1.
	 *
	 * @param float $valor
	 * @return string
	 */
	public static function formatar_percentual( $valor ) {
		$valor = (float) $valor;

		if ( floor( $valor ) === $valor ) {
			return number_format_i18n( $valor, 0 );
		}

		$separador = function_exists( 'wc_get_price_decimal_separator' ) ? wc_get_price_decimal_separator() : ',';
		$texto     = number_format_i18n( $valor, 2 );

		// Apenas o zero decimal supérfluo sai (7,50 vira 7,5), nunca o dígito inteiro.
		if ( false !== strpos( $texto, $separador ) ) {
			$texto = rtrim( $texto, '0' );
			$texto = rtrim( $texto, $separador );
		}

		return $texto;
	}

	/**
	 * Método de pagamento escolhido no momento.
	 *
	 * No checkout clássico vem da sessão do WooCommerce. No checkout em blocos, vem da
	 * chave que o callback de extensão grava na sessão.
	 *
	 * @return string
	 */
	public static function metodo_escolhido() {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return '';
		}

		$classico = (string) WC()->session->get( 'chosen_payment_method' );
		$blocos   = (string) WC()->session->get( 'pagou_wc_metodo_blocos' );

		// O método efetivamente escolhido no checkout manda. A chave gravada pelo
		// checkout em blocos só vale enquanto não houver um método definido pelo
		// fluxo padrão, senão seria possível manter o desconto de um meio de
		// pagamento e finalizar a compra por outro.
		if ( '' !== $classico ) {
			return $classico;
		}

		return $blocos;
	}

	/**
	 * Aplica o desconto como taxa negativa no carrinho.
	 *
	 * @param WC_Cart $cart
	 */
	public static function aplicar_desconto( $cart ) {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}

		$gateway_id = self::metodo_escolhido();

		if ( ! in_array( $gateway_id, self::$gateways, true ) ) {
			return;
		}

		// O desconto incide sobre os produtos, não sobre o frete.
		$base     = (float) $cart->get_subtotal();
		$desconto = self::calcular( $gateway_id, $base );

		if ( $desconto <= 0 ) {
			return;
		}

		$gateways = WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array();
		$nome_gateway = isset( $gateways[ $gateway_id ] ) ? $gateways[ $gateway_id ]->get_method_title() : $gateway_id;

		$cart->add_fee(
			sprintf(
				/* translators: %s: nome do meio de pagamento */
				__( 'Desconto %s', 'pagou-payments-for-woocommerce' ),
				$nome_gateway
			),
			-$desconto,
			false
		);
	}

	/**
	 * Acrescenta o rótulo do desconto ao título do método no checkout.
	 *
	 * @param string $titulo
	 * @param string $gateway_id
	 * @return string
	 */
	public static function titulo_com_desconto( $titulo, $gateway_id ) {
		if ( ! in_array( $gateway_id, self::$gateways, true ) || is_admin() ) {
			return $titulo;
		}

		$settings = get_option( 'woocommerce_' . $gateway_id . '_settings', array() );
		if ( isset( $settings['discount_show_title'] ) && 'yes' !== $settings['discount_show_title'] ) {
			return $titulo;
		}

		$rotulo = self::rotulo( $gateway_id );

		return $rotulo ? $titulo . ' (' . $rotulo . ')' : $titulo;
	}

	/**
	 * No checkout clássico, o WooCommerce não recalcula os totais ao trocar o método de
	 * pagamento. Este script dispara a atualização.
	 */
	public static function enfileirar_script_checkout() {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return;
		}

		if ( ! self::algum_desconto_ativo() ) {
			return;
		}

		// No checkout em blocos o script depende da API wc.blocksCheckout, que precisa
		// estar carregada antes; no clássico, basta o jQuery.
		$dependencias = array( 'jquery' );
		if ( function_exists( 'has_block' ) && has_block( 'woocommerce/checkout' ) && wp_script_is( 'wc-blocks-checkout', 'registered' ) ) {
			$dependencias[] = 'wc-blocks-checkout';
		}

		wp_enqueue_script(
			'pagou-wc-desconto',
			PAGOU_WC_URL . 'assets/js/desconto.js',
			$dependencias,
			Plugin::versao_asset( 'assets/js/desconto.js' ),
			true
		);
	}

	/**
	 * @return bool True se algum gateway Pagou tem desconto configurado.
	 */
	public static function algum_desconto_ativo() {
		foreach ( self::$gateways as $gateway_id ) {
			$config = self::config( $gateway_id );
			if ( $config['valor'] > 0 ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Registra o callback do Store API para o checkout em blocos informar o método
	 * escolhido e forçar o recálculo dos totais.
	 */
	public static function registrar_callback_blocos() {
		if ( ! class_exists( '\Automattic\WooCommerce\StoreApi\StoreApi' ) ) {
			return;
		}

		try {
			$extend = \Automattic\WooCommerce\StoreApi\StoreApi::container()->get(
				\Automattic\WooCommerce\StoreApi\Schemas\ExtendSchema::class
			);
		} catch ( \Exception $e ) {
			return;
		}

		if ( ! $extend || ! method_exists( $extend, 'register_update_callback' ) ) {
			return;
		}

		$extend->register_update_callback(
			array(
				'namespace' => 'pagou-desconto',
				'callback'  => function ( $data ) {
					$metodo = isset( $data['metodo'] ) ? sanitize_text_field( $data['metodo'] ) : '';

					if ( WC()->session ) {
						WC()->session->set(
							'pagou_wc_metodo_blocos',
							in_array( $metodo, self::$gateways, true ) ? $metodo : ''
						);
					}

					if ( WC()->cart ) {
						WC()->cart->calculate_totals();
					}
				},
			)
		);
	}

	/**
	 * Campos de configuração do desconto, reaproveitados pelos dois gateways.
	 *
	 * @param string $nome_metodo Ex.: "PIX".
	 * @return array
	 */
	public static function campos_configuracao( $nome_metodo ) {
		return array(
			'discount_section'    => array(
				/* translators: %s: nome do meio de pagamento, ex.: PIX */
				'title'       => sprintf( __( 'Desconto no %s', 'pagou-payments-for-woocommerce' ), $nome_metodo ),
				'type'        => 'title',
				/* translators: %s: nome do meio de pagamento, ex.: PIX */
				'description' => sprintf( __( 'Ofereça um desconto para quem pagar com %s. O total já sai com o desconto aplicado, inclusive no valor cobrado pela Pagou.', 'pagou-payments-for-woocommerce' ), $nome_metodo ),
			),
			'discount_amount'     => array(
				'title'       => __( 'Valor do desconto', 'pagou-payments-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Desconto concedido a quem escolher este meio de pagamento. Use 0 para não oferecer desconto. O desconto incide sobre o subtotal dos produtos.', 'pagou-payments-for-woocommerce' ),
				'default'     => '0',
				'desc_tip'    => true,
				'placeholder' => '0',
			),
			'discount_type'       => array(
				'title'       => __( 'Tipo de desconto', 'pagou-payments-for-woocommerce' ),
				'type'        => 'select',
				'description' => __( 'Percentual sobre o subtotal ou valor fixo em reais.', 'pagou-payments-for-woocommerce' ),
				'default'     => 'percentage',
				'desc_tip'    => true,
				'options'     => array(
					'percentage' => __( 'Percentual (%)', 'pagou-payments-for-woocommerce' ),
					'fixed'      => __( 'Valor fixo (R$)', 'pagou-payments-for-woocommerce' ),
				),
			),
			'discount_show_title' => array(
				'title'       => __( 'Exibir desconto no checkout', 'pagou-payments-for-woocommerce' ),
				'label'       => __( 'Mostrar o desconto ao lado do nome do meio de pagamento', 'pagou-payments-for-woocommerce' ),
				'type'        => 'checkbox',
				'description' => __( 'Exibe, por exemplo, "PIX (5% de desconto)" na lista de pagamentos.', 'pagou-payments-for-woocommerce' ),
				'default'     => 'yes',
				'desc_tip'    => true,
			),
		);
	}
}
