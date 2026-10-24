/**
 * Tela de pagamento: copiar código, contagem de expiração e detecção do pagamento.
 *
 * A detecção consulta o status do próprio pedido. A cada quarta verificação, pede uma
 * consulta direta à Pagou (deep), que funciona como rede de segurança caso a
 * notificação automática não tenha chegado.
 */
( function () {
	'use strict';

	var raiz = document.querySelector( '.pagou-pay, .pagou-boleto' );

	if ( ! raiz || typeof pagouPagamento === 'undefined' ) {
		return;
	}

	var pedido = raiz.getAttribute( 'data-pagou-order' );
	var chave = raiz.getAttribute( 'data-pagou-key' );

	// ---------- Copiar código ----------
	function copiar( texto ) {
		if ( navigator.clipboard && window.isSecureContext ) {
			return navigator.clipboard.writeText( texto );
		}
		// Navegadores antigos ou contexto sem HTTPS.
		return new Promise( function ( resolve, reject ) {
			var area = document.createElement( 'textarea' );
			area.value = texto;
			area.setAttribute( 'readonly', '' );
			area.style.position = 'fixed';
			area.style.opacity = '0';
			document.body.appendChild( area );
			area.select();
			var ok = false;
			try {
				ok = document.execCommand( 'copy' );
			} catch ( e ) {
				ok = false;
			}
			document.body.removeChild( area );
			ok ? resolve() : reject();
		} );
	}

	Array.prototype.forEach.call( raiz.querySelectorAll( '[data-pagou-copy]' ), function ( botao ) {
		botao.addEventListener( 'click', function () {
			var codigo = botao.getAttribute( 'data-pagou-code' ) || '';
			var alvo = botao.querySelector( '.pagou-pay__copy-text' ) || botao;
			var original = alvo.textContent;

			copiar( codigo ).then( function () {
				botao.classList.add( 'is-copied' );
				alvo.textContent = pagouPagamento.copiado;
				setTimeout( function () {
					botao.classList.remove( 'is-copied' );
					alvo.textContent = original;
				}, 2500 );
			} ).catch( function () {
				alvo.textContent = pagouPagamento.erroCopia;
				setTimeout( function () {
					alvo.textContent = original;
				}, 2500 );
			} );
		} );
	} );

	// ---------- QR Code do Pix e código de barras do boleto ----------
	// As bibliotecas podem chegar depois deste script (plugins de cache ou otimização que
	// adiam ou reordenam scripts): o que faltar é desenhado de novo no load da página.
	function desenharQr() {
		if ( typeof window.qrcode !== 'function' ) {
			return false;
		}

		Array.prototype.forEach.call( raiz.querySelectorAll( '[data-pagou-qr]' ), function ( alvo ) {
			var codigo = alvo.getAttribute( 'data-pagou-qr' ) || '';

			if ( ! codigo || alvo.querySelector( '.pagou-pay__qr' ) ) {
				return;
			}

			try {
				var qr = window.qrcode( 0, 'M' );
				qr.addData( codigo );
				qr.make();

				var img = document.createElement( 'img' );
				img.className = 'pagou-pay__qr';
				img.width = 200;
				img.height = 200;
				img.alt = pagouPagamento.qrAlt;
				img.src = qr.createDataURL( 6, 2 );
				alvo.appendChild( img );
			} catch ( e ) {
				// Sem QR, o copia e cola continua disponível.
			}
		} );

		return true;
	}

	function desenharBarras() {
		var barras = raiz.querySelector( '[data-pagou-barcode]' );

		if ( ! barras || barras.getAttribute( 'data-pagou-desenhado' ) ) {
			return true;
		}

		if ( typeof window.JsBarcode !== 'function' ) {
			return false;
		}

		try {
			window.JsBarcode( barras, barras.getAttribute( 'data-pagou-barcode' ), {
				format: 'ITF',
				lineColor: '#09090B',
				width: 2,
				height: 70,
				displayValue: false,
				margin: 6
			} );
			barras.setAttribute( 'data-pagou-desenhado', '1' );
		} catch ( e ) {
			barras.style.display = 'none';
		}

		return true;
	}

	var qrPronto = ! raiz.querySelector( '[data-pagou-qr]' ) || desenharQr();
	var barrasPronto = desenharBarras();

	if ( ! qrPronto || ! barrasPronto ) {
		window.addEventListener( 'load', function () {
			desenharQr();
			desenharBarras();
		} );
	}

	// ---------- Boleto ainda em registro: recarrega poucas vezes ----------
	if ( raiz.hasAttribute( 'data-pagou-processando' ) ) {
		var chaveTentativas = 'pagouBoletoTentativas';
		var feitas = 0;
		try {
			feitas = parseInt( window.sessionStorage.getItem( chaveTentativas ) || '0', 10 );
			if ( feitas < 6 ) {
				window.sessionStorage.setItem( chaveTentativas, feitas + 1 );
				setTimeout( function () { window.location.reload(); }, 5000 );
			} else {
				window.sessionStorage.removeItem( chaveTentativas );
			}
		} catch ( e ) {
			// Sem sessionStorage: o cliente recarrega manualmente.
		}
		return;
	}

	try {
		window.sessionStorage.removeItem( 'pagouBoletoTentativas' );
	} catch ( e ) {}

	// ---------- Contagem de expiração ----------
	var expira = parseInt( raiz.getAttribute( 'data-pagou-expira' ) || '0', 10 );
	var caixaTimer = raiz.querySelector( '[data-pagou-timer]' );

	if ( expira > 0 && caixaTimer ) {
		var valorTimer = caixaTimer.querySelector( '.pagou-pay__timer-value' );

		var tick = function () {
			var restante = expira - Math.floor( Date.now() / 1000 );

			if ( restante <= 0 ) {
				caixaTimer.classList.add( 'is-expired' );
				valorTimer.textContent = pagouPagamento.expirado;
				clearInterval( intervaloTimer );
				return;
			}

			var h = Math.floor( restante / 3600 );
			var m = Math.floor( ( restante % 3600 ) / 60 );
			var s = restante % 60;
			var pad = function ( n ) {
				return n < 10 ? '0' + n : '' + n;
			};

			valorTimer.textContent = h > 0
				? h + ':' + pad( m ) + ':' + pad( s )
				: pad( m ) + ':' + pad( s );

			// Últimos cinco minutos ganham destaque.
			if ( restante < 300 ) {
				caixaTimer.classList.add( 'is-urgent' );
			}
		};

		tick();
		var intervaloTimer = setInterval( tick, 1000 );
	}

	// ---------- Detecção do pagamento ----------
	var status = raiz.querySelector( '[data-pagou-status]' );

	if ( ! status || ! pedido || ! chave ) {
		return;
	}

	var textoStatus = status.querySelector( '.pagou-pay__status-text' );
	var tentativas = 0;
	var maximo = 240; // ~20 minutos a cada 5s
	var verificando = false;

	function confirmar( resposta ) {
		status.classList.remove( 'is-waiting' );
		status.classList.add( 'is-paid' );
		textoStatus.textContent = pagouPagamento.confirmado;

		setTimeout( function () {
			window.location.href = resposta.redirect || window.location.href;
		}, 1200 );
	}

	function verificar() {
		if ( verificando ) {
			return;
		}
		verificando = true;
		tentativas++;

		// A cada sexta tentativa (~30s), confere direto na Pagou. O servidor também
		// limita essa consulta, então manter o intervalo acima do freio evita
		// requisições que seriam descartadas.
		var profundo = tentativas % 6 === 0 ? '1' : '0';

		var url = pagouPagamento.ajaxUrl
			+ '?action=pagou_wc_status&order_id=' + encodeURIComponent( pedido )
			+ '&key=' + encodeURIComponent( chave )
			+ '&deep=' + profundo;

		fetch( url, { credentials: 'same-origin' } )
			.then( function ( r ) {
				return r.json();
			} )
			.then( function ( json ) {
				verificando = false;
				if ( json && json.success && json.data && json.data.pago ) {
					clearInterval( intervalo );
					confirmar( json.data );
				}
			} )
			.catch( function () {
				verificando = false;
			} );

		if ( tentativas >= maximo ) {
			clearInterval( intervalo );
		}
	}

	status.classList.add( 'is-waiting' );
	var intervalo = setInterval( verificar, 5000 );

	// Ao voltar para a aba, confere na hora (cliente pagou no celular e voltou).
	document.addEventListener( 'visibilitychange', function () {
		if ( ! document.hidden ) {
			verificar();
		}
	} );
} )();
