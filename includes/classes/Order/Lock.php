<?php
/**
 * Trava atômica por pedido.
 *
 * Webhook, retorno do checkout, tela de pagamento e cron podem tentar confirmar o mesmo
 * pagamento ao mesmo tempo. A trava usa INSERT IGNORE na tabela de opções, que é
 * atômico no MySQL (chave única em option_name), em vez de get/set de meta, que não é.
 *
 * Uma trava esquecida (processo morto no meio) expira sozinha depois de TTL segundos.
 *
 * @package Pagou
 */

namespace Pagou\WooCommerce\Order;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Lock {

	const TTL = 60;

	/**
	 * Tenta adquirir a trava. Retorna false se outro processo a detém.
	 *
	 * @param int    $order_id
	 * @param string $escopo Ex.: 'pagamento'.
	 * @return bool
	 */
	public static function adquirir( $order_id, $escopo = 'pagamento' ) {
		global $wpdb;

		$nome  = self::nome( $order_id, $escopo );
		$agora = time();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- trava atômica; não pode passar por cache.
		$inseriu = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')",
				$nome,
				(string) $agora
			)
		);

		if ( $inseriu ) {
			return true;
		}

		// Trava existente: se estiver vencida, toma posse de forma atômica (UPDATE condicional).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$tomou = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND CAST(option_value AS UNSIGNED) < %d",
				(string) $agora,
				$nome,
				$agora - self::TTL
			)
		);

		return (bool) $tomou;
	}

	/**
	 * @param int    $order_id
	 * @param string $escopo
	 */
	public static function liberar( $order_id, $escopo = 'pagamento' ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( $wpdb->options, array( 'option_name' => self::nome( $order_id, $escopo ) ) );
	}

	/**
	 * Executa $callback com a trava. Retorna null se a trava estiver ocupada.
	 *
	 * @param int      $order_id
	 * @param callable $callback
	 * @param string   $escopo
	 * @return mixed
	 */
	public static function com_trava( $order_id, callable $callback, $escopo = 'pagamento' ) {
		if ( ! self::adquirir( $order_id, $escopo ) ) {
			return null;
		}

		try {
			return call_user_func( $callback );
		} finally {
			self::liberar( $order_id, $escopo );
		}
	}

	/**
	 * @param int    $order_id
	 * @param string $escopo
	 * @return string
	 */
	private static function nome( $order_id, $escopo ) {
		return '_pagou_lock_' . sanitize_key( $escopo ) . '_' . absint( $order_id );
	}
}
