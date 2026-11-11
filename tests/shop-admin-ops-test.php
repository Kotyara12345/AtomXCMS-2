<?php
/**
 * Shop admin operations checks that do not need a database.
 *
 *     php tests/shop-admin-ops-test.php
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

atm_test_assert(ShopHelper::trackingUrlValid(''), 'Empty tracking URL rejected.');
atm_test_assert(ShopHelper::trackingUrlValid('https://track.example.com/1'), 'HTTPS tracking URL rejected.');
atm_test_assert(ShopHelper::trackingUrlValid('http://track.example.com/1'), 'HTTP tracking URL rejected.');
atm_test_assert(!ShopHelper::trackingUrlValid('javascript:alert(1)'), 'Unsafe tracking URL accepted.');
atm_test_assert(!ShopHelper::trackingUrlValid('ftp://example.com/track'), 'FTP tracking URL accepted.');
atm_test_assert(ShopHelper::cleanTrackingNumber(" AB\r\n12 ") === 'AB 12', 'Tracking number was not cleaned.');
atm_test_assert(mb_strlen(ShopHelper::cleanTrackingNumber(str_repeat('A', 300))) === 128,
    'Tracking number was not limited to 128 characters.');

$library = (string)file_get_contents($root . '/modules/shop/shop.lib.php');
foreach (array(
    'function ensureCartsTable',
    'function ensureOrderTrackingColumns',
    'function setOrderTracking',
    'function cleanTrackingNumber',
    'function trackingUrlValid',
    'function ensureVariantNotifyColumn',
    'function lowStockAlert',
    'function shopLowStockAlert',
) as $needle) {
    atm_test_assert(strpos($library, $needle) !== false,
        'modules/shop/shop.lib.php is missing ' . $needle . '.');
}

$module = (string)file_get_contents($root . '/modules/shop/index.php');
atm_test_assert(strpos($module, "fpsCron('shopLowStockAlert', 86400)") !== false,
    'modules/shop/index.php does not schedule the low stock alert.');

$admin = (string)file_get_contents($root . '/admin/shop.php');
foreach (array(
    'function shop_admin_orders_where',
    'function shop_admin_csv_cell',
    'function shop_admin_order_print',
    "'export' => 'csv'",
    "\$_GET['export'] === 'csv'",
    'order_tracking',
    'tracking_number',
    'tracking_url',
    'Выручка за сегодня',
    'Выручка за 7 дней',
    'Выручка за 30 дней',
    'Продажи за 14 дней',
    'Топ товаров',
    'Топ категорий',
) as $needle) {
    atm_test_assert(strpos($admin, $needle) !== false,
        'admin/shop.php is missing ' . $needle . '.');
}

$schema = (string)file_get_contents($root . '/modules/shop/install_db.php');
foreach (array('shop_carts', 'tracking_number', 'tracking_url', 'low_stock_notified') as $needle) {
    atm_test_assert(strpos($schema, $needle) !== false,
        'install_db.php is missing ' . $needle . '.');
}

$settings = (string)file_get_contents($root . '/modules/shop/info.php');
$defaults = (string)file_get_contents($root . '/modules/shop/install_settings.php');
atm_test_assert(strpos($settings, 'low_stock_threshold') !== false,
    'info.php is missing low_stock_threshold.');
atm_test_assert(strpos($defaults, "'low_stock_threshold' => 3") !== false,
    'install_settings.php is missing the low stock default.');

$orderTemplate = (string)file_get_contents($root . '/modules/shop/install_template/html/order.html');
atm_test_assert(strpos($orderTemplate, 'order.tracking_number') !== false,
    'order.html does not show the tracking number.');
atm_test_assert(strpos($orderTemplate, 'order.tracking_url') !== false,
    'order.html does not show the tracking link.');

if ($failures) {
    echo "FAIL\n- " . implode("\n- ", $failures) . "\n";
    echo $assertions . " assertions\n";
    exit(1);
}

echo "OK\n" . $assertions . " assertions\n";
