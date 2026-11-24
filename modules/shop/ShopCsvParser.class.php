<?php
class ShopCsvParser
{
    public static function variantHeaderMap($header) { return self::headerMap($header, array('variant_id'=>array('variant_id','id','идентификатор варианта'),'currency'=>array('currency','currency_code','валюта'),'sku'=>array('sku','артикул'),'stock'=>array('stock','остаток','количество'),'active'=>array('active','активен','активность'),'price'=>array('price','цена'),'compare_price'=>array('compare_price','old_price','старая цена','цена до скидки'))); }
    public static function headerMap($header, $aliases) { $map=array(); foreach ((array)$header as $index=>$name) { $name=mb_strtolower(trim(preg_replace('/^\xEF\xBB\xBF/','',(string)$name))); foreach ($aliases as $field=>$names) if (!isset($map[$field]) && in_array($name,$names,true)) { $map[$field]=(int)$index; break; } } return $map; }
    public static function flag($value) { return in_array(mb_strtolower(trim((string)$value)),array('1','да','yes','true','+','y'),true)?1:0; }
}
