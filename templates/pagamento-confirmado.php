<?php
/**
 * Pagamento já confirmado.
 *
 * Pode ser sobrescrito pelo tema em woocommerce/pagou/pagamento-confirmado.php.
 *
 * @var WC_Order $order
 * @var string   $titulo
 * @var float    $desconto
 *
 * @package Pagou
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="pagou-pay pagou-pay--done">
	<div class="pagou-pay__done">
		<span class="pagou-pay__done-icon" aria-hidden="true">
			<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
		</span>
		<div>
			<strong class="pagou-pay__done-title"><?php echo esc_html( $titulo ); ?></strong>
			<span class="pagou-pay__done-text">
				<?php
				printf(
					/* translators: %s: valor pago */
					esc_html__( 'Recebemos %s. Seu pedido já está em preparação.', 'pagou-payments-for-woocommerce' ),
					wp_kses_post( wc_price( $order->get_total() ) )
				);
				?>
				<?php if ( $desconto > 0 ) : ?>
					<?php
					printf(
						/* translators: %s: valor economizado */
						esc_html__( 'Você economizou %s.', 'pagou-payments-for-woocommerce' ),
						wp_kses_post( wc_price( $desconto ) )
					);
					?>
				<?php endif; ?>
			</span>
		</div>
	</div>
</div>
