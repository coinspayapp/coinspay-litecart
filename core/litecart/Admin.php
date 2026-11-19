<?php
/**
 * Admin side of the module: the settings fields LiteCart draws, install and uninstall, and the
 * panel above the fields (state, connection check, webhook address, test buyer link, invoices).
 * LiteCart prints the module description as HTML, so the panel lives there.
 */
if (!defined('PLATFORM_NAME')) {
	exit;
}

class CoinsPay_LiteCartAdmin {

	/** Module id of the polling job, registered together with the payment module. */
	const JOB = 'job_coinspay';

	/** The panel checks the saved key again after this many seconds. */
	const CHECK_EVERY = 600;

	/** Default icon of the method, shipped in the archive. */
	const ICON = 'images/payment/coinspay.png';

	/** @var array Names of the module's own order statuses: kind → language → name. */
	public static $statusNames = array(
		'awaiting' => array('en' => 'Awaiting crypto payment', 'ru' => 'Ожидает криптоплатежа'),
		'review' => array('en' => 'Crypto payment review', 'ru' => 'Проверка криптоплатежа'),
	);

	/** @var CoinsPay_LiteCartStore */
	private $store;

	/** @var CoinsPay_Plugin */
	private $plugin;

	public function __construct() {
		$this->store = new CoinsPay_LiteCartStore();
		$this->plugin = new CoinsPay_Plugin($this->store);
	}

	/** Texts in the admin's language. */
	private static function text() {
		return new CoinsPay_Text(CoinsPay_LiteCartStore::lang(isset(language::$selected['code']) ? language::$selected['code'] : ''));
	}

	/**
	 * Default value of a setting when the module row does not hold it yet.
	 */
	public static function defaultValue($key) {
		foreach (self::fieldList() as $field) {
			if ($field['key'] === $key) {
				return $field['default_value'];
			}
		}
		return null;
	}

	/**
	 * The fields of the settings form, as pm_coinspay::settings() returns them to LiteCart.
	 *
	 * @return array
	 */
	public static function fields() {
		$t = self::text();
		$fields = array();
		foreach (self::fieldList() as $field) {
			$help = isset($field['help']) ? $t->t($field['help']) : '';
			$fields[] = array(
				'key' => $field['key'],
				'default_value' => $field['default_value'],
				'title' => isset($field['title_text']) ? $field['title_text'] : $t->t($field['title']),
				'description' => $help,
				'function' => $field['function'],
			);
		}
		return $fields;
	}

