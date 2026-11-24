<?php

/**
 * Currency-aware order thresholds and checkout hints.
 */
class ShopCheckoutRules
{
    public static function freeDeliveryHint($subtotal, $currencyCode = null)
    {
        if ((int)ShopHelper::cfg('courier_enabled', 1) !== 1) return '';
        $currency = $currencyCode ? ShopHelper::currency($currencyCode) : ShopHelper::activeCurrency();
        $freeFrom = max(0, (float)ShopHelper::cfg('free_delivery_from_' . strtolower($currency['code']), 0));
        if ($freeFrom <= 0) return '';
        $subtotal = round((float)$subtotal, 2);
        if ($subtotal >= $freeFrom) return 'Доставка бесплатно.';
        return 'До бесплатной доставки осталось ' . ShopHelper::formatPrice($freeFrom - $subtotal, $currency['code']) . '.';
    }

    public static function minOrderTotal($currencyCode = null)
    {
        $currency = $currencyCode ? ShopHelper::currency($currencyCode) : ShopHelper::activeCurrency();
        return max(0, (float)ShopHelper::cfg('min_order_total_' . strtolower($currency['code']), 0));
    }

    public static function minOrderShortfall($subtotal, $currencyCode = null)
    {
        $min = self::minOrderTotal($currencyCode);
        if ($min <= 0) return 0.0;
        return max(0, round($min - (float)$subtotal, 2));
    }

    public static function minOrderHint($subtotal, $currencyCode = null)
    {
        $currency = $currencyCode ? ShopHelper::currency($currencyCode) : ShopHelper::activeCurrency();
        $shortfall = self::minOrderShortfall($subtotal, $currency['code']);
        if ($shortfall <= 0) return '';
        return 'Минимальная сумма заказа — ' . ShopHelper::formatPrice(self::minOrderTotal($currency['code']), $currency['code'])
            . '. Добавьте товаров на ' . ShopHelper::formatPrice($shortfall, $currency['code']) . '.';
    }
}
