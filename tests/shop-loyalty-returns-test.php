<?php
/**
 * Shop returns, warehouse and loyalty checks that do not need a database.
 *
 *     php tests/shop-loyalty-returns-test.php
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

$library = (string)file_get_contents($root . '/modules/shop/shop.lib.php');
$statuses = (string)file_get_contents($root . '/modules/shop/ShopOrderStatus.class.php');
foreach (array(
    'function ensureInventoryTables',
    'function changeStock',
    'function setWarehouseStock',
    'function stockMovements',
    'function ensureReturnTables',
    'function createOrderReturn',
    'function receiveOrderReturn',
    'function refundOrderReturn',
    'function cancelOrderReturn',
    'function returnedQuantities',
    'function ensureLoyaltyTables',
    'function loyaltyAdd',
    'function loyaltyCreditOrder',
    'function loyaltyReverseOrder',
    'function loyaltyMaxBonus',
    'function rateLimit',
) as $needle) {
    atm_test_assert(strpos($library, $needle) !== false,
        'modules/shop/shop.lib.php is missing ' . $needle . '.');
}

foreach (array('returned', 'refunded') as $status) {
    atm_test_assert(strpos($statuses, "'{$status}' =>") !== false,
        'Order status ' . $status . ' is not registered.');
}

$module = (string)file_get_contents($root . '/modules/shop/index.php');
foreach (array(
    "rateLimit('place_order'",
    "rateLimit('apply_promo'",
    "rateLimit('add_review'",
    "rateLimit('notify_stock'",
    "'reserved_until' => ShopHelper::reservationDeadline",
    "changeStock((int)\$item['variant_id']",
    "loyaltyAdd(\$loyaltyEmail, -\$bonus",
    "\$_SESSION['shop_ref']",
) as $needle) {
    atm_test_assert(strpos($module, $needle) !== false,
        'modules/shop/index.php is missing ' . $needle . '.');
}

$admin = (string)file_get_contents($root . '/admin/shop.php');
foreach (array(
    'order_return_create',
    'order_return_receive',
    'order_return_refund',
    'order_return_cancel',
    'warehouse_stock_save',
    'loyalty_adjust',
    'function shop_admin_warehouses',
    'function shop_admin_warehouse_stock',
    'function shop_admin_loyalty',
) as $needle) {
    atm_test_assert(strpos($admin, $needle) !== false,
        'admin/shop.php is missing ' . $needle . '.');
}

$schema = (string)file_get_contents($root . '/modules/shop/install_db.php');
foreach (array(
    'shop_warehouses',
    'shop_warehouse_stock',
    'shop_stock_movements',
    'shop_order_returns',
    'shop_order_return_items',
    'shop_return_history',
    'shop_loyalty_accounts',
    'shop_loyalty_transactions',
    'shop_rate_limits',
    'reserved_until',
    'bonus_spent',
) as $needle) {
    atm_test_assert(strpos($schema, $needle) !== false,
        'modules/shop/install_db.php is missing ' . $needle . '.');
}

$settings = (string)file_get_contents($root . '/modules/shop/install_settings.php');
foreach (array('loyalty_enabled', 'loyalty_accrual_percent', 'loyalty_tiers') as $needle) {
    atm_test_assert(strpos($settings, $needle) !== false,
        'modules/shop/install_settings.php is missing ' . $needle . '.');
}

$checkout = (string)file_get_contents($root . '/modules/shop/install_template/html/checkout.html');
foreach (array('use_bonus', 'loyalty_discount_formatted', 'shop-bonus-row') as $needle) {
    atm_test_assert(strpos($checkout, $needle) !== false,
        'checkout.html is missing ' . $needle . '.');
}

if ($failures) {
    echo "FAIL\n- " . implode("\n- ", $failures) . "\n";
    exit(1);
}
echo "OK\n{$assertions} assertions\n";
