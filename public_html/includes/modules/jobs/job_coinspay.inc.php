<?php
/**
 * CoinsPay: asks CoinsPay about open invoices, for payments whose webhook did not arrive, and
 * cancels the open invoice of an order the shop cancelled. Installed with the payment module.
 */

#[AllowDynamicProperties]
class job_coinspay {
	public $id = __CLASS__;
	public $name = 'CoinsPay';
	public $description = 'Checks open CoinsPay invoices: payments whose webhook did not arrive, invoices of cancelled orders.';
	public $author = 'CoinsPay';
	public $version = '0.1.1';
	public $website = 'https://coinspay.app';
	public $priority = 0;

	public function process($force, $last_run) {
		if (empty($this->settings['status'])) {
			return;
		}
		require_once FS_DIR_APP . 'includes/coinspay/loader.php';
		if (!CoinsPay_LiteCartStore::installed()) {
			return;
		}
		$front = new CoinsPay_LiteCartFront();
		echo $front->job() . PHP_EOL;
	}

	public function settings() {
		return array(
			array(
				'key' => 'status',
				'default_value' => '1',
				'title' => language::translate('title_status', 'Status'),
				'description' => '',
				'function' => 'toggle("e/d")',
			),
			array(
				'key' => 'priority',
				'default_value' => '0',
				'title' => language::translate('title_priority', 'Priority'),
				'description' => '',
				'function' => 'number()',
			),
		);
	}

	public function install() {
	}

	public function uninstall() {
	}
}
