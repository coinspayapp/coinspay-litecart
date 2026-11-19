<?php
/**
 * Storefront side of the module: the method at checkout, the order before the payment page,
 * the buyer's return, the webhook, polling, the test buyer link and paying an order later.
 */
if (!defined('PLATFORM_NAME')) {
	exit;
}

class CoinsPay_LiteCartFront {

	/** Session field of the test buyer: Unix time until which the flag holds. */
	const TEST_BUYER = 'coinspay_test_buyer';

	/** Option id of the method: the order keeps "pm_coinspay:crypto". */
	const OPTION = 'crypto';

	/** @var CoinsPay_LiteCartStore */
	public $store;

	/** @var CoinsPay_Plugin */
	public $plugin;

	/** @var int Order whose lock verify() holds until after_process(). */
	private static $held = 0;

	public function __construct() {
		$this->store = new CoinsPay_LiteCartStore();
		$this->plugin = new CoinsPay_Plugin($this->store);
	}

	public function isTestBuyer() {
		return isset(session::$data[self::TEST_BUYER]) && (int)session::$data[self::TEST_BUYER] > time();
	}

	/**
	 * The method at checkout, or null when it is not offered: off, under the minimum total,
	 * outside the geo zone, a test key for a buyer who is not the test buyer, a currency
	 * CoinsPay does not take.
	 *
	 * @param array  $settings      Settings of the module object.
	 * @param float  $subtotal      Items total in the store currency.
	 * @param float  $tax
	 * @param string $currencyCode  Currency of the session.
	 * @param array  $customer
	 * @return array|null
	 */
	public function options($settings, $subtotal, $tax, $currencyCode, $customer) {
		if (empty($settings['status'])) {
			return null;
		}
		$minimum = isset($settings['total']) ? (float)$settings['total'] : 0.0;
		if ($minimum > 0 && (float)$subtotal + (float)$tax < $minimum) {
			return null;
		}
		if (!empty($settings['geo_zones'])) {
			$country = !empty($customer['shipping_address']['country_code']) ? $customer['shipping_address']['country_code'] : (isset($customer['country_code']) ? $customer['country_code'] : '');
			if ($country === '' || !reference::country($country)->in_geo_zone($settings['geo_zones'], $customer)) {
				return null;
			}
		}
		if (!$this->plugin->available((string)$currencyCode, $this->isTestBuyer())) {
			return null;
		}
		$t = $this->plugin->buyerText();
		$code = isset(language::$selected['code']) ? language::$selected['code'] : '';
		$title = isset($settings['title'][$code]) ? trim((string)$settings['title'][$code]) : '';
		$description = $t->t('catalog_description');
		if ($this->plugin->settings->isTestMode()) {
			$description .= ' ' . $t->t('catalog_test');
		}
		$icon = isset($settings['icon']) ? trim((string)$settings['icon']) : '';
		return array(
			'title' => $title !== '' ? CoinsPay_LiteCartAdmin::e($title) : CoinsPay_LiteCartAdmin::e($t->t('catalog_title')),
			'description' => '',
			'options' => array(
				array(
					'id' => self::OPTION,
					'icon' => $icon !== '' && is_file((defined('FS_DIR_STORAGE') ? FS_DIR_STORAGE : FS_DIR_APP) . $icon) ? $icon : '',
					'name' => CoinsPay_LiteCartAdmin::e($t->t('catalog_name')),
					'description' => CoinsPay_LiteCartAdmin::e($description),
					'fields' => '',
					'cost' => 0,
					'tax_class_id' => 0,
					'confirm' => CoinsPay_LiteCartAdmin::e($t->t('catalog_confirm')),
				),
			),
		);
	}

	/**
	 * The order is saved with the status of the "created" event (LiteCart saves it only after
	 * the payment otherwise), then the buyer goes to the invoice's payment page.
	 *
	 * @param ent_order $order The order of the session.
	 * @return array What order_process does next: GET to the payment page, or an error.
	 */
	public function transfer($order) {
		$buyer = $this->plugin->buyerText();
		if (!$this->plugin->available((string)$order->data['currency_code'], $this->isTestBuyer())) {
			return array('error' => $buyer->t('error_failed'));
		}
		$status = $this->plugin->settings->statusFor('created');
		if ($status <= 0) {
			$kinds = CoinsPay_LiteCartAdmin::statusKinds();
			$status = (int)$kinds['awaiting'];
			$this->store->log('the order status for "created" is not set; the module\'s "awaiting" status is used', true);
		}
		if ($status > 0) {
			$order->data['order_status_id'] = $status;
		}
		$order->save();
		$orderId = (int)$order->data['id'];
		if (!$orderId) {
			return array('error' => $buyer->t('error_failed'));
		}

		try {
			$invoice = $this->plugin->payment->invoiceFor($orderId, $this->isTestBuyer(), true);
		} catch (CoinsPay_ApiError $e) {
			$this->store->log(sprintf('order %d: invoice not created: %s', $orderId, $e->summary()), true);
			return array('error' => CoinsPay_Payment::errorMessage($e, $buyer));
		} catch (Exception $e) {
			$this->store->log(sprintf('order %d: invoice not created: %s', $orderId, $e->getMessage()), true);
			return array('error' => $buyer->t('error_failed'));
		}
		// The ent_order of the session is stale after the module wrote to the order.
		$order->load($orderId);
		if (CoinsPay_Util::value($invoice, 'status') === 'paid') {
			return array('method' => 'GET', 'action' => document::ilink('order_process'));
		}
		return array('method' => 'GET', 'action' => $this->plugin->payUrl($invoice));
	}

