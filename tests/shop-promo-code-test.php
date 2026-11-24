<?php
require_once dirname(__DIR__) . '/modules/shop/ShopPromoCode.class.php';
function shop_promo_code_assert($condition, $message) { if (!$condition) { fwrite(STDERR, "FAIL: " . $message . "\n"); exit(1); } }
shop_promo_code_assert(ShopPromoCode::normalize(' sale-10 ') === 'SALE-10', 'Code must be trimmed and uppercased.');
shop_promo_code_assert(ShopPromoCode::normalize('x!') === '', 'Short unsafe code must be rejected.');
shop_promo_code_assert(ShopPromoCode::normalize('A!B@1') === 'AB1', 'Unsafe characters must be removed.');
echo "OK\n";
