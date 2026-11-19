<?php
/**
 * CoinsPay: POST — a webhook event of the shop's CoinsPay shop; GET with ?key= — polling of open
 * invoices by a cron job of the hosting. One address for both: LiteCart's bot challenge and CSRF
 * check let addresses with "webhook" through.
 */
require_once FS_DIR_APP . 'includes/coinspay/loader.php';
header('X-Robots-Tag: noindex');

if (!CoinsPay_LiteCartStore::installed()) {
	CoinsPay_LiteCartFront::answer(array(404, 'CoinsPay is not installed'));
}

$coinspay = new CoinsPay_LiteCartFront();
if (isset($_GET['key']) && (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST')) {
	CoinsPay_LiteCartFront::answer($coinspay->cron((string)$_GET['key']));
}
CoinsPay_LiteCartFront::answer($coinspay->webhook());