	/**
	 * The buyer is back in order_process: the invoice is applied to the order, and the order
	 * object of the session is read again, since order_process saves it next. The order lock
	 * stays until after_process(), so a webhook arriving meanwhile waits instead of having its
	 * status and comment overwritten by that save.
	 *
	 * @param ent_order $order
	 * @return array What order_process records; {error} sends the buyer back to checkout.
	 */
	public function verify($order) {
		$buyer = $this->plugin->buyerText();
		$orderId = (int)$order->data['id'];
		$row = $orderId ? $this->plugin->repo->current($orderId) : null;
		if (!$row) {
			return array('error' => $buyer->t('error_order'));
		}
		if ($this->plugin->repo->lock($orderId)) {
			self::$held = $orderId;
		}
		$invoice = $this->refresh($orderId);
		$order->load($orderId);
		if (!is_array($invoice)) {
			return array();
		}
		$status = CoinsPay_Util::value($invoice, 'status');
		if (!self::hasMoney($invoice) && in_array($status, array('expired', 'cancelled'), true)) {
			$this->release();
			return array('error' => $buyer->t('catalog_return_closed_short'));
		}
		$result = array('transaction_id' => strtolower(CoinsPay_Util::value($invoice, 'id')));
		if ($this->plugin->repo->paidInvoice($orderId) !== '') {
			$result['is_paid'] = true;
		}
		return $result;
	}

	/** order_process saved the order: the webhook may go on. */
	public function afterProcess() {
		$this->release();
	}

	private function release() {
		if (self::$held) {
			$this->plugin->repo->unlock(self::$held);
			self::$held = 0;
		}
	}

	/**
	 * The invoice of an order as CoinsPay sees it now, applied to the order; the last stored
	 * state when CoinsPay does not answer; null without an invoice.
	 */
	public function refresh($orderId) {
		$row = $this->plugin->repo->current($orderId);
		if (!$row) {
			return null;
		}
		try {
			$invoice = $this->plugin->settings->client()->invoice($row['invoice_id']);
			$this->plugin->sync->apply($invoice);
			return $invoice;
		} catch (Exception $e) {
			$this->store->log(sprintf('return to order %d: %s', $orderId, $e->getMessage()), true);
			$snapshot = json_decode((string)$row['snapshot'], true);
			return is_array($snapshot) ? $snapshot + array('status' => $row['status'], 'id' => $row['invoice_id']) : null;
		}
	}

	/** Paid, or money on its way. */
	public static function hasMoney($invoice) {
		return is_array($invoice) && (CoinsPay_Util::value($invoice, 'status') === 'paid'
			|| CoinsPay_Util::positive(CoinsPay_Util::value($invoice, 'amount_paid'))
			|| CoinsPay_Util::positive(CoinsPay_Util::value($invoice, 'amount_pending')));
	}

	/**
	 * The block on the "order placed" page: the state of the payment and, while the invoice is
	 * open, the button to the payment page.
	 *
	 * @param ent_order $order
	 * @return string HTML
	 */
	public function receipt($order) {
		$orderId = (int)$order->data['id'];
		$row = $orderId ? $this->plugin->repo->current($orderId) : null;
		if (!$row) {
			return '';
		}
		$t = $this->plugin->buyerText();
		$snapshot = json_decode((string)$row['snapshot'], true);
		$invoice = (is_array($snapshot) ? $snapshot : array()) + array('status' => $row['status']);
		if ($this->plugin->repo->paidInvoice($orderId) !== '' || $row['status'] === 'paid') {
			$text = $t->t('catalog_return_paid');
			$button = '';
		} elseif (self::hasMoney($invoice)) {
			$text = $t->t('catalog_return_pending');
			$button = '';
		} elseif ($row['status'] === 'open') {
			$text = $t->t('catalog_return_waiting');
			$button = '<p><a class="btn btn-success" href="' . CoinsPay_LiteCartAdmin::e($this->payLaterUrl($orderId)) . '">' . CoinsPay_LiteCartAdmin::e($t->t('catalog_pay_again')) . '</a></p>';
		} else {
			$text = $t->t('catalog_return_closed', '#' . $orderId);
			$button = '';
		}
		return '<div class="coinspay-receipt"><p>' . CoinsPay_LiteCartAdmin::e($text) . '</p>' . $button . '</div>';
	}

