<?php
require_once dirname(__DIR__) . '/modules/shop/ShopPaymentMethods.class.php';
function shop_payment_assert($condition, $message) { if (!$condition) { fwrite(STDERR, "FAIL: " . $message . "\n"); exit(1); } }
$methods = ShopPaymentMethods::all("card|Картой\ninvalid code|Bad\ncash");
shop_payment_assert(isset($methods['card']) && $methods['card']['title'] === 'Картой', 'Valid configured method missing.');
shop_payment_assert(isset($methods['cash']) && $methods['cash']['title'] === 'cash', 'Code must be fallback title.');
shop_payment_assert(!isset($methods['invalid code']), 'Invalid code must be ignored.');
shop_payment_assert(isset(ShopPaymentMethods::all('')['cash']), 'Default methods missing.');
echo "OK\n";
