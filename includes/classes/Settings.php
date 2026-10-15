<?php
/**
 * Configurações gerais do plugin, compartilhadas por todos os meios de pagamento.
 *
 * Fica numa única opção (`pagou_wc_settings`). A chave de API pode, opcionalmente, vir da
 * constante PAGOU_API_KEY no wp-config.php, o que a tira do banco de dados.
 *
 * @package Pagou
 */

namespace Pagou\WooCommerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Settings {

	const OPCAO = 'pagou_wc_settings';

	const URLS = array(
		'pro' => 'https://api.pagou.com.br',
		'hom' => 'https://api.pagou.cloud',
	);

	/**
	 * @var array|null Cache da requisição.
	 */
	private static $cache = null;

	/**
	 * Valores padrão de cada configuração.
	 *
	 * @return array
	 */
	public static function padroes() {
		return array(
			'api_key'          => '',
			'environment'      => 'pro',
			'debug'            => 'no',
			'ip_header'        => '',
			'brazilian_fields' => 'auto',
		);
	}

	/**
	 * Todas as configurações, já mescladas com os padrões.
	 *
	 * @return array
	 */
	public static function todas() {
		if ( null === self::$cache ) {
			$salvas      = get_option( self::OPCAO, array() );
			self::$cache = array_merge( self::padroes(), is_array( $salvas ) ? $salvas : array() );
		}

		return self::$cache;
	}

	/**
	 * @param string $chave
	 * @param mixed  $padrao
	 * @return mixed
	 */
	public static function get( $chave, $padrao = null ) {
		$todas = self::todas();

		return array_key_exists( $chave, $todas ) ? $todas[ $chave ] : $padrao;
	}

	/**
	 * Grava um conjunto de configurações (mescla com as existentes).
	 *
	 * @param array $valores
	 */
	public static function salvar( array $valores ) {
		$atuais = get_option( self::OPCAO, array() );
		$atuais = is_array( $atuais ) ? $atuais : array();

		update_option( self::OPCAO, array_merge( $atuais, $valores ), false );
		self::$cache = null;
	}

	/**
	 * Limpa o cache em memória (após salvar pelo formulário do WooCommerce).
	 */
	public static function recarregar() {
		self::$cache = null;
	}

	/**
	 * Chave de API efetiva. A constante PAGOU_API_KEY, quando definida, tem prioridade.
	 *
	 * @return string
	 */
	public static function api_key() {
		if ( defined( 'PAGOU_API_KEY' ) && '' !== (string) PAGOU_API_KEY ) {
			return (string) PAGOU_API_KEY;
		}

		return trim( (string) self::get( 'api_key', '' ) );
	}

	/**
	 * @return bool True quando a chave vem do wp-config.php.
	 */
	public static function api_key_por_constante() {
		return defined( 'PAGOU_API_KEY' ) && '' !== (string) PAGOU_API_KEY;
	}

	/**
	 * @return string 'pro' ou 'hom'.
	 */
	public static function ambiente() {
		$ambiente = (string) self::get( 'environment', 'pro' );

		return isset( self::URLS[ $ambiente ] ) ? $ambiente : 'pro';
	}

	/**
	 * @return bool
	 */
	public static function sandbox() {
		return 'hom' === self::ambiente();
	}

	/**
	 * URL base da API para o ambiente configurado.
	 *
	 * @return string
	 */
	public static function url_base() {
		return self::URLS[ self::ambiente() ];
	}

	/**
	 * @return bool
	 */
	public static function debug() {
		return 'yes' === self::get( 'debug', 'no' );
	}

	/**
	 * IP real do comprador, conforme o cabeçalho de proxy configurado.
	 *
	 * Só lê o cabeçalho escolhido pelo lojista (um cabeçalho arbitrário poderia ser forjado
	 * pelo próprio cliente). No X-Forwarded-For vale o primeiro IP da lista.
	 *
	 * @return string
	 */
	public static function ip_cliente() {
		$cabecalho = (string) self::get( 'ip_header', '' );
		$ip        = '';

		if ( '' !== $cabecalho && isset( self::cabecalhos_ip()[ $cabecalho ] ) && ! empty( $_SERVER[ $cabecalho ] ) ) {
			$valor = sanitize_text_field( wp_unslash( $_SERVER[ $cabecalho ] ) );
			$ip    = trim( explode( ',', $valor )[0] );
		}

		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		}

		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}

	/**
	 * Cabeçalhos aceitos como fonte do IP real do cliente atrás de proxy.
	 *
	 * @return array
	 */
	public static function cabecalhos_ip() {
		return array(
			''                      => __( 'Conexão direta (REMOTE_ADDR)', 'pagou-payments-for-woocommerce' ),
			'HTTP_CF_CONNECTING_IP' => __( 'Cloudflare (CF-Connecting-IP)', 'pagou-payments-for-woocommerce' ),
			'HTTP_X_REAL_IP'        => __( 'Proxy reverso (X-Real-IP)', 'pagou-payments-for-woocommerce' ),
			'HTTP_X_FORWARDED_FOR'  => __( 'Proxy ou balanceador (X-Forwarded-For)', 'pagou-payments-for-woocommerce' ),
		);
	}
}
