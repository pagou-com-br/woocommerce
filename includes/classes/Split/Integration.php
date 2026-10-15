<?php
/**
 * Split de pagamento: configuração, aplicação na cobrança e acompanhamento.
 *
 * @package Pagou
 */

namespace Pagou\WooCommerce\Split;

use Pagou\WooCommerce\Api\Client;
use Pagou\WooCommerce\Api\Response;
use Pagou\WooCommerce\Logger;
use Pagou\WooCommerce\Money;
use Pagou\WooCommerce\Plugin;
use Pagou\WooCommerce\Settings;
use WC_Order;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Integration {

	public static function init() {
		add_filter( 'pagou_wc_corpo_cobranca', array( __CLASS__, 'aplicar' ), 10, 3 );
		add_action( 'pagou_wc_split_recusado', array( __CLASS__, 'recusado' ), 10, 3 );

		if ( is_admin() ) {
			add_filter( 'pagou_wc_campos_configuracao', array( __CLASS__, 'campos' ) );
			add_action( 'woocommerce_admin_field_pagou_split_regras', array( __CLASS__, 'campo_regras' ) );
			add_filter( 'woocommerce_product_data_tabs', array( __CLASS__, 'aba_produto' ) );
			add_action( 'woocommerce_product_data_panels', array( __CLASS__, 'painel_produto' ) );
			add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'salvar_produto' ) );
			add_filter( 'pagou_wc_painel_pedido', array( __CLASS__, 'linhas_pedido' ), 10, 2 );
			add_action( 'admin_post_pagou_wc_consultar_split', array( __CLASS__, 'consultar' ) );
		}
	}

	/**
	 * Acrescenta o split ao corpo da cobrança.
	 *
	 * @param array    $corpo
	 * @param WC_Order $order
	 * @param string   $unidade
	 * @return array
	 */
	public static function aplicar( $corpo, $order, $unidade ) {
		if ( ! Rules::ativo() || ! in_array( $order->get_payment_method(), Rules::meios(), true ) ) {
			return $corpo;
		}

		$resultado = Calculator::calcular( $order );

		if ( '' !== $resultado['aviso'] ) {
			$order->add_order_note( 'Pagou (split): ' . $resultado['aviso'] );
		}

		if ( empty( $resultado['split'] ) ) {
			return $corpo;
		}

		// O valor fixo do split vai em reais em todos os meios, inclusive no cartão.
		$corpo['split'] = $resultado['split'];

		$order->update_meta_data( '_pagou_split_enviado', $resultado['split'] );
		$order->save();

		Logger::log( 'Split aplicado à cobrança.', array( 'pedido' => $order->get_id(), 'split' => $resultado['split'], 'unidade' => $unidade ) );

		return $corpo;
	}

	/**
	 * A Pagou recusou o split: a venda segue sem ele e o lojista é avisado.
	 *
	 * @param WC_Order $order
	 * @param Response $resposta
	 * @param array    $split
	 */
	public static function recusado( $order, $resposta, $split ) {
		$erro = $resposta->erro();

		$order->delete_meta_data( '_pagou_split_enviado' );
		$order->update_meta_data( '_pagou_split_recusado', $erro );
		$order->add_order_note(
			sprintf(
				/* translators: %s: erro da Pagou */
				__( 'Pagou (split): a divisão do pagamento foi recusada (%s). A cobrança foi criada sem split; o valor inteiro fica na conta da loja e o repasse aos recebedores precisa ser feito manualmente.', 'pagou-payments-for-woocommerce' ),
				$erro
			)
		);
		$order->save();

		Plugin::avisar_admin(
			'split_recusado',
			sprintf(
				/* translators: 1: link do pedido, 2: número do pedido, 3: erro */
				__( 'O split foi recusado no <a href="%1$s">pedido #%2$s</a> (%3$s). A venda foi concluída sem split. Confira os recebedores autorizados em Configurações &gt; Split no painel da Pagou.', 'pagou-payments-for-woocommerce' ),
				esc_url( $order->get_edit_order_url() ),
				esc_html( $order->get_order_number() ),
				esc_html( $erro )
			),
			'warning'
		);

		Logger::aviso( 'Split recusado; cobrança refeita sem split.', array( 'pedido' => $order->get_id(), 'erro' => $erro, 'split' => $split ) );
	}

	/**
	 * Seção de split na aba Pagou.
	 *
	 * @param array $campos
	 * @return array
	 */
	public static function campos( $campos ) {
		$opcao = Settings::OPCAO;

		return array_merge(
			$campos,
			array(
				array(
					'title' => __( 'Split de pagamento', 'pagou-payments-for-woocommerce' ),
					'type'  => 'title',
					'desc'  => __( 'Divide automaticamente cada venda com outras contas Pagou (sócios, parceiros, fornecedores). A sua conta precisa estar aprovada para split pela Pagou e cada recebedor precisa estar autorizado no painel da Pagou, em Configurações &gt; Split. Se a Pagou recusar o split de uma venda, ela é cobrada sem split e você recebe um aviso aqui no painel.', 'pagou-payments-for-woocommerce' ),
					'id'    => 'pagou_split',
				),
				array(
					'title'   => __( 'Ativar split', 'pagou-payments-for-woocommerce' ),
					'id'      => $opcao . '[split_enabled]',
					'type'    => 'checkbox',
					'default' => 'no',
					'desc'    => __( 'Aplicar as regras de split nas cobranças', 'pagou-payments-for-woocommerce' ),
				),
				array(
					'title'   => __( 'Meios de pagamento', 'pagou-payments-for-woocommerce' ),
					'id'      => $opcao . '[split_methods]',
					'type'    => 'multiselect',
					'class'   => 'wc-enhanced-select',
					'default' => array( 'pagou_pix', 'pagou_boleto', 'pagou_cartao' ),
					'options' => array(
						'pagou_pix'    => 'Pix',
						'pagou_boleto' => __( 'Boleto', 'pagou-payments-for-woocommerce' ),
						'pagou_cartao' => __( 'Cartão de crédito', 'pagou-payments-for-woocommerce' ),
					),
				),
				array(
					'title'   => __( 'Percentual calculado sobre', 'pagou-payments-for-woocommerce' ),
					'id'      => $opcao . '[split_base]',
					'type'    => 'select',
					'default' => 'produtos',
					'options' => array(
						'produtos' => __( 'Somente os produtos (sem frete)', 'pagou-payments-for-woocommerce' ),
						'pedido'   => __( 'O pedido inteiro (produtos e frete)', 'pagou-payments-for-woocommerce' ),
					),
					'desc'    => __( 'Vale para as regras abaixo. Juros do parcelamento ficam sempre com a loja.', 'pagou-payments-for-woocommerce' ),
				),
				array(
					'title' => __( 'Recebedores (regra geral)', 'pagou-payments-for-woocommerce' ),
					'id'    => $opcao . '[split_rules]',
					'type'  => 'pagou_split_regras',
					'desc'  => __( 'Valor fixo: uma vez por pedido. Cada produto pode usar esta regra, ter regra própria ou ficar fora do split (aba "Split Pagou" do produto).', 'pagou-payments-for-woocommerce' ),
				),
				array(
					'type' => 'sectionend',
					'id'   => 'pagou_split',
				),
			)
		);
	}

	/**
	 * Tabela de recebedores (configurações gerais).
	 *
	 * @param array $campo
	 */
	public static function campo_regras( $campo ) {
		?>
		<tr valign="top">
			<th scope="row" class="titledesc"><?php echo esc_html( $campo['title'] ); ?></th>
			<td class="forminp">
				<?php self::tabela( $campo['id'], Rules::globais(), __( 'por pedido', 'pagou-payments-for-woocommerce' ) ); ?>
				<p class="description"><?php echo esc_html( $campo['desc'] ); ?></p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Tabela editável de regras, usada nas configurações e no produto.
	 *
	 * @param string $nome         Prefixo do name dos campos.
	 * @param array  $regras
	 * @param string $sufixo_fixo  Ex.: "por pedido" ou "por unidade".
	 */
	public static function tabela( $nome, array $regras, $sufixo_fixo ) {
		$regras[] = array(
			'label'       => '',
			'customer_id' => '',
			'type'        => 'percentage',
			'value'       => '',
		);
		?>
		<table class="widefat pagou-split-tabela" style="max-width:860px;">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Recebedor (apelido)', 'pagou-payments-for-woocommerce' ); ?></th>
					<th><?php esc_html_e( 'ID da conta Pagou (UUID)', 'pagou-payments-for-woocommerce' ); ?></th>
					<th><?php esc_html_e( 'Tipo', 'pagou-payments-for-woocommerce' ); ?></th>
					<th><?php esc_html_e( 'Valor', 'pagou-payments-for-woocommerce' ); ?></th>
					<th></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( array_values( $regras ) as $i => $regra ) : ?>
					<tr>
						<td><input type="text" style="width:100%" name="<?php echo esc_attr( $nome . '[' . $i . '][label]' ); ?>" value="<?php echo esc_attr( $regra['label'] ); ?>" placeholder="<?php esc_attr_e( 'Ex.: Sócio', 'pagou-payments-for-woocommerce' ); ?>"></td>
						<td><input type="text" style="width:100%;font-family:monospace" name="<?php echo esc_attr( $nome . '[' . $i . '][customer_id]' ); ?>" value="<?php echo esc_attr( $regra['customer_id'] ); ?>" placeholder="00000000-0000-4000-8000-000000000000" pattern="[0-9a-fA-F-]{36}"></td>
						<td>
							<select name="<?php echo esc_attr( $nome . '[' . $i . '][type]' ); ?>">
								<option value="percentage" <?php selected( $regra['type'], 'percentage' ); ?>><?php esc_html_e( 'Percentual (%)', 'pagou-payments-for-woocommerce' ); ?></option>
								<option value="fixed" <?php selected( $regra['type'], 'fixed' ); ?>>
									<?php
									/* translators: %s: por pedido / por unidade */
									echo esc_html( sprintf( __( 'Fixo em R$ (%s)', 'pagou-payments-for-woocommerce' ), $sufixo_fixo ) );
									?>
								</option>
							</select>
						</td>
						<td><input type="text" style="width:90px" inputmode="decimal" name="<?php echo esc_attr( $nome . '[' . $i . '][value]' ); ?>" value="<?php echo esc_attr( '' === $regra['value'] ? '' : wc_format_localized_decimal( $regra['value'] ) ); ?>"></td>
						<td><button type="button" class="button-link pagou-split-remover" aria-label="<?php esc_attr_e( 'Remover', 'pagou-payments-for-woocommerce' ); ?>">&times;</button></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p>
			<button type="button" class="button pagou-split-adicionar"><?php esc_html_e( 'Adicionar recebedor', 'pagou-payments-for-woocommerce' ); ?></button>
			<span class="description">
				<?php
				/* translators: %d: máximo de recebedores */
				echo esc_html( sprintf( __( 'Até %d recebedores por venda. Linhas incompletas ou com ID inválido são ignoradas ao salvar.', 'pagou-payments-for-woocommerce' ), Rules::MAX_RECEBEDORES ) );
				?>
			</span>
		</p>
		<script>
		( function () {
			if ( window.pagouSplitTabela ) { return; }
			window.pagouSplitTabela = true;
			document.addEventListener( 'click', function ( e ) {
				var alvo = e.target;
				if ( alvo.classList.contains( 'pagou-split-remover' ) ) {
					var linha = alvo.closest( 'tr' );
					var corpo = linha.parentNode;
					if ( corpo.rows.length > 1 ) { linha.remove(); } else { linha.querySelectorAll( 'input' ).forEach( function ( i ) { i.value = ''; } ); }
				}
				if ( alvo.classList.contains( 'pagou-split-adicionar' ) ) {
					var tabela = alvo.closest( 'p' ).previousElementSibling.tBodies[0];
					var modelo = tabela.rows[ tabela.rows.length - 1 ];
					var nova = modelo.cloneNode( true );
					var indice = Date.now();
					nova.querySelectorAll( 'input, select' ).forEach( function ( campo ) {
						campo.name = campo.name.replace( /\[\d+\](?=\[[a-z_]+\]$)/, '[' + indice + ']' );
						if ( campo.tagName === 'INPUT' ) { campo.value = ''; }
					} );
					tabela.appendChild( nova );
				}
			} );
		} )();
		</script>
		<?php
	}

	/**
	 * @param array $abas
	 * @return array
	 */
	public static function aba_produto( $abas ) {
		$abas['pagou_split'] = array(
			'label'    => __( 'Split Pagou', 'pagou-payments-for-woocommerce' ),
			'target'   => 'pagou_split_dados',
			'class'    => array(),
			'priority' => 80,
		);

		return $abas;
	}

	public static function painel_produto() {
		global $post;

		$produto_id = $post ? (int) $post->ID : 0;
		$modo       = Rules::modo_produto( $produto_id );
		?>
		<div id="pagou_split_dados" class="panel woocommerce_options_panel hidden">
			<div class="options_group" style="padding:12px;">
				<?php if ( ! Rules::ativo() ) : ?>
					<p class="description">
						<?php
						printf(
							/* translators: %s: link */
							wp_kses_post( __( 'O split está desligado. Ative em <a href="%s">WooCommerce &gt; Configurações &gt; Pagou</a>.', 'pagou-payments-for-woocommerce' ) ),
							esc_url( admin_url( 'admin.php?page=wc-settings&tab=pagou' ) )
						);
						?>
					</p>
				<?php endif; ?>
				<p class="form-field">
					<label for="pagou_split_modo"><?php esc_html_e( 'Split deste produto', 'pagou-payments-for-woocommerce' ); ?></label>
					<select id="pagou_split_modo" name="pagou_split_modo">
						<option value="global" <?php selected( $modo, 'global' ); ?>><?php esc_html_e( 'Usar a regra geral da loja', 'pagou-payments-for-woocommerce' ); ?></option>
						<option value="proprio" <?php selected( $modo, 'proprio' ); ?>><?php esc_html_e( 'Regra própria (abaixo)', 'pagou-payments-for-woocommerce' ); ?></option>
						<option value="nenhum" <?php selected( $modo, 'nenhum' ); ?>><?php esc_html_e( 'Sem split', 'pagou-payments-for-woocommerce' ); ?></option>
					</select>
				</p>
				<p class="description" style="margin:0 0 8px;"><?php esc_html_e( 'Na regra própria, o percentual incide sobre o valor do produto no pedido e o valor fixo é por unidade vendida. A regra própria substitui a geral para este produto.', 'pagou-payments-for-woocommerce' ); ?></p>
				<?php self::tabela( 'pagou_split_regras', Rules::do_produto( $produto_id ), __( 'por unidade', 'pagou-payments-for-woocommerce' ) ); ?>
				<?php wp_nonce_field( 'pagou_split_produto', 'pagou_split_nonce' ); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * @param \WC_Product $produto
	 */
	public static function salvar_produto( $produto ) {
		if ( ! isset( $_POST['pagou_split_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['pagou_split_nonce'] ) ), 'pagou_split_produto' ) ) {
			return;
		}

		$modo = isset( $_POST['pagou_split_modo'] ) ? sanitize_key( wp_unslash( $_POST['pagou_split_modo'] ) ) : 'global';
		$produto->update_meta_data( '_pagou_split_modo', in_array( $modo, array( 'proprio', 'nenhum' ), true ) ? $modo : 'global' );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validado campo a campo em Rules::sanitizar().
		$regras = isset( $_POST['pagou_split_regras'] ) && is_array( $_POST['pagou_split_regras'] ) ? wp_unslash( $_POST['pagou_split_regras'] ) : array();
		$produto->update_meta_data( '_pagou_split_regras', Rules::sanitizar( $regras ) );
	}

	/**
	 * Dados do split no painel do pedido.
	 *
	 * @param array    $linhas
	 * @param WC_Order $order
	 * @return array
	 */
	public static function linhas_pedido( $linhas, $order ) {
		$recusado = (string) $order->get_meta( '_pagou_split_recusado' );

		if ( '' !== $recusado ) {
			$linhas[ __( 'Split', 'pagou-payments-for-woocommerce' ) ] = sprintf(
				/* translators: %s: erro */
				__( 'recusado pela Pagou (%s); venda sem split', 'pagou-payments-for-woocommerce' ),
				$recusado
			);
			return $linhas;
		}

		$enviado = $order->get_meta( '_pagou_split_enviado' );

		if ( empty( $enviado ) || ! is_array( $enviado ) ) {
			return $linhas;
		}

		$resposta = self::split_da_resposta( $order );
		$status   = isset( $resposta['status'] ) ? (int) $resposta['status'] : 0;
		$nomes    = array(
			1 => __( 'registrado, aguardando pagamento', 'pagou-payments-for-woocommerce' ),
			2 => __( 'repasse em andamento', 'pagou-payments-for-woocommerce' ),
			3 => __( 'cancelado', 'pagou-payments-for-woocommerce' ),
			4 => __( 'repasses concluídos', 'pagou-payments-for-woocommerce' ),
			5 => __( 'revertido', 'pagou-payments-for-woocommerce' ),
			6 => __( 'ação necessária (fale com a Pagou)', 'pagou-payments-for-woocommerce' ),
		);

		$linhas[ __( 'Split', 'pagou-payments-for-woocommerce' ) ] = isset( $nomes[ $status ] ) ? $nomes[ $status ] : __( 'enviado', 'pagou-payments-for-woocommerce' );

		$rotulos = self::rotulos_conhecidos();
		foreach ( $enviado as $item ) {
			$uuid  = $item['customer_id'];
			$nome  = isset( $rotulos[ $uuid ] ) && '' !== $rotulos[ $uuid ] ? $rotulos[ $uuid ] : substr( $uuid, 0, 8 ) . '…';
			$valor = wp_strip_all_tags( Money::exibir( Money::centavos( $item['value'] ) ) );
			/* translators: %s: recebedor */
			$linhas[ sprintf( __( 'Recebedor %s', 'pagou-payments-for-woocommerce' ), $nome ) ] = $valor;
		}

		$linhas[ __( 'Atualizar split', 'pagou-payments-for-woocommerce' ) ] = wp_nonce_url( admin_url( 'admin-post.php?action=pagou_wc_consultar_split&pedido=' . $order->get_id() ), 'pagou_wc_consultar_split' );

		return $linhas;
	}

	/**
	 * Objeto split da última resposta guardada no pedido.
	 *
	 * @param WC_Order $order
	 * @return array
	 */
	private static function split_da_resposta( WC_Order $order ) {
		foreach ( array( '_pagou_split_resposta', '_pagou_cartao_response', '_pagou_pix_response', '_pagou_boleto_response' ) as $meta ) {
			$dados = $order->get_meta( $meta );
			if ( '_pagou_split_resposta' === $meta && is_array( $dados ) && ! empty( $dados ) ) {
				return $dados;
			}
			if ( is_array( $dados ) && isset( $dados['split'] ) && is_array( $dados['split'] ) ) {
				return $dados['split'];
			}
		}

		return array();
	}

	/**
	 * Apelidos dos recebedores (regras globais e de produtos dos itens).
	 *
	 * @return array uuid => apelido
	 */
	private static function rotulos_conhecidos() {
		$rotulos = array();

		foreach ( Rules::globais() as $regra ) {
			$rotulos[ $regra['customer_id'] ] = $regra['label'];
		}

		return $rotulos;
	}

	/**
	 * Consulta a cobrança na Pagou e guarda o estado atual do split.
	 */
	public static function consultar() {
		check_admin_referer( 'pagou_wc_consultar_split' );

		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_die( esc_html__( 'Sem permissão.', 'pagou-payments-for-woocommerce' ) );
		}

		$order = wc_get_order( isset( $_GET['pedido'] ) ? absint( $_GET['pedido'] ) : 0 );

		if ( $order ) {
			$caminhos = array(
				'_pagou_cartao_id' => '/v1/creditcard/charges/',
				'_pagou_pix_id'    => '/v1/pix/',
				'_pagou_boleto_id' => '/v1/charges/',
			);

			foreach ( $caminhos as $meta => $caminho ) {
				$id = (string) $order->get_meta( $meta );
				if ( '' === $id ) {
					continue;
				}
				$resposta = ( new Client() )->get( $caminho . rawurlencode( $id ) );
				if ( $resposta->ok() && isset( $resposta->body['split'] ) && is_array( $resposta->body['split'] ) ) {
					$order->update_meta_data( '_pagou_split_resposta', $resposta->body['split'] );
					$order->save();
				}
				break;
			}
		}

		wp_safe_redirect( $order ? $order->get_edit_order_url() : admin_url() );
		exit;
	}
}
