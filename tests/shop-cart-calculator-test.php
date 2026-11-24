<?php

require_once dirname(__DIR__) . '/modules/shop/ShopCartCalculator.class.php';

function shop_cart_assert($condition, $message)
{
    if (!$condition) { fwrite(STDERR, "FAIL: " . $message . "\n"); exit(1); }
}

$summary = ShopCartCalculator::summarize(array(
    array('total' => '19.99', 'quantity' => '2'),
    array('total' => 5.01, 'quantity' => 1),
));
shop_cart_assert(abs($summary['subtotal'] - 25.0) < 0.00001, 'Cart subtotal must sum line totals.');
shop_cart_assert($summary['quantity'] === 3, 'Cart quantity must sum line quantities.');
shop_cart_assert(ShopCartCalculator::summarize(array()) === array('subtotal' => 0.0, 'quantity' => 0), 'Empty cart must have zero totals.');

echo "OK\n";