	/** The fields with their dictionary keys and defaults. */
	private static function fieldList() {
		$kinds = self::statusKinds();
		$defaults = CoinsPay_Settings::installDefaults($kinds, self::cancelledStatuses());
		$list = array(
			array('key' => 'status', 'default_value' => '1', 'title' => 'admin_status', 'help' => 'admin_status_help', 'function' => 'toggle("e/d")'),
			array('key' => 'title', 'default_value' => '', 'title' => 'admin_title', 'help' => 'admin_title_help', 'function' => 'regional_text()'),
			array('key' => 'icon', 'default_value' => self::ICON, 'title' => 'admin_icon', 'help' => 'admin_icon_help', 'function' => 'text()'),
			array('key' => 'api_key', 'default_value' => '', 'title' => 'admin_api_key', 'help' => 'admin_api_key_help', 'function' => 'password()'),
			array('key' => 'webhook_secret', 'default_value' => '', 'title' => 'admin_webhook_secret', 'help' => 'admin_webhook_secret_help', 'function' => 'password()'),
			array('key' => 'api_url', 'default_value' => '', 'title' => 'admin_api_url', 'help' => 'admin_api_url_help', 'function' => 'text()'),
			array('key' => 'lifetime', 'default_value' => (string)CoinsPay_Settings::DEFAULT_LIFETIME, 'title' => 'admin_lifetime', 'help' => 'admin_lifetime_help', 'function' => 'number()'),
			array('key' => 'auto_return', 'default_value' => '1', 'title' => 'admin_auto_return', 'help' => 'admin_auto_return_help', 'function' => 'toggle("y/n")'),
			array('key' => 'stablecoins_only', 'default_value' => '0', 'title' => 'admin_stablecoins', 'function' => 'toggle("y/n")'),
			array('key' => 'buyer_pays_fee', 'default_value' => '0', 'title' => 'admin_buyer_fee', 'help' => 'admin_buyer_fee_help', 'function' => 'toggle("y/n")'),
			array('key' => 'buyer_pays_network', 'default_value' => '0', 'title' => 'admin_buyer_network', 'function' => 'toggle("y/n")'),
			array('key' => 'test_buyer_only', 'default_value' => '1', 'title' => 'admin_test_buyer_only', 'help' => 'admin_test_buyer_help', 'function' => 'toggle("y/n")'),
		);
		foreach (CoinsPay_Settings::$events as $event) {
			$value = $defaults['status_' . $event];
			if (in_array($event, CoinsPay_Settings::$paidLike, true)) {
				$help = 'admin_status_help_paid_like';
				$value = '';
			} elseif (in_array($event, CoinsPay_Settings::$required, true)) {
				$help = 'admin_status_help_required';
			} else {
				$help = 'admin_status_help_optional';
			}
			$list[] = array(
				'key' => 'status_' . $event,
				'default_value' => $value === 0 ? '' : (string)$value,
				'title' => 'admin_event_' . $event,
				'help' => $help,
				'function' => 'order_status()',
			);
		}
		$list[] = array('key' => 'cancel_statuses', 'default_value' => '', 'title' => 'admin_cancel_statuses', 'help' => 'admin_cancel_statuses_help', 'function' => 'order_statuses()');
		$list[] = array('key' => 'total', 'default_value' => '', 'title' => 'admin_total', 'help' => 'admin_total_help', 'function' => 'decimal()');
		$list[] = array('key' => 'geo_zones', 'default_value' => '', 'title' => 'admin_geo_zone', 'help' => 'admin_geo_zone_help', 'function' => 'geo_zone()');
		$list[] = array('key' => 'debug', 'default_value' => '0', 'title' => 'admin_debug', 'help' => 'admin_debug_help', 'function' => 'toggle("y/n")');
		$list[] = array('key' => 'priority', 'default_value' => '0', 'title' => 'admin_priority', 'help' => 'admin_priority_help', 'function' => 'number()');
		return $list;
	}

	/**
	 * Order status ids by kind: the module's own (awaiting, review) and the shop's first status
	 * in the processing and cancelled states. 0 for a kind the shop does not have yet.
	 *
	 * @return array
	 */
	public static function statusKinds() {
		$p = DB_TABLE_PREFIX;
		$kinds = array('awaiting' => 0, 'review' => 0, 'payment' => 0, 'canceled' => 0);
		foreach (array('awaiting', 'review') as $kind) {
			$kinds[$kind] = self::ownStatus($kind);
		}
		$row = self::first('SELECT id FROM `' . $p . 'order_statuses` WHERE state = \'processing\' ORDER BY priority, id LIMIT 1');
		if (!$row) {
			$row = self::first('SELECT id FROM `' . $p . 'order_statuses` WHERE is_sale = 1 ORDER BY priority, id LIMIT 1');
		}
		$kinds['payment'] = $row ? (int)$row['id'] : 0;
		$cancelled = self::cancelledStatuses();
		$kinds['canceled'] = $cancelled ? (int)$cancelled[0] : 0;
		return $kinds;
	}

	/** Statuses in the cancelled state: the defaults of "Order cancel statuses". */
	public static function cancelledStatuses() {
		$ids = array();
		$result = database::query('SELECT id FROM `' . DB_TABLE_PREFIX . 'order_statuses` WHERE state = \'cancelled\' ORDER BY priority, id');
		while ($row = database::fetch($result)) {
			$ids[] = (string)(int)$row['id'];
		}
		return $ids;
	}

	/** Id of the module's own status of a kind, found by its English name; 0 when missing. */
	private static function ownStatus($kind) {
		$row = self::first('SELECT order_status_id FROM `' . DB_TABLE_PREFIX . 'order_statuses_info` WHERE language_code = \'en\' AND name = \''
			. database::input(self::$statusNames[$kind]['en']) . '\' ORDER BY order_status_id LIMIT 1');
		return $row ? (int)$row['order_status_id'] : 0;
	}

	private static function first($sql) {
		$result = database::query($sql);
		$row = is_object($result) ? database::fetch($result) : null;
		return $row ? $row : null;
	}

