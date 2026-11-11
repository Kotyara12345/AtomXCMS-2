<?php
/**
 * Shop customer-retention checks that do not need a database.
 *
 *     php tests/shop-engagement-test.php
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

foreach (array(1, 3, 5) as $rating) {
	atm_test_assert(ShopHelper::ratingValid($rating), 'Valid rating rejected: ' . $rating);
}
foreach (array(0, 6, -1, 'x') as $rating) {
	atm_test_assert(!ShopHelper::ratingValid($rating), 'Invalid rating accepted: ' . var_export($rating, true));
}

$library = (string)file_get_contents($root . '/modules/shop/shop.lib.php');
foreach (array(
	'function ensureReviewsTable',
	'function addReview',
	'function productRatings',
	'function similarProducts',
	'function recordProductView',
	'function getRecentlyViewed',
	'function notifyStockSubscribers',
	'function shopNotifyStock',
) as $needle) {
	atm_test_assert(strpos($library, $needle) !== false,
		'modules/shop/shop.lib.php is missing ' . $needle . '.');
}

$module = (string)file_get_contents($root . '/modules/shop/index.php');
foreach (array(
	'public function add_review',
	'public function notify_stock',
	"fpsCron('shopNotifyStock', 900)",
	'private function attachRatings',
	"'AggregateRating'",
	"'featured_products'",
	"'similar_products'",
	"'recently_viewed'",
) as $needle) {
	atm_test_assert(strpos($module, $needle) !== false,
		'modules/shop/index.php is missing ' . $needle . '.');
}

$templates = array(
	'product.html' => array('shop-review-form', 'stock_notify_action', 'similar_products', 'recently_viewed', 'shop-product-rating'),
	'list.html' => array('featured_products', 'shop-card-rating'),
);
foreach ($templates as $file => $needles) {
	$html = (string)file_get_contents($root . '/modules/shop/install_template/html/' . $file);
	foreach ($needles as $needle) {
		atm_test_assert(strpos($html, $needle) !== false,
			$file . ' is missing ' . $needle . '.');
	}
}

$admin = (string)file_get_contents($root . '/admin/shop.php');
foreach (array("'reviews'", 'review_approve', 'review_delete', 'function shop_admin_reviews') as $needle) {
	atm_test_assert(strpos($admin, $needle) !== false,
		'admin/shop.php is missing ' . $needle . '.');
}

$schema = (string)file_get_contents($root . '/modules/shop/install_db.php');
foreach (array('shop_reviews', 'shop_stock_subscriptions') as $table) {
	atm_test_assert(strpos($schema, $table) !== false,
		'install_db.php is missing ' . $table . '.');
}

if ($failures) {
	echo "FAIL\n- " . implode("\n- ", $failures) . "\n";
	echo $assertions . " assertions\n";
	exit(1);
}

echo "OK\n" . $assertions . " assertions\n";
