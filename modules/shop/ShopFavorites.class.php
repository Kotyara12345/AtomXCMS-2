<?php
class ShopFavorites
{
    private static $ready = false;
    public static function ensureTable() { if (self::$ready) return true; $table = ShopHelper::table('favorites'); try { ShopHelper::db()->query("CREATE TABLE IF NOT EXISTS `{$table}` (`user_id` INT UNSIGNED NOT NULL, `product_id` INT UNSIGNED NOT NULL, `created_at` DATETIME DEFAULT NULL, PRIMARY KEY (`user_id`, `product_id`), KEY `product_id` (`product_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Exception $e) { return false; } self::$ready = true; return true; }
    public static function ids($userId) { $userId = (int)$userId; if ($userId < 1 || !self::ensureTable()) return array(); $table = ShopHelper::table('favorites'); $rows = ShopHelper::db()->query("SELECT `product_id` FROM `{$table}` WHERE `user_id` = {$userId} ORDER BY `created_at` DESC, `product_id` DESC"); return array_map(function ($row) { return (int)$row['product_id']; }, $rows); }
    public static function contains($userId, $productId) { $userId = (int)$userId; $productId = (int)$productId; if ($userId < 1 || $productId < 1 || !self::ensureTable()) return false; $table = ShopHelper::table('favorites'); return !empty(ShopHelper::db()->query("SELECT 1 FROM `{$table}` WHERE `user_id` = {$userId} AND `product_id` = {$productId} LIMIT 1")); }
    public static function add($userId, $productId) { return self::change($userId, $productId, 'INSERT IGNORE INTO `%s` (`user_id`, `product_id`, `created_at`) VALUES (%d, %d, NOW())'); }
    public static function remove($userId, $productId) { return self::change($userId, $productId, 'DELETE FROM `%s` WHERE `user_id` = %d AND `product_id` = %d'); }
    public static function products($userId, $limit = 100) { $ids = self::ids($userId); return $ids ? ShopHelper::getProducts(array('ids' => $ids, 'limit' => $limit)) : array('items' => array(), 'total' => 0, 'page' => 1, 'pages' => 0, 'limit' => 0, 'sort' => 'default'); }
    private static function change($userId, $productId, $sql) { $userId = (int)$userId; $productId = (int)$productId; if ($userId < 1 || $productId < 1 || !self::ensureTable()) return false; ShopHelper::db()->query(sprintf($sql, ShopHelper::table('favorites'), $userId, $productId)); return true; }
}
