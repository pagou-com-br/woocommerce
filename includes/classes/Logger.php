<?php
/**
 * Log do plugin em WooCommerce > Status > Logs (fonte "pagou"), só com o modo debug ligado.
 *
 * Nada sensível é escrito: chave de API, token de cartão, CVV e número de cartão são
 * mascarados antes do registro, e a imagem base64 do QR é omitida.
 *
 * @package Pagou
 */

namespace Pagou\WooCommerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Logger {

	/**
	 * Chaves cujo valor nunca vai para o log.
	 */
	const SENSIVEIS = array( 'api_key', 'apikey', 'x-api-key', 'authorization', 'card_token', 'cvv', 'number', 'token' );

	/**
	 * @param string $mensagem
	 * @param array  $contexto
	 * @param string $nivel info, warning ou error.
	 */
	public static function log( $mensagem, $contexto = array(), $nivel = 'info' ) {
		// Erros sempre ficam registrados: são eles que o suporte precisa ver.
		if ( ( ! Settings::debug() && 'error' !== $nivel ) || ! function_exists( 'wc_get_logger' ) ) {
			return;
		}

		if ( ! empty( $contexto ) ) {
			$mensagem .= ' | ' . Api\Client::json( self::mascarar( $contexto ) );
		}

		wc_get_logger()->log( $nivel, $mensagem, array( 'source' => 'pagou' ) );
	}

	/**
	 * @param string $mensagem
	 * @param array  $contexto
	 */
	public static function erro( $mensagem, $contexto = array() ) {
		self::log( $mensagem, $contexto, 'error' );
	}

	/**
	 * @param string $mensagem
	 * @param array  $contexto
	 */
	public static function aviso( $mensagem, $contexto = array() ) {
		self::log( $mensagem, $contexto, 'warning' );
	}

	/**
	 * Mascara credenciais e dados de cartão, recursivamente.
	 *
	 * @param mixed $dados
	 * @return mixed
	 */
	public static function mascarar( $dados ) {
		if ( ! is_array( $dados ) ) {
			return $dados;
		}

		foreach ( $dados as $chave => $valor ) {
			$nome = strtolower( (string) $chave );

			if ( in_array( $nome, self::SENSIVEIS, true ) ) {
				// "number" também é número de endereço; só mascara quando parece cartão.
				if ( 'number' === $nome && ( ! is_string( $valor ) || strlen( preg_replace( '/\D/', '', $valor ) ) < 13 ) ) {
					continue;
				}
				$dados[ $chave ] = '***';
				continue;
			}

			if ( 'image' === $nome && is_string( $valor ) && strlen( $valor ) > 200 ) {
				$dados[ $chave ] = '[imagem omitida]';
				continue;
			}

			// A Pagou devolve metadata como lista de pares key/value, que pode conter a api_key.
			if ( is_array( $valor ) && isset( $valor['key'] ) && 'api_key' === $valor['key'] ) {
				$dados[ $chave ]['value'] = '***';
				continue;
			}

			if ( is_array( $valor ) ) {
				$dados[ $chave ] = self::mascarar( $valor );
			}
		}

		return $dados;
	}
}
