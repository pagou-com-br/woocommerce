<?php
/**
 * Conversões de dinheiro.
 *
 * Internamente o plugin trabalha em centavos (inteiros), para não acumular erro de ponto
 * flutuante. Cada campo da API é serializado na unidade que ela exige:
 * Pix e boleto em reais, cartão em centavos, split `fixed` em reais (inclusive no cartão).
 *
 * @package Pagou
 */

namespace Pagou\WooCommerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Money {

	/**
	 * Converte um valor em reais (float ou string, com ponto ou vírgula) para centavos.
	 *
	 * @param mixed $reais
	 * @return int
	 */
	public static function centavos( $reais ) {
		if ( is_string( $reais ) ) {
			$reais = str_replace( ',', '.', $reais );
		}

		return (int) round( ( (float) $reais ) * 100 );
	}

	/**
	 * Converte centavos para reais com duas casas.
	 *
	 * @param int $centavos
	 * @return float
	 */
	public static function reais( $centavos ) {
		return round( ( (int) $centavos ) / 100, 2 );
	}

	/**
	 * Formata centavos como preço da loja (para exibição).
	 *
	 * @param int $centavos
	 * @return string HTML do wc_price.
	 */
	public static function exibir( $centavos ) {
		return wc_price( self::reais( $centavos ) );
	}
}
