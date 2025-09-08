<?php if ( ! defined( 'ABSPATH' ) ) { exit; } ?>
<div class="ac-content_widget">
    <div class="ac-pix_cc">
        <h2><?php echo esc_html_e('Pagamento via PIX', 'pagou-payments-for-woocommerce')?></h2>
        <p><?php echo esc_html_e('Escaneie o código QR abaixo para realizar o pagamento ou copie o código PIX.', 'pagou-payments-for-woocommerce')?></p>
        <img src="data:image/png;base64,<?php echo esc_attr($pix_response['payload']['image'])?>" class="ac-pix_qr" alt="QR Code PIX" />
        <p class="ac-pix_or"><?php echo esc_html_e('ou clique abaixo para copiar o código e pagar em seu banco', 'pagou-payments-for-woocommerce')?></p>
        <p class="ac-code_copy_b"><?php echo esc_html($pix_response['payload']['data'])?></p>
    </div>
</div>