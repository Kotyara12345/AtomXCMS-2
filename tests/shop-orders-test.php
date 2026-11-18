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
	'function orderByNumberAndEmail',
	'shop_order_status_history',
	'$recipient = null, $force = false',
	'Открыть заказ на сайте',
	'function promoIdSet',
	'function minOrderHint',
	'function customerCanCancel',
	'function saveReviewPhoto',
	'function markReviewHelpful',
	'function ensureReviewExtras',
	'review_votes',
	'restrict_products',
	'restrict_categories',
	'/img.php?src=',
) as $needle) {
	atm_test_assert(strpos($library, $needle) !== false,
		'modules/shop/shop.lib.php is missing ' . $needle . '.');
}

atm_test_assert(ShopHelper::promoIdSet('3, 7,,x,-2,7') === array(3 => 3, 7 => 7),
	'promoIdSet must keep positive unique ids only.');
atm_test_assert(ShopHelper::promoIdSet('') === array(), 'promoIdSet must accept an empty list.');
atm_test_assert(ShopHelper::customerCanCancel(array('status' => 'new', 'stock_released' => 0)),
	'A new order must be cancellable by the customer.');
atm_test_assert(!ShopHelper::customerCanCancel(array('status' => 'shipped', 'stock_released' => 0)),
	'A shipped order must not be cancellable by the customer.');
atm_test_assert(!ShopHelper::customerCanCancel(array('status' => 'new', 'stock_released' => 1)),
	'An order with released stock must not be cancelled twice.');

$module = (string)file_get_contents($root . '/modules/shop/index.php');
foreach (array(
	'ShopHelper::phoneValid',
	"logOrderStatus(\$orderId, 'new'",
	"fpsCron('shopExpireOrders', 900)",
	'function lookup_order',
	'function repeat_order',
	'function canViewOrder',
	'ShopHelper::orderByNumberAndEmail',
	'ShopHelper::getOrderStatusHistory($id)',
	'function cancel_order',
	'function review_helpful',
	'function collectReviewPhotos',
	'ShopHelper::minOrderShortfall',
) as $needle) {
	atm_test_assert(strpos($module, $needle) !== false,
		'modules/shop/index.php is missing ' . $needle . '.');
}

$orderTemplate = (string)file_get_contents($root . '/modules/shop/install_template/html/order.html');
foreach (array('shop-history', 'repeat_action', 'order_token', 'cancel_allowed', 'cancel_action') as $needle) {
	atm_test_assert(strpos($orderTemplate, $needle) !== false,
		'modules/shop/install_template/html/order.html is missing ' . $needle . '.');
}

$productTemplate = (string)file_get_contents($root . '/modules/shop/install_template/html/product.html');
foreach (array('multipart/form-data', 'photos[]', 'review.photo_urls', 'review_helpful_action', 'review.helpful_count') as $needle) {
	atm_test_assert(strpos($productTemplate, $needle) !== false,
		'modules/shop/install_template/html/product.html is missing ' . $needle . '.');
}

foreach (array('cart.html', 'checkout.html') as $templateName) {
	$template = (string)file_get_contents($root . '/modules/shop/install_template/html/' . $templateName);
	atm_test_assert(strpos($template, 'min_order_hint') !== false,
		$templateName . ' is missing min_order_hint.');
}

$settings = (string)file_get_contents($root . '/modules/shop/install_settings.php');
foreach (array('review_photos', 'min_order_total_rub', 'min_order_total_byn') as $needle) {
	atm_test_assert(strpos($settings, $needle) !== false,
		'modules/shop/install_settings.php is missing ' . $needle . '.');
}

$ordersTemplate = (string)file_get_contents($root . '/modules/shop/install_template/html/orders.html');
foreach (array('lookup_action', 'lookup_csrf_token', 'name="number"') as $needle) {
	atm_test_assert(strpos($ordersTemplate, $needle) !== false,
		'modules/shop/install_template/html/orders.html is missing ' . $needle . '.');
}

if ($failures) {
	echo "FAIL\n- " . implode("\n- ", $failures) . "\n";
	echo $assertions . " assertions\n";
	exit(1);
}

echo "OK\n" . $assertions . " assertions\n";
