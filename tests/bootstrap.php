<?php
/**
 * Unit tests of the CoinsPay core: no LiteCart, a fake shop where one is needed.
 */
require dirname(__DIR__) . '/vendor/autoload.php';
define('COINSPAY_TESTS', true);
require dirname(__DIR__) . '/core/loader.php';
require __DIR__ . '/FakeStore.php';
