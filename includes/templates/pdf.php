<?php if ( ! defined( 'ABSPATH' ) ) { exit; } ?>
<div class="ac-content_widget">
    <div class="ac-pix_cc">
        <h2><?php echo esc_html_e('Pagamento via Boleto', 'pagou-payments-for-woocommerce')?></h2>
        <br>
        <div class="ac-bank_info">
            <p><strong><?php echo esc_html_e('Banco emissor:', 'pagou-payments-for-woocommerce')?></strong> <?php echo esc_html($response['payload']['bank']['issuer'])?></p>
            <p><strong><?php echo esc_html_e('Número:', 'pagou-payments-for-woocommerce')?></strong> <?php echo esc_html($response['payload']['bank']['number'])?></p>
            <p><strong><?php echo esc_html_e('Agência:', 'pagou-payments-for-woocommerce')?></strong> <?php echo esc_html($response['payload']['bank']['agency'])?></p>
            <p><strong><?php echo esc_html_e('Conta:', 'pagou-payments-for-woocommerce')?></strong> <?php echo esc_html($response['payload']['bank']['account'])?></p>
            <p><strong><?php echo esc_html_e('Cedente:', 'pagou-payments-for-woocommerce')?></strong> <?php echo esc_html($response['payload']['bank']['assignor'])?></p>
        </div>
        <svg id="ac-barcode_bol" class="ac-code_copy_v" val="<?php echo esc_attr($response['payload']['line'])?>" barcode="<?php echo esc_attr($barcode)?>"></svg>
        <h4 class="ac-code_copy_v" style="text-align: center" val="<?php echo esc_attr($response['payload']['line'])?>"><?php echo esc_html($formatted_line, 'pagou-payments-for-woocommerce')?></h4>
        <p class="ac-code_copy_v" style="text-align: center;margin-bottom: 20px !important;" val="<?php echo esc_attr($response['payload']['line'])?>"><?php echo esc_html_e('Clique para copiar o código e pagar em seu banco.', 'pagou-payments-for-woocommerce')?></p>
        <button type="button" id="ac-btn_see_pdf" onclick="window.open('https://fatura.pagou.com.br/boleto/pdf/<?php echo esc_attr($response['id'])?>', '_blank');"><?php echo esc_html_e('Ver boleto completo', 'pagou-payments-for-woocommerce')?></button>
    </div>
</div>