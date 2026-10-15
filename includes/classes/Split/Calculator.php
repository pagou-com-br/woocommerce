<?php
/**
 * Cálculo do split de um pedido.
 *
 * As regras globais e as de produto são resolvidas pelo plugin em valores fixos (reais),
 * somados por recebedor, porque a Pagou calcula percentuais sobre o bruto do pagamento
 * inteiro e isso não permite separar produto, frete e juros. O que sobra (inclusive os
 * juros do parcelamento) fica com a conta da loja.
 *
 * Regras:
 * - Produto em "regra própria": percentual sobre o total da linha (com descontos e
 *   impostos); valor fixo multiplicado pela quantidade.
 * - Produto em "sem split": fica fora de tudo.
 * - Demais produtos seguem a regra global. Base "produtos": percentual sobre as linhas
 *   desses produtos. Base "pedido": percentual sobre o total do pedido (com frete), sem
 *   os juros e sem as linhas que têm regra própria ou estão fora do split.
 *   O valor fixo global vale uma vez por pedido.
 * - Descontos da Pagou (Pix/boleto) reduzem a base proporcionalmente.
 *
 * @package Pagou
 */

namespace Pagou\WooCommerce\Split;

use Pagou\WooCommerce\Discounts;
use Pagou\WooCommerce\Gateway\Card;
use Pagou\WooCommerce\Money;
use WC_Order;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Calculator {

	/**
	 * @param WC_Order $order
	 * @return array{split:array,detalhe:array,aviso:string} split no formato da API.
	 */
	public static function calcular( WC_Order $order ) {
		$por_recebedor = array();
		$detalhe       = array();
		$linhas_global = 0;
		$linhas_fora   = 0;
		$subtotal      = 0;

		foreach ( $order->get_items() as $item ) {
			$subtotal += Money::centavos( $item->get_total() + $item->get_total_tax() );
		}

		// Desconto da Pagou (Pix/boleto) reduz proporcionalmente todas as bases.
		$desconto = Money::centavos( Discounts::desconto_do_pedido( $order ) );
		$fator    = $subtotal > 0 ? max( 0, ( $subtotal - $desconto ) / $subtotal ) : 1;

		foreach ( $order->get_items() as $item ) {
			$produto_id = $item->get_product_id();
			$linha      = (int) round( Money::centavos( $item->get_total() + $item->get_total_tax() ) * $fator );
			$modo       = Rules::modo_produto( $produto_id );

			if ( 'nenhum' === $modo ) {
				$linhas_fora += $linha;
				continue;
			}

			if ( 'proprio' === $modo ) {
				$linhas_fora += $linha;
				foreach ( Rules::do_produto( $produto_id ) as $regra ) {
					$valor = 'percentage' === $regra['type']
						? self::percentual( $linha, $regra['value'] )
						: Money::centavos( $regra['value'] ) * max( 1, (int) $item->get_quantity() );
					self::somar( $por_recebedor, $regra['customer_id'], $valor );
					$detalhe[] = array( 'origem' => $item->get_name(), 'regra' => $regra, 'centavos' => $valor );
				}
				continue;
			}

			$linhas_global += $linha;
		}

		$globais = Rules::globais();

		if ( ! empty( $globais ) ) {
			if ( 'pedido' === Rules::base() ) {
				$juros = Money::centavos( Card::juros_no_pedido( $order ) );
				$base  = max( 0, Money::centavos( $order->get_total() ) - $juros - $linhas_fora );
			} else {
				$base = $linhas_global;
			}

			foreach ( $globais as $regra ) {
				if ( $base <= 0 && 'percentage' === $regra['type'] ) {
					continue;
				}
				// Valor fixo global só entra se algum produto do pedido segue a regra global.
				if ( 'fixed' === $regra['type'] && $linhas_global <= 0 ) {
					continue;
				}
				$valor = 'percentage' === $regra['type'] ? self::percentual( $base, $regra['value'] ) : Money::centavos( $regra['value'] );
				self::somar( $por_recebedor, $regra['customer_id'], $valor );
				$detalhe[] = array( 'origem' => 'global', 'regra' => $regra, 'centavos' => $valor );
			}
		}

		$por_recebedor = array_filter( $por_recebedor );
		$aviso         = '';

		if ( count( $por_recebedor ) > Rules::MAX_RECEBEDORES ) {
			arsort( $por_recebedor );
			$por_recebedor = array_slice( $por_recebedor, 0, Rules::MAX_RECEBEDORES, true );
			$aviso         = __( 'O pedido tinha mais de 10 recebedores; foram mantidos os 10 de maior valor.', 'pagou-payments-for-woocommerce' );
		}

		$soma = array_sum( $por_recebedor );
		if ( $soma >= Money::centavos( $order->get_total() ) ) {
			return array(
				'split'   => array(),
				'detalhe' => $detalhe,
				'aviso'   => __( 'As regras de split somam o valor inteiro do pedido (ou mais) e não sobra margem para as tarifas. O pedido foi cobrado sem split.', 'pagou-payments-for-woocommerce' ),
			);
		}

		$split = array();
		foreach ( $por_recebedor as $uuid => $centavos ) {
			$split[] = array(
				'customer_id' => $uuid,
				'type'        => 'fixed',
				'value'       => Money::reais( $centavos ),
			);
		}

		return array(
			'split'   => $split,
			'detalhe' => $detalhe,
			'aviso'   => $aviso,
		);
	}

	/**
	 * Percentual com arredondamento de meio centavo para cima (mesma regra da Pagou).
	 *
	 * @param int   $base_centavos
	 * @param float $percentual
	 * @return int
	 */
	private static function percentual( $base_centavos, $percentual ) {
		return (int) floor( ( $base_centavos * $percentual / 100 ) + 0.5 );
	}

	/**
	 * @param array  $acumulado
	 * @param string $uuid
	 * @param int    $centavos
	 */
	private static function somar( array &$acumulado, $uuid, $centavos ) {
		$acumulado[ $uuid ] = ( isset( $acumulado[ $uuid ] ) ? $acumulado[ $uuid ] : 0 ) + (int) $centavos;
	}
}
