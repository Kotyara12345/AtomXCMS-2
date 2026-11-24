<?php
require_once dirname(__DIR__) . '/modules/shop/ShopCsvParser.class.php';
function shop_csv_assert($ok, $message) { if (!$ok) { fwrite(STDERR, "FAIL: $message\n"); exit(1); } }
$map = ShopCsvParser::variantHeaderMap(array("\xEF\xBB\xBFid", 'Валюта', 'цена', 'цена'));
shop_csv_assert($map === array('variant_id' => 0, 'currency' => 1, 'price' => 2), 'Header aliases or first-match rule failed.');
shop_csv_assert(ShopCsvParser::flag('Да') === 1 && ShopCsvParser::flag('0') === 0, 'Boolean flags parsed incorrectly.');
echo "OK\n";
