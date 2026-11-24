<?php
class ShopOrderFormatter { public static function date($value) { if (empty($value) || $value === '0000-00-00 00:00:00') return ''; $time = strtotime($value); return $time ? date('d.m.Y H:i', $time) : (string)$value; } public static function number($id) { return 100000 + (int)$id; } }
