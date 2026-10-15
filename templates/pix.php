<?php
/**
 * Tela de pagamento via Pix.
 *
 * Pode ser sobrescrito pelo tema em woocommerce/pagou/pix.php.
 *
 * @var WC_Order $order
 * @var string   $codigo    Pix copia e cola.
 * @var string   $imagem    QR em base64 (só pedidos da 1.x; os novos desenham o QR no navegador).
 * @var int      $expira_em Timestamp UTC.
 * @var float    $desconto  Desconto aplicado.
 *
 * @package Pagou
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="pagou-pay" data-pagou-order="<?php echo esc_attr( $order->get_id() ); ?>" data-pagou-key="<?php echo esc_attr( $order->get_order_key() ); ?>" data-pagou-expira="<?php echo esc_attr( $expira_em ); ?>">

	<div class="pagou-pay__head">
		<h2 class="pagou-pay__title"><?php esc_html_e( 'Falta pouco: pague com Pix', 'pagou-payments-for-woocommerce' ); ?></h2>
		<p class="pagou-pay__lead"><?php esc_html_e( 'Seu pedido está reservado e será liberado assim que o pagamento for identificado.', 'pagou-payments-for-woocommerce' ); ?></p>
	</div>

	<div class="pagou-pay__amount">
		<div>
			<span class="pagou-pay__amount-label"><?php esc_html_e( 'Valor a pagar', 'pagou-payments-for-woocommerce' ); ?></span>
			<strong class="pagou-pay__amount-value"><?php echo wp_kses_post( wc_price( $order->get_total() ) ); ?></strong>
			<?php if ( $desconto > 0 ) : ?>
				<span class="pagou-pay__saved">
					<?php
					printf(
						/* translators: %s: valor economizado */
						esc_html__( 'Você economizou %s pagando com Pix', 'pagou-payments-for-woocommerce' ),
						wp_kses_post( wc_price( $desconto ) )
					);
					?>
				</span>
			<?php endif; ?>
		</div>
		<?php if ( $expira_em > time() ) : ?>
			<div class="pagou-pay__timer" data-pagou-timer>
				<span class="pagou-pay__timer-label"><?php esc_html_e( 'Este código expira em', 'pagou-payments-for-woocommerce' ); ?></span>
				<strong class="pagou-pay__timer-value">--:--</strong>
			</div>
		<?php endif; ?>
	</div>

	<div class="pagou-pay__grid">
		<div class="pagou-pay__qr-wrap">
			<?php if ( $imagem ) : ?>
				<?php // width/height explícitos: se o CSS não carregar, o QR não explode para o tamanho original. ?>
				<img class="pagou-pay__qr" width="200" height="200" src="data:image/png;base64,<?php echo esc_attr( $imagem ); ?>" alt="<?php esc_attr_e( 'QR Code para pagamento via Pix', 'pagou-payments-for-woocommerce' ); ?>" />
			<?php else : ?>
				<div class="pagou-pay__qr-canvas" data-pagou-qr="<?php echo esc_attr( $codigo ); ?>"></div>
			<?php endif; ?>
			<span class="pagou-pay__qr-hint"><?php esc_html_e( 'Aponte a câmera do app do seu banco', 'pagou-payments-for-woocommerce' ); ?></span>
		</div>

		<div class="pagou-pay__actions">
			<button type="button" class="pagou-pay__copy" data-pagou-copy data-pagou-code="<?php echo esc_attr( $codigo ); ?>">
				<span class="pagou-pay__copy-text"><?php esc_html_e( 'Copiar código Pix', 'pagou-payments-for-woocommerce' ); ?></span>
			</button>

			<code class="pagou-pay__code" data-pagou-code-preview><?php echo esc_html( $codigo ); ?></code>

			<ol class="pagou-pay__steps">
				<li><?php esc_html_e( 'Abra o app do seu banco', 'pagou-payments-for-woocommerce' ); ?></li>
				<li><?php esc_html_e( 'Escolha Pix e depois Pagar com QR Code ou Pix Copia e Cola', 'pagou-payments-for-woocommerce' ); ?></li>
				<li>
					<?php
					printf(
						/* translators: %s: valor do pedido */
						esc_html__( 'Confirme o valor de %s', 'pagou-payments-for-woocommerce' ),
						wp_kses_post( wc_price( $order->get_total() ) )
					);
					?>
				</li>
			</ol>
		</div>
	</div>

	<div class="pagou-pay__status" data-pagou-status aria-live="polite">
		<span class="pagou-pay__status-dot"></span>
		<span class="pagou-pay__status-text"><?php esc_html_e( 'Aguardando o pagamento. Esta página se atualiza sozinha assim que identificarmos.', 'pagou-payments-for-woocommerce' ); ?></span>
	</div>
</div>
