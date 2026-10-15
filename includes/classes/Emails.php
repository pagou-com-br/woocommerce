<?php
/**
 * Inclui o código de pagamento nos e-mails do pedido.
 *
 * Sem isso, quem fecha a aba do checkout perde o código Pix e precisa procurar o
 * pedido na área do cliente.
 *
 * @package Pagou
 */

namespace Pagou\WooCommerce;

use Pagou\WooCommerce\Gateway\Pix;
use WC_Email;
use WC_Order;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Emails {

	public static function init() {
		add_action( 'woocommerce_email_before_order_table', array( __CLASS__, 'incluir_pagamento' ), 10, 4 );
	}

	/**
	 * @param WC_Order $order
	 * @param bool     $sent_to_admin
	 * @param bool     $plain_text
	 * @param WC_Email $email
	 */
	public static function incluir_pagamento( $order, $sent_to_admin, $plain_text = false, $email = null ) {
		if ( ! $order instanceof WC_Order || $sent_to_admin ) {
			return;
		}

		$metodo = $order->get_payment_method();

		if ( ! in_array( $metodo, array( 'pagou_pix', 'pagou_boleto' ), true ) ) {
			return;
		}

		// Só faz sentido enquanto o pedido aguarda pagamento.
		if ( ! $order->has_status( array( 'on-hold', 'pending' ) ) ) {
			return;
		}

		$is_pix   = 'pagou_pix' === $metodo;
		$resposta = $order->get_meta( $is_pix ? '_pagou_pix_response' : '_pagou_boleto_response' );

		if ( ! is_array( $resposta ) || empty( $resposta['payload'] ) ) {
			return;
		}

		$codigo = $is_pix
			? Pix::codigo( $order )
			: ( isset( $resposta['payload']['line'] ) ? $resposta['payload']['line'] : '' );

		if ( '' === $codigo ) {
			return;
		}

		$url_pedido = $order->get_checkout_order_received_url();

		if ( $plain_text ) {
			self::texto_simples( $is_pix, $codigo, $order, $url_pedido );
			return;
		}

		self::html( $is_pix, $codigo, $order, $url_pedido, $resposta );
	}

	private static function texto_simples( $is_pix, $codigo, $order, $url_pedido ) {
		echo "\n----------------------------------------\n";
		echo $is_pix
			? esc_html__( 'PAGUE COM PIX', 'pagou-payments-for-woocommerce' ) . "\n"
			: esc_html__( 'PAGUE SEU BOLETO', 'pagou-payments-for-woocommerce' ) . "\n";
		// Versão em texto puro: remove as tags do wc_price e decodifica entidades
		// (&nbsp;) para o valor não aparecer com código no corpo do e-mail.
		$valor_texto = html_entity_decode( wp_strip_all_tags( wc_price( $order->get_total() ) ), ENT_QUOTES, 'UTF-8' );

		printf(
			/* translators: %s: valor do pedido */
			esc_html__( 'Valor: %s', 'pagou-payments-for-woocommerce' ) . "\n\n",
			esc_html( $valor_texto )
		);
		echo $is_pix
			? esc_html__( 'Copie o código abaixo e pague no app do seu banco:', 'pagou-payments-for-woocommerce' ) . "\n"
			: esc_html__( 'Linha digitável:', 'pagou-payments-for-woocommerce' ) . "\n";
		echo esc_html( $codigo ) . "\n\n";
		echo esc_html__( 'Ver o pedido:', 'pagou-payments-for-woocommerce' ) . ' ' . esc_url( $url_pedido ) . "\n";
		echo "----------------------------------------\n\n";
	}

	private static function html( $is_pix, $codigo, $order, $url_pedido, $resposta ) {
		$titulo = $is_pix
			? __( 'Pague com Pix para concluir', 'pagou-payments-for-woocommerce' )
			: __( 'Pague seu boleto para concluir', 'pagou-payments-for-woocommerce' );

		$instrucao = $is_pix
			? __( 'Copie o código abaixo e pague no app do seu banco. A confirmação é automática.', 'pagou-payments-for-woocommerce' )
			: __( 'Use a linha digitável abaixo em qualquer banco ou aplicativo.', 'pagou-payments-for-woocommerce' );

		?>
		<div style="margin:0 0 28px;padding:20px;border:1px solid #E4E4E7;border-radius:10px;background:#FAFAFA;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Arial,sans-serif;">
			<p style="margin:0 0 4px;font-size:11px;font-weight:bold;letter-spacing:.04em;text-transform:uppercase;color:#0F766E;">
				<?php esc_html_e( 'Pagamento pendente', 'pagou-payments-for-woocommerce' ); ?>
			</p>
			<h2 style="margin:0 0 6px;font-size:18px;color:#09090B;"><?php echo esc_html( $titulo ); ?></h2>
			<p style="margin:0 0 14px;font-size:14px;color:#52525B;"><?php echo esc_html( $instrucao ); ?></p>

			<p style="margin:0 0 16px;font-size:22px;font-weight:bold;color:#09090B;">
				<?php echo wp_kses_post( wc_price( $order->get_total() ) ); ?>
			</p>

			<p style="margin:0 0 6px;font-size:11px;font-weight:bold;letter-spacing:.04em;text-transform:uppercase;color:#A1A1AA;">
				<?php
				echo $is_pix
					? esc_html__( 'Pix copia e cola', 'pagou-payments-for-woocommerce' )
					: esc_html__( 'Linha digitável', 'pagou-payments-for-woocommerce' );
				?>
			</p>
			<p style="margin:0 0 16px;padding:12px;border:1px solid #E4E4E7;border-radius:8px;background:#FFFFFF;font-family:Consolas,Menlo,monospace;font-size:12px;line-height:1.6;color:#09090B;word-break:break-all;">
				<?php echo esc_html( $codigo ); ?>
			</p>

			<?php if ( ! $is_pix && ! empty( $resposta['id'] ) ) : ?>
				<p style="margin:0 0 12px;">
					<a href="<?php echo esc_url( 'https://fatura.pagou.com.br/boleto/pdf/' . rawurlencode( $resposta['id'] ) ); ?>" style="display:inline-block;padding:11px 18px;background:#09090B;color:#ffffff;border-radius:8px;font-size:14px;font-weight:bold;text-decoration:none;">
						<?php esc_html_e( 'Abrir boleto em PDF', 'pagou-payments-for-woocommerce' ); ?>
					</a>
				</p>
			<?php endif; ?>

			<p style="margin:0;font-size:13px;">
				<a href="<?php echo esc_url( $url_pedido ); ?>" style="color:#0F766E;">
					<?php
					echo $is_pix
						? esc_html__( 'Abrir a tela de pagamento com o QR Code', 'pagou-payments-for-woocommerce' )
						: esc_html__( 'Abrir a tela do boleto', 'pagou-payments-for-woocommerce' );
					?>
				</a>
			</p>
		</div>
		<?php
	}
}
