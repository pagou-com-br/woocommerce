( function( wcBlocksRegistry, i18n, element ) {
    const { registerPaymentMethod } = wcBlocksRegistry;
    const { __ } = i18n;
    const { createElement, useState, useEffect } = element;

    // Mascara de CPF
    function maskCPF(value) {
        let v = value.replace(/\D/g, '');
        v = v.substring(0, 11);
        
        if (v.length > 9) {
            v = v.replace(/(\d{3})(\d{3})(\d{3})(\d{2})/, '$1.$2.$3-$4');
        } else if (v.length > 6) {
            v = v.replace(/(\d{3})(\d{3})(\d{1,3})/, '$1.$2.$3');
        } else if (v.length > 3) {
            v = v.replace(/(\d{3})(\d{1,3})/, '$1.$2');
        }
        return v;
    }

    // Mascara de CNPJ
    function maskCNPJ(value) {
        let v = value.replace(/\D/g, '');
        v = v.substring(0, 14);
        if (v.length > 12) {
            v = v.replace(/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/, '$1.$2.$3/$4-$5');
        } else if (v.length > 8) {
            v = v.replace(/(\d{2})(\d{3})(\d{3})(\d{1,4})/, '$1.$2.$3/$4');
        } else if (v.length > 5) {
            v = v.replace(/(\d{2})(\d{3})(\d{1,3})/, '$1.$2.$3');
        } else if (v.length > 2) {
            v = v.replace(/(\d{2})(\d{1,3})/, '$1.$2');
        }
        return v;
    }

    // Valida CPF 
    function isValidCPF(cpf) {
        const numericCPF = cpf.replace(/\D/g, '');
        if (!numericCPF || numericCPF.length !== 11) return false;
        if (/^(\d)\1{10}$/.test(numericCPF)) return false;

        let soma = 0;
        for (let i = 1; i <= 9; i++) {
            soma += parseInt(numericCPF.substring(i - 1, i), 10) * (11 - i);
        }
        let resto = (soma * 10) % 11;
        if ((resto === 10) || (resto === 11)) resto = 0;
        if (resto !== parseInt(numericCPF.substring(9, 10), 10)) return false;

        soma = 0;
        for (let i = 1; i <= 10; i++) {
            soma += parseInt(numericCPF.substring(i - 1, i), 10) * (12 - i);
        }
        resto = (soma * 10) % 11;
        if ((resto === 10) || (resto === 11)) resto = 0;
        if (resto !== parseInt(numericCPF.substring(10, 11), 10)) return false;

        return true;
    }

    // Valida CNPJ
    function isValidCNPJ(cnpj) {
        const numericCNPJ = cnpj.replace(/\D/g, '');
        if (!numericCNPJ || numericCNPJ.length !== 14) return false;

        if (/^(\d)\1{13}$/.test(numericCNPJ)) return false;

        let tamanho = numericCNPJ.length - 2;
        let numeros = numericCNPJ.substring(0, tamanho);
        let digitos = numericCNPJ.substring(tamanho);
        let soma = 0;
        let pos = tamanho - 7;

        for (let i = tamanho; i >= 1; i--) {
            soma += parseInt(numeros.charAt(tamanho - i), 10) * pos--;
            if (pos < 2) pos = 9;
        }

        let resultado = soma % 11 < 2 ? 0 : 11 - (soma % 11);
        if (resultado !== parseInt(digitos.charAt(0), 10)) return false;

        tamanho = tamanho + 1;
        numeros = numericCNPJ.substring(0, tamanho);
        soma = 0;
        pos = tamanho - 7;
        for (let i = tamanho; i >= 1; i--) {
            soma += parseInt(numeros.charAt(tamanho - i), 10) * pos--;
            if (pos < 2) pos = 9;
        }
        resultado = soma % 11 < 2 ? 0 : 11 - (soma % 11);
        if (resultado !== parseInt(digitos.charAt(1), 10)) return false;

        return true;
    }

    if ( typeof pagouBoletoData === 'undefined' ) {
        return; 
    }

    // Componente que exibe o tipo de pessoa e o campo CPF/CNPJ
const PagouBoletoFields = ( props ) => {
        const { eventRegistration, emitResponse } = props;
        const { onPaymentProcessing } = eventRegistration;

        // 1 = Pessoa Física, 2 = Pessoa Jurídica
        const [personType, setPersonType] = useState('1');
        const [documentNumber, setDocumentNumber] = useState('');
        const [companyName, setCompanyName] = useState('');

        // Aplica máscara de acordo com o tipo
        const handleDocumentChange = (value) => {
            if (personType === '1') {
                setDocumentNumber(maskCPF(value));
            } else {
                setDocumentNumber(maskCNPJ(value));
            }
        };

        // Ao finalizar compra
        useEffect(() => {
            const unsubscribe = onPaymentProcessing(async () => {
                const numericDoc = documentNumber.replace(/\D/g, '');

                // Verifica se o doc foi preenchido
                if (!numericDoc) {
                    return {
                        type: emitResponse.responseTypes.ERROR,
                        message: personType === '1' ? 'Informe o CPF' : 'Informe o CNPJ',
                    };
                }

                if (personType === '1') {
                    // Validar CPF
                    if (!isValidCPF(documentNumber)) {
                        return {
                            type: emitResponse.responseTypes.ERROR,
                            message: 'CPF inválido',
                        };
                    }
                } else {
                    // Validar CNPJ
                    if (!isValidCNPJ(documentNumber)) {
                        return {
                            type: emitResponse.responseTypes.ERROR,
                            message: 'CNPJ inválido',
                        };
                    }
                    // Nome da Empresa é obrigatório em PJ
                    if (!companyName.trim()) {
                        return {
                            type: emitResponse.responseTypes.ERROR,
                            message: 'Informe o nome da empresa',
                        };
                    }
                }

                // Se estiver tudo ok, envia via payment_data
                return {
                    type: emitResponse.responseTypes.SUCCESS,
                    meta: {
                        paymentMethodData: {
                            personType,
                            documentNumber,
                            companyName,
                        },
                    },
                };
            });
            return () => unsubscribe();
        }, [personType, documentNumber, companyName, onPaymentProcessing, emitResponse]);

        return createElement(
            'div',
            null,
            createElement(
                'p',
                { style: { color: '#777', fontSize: '0.9rem' } },
                pagouBoletoData.description
            ),
            createElement(
                'div',
                { style: { display: 'flex', alignItems: 'center', gap: '10px', marginBottom: '8px' } },
                // Select Tipo de Pessoa
                createElement(
                    'div',
                    { style: { display: 'flex', flexDirection: 'column', gap: '5px', flex: '1 1' } },
                    createElement(
                        'label',
                        null,
                        'Tipo de Pessoa:'
                    ),
                    createElement(
                        'select',
                        {
                            value: personType,
                            onChange: (e) => {
                                setPersonType(e.target.value);
                                setDocumentNumber('');
                                setCompanyName('');
                            }
                        },
                        createElement('option', { value: '1' }, 'Pessoa Física'),
                        createElement('option', { value: '2' }, 'Pessoa Jurídica')
                    )
                ),
                // CPF/CNPJ
                createElement(
                    'div',
                    { style: { display: 'flex', flexDirection: 'column', gap: '5px', flex: '1 1' } },
                    createElement(
                        'label',
                        null,
                        personType === '1' ? 'CPF:' : 'CNPJ:'
                    ),
                    createElement(
                        'input',
                        {
                            type: 'text',
                            style: { width: '100%' },
                            value: documentNumber,
                            onChange: (e) => handleDocumentChange(e.target.value),
                        }
                    )
                )
            ),
            // Nome da empresa para PJ
            personType === '2' &&
            createElement(
                'div',
                { style: { marginBottom: '8px' } },
                createElement(
                    'label',
                    null,
                    'Nome da Empresa:'
                ),
                createElement(
                    'input',
                    {
                        type: 'text',
                        style: { width: '100%', marginTop: '5px' },
                        value: companyName,
                        onChange: ( e ) => setCompanyName(e.target.value),
                    }
                )
            )
        );
    };

    // Registra o método de pagamento
    const PagouBoletoPaymentMethod = {
        name: 'pagou_boleto',
        label: pagouBoletoData.title,
        ariaLabel: pagouBoletoData.description,
        content: createElement( PagouBoletoFields ),
        edit: createElement( PagouBoletoFields ),
        canMakePayment: () => true,
        supports: {
            features: [ 'products' ],
        },
    };

    registerPaymentMethod( PagouBoletoPaymentMethod );
} )( window.wc.wcBlocksRegistry, wp.i18n, wp.element );
