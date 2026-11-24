<?php
require_once dirname(__DIR__) . '/modules/shop/ShopOrderFormatter.class.php';
if (ShopOrderFormatter::date('0000-00-00 00:00:00') !== '' || ShopOrderFormatter::number(42) !== 100042) exit(1);
echo "OK\n";
