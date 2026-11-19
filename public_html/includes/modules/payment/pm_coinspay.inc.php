<?php
/**
 * CoinsPay: cryptocurrency payments for LiteCart 2.5 to 2.7. Buyers pay in USDT, BTC, ETH and
 * 30+ coins on the CoinsPay payment page; the money goes straight to the shop's wallets.
 * The work is done by the CoinsPay core in includes/coinspay; this class is LiteCart's entry.
 */

#[AllowDynamicProperties]
class pm_coinspay {
	public $id = __CLASS__;
	public $name = 'CoinsPay';
	public $description = '';
	public $author = 'CoinsPay';
	public $version = '0.1.2';
	public $support_link = 'https://coinspay.app/plugins/litecart';
	public $website = 'https://coinspay.app';
	public $priority = 0;

	/** @var CoinsPay_LiteCartFront|null */
	private $front = null;

	public function __construct() {
		require_once FS_DIR_APP . 'includes/coinspay/loader.php';
		$this->name = 'CoinsPay';
		// The panel (state, connection, webhook, invoices) only on the settings page of this module.
		if (isset($_GET['doc'], $_GET['module_id']) && $_GET['doc'] === 'edit_payment' && $_GET['module_id'] === __CLASS__
			&& class_exists('user', false) && !empty(user::$data['id'])) {
			$admin = new CoinsPay_LiteCartAdmin();
			$this->description = $admin->panel();
		}
	}

	private function front() {
		if ($this->front === null) {
			$this->front = new CoinsPay_LiteCartFront();
		}
		return $this->front;
	}

	public function options($items, $subtotal, $tax, $currency_code, $customer) {
		return $this->front()->options(isset($this->settings) ? $this->settings : array(), $subtotal, $tax, $currency_code, $customer);
	}

	public function transfer($order, $success_url = '', $cancel_url = '') {
		return $this->front()->transfer($order);
	}

	public function verify($order) {
		return $this->front()->verify($order);
	}

	public function after_process($order) {
		$this->front()->afterProcess();
	}

	public function receipt($order) {
		return $this->front()->receipt($order);
	}

	public function settings() {
		return CoinsPay_LiteCartAdmin::fields();
	}

	public function install() {
		$admin = new CoinsPay_LiteCartAdmin();
		$admin->install($this->settings);
	}

	public function uninstall() {
		$admin = new CoinsPay_LiteCartAdmin();
		$admin->uninstall();
	}
}
