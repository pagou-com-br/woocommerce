<?php
/**
 * Limpeza na desinstalação do plugin.
 *
 * Remove configurações (que incluem a chave de API), avisos e agendamentos. Pedidos e
 * seus metadados são preservados de propósito: são registro financeiro da loja.
 *
 * @package Pagou
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

wp_clear_scheduled_hook( 'pagou_wc_conciliar' );
wp_clear_scheduled_hook( 'acppfw_pagou_conciliar' );

foreach ( array( 'pagou_wc_settings', 'pagou_wc_avisos', 'pagou_wc_db_version', 'pagou_wc_ativado_em', 'woocommerce_pagou_pix_settings', 'woocommerce_pagou_boleto_settings', 'woocommerce_pagou_cartao_settings' ) as $pagou_wc_opcao ) {
	delete_option( $pagou_wc_opcao );
}
