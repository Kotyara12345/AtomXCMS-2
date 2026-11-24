<?php

/** Parses the administrator payment-method setting. */
class ShopPaymentMethods
{
    public static function all($raw)
    {
        $methods = array();
        foreach (preg_split('/\r\n|\r|\n/', (string)$raw) as $line) {
            $parts = explode('|', trim($line), 2);
            $code = strtolower(trim($parts[0]));
            if ($code === '' || !preg_match('/^[a-z0-9_\-]+$/', $code)) continue;
            $title = isset($parts[1]) && trim($parts[1]) !== '' ? trim($parts[1]) : $code;
            $methods[$code] = array('code' => $code, 'title' => $title);
        }
        return $methods ?: array(
            'cash' => array('code' => 'cash', 'title' => 'Наличными при получении'),
            'transfer' => array('code' => 'transfer', 'title' => 'Банковский перевод'),
        );
    }
}
