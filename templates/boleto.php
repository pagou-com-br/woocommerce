<?php
/**
 * Tela de pagamento via boleto.
 *
 * Pode ser sobrescrito pelo tema em woocommerce/pagou/boleto.php.
 *
 * @var WC_Order $order
 * @var string   $boleto_id
 * @var string   $barras     Código de barras.
 * @var string   $linha      Linha digitável (só dígitos).
 * @var string   $linha_fmt  Linha digitável formatada.
 * @var string   $pix        Pix embutido no boleto (copia e cola), quando houver.
 * @var string   $vencimento
 * @var array    $banco
 * @var float    $desconto
 *
 * @package Pagou
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="pagou-pay pagou-boleto" data-pagou-order="<?php echo esc_attr( $order->get_id() ); ?>" data-pagou-key="<?php echo esc_attr( $order->get_order_key() ); ?>">

	<div class="pagou-pay__head">
		<h2 class="pagou-pay__title"><?php esc_html_e( 'Seu boleto está pronto', 'pagou-payments-for-woocommerce' ); ?></h2>
		<p class="pagou-pay__lead"><?php esc_html_e( 'Pague em qualquer banco ou aplicativo. A confirmação pode levar até 2 dias úteis.', 'pagou-payments-for-woocommerce' ); ?></p>
	</div>

	<div class="pagou-pay__amount">
		<div>
			<span class="pagou-pay__amount-label"><?php esc_html_e( 'Valor do boleto', 'pagou-payments-for-woocommerce' ); ?></span>
			<strong class="pagou-pay__amount-value"><?php echo wp_kses_post( wc_price( $order->get_total() ) ); ?></strong>
			<?php if ( $desconto > 0 ) : ?>
				<span class="pagou-pay__saved">
					<?php
					printf(
						/* translators: %s: valor economizado */
						esc_html__( 'Você economizou %s neste meio de pagamento', 'pagou-payments-for-woocommerce' ),
						wp_kses_post( wc_price( $desconto ) )
					);
					?>
				</span>
			<?php endif; ?>
		</div>
		<?php if ( $vencimento ) : ?>
			<div class="pagou-pay__timer">
				<span class="pagou-pay__timer-label"><?php esc_html_e( 'Vencimento', 'pagou-payments-for-woocommerce' ); ?></span>
				<strong class="pagou-pay__timer-value"><?php echo esc_html( date_i18n( 'd/m/Y', strtotime( $vencimento ) ) ); ?></strong>
			</div>
		<?php endif; ?>
	</div>

	<div class="pagou-pay__actions pagou-pay__actions--full">
		<button type="button" class="pagou-pay__copy" data-pagou-copy data-pagou-code="<?php echo esc_attr( $linha ); ?>">
			<span class="pagou-pay__copy-text"><?php esc_html_e( 'Copiar linha digitável', 'pagou-payments-for-woocommerce' ); ?></span>
		</button>

		<code class="pagou-pay__code pagou-pay__code--line"><?php echo esc_html( $linha_fmt ); ?></code>

		<svg class="pagou-boleto__barcode" data-pagou-barcode="<?php echo esc_attr( $barras ); ?>"></svg>

		<a class="pagou-pay__secondary" target="_blank" rel="noopener" href="<?php echo esc_url( 'https://fatura.pagou.com.br/boleto/pdf/' . rawurlencode( $boleto_id ) ); ?>">
			<?php esc_html_e( 'Abrir boleto em PDF', 'pagou-payments-for-woocommerce' ); ?>
		</a>
	</div>

	<?php if ( $pix ) : ?>
		<div class="pagou-boleto__pix">
			<h3><?php esc_html_e( 'Prefere pagar na hora? Este boleto aceita Pix', 'pagou-payments-for-woocommerce' ); ?></h3>
			<p><?php esc_html_e( 'Pagando por Pix, a confirmação é imediata em vez de levar dias úteis.', 'pagou-payments-for-woocommerce' ); ?></p>
			<div class="pagou-pay__qr-canvas" data-pagou-qr="<?php echo esc_attr( $pix ); ?>"></div>
			<button type="button" class="pagou-pay__copy pagou-pay__copy--ghost" data-pagou-copy data-pagou-code="<?php echo esc_attr( $pix ); ?>">
				<span class="pagou-pay__copy-text"><?php esc_html_e( 'Copiar código Pix do boleto', 'pagou-payments-for-woocommerce' ); ?></span>
			</button>
		</div>
	<?php endif; ?>

	<?php if ( ! empty( $banco ) ) : ?>
		<details class="pagou-pay__details">
			<summary><?php esc_html_e( 'Dados bancários do boleto', 'pagou-payments-for-woocommerce' ); ?></summary>
			<ul>
				<?php if ( ! empty( $banco['issuer'] ) ) : ?>
					<li><span><?php esc_html_e( 'Banco emissor', 'pagou-payments-for-woocommerce' ); ?></span><?php echo esc_html( $banco['issuer'] ); ?></li>
				<?php endif; ?>
				<?php if ( ! empty( $banco['number'] ) ) : ?>
					<li><span><?php esc_html_e( 'Número', 'pagou-payments-for-woocommerce' ); ?></span><?php echo esc_html( $banco['number'] ); ?></li>
				<?php endif; ?>
				<?php if ( ! empty( $banco['agency'] ) ) : ?>
					<li><span><?php esc_html_e( 'Agência', 'pagou-payments-for-woocommerce' ); ?></span><?php echo esc_html( $banco['agency'] ); ?></li>
				<?php endif; ?>
				<?php if ( ! empty( $banco['account'] ) ) : ?>
					<li><span><?php esc_html_e( 'Conta', 'pagou-payments-for-woocommerce' ); ?></span><?php echo esc_html( $banco['account'] ); ?></li>
				<?php endif; ?>
				<?php if ( ! empty( $banco['assignor'] ) ) : ?>
					<li><span><?php esc_html_e( 'Cedente', 'pagou-payments-for-woocommerce' ); ?></span><?php echo esc_html( $banco['assignor'] ); ?></li>
				<?php endif; ?>
			</ul>
		</details>
	<?php endif; ?>

	<div class="pagou-pay__status" data-pagou-status aria-live="polite">
		<span class="pagou-pay__status-dot"></span>
		<span class="pagou-pay__status-text"><?php esc_html_e( 'Assim que o pagamento for identificado, você recebe a confirmação por e-mail.', 'pagou-payments-for-woocommerce' ); ?></span>
	</div>
</div>
