<?php
/**
 * Pagamento com cartão em análise ou com resultado ainda sendo confirmado.
 *
 * Pode ser sobrescrito pelo tema em woocommerce/pagou/cartao-analise.php.
 *
 * @var WC_Order $order
 *
 * @package Pagou
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="pagou-pay" data-pagou-order="<?php echo esc_attr( $order->get_id() ); ?>" data-pagou-key="<?php echo esc_attr( $order->get_order_key() ); ?>">
	<div class="pagou-pay__head">
		<h2 class="pagou-pay__title"><?php esc_html_e( 'Estamos confirmando seu pagamento', 'pagou-payments-for-woocommerce' ); ?></h2>
		<p class="pagou-pay__lead"><?php esc_html_e( 'O pagamento com cartão está em análise. Não é preciso pagar de novo: você recebe a confirmação por e-mail.', 'pagou-payments-for-woocommerce' ); ?></p>
	</div>
	<div class="pagou-pay__status" data-pagou-status aria-live="polite">
		<span class="pagou-pay__status-dot"></span>
		<span class="pagou-pay__status-text"><?php esc_html_e( 'Esta página se atualiza sozinha quando o pagamento for confirmado.', 'pagou-payments-for-woocommerce' ); ?></span>
	</div>
</div>