	/** Link to the payment page of an unpaid order, signed so nobody else opens it. */
	public function payLaterUrl($orderId) {
		return document::ilink('coinspay_pay', array('order_id' => (int)$orderId, 'token' => $this->plugin->tokens->returnToken($orderId)), false);
	}

	/**
	 * /coinspay_return: back from the payment page. The buyer of this session goes on through
	 * order_process (the order is finished there: cart, e-mail, the "order placed" page); a
	 * buyer in another browser gets the "order placed" page of the order by its public key.
	 *
	 * @return string URL to send the buyer to.
	 */
	public function returnPage($orderId, $token) {
		$orderId = (int)$orderId;
		$order = $orderId && $this->plugin->tokens->checkReturn($orderId, (string)$token) ? $this->store->order($orderId) : null;
		if (!$order || empty($order['ours'])) {
			notices::add('errors', $this->plugin->buyerText()->t('error_order'));
			return document::ilink('');
		}
		if (isset(session::$data['order']) && is_object(session::$data['order']) && (int)session::$data['order']->data['id'] === $orderId) {
			return document::ilink('order_process');
		}
		$this->refresh($orderId);
		return document::ilink('order_success', array('order_id' => $orderId, 'public_key' => $order['public_key']));
	}

	/**
	 * /coinspay_pay: the open invoice of an unpaid order, or a new one when the last closed.
	 *
	 * @return string URL to send the buyer to.
	 */
	public function payPage($orderId, $token) {
		$buyer = $this->plugin->buyerText();
		$orderId = (int)$orderId;
		$order = $orderId && $this->plugin->tokens->checkReturn($orderId, (string)$token) ? $this->store->order($orderId) : null;
		if (!$order || empty($order['ours'])) {
			notices::add('errors', $buyer->t('error_order'));
			return document::ilink('');
		}
		$success = document::ilink('order_success', array('order_id' => $orderId, 'public_key' => $order['public_key']));
		if ($this->plugin->repo->paidInvoice($orderId) !== '') {
			return $success;
		}
		$row = $this->plugin->repo->current($orderId);
		// A closed invoice is not opened again from an old link: the order may have been
		// cancelled with it and its stock released. The buyer orders again instead.
		if (!$row || $row['status'] !== 'open') {
			notices::add('errors', $buyer->t('catalog_return_closed', '#' . $orderId));
			return $success;
		}
		$testBuyer = $this->isTestBuyer() || !empty($row['test_buyer']);
		try {
			$invoice = $this->plugin->payment->invoiceFor($orderId, $testBuyer);
		} catch (CoinsPay_ApiError $e) {
			$this->store->log(sprintf('order %d: invoice not created: %s', $orderId, $e->summary()), true);
			notices::add('errors', CoinsPay_Payment::errorMessage($e, $buyer));
			return $success;
		} catch (Exception $e) {
			$this->store->log(sprintf('order %d: invoice not created: %s', $orderId, $e->getMessage()), true);
			notices::add('errors', $buyer->t('error_failed'));
			return $success;
		}
		return CoinsPay_Util::value($invoice, 'status') === 'paid' ? $success : $this->plugin->payUrl($invoice);
	}

	/**
	 * /coinspay_webhook: raw body and signature to the core.
	 *
	 * @return array {0: HTTP status, 1: text}
	 */
	public function webhook() {
		if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
			return array(405, 'POST only');
		}
		$signature = isset($_SERVER['HTTP_X_CPAY_SIGNATURE']) ? (string)$_SERVER['HTTP_X_CPAY_SIGNATURE'] : '';
		// The signature is over the raw bytes; php://input is the only place they are.
		return $this->plugin->webhook->handle((string)file_get_contents('php://input'), $signature);
	}

	/**
	 * GET /coinspay_webhook?key=…: polling by a cron job of the hosting.
	 *
	 * @return array {0: HTTP status, 1: text}
	 */
	public function cron($key) {
		if (!$this->plugin->tokens->checkCron((string)$key)) {
			return array(403, 'bad key');
		}
		ignore_user_abort(true);
		if (function_exists('set_time_limit')) {
			@set_time_limit(120);
		}
		return array(200, 'checked ' . $this->plugin->poller->run());
	}

	/** The polling job of LiteCart (push_jobs). */
	public function job() {
		return 'checked ' . $this->plugin->poller->run();
	}

	/**
	 * /coinspay_test: the test buyer link from the settings.
	 *
	 * @return bool The link was valid.
	 */
	public function becomeTestBuyer($value) {
		if (!$this->plugin->tokens->checkTestBuyer((string)$value)) {
			return false;
		}
		session::$data[self::TEST_BUYER] = time() + CoinsPay_Tokens::TEST_BUYER_TTL;
		return true;
	}

	/** Sends a short plain-text answer and ends the request. */
	public static function answer($answer) {
		http_response_code((int)$answer[0]);
		header('Content-Type: text/plain; charset=utf-8');
		header('Cache-Control: no-store');
		echo $answer[1];
		exit;
	}
}
