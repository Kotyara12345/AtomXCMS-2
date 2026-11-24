<?php

function h($value) { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
class ShopHelper { public static function register() { return array(); } }

require_once dirname(__DIR__) . '/modules/shop/ShopDescriptionFormatter.class.php';

function shop_description_assert($condition, $message)
{
    if (!$condition) { fwrite(STDERR, "FAIL: " . $message . "\n"); exit(1); }
}

$html = ShopDescriptionFormatter::format('<p onclick="alert(1)">Safe<script>alert(1)</script><a href="javascript:bad()">link</a></p>');
shop_description_assert(strpos($html, 'script') === false, 'Blocked elements must be removed.');
shop_description_assert(strpos($html, 'onclick') === false, 'Event attributes must be removed.');
shop_description_assert(strpos($html, 'href="#"') !== false, 'Dangerous URLs must be neutralized.');
shop_description_assert(ShopDescriptionFormatter::text('<p>One</p><p>Two &amp; three</p>') === 'One Two & three', 'Text conversion must preserve readable spacing.');

echo "OK\n";
