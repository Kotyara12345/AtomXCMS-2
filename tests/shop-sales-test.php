<?php
/**
 * Shop sales checks that do not need a database.
 *
 *     php tests/shop-sales-test.php
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

$headerMap = new ReflectionMethod('ShopHelper', 'csvHeaderMap');
$map = $headerMap->invoke(null, array("\xEF\xBB\xBFНазвание", 'Артикул', 'Цена', 'Остаток', 'Активен'));
atm_test_assert(isset($map['title']) && $map['title'] === 0, 'CSV header with BOM and Russian title was not recognized.');
atm_test_assert(isset($map['sku']) && $map['sku'] === 1, 'CSV header "Артикул" was not mapped to sku.');
atm_test_assert(isset($map['price']) && $map['price'] === 2, 'CSV header "Цена" was not mapped to price.');
atm_test_assert(isset($map['stock']) && $map['stock'] === 3, 'CSV header "Остаток" was not mapped to stock.');
atm_test_assert(isset($map['active']) && $map['active'] === 4, 'CSV header "Активен" was not mapped to active.');

$englishMap = $headerMap->invoke(null, array('title', 'slug', 'category', 'featured'));
atm_test_assert(isset($englishMap['slug']) && isset($englishMap['category']) && isset($englishMap['featured']),
    'English CSV headers were not recognized.');

$flag = new ReflectionMethod('ShopHelper', 'csvFlag');
atm_test_assert($flag->invoke(null, 'да') === 1, 'Russian "да" was not parsed as true.');
atm_test_assert($flag->invoke(null, '1') === 1, 'Numeric flag was not parsed as true.');
atm_test_assert($flag->invoke(null, 'нет') === 0, 'Russian "нет" was parsed as true.');

$library = (string)file_get_contents($root . '/modules/shop/shop.lib.php');
foreach (array(
    'function abandonedCartRecipients',
    'function remindAbandonedCarts',
    'function importProductsCsv',
    'function shopRemindCarts',
    'ALTER TABLE `{$table}` ADD COLUMN `reminded_at`',
) as $needle) {
    atm_test_assert(strpos($library, $needle) !== false,
        'modules/shop/shop.lib.php is missing ' . $needle . '.');
}

$module = (string)file_get_contents($root . '/modules/shop/index.php');
atm_test_assert(strpos($module, "fpsCron('shopRemindCarts', 3600)") !== false,
    'modules/shop/index.php does not schedule the cart reminder.');

$admin = (string)file_get_contents($root . '/admin/shop.php');
foreach (array(
    'product_import',
    'enctype="multipart/form-data"',
    'name="csv"',
    'Импорт CSV',
    'importProductsCsv',
) as $needle) {
    atm_test_assert(strpos($admin, $needle) !== false,
        'admin/shop.php is missing ' . $needle . '.');
}

$schema = (string)file_get_contents($root . '/modules/shop/install_db.php');
atm_test_assert(strpos($schema, 'reminded_at') !== false,
    'install_db.php is missing the carts reminder column.');

$settings = (string)file_get_contents($root . '/modules/shop/info.php');
$defaults = (string)file_get_contents($root . '/modules/shop/install_settings.php');
atm_test_assert(strpos($settings, 'abandoned_cart_hours') !== false,
    'info.php is missing abandoned_cart_hours.');
atm_test_assert(strpos($defaults, "'abandoned_cart_hours' => 24") !== false,
    'install_settings.php is missing the cart reminder default.');

if ($failures) {
    echo "FAIL\n- " . implode("\n- ", $failures) . "\n";
    echo $assertions . " assertions\n";
    exit(1);
}

echo "OK\n" . $assertions . " assertions\n";