	/**
	 * Install, called by LiteCart when the module row is created, before it saves the posted
	 * settings: the tables, the secret of the module's links, the two own statuses, the polling
	 * job; statuses left empty on the install form get the defaults.
	 *
	 * @param array $settings The settings LiteCart saves next (a reference to them).
	 */
	public function install(&$settings) {
		$this->plugin->repo->install();
		CoinsPay_LiteCartStore::tablesReady(true);
		if ($this->plugin->repo->kvGet('secret') === null) {
			$this->plugin->repo->kvSet('secret', CoinsPay_Util::randomHex(32));
		}
		self::ensureStatuses();
		$this->registerJob();

		if (!is_array($settings)) {
			return;
		}
		$defaults = CoinsPay_Settings::installDefaults(self::statusKinds(), self::cancelledStatuses());
		foreach (CoinsPay_Settings::$events as $event) {
			$key = 'status_' . $event;
			$value = $defaults[$key];
			if ((!isset($settings[$key]) || $settings[$key] === '') && is_int($value) && $value > 0) {
				$settings[$key] = (string)$value;
			}
		}
	}

	/**
	 * Uninstall: the polling job and the cache go; the invoices and the two statuses stay,
	 * orders refer to them.
	 */
	public function uninstall() {
		database::query('DELETE FROM `' . DB_TABLE_PREFIX . 'modules` WHERE module_id = \'' . self::JOB . '\'');
		$this->plugin->repo->uninstall();
	}

	/** Creates the module's two order statuses when they are not there. */
	public static function ensureStatuses() {
		foreach (array_keys(self::$statusNames) as $kind) {
			if (!self::ownStatus($kind)) {
				self::createStatus($kind);
			}
		}
	}

	/** Adds the polling job to the modules table, enabled, once. */
	private function registerJob() {
		if (self::first('SELECT id FROM `' . DB_TABLE_PREFIX . 'modules` WHERE module_id = \'' . self::JOB . '\' LIMIT 1')) {
			return;
		}
		database::query('INSERT INTO `' . DB_TABLE_PREFIX . 'modules` (module_id, type, status, priority, settings, date_created) VALUES (\''
			. self::JOB . '\', \'job\', 1, 0, \'' . database::input(json_encode(array('status' => '1', 'priority' => '0')), true, false) . '\', \'' . date('Y-m-d H:i:s') . '\')');
	}

	/**
	 * One of the module's statuses: no notification e-mail, the stock reserved (an unpaid order
	 * holds its items until the invoice expires), named in English and, for a Russian store
	 * language, in Russian.
	 */
	private static function createStatus($kind) {
		$p = DB_TABLE_PREFIX;
		$state = $kind === 'review' ? 'on_hold' : 'created';
		$color = $kind === 'review' ? '#d9534f' : '#f0ad4e';
		$icon = $kind === 'review' ? 'fa-exclamation-triangle' : 'fa-clock-o';
		$now = date('Y-m-d H:i:s');
		database::query('INSERT INTO `' . $p . 'order_statuses` (state, icon, color, is_sale, is_archived, is_trackable, stock_action, notify, priority, date_updated, date_created) VALUES (\''
			. $state . '\', \'' . $icon . '\', \'' . $color . '\', 0, 0, 0, \'reserve\', 0, 0, \'' . $now . '\', \'' . $now . '\')');
		$id = (int)database::insert_id();
		$languages = array();
		$result = database::query('SELECT code FROM `' . $p . 'languages`');
		while ($row = database::fetch($result)) {
			$languages[] = (string)$row['code'];
		}
		if (!in_array('en', $languages, true)) {
			$languages[] = 'en';
		}
		foreach (array_unique($languages) as $code) {
			$name = isset(self::$statusNames[$kind][$code]) ? self::$statusNames[$kind][$code] : self::$statusNames[$kind]['en'];
			database::query('INSERT INTO `' . $p . 'order_statuses_info` (order_status_id, language_code, name, description, email_subject, email_message) VALUES ('
				. $id . ', \'' . database::input($code) . '\', \'' . database::input($name) . '\', \'\', \'\', \'\')');
		}
		return $id;
	}

