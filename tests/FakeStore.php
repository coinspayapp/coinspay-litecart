<?php
/**
 * A shop for the unit tests: settings in an array, order statuses in a list, SQL recorded.
 * Status ids: 2 Processing, 6 Cancelled, 8 another status; 14 and 15 stand for the module's own
 * "awaiting" and "review" statuses.
 */
class FakeStore implements CoinsPay_Store {

	public $settings = array();
	public $statuses = array(2, 6, 8, 14, 15);
	public $queries = array();
	public $logs = array();

	public function query($sql) {
		$this->queries[] = $sql;
		return array();
	}

	public function escape($value) {
		return addslashes($value);
	}

	public function prefix() {
		return 'lc_';
	}

	public function shopId() {
		return 1;
	}

	public function setting($key) {
		return isset($this->settings[$key]) ? $this->settings[$key] : null;
	}

	public function defaultCurrency() {
		return 'USD';
	}

	public function statusExists($statusId) {
		return in_array((int)$statusId, $this->statuses, true);
	}

	public function order($orderId) {
		return null;
	}

	public function addHistory($orderId, $statusId, $comment) {
		return $statusId;
	}

	public $paid = array();

	public function paid($orderId, $invoiceId) {
		$this->paid[] = array((int)$orderId, (string)$invoiceId);
	}

	public function returnUrl($orderId, $token) {
		return 'https://shop.example/coinspay_return?order_id=' . $orderId . '&token=' . $token;
	}

	public function webhookUrl() {
		return 'https://shop.example/coinspay_webhook';
	}

	public function cronUrl($key) {
		return 'https://shop.example/coinspay_webhook?key=' . $key;
	}

	public function siteUrl() {
		return 'https://shop.example/';
	}

	public function adminLanguage() {
		return 'en';
	}

	public function buyerLanguage() {
		return 'en';
	}

	public function userAgent() {
		return 'CoinsPay-LiteCart/test';
	}

	public function log($message, $error) {
		$this->logs[] = $message;
	}
}
