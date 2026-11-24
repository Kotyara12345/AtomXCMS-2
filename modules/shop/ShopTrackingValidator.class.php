<?php
class ShopTrackingValidator
{
    public static function number($value) { $value = preg_replace('/[\x00-\x1F\x7F]+/', ' ', (string)$value); return mb_substr(trim((string)$value), 0, 128); }
    public static function url($url) { $url = trim((string)$url); return $url === '' || (preg_match('#^https?://#i', $url) && filter_var($url, FILTER_VALIDATE_URL) !== false); }
}
