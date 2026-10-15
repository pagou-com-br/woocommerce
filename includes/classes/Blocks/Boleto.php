<?php
/**
 * Boleto no checkout em blocos.
 *
 * @package Pagou
 */

namespace Pagou\WooCommerce\Blocks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Boleto extends AbstractMethod {

	protected $name = 'pagou_boleto';
}
