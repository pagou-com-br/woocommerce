<?php
/**
 * Campos brasileiros nativos: CPF/CNPJ, número e bairro.
 *
 * Antes da 2.0.0 o plugin dependia do Brazilian Market on WooCommerce. Agora:
 * - Checkout em blocos: registra os campos pela API oficial de campos adicionais
 *   (o Brazilian Market não atua no checkout em blocos).
 * - Checkout clássico: se o Brazilian Market estiver ativo, usa os campos dele; se não,
 *   adiciona campos equivalentes, com as mesmas chaves de número e bairro.
 *
 * A validação do CPF/CNPJ acontece no servidor, não só no navegador.
 *
 * @package Pagou
 */

namespace Pagou\WooCommerce\Checkout;

use Pagou\WooCommerce\Document;
use Pagou\WooCommerce\Settings;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BrazilianFields {

	const CAMPO_DOCUMENTO = 'pagou/document';
	const CAMPO_NUMERO    = 'pagou/number';
	const CAMPO_BAIRRO    = 'pagou/neighborhood';

	const GATEWAYS = array( 'pagou_pix', 'pagou_boleto', 'pagou_cartao' );

	public static function init() {
		if ( 'off' === Settings::get( 'brazilian_fields', 'auto' ) ) {
			return;
		}

		add_action( 'woocommerce_init', array( __CLASS__, 'registrar_campos_blocos' ) );

		if ( ! self::brazilian_market_ativo() ) {
			add_filter( 'woocommerce_billing_fields', array( __CLASS__, 'campos_classicos' ), 20 );
			add_filter( 'woocommerce_admin_billing_fields', array( __CLASS__, 'campos_admin' ) );
		}

		add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'validar_classico' ), 10, 2 );
	}

	/**
	 * @return bool
	 */
	public static function brazilian_market_ativo() {
		return defined( 'CSBMW_PLUGIN_FILE' ) || class_exists( 'Extra_Checkout_Fields_For_Brazil' );
	}

	/**
	 * Regras condicionais (WooCommerce 9.9+). Em versões anteriores, campo opcional e
	 * validação feita no pagamento.
	 *
	 * @return bool
	 */
	private static function suporta_regras() {
		return defined( 'WC_VERSION' ) && version_compare( WC_VERSION, '9.9', '>=' );
	}

	/**
	 * Registra os campos no checkout em blocos.
	 */
	public static function registrar_campos_blocos() {
		if ( ! function_exists( 'woocommerce_register_additional_checkout_field' ) ) {
			return;
		}

		/**
		 * Permite desligar os campos do checkout em blocos (ex.: outro plugin já os fornece).
		 *
		 * @param bool $registrar
		 */
		if ( ! apply_filters( 'pagou_wc_registrar_campos_blocos', true ) ) {
			return;
		}

		$somente_brasil = array(
			'type'       => 'object',
			'properties' => array(
				'customer' => array(
					'properties' => array(
						'address' => array(
							'properties' => array(
								'country' => array( 'const' => 'BR' ),
							),
						),
					),
				),
			),
		);

		$fora_do_brasil = array(
			'type'       => 'object',
			'properties' => array(
				'customer' => array(
					'properties' => array(
						'address' => array(
							'properties' => array(
								'country' => array( 'not' => array( 'const' => 'BR' ) ),
							),
						),
					),
				),
			),
		);

		$pagando_com_pagou = array(
			'type'       => 'object',
			'properties' => array(
				'checkout' => array(
					'properties' => array(
						'payment_method' => array( 'enum' => self::GATEWAYS ),
					),
					'required'   => array( 'payment_method' ),
				),
			),
		);

		$regras = self::suporta_regras();

		woocommerce_register_additional_checkout_field(
			array(
				'id'                => self::CAMPO_DOCUMENTO,
				'label'             => __( 'CPF ou CNPJ', 'pagou-payments-for-woocommerce' ),
				'optionalLabel'     => __( 'CPF ou CNPJ (opcional)', 'pagou-payments-for-woocommerce' ),
				'location'          => 'contact',
				'type'              => 'text',
				'required'          => $regras ? $pagando_com_pagou : false,
				'attributes'        => array(
					'autocomplete' => 'off',
					'inputmode'    => 'numeric',
					'maxLength'    => 18,
					'data-pagou'   => 'documento',
				),
				'sanitize_callback' => array( __CLASS__, 'sanitizar_documento' ),
				'validate_callback' => array( __CLASS__, 'validar_documento' ),
			)
		);

		woocommerce_register_additional_checkout_field(
			array(
				'id'            => self::CAMPO_NUMERO,
				'label'         => __( 'Número', 'pagou-payments-for-woocommerce' ),
				'optionalLabel' => __( 'Número (opcional)', 'pagou-payments-for-woocommerce' ),
				'location'      => 'address',
				'type'          => 'text',
				'required'      => $regras ? $somente_brasil : false,
				'hidden'        => $regras ? $fora_do_brasil : false,
				'attributes'    => array(
					'autocomplete' => 'address-line2',
					'maxLength'    => 20,
				),
			)
		);

		woocommerce_register_additional_checkout_field(
			array(
				'id'            => self::CAMPO_BAIRRO,
				'label'         => __( 'Bairro', 'pagou-payments-for-woocommerce' ),
				'optionalLabel' => __( 'Bairro (opcional)', 'pagou-payments-for-woocommerce' ),
				'location'      => 'address',
				'type'          => 'text',
				'required'      => $regras ? $somente_brasil : false,
				'hidden'        => $regras ? $fora_do_brasil : false,
				'attributes'    => array(
					'autocomplete' => 'address-level3',
					'maxLength'    => 60,
				),
			)
		);
	}

	/**
	 * @param string $valor
	 * @return string Documento formatado.
	 */
	public static function sanitizar_documento( $valor ) {
		$valor = sanitize_text_field( (string) $valor );

		return Document::valido( $valor ) ? Document::formatar( $valor ) : $valor;
	}

	/**
	 * @param string $valor
	 * @return true|WP_Error
	 */
	public static function validar_documento( $valor ) {
		if ( '' === trim( (string) $valor ) || Document::valido( $valor ) ) {
			return true;
		}

		return new WP_Error( 'pagou_documento_invalido', __( 'Informe um CPF ou CNPJ válido.', 'pagou-payments-for-woocommerce' ) );
	}

	/**
	 * Campos no checkout clássico, quando o Brazilian Market não está ativo.
	 *
	 * @param array $campos
	 * @return array
	 */
	public static function campos_classicos( $campos ) {
		$novos = array();

		foreach ( $campos as $chave => $campo ) {
			$novos[ $chave ] = $campo;

			if ( 'billing_last_name' === $chave ) {
				$novos['billing_pagou_document'] = array(
					'label'       => __( 'CPF ou CNPJ', 'pagou-payments-for-woocommerce' ),
					'required'    => false,
					'class'       => array( 'form-row-wide' ),
					'priority'    => 25,
					'custom_attributes' => array(
						'inputmode' => 'numeric',
						'maxlength' => 18,
					),
				);
			}

			if ( 'billing_address_1' === $chave ) {
				$novos['billing_number'] = array(
					'label'    => __( 'Número', 'pagou-payments-for-woocommerce' ),
					'required' => false,
					'class'    => array( 'form-row-first', 'pagou-somente-br' ),
					'priority' => 55,
				);
				$novos['billing_neighborhood'] = array(
					'label'    => __( 'Bairro', 'pagou-payments-for-woocommerce' ),
					'required' => false,
					'class'    => array( 'form-row-last', 'pagou-somente-br' ),
					'priority' => 56,
				);
			}
		}

		return $novos;
	}

	/**
	 * Exibe os campos na edição do pedido no admin.
	 *
	 * @param array $campos
	 * @return array
	 */
	public static function campos_admin( $campos ) {
		$campos['pagou_document'] = array(
			'label' => __( 'CPF ou CNPJ', 'pagou-payments-for-woocommerce' ),
			'show'  => true,
		);
		$campos['number']         = array(
			'label' => __( 'Número', 'pagou-payments-for-woocommerce' ),
			'show'  => false,
		);
		$campos['neighborhood']   = array(
			'label' => __( 'Bairro', 'pagou-payments-for-woocommerce' ),
			'show'  => false,
		);

		return $campos;
	}

	/**
	 * Validação no checkout clássico.
	 *
	 * @param array    $dados
	 * @param WP_Error $erros
	 */
	public static function validar_classico( $dados, $erros ) {
		$metodo = isset( $dados['payment_method'] ) ? $dados['payment_method'] : '';

		if ( ! in_array( $metodo, self::GATEWAYS, true ) ) {
			return;
		}

		// Com o Brazilian Market ativo, ele já valida os próprios campos.
		if ( self::brazilian_market_ativo() ) {
			return;
		}

		$documento = isset( $dados['billing_pagou_document'] ) ? $dados['billing_pagou_document'] : '';

		if ( ! Document::valido( $documento ) ) {
			$erros->add( 'pagou_documento', __( 'Informe um CPF ou CNPJ válido para pagar com a Pagou.', 'pagou-payments-for-woocommerce' ) );
		}

		$pais = isset( $dados['billing_country'] ) ? $dados['billing_country'] : 'BR';

		if ( 'BR' === $pais ) {
			if ( empty( $dados['billing_number'] ) ) {
				$erros->add( 'pagou_numero', __( 'Informe o número do endereço.', 'pagou-payments-for-woocommerce' ) );
			}
			if ( empty( $dados['billing_neighborhood'] ) ) {
				$erros->add( 'pagou_bairro', __( 'Informe o bairro.', 'pagou-payments-for-woocommerce' ) );
			}
		}
	}
}
