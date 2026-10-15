/**
 * Cartão Pagou no checkout clássico e em "Adicionar método de pagamento".
 *
 * Intercepta o envio, gera o token, preenche os campos ocultos, apaga número e CVV e
 * reenvia o formulário.
 */
( function ( $ ) {
	'use strict';

	if ( typeof pagouCartao === 'undefined' || ! window.pagouCartaoComum ) {
		return;
	}

	var comum = window.pagouCartaoComum;
	var tokenizado = false;

	comum.preparar( pagouCartao.producao );

	function campo( nome ) {
		return $( '[data-pagou-cartao="' + nome + '"]' );
	}

	function usandoCartaoSalvo() {
		var escolha = $( 'input[name="wc-pagou_cartao-payment-token"]:checked' ).val();
		return escolha && escolha !== 'new';
	}

	function pagouSelecionado() {
		return $( '#payment_method_pagou_cartao' ).is( ':checked' ) || $( 'form#add_payment_method' ).length > 0;
	}

	$( document.body ).on( 'input', '[data-pagou-cartao="numero"]', function () {
		this.value = comum.mascaraNumero( this.value );
	} );

	$( document.body ).on( 'input', '[data-pagou-cartao="validade"]', function () {
		this.value = comum.mascaraValidade( this.value );
	} );

	$( document.body ).on( 'input', '[data-pagou-cartao="cvv"]', function () {
		this.value = this.value.replace( /\D/g, '' ).slice( 0, 4 );
	} );

	// Cartão salvo dispensa os campos do cartão, mas mantém as parcelas.
	$( document.body ).on( 'change', 'input[name="wc-pagou_cartao-payment-token"]', function () {
		$( '.pagou-cartao__campos' ).toggle( ! usandoCartaoSalvo() );
	} );

	function erro( formulario, mensagem ) {
		var $form = $( formulario );
		$form.removeClass( 'processing' ).unblock && $form.unblock();
		$( '.woocommerce-NoticeGroup-checkout, .woocommerce-error' ).remove();
		$form.prepend( '<div class="woocommerce-NoticeGroup woocommerce-NoticeGroup-checkout"><ul class="woocommerce-error" role="alert"><li>' + $( '<div>' ).text( mensagem ).html() + '</li></ul></div>' );
		$( 'html, body' ).animate( { scrollTop: $form.offset().top - 100 }, 300 );
	}

	function interceptar( formulario ) {
		if ( ! pagouSelecionado() || usandoCartaoSalvo() ) {
			return true;
		}

		if ( tokenizado ) {
			tokenizado = false;
			return true;
		}

		var dados = {
			numero: campo( 'numero' ).val() || '',
			nome: campo( 'nome' ).val() || '',
			validade: campo( 'validade' ).val() || '',
			cvv: campo( 'cvv' ).val() || '',
		};

		comum.tokenizar( dados, pagouCartao.producao ).then( function ( card ) {
			$( 'input[name="pagou_card_token"]' ).val( card.card_token );
			$( 'input[name="pagou_card_holder"]' ).val( card.holder );
			$( 'input[name="pagou_card_expiry"]' ).val( card.expires_at );
			campo( 'numero' ).val( '' );
			campo( 'cvv' ).val( '' );
			tokenizado = true;
			$( formulario ).trigger( 'submit' );
		} ).catch( function ( e ) {
			erro( formulario, e && e.message === 'indisponivel' ? pagouCartao.textos.indisponivel : pagouCartao.textos.confira );
		} );

		return false;
	}

	$( 'form.checkout' ).on( 'checkout_place_order_pagou_cartao', function () {
		return interceptar( this );
	} );

	// Pedido já criado (pagar pedido) e "Adicionar método de pagamento".
	$( document.body ).on( 'submit', 'form#order_review, form#add_payment_method', function () {
		return interceptar( this );
	} );

	// Após um erro do servidor, o token já foi usado: exige nova tokenização.
	$( document.body ).on( 'checkout_error updated_checkout', function () {
		$( 'input[name="pagou_card_token"]' ).val( '' );
		tokenizado = false;
	} );
} )( jQuery );
