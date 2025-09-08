<?php
 
if ( ! defined( 'ABSPATH' ) ) { exit; }

class ACPPFW_Pagou_Boleto extends WC_Payment_Gateway {

    public function __construct() {
        $this->id = 'pagou_boleto';
        $this->icon = '';
        $this->has_fields = false;
        $this->method_title = 'Boleto';
        $this->method_description = 'Pagamento via Boleto utilizando o gateway Pagou.';
        
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
        $this->title = $this->get_option('title');
        $this->description = $this->get_option('description');
        $this->enabled = $this->get_option('enabled');
        $this->debug = $this->get_option('debug');
        $this->environment = $this->get_option('environment', 'pro'); // "pro" por padrão

        // Salva as configurações
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));

        // Exibe código de pagamento
        add_action('woocommerce_thankyou_' . $this->id, array($this, 'display_boleto'));
        add_action('woocommerce_view_order', array($this, 'display_boleto'), 20);
    }

    public function init_form_fields() {
        $this->form_fields = array(
            'enabled' => array(
                'title'       => 'Ativar/Desativar',
                'label'       => 'Ativar Pagou Boleto',
                'type'        => 'checkbox',
                'description' => '',
                'default'     => 'no'
            ),
            'title' => array(
                'title'       => 'Título',
                'type'        => 'text',
                'description' => 'Título exibido no checkout.',
                'default'     => 'Boleto',
                'desc_tip'    => true,
            ),
            'description' => array(
                'title'       => 'Descrição',
                'type'        => 'textarea',
                'description' => 'Descrição exibida no checkout.',
                'default'     => 'Pague com Boleto usando o gateway Pagou.',
            ),
            'grace_period' => array(
                'title'       => 'Prazo Adicional para Pagamento',
                'type'        => 'number',
                'description' => 'Dias adicionais para o vencimento do boleto.',
                'default'     => '5',
                'desc_tip'    => true,
            ),
            'fine' => array(
                'title'       => 'Multa por Atraso (%)',
                'type'        => 'number',
                'description' => 'Percentual da multa por atraso do pagamento.',
                'default'     => '0',
                'desc_tip'    => true,
            ),
            'interest' => array(
                'title'       => 'Juros por Atraso (%)',
                'type'        => 'number',
                'description' => 'Percentual de juros diário por atraso do pagamento.',
                'default'     => '0',
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

    public function process_payment($order_id) {
        $order = wc_get_order($order_id);
        $amount = $order->get_total();
        $billing = $order->get_address('billing');
        $billing_cpf = $order->get_meta('_billing_cpf');
        $billing_cnpj = $order->get_meta('_billing_cnpj');
        $billing_persontype = $order->get_meta('_billing_persontype');

        $payer_data = array(
            'document' => $billing_persontype == 1 ? preg_replace('/\D/', '', $billing_cpf) : preg_replace('/\D/', '', $billing_cnpj),
            'name' => $billing_persontype == 1 ? $billing['first_name'].' '.$billing['last_name'] : $billing['company'],
            'city' => $billing['city'],
            'neighborhood' => $billing['neighborhood'],
            'number' => !empty($billing['number']) ? $billing['number'] : "0",
            'state' => $billing['state'],
            'street' => $billing['address_1'],
            'zip' => str_replace('.', '', str_replace('-', '', $billing['postcode']))
        );
        $api_key = $this->get_option('api_key');
        $environment = $this->get_option('environment', 'pro');
        $grace_period = $this->get_option('grace_period', 5);
        $fine = $this->get_option('fine', 0);
        $interest = $this->get_option('interest', 0);
        $response = $this->generate_pagou_boleto($amount, $payer_data, $order_id, $api_key, $environment, $grace_period, $fine, $interest);

        if ($response && isset($response['id'])) {
            $order->update_meta_data('_pagou_boleto_response', $response);
            $order->update_meta_data('_pagou_boleto_id', $response['id']);
            $order->save();
            $order->update_status('on-hold', __('Aguardando pagamento via Boleto.', 'pagou-payments-for-woocommerce'));
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
                wc_add_notice('Erro ao gerar o boleto. Tente novamente.', 'error');
            }
            return array(
                'result'   => 'failure',
                'redirect' => '',
            );
        }
    }

    public static function generate_pagou_boleto($amount, $payer_data, $order_id, $api_key, $environment, $grace_period, $fine, $interest) {
        $url = ACPPFW_PAGOU_URLS[$environment] . '/v1/charges';

        $body = wp_json_encode(array(
            'amount' => floatval($amount),
            'notification_url' => get_home_url(null, 'wc-api/acppfw_boleto'),
            'external_id' => $order_id,
            'description' => 'Pedido ' . $order_id,
            'grace_period' => intval($grace_period),
            'due_date' => gmdate('Y-m-d', strtotime('+' . intval($grace_period) . ' days')),
            'fine' => floatval($fine),
            'interest' => floatval($interest),
            'payer' => $payer_data,
        ));

        $response = wp_remote_post($url, array(
            'method'    => 'POST',
            'body'      => $body,
            'headers'   => array(
                'Content-Type'  => 'application/json',
                'X-API-KEY'     => $api_key
            ),
        ));

        if (is_wp_error($response)) {
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        return json_decode($body, true);
    }

    public function display_boleto($order_id) {
        wp_enqueue_script('acppfw_pagou_script');
        wp_enqueue_style('acppfw_pagou_style');

        $order = wc_get_order($order_id);

        if($order->get_payment_method() !== 'pagou_boleto'){
            return;
        }

        $order_status = $order->get_status();
        if ($order_status === 'completed' || $order_status === 'processing') {
            echo '<p>'.esc_html_e('Recebemos seu pagamento via boleto bancario para este pedido.', 'pagou-payments-for-woocommerce').'</p>';
            return;
        }

        $response = $order->get_meta('_pagou_boleto_response');

        if(!isset($response['payload']['bar_code']) && isset($response['id'])){
            $api_key = $this->get_option('api_key');
            $url = ACPPFW_PAGOU_URLS[$this->environment] . '/v1/charges/'. $response['id'] .'/';

            $response = wp_remote_post($url, array(
                'method'    => 'GET',
                'headers'   => array(
                    'Content-Type'  => 'application/json',
                    'X-API-KEY'     => $api_key
                ),
            ));
        
            if (!is_wp_error($response)) {
                $body = wp_remote_retrieve_body($response);
                $response = json_decode($body, true);

                if(isset($body['payload']['bar_code'])){
                    $order->update_meta_data('_pagou_boleto_response', $response);
                    $order->save();
                }
            }
        }

        if ($response && isset($response['payload']['bar_code'])) {
            
            $barcode = $response['payload']['bar_code'];
            $line = $response['payload']['line'];
            $formatted_line = substr($line, 0, 5) . '.' . substr($line, 5, 5) . ' ' . 
                              substr($line, 10, 5) . '.' . substr($line, 15, 6) . ' ' . 
                              substr($line, 21, 5) . '.' . substr($line, 26, 6) . ' ' . 
                              substr($line, 32, 1) . ' ' . substr($line, 33);

            include_once ACPPFW_PAGOU_PATH_SERVER . 'includes/templates/pdf.php';
        } else {
            include_once ACPPFW_PAGOU_PATH_SERVER . 'includes/templates/pdf_loading.php';
        }
    }
    public function process_refund($order_id, $amount = null, $reason = '') {
        $order = wc_get_order($order_id);
        $boleto_response = $order->get_meta('_pagou_boleto_response');

        if (!$boleto_response || !isset($boleto_response['id'])) {
            return new WP_Error('pagou_refund_error', 'Não foi possível encontrar o ID do boleto para estorno.');
        }

        $charge_id = $boleto_response['id'];
        $environment = $this->get_option('environment', 'pro');
        $refund_url = ACPPFW_PAGOU_URLS[$environment] . '/v1/charges/' . $charge_id . '/refund';

        $body = wp_json_encode(array(
            'amount' => floatval($amount),
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

        $response_body = wp_json_encode(wp_remote_retrieve_body($response), true);

        if (isset($response_body['error'])) {
            return new WP_Error('pagou_refund_error', 'Erro ao estornar o pagamento: ' . $response_body['error']);
        }

        $order->add_order_note('Estorno via Boleto processado com sucesso. Valor: R$' . $amount);
        return true;
    }
}
