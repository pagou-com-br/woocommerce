jQuery(document).ready(function($){
    $('.ac-code_copy_b').click(function() {
        var pixCode = $(this).text();
        var $temp = $('<textarea>');
        $('body').append($temp);
        $temp.val(pixCode).select();
        document.execCommand('copy');
        $temp.remove();
        alert('Código PIX copiado!');
    });

    $('.ac-code_copy_v').click(function() {
        var pixCode = $(this).attr('val');
        var $temp = $('<textarea>');
        $('body').append($temp);
        $temp.val(pixCode).select();
        document.execCommand('copy');
        $temp.remove();
        alert('Código copiado!');
    });

    // Máscara para CPF
    $(document).on('focus', '.ac-cpf', function(){
        $(this).mask('000.000.000-00', {reverse: true});
    });

    // Máscara para CNPJ
    $(document).on('focus', '.ac-cnpj', function(){
        $(this).mask('00.000.000/0000-00', {reverse: true});
    });

    // Pesquisa CEP 
    $(document).on('keyup', '#billing_postcode', function ($e){
        var cep = $(this).val();
        cep = cep.replace("-","");
        cep = cep.replace(".","");
        if(cep.length == 8){
            $.ajax({
                type: 'GET',
                url: 'https://viacep.com.br/ws/'+cep+'/json/',
                success:function(json){ 
                    $("#billing_neighborhood").val(json.bairro);
                    $("#billing_city").val(json.localidade);
                    $("#billing_address_1").val(json.logradouro);
                    $("#billing_state").val(json.uf).trigger('change')
                }
            })
        }
    });

    if($('#ac-barcode_bol').length > 0){
        var barcodeValue = $('#ac-barcode_bol').attr('barcode');

        JsBarcode("#ac-barcode_bol", barcodeValue, {
            format: "ITF",
            lineColor: "#000000",
            width: 2,
            height: 100,
            displayValue: false,
            fontSize: 18,
            margin: 10,
            itf14: true
        });
    }

    if($('.ac-load_boleto').length > 0){
        setTimeout(function() { window.location.reload();}, 5000);
    }
});
