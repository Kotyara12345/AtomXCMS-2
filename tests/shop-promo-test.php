<?php
/**
 * Shop promo code and old price checks that do not need a database.
 *
 *     php tests/shop-promo-test.php
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

atm_test_assert(ShopHelper::promoNormalize(' sale10 ') === 'SALE10',
    'Promo codes must be trimmed and upper-cased.');
atm_test_assert(ShopHelper::promoNormalize('sale-10_new') === 'SALE-10_NEW',
    'Promo code dashes and underscores must survive normalization.');
atm_test_assert(ShopHelper::promoNormalize('SALE 10!') === 'SALE10',
    'Promo code normalization must drop separators and punctuation.');
atm_test_assert(ShopHelper::promoNormalize('X') === '',
    'A one-character promo code must be rejected.');
atm_test_assert(ShopHelper::promoNormalize(str_repeat('A', 65)) === '',
    'A promo code longer than 64 characters must be rejected.');

$library = (string)file_get_contents($root . '/modules/shop/shop.lib.php');
foreach (array(
    'function ensurePromoTables',
    'function promoCheck',
    'function setPromoCode',
    'function clearPromoCode',
    'ADD COLUMN `compare_price`',
    '`used_count` = GREATEST(`used_count` - 1, 0)',
    '`discount` DECIMAL(12,2)',
) as $needle) {
    atm_test_assert(strpos($library, $needle) !== false,
        'modules/shop/shop.lib.php is missing ' . $needle . '.');
}

$schema = (string)file_get_contents($root . '/modules/shop/install_db.php');
foreach (array(
    'shop_promocodes',
    '`discount_type`',
    '`max_uses`',
    '`compare_price` DECIMAL(12,2)',
    '`discount` DECIMAL(12,2)',
    '`promocode` VARCHAR(64)',
) as $needle) {
    atm_test_assert(strpos($schema, $needle) !== false,
        'install_db.php is missing ' . $needle . '.');
}

$module = (string)file_get_contents($root . '/modules/shop/index.php');
foreach (array(
    'public function apply_promo',
    'public function remove_promo',
    "promoCheck(\$promoCode, \$subtotal, \$currency['code'], true)",
    "'promocode' => \$promoCode",
    "'discount' => \$discount",
    '`used_count` = `used_count` + 1',
    'ShopHelper::clearPromoCode();',
) as $needle) {
    atm_test_assert(strpos($module, $needle) !== false,
        'modules/shop/index.php is missing ' . $needle . '.');
}

$admin = (string)file_get_contents($root . '/admin/shop.php');
foreach (array(
    "\$tabs['promocodes'] = 'Промокоды'",
    'promocode_save',
    'promocode_delete',
    'compare_prices',
    'shop_admin_promocodes',
    "'Промокод'",
) as $needle) {
    atm_test_assert(strpos($admin, $needle) !== false,
        'admin/shop.php is missing ' . $needle . '.');
}

$templates = array(
    'cart.html' => array('apply_promo_action', 'remove_promo_action', 'totals.discount_formatted', 'totals.total_formatted'),
    'checkout.html' => array('apply_promo_action', 'promo_code', 'discount_raw', 'subtotal - discount + price'),
    'product.html' => array('price_from_compare_formatted', 'variant.compare_price_formatted', 'shop-badge-discount'),
    'list.html' => array('product.price_from_compare_formatted', 'price_discount_percent'),
    'favorites.html' => array('product.price_from_compare_formatted'),
    'order.html' => array('order.discount_formatted', 'order.promocode'),
);
foreach ($templates as $file => $needles) {
    $html = (string)file_get_contents($root . '/modules/shop/install_template/html/' . $file);
    foreach ($needles as $needle) {
        atm_test_assert(strpos($html, $needle) !== false,
            'install_template/html/' . $file . ' is missing ' . $needle . '.');
    }
}

$css = (string)file_get_contents($root . '/template/default/css/shop.css');
atm_test_assert(strpos($css, '.shop-price-old') !== false,
    'The storefront stylesheet has no old price style.');
atm_test_assert(strpos($css, '.shop-badge-discount') !== false,
    'The storefront stylesheet has no discount badge style.');

if ($failures) {
    echo "FAIL\n- " . implode("\n- ", $failures) . "\n";
    echo $assertions . " assertions\n";
    exit(1);
}

echo "OK\n" . $assertions . " assertions\n";
