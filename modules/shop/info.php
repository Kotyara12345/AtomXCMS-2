<?php

$menuInfo = array(
    'url' => 'shop.php',
    'ankor' => 'Магазин',
    'icon' => 'shop',
    'sub' => array(
        'shop.php' => 'Обзор',
        'shop.php?tab=products' => 'Товары',
        'shop.php?tab=attributes' => 'Атрибуты',
        'shop.php?tab=categories' => 'Категории',
        'shop.php?tab=orders' => 'Заказы',
        'settings.php?m=shop' => 'Настройки',
        'design.php?m=shop' => 'Дизайн',
    ),
);

$settingsInfo = array(
    'title' => array(
        'type' => 'text',
        'title' => 'Заголовок модуля',
        'description' => 'Используется в шапке страницы магазина.',
    ),
    'description' => array(
        'type' => 'textarea',
        'title' => 'Описание',
        'description' => 'Meta description страниц магазина по умолчанию.',
    ),
    'per_page' => array(
        'type' => 'text',
        'title' => 'Товаров на странице',
        'default' => '12',
    ),

    'Валюта',
    'active_currency' => array(
        'type' => 'select',
        'title' => 'Активная валюта сайта',
        'description' => 'Публичные цены показываются только в этой валюте. Цены задаются отдельно для каждой валюты.',
        'options' => array(
            'RUB' => 'RUB — Российский рубль',
            'USD' => 'USD — Доллар США',
            'EUR' => 'EUR — Евро',
            'BYN' => 'BYN — Белорусский рубль',
        ),
        'default' => 'RUB',
    ),

    'Доставка',
    'pickup_enabled' => array(
        'type' => 'checkbox',
        'title' => 'Самовывоз',
        'description' => 'Разрешить получение заказа самовывозом.',
        'value' => '1',
        'checked' => '1',
        'default' => '1',
    ),
    'courier_enabled' => array(
        'type' => 'checkbox',
        'title' => 'Доставка',
        'description' => 'Разрешить доставку по фиксированной стоимости.',
        'value' => '1',
        'checked' => '1',
        'default' => '1',
    ),
    'delivery_price_rub' => array(
        'type' => 'text',
        'title' => 'Стоимость доставки, RUB',
        'default' => '0',
    ),
    'delivery_price_usd' => array(
        'type' => 'text',
        'title' => 'Стоимость доставки, USD',
        'default' => '0',
    ),
    'delivery_price_eur' => array(
        'type' => 'text',
        'title' => 'Стоимость доставки, EUR',
        'default' => '0',
    ),
    'delivery_price_byn' => array(
        'type' => 'text',
        'title' => 'Стоимость доставки, BYN',
        'default' => '0',
    ),

    'Оплата',
    'payment_methods' => array(
        'type' => 'textarea',
        'title' => 'Способы оплаты',
        'description' => 'По одному способу в строке в формате: код|Название. Например: cash|Наличными при получении',
        'default' => "cash|Наличными при получении\ntransfer|Банковский перевод",
    ),
    'notify_email' => array(
        'type' => 'text',
        'title' => 'E-mail для уведомлений о заказах',
        'description' => 'Если пусто, используется e-mail администратора из общих настроек.',
    ),

    'Общие',
    'active' => array(
        'type' => 'checkbox',
        'title' => 'Статус',
        'description' => 'Включить или выключить модуль.',
        'value' => '1',
        'checked' => '1',
        'default' => '1',
    ),
);
