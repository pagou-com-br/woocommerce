/**
 * Cartão Pagou: tokenização, máscaras e parcelas (usado pelos checkouts clássico e em blocos).
 *
 * Número e CVV só existem no navegador: viram um token criptografado com a chave pública
 * da Pagou e são apagados do formulário em seguida.
 */
( function ( janela ) {
	'use strict';

	var bibliotecas = {};
	var formatoToken = /^[A-Za-z0-9+/]+={0,2}$/;

	function biblioteca( producao ) {
		if ( typeof janela.forge !== 'object' || typeof janela.BaasCard !== 'function' ) {
			return Promise.reject( new Error( 'indisponivel' ) );
		}

		var chave = producao ? 'p' : 's';

		if ( ! bibliotecas[ chave ] ) {
			bibliotecas[ chave ] = ( async function () {
				var lib = new janela.BaasCard( producao );
				await lib._setPublicKey();
				if ( typeof lib._publicKey !== 'string' || lib._publicKey.length < 100 ) {
					throw new Error( 'indisponivel' );
				}
				return lib;
			} )().catch( function ( erro ) {
				delete bibliotecas[ chave ];
				throw erro;
			} );
		}

		return bibliotecas[ chave ];
	}

	/**
	 * @param {{numero:string,nome:string,validade:string,cvv:string}} dados validade em MM/AA ou MM/AAAA.
	 * @param {boolean} producao
	 * @return {Promise<{card_token:string,holder:string,expires_at:string}>}
	 */
	async function tokenizar( dados, producao ) {
		var lib = await biblioteca( producao );
		var expira = validadeApi( dados.validade );

		if ( ! expira ) {
			throw new Error( 'confira' );
		}

		var token;
		try {
			var cartao = lib.newCard( {
				number: String( dados.numero ).replace( /\D/g, '' ),
				holder: String( dados.nome ).trim(),
				expiresAt: expira,
				cvv: String( dados.cvv ).replace( /\D/g, '' ),
			} );
			cartao.validate();
			token = cartao.encrypt( lib._publicKey );
		} catch ( e ) {
			throw new Error( 'confira' );
		}

		if ( typeof token !== 'string' || token.length < 64 || token.length > 16384 || ! formatoToken.test( token ) ) {
			throw new Error( 'confira' );
		}

		return { card_token: token, holder: String( dados.nome ).trim(), expires_at: expira };
	}

	/** "08/29" ou "08/2029" vira "2029-08". */
	function validadeApi( texto ) {
		var m = String( texto || '' ).replace( /\s/g, '' ).match( /^(0[1-9]|1[0-2])\/?(\d{2}|\d{4})$/ );
		if ( ! m ) {
			return '';
		}
		var ano = m[ 2 ].length === 2 ? '20' + m[ 2 ] : m[ 2 ];
		return ano + '-' + m[ 1 ];
	}

	function mascaraNumero( valor ) {
		var d = String( valor ).replace( /\D/g, '' ).slice( 0, 19 );
		// Amex usa 4-6-5; os demais, grupos de 4.
		if ( /^3[47]/.test( d ) ) {
			return d.replace( /^(\d{0,4})(\d{0,6})(\d{0,5}).*/, function ( _, a, b, c ) {
				return [ a, b, c ].filter( Boolean ).join( ' ' );
			} );
		}
		return d.replace( /(\d{4})(?=\d)/g, '$1 ' );
	}

	function mascaraValidade( valor ) {
		var d = String( valor ).replace( /\D/g, '' ).slice( 0, 6 );
		if ( d.length === 1 && d > '1' ) {
			d = '0' + d;
		}
		return d.length > 2 ? d.slice( 0, 2 ) + '/' + d.slice( 2 ) : d;
	}

	function dinheiro( centavos, moeda ) {
		var reais = ( centavos / 100 ).toFixed( 2 ).split( '.' );
		var inteiro = reais[ 0 ].replace( /\B(?=(\d{3})+(?!\d))/g, moeda.milhar );
		return moeda.simbolo + ' ' + inteiro + moeda.decimal + reais[ 1 ];
	}

	function formatar( modelo, valores ) {
		var i = 0;
		return modelo.replace( /%(\d)\$[sd]|%[sd]/g, function ( _, pos ) {
			return pos ? valores[ pos - 1 ] : valores[ i++ ];
		} );
	}

	/**
	 * Opções de parcelamento (o servidor recalcula; isto é só exibição).
	 */
	function parcelas( totalCentavos, cfg, moeda, textos ) {
		var lista = [];
		for ( var n = 1; n <= cfg.maximo; n++ ) {
			var parcela, total, semJuros = n <= cfg.semJuros || cfg.taxa <= 0;
			if ( semJuros ) {
				parcela = Math.ceil( totalCentavos / n );
				total = totalCentavos;
			} else {
				parcela = Math.round( totalCentavos * cfg.taxa / ( 1 - Math.pow( 1 + cfg.taxa, -n ) ) );
				total = parcela * n;
			}
			if ( n > 1 && parcela < cfg.minimo ) {
				break;
			}
			var rotulo = n === 1
				? formatar( textos.avista, [ dinheiro( parcela, moeda ) ] )
				: semJuros
					? formatar( textos.semJuros, [ n, dinheiro( parcela, moeda ) ] )
					: formatar( textos.comJuros, [ n, dinheiro( parcela, moeda ), dinheiro( total, moeda ) ] );
			lista.push( { n: n, rotulo: rotulo } );
		}
		return lista;
	}

	janela.pagouCartaoComum = {
		tokenizar: tokenizar,
		preparar: function ( producao ) {
			return biblioteca( producao ).catch( function () {} );
		},
		mascaraNumero: mascaraNumero,
		mascaraValidade: mascaraValidade,
		parcelas: parcelas,
	};
} )( window );
