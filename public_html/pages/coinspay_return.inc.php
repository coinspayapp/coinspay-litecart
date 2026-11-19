<?php
/**
 * CoinsPay: the buyer comes back from the payment page.
 */
require_once FS_DIR_APP . 'includes/coinspay/loader.php';
header('X-Robots-Tag: noindex');

if (!CoinsPay_LiteCartStore::installed()) {
	header('Location: ' . document::ilink(''));
	exit;
}

$coinspay = new CoinsPay_LiteCartFront();
header('Location: ' . $coinspay->returnPage(isset($_GET['order_id']) ? $_GET['order_id'] : 0, isset($_GET['token']) ? (string)$_GET['token'] : ''));
exit;
