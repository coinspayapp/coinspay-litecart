<?php
/**
 * What the core needs from LiteCart. The core itself makes no LiteCart calls, so it runs in
 * unit tests; CoinsPay_LiteCartStore implements this for LiteCart 2.5 to 2.7.
 */
if (!defined('PLATFORM_NAME') && !defined('COINSPAY_TESTS')) {
	exit;
}

interface CoinsPay_Store {

	/**
	 * Runs SQL; rows for SELECT, an empty array otherwise.
	 *
	 * @param string $sql
	 * @return array
	 */
	public function query($sql);

	/** Escapes a value for SQL inside quotes. */
	public function escape($value);

	/** Table prefix (DB_TABLE_PREFIX). */
	public function prefix();

	/** Shop the plugin works for: LiteCart runs one shop, so always 1. */
	public function shopId();

	/**
	 * A plugin setting by its key ("api_key").
	 *
	 * @param string $key
	 * @return mixed Null when not set.
	 */
	public function setting($key);

	/** Currency of the shop (store_currency_code), ISO code: order totals are kept in it. */
	public function defaultCurrency();

	/** Whether an order status exists. */
	public function statusExists($statusId);

	/**
	 * The order, or null.
	 *
	 * @param int $orderId
	 * @return array|null {id, reference, status_id, total, default_total, currency_code, ours,
	 *                    items: [{name, quantity}]}. total is in the order currency,
	 *                    default_total in the currency of the shop; ours — the order is paid
	 *                    with this module.
	 */
	public function order($orderId);

	/**
	 * Writes an event to the order: $statusId is the status to set (the current one for a
	 * comment only); the comment goes to the order hidden from the customer.
	 *
	 * @param int    $orderId
	 * @param int    $statusId
	 * @param string $comment
	 * @return int The order status afterwards.
	 */
	public function addHistory($orderId, $statusId, $comment);

	/**
	 * The invoice paid the order: the shop records the payment date and the transaction.
	 *
	 * @param int    $orderId
	 * @param string $invoiceId
	 */
	public function paid($orderId, $invoiceId);

	/** Link the buyer comes back by from the payment page. */
	public function returnUrl($orderId, $token);

	/** Address of the webhook route. */
	public function webhookUrl();

	/** Address of the keyed polling route. */
	public function cronUrl($key);

	/** Shop address: part of the idempotency key, so a staging copy never gets the live shop's invoices. */
	public function siteUrl();

	/** Language of order comments and admin messages: en or ru. */
	public function adminLanguage();

	/** Language of the payment page for the buyer: en or ru. */
	public function buyerLanguage();

	/** User-Agent of API requests. */
	public function userAgent();

	/**
	 * Writes to the log of the module.
	 *
	 * @param string $message
	 * @param bool   $error   Errors are logged always, the rest with the debug setting only.
	 */
	public function log($message, $error);
}
