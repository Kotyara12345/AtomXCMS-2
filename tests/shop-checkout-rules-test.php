<?php

class ShopHelper
{
    public static $settings = array();
    public static function cfg($key, $default = null) { return isset(self::$settings[$key]) ? self::$settings[$key] : $default; }
    public static function currency($code) { return array('code' => strtoupper($code)); }
    public static function activeCurrency() { return array('code' => 'RUB'); }
    public static function formatPrice($value, $code) { return number_format($value, 2, '.', '') . ' ' . $code; }
}

require_once dirname(__DIR__) . '/modules/shop/ShopCheckoutRules.class.php';

function shop_checkout_assert($condition, $message)
{
    if (!$condition) { fwrite(STDERR, "FAIL: " . $message . "\n"); exit(1); }
}

ShopHelper::$settings = array('courier_enabled' => 1, 'free_delivery_from_rub' => 100, 'min_order_total_rub' => 50);
shop_checkout_assert(ShopCheckoutRules::freeDeliveryHint(100, 'rub') === 'Доставка бесплатно.', 'Threshold must enable free delivery.');
shop_checkout_assert(ShopCheckoutRules::freeDeliveryHint(75, 'rub') === 'До бесплатной доставки осталось 25.00 RUB.', 'Hint must show delivery shortfall.');
shop_checkout_assert(ShopCheckoutRules::minOrderShortfall(35, 'rub') === 15.0, 'Minimum-order shortfall must be exact.');
shop_checkout_assert(ShopCheckoutRules::minOrderHint(35, 'rub') === 'Минимальная сумма заказа — 50.00 RUB. Добавьте товаров на 15.00 RUB.', 'Minimum-order hint must include both values.');

echo "OK\n";
