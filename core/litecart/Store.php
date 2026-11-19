<?php
/**
 * CoinsPay_Store on LiteCart 2.5 to 2.7. Orders are read with plain SQL and changed through
 * ent_order, so LiteCart moves the stock, logs the status change and sends its e-mails as for any
 * status change made in the admin.
 */
if (!defined('PLATFORM_NAME')) {
	exit;
}

class CoinsPay_LiteCartStore implements CoinsPay_Store {

	/** Module id of the payment module: the key of its row in the modules table. */
	const MODULE = 'pm_coinspay';

	/** @var array|null Settings of the module row, per request. */
	private static $settings = null;

	public function query($sql) {
		$result = database::query($sql);
		if (!is_object($result)) {
			return array();
		}
		$rows = array();
		while ($row = database::fetch($result)) {
			$rows[] = $row;
		}
		return $rows;
	}

	public function escape($value) {
		// No strip_tags and no trim: invoice snapshots are JSON and may hold "<".
		return database::input((string)$value, true, false);
	}

	public function prefix() {
		return DB_TABLE_PREFIX;
	}

	public function shopId() {
		return 1;
	}

	public function setting($key) {
		if ($key === 'secret') {
			// Before install (and after uninstall) the table is not there: LiteCart stops the
			// request on any SQL error, so it is looked up first.
			if (!self::tablesReady()) {
				return null;
			}
			$rows = $this->query('SELECT v FROM `' . DB_TABLE_PREFIX . 'coinspay_kv` WHERE k = \'s1:secret\' LIMIT 1');
			return $rows ? (string)$rows[0]['v'] : null;
		}
		$settings = self::settings();
		$value = array_key_exists($key, $settings) ? $settings[$key] : CoinsPay_LiteCartAdmin::defaultValue($key);
		if ($key === 'cancel_statuses') {
			$ids = array_filter(array_map('trim', is_array($value) ? $value : explode(',', (string)$value)), 'strlen');
			// Left empty: every status in the cancelled state, the shop's own ones included.
			return implode(',', $ids ? $ids : CoinsPay_LiteCartAdmin::cancelledStatuses());
		}
		if (strpos($key, 'status_') === 0 && ($value === '' || $value === null)) {
			// A status list has no "same as Paid" and no "do not change": left empty, an event that
			// may stay without a status keeps the order status and writes a comment only.
			$event = substr($key, 7);
			if (in_array($event, CoinsPay_Settings::$paidLike, true)) {
				return CoinsPay_Settings::SAME_AS_PAID;
			}
			return in_array($event, CoinsPay_Settings::$required, true) ? '' : '0';
		}
		return $value;
	}

	/** The saved settings of the module, decoded. */
	public static function settings() {
		if (self::$settings === null) {
			self::$settings = array();
			$result = database::query('SELECT settings FROM `' . DB_TABLE_PREFIX . 'modules` WHERE module_id = \'' . self::MODULE . '\' LIMIT 1');
			$row = is_object($result) ? database::fetch($result) : null;
			if ($row) {
				$decoded = json_decode((string)$row['settings'], true);
				self::$settings = is_array($decoded) ? $decoded : array();
			}
		}
		return self::$settings;
	}

	/** Whether the payment module is installed: its row and its tables exist. */
	public static function installed() {
		$result = database::query('SELECT id FROM `' . DB_TABLE_PREFIX . 'modules` WHERE module_id = \'' . self::MODULE . '\' LIMIT 1');
		return is_object($result) && (bool)database::fetch($result) && self::tablesReady();
	}

	/** @var bool|null The module's tables exist, per request. */
	private static $tablesReady = null;

	/** Whether the module's tables exist; true once install() made them. */
	public static function tablesReady($recheck = false) {
		if (self::$tablesReady === null || $recheck) {
			$result = database::query('SHOW TABLES LIKE \'' . database::input(DB_TABLE_PREFIX . 'coinspay_kv', true, false) . '\'');
			self::$tablesReady = is_object($result) && (bool)database::fetch($result);
		}
		return self::$tablesReady;
	}

	public function defaultCurrency() {
		return (string)settings::get('store_currency_code');
	}

	public function statusExists($statusId) {
		return (bool)$this->query('SELECT id FROM `' . DB_TABLE_PREFIX . 'order_statuses` WHERE id = ' . (int)$statusId . ' LIMIT 1');
	}

