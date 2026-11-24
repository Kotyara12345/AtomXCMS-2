<?php

/**
 * Pure cart arithmetic, kept separate from session and promotion handling.
 */
class ShopCartCalculator
{
    public static function summarize($items)
    {
        $subtotal = 0.0;
        $quantity = 0;
        foreach ($items as $item) {
            $subtotal += (float)$item['total'];
            $quantity += (int)$item['quantity'];
        }
        return array('subtotal' => $subtotal, 'quantity' => $quantity);
    }
}
