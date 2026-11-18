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
    'order_ttl_hours' => 72,
    'low_stock_threshold' => 3,
    'abandoned_cart_hours' => 24,
    'reviews_enabled' => 1,
    'reviews_moderate' => 1,
    'review_photos' => 1,
    'similar_enabled' => 1,
    'related_enabled' => 1,
    'structured_data' => 1,
    'noindex_filters' => 1,
    'yml_enabled' => 1,
    'yml_sales_notes' => '',
    'free_delivery_from_rub' => 0,
    'free_delivery_from_usd' => 0,
    'free_delivery_from_eur' => 0,
    'free_delivery_from_byn' => 0,
    'min_order_total_rub' => 0,
    'min_order_total_usd' => 0,
    'min_order_total_eur' => 0,
    'min_order_total_byn' => 0,
);
