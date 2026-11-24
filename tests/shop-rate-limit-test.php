<?php

require_once dirname(__DIR__) . '/modules/shop/ShopRateLimiter.class.php';

function shop_rate_limit_assert($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: " . $message . "\n");
        exit(1);
    }
}

$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
shop_rate_limit_assert(
    ShopRateLimiter::key('place_order', 'customer@example.test')
        === sha1('place_order|127.0.0.1|customer@example.test'),
    'The limiter key must combine action, client IP and subject.'
);

unset($_SERVER['REMOTE_ADDR']);
shop_rate_limit_assert(
    ShopRateLimiter::key('place_order') === sha1('place_order|unknown|'),
    'The limiter key must use the stable fallback when client IP is absent.'
);

echo "OK\n";
