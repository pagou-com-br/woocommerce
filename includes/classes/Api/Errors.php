<?php
/**
 * Tradução de erros da API para mensagens que o cliente da loja entende.
 *
 * Detalhe técnico vai para o log; ao cliente vai só o que ele pode corrigir.
 *
 * @package Pagou
 */

namespace Pagou\WooCommerce\Api;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Errors {

	/**
	 * Nomes dos campos da API em português.
	 *
	 * @return array
	 */
	private static function campos() {
		return array(
			'Name'         => __( 'Nome completo', 'pagou-payments-for-woocommerce' ),
			'Document'     => __( 'CPF/CNPJ', 'pagou-payments-for-woocommerce' ),
			'Zip'          => __( 'CEP', 'pagou-payments-for-woocommerce' ),
			'ZipCode'      => __( 'CEP', 'pagou-payments-for-woocommerce' ),
			'Street'       => __( 'Rua', 'pagou-payments-for-woocommerce' ),
			'City'         => __( 'Cidade', 'pagou-payments-for-woocommerce' ),
			'State'        => __( 'Estado', 'pagou-payments-for-woocommerce' ),
			'Number'       => __( 'Número', 'pagou-payments-for-woocommerce' ),
			'Neighborhood' => __( 'Bairro', 'pagou-payments-for-woocommerce' ),
			'Email'        => __( 'E-mail', 'pagou-payments-for-woocommerce' ),
			'Phone'        => __( 'Telefone', 'pagou-payments-for-woocommerce' ),
		);
	}

	/**
	 * Mensagens por código de regra de negócio.
	 *
	 * @return array
	 */
	private static function codigos() {
		return array(
			'merchant_not_accredited' => __( 'Pagamento com cartão indisponível nesta loja no momento. Escolha outro meio de pagamento.', 'pagou-payments-for-woocommerce' ),
			'card_expired'            => __( 'O cartão está vencido. Use outro cartão.', 'pagou-payments-for-woocommerce' ),
			'document_type_mismatch'  => __( 'O CPF/CNPJ informado não confere. Revise o documento.', 'pagou-payments-for-woocommerce' ),
			'installments_exceed_max' => __( 'Este cartão não aceita essa quantidade de parcelas. Escolha menos parcelas.', 'pagou-payments-for-woocommerce' ),
		);
	}

	/**
	 * Mensagem para o cliente final a partir de uma resposta de erro.
	 *
	 * @param Response $resposta
	 * @param string   $padrao Mensagem quando não há tradução específica.
	 * @return string
	 */
	public static function para_cliente( Response $resposta, $padrao = '' ) {
		$codigos = self::codigos();

		if ( isset( $codigos[ $resposta->codigo() ] ) ) {
			return $codigos[ $resposta->codigo() ];
		}

		if ( 401 === $resposta->http ) {
			return __( 'Não foi possível processar o pagamento agora. A loja já foi avisada; tente novamente em instantes ou escolha outro meio de pagamento.', 'pagou-payments-for-woocommerce' );
		}

		$erros = $resposta->erros_validacao();

		if ( ! empty( $erros ) ) {
			return sprintf(
				/* translators: %s: lista de campos */
				__( 'Revise os seguintes dados: %s.', 'pagou-payments-for-woocommerce' ),
				self::listar( $erros )
			);
		}

		if ( '' !== $padrao ) {
			return $padrao;
		}

		return __( 'Não foi possível processar o pagamento. Tente novamente em instantes.', 'pagou-payments-for-woocommerce' );
	}

	/**
	 * "Name is invalid", "Zip is invalid" viram "Nome completo e CEP".
	 *
	 * @param string[] $erros
	 * @return string
	 */
	public static function listar( array $erros ) {
		$campos    = self::campos();
		$traduzido = array();

		foreach ( $erros as $erro ) {
			$nome        = trim( preg_replace( '/\s+is (invalid|required|empty)\.?$/i', '', $erro ) );
			$traduzido[] = isset( $campos[ $nome ] ) ? $campos[ $nome ] : $nome;
		}

		$traduzido = array_values( array_unique( $traduzido ) );

		if ( count( $traduzido ) < 2 ) {
			return implode( '', $traduzido );
		}

		$ultimo = array_pop( $traduzido );

		return implode( ', ', $traduzido ) . ' ' . __( 'e', 'pagou-payments-for-woocommerce' ) . ' ' . $ultimo;
	}
}
