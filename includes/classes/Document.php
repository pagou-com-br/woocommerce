<?php
/**
 * Validação e formatação de CPF e CNPJ.
 *
 * @package Pagou
 */

namespace Pagou\WooCommerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Document {

	/**
	 * @param string $valor
	 * @return string Somente dígitos.
	 */
	public static function digitos( $valor ) {
		return preg_replace( '/\D/', '', (string) $valor );
	}

	/**
	 * @param string $valor
	 * @return string 'cpf', 'cnpj' ou '' quando inválido.
	 */
	public static function tipo( $valor ) {
		$numeros = self::digitos( $valor );

		if ( self::cpf_valido( $numeros ) ) {
			return 'cpf';
		}

		if ( self::cnpj_valido( $numeros ) ) {
			return 'cnpj';
		}

		return '';
	}

	/**
	 * @param string $valor
	 * @return bool
	 */
	public static function valido( $valor ) {
		return '' !== self::tipo( $valor );
	}

	/**
	 * @param string $cpf Somente dígitos.
	 * @return bool
	 */
	public static function cpf_valido( $cpf ) {
		if ( 11 !== strlen( $cpf ) || preg_match( '/^(\d)\1{10}$/', $cpf ) ) {
			return false;
		}

		for ( $t = 9; $t < 11; $t++ ) {
			$soma = 0;
			for ( $i = 0; $i < $t; $i++ ) {
				$soma += (int) $cpf[ $i ] * ( ( $t + 1 ) - $i );
			}
			$digito = ( ( 10 * $soma ) % 11 ) % 10;
			if ( (int) $cpf[ $t ] !== $digito ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * @param string $cnpj Somente dígitos.
	 * @return bool
	 */
	public static function cnpj_valido( $cnpj ) {
		if ( 14 !== strlen( $cnpj ) || preg_match( '/^(\d)\1{13}$/', $cnpj ) ) {
			return false;
		}

		$pesos = array( 6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2 );

		for ( $t = 12; $t < 14; $t++ ) {
			$soma = 0;
			for ( $i = 0; $i < $t; $i++ ) {
				$soma += (int) $cnpj[ $i ] * $pesos[ $i + ( 13 - $t ) ];
			}
			$resto  = $soma % 11;
			$digito = $resto < 2 ? 0 : 11 - $resto;
			if ( (int) $cnpj[ $t ] !== $digito ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Formata para exibição (000.000.000-00 ou 00.000.000/0000-00).
	 *
	 * @param string $valor
	 * @return string
	 */
	public static function formatar( $valor ) {
		$numeros = self::digitos( $valor );

		if ( 11 === strlen( $numeros ) ) {
			return preg_replace( '/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', $numeros );
		}

		if ( 14 === strlen( $numeros ) ) {
			return preg_replace( '/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/', '$1.$2.$3/$4-$5', $numeros );
		}

		return $numeros;
	}
}
