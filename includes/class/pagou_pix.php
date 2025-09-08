<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

class ACPPFW_Pagou_Pix extends WC_Payment_Gateway {

    public function __construct() {
        $this->id = 'pagou_pix';
        $this->icon = '';
        $this->has_fields = false;
        $this->method_title = 'PIX';
        $this->method_description = 'Pagamento via PIX utilizando o gateway Pagou.';

        // Suportes
        $this->supports = array(
            'products',
            'refunds',
            'wc-blocks',
        );

        // Inicializa os campos do formulário de configuração
        $this->init_form_fields();
        $this->init_settings();

        // Carrega as configurações
        $this->title = $this->get_option('title') ?: 'PIX';
        $this->description = $this->get_option('description') ?: 'Pague com PIX usando o gateway Pagou.';
        $this->enabled = $this->get_option('enabled');
        $this->debug = $this->get_option('debug');
        $this->environment = $this->get_option('environment', 'pro'); // "pro" por padrão

        // Salva as configurações
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));

        // Exibe código de pagamento
        add_action('woocommerce_thankyou_' . $this->id, array($this, 'display_pix_qr_code'));
        add_action('woocommerce_view_order',  array($this, 'display_pix_qr_code'), 20);

        // Reembolso
        add_action('woocommerce_order_refunded', array($this, 'process_refund'), 10, 2);
        add_filter('woocommerce_order_actions', array($this, 'hide_manual_refund_button'), 10, 2);
    }

    public function init_form_fields() {
        $this->form_fields = array(
            'enabled' => array(
                'title'       => 'Ativar/Desativar',
                'label'       => 'Ativar Pagou PIX',
                'type'        => 'checkbox',
                'description' => '',
                'default'     => 'no'
            ),
            'title' => array(
                'title'       => 'Título',
                'type'        => 'text',
                'description' => 'Título exibido no checkout.',
                'default'     => 'PIX',
                'desc_tip'    => true,
            ),
            'description' => array(
                'title'       => 'Descrição',
                'type'        => 'textarea',
                'description' => 'Descrição exibida no checkout.',
                'default'     => 'Pague com PIX usando o gateway Pagou.',
            ),
            'expiration' => array(
                'title'       => 'Tempo para pagamento (em segundos)',
                'type'        => 'number',
                'description' => 'Esse é o tempo máximo para pagamento de um PIX.',
                'default'     => '3600000',
                'desc_tip'    => true,
            ),
            'api_key' => array(
                'title'       => 'API Key',
                'type'        => 'text',
                'description' => 'Insira sua chave API da Pagou.',
                'default'     => '',
                'desc_tip'    => true,
            ),
            'environment' => array(
                'title'       => 'Ambiente',
                'type'        => 'select',
                'description' => 'Selecione o ambiente: Homologação ou Produção.',
                'default'     => 'pro',
                'options'     => array(
                    'hom' => 'Homologação',
                    'pro' => 'Produção',
                ),
            ),
            'debug' => array(
                'title'       => 'Modo Debug',
                'label'       => 'Habilitar Modo Debug',
                'type'        => 'checkbox',
                'description' => 'Log de respostas da API em caso de erro.',
                'default'     => 'no',
                'desc_tip'    => true,
            ),
        );
    }

    public function get_payment_method_script_handles() {
        return [ 'wc-pagou-pix-blocks-integration' ];
    }

    public function process_payment($order_id) {
        $order = wc_get_order($order_id);
        $amount = $order->get_total();
        $billing = $order->get_address('billing');
        $billing_cpf = $order->get_meta('_billing_cpf');
        $billing_cnpj = $order->get_meta('_billing_cnpj');
        $billing_persontype = $order->get_meta('_billing_persontype');

        $payer_data = array(
            'document' => $billing_persontype == 1 ? $billing_cpf : $billing_cnpj,
            'name' => $billing_persontype == 1 ? $billing['first_name'].' '.$billing['last_name'] : $billing['company']
        );

        // Gera o pagamento PIX
        $api_key = $this->get_option('api_key');
        $environment = $this->get_option('environment', 'pro');
        $expiration = $this->get_option('expiration');
        $response = $this->generate_pix_payment($amount, $payer_data, $order_id, $api_key, $environment, $expiration);

        if ($response && isset($response['payload']['data'])) {
            $order->update_meta_data('_pagou_pix_response', $response);
            $order->update_meta_data('_pagou_pix_id', $response['id']);
            $order->save();
            $order->update_status('on-hold', __('Aguardando pagamento via Pix.', 'pagou-payments-for-woocommerce'));
            WC()->cart->empty_cart();

            return array(
                'result'   => 'success',
                'redirect' => $this->get_return_url($order),
            );
        } else {
            if (isset($response)) {
                $msg = isset($response['error']) ? $response['error'] : $response;
                $msg = $msg == 'invalid api key' ? '<b>Sua chave de API é inválida para o ambiente de ' . ($this->environment === 'pro' ? 'produção' : 'homologação') . '</b>. Saiba mais acessando seu <a target="_blank" href="'.admin_url('admin.php?page=wc-settings&tab=checkout&section=pagou_pix').'">painel administrativo</a>.' : $msg;
                $msg = $msg == 'validation error' ? '<b>Falha ao validar os dados enviados</b>, verifique os seguintes campos: '.acppfw_msg_error($response['errors']).'.' : $msg;
                wc_add_notice($msg, 'error');
            } else {
                wc_add_notice('Erro ao gerar o QR Code do PIX. Tente novamente.', 'error');
            }
            return array(
                'result'   => 'failure',
                'redirect' => '',
            );
        }
    }

    public static function generate_pix_payment($amount, $payer_data, $order_id, $api_key, $environment, $expiration) {
        $url = ACPPFW_PAGOU_URLS[$environment] . '/v1/pix';

        $body = wp_json_encode(array(
            'amount' => floatval($amount),
            'notification_url' => get_home_url(null, 'wc-api/acppfw_pix'),
            'external_id' => $order_id,
            'description' => 'Pagamento do pedido '.$order_id,
            'expiration' => intval($expiration),
            'metadata' => array(
                array(
                    'key' => 'pedido_id',
                    'value' => $order_id.'000'.uniqid()
                )
            ),
            'payer' => $payer_data,
        ));   

        $response = wp_remote_post($url, array(
            'method'    => 'POST',
            'body'      => $body,
            'headers'   => array(
                'Content-Type'  => 'application/json',
                'X-API-KEY'     => $api_key,
            ),
        ));

        if (is_wp_error($response)) {
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        return json_decode($body, true);
    }

    public function display_pix_qr_code($order_id) {
        wp_enqueue_script('acppfw_pagou_script');
        wp_enqueue_style('acppfw_pagou_style');
        
        $order = wc_get_order($order_id);

        if($order->get_payment_method() !== 'pagou_pix'){
            return;
        }

        $order_status = $order->get_status();
        if ($order_status === 'completed' || $order_status === 'processing') {
            echo '<p>'.esc_html_e('Recebemos seu pagamento via PIX para este pedido.', 'pagou-payments-for-woocommerce').'</p>';
            return;
        }

        $pix_response = $order->get_meta('_pagou_pix_response');

        if ($pix_response && isset($pix_response['payload']['data'])) {
            include_once ACPPFW_PAGOU_PATH_SERVER . 'includes/templates/pix.php';
        } else {
            echo '<p>'.esc_html_e('Erro ao obter o código PIX.', 'pagou-payments-for-woocommerce').'</p>';
        }
    }

    public function process_refund($order_id, $amount = null, $reason = '') {
        $order = wc_get_order($order_id);
        $pix_id = $order->get_meta('_pagou_pix_id');

        if ( empty($pix_id) ) {
            return new WP_Error('pagou_refund_error', 'Não foi possível encontrar o PIX para estorno.');
        }

        $environment = $this->get_option('environment', 'pro');
        $refund_url = ACPPFW_PAGOU_URLS[$environment] . '/v1/pix/' . $pix_id . '/refund';

        $body = wp_json_encode(array(
            'amount' => floatval($amount),
            'reason' => 1,
            'description' => $reason ? $reason : 'Estorno do pedido via WooCommerce.'
        ));

        $response = wp_remote_request($refund_url, array(
            'method'    => 'DELETE',
            'body'      => $body,
            'headers'   => array(
                'Content-Type'  => 'application/json',
                'X-API-KEY'     => $this->get_option('api_key'),
            ),
        ));

        if (is_wp_error($response)) {
            return new WP_Error('pagou_refund_error', 'Erro ao tentar processar o estorno: ' . $response->get_error_message());
        }

        $response_body = json_decode(wp_remote_retrieve_body($response), true);

        if (isset($response_body['error'])) {
            return new WP_Error('pagou_refund_error', 'Erro ao estornar o pagamento: ' . $response_body['error']);
        }

        $order->add_order_note('Estorno via PIX processado com sucesso. Valor: R$' . $amount);
        return true;
    }

    public function hide_manual_refund_button($actions, $order) {
        if ($order->get_payment_method() === $this->id) {
            unset($actions['refund']);
        }

        return $actions;
    }
}