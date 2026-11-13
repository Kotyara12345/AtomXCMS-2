<?php
/**
 * Manual "customers also buy" checks that do not need a database.
 *
 *     php tests/shop-related-test.php
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

$library = (string)file_get_contents($root . '/modules/shop/shop.lib.php');
foreach (array(
    'function ensureRelatedProductsTable',
    'function relatedProductIds',
    'function syncRelatedProducts',
    'function relatedProducts',
    'table(\'related_products\')',
) as $needle) {
    atm_test_assert(strpos($library, $needle) !== false,
        'modules/shop/shop.lib.php is missing ' . $needle . '.');
}

$schema = (string)file_get_contents($root . '/modules/shop/install_db.php');
atm_test_assert(strpos($schema, 'shop_related_products') !== false,
    'install_db.php does not create the related products table.');

$admin = (string)file_get_contents($root . '/admin/shop.php');
foreach (array(
    'С этим покупают',
    'name="related[]"',
    'syncRelatedProducts',
    "`related_product_id` = {\$id}",
) as $needle) {
    atm_test_assert(strpos($admin, $needle) !== false,
        'admin/shop.php is missing ' . $needle . '.');
}

$module = (string)file_get_contents($root . '/modules/shop/index.php');
atm_test_assert(strpos($module, 'ShopHelper::relatedProducts($product') !== false,
    'modules/shop/index.php does not load manual related products.');
atm_test_assert(strpos($module, "'related_products' => \$relatedProducts") !== false,
    'modules/shop/index.php does not pass related products to the template.');
foreach (array("cfg('related_enabled', 1)", "cfg('similar_enabled', 1)") as $needle) {
    atm_test_assert(strpos($module, $needle) !== false,
        'modules/shop/index.php does not honour ' . $needle . '.');
}

$settings = (string)file_get_contents($root . '/modules/shop/info.php');
foreach (array("'similar_enabled'", "'related_enabled'", 'Похожие товары', 'С этим покупают') as $needle) {
    atm_test_assert(strpos($settings, $needle) !== false,
        'modules/shop/info.php has no setting ' . $needle . '.');
}

$defaults = (string)file_get_contents($root . '/modules/shop/install_settings.php');
atm_test_assert(strpos($defaults, "'similar_enabled' => 1") !== false
    && strpos($defaults, "'related_enabled' => 1") !== false,
    'install_settings.php is missing the recommendation block defaults.');

$html = (string)file_get_contents($root . '/modules/shop/install_template/html/product.html');
atm_test_assert(strpos($html, 'С этим покупают') !== false,
    'product.html has no related products heading.');
atm_test_assert(strpos($html, 'related_products') !== false,
    'product.html does not render related products.');

$css = (string)file_get_contents($root . '/admin/template/css/shop.css');
atm_test_assert(strpos($css, '.shop-admin-select-multiple') !== false,
    'The admin stylesheet has no multi-select style.');

if ($failures) {
    echo "FAIL\n- " . implode("\n- ", $failures) . "\n";
    echo $assertions . " assertions\n";
    exit(1);
}

echo "OK\n" . $assertions . " assertions\n";
