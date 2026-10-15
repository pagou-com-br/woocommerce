<?php
/**
 * Regras de split: leitura, validação e configuração.
 *
 * Uma regra é: rótulo (só para o lojista), UUID da conta Pagou recebedora, tipo
 * (percentage ou fixed) e valor. No nível do produto, "fixed" é por unidade vendida.
 *
 * @package Pagou
 */

namespace Pagou\WooCommerce\Split;

use Pagou\WooCommerce\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Rules {

	const MAX_RECEBEDORES = 10;

	/**
	 * @return bool
	 */
	public static function ativo() {
		return 'yes' === Settings::get( 'split_enabled', 'no' );
	}

	/**
	 * Meios de pagamento em que o split vale.
	 *
	 * @return string[]
	 */
	public static function meios() {
		$meios = Settings::get( 'split_methods', array( 'pagou_pix', 'pagou_boleto', 'pagou_cartao' ) );

		return is_array( $meios ) ? $meios : array();
	}

	/**
	 * Base do percentual global: 'produtos' (sem frete) ou 'pedido' (com frete).
	 *
	 * @return string
	 */
	public static function base() {
		return 'pedido' === Settings::get( 'split_base', 'produtos' ) ? 'pedido' : 'produtos';
	}

	/**
	 * @return array[]
	 */
	public static function globais() {
		return self::sanitizar( Settings::get( 'split_rules', array() ) );
	}

	/**
	 * Modo do produto: 'global', 'proprio' ou 'nenhum'.
	 *
	 * @param int $produto_id
	 * @return string
	 */
	public static function modo_produto( $produto_id ) {
		$modo = (string) get_post_meta( $produto_id, '_pagou_split_modo', true );

		return in_array( $modo, array( 'proprio', 'nenhum' ), true ) ? $modo : 'global';
	}

	/**
	 * @param int $produto_id
	 * @return array[]
	 */
	public static function do_produto( $produto_id ) {
		return self::sanitizar( get_post_meta( $produto_id, '_pagou_split_regras', true ) );
	}

	/**
	 * @param string $uuid
	 * @return bool
	 */
	public static function uuid_valido( $uuid ) {
		return (bool) preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', (string) $uuid );
	}

	/**
	 * Limpa e valida uma lista de regras, descartando as inválidas.
	 *
	 * @param mixed $regras
	 * @return array[]
	 */
	public static function sanitizar( $regras ) {
		if ( ! is_array( $regras ) ) {
			return array();
		}

		$limpas = array();

		foreach ( $regras as $regra ) {
			if ( ! is_array( $regra ) ) {
				continue;
			}

			$uuid  = strtolower( trim( isset( $regra['customer_id'] ) ? (string) $regra['customer_id'] : '' ) );
			$tipo  = isset( $regra['type'] ) && 'fixed' === $regra['type'] ? 'fixed' : 'percentage';
			$valor = isset( $regra['value'] ) ? (float) str_replace( ',', '.', (string) $regra['value'] ) : 0;

			if ( ! self::uuid_valido( $uuid ) || $valor <= 0 ) {
				continue;
			}

			if ( 'percentage' === $tipo ) {
				$valor = min( 100, round( $valor, 4 ) );
			} else {
				$valor = round( $valor, 2 );
			}

			$limpas[] = array(
				'label'       => sanitize_text_field( isset( $regra['label'] ) ? (string) $regra['label'] : '' ),
				'customer_id' => $uuid,
				'type'        => $tipo,
				'value'       => $valor,
			);
		}

		return array_slice( $limpas, 0, self::MAX_RECEBEDORES );
	}
}
