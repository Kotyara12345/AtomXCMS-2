<?php
class ShopDeliveryMethods
{
    public static function all($currencyCode = null, $subtotal = null)
    {
        $currency = $currencyCode ? ShopHelper::currency($currencyCode) : ShopHelper::activeCurrency();
        $methods = array();
        if ((int)ShopHelper::cfg('pickup_enabled', 1) === 1) $methods['pickup'] = self::pickup();
        if ((int)ShopHelper::cfg('courier_enabled', 1) === 1) {
            $lower = strtolower($currency['code']);
            $price = max(0, (float)ShopHelper::cfg('delivery_price_' . $lower, 0));
            $freeFrom = max(0, (float)ShopHelper::cfg('free_delivery_from_' . $lower, 0));
            if ($freeFrom > 0 && $subtotal !== null && (float)$subtotal >= $freeFrom) $price = 0.0;
            $methods['courier'] = array('code' => 'courier', 'title' => 'Доставка', 'price' => $price, 'price_formatted' => $price > 0 ? ShopHelper::formatPrice($price, $currency['code']) : 'Бесплатно', 'free_from' => $freeFrom, 'free_from_formatted' => $freeFrom > 0 ? ShopHelper::formatPrice($freeFrom, $currency['code']) : '');
        }
        return $methods ?: array('pickup' => self::pickup());
    }
    private static function pickup() { return array('code' => 'pickup', 'title' => 'Самовывоз', 'price' => 0.0, 'price_formatted' => 'Бесплатно'); }
}
