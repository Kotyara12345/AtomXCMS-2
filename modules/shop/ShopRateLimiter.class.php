<?php

/**
 * Database-backed request limiter for public shop actions.
 *
 * It fails open when the database is unavailable so checkout remains usable.
 */
class ShopRateLimiter
{
    /** @var bool */
    private static $tableReady = false;

    public static function ensureTable()
    {
        if (self::$tableReady) return true;
        try {
            ShopHelper::db()->query("CREATE TABLE IF NOT EXISTS `" . ShopHelper::table('rate_limits') . "` (
                `key_hash` CHAR(40) NOT NULL,
                `hits` INT UNSIGNED NOT NULL DEFAULT 0,
                `window_start` DATETIME DEFAULT NULL,
                PRIMARY KEY (`key_hash`),
                KEY `window_start` (`window_start`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Throwable $e) {
            return false;
        }
        self::$tableReady = true;
        return true;
    }

    public static function key($action, $subject = '')
    {
        $ip = !empty($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : 'unknown';
        return sha1($action . '|' . $ip . '|' . (string)$subject);
    }

    public static function allow($action, $limit, $seconds, $subject = '')
    {
        if (!self::ensureTable()) return true;
        $hash = self::key($action, $subject);
        $limit = max(1, (int)$limit);
        $seconds = max(1, (int)$seconds);
        try {
            $rows = ShopHelper::db()->query("SELECT * FROM `" . ShopHelper::table('rate_limits') . "`
                WHERE `key_hash` = '" . ShopHelper::esc($hash) . "' LIMIT 1");
            $row = $rows[0] ?? null;
            $now = time();
            if (!$row || empty($row['window_start']) || strtotime($row['window_start']) < $now - $seconds) {
                ShopHelper::db()->query("INSERT INTO `" . ShopHelper::table('rate_limits') . "`
                    (`key_hash`, `hits`, `window_start`) VALUES ('" . ShopHelper::esc($hash) . "', 1, NOW())
                    ON DUPLICATE KEY UPDATE `hits` = 1, `window_start` = NOW()");
                return true;
            }
            if ((int)$row['hits'] >= $limit) return false;
            ShopHelper::db()->query("UPDATE `" . ShopHelper::table('rate_limits') . "`
                SET `hits` = `hits` + 1 WHERE `key_hash` = '" . ShopHelper::esc($hash) . "'");
            return true;
        } catch (Throwable $e) {
            return true;
        }
    }
}
