<?php
/**
 * Shop order lifecycle checks that do not need a database.
 *
 *     php tests/shop-orders-test.php
 */

$root = dirname(__DIR__);
$assertions = 0;
$failures = array();

function atm_test_assert($condition, $message)
{
	global $assertions, $failures;
	$assertions++;
	if (!$condition) {
		$failures[] = $message;
	}
}

require_once $root . '/modules/shop/shop.lib.php';

foreach (array('+7 (999) 123-45-67', '1234567', '123456789012345') as $phone) {
	atm_test_assert(ShopHelper::phoneValid($phone), 'Valid phone rejected: ' . $phone);
}
foreach (array('', '123456', '1234567890123456', 'телефон') as $phone) {
	atm_test_assert(!ShopHelper::phoneValid($phone), 'Invalid phone accepted: ' . $phone);
}

$library = (string)file_get_contents($root . '/modules/shop/shop.lib.php');
foreach (array(
	'function expireUnpaidOrders',
	'function getOrderStatusHistory',
	'function logOrderStatus',
	'shop_order_status_history',
	'$recipient = null, $force = false',
	'Открыть заказ на сайте',
) as $needle) {
	atm_test_assert(strpos($library, $needle) !== false,
		'modules/shop/shop.lib.php is missing ' . $needle . '.');
}

$module = (string)file_get_contents($root . '/modules/shop/index.php');
foreach (array(
	'ShopHelper::phoneValid',
	"logOrderStatus(\$orderId, 'new'",
	"fpsCron('shopExpireOrders', 900)",
) as $needle) {
	atm_test_assert(strpos($module, $needle) !== false,
		'modules/shop/index.php is missing ' . $needle . '.');
}

if ($failures) {
	echo "FAIL\n- " . implode("\n- ", $failures) . "\n";
	echo $assertions . " assertions\n";
	exit(1);
}

echo "OK\n" . $assertions . " assertions\n";
