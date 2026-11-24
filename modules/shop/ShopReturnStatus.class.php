<?php
class ShopReturnStatus { public static function all() { return array('new'=>'Оформлен','received'=>'Принят на склад','refunded'=>'Деньги возвращены','cancelled'=>'Отменён'); } public static function label($status) { $all=self::all(); return isset($all[$status]) ? $all[$status] : (string)$status; } }
