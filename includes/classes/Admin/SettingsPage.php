<?php
/**
 * Aba WooCommerce > Configurações > Pagou: credenciais e opções gerais.
 *
 * @package Pagou
 */

namespace Pagou\WooCommerce\Admin;

use Pagou\WooCommerce\Api\Client;
use Pagou\WooCommerce\Gateway\AbstractGateway;
use Pagou\WooCommerce\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SettingsPage {

	const ABA = 'pagou';

	public static function init() {
		add_filter( 'woocommerce_settings_tabs_array', array( __CLASS__, 'aba' ), 60 );
		add_action( 'woocommerce_settings_tabs_' . self::ABA, array( __CLASS__, 'exibir' ) );
		add_action( 'woocommerce_update_options_' . self::ABA, array( __CLASS__, 'salvar' ) );
		add_action( 'woocommerce_admin_field_pagou_info', array( __CLASS__, 'campo_info' ) );
		add_action( 'wp_ajax_pagou_wc_testar', array( __CLASS__, 'testar_conexao' ) );
	}

	/**
	 * @param array $abas
	 * @return array
	 */
	public static function aba( $abas ) {
		$abas[ self::ABA ] = 'Pagou';
		return $abas;
	}

	/**
	 * Campos da aba. Seções extras (como o split) entram pelo filtro.
	 *
	 * @return array
	 */
	public static function campos() {
		$opcao = Settings::OPCAO;

		$campos = array(
			array(
				'title' => __( 'Conta Pagou', 'pagou-payments-for-woocommerce' ),
				'type'  => 'title',
				'desc'  => __( 'Credenciais e ambiente valem para todos os meios de pagamento da Pagou (Pix, boleto e cartão).', 'pagou-payments-for-woocommerce' ),
				'id'    => 'pagou_conta',
			),
			array(
				'title'   => __( 'Ambiente', 'pagou-payments-for-woocommerce' ),
				'id'      => $opcao . '[environment]',
				'type'    => 'select',
				'default' => 'pro',
				'options' => array(
					'pro' => __( 'Produção', 'pagou-payments-for-woocommerce' ),
					'hom' => __( 'Sandbox (testes, api.pagou.cloud)', 'pagou-payments-for-woocommerce' ),
				),
				'desc_tip' => __( 'No sandbox nenhuma cobrança é real. Use a chave de API da conta de sandbox.', 'pagou-payments-for-woocommerce' ),
			),
		);

		if ( Settings::api_key_por_constante() ) {
			$campos[] = array(
				'title' => __( 'Chave de API', 'pagou-payments-for-woocommerce' ),
				'type'  => 'pagou_info',
				'id'    => 'pagou_api_key_constante',
				'desc'  => __( 'Definida pela constante PAGOU_API_KEY no wp-config.php.', 'pagou-payments-for-woocommerce' ),
			);
		} else {
			$campos[] = array(
				'title'             => __( 'Chave de API', 'pagou-payments-for-woocommerce' ),
				'id'                => $opcao . '[api_key]',
				'type'              => 'password',
				'default'           => '',
				'desc'              => __( 'Encontrada no painel da Pagou. Para tirá-la do banco de dados, defina a constante PAGOU_API_KEY no wp-config.php.', 'pagou-payments-for-woocommerce' ),
				'custom_attributes' => array( 'autocomplete' => 'off' ),
			);
		}

		$campos[] = array(
			'title' => __( 'Conexão', 'pagou-payments-for-woocommerce' ),
			'type'  => 'pagou_info',
			'id'    => 'pagou_testar',
			'desc'  => 'testar',
		);

		$campos[] = array(
			'title' => __( 'URL de notificação', 'pagou-payments-for-woocommerce' ),
			'type'  => 'pagou_info',
			'id'    => 'pagou_webhook',
			'desc'  => 'webhook',
		);

		$campos[] = array(
			'type' => 'sectionend',
			'id'   => 'pagou_conta',
		);

		$campos[] = array(
			'title' => __( 'Checkout', 'pagou-payments-for-woocommerce' ),
			'type'  => 'title',
			'id'    => 'pagou_checkout',
		);

		$campos[] = array(
			'title'   => __( 'Campos brasileiros', 'pagou-payments-for-woocommerce' ),
			'id'      => $opcao . '[brazilian_fields]',
			'type'    => 'select',
			'default' => 'auto',
			'options' => array(
				'auto' => __( 'Automático: adicionar CPF/CNPJ, número e bairro quando faltarem', 'pagou-payments-for-woocommerce' ),
				'off'  => __( 'Desligado: outro plugin fornece esses campos', 'pagou-payments-for-woocommerce' ),
			),
			'desc'    => __( 'Com o Brazilian Market on WooCommerce ativo, o checkout clássico usa os campos dele. O checkout em blocos sempre usa os campos da Pagou.', 'pagou-payments-for-woocommerce' ),
		);

		$campos[] = array(
			'title'   => __( 'IP do cliente', 'pagou-payments-for-woocommerce' ),
			'id'      => $opcao . '[ip_header]',
			'type'    => 'select',
			'default' => '',
			'options' => Settings::cabecalhos_ip(),
			'desc'    => __( 'O antifraude do cartão exige o IP real do comprador. Se a loja usa Cloudflare ou outro proxy, escolha o cabeçalho correspondente.', 'pagou-payments-for-woocommerce' ),
		);

		$campos[] = array(
			'type' => 'sectionend',
			'id'   => 'pagou_checkout',
		);

		/**
		 * Seções adicionais na aba da Pagou.
		 *
		 * @param array $campos
		 */
		$campos = apply_filters( 'pagou_wc_campos_configuracao', $campos );

		$campos[] = array(
			'title' => __( 'Diagnóstico', 'pagou-payments-for-woocommerce' ),
			'type'  => 'title',
			'id'    => 'pagou_diagnostico',
		);

		$campos[] = array(
			'title'   => __( 'Modo debug', 'pagou-payments-for-woocommerce' ),
			'id'      => $opcao . '[debug]',
			'type'    => 'checkbox',
			'default' => 'no',
			'desc'    => sprintf(
				/* translators: %s: link para os logs */
				__( 'Registrar chamadas à API e webhooks em <a href="%s">WooCommerce &gt; Status &gt; Logs</a> (fonte "pagou"). Erros são sempre registrados.', 'pagou-payments-for-woocommerce' ),
				esc_url( admin_url( 'admin.php?page=wc-status&tab=logs&source=pagou' ) )
			),
		);

		$campos[] = array(
			'type' => 'sectionend',
			'id'   => 'pagou_diagnostico',
		);

		return $campos;
	}

	public static function exibir() {
		self::carregar_wc_admin();
		\WC_Admin_Settings::output_fields( self::campos() );
	}

	public static function salvar() {
		self::carregar_wc_admin();
		\WC_Admin_Settings::save_fields( self::campos() );
		Settings::recarregar();
	}

	/**
	 * Garante a classe de configurações do WooCommerce (carregada só em telas do admin).
	 */
	private static function carregar_wc_admin() {
		if ( ! class_exists( 'WC_Admin_Settings', false ) && defined( 'WC_ABSPATH' ) ) {
			include_once WC_ABSPATH . 'includes/admin/class-wc-admin-settings.php';
		}
	}

	/**
	 * Campos informativos (constante, testar conexão, URL do webhook).
	 *
	 * @param array $campo
	 */
	public static function campo_info( $campo ) {
		?>
		<tr valign="top">
			<th scope="row" class="titledesc"><?php echo esc_html( $campo['title'] ); ?></th>
			<td class="forminp">
				<?php if ( 'testar' === $campo['desc'] ) : ?>
					<button type="button" class="button" id="pagou-testar" data-nonce="<?php echo esc_attr( wp_create_nonce( 'pagou_wc_testar' ) ); ?>">
						<?php esc_html_e( 'Testar conexão', 'pagou-payments-for-woocommerce' ); ?>
					</button>
					<span id="pagou-testar-resultado" style="margin-left:8px;"></span>
					<p class="description"><?php esc_html_e( 'Salve a chave antes de testar.', 'pagou-payments-for-woocommerce' ); ?></p>
					<script>
					( function () {
						var botao = document.getElementById( 'pagou-testar' );
						if ( ! botao ) { return; }
						botao.addEventListener( 'click', function () {
							var saida = document.getElementById( 'pagou-testar-resultado' );
							saida.textContent = '…';
							var dados = new FormData();
							dados.append( 'action', 'pagou_wc_testar' );
							dados.append( 'nonce', botao.getAttribute( 'data-nonce' ) );
							fetch( ajaxurl, { method: 'POST', body: dados, credentials: 'same-origin' } )
								.then( function ( r ) { return r.json(); } )
								.then( function ( r ) {
									saida.style.color = r.success ? '#008a20' : '#d63638';
									saida.textContent = r.data && r.data.message ? r.data.message : '';
								} )
								.catch( function () { saida.style.color = '#d63638'; saida.textContent = 'Erro'; } );
						} );
					} )();
					</script>
				<?php elseif ( 'webhook' === $campo['desc'] ) : ?>
					<code><?php echo esc_html( AbstractGateway::url_webhook() ); ?></code>
					<p class="description"><?php esc_html_e( 'Enviada automaticamente em cada cobrança. Não é preciso cadastrá-la no painel da Pagou.', 'pagou-payments-for-woocommerce' ); ?></p>
				<?php else : ?>
					<p><?php echo esc_html( $campo['desc'] ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Valida a chave consultando o saldo da conta.
	 */
	public static function testar_conexao() {
		check_ajax_referer( 'pagou_wc_testar', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Sem permissão.', 'pagou-payments-for-woocommerce' ) ), 403 );
		}

		Settings::recarregar();

		if ( '' === Settings::api_key() ) {
			wp_send_json_error( array( 'message' => __( 'Informe e salve a chave de API.', 'pagou-payments-for-woocommerce' ) ) );
		}

		$resposta = ( new Client() )->get( '/v1/customers/balance' );

		if ( $resposta->ok() ) {
			wp_send_json_success(
				array(
					'message' => sprintf(
						/* translators: %s: ambiente */
						__( 'Conectado à Pagou (%s). Chave válida.', 'pagou-payments-for-woocommerce' ),
						Settings::sandbox() ? __( 'sandbox', 'pagou-payments-for-woocommerce' ) : __( 'produção', 'pagou-payments-for-woocommerce' )
					),
				)
			);
		}

		if ( 401 === $resposta->http ) {
			wp_send_json_error( array( 'message' => __( 'Chave inválida para o ambiente escolhido.', 'pagou-payments-for-woocommerce' ) ) );
		}

		wp_send_json_error(
			array(
				/* translators: %s: erro */
				'message' => sprintf( __( 'Não foi possível conectar: %s', 'pagou-payments-for-woocommerce' ), $resposta->erro() ),
			)
		);
	}
}