	public function order($orderId) {
		$row = $this->orderRow($orderId);
		if (!$row) {
			return null;
		}
		$items = array();
		foreach ($this->query('SELECT name, quantity FROM `' . DB_TABLE_PREFIX . 'orders_items` WHERE order_id = ' . (int)$row['id'] . ' ORDER BY priority, id') as $item) {
			$items[] = array('name' => $item['name'], 'quantity' => CoinsPay_Util::normalize((string)(float)$item['quantity']));
		}
		// Totals are kept in the store currency; the order shows them at its currency value.
		$value = (float)$row['currency_value'] > 0 ? (float)$row['currency_value'] : 1.0;
		return array(
			'id' => (int)$row['id'],
			'reference' => (string)$row['id'],
			'status_id' => (int)$row['order_status_id'],
			'total' => (float)$row['payment_due'] * $value,
			'default_total' => (float)$row['payment_due'],
			'currency_code' => (string)$row['currency_code'],
			'ours' => strpos((string)$row['payment_option_id'], self::MODULE . ':') === 0,
			'items' => $items,
			'public_key' => (string)$row['public_key'],
			'language_code' => (string)$row['language_code'],
			'date_paid' => (string)$row['date_paid'],
		);
	}

	private function orderRow($orderId) {
		$rows = $this->query('SELECT id, order_status_id, payment_option_id, payment_due, currency_code, currency_value, public_key, language_code, date_paid FROM `'
			. DB_TABLE_PREFIX . 'orders` WHERE id = ' . (int)$orderId . ' LIMIT 1');
		return $rows ? $rows[0] : null;
	}

	/**
	 * Sets the status through ent_order (stock, the status comment and e-mails as in the admin)
	 * and adds the comment hidden from the customer.
	 */
	public function addHistory($orderId, $statusId, $comment) {
		$order = new ent_order((int)$orderId);
		if (empty($order->data['id'])) {
			throw new RuntimeException('order ' . (int)$orderId . ' not found');
		}
		$changed = false;
		if ((int)$statusId > 0 && (int)$order->data['order_status_id'] !== (int)$statusId) {
			$order->data['order_status_id'] = (int)$statusId;
			$changed = true;
		}
		if ($comment !== '') {
			$order->data['comments'][] = array(
				'author' => 'system',
				'text' => CoinsPay_Util::truncate($comment, 2000),
				'hidden' => 1,
			);
			$changed = true;
		}
		if ($changed) {
			$order->save();
		}
		$after = $this->orderRow($orderId);
		return $after ? (int)$after['order_status_id'] : (int)$statusId;
	}

	public function paid($orderId, $invoiceId) {
		$this->query('UPDATE `' . DB_TABLE_PREFIX . 'orders` SET
			date_paid = IF(date_paid IS NULL, \'' . date('Y-m-d H:i:s') . '\', date_paid),
			payment_transaction_id = \'' . $this->escape(strtolower((string)$invoiceId)) . '\'
			WHERE id = ' . (int)$orderId . ' LIMIT 1');
	}

	public function returnUrl($orderId, $token) {
		return document::ilink('coinspay_return', array('order_id' => (int)$orderId, 'token' => $token), false);
	}

	public function webhookUrl() {
		return $this->storeLink('coinspay_webhook', array());
	}

	public function cronUrl($key) {
		// The webhook page polls on GET with the key: LiteCart's bot challenge and CSRF check let
		// addresses with "webhook" through, so a cron job of the hosting reaches it as well.
		return $this->storeLink('coinspay_webhook', array('key' => $key));
	}

	/** A storefront link in the store language: the same address whoever builds it. */
	public function storeLink($page, $params) {
		return document::ilink($page, $params, false, array(), settings::get('store_language_code'));
	}

	public function siteUrl() {
		return (string)document::link(WS_DIR_APP, array(), false);
	}

	public function adminLanguage() {
		// Order comments are for the staff: in the admin in the admin's language, elsewhere
		// (webhooks, the buyer's return, the cron) in the store language.
		$admin = defined('WS_DIR_ADMIN') && isset($_SERVER['REQUEST_URI']) && strpos((string)$_SERVER['REQUEST_URI'], WS_DIR_ADMIN) === 0;
		return self::lang($admin ? language::$selected['code'] : settings::get('store_language_code'));
	}

	public function buyerLanguage() {
		return self::lang(isset(language::$selected['code']) ? language::$selected['code'] : '');
	}

	public static function lang($code) {
		return strtolower((string)$code) === 'ru' ? 'ru' : 'en';
	}

	public function userAgent() {
		return 'CoinsPay-LiteCart/' . CoinsPay_Plugin::VERSION . ' (LiteCart ' . PLATFORM_VERSION . '; PHP ' . PHP_VERSION . ')';
	}

	public function log($message, $error) {
		if (!$error && !$this->setting('debug')) {
			return;
		}
		$dir = (defined('FS_DIR_STORAGE') ? FS_DIR_STORAGE : FS_DIR_APP) . 'logs/';
		if (!is_dir($dir)) {
			return;
		}
		$line = '[' . date('Y-m-d H:i:s') . '] ' . ($error ? 'ERROR ' : '') . str_replace(array("\r", "\n"), ' ', CoinsPay_Util::truncate((string)$message, 1000)) . PHP_EOL;
		@file_put_contents($dir . 'coinspay.log', $line, FILE_APPEND | LOCK_EX);
	}
}
