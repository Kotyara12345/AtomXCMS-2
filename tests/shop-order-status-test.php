<?php

require_once dirname(__DIR__) . '/modules/shop/ShopOrderStatus.class.php';

function shop_order_status_assert($condition, $message)
{
    if (!$condition) { fwrite(STDERR, "FAIL: " . $message . "\n"); exit(1); }
}

shop_order_status_assert(ShopOrderStatus::label('paid') === 'Оплачен', 'Known statuses must have labels.');
shop_order_status_assert(ShopOrderStatus::label('custom') === 'custom', 'Unknown statuses must stay readable.');
shop_order_status_assert(ShopOrderStatus::customerCanCancel(array('status' => 'confirmed', 'stock_released' => 0)), 'Confirmed, reserved orders can be cancelled.');
shop_order_status_assert(!ShopOrderStatus::customerCanCancel(array('status' => 'paid', 'stock_released' => 0)), 'Paid orders cannot be cancelled.');
shop_order_status_assert(!ShopOrderStatus::customerCanCancel(array('status' => 'new', 'stock_released' => 1)), 'Released stock prevents cancellation.');

echo "OK\n";
