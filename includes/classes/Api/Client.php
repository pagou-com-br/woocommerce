<?php
/**
 * Cliente HTTP da API da Pagou.
 *
 * Único ponto do plugin que fala com a API: ambiente, autenticação, timeout,
 * identificação da integração, chave de idempotência e log passam todos por aqui.
 *
 * @package Pagou
 */

namespace Pagou\WooCommerce\Api;

use Pagou\WooCommerce\Logger;
use Pagou\WooCommerce\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Client {

	/**
	 * @var string
	 */
	private $api_key;

	/**
	 * @var string
	 */
	private $url_base;

	/**
	 * @param string|null $api_key  Padrão: chave das configurações.
	 * @param string|null $url_base Padrão: URL do ambiente configurado.
	 */
	public function __construct( $api_key = null, $url_base = null ) {
		$this->api_key  = null === $api_key ? Settings::api_key() : (string) $api_key;
		$this->url_base = null === $url_base ? Settings::url_base() : untrailingslashit( (string) $url_base );
	}

	/**
	 * @return bool
	 */
	public function configurado() {
		return '' !== $this->api_key;
	}

	/**
	 * @param string $caminho
	 * @param array  $query
	 * @return Response
	 */
	public function get( $caminho, $query = array() ) {
		if ( ! empty( $query ) ) {
			$caminho = add_query_arg( array_map( 'rawurlencode', $query ), $caminho );
		}

		return $this->requisitar( 'GET', $caminho );
	}

	/**
	 * @param string      $caminho
	 * @param array       $corpo
	 * @param string|null $idempotencia Idempotency-Key.
	 * @return Response
	 */
	public function post( $caminho, $corpo = array(), $idempotencia = null ) {
		return $this->requisitar( 'POST', $caminho, $corpo, $idempotencia );
	}

	/**
	 * @param string      $caminho
	 * @param array|null  $corpo
	 * @param string|null $idempotencia
	 * @return Response
	 */
	public function put( $caminho, $corpo = null, $idempotencia = null ) {
		return $this->requisitar( 'PUT', $caminho, $corpo, $idempotencia );
	}

	/**
	 * @param string     $caminho
	 * @param array|null $corpo
	 * @return Response
	 */
	public function delete( $caminho, $corpo = null ) {
		return $this->requisitar( 'DELETE', $caminho, $corpo );
	}

	/**
	 * @param string      $metodo
	 * @param string      $caminho Começando com "/v1/...". Identificadores externos já escapados.
	 * @param array|null  $corpo
	 * @param string|null $idempotencia
	 * @return Response
	 */
	public function requisitar( $metodo, $caminho, $corpo = null, $idempotencia = null ) {
		if ( ! $this->configurado() ) {
			return new Response( 401, array( 'error' => 'invalid api key' ) );
		}

		$cabecalhos = array(
			'X-API-KEY'    => $this->api_key,
			'Accept'       => 'application/json',
			'User-Agent'   => 'PagouWooCommerce/' . PAGOU_WC_VERSION . ' WordPress/' . get_bloginfo( 'version' ),
			'Content-Type' => 'application/json',
		);

		if ( $idempotencia ) {
			$cabecalhos['Idempotency-Key'] = $idempotencia;
		}

		$args = array(
			'method'  => $metodo,
			'timeout' => 35,
			'headers' => $cabecalhos,
		);

		if ( null !== $corpo ) {
			$args['body'] = self::json( $corpo );
		}

		$inicio   = microtime( true );
		$resposta = wp_remote_request( $this->url_base . $caminho, $args );
		$duracao  = (int) round( ( microtime( true ) - $inicio ) * 1000 );

		if ( is_wp_error( $resposta ) ) {
			$saida = new Response( 0, array(), $resposta->get_error_message() );
			Logger::erro(
				sprintf( 'API %s %s: falha de comunicação.', $metodo, self::caminho_para_log( $caminho ) ),
				array( 'erro' => $saida->erro_transporte, 'ms' => $duracao )
			);
			return $saida;
		}

		$http  = (int) wp_remote_retrieve_response_code( $resposta );
		$saida = new Response( $http, json_decode( wp_remote_retrieve_body( $resposta ), true ) );

		Logger::log(
			sprintf( 'API %s %s: HTTP %d.', $metodo, self::caminho_para_log( $caminho ), $http ),
			array(
				'ms'       => $duracao,
				'enviado'  => null === $corpo ? null : $corpo,
				'resposta' => $saida->body,
			),
			$saida->ok() ? 'info' : ( $saida->incerto() ? 'error' : 'warning' )
		);

		return $saida;
	}

	/**
	 * JSON com floats curtos (79.9, e não 79.900000000000005684...).
	 *
	 * Servidores com serialize_precision = 17 expandem floats no json_encode. O corpo
	 * precisa ser estável byte a byte: a repetição por idempotência exige o mesmo corpo.
	 *
	 * @param array $corpo
	 * @return string
	 */
	public static function json( $corpo ) {
		$anterior = ini_get( 'serialize_precision' );
		// phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged, WordPress.PHP.IniSet.Risky -- restaurado logo abaixo.
		ini_set( 'serialize_precision', '-1' );
		$json = wp_json_encode( $corpo );
		// phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged, WordPress.PHP.IniSet.Risky
		ini_set( 'serialize_precision', (string) $anterior );

		return (string) $json;
	}

	/**
	 * Gera uma chave de idempotência nova (UUID v4).
	 *
	 * @return string
	 */
	public static function nova_chave_idempotencia() {
		return wp_generate_uuid4();
	}

	/**
	 * Remove a query string do caminho para o log.
	 *
	 * @param string $caminho
	 * @return string
	 */
	private static function caminho_para_log( $caminho ) {
		$posicao = strpos( $caminho, '?' );

		return false === $posicao ? $caminho : substr( $caminho, 0, $posicao );
	}
}
