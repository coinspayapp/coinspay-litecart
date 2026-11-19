<?php
/**
 * Loads the CoinsPay core, and the LiteCart layer when LiteCart runs (unit tests load the
 * core alone). Plain require_once: the LiteCart autoloader knows only its own class prefixes.
 */
if (!defined('PLATFORM_NAME') && !defined('COINSPAY_TESTS')) {
	exit;
}

if (!class_exists('CoinsPay_Plugin', false)) {
	$coinspay_dir = dirname(__FILE__);
	require_once $coinspay_dir . '/Util.php';
	require_once $coinspay_dir . '/ApiError.php';
	require_once $coinspay_dir . '/ApiClient.php';
	require_once $coinspay_dir . '/Signature.php';
	require_once $coinspay_dir . '/Store.php';
	require_once $coinspay_dir . '/Settings.php';
	require_once $coinspay_dir . '/Text.php';
	require_once $coinspay_dir . '/InvoiceRequest.php';
	require_once $coinspay_dir . '/Decision.php';
	require_once $coinspay_dir . '/Repo.php';
	require_once $coinspay_dir . '/Currencies.php';
	require_once $coinspay_dir . '/Tokens.php';
	require_once $coinspay_dir . '/Sync.php';
	require_once $coinspay_dir . '/Payment.php';
	require_once $coinspay_dir . '/Poller.php';
	require_once $coinspay_dir . '/Webhook.php';
	require_once $coinspay_dir . '/Plugin.php';
	if (defined('PLATFORM_NAME')) {
		require_once $coinspay_dir . '/litecart/Store.php';
		require_once $coinspay_dir . '/litecart/Front.php';
		require_once $coinspay_dir . '/litecart/Admin.php';
	}
	unset($coinspay_dir);
}
