/**
 * Cartão Pagou no checkout em blocos.
 *
 * Ao finalizar, gera o token no navegador e envia ao servidor só token, portador,
 * validade e parcelas. Número e CVV ficam apenas no estado deste componente.
 */
( function () {
	'use strict';

	var registro = window.wc && window.wc.wcBlocksRegistry;
	var configs = window.wc && window.wc.wcSettings;
	var elemento = window.wp && window.wp.element;
	var dadosWp = window.wp && window.wp.data;
	var comum = window.pagouCartaoComum;
	var decodificar = window.wp && window.wp.htmlEntities ? window.wp.htmlEntities.decodeEntities : function ( t ) { return t; };

	if ( ! registro || ! configs || ! elemento || ! comum ) {
		return;
	}

	var dados = configs.getSetting( 'pagou_cartao_data', null );

	if ( ! dados ) {
		return;
	}

	var el = elemento.createElement;
	var useState = elemento.useState;
	var useEffect = elemento.useEffect;
	var useRef = elemento.useRef;
	var titulo = decodificar( dados.title || '' );

	comum.preparar( dados.producao );

	function totalCarrinho() {
		var loja = dadosWp && dadosWp.select( 'wc/store/cart' );
		var totais = loja && loja.getCartTotals ? loja.getCartTotals() : null;
		if ( ! totais ) {
			return 0;
		}
		var menor = parseInt( totais.total_price, 10 ) || 0;
		var casas = typeof totais.currency_minor_unit === 'number' ? totais.currency_minor_unit : 2;
		return Math.round( menor * Math.pow( 10, 2 - casas ) );
	}

	function useTotal() {
		var estado = useState( totalCarrinho() );
		useEffect( function () {
			if ( ! dadosWp ) {
				return undefined;
			}
			return dadosWp.subscribe( function () {
				var atual = totalCarrinho();
				estado[ 1 ]( function ( anterior ) {
					return anterior === atual ? anterior : atual;
				} );
			} );
		}, [] );
		return estado[ 0 ];
	}

	function SeletorParcelas( props ) {
		var opcoes = comum.parcelas( props.total, dados.parcelas, dados.moeda, dados.textos );

		useEffect( function () {
			var existe = opcoes.some( function ( o ) { return String( o.n ) === String( props.valor ); } );
			if ( ! existe ) {
				props.aoMudar( '1' );
			}
		}, [ props.total ] );

		return el(
			'div',
			{ className: 'wc-block-components-select-input pagou-cartao__parcelas' },
			el( 'label', { htmlFor: 'pagou-blocos-parcelas' }, dados.textos.parcelas ),
			el(
				'select',
				{
					id: 'pagou-blocos-parcelas',
					value: props.valor,
					onChange: function ( e ) { props.aoMudar( e.target.value ); },
				},
				opcoes.map( function ( o ) {
					return el( 'option', { key: o.n, value: String( o.n ) }, o.rotulo );
				} )
			)
		);
	}

	function Campo( props ) {
		return el(
			'div',
			{ className: 'wc-block-components-text-input is-active pagou-cartao__campo ' + ( props.classe || '' ) },
			el( 'input', {
				id: props.id,
				type: props.tipo || 'text',
				inputMode: props.modo || 'text',
				autoComplete: props.auto,
				value: props.valor,
				maxLength: props.max,
				'aria-label': props.rotulo,
				onChange: function ( e ) { props.aoMudar( props.mascara ? props.mascara( e.target.value ) : e.target.value ); },
			} ),
			el( 'label', { htmlFor: props.id }, props.rotulo )
		);
	}

	function Formulario( props ) {
		var numero = useState( '' );
		var nome = useState( '' );
		var validade = useState( '' );
		var cvv = useState( '' );
		var parcelas = useState( '1' );
		var total = useTotal();
		var atual = useRef( {} );

		atual.current = { numero: numero[ 0 ], nome: nome[ 0 ], validade: validade[ 0 ], cvv: cvv[ 0 ], parcelas: parcelas[ 0 ] };

		var setup = props.eventRegistration.onPaymentSetup;
		var tipos = props.emitResponse.responseTypes;

		useEffect( function () {
			return setup( async function () {
				var d = atual.current;
				try {
					var card = await comum.tokenizar( d, dados.producao );
					// O token já foi gerado: número e CVV saem do estado.
					numero[ 1 ]( '' );
					cvv[ 1 ]( '' );
					return {
						type: tipos.SUCCESS,
						meta: {
							paymentMethodData: {
								pagou_card_token: card.card_token,
								pagou_card_holder: card.holder,
								pagou_card_expiry: card.expires_at,
								pagou_installments: d.parcelas,
							},
						},
					};
				} catch ( e ) {
					return {
						type: tipos.ERROR,
						message: e && e.message === 'indisponivel' ? dados.textos.indisponivel : dados.textos.confira,
					};
				}
			} );
		}, [ setup ] );

		return el(
			'div',
			{ className: 'pagou-cartao pagou-cartao--blocos' },
			dados.description ? el( 'p', { className: 'pagou-blocos__descricao' }, decodificar( dados.description ) ) : null,
			el( Campo, { id: 'pagou-blocos-numero', rotulo: dados.textos.numero, valor: numero[ 0 ], aoMudar: numero[ 1 ], modo: 'numeric', auto: 'cc-number', max: 23, mascara: comum.mascaraNumero } ),
			el( Campo, { id: 'pagou-blocos-nome', rotulo: dados.textos.nome, valor: nome[ 0 ], aoMudar: nome[ 1 ], auto: 'cc-name' } ),
			el(
				'div',
				{ className: 'pagou-cartao__linha' },
				el( Campo, { id: 'pagou-blocos-validade', rotulo: dados.textos.validade, valor: validade[ 0 ], aoMudar: validade[ 1 ], modo: 'numeric', auto: 'cc-exp', max: 7, mascara: comum.mascaraValidade } ),
				el( Campo, { id: 'pagou-blocos-cvv', rotulo: dados.textos.cvv, valor: cvv[ 0 ], aoMudar: cvv[ 1 ], modo: 'numeric', auto: 'cc-csc', max: 4, tipo: 'password', mascara: function ( v ) { return v.replace( /\D/g, '' ).slice( 0, 4 ); } } )
			),
			el( SeletorParcelas, { total: total, valor: parcelas[ 0 ], aoMudar: parcelas[ 1 ] } )
		);
	}

	// Cartão salvo: só as parcelas.
	function FormularioSalvo( props ) {
		var parcelas = useState( '1' );
		var total = useTotal();
		var atual = useRef( '1' );
		atual.current = parcelas[ 0 ];

		var setup = props.eventRegistration.onPaymentSetup;
		var tipos = props.emitResponse.responseTypes;

		useEffect( function () {
			return setup( function () {
				return {
					type: tipos.SUCCESS,
					meta: { paymentMethodData: { pagou_installments: atual.current } },
				};
			} );
		}, [ setup ] );

		return el( SeletorParcelas, { total: total, valor: parcelas[ 0 ], aoMudar: parcelas[ 1 ] } );
	}

	var Rotulo = function () {
		return el(
			'span',
			{ className: 'pagou-blocos__rotulo' },
			el( 'span', null, titulo ),
			dados.icon ? el( 'img', { src: dados.icon, alt: '', className: 'pagou-blocos__icone' } ) : null
		);
	};

	registro.registerPaymentMethod( {
		name: 'pagou_cartao',
		label: el( Rotulo ),
		ariaLabel: titulo,
		content: el( Formulario ),
		edit: el( 'p', null, titulo ),
		savedTokenComponent: el( FormularioSalvo ),
		canMakePayment: function () {
			return true;
		},
		supports: {
			showSavedCards: !! dados.salvarCartao,
			showSaveOption: !! dados.salvarCartao,
			features: dados.supports || [ 'products' ],
		},
	} );
} )();
