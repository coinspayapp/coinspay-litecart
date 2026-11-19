<?php
/**
 * The English and Russian dictionaries hold the same keys, and every key the module asks for
 * is in them: a missing one would show its key to the buyer or the admin.
 */

use PHPUnit\Framework\TestCase;

final class DictionaryTest extends TestCase {

	private static function load($lang) {
		return include dirname(dirname(__DIR__)) . '/core/lang/' . $lang . '.php';
	}

	public function test_both_languages_have_the_same_keys() {
		$en = array_keys(self::load('en'));
		$ru = array_keys(self::load('ru'));
		sort($en);
		sort($ru);
		$this->assertSame($en, $ru);
	}

	public function test_every_key_the_code_uses_is_in_the_dictionary() {
		$en = self::load('en');
		$root = dirname(dirname(__DIR__));
		$files = array_merge(glob($root . '/core/*.php'), glob($root . '/core/litecart/*.php'), glob($root . '/public_html/pages/*.php'));
		$used = array();
		foreach ($files as $file) {
			$code = file_get_contents($file);
			// t('key' …) calls and the dictionary keys of the settings fields.
			preg_match_all('/->t\(\s*\'([a-z_]+)\'/', $code, $calls);
			preg_match_all('/\'(?:title|help)\' => \'((?:admin|catalog)_[a-z_]+)\'/', $code, $fields);
			$used = array_merge($used, $calls[1], $fields[1]);
		}
		$this->assertGreaterThan(40, count(array_unique($used)));
		foreach (array_unique($used) as $key) {
			// A prefix the code completes (admin_event_ . $event) is checked below.
			if (substr($key, -1) === '_') {
				continue;
			}
			$this->assertArrayHasKey($key, $en, $key . ' is used but not in the dictionary');
		}
		// Keys built from the event names: the title of each status field.
		foreach (CoinsPay_Settings::$events as $event) {
			$this->assertArrayHasKey('admin_event_' . $event, $en);
		}
		foreach (array('open', 'paid', 'expired', 'cancelled') as $status) {
			$this->assertArrayHasKey('admin_invoice_' . $status, $en);
		}
	}
}