	/**
	 * The panel above the settings fields: before install a short intro; after it the state of
	 * the module, the connection, the webhook, the test buyer link, the last invoices.
	 *
	 * @return string HTML
	 */
	public function panel() {
		$t = self::text();
		$intro = '<p>' . self::e($t->t('admin_intro')) . '</p>';
		if (!CoinsPay_LiteCartStore::installed()) {
			// The install form offers the module's own statuses as defaults: LiteCart 2.5 and 2.6
			// save the posted form after install() and drop what it sets, so they exist before.
			self::ensureStatuses();
			return $intro . '<p>' . self::e($t->t('admin_install_first')) . '</p>';
		}
		$settings = $this->plugin->settings;
		$notice = $this->action($t);
		$html = $intro;
		if ($notice !== '') {
			$html .= '<div class="alert alert-success">' . self::e($notice) . '</div>';
		}

		// The state of the module.
		$alerts = array();
		if (!$settings->configured() || $settings->webhookSecret() === '') {
			$alerts[] = array('warning', $t->t('admin_hint_setup'));
		}
		if ($settings->isTestMode()) {
			$alerts[] = array('warning', $t->t('admin_test_mode_notice'));
		}
		$broken = $settings->brokenEvents();
		if ($broken) {
			$names = array();
			foreach ($broken as $event) {
				$names[] = $t->t('admin_event_' . $event);
			}
			$alerts[] = array('warning', $t->t('admin_warning_broken', implode(', ', $names)));
		}
		$lastRun = strtotime((string)settings::get('jobs_last_run'));
		if (!$lastRun || $lastRun < time() - 75 * 60) {
			$alerts[] = array('warning', $t->t('admin_hint_cron', $this->store->storeLink('push_jobs', array())));
		}
		if (!$alerts) {
			$alerts[] = array('success', $t->t('admin_ready'));
		}
		foreach ($alerts as $alert) {
			$html .= '<div class="alert alert-' . $alert[0] . '">' . self::e($alert[1]) . '</div>';
		}

		// Connection.
		if ($settings->configured()) {
			$check = $this->check(false);
			$html .= '<div class="alert alert-' . ($check['ok'] ? 'success' : 'danger') . '"><strong>' . self::e($t->t('admin_connection')) . ':</strong> '
				. self::e(implode(' ', $check['lines'])) . ' <a href="' . self::e($this->actionUrl('check')) . '">' . self::e($t->t('admin_check_again')) . '</a></div>';
		}

		// Webhook, test buyer, cron.
		$html .= '<p><strong>' . self::e($t->t('admin_webhook_url')) . ':</strong> <code>' . self::e($this->store->webhookUrl()) . '</code><br>' . self::e($t->t('admin_webhook_help'));
		$last = $this->plugin->repo->kvGet('last_webhook_test');
		if ($last !== null) {
			$html .= ' ' . self::e($t->t('admin_webhook_last', date('Y-m-d H:i:s', (int)$last)));
		}
		$html .= '</p>';
		if ($settings->isTestMode()) {
			$link = $this->store->storeLink('coinspay_test', array('v' => $this->plugin->tokens->testBuyer()));
			$html .= '<p><a href="' . self::e($link) . '" target="_blank" rel="noopener">' . self::e($t->t('admin_test_link')) . '</a><br>' . self::e($t->t('admin_test_buyer_help')) . '</p>';
		}
		$html .= '<p><strong>' . self::e($t->t('admin_cron')) . ':</strong> <code>' . self::e($this->store->cronUrl($this->plugin->tokens->cronKey())) . '</code><br>' . self::e($t->t('admin_cron_help')) . '</p>';

		$html .= $this->invoices($t);
		$html .= '<p class="text-muted">' . self::e($t->t('admin_version', CoinsPay_Plugin::VERSION, PLATFORM_VERSION)) . '</p>';
		return '<div class="coinspay-panel" style="max-width: 960px;">' . $html . '</div>';
	}

