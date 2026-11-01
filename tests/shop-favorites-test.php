<?php
/**
 * Static checks for shop favorites.
 *
 * These checks do not need a database or a web server. They guard the
 * schema, the helper methods, the controller guards and the template
 * markers. Run with:
 *
 *     php tests/shop-favorites-test.php
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

function atm_test_read($path)
{
	return (string)@file_get_contents($path);
}

$library = atm_test_read($root . '/modules/shop/shop.lib.php');
$schema = atm_test_read($root . '/modules/shop/install_db.php');
$controller = atm_test_read($root . '/modules/shop/index.php');
$productTpl = atm_test_read($root . '/modules/shop/install_template/html/product.html');
$listTpl = atm_test_read($root . '/modules/shop/install_template/html/list.html');
$favoritesTplPath = $root . '/modules/shop/install_template/html/favorites.html';
$favoritesTpl = atm_test_read($favoritesTplPath);

atm_test_assert($library !== '', 'Missing modules/shop/shop.lib.php.');
atm_test_assert($schema !== '', 'Missing modules/shop/install_db.php.');
atm_test_assert($controller !== '', 'Missing modules/shop/index.php.');
atm_test_assert(is_file($favoritesTplPath), 'Missing favorites template.');

/* Schema: per-user, per-product favorites with a composite key. */
atm_test_assert(strpos($schema, 'shop_favorites') !== false, 'Install schema is missing shop_favorites.');
atm_test_assert(strpos($schema, 'PRIMARY KEY (`user_id`, `product_id`)') !== false, 'Favorites schema is missing the composite primary key.');

/* Helpers. */
foreach (array(
	'ensureFavoritesTable',
	'favoriteIds',
	'isFavorite',
	'addFavorite',
	'removeFavorite',
	'getFavorites',
) as $method) {
	atm_test_assert(strpos($library, 'function ' . $method . '(') !== false, 'ShopHelper is missing ' . $method . '().');
}
atm_test_assert(strpos($library, "CREATE TABLE IF NOT EXISTS `{\$table}`") !== false, 'Favorites table is not created lazily.');
atm_test_assert(strpos($library, "INSERT IGNORE INTO `{\$table}`") !== false, 'addFavorite() does not ignore duplicates.');
atm_test_assert(strpos($library, "DELETE FROM `{\$table}`") !== false, 'removeFavorite() does not delete the row.');
atm_test_assert(strpos($library, "p.`id` IN (") !== false, 'getProducts() is missing the ids filter.');
atm_test_assert(strpos($library, "self::getProducts(array('ids' => \$ids") !== false, 'getFavorites() does not reuse getProducts().');

/* Controller: only POST, CSRF-checked, login required. */
atm_test_assert(strpos($controller, 'public function favorites()') !== false, 'Controller is missing the favorites page.');
atm_test_assert(strpos($controller, 'public function add_to_favorites()') !== false, 'Controller is missing the add action.');
atm_test_assert(strpos($controller, 'public function remove_from_favorites()') !== false, 'Controller is missing the remove action.');
atm_test_assert(strpos($controller, 'private function requireFavoriteUser()') !== false, 'Controller is missing the login guard.');
atm_test_assert(strpos($controller, "redirect('/users/login_form/')") !== false, 'Guests are not redirected to the login form.');
atm_test_assert(strpos($controller, "strtoupper(\$_SERVER['REQUEST_METHOD']) !== 'POST'") !== false, 'Favorite actions do not require POST.');
atm_test_assert(strpos($controller, 'ShopHelper::csrfCheck(') !== false, 'Favorite actions do not check CSRF.');
atm_test_assert(strpos($controller, "'is_favorite' => \$isFavorite") !== false, 'Product page does not expose the favorite state.');
atm_test_assert(strpos($controller, "'favorite_action' => get_url(") !== false, 'Product page does not expose the favorite action.');

/* Templates. */
atm_test_assert(strpos($productTpl, 'favorite_action') !== false, 'Product template is missing the favorite form.');
atm_test_assert(strpos($productTpl, 'csrf_token') !== false, 'Product favorite form is missing the CSRF token.');
atm_test_assert(strpos($productTpl, 'login_url') !== false, 'Product template is missing the guest login link.');
atm_test_assert(strpos($productTpl, 'favorites_url') !== false, 'Product template is missing the favorites link.');
atm_test_assert(strpos($listTpl, 'favorites_url') !== false, 'Catalog template is missing the favorites link.');
atm_test_assert(strpos($favoritesTpl, 'remove_action') !== false, 'Favorites template is missing the remove form.');
atm_test_assert(strpos($favoritesTpl, 'csrf_token') !== false, 'Favorites remove form is missing the CSRF token.');
atm_test_assert(strpos($favoritesTpl, 'В избранном пока нет товаров') !== false, 'Favorites template is missing the empty state.');

echo $failures ? "FAIL\n" : "OK\n";
foreach ($failures as $failure) echo '- ' . $failure . "\n";
echo $assertions . " assertions\n";
exit($failures ? 1 : 0);
