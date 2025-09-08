<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

// Setup
define('ACPPFW_PAGOU_VERSION', '1.1.2');
// define('ACPPFW_PAGOU_VERSION', '1.1.2'.rand(111111,999999)); // Avoid cache
define('ACPPFW_PAGOU_NAME', 'Pagou');
define('ACPPFW_PAGOU_SLUG', 'pagou-payments-for-woocommerce');
define('ACPPFW_PAGOU_PATH', plugins_url('/', __FILE__));
define('ACPPFW_PAGOU_PATH_URL', __file__);
define('ACPPFW_PAGOU_PATH_SERVER', plugin_dir_path( __FILE__ ));
define('ACPPFW_PAGOU_URLS', array("hom" => "https://sandbox-api.pagou.com.br","pro" => "https://api.pagou.com.br"));

// ini_set('display_errors', '1');
// ini_set('display_startup_errors', '1');
// error_reporting(E_ALL);
