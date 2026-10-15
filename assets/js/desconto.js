/**
 * Desconto por meio de pagamento.
 *
 * Checkout clássico: o WooCommerce não recalcula os totais quando o cliente troca o
 * método de pagamento, então disparamos a atualização manualmente.
 *
 * Checkout em blocos: informamos ao Store API qual método está selecionado, para que o
 * carrinho seja recalculado com o desconto correspondente.
 */
( function () {
	'use strict';

	// ---------- Checkout clássico ----------
	if ( window.jQuery ) {
		jQuery( function ( $ ) {
			$( document.body ).on( 'change', 'input[name="payment_method"]', function () {
				$( document.body ).trigger( 'update_checkout' );
			} );
		} );
	}

	// ---------- Checkout em blocos ----------
	if ( ! window.wp || ! wp.data || ! window.wc || ! wc.blocksCheckout || ! wc.blocksCheckout.extensionCartUpdate ) {
		return;
	}

	var metodoAnterior = null;
	var atualizando = false;

	wp.data.subscribe( function () {
		var store = wp.data.select( 'wc/store/payment' );

		if ( ! store || typeof store.getActivePaymentMethod !== 'function' ) {
			return;
		}

		var metodoAtual = store.getActivePaymentMethod();

		if ( ! metodoAtual || metodoAtual === metodoAnterior || atualizando ) {
			return;
		}

		metodoAnterior = metodoAtual;
		atualizando = true;

		wc.blocksCheckout
			.extensionCartUpdate( {
				namespace: 'pagou-desconto',
				data: { metodo: metodoAtual },
			} )
			.finally( function () {
				atualizando = false;
			} );
	} );
} )();
