<?php
/**
 * CoinsPay: the test buyer link from the module settings. With a test key the payment method
 * shows only for the test buyer, so real buyers never see a test payment page.
 */
require_once FS_DIR_APP . 'includes/coinspay/loader.php';
header('X-Robots-Tag: noindex');

if (CoinsPay_LiteCartStore::installed()) {
	$coinspay = new CoinsPay_LiteCartFront();
	$text = $coinspay->plugin->buyerText();
	if ($coinspay->becomeTestBuyer(isset($_GET['v']) ? (string)$_GET['v'] : '')) {
		notices::add('success', $text->t('catalog_test_on'));
	} else {
		notices::add('errors', $text->t('catalog_test_bad'));
	}
}
header('Location: ' . document::ilink(''));
exit;
