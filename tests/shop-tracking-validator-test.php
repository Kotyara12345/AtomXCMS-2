<?php
require_once dirname(__DIR__) . '/modules/shop/ShopTrackingValidator.class.php';
if (ShopTrackingValidator::number(" AB\n12 ") !== 'AB 12' || !ShopTrackingValidator::url('https://example.test/a') || ShopTrackingValidator::url('javascript:alert(1)')) exit(1);
echo "OK\n";
