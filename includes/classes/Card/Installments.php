<?php
/**
 * Cálculo das parcelas do cartão.
 *
 * A API da Pagou não calcula juros: o plugin oferece as opções conforme a configuração do
 * lojista (parcelas máximas, valor mínimo da parcela, parcelas sem juros e taxa ao mês) e
 * acrescenta os juros ao pedido como uma taxa. O servidor sempre recalcula: o valor que
 * vem do navegador nunca é usado.
 *
 * Juros pela tabela Price: parcela = P * i / (1 - (1 + i)^-n).
 *
 * @package Pagou
 */

namespace Pagou\WooCommerce\Card;

use Pagou\WooCommerce\Money;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Installments {

	/**
	 * @var int Parcelas máximas (1 a 12).
	 */
	private $maximo;

	/**
	 * @var int Valor mínimo da parcela, em centavos.
	 */
	private $minimo;

	/**
	 * @var int Até quantas parcelas não há juros.
	 */
	private $sem_juros;

	/**
	 * @var float Taxa ao mês, em fração (2,99% = 0.0299).
	 */
	private $taxa;

	/**
	 * @param int   $maximo
	 * @param float $minimo_reais
	 * @param int   $sem_juros
	 * @param float $taxa_percentual
	 */
	public function __construct( $maximo, $minimo_reais, $sem_juros, $taxa_percentual ) {
		$this->maximo    = min( 12, max( 1, (int) $maximo ) );
		$this->minimo    = max( 0, Money::centavos( $minimo_reais ) );
		$this->sem_juros = min( $this->maximo, max( 1, (int) $sem_juros ) );
		$this->taxa      = max( 0, (float) str_replace( ',', '.', (string) $taxa_percentual ) ) / 100;
	}

	/**
	 * A partir das configurações do gateway.
	 *
	 * @param array $config
	 * @return self
	 */
	public static function de_config( array $config ) {
		return new self(
			isset( $config['installments_max'] ) ? $config['installments_max'] : 12,
			isset( $config['installments_min'] ) ? $config['installments_min'] : 5,
			isset( $config['installments_free'] ) ? $config['installments_free'] : 1,
			isset( $config['installments_rate'] ) ? $config['installments_rate'] : 0
		);
	}

	/**
	 * Parâmetros para o navegador desenhar o seletor (o servidor recalcula ao cobrar).
	 *
	 * @return array
	 */
	public function para_js() {
		return array(
			'maximo'   => $this->maximo,
			'minimo'   => $this->minimo,
			'semJuros' => $this->sem_juros,
			'taxa'     => $this->taxa,
		);
	}

	/**
	 * Opções de parcelamento para um valor.
	 *
	 * @param int $total_centavos Total do pedido antes dos juros.
	 * @return array[] Cada item: n, parcela, total, juros (centavos) e sem_juros (bool).
	 */
	public function opcoes( $total_centavos ) {
		$total_centavos = (int) $total_centavos;
		$opcoes         = array();

		for ( $n = 1; $n <= $this->maximo; $n++ ) {
			$opcao = $this->calcular( $total_centavos, $n );

			// A primeira parcela sempre existe; as demais respeitam o valor mínimo.
			if ( $n > 1 && $opcao['parcela'] < $this->minimo ) {
				break;
			}

			$opcoes[ $n ] = $opcao;
		}

		return $opcoes;
	}

	/**
	 * @param int $total_centavos
	 * @param int $n
	 * @return array
	 */
	public function calcular( $total_centavos, $n ) {
		$n = max( 1, (int) $n );

		if ( $n <= $this->sem_juros || $this->taxa <= 0 ) {
			return array(
				'n'         => $n,
				'parcela'   => (int) ceil( $total_centavos / $n ),
				'total'     => $total_centavos,
				'juros'     => 0,
				'sem_juros' => true,
			);
		}

		$parcela = (int) round( $total_centavos * $this->taxa / ( 1 - pow( 1 + $this->taxa, -$n ) ) );
		$total   = $parcela * $n;

		return array(
			'n'         => $n,
			'parcela'   => $parcela,
			'total'     => $total,
			'juros'     => max( 0, $total - $total_centavos ),
			'sem_juros' => false,
		);
	}

	/**
	 * Texto da opção, ex.: "3x de R$ 33,30 sem juros" ou "6x de R$ 18,20 (total R$ 109,20)".
	 *
	 * @param array $opcao
	 * @return string
	 */
	public static function rotulo( array $opcao ) {
		$texto = self::rotulo_html( $opcao );

		return html_entity_decode( wp_strip_all_tags( $texto ), ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * @param array $opcao
	 * @return string
	 */
	private static function rotulo_html( array $opcao ) {
		$parcela = wp_strip_all_tags( Money::exibir( $opcao['parcela'] ) );

		if ( 1 === $opcao['n'] ) {
			/* translators: %s: valor */
			return sprintf( __( 'À vista, %s', 'pagou-payments-for-woocommerce' ), $parcela );
		}

		if ( $opcao['sem_juros'] ) {
			/* translators: 1: parcelas, 2: valor da parcela */
			return sprintf( __( '%1$dx de %2$s sem juros', 'pagou-payments-for-woocommerce' ), $opcao['n'], $parcela );
		}

		return sprintf(
			/* translators: 1: parcelas, 2: valor da parcela, 3: total */
			__( '%1$dx de %2$s (total %3$s)', 'pagou-payments-for-woocommerce' ),
			$opcao['n'],
			$parcela,
			wp_strip_all_tags( Money::exibir( $opcao['total'] ) )
		);
	}
}
