<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;
use Automattic\WooCommerce\StoreApi\Payments\PaymentContext;
use Automattic\WooCommerce\StoreApi\Payments\PaymentResult;

class ACPPFW_Pagou_Pix_Blocks_Support extends AbstractPaymentMethodType {

    protected $name = 'pagou_pix';
    protected $settings = array();

    public function get_name() {
        return $this->name;
    }

    public function initialize() {
        $this->settings = get_option( 'woocommerce_pagou_pix_settings', array() );

        // Store API:
        add_action(
            'woocommerce_rest_checkout_process_payment_with_context',
            array( $this, 'handle_store_api_payment' ),
            10,
            2
        );
    }

    public function is_active() {
        return isset( $this->settings['enabled'] ) && 'yes' === $this->settings['enabled'];
    }

    public function get_payment_method_script_handles() {
        wp_localize_script(
            'wc-pagou-pix-blocks-integration',
            'pagouPixData',
            array(
                'title'       => $this->settings['title'],
                'description' => $this->settings['description']
            )
        );
        return [ 'wc-pagou-pix-blocks-integration' ];
    }

    public function get_payment_method_script_handles_for_admin() {
        return [ 'wc-pagou-pix-blocks-integration' ];
    }

    public function get_payment_method_data() {
        return array(
            'title'       => $this->settings['title'] ?? 'PIX',
            'description' => $this->settings['description'] ?? 'Pague com PIX usando o gateway Pagou.',
            'supports'    => array( 'products' ),
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

        $gateway = new ACPPFW_Pagou_Pix();
        $api_key = $gateway->get_option('api_key');
        $environment = $gateway->get_option('environment');
        $expiration = $gateway->get_option('expiration');
        $response = ACPPFW_Pagou_Pix::generate_pix_payment(
            $amount,
            $payer_data,
            $order->get_id(),
            $api_key,
            $environment,
            $expiration
        );

        if ( $response && isset( $response['payload']['data'] ) ) {
            $order->update_meta_data('_pagou_pix_response', $response );
            $order->update_meta_data('_pagou_pix_id', $response['id'] );
            $order->save();

            $order->update_status(
                'on-hold',
                __( 'Aguardando pagamento via Pix (Store API).', 'pagou-payments-for-woocommerce' )
            );

            $result->set_status( 'success' );
            $result->set_redirect_url( $this->get_return_url( $order ) );

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
