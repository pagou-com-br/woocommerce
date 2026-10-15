<?php
/**
 * Resposta normalizada da API da Pagou.
 *
 * A API devolve erros em formatos diferentes (validação traz `errors`, regra de negócio
 * traz `code`). Esta classe dá uma leitura única para os dois e separa o que é falha
 * definitiva do que é resultado incerto (timeout, 5xx, idempotência em andamento), que
 * nunca pode ser tratado como "não cobrou".
 *
 * @package Pagou
 */

namespace Pagou\WooCommerce\Api;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Response {

	/**
	 * @var int Código HTTP (0 quando não houve resposta).
	 */
	public $http = 0;

	/**
	 * @var array Corpo decodificado (já sem a api_key ecoada pela API).
	 */
	public $body = array();

	/**
	 * @var string Mensagem de erro de transporte (timeout, DNS), quando houver.
	 */
	public $erro_transporte = '';

	/**
	 * @param int    $http
	 * @param array  $body
	 * @param string $erro_transporte
	 */
	public function __construct( $http, $body, $erro_transporte = '' ) {
		$this->http            = (int) $http;
		$this->body            = is_array( $body ) ? self::higienizar( $body ) : array();
		$this->erro_transporte = (string) $erro_transporte;
	}

	/**
	 * @return bool 2xx.
	 */
	public function ok() {
		return $this->http >= 200 && $this->http < 300;
	}

	/**
	 * Resultado incerto: a operação pode ou não ter acontecido na Pagou.
	 *
	 * @return bool
	 */
	public function incerto() {
		if ( '' !== $this->erro_transporte || 0 === $this->http ) {
			return true;
		}

		if ( $this->http >= 500 ) {
			return true;
		}

		return 409 === $this->http && in_array( $this->codigo(), array( 'idempotency_in_progress', 'idempotency_outcome_unknown' ), true );
	}

	/**
	 * Código de erro de regra de negócio (ex.: merchant_not_accredited).
	 *
	 * @return string
	 */
	public function codigo() {
		return isset( $this->body['code'] ) && is_string( $this->body['code'] ) ? $this->body['code'] : '';
	}

	/**
	 * Mensagem de erro crua da API.
	 *
	 * @return string
	 */
	public function erro() {
		if ( '' !== $this->erro_transporte ) {
			return $this->erro_transporte;
		}

		if ( isset( $this->body['error'] ) && is_string( $this->body['error'] ) ) {
			return $this->body['error'];
		}

		if ( isset( $this->body['message'] ) && is_string( $this->body['message'] ) ) {
			return $this->body['message'];
		}

		return $this->ok() ? '' : 'HTTP ' . $this->http;
	}

	/**
	 * Lista de erros de validação (campo a campo), quando houver.
	 *
	 * @return string[]
	 */
	public function erros_validacao() {
		if ( isset( $this->body['errors'] ) && is_array( $this->body['errors'] ) ) {
			return array_values( array_filter( $this->body['errors'], 'is_string' ) );
		}

		return array();
	}

	/**
	 * @param string $chave
	 * @param mixed  $padrao
	 * @return mixed
	 */
	public function get( $chave, $padrao = null ) {
		return array_key_exists( $chave, $this->body ) ? $this->body[ $chave ] : $padrao;
	}

	/**
	 * Remove a api_key que a API ecoa dentro de `metadata`.
	 *
	 * @param array $body
	 * @return array
	 */
	public static function higienizar( array $body ) {
		if ( isset( $body['metadata'] ) && is_array( $body['metadata'] ) ) {
			$body['metadata'] = array_values(
				array_filter(
					$body['metadata'],
					function ( $par ) {
						return ! ( is_array( $par ) && isset( $par['key'] ) && 'api_key' === $par['key'] );
					}
				)
			);
		}

		unset( $body['api_key'] );

		return $body;
	}
}
