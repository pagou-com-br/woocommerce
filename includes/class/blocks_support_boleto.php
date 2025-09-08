<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;
use Automattic\WooCommerce\StoreApi\Payments\PaymentContext;
use Automattic\WooCommerce\StoreApi\Payments\PaymentResult;

class ACPPFW_Pagou_Boleto_Blocks_Support extends AbstractPaymentMethodType {

    protected $name = 'pagou_boleto';
    protected $settings = array();

    public function get_name() {
        return $this->name;
    }

    public function initialize() {
        $this->settings = get_option( 'woocommerce_pagou_boleto_settings', array() );

        // Store API:
        add_action(
            'woocommerce_rest_checkout_process_payment_with_context',
            array( $this, 'handle_store_api_payment' ),
            10,
            2
        );
    }

    public function is_active() {
        return ! empty( $this->settings['enabled'] ) && 'yes' === $this->settings['enabled'];
    }

    public function get_payment_method_script_handles() {
        wp_localize_script(
            'wc-pagou-boleto-blocks-integration',
            'pagouBoletoData',
            array(
                'title'       => $this->settings['title'],
                'description' => $this->settings['description']
            )
        );
        return [ 'wc-pagou-boleto-blocks-integration' ];
    }

    public function get_payment_method_data() {
        return array(
            'title'       => $this->settings['title'] ?? 'Boleto',
            'description' => $this->settings['description'] ?? '',
        );
    }

    public function handle_store_api_payment( PaymentContext $context, PaymentResult $result ) {
        if ( $context->payment_method !== $this->name ) {
            return; 
        }

        $personType     = $context->payment_data['persontype']     ?? '';
        $documentNumber = $context->payment_data['documentnumber'] ?? '';
        $companyName    = $context->payment_data['companyname']    ?? '';
        $order = $context->order; 
        $order->update_meta_data( '_billing_persontype', $personType );
        $amount = (float) $order->get_total();

        if ( '2' === $personType && ! empty( $companyName ) ) {
            $order->update_meta_data( '_billing_cpf', $documentNumber );
            $order->update_meta_data( '_billing_company', $companyName );
        } else {
            $order->update_meta_data( '_billing_cnpj',$documentNumber );
        }

        $order->save();

        $payer_data = array(
            'document' => $personType == 1 ? preg_replace("/[^0-9]/", "", $documentNumber) : $documentNumber
            ,
            'name'     => $personType == 1 
                ? $order->get_billing_first_name().' '.$order->get_billing_last_name() 
                : $companyName
        );

        $gateway = new ACPPFW_Pagou_Boleto();
        $api_key = $gateway->get_option('api_key');
        $environment = $gateway->get_option('environment');
        $grace_period = $gateway->get_option('grace_period', 5);
        $fine = $gateway->get_option('fine', 0);
        $interest = $gateway->get_option('interest', 0);
        $response = ACPPFW_Pagou_Boleto::generate_pagou_boleto(
            $amount,
            $payer_data,
            $order->get_id(),
            $api_key,
            $environment,
            $grace_period,
            $fine,
            $interest
        );

        if ( $response && isset( $response['payload']['data'] ) ) {
            $order->update_meta_data('_pagou_boleto_response', $response );
            $order->update_meta_data('_pagou_boleto_id', $response['id'] );
            $order->save();

            $order->update_status(
                'on-hold',
                __( 'Aguardando pagamento via Boleot (Store API).', 'pagou-payments-for-woocommerce' )
            );

            $result->set_status( 'success' );
            $result->set_redirect_url( $order->get_checkout_order_received_url() );

            // Dados extras
            $result->set_payment_details(
                array_merge(
                    $result->payment_details,
                    array( 'pix_store_api' => 'Pagamento gerado com sucesso via Store API' )
                )
            );
        } else {
            $result->set_status( 'error' );

            // Dados extras
            $result->set_payment_details(
                array_merge(
                    $result->payment_details,
                    array( 'error' => 'Falha ao gerar QR Code via Store API' )
                )
            );
        }
    }

    private function get_return_url( $order ) {
        return $order ? $order->get_checkout_order_received_url() : wc_get_checkout_url();
    }
}