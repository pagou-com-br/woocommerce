<?php
/**
 * Boleto ainda em registro no banco (o código de barras chega em instantes).
 *
 * Pode ser sobrescrito pelo tema em woocommerce/pagou/boleto-processando.php.
 *
 * @var WC_Order $order
 *
 * @package Pagou
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="pagou-pay pagou-boleto" data-pagou-processando data-pagou-order="<?php echo esc_attr( $order->get_id() ); ?>" data-pagou-key="<?php echo esc_attr( $order->get_order_key() ); ?>">
	<div class="pagou-pay__head">
		<h2 class="pagou-pay__title"><?php esc_html_e( 'Gerando seu boleto', 'pagou-payments-for-woocommerce' ); ?></h2>
		<p class="pagou-pay__lead"><?php esc_html_e( 'O banco está registrando o boleto. Esta página se atualiza sozinha em alguns segundos; o boleto também chega no seu e-mail.', 'pagou-payments-for-woocommerce' ); ?></p>
	</div>
</div>