	/**
	 * The connection check with the saved key, cached for CHECK_EVERY seconds.
	 *
	 * @param bool $force
	 * @return array {ok, lines}
	 */
	private function check($force) {
		$fingerprint = md5($this->plugin->settings->apiUrl() . '|' . $this->plugin->settings->apiKey() . '|' . language::$selected['code']);
		if (!$force) {
			$cached = json_decode((string)$this->plugin->repo->kvGet('check_' . $fingerprint, self::CHECK_EVERY), true);
			if (is_array($cached) && isset($cached['lines'])) {
				return $cached;
			}
		}
		$plugin = new CoinsPay_Plugin($this->store);
		$plugin->text = self::text();
		$result = $plugin->checkConnection($this->plugin->settings->apiUrl(), $this->plugin->settings->apiKey());
		$this->plugin->repo->kvSet('check_' . $fingerprint, CoinsPay_Util::json($result));
		return $result;
	}

	/**
	 * The last invoices with their state and a link that asks CoinsPay about one now.
	 */
	private function invoices($t) {
		$rows = $this->plugin->repo->recent(20);
		$html = '<h3>' . self::e($t->t('admin_invoices')) . '</h3>';
		if (!$rows) {
			return $html . '<p>' . self::e($t->t('admin_no_invoices')) . '</p>';
		}
		$html .= '<table class="table table-striped table-hover data-table"><thead><tr><th>' . self::e($t->t('admin_col_order')) . '</th><th>' . self::e($t->t('admin_col_invoice'))
			. '</th><th>' . self::e($t->t('admin_col_amount')) . '</th><th>' . self::e($t->t('admin_col_status')) . '</th><th>' . self::e($t->t('admin_col_created')) . '</th><th></th></tr></thead><tbody>';
		foreach ($rows as $row) {
			$orderUrl = document::link(WS_DIR_ADMIN, array('app' => 'orders', 'doc' => 'edit_order', 'order_id' => (int)$row['order_id']));
			$status = $t->t('admin_invoice_' . $row['status']);
			$html .= '<tr><td><a href="' . self::e($orderUrl) . '">#' . (int)$row['order_id'] . '</a></td><td>' . self::e($row['number']) . ((int)$row['test'] ? ' <span class="badge">' . self::e($t->t('admin_test_badge')) . '</span>' : '')
				. '</td><td>' . self::e($row['amount'] . ' ' . $row['currency']) . '</td><td>' . self::e($status) . ((int)$row['paid_applied'] ? ' ✓' : '')
				. '</td><td>' . self::e(date('Y-m-d H:i', (int)$row['created'])) . '</td><td><a href="' . self::e($this->actionUrl('refresh', $row['invoice_id'])) . '">' . self::e($t->t('admin_refresh')) . '</a></td></tr>';
		}
		return $html . '</tbody></table><p class="text-muted">' . self::e($t->t('admin_invoices_help')) . '</p>';
	}

	/**
	 * Runs a panel action of the request (check again, refresh an invoice) when its token
	 * matches the admin's session.
	 *
	 * @return string What happened, or ''.
	 */
	private function action($t) {
		if (empty($_GET['coinspay_action']) || empty($_GET['coinspay_token']) || !CoinsPay_Util::equals($this->actionToken(), (string)$_GET['coinspay_token'])) {
			return '';
		}
		if ($_GET['coinspay_action'] === 'check') {
			$this->check(true);
			return '';
		}
		if ($_GET['coinspay_action'] === 'refresh' && !empty($_GET['invoice']) && $this->plugin->repo->find((string)$_GET['invoice'])) {
			try {
				$invoice = $this->plugin->settings->client()->invoice((string)$_GET['invoice']);
				$this->plugin->sync->apply($invoice);
				return $t->t('admin_refreshed', $t->t('admin_invoice_' . CoinsPay_Util::value($invoice, 'status')));
			} catch (Exception $e) {
				$this->store->log('refresh from the admin: ' . $e->getMessage(), true);
				return $t->t('admin_refresh_failed');
			}
		}
		return '';
	}

	private function actionUrl($action, $invoiceId = '') {
		$params = array('coinspay_action' => $action, 'coinspay_token' => $this->actionToken());
		if ($invoiceId !== '') {
			$params['invoice'] = $invoiceId;
		}
		return document::link(null, $params, array('app', 'doc', 'module_id'));
	}

	/** Token of the panel's links, bound to the admin's session. */
	private function actionToken() {
		return substr(hash_hmac('sha256', 'admin|' . session_id(), $this->plugin->settings->secret()), 0, 32);
	}

	public static function e($value) {
		return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
	}
}
