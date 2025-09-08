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

    // Se não houver dados localizados, sair
    if ( typeof pagouPixData === 'undefined' ) {
        return; 
    }

    // Componente que exibe tipo de pessoa e campo CPF/CNPJ para PIX
    const PagouPixFields = ( props ) => {
        const { eventRegistration, emitResponse } = props;
        const { onPaymentProcessing } = eventRegistration;

        // Começa como Pessoa Física
        const [personType, setPersonType] = useState('1'); // '1' = PF, '2' = PJ
        const [documentNumber, setDocumentNumber] = useState('');
        // Campo adicional: Nome da Empresa (somente se CNPJ)
        const [companyName, setCompanyName] = useState('');

        // Aplica máscara CPF/CNPJ
        const handleDocumentChange = (value) => {
            if (personType === '1') {
                setDocumentNumber(maskCPF(value));
            } else {
                setDocumentNumber(maskCNPJ(value));
            }
        };

        // Intercepta "Finalizar Compra" no Checkout Block
        useEffect(() => {
            const unsubscribe = onPaymentProcessing(async () => {
                // Remove a máscara para validação
                const numericDoc = documentNumber.replace(/\D/g, '');

                // Validação básica: documento preenchido?
                if (!numericDoc) {
                    return {
                        type: emitResponse.responseTypes.ERROR,
                        message: personType === '1' ? 'Informe o CPF' : 'Informe o CNPJ',
                    };
                }

                // Se PF: valida CPF
                if (personType === '1') {
                    if (!isValidCPF(documentNumber)) {
                        return {
                            type: emitResponse.responseTypes.ERROR,
                            message: 'CPF inválido',
                        };
                    }
                } else {
                    // Se PJ: valida CNPJ
                    if (!isValidCNPJ(documentNumber)) {
                        return {
                            type: emitResponse.responseTypes.ERROR,
                            message: 'CNPJ inválido',
                        };
                    }
                    // Também exige nome da empresa
                    if (!companyName.trim()) {
                        return {
                            type: emitResponse.responseTypes.ERROR,
                            message: 'Informe o nome da empresa',
                        };
                    }
                }

                // Se chegou até aqui, dados OK. Envia via payment_data
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
            return () => {
                unsubscribe();
            };
        }, [personType, documentNumber, companyName, onPaymentProcessing, emitResponse]);

        return createElement(
            'div',
            null,
            // Descrição (ex.: "Pague com Pix...")
            createElement(
                'p',
                { style: { color: '#777', fontSize: '0.9rem' } },
                pagouPixData.description
            ),
            // Linha com Select (PF/PJ) e Campo CPF/CNPJ
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
                            onChange: ( e ) => {
                                setPersonType(e.target.value);
                                setDocumentNumber('');
                                setCompanyName('');
                            },
                        },
                        createElement('option', { value: '1' }, 'Pessoa Física'),
                        createElement('option', { value: '2' }, 'Pessoa Jurídica')
                    )
                ),
                // Input CPF/CNPJ
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
                            onChange: ( e ) => handleDocumentChange(e.target.value),
                        }
                    )
                )
            ),
            // Se for Pessoa Jurídica, exibe campo Nome da Empresa
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

    // Registra o método de pagamento PIX
    const PagouPixPaymentMethod = {
        name: 'pagou_pix',
        label: pagouPixData.title,
        ariaLabel: pagouPixData.description,
        content: createElement( PagouPixFields ),
        edit: createElement( PagouPixFields ),
        canMakePayment: () => true,
        supports: {
            features: [ 'products' ],
        },
    };

    registerPaymentMethod( PagouPixPaymentMethod );
} )( window.wc.wcBlocksRegistry, wp.i18n, wp.element );
