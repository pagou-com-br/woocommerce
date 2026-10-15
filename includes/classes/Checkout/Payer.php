<?php
/**
 * Dados do pagador a partir do pedido.
 *
 * Procura o documento, o número e o bairro em todas as origens conhecidas: campos nativos
 * do plugin (blocos e clássico), Brazilian Market, pedidos antigos da 1.x e campos de
 * outros plugins com nomes reconhecíveis.
 *
 * @package Pagou
 */

namespace Pagou\WooCommerce\Checkout;

use Pagou\WooCommerce\Document;
use WC_Order;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Payer {

	/**
	 * Valor de um campo adicional do checkout em blocos.
	 *
	 * @param WC_Order $order
	 * @param string   $campo ID do campo (ex.: pagou/document).
	 * @param string   $grupo 'billing' para campos de endereço, 'other' para contato.
	 * @return string
	 */
	public static function campo_blocos( WC_Order $order, $campo, $grupo ) {
		$servico = self::servico_campos();

		if ( $servico && method_exists( $servico, 'get_field_from_object' ) ) {
			$valor = $servico->get_field_from_object( $campo, $order, $grupo );
			if ( is_scalar( $valor ) && '' !== (string) $valor ) {
				return (string) $valor;
			}
		}

		$valor = $order->get_meta( '_wc_' . $grupo . '/' . $campo );

		return is_scalar( $valor ) ? (string) $valor : '';
	}

	/**
	 * @return object|null Serviço CheckoutFields do WooCommerce.
	 */
	private static function servico_campos() {
		static $servico = false;

		if ( false === $servico ) {
			$servico = null;
			if ( class_exists( '\Automattic\WooCommerce\Blocks\Package' ) && class_exists( '\Automattic\WooCommerce\Blocks\Domain\Services\CheckoutFields' ) ) {
				try {
					$servico = \Automattic\WooCommerce\Blocks\Package::container()->get( \Automattic\WooCommerce\Blocks\Domain\Services\CheckoutFields::class );
				} catch ( \Exception $e ) {
					$servico = null;
				}
			}
		}

		return $servico;
	}

	/**
	 * CPF ou CNPJ do pagador (somente dígitos), ou '' se não houver um válido.
	 *
	 * @param WC_Order $order
	 * @return string
	 */
	public static function documento( WC_Order $order ) {
		$candidatos = array(
			self::campo_blocos( $order, BrazilianFields::CAMPO_DOCUMENTO, 'other' ),
			$order->get_meta( '_billing_pagou_document' ),
			$order->get_meta( '_billing_cpf' ),
			$order->get_meta( '_billing_cnpj' ),
			$order->get_meta( '_billing_document' ),
		);

		// Campos de outros plugins com nome reconhecível.
		foreach ( $order->get_meta_data() as $meta ) {
			$dados = $meta->get_data();
			$chave = isset( $dados['key'] ) ? (string) $dados['key'] : '';
			if ( preg_match( '/(cpf|cnpj|document)/i', $chave ) && is_scalar( $dados['value'] ) ) {
				$candidatos[] = $dados['value'];
			}
		}

		// Brazilian Market com tipo de pessoa: respeita a escolha (PF usa CPF, PJ usa CNPJ).
		$tipo_pessoa = (string) $order->get_meta( '_billing_persontype' );
		if ( '2' === $tipo_pessoa ) {
			array_unshift( $candidatos, $order->get_meta( '_billing_cnpj' ) );
		}

		foreach ( $candidatos as $candidato ) {
			if ( is_scalar( $candidato ) && Document::valido( (string) $candidato ) ) {
				return Document::digitos( $candidato );
			}
		}

		return '';
	}

	/**
	 * Nome do pagador: razão social para CNPJ (quando informada), nome completo para CPF.
	 *
	 * @param WC_Order $order
	 * @param string   $documento
	 * @return string
	 */
	public static function nome( WC_Order $order, $documento ) {
		$pessoa  = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		$empresa = trim( (string) $order->get_billing_company() );

		if ( 14 === strlen( $documento ) && '' !== $empresa ) {
			return $empresa;
		}

		return '' !== $pessoa ? $pessoa : $empresa;
	}

	/**
	 * Número do endereço de cobrança.
	 *
	 * @param WC_Order $order
	 * @return string
	 */
	public static function numero( WC_Order $order ) {
		$candidatos = array(
			self::campo_blocos( $order, BrazilianFields::CAMPO_NUMERO, 'billing' ),
			$order->get_meta( '_billing_number' ),
		);

		foreach ( $candidatos as $candidato ) {
			if ( is_scalar( $candidato ) && '' !== trim( (string) $candidato ) ) {
				return trim( (string) $candidato );
			}
		}

		// Último recurso: "Rua Exemplo, 123" no próprio endereço.
		if ( preg_match( '/,\s*(\d+[A-Za-z]?)\b/', (string) $order->get_billing_address_1(), $m ) ) {
			return $m[1];
		}

		return 'S/N';
	}

	/**
	 * Bairro do endereço de cobrança.
	 *
	 * @param WC_Order $order
	 * @return string
	 */
	public static function bairro( WC_Order $order ) {
		$candidatos = array(
			self::campo_blocos( $order, BrazilianFields::CAMPO_BAIRRO, 'billing' ),
			$order->get_meta( '_billing_neighborhood' ),
		);

		foreach ( $candidatos as $candidato ) {
			if ( is_scalar( $candidato ) && '' !== trim( (string) $candidato ) ) {
				return trim( (string) $candidato );
			}
		}

		return '';
	}

	/**
	 * Telefone somente com dígitos, sem o DDI 55 quando presente.
	 *
	 * @param WC_Order $order
	 * @return string
	 */
	public static function telefone( WC_Order $order ) {
		$telefone = Document::digitos( $order->get_billing_phone() );

		if ( strlen( $telefone ) > 11 && 0 === strpos( $telefone, '55' ) ) {
			$telefone = substr( $telefone, 2 );
		}

		return $telefone;
	}

	/**
	 * Endereço de cobrança no formato usado pela API de cartão.
	 *
	 * @param WC_Order $order
	 * @return array
	 */
	public static function endereco( WC_Order $order ) {
		$endereco = array(
			'zip_code'     => Document::digitos( $order->get_billing_postcode() ),
			'street'       => (string) $order->get_billing_address_1(),
			'number'       => self::numero( $order ),
			'neighborhood' => self::bairro( $order ),
			'city'         => (string) $order->get_billing_city(),
			'state'        => (string) $order->get_billing_state(),
		);

		$complemento = trim( (string) $order->get_billing_address_2() );
		if ( '' !== $complemento ) {
			$endereco['complement'] = $complemento;
		}

		return $endereco;
	}

	/**
	 * Campos obrigatórios ausentes para um meio de pagamento, já em português.
	 *
	 * @param WC_Order $order
	 * @param bool     $exige_endereco
	 * @param bool     $exige_contato  E-mail e telefone (cartão).
	 * @return string[]
	 */
	public static function faltando( WC_Order $order, $exige_endereco, $exige_contato = false ) {
		$faltando  = array();
		$documento = self::documento( $order );

		if ( '' === $documento ) {
			$faltando[] = __( 'CPF ou CNPJ válido', 'pagou-payments-for-woocommerce' );
		}

		if ( '' === self::nome( $order, $documento ) ) {
			$faltando[] = __( 'nome completo', 'pagou-payments-for-woocommerce' );
		}

		if ( $exige_endereco ) {
			$endereco = self::endereco( $order );
			if ( 8 !== strlen( $endereco['zip_code'] ) ) {
				$faltando[] = __( 'CEP', 'pagou-payments-for-woocommerce' );
			}
			if ( '' === $endereco['street'] ) {
				$faltando[] = __( 'endereço', 'pagou-payments-for-woocommerce' );
			}
			if ( '' === $endereco['neighborhood'] ) {
				$faltando[] = __( 'bairro', 'pagou-payments-for-woocommerce' );
			}
			if ( '' === $endereco['city'] ) {
				$faltando[] = __( 'cidade', 'pagou-payments-for-woocommerce' );
			}
			if ( 2 !== strlen( $endereco['state'] ) ) {
				$faltando[] = __( 'estado', 'pagou-payments-for-woocommerce' );
			}
		}

		if ( $exige_contato ) {
			if ( ! is_email( $order->get_billing_email() ) ) {
				$faltando[] = __( 'e-mail', 'pagou-payments-for-woocommerce' );
			}
			$telefone = self::telefone( $order );
			if ( strlen( $telefone ) < 10 || strlen( $telefone ) > 14 ) {
				$faltando[] = __( 'telefone com DDD', 'pagou-payments-for-woocommerce' );
			}
		}

		return $faltando;
	}
}
