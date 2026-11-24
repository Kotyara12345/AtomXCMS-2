<?php
class ShopHelper { public static $settings = array(); public static function cfg($key, $default = null) { return isset(self::$settings[$key]) ? self::$settings[$key] : $default; } public static function currency($code) { return array('code' => strtoupper($code)); } public static function activeCurrency() { return array('code' => 'RUB'); } public static function formatPrice($value, $code) { return $value . ' ' . $code; } }
require_once dirname(__DIR__) . '/modules/shop/ShopDeliveryMethods.class.php';
function shop_delivery_assert($condition, $message) { if (!$condition) { fwrite(STDERR, "FAIL: " . $message . "\n"); exit(1); } }
ShopHelper::$settings = array('pickup_enabled' => 0, 'courier_enabled' => 1, 'delivery_price_rub' => 15, 'free_delivery_from_rub' => 100);
shop_delivery_assert(ShopDeliveryMethods::all('rub', 99)['courier']['price'] === 15.0, 'Delivery below threshold must be paid.');
shop_delivery_assert(ShopDeliveryMethods::all('rub', 100)['courier']['price'] === 0.0, 'Delivery at threshold must be free.');
echo "OK\n";
