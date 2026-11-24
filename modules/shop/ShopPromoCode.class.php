<?php

/** Validates the portable representation stored for a promo code. */
class ShopPromoCode
{
    public static function normalize($code)
    {
        $code = strtoupper(trim((string)$code));
        $code = preg_replace('/[^A-Z0-9_-]/', '', $code);
        return strlen($code) >= 2 && strlen($code) <= 64 ? $code : '';
    }
}
