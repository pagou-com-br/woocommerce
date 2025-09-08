<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

add_action('woocommerce_api_acppfw_pix', 'acppfw_payment_status_handler');
function acppfw_payment_status_handler($request ='') {
    $body = json_decode(file_get_contents('php://input'), true);

    if (!isset($body['name']) || !isset($body['data']['external_id'])) {
        wp_send_json(array('message' => 'Dados inválidos.'));
    }

    $order_id = $body['data']['external_id'];
    $order = wc_get_order($order_id);

    if (!$order) {
        global $wpdb;
        $meta_query = sanitize_text_field($body['data']['id']);
        $cache_key = 'pagou_order_id_' . $meta_query; 
        $order_id = wp_cache_get( $cache_key );

        if ( false === $order_id ) {
            $order_id = $wpdb->get_var($wpdb->prepare("
                SELECT order_id FROM {$wpdb->prefix}wc_orders_meta
                WHERE meta_key = '_pagou_pix_id'
                AND meta_value = %s
                LIMIT 1
            ", $meta_query));
            
            if ( $order_id ) {
                wp_cache_set( $cache_key, $order_id, '', 3600 );
            }
        }

        if ( $order_id ) {
            $order = wc_get_order($order_id);
        }

    }

    if (!$order) {
        wp_send_json(array('message' => 'Pedido #'.$order_id.' não encontrado.'));
    }

    switch ($body['name']) {
        case 'qrcode.completed':
            $order->payment_complete();
            $order->add_order_note('Pagamento confirmado via PIX.');
            break;
        
        case 'qrcode.refunded':
            $order->update_status('refunded', 'Pagamento extornado via PIX.');
            break;

        case 'qrcode.canceled':
            $order->update_status('cancelled', 'Pagamento cancelado via PIX.');
            break;

        default:
            wp_send_json(array('message' => 'Tipo de notificação desconhecido.'));
    }

    wp_send_json(array('message' => 'Status do pedido atualizado com sucesso.'));
}

add_action('woocommerce_api_acppfw_boleto', 'acppfw_boleto_status_handler');
function acppfw_boleto_status_handler() {
    $body = json_decode(file_get_contents('php://input'), true);

    if (!isset($body['name']) || !isset($body['data']['transaction_id'])) {
        wp_send_json(array('message' => 'Dados inválidos.'));
    }

    $order_id = $body['data']['transaction_id']; 
    $order = wc_get_order($order_id);

    if (!$order) {
        global $wpdb;
        $meta_query = sanitize_text_field( $body['data']['id'] );
        $cache_key = 'pagou_boleto_order_id_' . $meta_query; 
        $order_id = wp_cache_get( $cache_key );

        if ( false === $order_id ) {
            $order_id = $wpdb->get_var($wpdb->prepare("
                SELECT order_id FROM {$wpdb->prefix}wc_orders_meta
                WHERE meta_key = '_pagou_boleto_id'
                AND meta_value = %s
                LIMIT 1
            ", $meta_query));
            
            if ( $order_id ) {
                wp_cache_set( $cache_key, $order_id, '', 3600 );
            }
        }

        if ( $order_id ) {
            $order = wc_get_order($order_id);
        }
    }

    if (!$order) {
        wp_send_json(array('message' => 'Pedido #'.$order_id.' não encontrado.'));
    }

    switch ($body['name']) {
        case 'charge.paid':
            $order->payment_complete();
            $order->add_order_note('Pagamento de boleto confirmado.');
            break;
        
        case 'charge.refunded':
            $order->update_status('refunded', 'Pagamento extornado via Boleto.');
            break;

        case 'charge.canceled':
            $order->update_status('cancelled', 'Pagamento cancelado via Boleto.');
            break;

        default:
            wp_send_json(array('message' => 'Tipo de notificação desconhecido.'));
    }

    wp_send_json(array('message' => 'Status do pedido atualizado.'));
}

function acppfw_pagou_activate() {
    flush_rewrite_rules();
}

function acppfw_pagou_cart_checkout_blocks_compatibility() {
    if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'woocommerce_blocks_checkout',
            __FILE__,
            true
        );
    }
}
add_action( 'before_woocommerce_init', 'acppfw_pagou_cart_checkout_blocks_compatibility' );
add_action( 'woocommerce_admin_order_data_after_order_details', 'acppfw_display_customer_view_link_in_order' );

function acppfw_display_customer_view_link_in_order($order) {
    $pix_response = $order->get_meta('_pagou_pix_response');
    $boleto_response = $order->get_meta('_pagou_boleto_response');

    if ($pix_response || $boleto_response) {
        $order_id = $order->get_id();
        $account_view_link = wc_get_account_endpoint_url('view-order') . $order_id . '/';
        echo '<p style="margin: 20px 0 !important;display: flow-root;width: 100%"><br><a href="' . esc_url($account_view_link) . '" target="_blank">'.esc_html_e('Detalhes do pedido e pagamento', 'pagou-payments-for-woocommerce').'</a></p>';
    }
}

function acppfw_msg_error($error = '') {
    if (!is_array($error) || empty($error)) {
        return 'Erro desconhecido.';
    }

    $translated_errors = array_map(function ($msg) {
        $msg = str_replace(' is invalid', '', $msg);
        $msg = str_replace('Name', 'Nome Completo', $msg);
        $msg = str_replace('Document', 'Documento (CPF/CNPJ)', $msg);
        $msg = str_replace('Zip', 'CEP', $msg);
        $msg = str_replace('Street', 'Rua', $msg);
        $msg = str_replace('City', 'Cidade', $msg);
        $msg = str_replace('State', 'Estado', $msg);
        $msg = str_replace('Number', 'Número', $msg);
        $msg = str_replace('Neighborhood', 'Bairro', $msg);
        return $msg;
    }, $error);

    $error_message = implode(', ', array_slice($translated_errors, 0, -1));
    if (count($translated_errors) > 1) {
        $error_message .= ' e ' . end($translated_errors);
    } else {
        $error_message = $translated_errors[0];
    }

    return $error_message;
}
