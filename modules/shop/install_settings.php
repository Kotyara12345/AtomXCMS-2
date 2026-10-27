<?php
/**
 * Default shop settings.
 *
 * The installer stores this array as Config::read('shop').
 */

$FpsInstallSettings = array(
    'active' => 1,
    'title' => 'Магазин',
    'description' => 'Каталог товаров и оформление заказов',
    'per_page' => 12,
    'active_currency' => 'RUB',

    'pickup_enabled' => 1,
    'courier_enabled' => 1,

    'delivery_price_rub' => 0,
    'delivery_price_usd' => 0,
    'delivery_price_eur' => 0,
    'delivery_price_byn' => 0,

    'payment_methods' => "cash|Наличными при получении\ntransfer|Банковский перевод",
    'notify_email' => '',
);
