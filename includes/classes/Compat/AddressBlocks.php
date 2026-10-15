<?php
/**
 * Compatibilidade do endereço brasileiro com o checkout em blocos.
 *
 * O plugin Brazilian Market on WooCommerce acrescenta {number} e {neighborhood} ao
 * formato de endereço do Brasil e os substitui no PHP. O checkout em blocos, porém,
 * monta o endereço no JavaScript e só conhece os campos padrão do Store API, então
 * esses marcadores acabam exibidos crus ao cliente, assim:
 *
 *     Rua Caramuru, 370, {number}, {neighborhood}, Pato Branco, Paraná
 *
 * Aqui removemos, apenas nas telas de carrinho e checkout em blocos, os marcadores que
 * o JavaScript não sabe preencher. Nos e-mails, no admin e no checkout clássico o
 * formato continua intacto, porque nesses casos a substituição funciona.
 *
 * @package Pagou
 */

namespace Pagou\WooCommerce\Compat;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AddressBlocks {

	/**
	 * Marcadores que o checkout em blocos consegue preencher.
	 *
	 * @var string[]
	 */
	private static $conhecidos = array(
		'{name}',
		'{name_upper}',
		'{first_name}',
		'{first_name_upper}',
		'{last_name}',
		'{last_name_upper}',
		'{company}',
		'{company_upper}',
		'{address_1}',
		'{address_1_upper}',
		'{address_2}',
		'{address_2_upper}',
		'{city}',
		'{city_upper}',
		'{state}',
		'{state_code}',
		'{state_upper}',
		'{postcode}',
		'{postcode_upper}',
		'{country}',
		'{country_upper}',
	);

	public static function init() {
		add_action( 'wp', array( __CLASS__, 'talvez_aplicar' ) );
	}

	/**
	 * Só age nas páginas de carrinho e checkout construídas com blocos.
	 */
	public static function talvez_aplicar() {
		if ( is_admin() || ! function_exists( 'is_checkout' ) || ! function_exists( 'has_block' ) ) {
			return;
		}

		if ( ! is_checkout() && ! is_cart() ) {
			return;
		}

		$post = get_post();

		if ( ! $post ) {
			return;
		}

		$usa_blocos = has_block( 'woocommerce/checkout', $post ) || has_block( 'woocommerce/cart', $post );

		if ( ! $usa_blocos ) {
			return;
		}

		add_filter( 'woocommerce_localisation_address_formats', array( __CLASS__, 'limpar_marcadores' ), 99 );
	}

	/**
	 * Remove marcadores desconhecidos e os separadores que sobram.
	 *
	 * @param array $formatos
	 * @return array
	 */
	public static function limpar_marcadores( $formatos ) {
		if ( ! is_array( $formatos ) ) {
			return $formatos;
		}

		foreach ( $formatos as $pais => $formato ) {
			if ( ! is_string( $formato ) || ! preg_match_all( '/\{[a-z0-9_]+\}/', $formato, $encontrados ) ) {
				continue;
			}

			$alterou = false;

			foreach ( array_unique( $encontrados[0] ) as $marcador ) {
				if ( ! in_array( $marcador, self::$conhecidos, true ) ) {
					$formato = str_replace( $marcador, '', $formato );
					$alterou = true;
				}
			}

			if ( ! $alterou ) {
				continue;
			}

			// "{address_1}, " passa a "{address_1}"; vírgulas e espaços órfãos saem.
			$formato = preg_replace( '/,[ \t]*(?=\n|$)/', '', $formato );
			$formato = preg_replace( '/,[ \t]*,/', ',', $formato );
			$formato = preg_replace( '/\n{2,}/', "\n", $formato );
			$formato = trim( $formato, " \t," );

			$formatos[ $pais ] = $formato;
		}

		return $formatos;
	}
}
