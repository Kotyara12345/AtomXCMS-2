<?php
class ShopPhoneValidator
{
    public static function valid($phone)
    {
        $digits = preg_replace('/\D+/', '', (string)$phone);
        return strlen($digits) >= 7 && strlen($digits) <= 15;
    }
}
