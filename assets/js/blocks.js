/**
 * Pix e boleto no checkout em blocos.
 *
 * O CPF/CNPJ não é pedido aqui: ele vem do campo nativo do checkout (contato), registrado
 * pelo plugin e validado no servidor.
 */
( function () {
	'use strict';

	var registro = window.wc && window.wc.wcBlocksRegistry;
	var configs = window.wc && window.wc.wcSettings;
	var el = window.wp && window.wp.element && window.wp.element.createElement;
	var decodificar = window.wp && window.wp.htmlEntities ? window.wp.htmlEntities.decodeEntities : function ( t ) { return t; };

	if ( ! registro || ! configs || ! el ) {
		return;
	}

	function registrar( nome, altIcone ) {
		var dados = configs.getSetting( nome + '_data', null );

		if ( ! dados ) {
			return;
		}

		var titulo = decodificar( dados.title || '' );

		var Rotulo = function () {
			return el(
				'span',
				{ className: 'pagou-blocos__rotulo' },
				el( 'span', null, dados.discount ? titulo + ' (' + dados.discount + ')' : titulo ),
				dados.icon ? el( 'img', { src: dados.icon, alt: altIcone, className: 'pagou-blocos__icone' } ) : null
			);
		};

		var Conteudo = function () {
			return dados.description
				? el( 'p', { className: 'pagou-blocos__descricao' }, decodificar( dados.description ) )
				: null;
		};

		registro.registerPaymentMethod( {
			name: nome,
			label: el( Rotulo ),
			ariaLabel: titulo,
			content: el( Conteudo ),
			edit: el( Conteudo ),
			canMakePayment: function () {
				return true;
			},
			supports: {
				features: dados.supports || [ 'products' ],
			},
		} );
	}

	registrar( 'pagou_pix', 'Pix' );
	registrar( 'pagou_boleto', 'Boleto' );
} )();
