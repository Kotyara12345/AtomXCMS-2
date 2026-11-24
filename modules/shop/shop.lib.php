<?php

require_once __DIR__ . '/ShopRateLimiter.class.php';
require_once __DIR__ . '/ShopDescriptionFormatter.class.php';
require_once __DIR__ . '/ShopOrderStatus.class.php';
require_once __DIR__ . '/ShopCartCalculator.class.php';
require_once __DIR__ . '/ShopCheckoutRules.class.php';
require_once __DIR__ . '/ShopPaymentMethods.class.php';
require_once __DIR__ . '/ShopDeliveryMethods.class.php';
require_once __DIR__ . '/ShopPromoCode.class.php';
require_once __DIR__ . '/ShopFavorites.class.php';
require_once __DIR__ . '/ShopCategoryTree.class.php';
require_once __DIR__ . '/ShopPhoneValidator.class.php';
require_once __DIR__ . '/ShopCsvParser.class.php';
require_once __DIR__ . '/ShopTrackingValidator.class.php';
require_once __DIR__ . '/ShopReturnStatus.class.php';
require_once __DIR__ . '/ShopReviewRatings.class.php';
require_once __DIR__ . '/ShopOrderFormatter.class.php';

/**
 * Shared shop helpers.
 *
 * The module deliberately uses the CMS database layer directly for catalogue
 * queries: the storefront needs joined variant prices, aggregate minimum
 * prices and order snapshots, which are easier to express as SQL than as a
 * generic ORM relation tree.
 */
#[\AllowDynamicProperties]
class ShopHelper
{
    const CART_KEY = 'shop_cart';
    const FLASH_KEY = 'shop_flash';
    const CSRF_KEY = 'shop_csrf_token';
    const PROMO_KEY = 'shop_promocode';

    /** @var array|null */
    private static $currencyCache = null;

    /** @var array|null */
    private static $activeCurrencyCache = null;

    public static function register()
    {
        return Register::getInstance();
    }

    public static function db()
    {
        $Register = self::register();
        return $Register['DB'];
    }

    public static function cfg($key, $default = null)
    {
        $value = Config::read($key, 'shop');
        if ($value === null || $value === '') return $default;
        return $value;
    }

    public static function table($name)
    {
        if (strpos($name, 'shop_') !== 0) $name = 'shop_' . $name;
        return self::db()->getFullTableName($name);
    }

    public static function esc($value)
    {
        return self::db()->escape($value);
    }

    public static function e($value)
    {
        return h($value);
    }

    /**
     * Schema.org JSON-LD block.
     *
     * JSON_HEX_TAG keeps a product title or description containing
     * "</script>" from closing the script element.
     */
    public static function jsonLdScript($data)
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        if ($json === false) return '';
        return '<script type="application/ld+json">' . $json . '</script>';
    }

    /**
     * Render an administrator-entered description.
     *
     * The shop uses the same WysiBB editor as news articles, so new values are
     * BBCode. Older values may be plain text or HTML saved by the previous
     * editor; both remain supported.
     */
    public static function formatDescription($text)
    {
        return ShopDescriptionFormatter::format($text);
    }

    private static function sanitizeDescriptionHtml($html)
    {
        $html = (string)$html;
        $blocked = 'script|style|iframe|object|embed|form|input|textarea|select|button|meta|link';

        // Remove element contents first, then any remaining standalone tags.
        $html = preg_replace('#<(' . $blocked . ')\b[^>]*>.*?</\1>#is', '', $html);
        $html = preg_replace('#</?(?:' . $blocked . ')\b[^>]*>#is', '', $html);
        $html = preg_replace('#\s+on[a-z]+\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)#is', '', $html);
        $html = preg_replace_callback('#\s+style\s*=\s*(["\'])(.*?)\1#is', array('self', 'sanitizeDescriptionStyle'), $html);
        $html = preg_replace('#\s+(href|src)\s*=\s*(["\'])\s*(?:javascript|vbscript|data):[^"\']*\2#is', ' $1="#"', $html);
        $html = preg_replace('#\s+(href|src)\s*=\s*(?:javascript|vbscript|data):[^\s>]*#is', ' $1="#"', $html);

        $allowed = '<p><br><strong><b><em><i><u><s><strike><ul><ol><li><a><img>'
            . '<h1><h2><h3><h4><h5><h6><blockquote><pre><code><hr>'
            . '<table><thead><tbody><tfoot><tr><th><td><figure><figcaption><span><div>';
        return strip_tags($html, $allowed);
    }

    private static function sanitizeDescriptionStyle($matches)
    {
        $safe = array();
        foreach (explode(';', $matches[2]) as $declaration) {
            if (!preg_match('/^\s*(text-align|color|background-color|font-weight|font-style|text-decoration)\s*:\s*(.+?)\s*$/i', $declaration, $parts)) {
                continue;
            }
            $value = trim($parts[2]);
            if ($value === '' || preg_match('/(?:expression|url\s*\(|javascript|behavior|@import)/i', $value)) {
                continue;
            }
            if (preg_match('/^[#a-z0-9\s(),.%\-]+$/i', $value)) {
                $safe[] = strtolower($parts[1]) . ': ' . $value;
            }
        }
        return $safe ? ' style="' . implode('; ', $safe) . '"' : '';
    }

    public static function descriptionText($text)
    {
        return ShopDescriptionFormatter::text($text);
    }

    public static function currencies($onlyEnabled = true)
    {
        if (self::$currencyCache === null) {
            $table = self::table('currencies');
            $rows = array();
            try {
                $rows = self::db()->query("SELECT * FROM `{$table}` ORDER BY `position` ASC, `id` ASC");
            } catch (Throwable $e) {
                $rows = array();
            }

            if (empty($rows)) {
                $rows = array(
                    array('code' => 'RUB', 'title' => 'Российский рубль', 'symbol' => '₽', 'enabled' => 1, 'position' => 10),
                    array('code' => 'USD', 'title' => 'Доллар США', 'symbol' => '$', 'enabled' => 1, 'position' => 20),
                    array('code' => 'EUR', 'title' => 'Евро', 'symbol' => '€', 'enabled' => 1, 'position' => 30),
                    array('code' => 'BYN', 'title' => 'Белорусский рубль', 'symbol' => 'Br', 'enabled' => 1, 'position' => 40),
                );
            }

            $cache = array();
            foreach ($rows as $row) {
                $code = strtoupper((string)$row['code']);
                if ($code === '') continue;
                $row['code'] = $code;
                $cache[$code] = $row;
            }
            self::$currencyCache = $cache;
        }

        if (!$onlyEnabled) return self::$currencyCache;

        $enabled = array();
        foreach (self::$currencyCache as $code => $row) {
            if (!empty($row['enabled'])) $enabled[$code] = $row;
        }
        return $enabled ? $enabled : self::$currencyCache;
    }

    public static function activeCurrency()
    {
        if (self::$activeCurrencyCache !== null) return self::$activeCurrencyCache;

        $currencies = self::currencies(true);
        $code = strtoupper(trim((string)self::cfg('active_currency', 'RUB')));
        if (!isset($currencies[$code])) {
            $code = isset($currencies['RUB']) ? 'RUB' : key($currencies);
        }
        self::$activeCurrencyCache = $currencies[$code];
        return self::$activeCurrencyCache;
    }

    public static function currency($code = null)
    {
        $currencies = self::currencies(false);
        if ($code === null || $code === '') {
            return self::activeCurrency();
        }
        $code = strtoupper((string)$code);
        return isset($currencies[$code]) ? $currencies[$code] : self::activeCurrency();
    }

    public static function formatPrice($amount, $currencyCode = null)
    {
        $currency = self::currency($currencyCode);
        $symbol = isset($currency['symbol']) ? $currency['symbol'] : $currency['code'];
        return number_format((float)$amount, 2, ',', ' ') . ' ' . $symbol;
    }

    public static function formatDate($value)
    {
        return ShopOrderFormatter::date($value);
    }

    public static function orderNumber($id)
    {
        return ShopOrderFormatter::number($id);
    }

    public static function phoneValid($phone)
    {
        return ShopPhoneValidator::valid($phone);
    }

    /**
     * Absolute URL for e-mail links. get_url() returns a site-relative path.
     */
    public static function absoluteUrl($path)
    {
        $path = trim((string)$path);
        if ($path === '') return '';
        if (preg_match('#^https?://#i', $path)) return $path;

        if (function_exists('atm_site_origin')) {
            return rtrim(atm_site_origin(false), '/') . '/' . ltrim($path, '/');
        }
        return $path;
    }

    /**
     * File cache for the YML feed. Marketplaces poll it often and the feed
     * touches the whole catalogue.
     */
    public static function ymlCachePath()
    {
        $dir = ROOT . '/sys/cache';
        if (!is_dir($dir) || !is_writable($dir)) return '';
        return $dir . '/shop_yml.xml';
    }

    public static function clearYmlCache()
    {
        $file = self::ymlCachePath();
        if ($file !== '' && is_file($file)) @unlink($file);
    }

    /* ---------------------------------------------------------------------
     * Cart
     * ------------------------------------------------------------------ */

    /** @var bool */
    private static $cartsTableReady = false;

    /** @var bool */
    private static $cartDbChecked = false;

    public static function ensureCartsTable()
    {
        if (self::$cartsTableReady) return true;

        $table = self::table('carts');
        try {
            self::db()->query("CREATE TABLE IF NOT EXISTS `{$table}` (
                `user_id` INT UNSIGNED NOT NULL,
                `data` MEDIUMTEXT,
                `updated_at` DATETIME DEFAULT NULL,
                `reminded_at` DATETIME DEFAULT NULL,
                PRIMARY KEY (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Throwable $e) {
            return false;
        }

        try {
            self::db()->query("SELECT `reminded_at` FROM `{$table}` LIMIT 1");
        } catch (Throwable $e) {
            try {
                self::db()->query("ALTER TABLE `{$table}` ADD COLUMN `reminded_at` DATETIME DEFAULT NULL");
            } catch (Throwable $alterError) {
                return false;
            }
        }

        self::$cartsTableReady = true;
        return true;
    }

    /** @var bool */
    private static $promoTablesReady = false;

    /**
     * Promo codes and the price columns that ship with them are added to older
     * installations on first use, like the cart and tracking helpers.
     */
    public static function ensurePromoTables()
    {
        if (self::$promoTablesReady) return true;

        try {
            self::db()->query("CREATE TABLE IF NOT EXISTS `" . self::table('promocodes') . "` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `code` VARCHAR(64) NOT NULL DEFAULT '',
                `discount_type` VARCHAR(16) NOT NULL DEFAULT 'percent',
                `value` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                `currency_code` VARCHAR(3) NOT NULL DEFAULT 'RUB',
                `min_total` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                `max_uses` INT UNSIGNED NOT NULL DEFAULT 0,
                `used_count` INT UNSIGNED NOT NULL DEFAULT 0,
                `once_per_customer` TINYINT(1) NOT NULL DEFAULT 0,
                `restrict_products` TEXT,
                `restrict_categories` TEXT,
                `starts_at` DATETIME DEFAULT NULL,
                `ends_at` DATETIME DEFAULT NULL,
                `active` TINYINT(1) NOT NULL DEFAULT 1,
                `created_at` DATETIME DEFAULT NULL,
                `updated_at` DATETIME DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `code` (`code`),
                KEY `active` (`active`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Throwable $e) {
            return false;
        }

        $orders = self::table('orders');
        try {
            self::db()->query("SELECT `discount` FROM `{$orders}` LIMIT 1");
        } catch (Throwable $e) {
            try {
                self::db()->query("ALTER TABLE `{$orders}`
                    ADD COLUMN `discount` DECIMAL(12,2) NOT NULL DEFAULT 0.00");
            } catch (Throwable $alterError) {
            }
        }
        try {
            self::db()->query("SELECT `promocode` FROM `{$orders}` LIMIT 1");
        } catch (Throwable $e) {
            try {
                self::db()->query("ALTER TABLE `{$orders}`
                    ADD COLUMN `promocode` VARCHAR(64) NOT NULL DEFAULT ''");
            } catch (Throwable $alterError) {
            }
        }
        try {
            self::db()->query("SELECT `once_per_customer` FROM `" . self::table('promocodes') . "` LIMIT 1");
        } catch (Throwable $e) {
            try {
                self::db()->query("ALTER TABLE `" . self::table('promocodes') . "`
                    ADD COLUMN `once_per_customer` TINYINT(1) NOT NULL DEFAULT 0");
            } catch (Throwable $alterError) {
            }
        }

        foreach (array('restrict_products', 'restrict_categories') as $column) {
            try {
                self::db()->query("SELECT `{$column}` FROM `" . self::table('promocodes') . "` LIMIT 1");
            } catch (Throwable $e) {
                try {
                    self::db()->query("ALTER TABLE `" . self::table('promocodes') . "`
                        ADD COLUMN `{$column}` TEXT");
                } catch (Throwable $alterError) {
                }
            }
        }
        try {
            self::db()->query("SELECT `email` FROM `" . self::table('promocodes') . "` LIMIT 1");
        } catch (Throwable $e) {
            try {
                self::db()->query("ALTER TABLE `" . self::table('promocodes') . "`
                    ADD COLUMN `email` VARCHAR(255) NOT NULL DEFAULT ''");
            } catch (Throwable $alterError) {
            }
        }

        $prices = self::table('variant_prices');
        try {
            self::db()->query("SELECT `compare_price` FROM `{$prices}` LIMIT 1");
        } catch (Throwable $e) {
            try {
                self::db()->query("ALTER TABLE `{$prices}`
                    ADD COLUMN `compare_price` DECIMAL(12,2) NOT NULL DEFAULT 0.00");
            } catch (Throwable $alterError) {
            }
        }

        self::$promoTablesReady = true;
        return true;
    }

    /**
     * Promo codes are stored and compared in upper case ASCII. Anything else
     * is usually a copy-paste artefact, so it is dropped instead of rejected.
     */
    /**
     * Comma-separated ids from the admin form as an id => id map, so callers
     * can test membership with isset(). Zero, negatives and junk are dropped.
     */
    public static function promoIdSet($value)
    {
        $ids = array();
        foreach (preg_split('/[,\s]+/', (string)$value) as $id) {
            if ($id === '' || !ctype_digit($id)) continue;
            $id = (int)$id;
            if ($id > 0) $ids[$id] = $id;
        }
        return $ids;
    }

    public static function promoNormalize($code)
    {
        return ShopPromoCode::normalize($code);
    }

    public static function promoCode()
    {
        return isset($_SESSION[self::PROMO_KEY]) ? self::promoNormalize($_SESSION[self::PROMO_KEY]) : '';
    }

    public static function setPromoCode($code)
    {
        $code = self::promoNormalize($code);
        if ($code === '') {
            unset($_SESSION[self::PROMO_KEY]);
            return '';
        }
        $_SESSION[self::PROMO_KEY] = $code;
        return $code;
    }

    public static function clearPromoCode()
    {
        unset($_SESSION[self::PROMO_KEY]);
    }

    /**
     * Validate a promo code against a cart subtotal.
     *
     * With $forUpdate the promo row is locked by the caller's transaction, so
     * the usage limit cannot be exceeded by two orders checked at once.
     */
    public static function promoCheck($code, $subtotal, $currencyCode = null, $forUpdate = false,
        $customerEmail = '', $customerUserId = 0, $items = null)
    {
        $result = array('valid' => false, 'error' => '', 'promocode' => null, 'discount' => 0.0);
        $code = self::promoNormalize($code);
        if ($code === '') {
            $result['error'] = 'Введите промокод.';
            return $result;
        }

        // Never run DDL inside the order transaction; the caller migrates earlier.
        if (!$forUpdate) self::ensurePromoTables();
        $currency = $currencyCode ? self::currency($currencyCode) : self::activeCurrency();
        $orders = self::table('orders');
        $subtotal = round((float)$subtotal, 2);
        try {
            $rows = self::db()->query("SELECT * FROM `" . self::table('promocodes') . "`
                WHERE `code` = '" . self::esc($code) . "' LIMIT 1" . ($forUpdate ? ' FOR UPDATE' : ''));
        } catch (Throwable $e) {
            $result['error'] = 'Промокод не найден.';
            return $result;
        }
        if (empty($rows[0])) {
            $result['error'] = 'Промокод не найден.';
            return $result;
        }

        $promo = $rows[0];
        $result['promocode'] = $promo;
        $promoEmail = mb_strtolower(trim((string)(isset($promo['email']) ? $promo['email'] : '')));
        if ($promoEmail !== '') {
            $email = mb_strtolower(trim((string)$customerEmail));
            if ($email === '') $email = mb_strtolower(trim((string)self::currentUser()['email']));
            if ($email !== $promoEmail) {
                $result['error'] = 'Этот промокод персональный. Войдите с тем e-mail, на который он выдан.';
                return $result;
            }
        }
        if (empty($promo['active'])) {
            $result['error'] = 'Промокод не действует.';
            return $result;
        }
        $now = time();
        if (!empty($promo['starts_at']) && strtotime($promo['starts_at']) > $now) {
            $result['error'] = 'Промокод ещё не начал действовать.';
            return $result;
        }
        if (!empty($promo['ends_at']) && strtotime($promo['ends_at']) < $now) {
            $result['error'] = 'Срок действия промокода истёк.';
            return $result;
        }
        if ((int)$promo['max_uses'] > 0 && (int)$promo['used_count'] >= (int)$promo['max_uses']) {
            $result['error'] = 'Промокод больше не действует: исчерпан лимит применений.';
            return $result;
        }
        if (!empty($promo['once_per_customer'])) {
            $email = trim((string)$customerEmail);
            $userId = (int)$customerUserId;
            if ($email === '' || $userId < 1) {
                $current = self::currentUser();
                if ($email === '') $email = (string)$current['email'];
                if ($userId < 1) $userId = (int)$current['id'];
            }
            $owners = array();
            if ($userId > 0) $owners[] = "`user_id` = {$userId}";
            if ($email !== '') {
                $owners[] = "LOWER(`email`) = '" . self::esc(mb_strtolower($email)) . "'";
            }
            if ($owners) {
                try {
                    $used = self::db()->query("SELECT `id` FROM `{$orders}`
                        WHERE `promocode` = '" . self::esc($code) . "'
                            AND `status` <> 'cancelled' AND (" . implode(' OR ', $owners) . ") LIMIT 1");
                } catch (Throwable $e) {
                    $used = array();
                }
                if (!empty($used)) {
                    $result['error'] = 'Этот промокод можно использовать только один раз.';
                    return $result;
                }
            }
        }
        if ((float)$promo['min_total'] > 0 && $subtotal < (float)$promo['min_total']) {
            $result['error'] = 'Промокод действует при заказе от '
                . self::formatPrice($promo['min_total'], $currency['code']) . '.';
            return $result;
        }

        $type = (string)$promo['discount_type'];
        $value = (float)$promo['value'];

        // A restricted promo discounts only the matching cart lines. Callers
        // without lines (older code, tests) fall back to the whole subtotal.
        $products = self::promoIdSet(isset($promo['restrict_products']) ? $promo['restrict_products'] : '');
        $categories = self::promoIdSet(isset($promo['restrict_categories']) ? $promo['restrict_categories'] : '');
        $eligible = $subtotal;
        if (($products || $categories) && is_array($items)) {
            $eligible = 0.0;
            foreach ($items as $item) {
                $productId = (int)(isset($item['product_id']) ? $item['product_id'] : 0);
                $categoryId = (int)(isset($item['category_id']) ? $item['category_id'] : 0);
                if (isset($products[$productId]) || isset($categories[$categoryId])) {
                    $eligible += (float)(isset($item['total']) ? $item['total'] : 0);
                }
            }
            if ($eligible <= 0) {
                $result['error'] = 'Промокод не действует на товары в корзине.';
                return $result;
            }
        }

        if ($type === 'percent') {
            if ($value <= 0 || $value > 100) {
                $result['error'] = 'Промокод настроен некорректно.';
                return $result;
            }
            $discount = round($eligible * $value / 100, 2);
        } else {
            if (strtoupper((string)$promo['currency_code']) !== strtoupper((string)$currency['code'])) {
                $result['error'] = 'Промокод действует только в валюте ' . $promo['currency_code'] . '.';
                return $result;
            }
            $discount = round(min($value, $eligible), 2);
        }
        if ($discount <= 0) {
            $result['error'] = 'Промокод не даёт скидку для этого заказа.';
            return $result;
        }
        if ($discount > $subtotal) $discount = $subtotal;

        $result['valid'] = true;
        $result['discount'] = $discount;
        return $result;
    }

    private static function cleanCart($cart)
    {
        if (!is_array($cart)) return array();
        $clean = array();
        foreach ($cart as $variantId => $qty) {
            $variantId = (int)$variantId;
            $qty = (int)$qty;
            if ($variantId > 0 && $qty > 0) $clean[$variantId] = $qty;
        }
        return $clean;
    }

    public static function getCart()
    {
        $cart = isset($_SESSION[self::CART_KEY]) && is_array($_SESSION[self::CART_KEY])
            ? $_SESSION[self::CART_KEY] : array();
        $clean = self::cleanCart($cart);
        if ($clean || self::$cartDbChecked) return $clean;

        self::$cartDbChecked = true;
        $userId = (int)self::currentUser()['id'];
        if ($userId < 1 || !self::ensureCartsTable()) return array();

        try {
            $rows = self::db()->query("SELECT `data` FROM `" . self::table('carts') . "`
                WHERE `user_id` = {$userId} LIMIT 1");
        } catch (Throwable $e) {
            return array();
        }
        if (empty($rows[0]['data'])) return array();

        $decoded = json_decode((string)$rows[0]['data'], true);
        $clean = self::cleanCart(is_array($decoded) ? $decoded : array());
        if ($clean) $_SESSION[self::CART_KEY] = $clean;
        return $clean;
    }

    public static function saveCart($cart)
    {
        $clean = self::cleanCart($cart);
        $_SESSION[self::CART_KEY] = $clean;

        $userId = (int)self::currentUser()['id'];
        if ($userId < 1 || !self::ensureCartsTable()) return;

        try {
            self::db()->delete('shop_carts', array('user_id' => $userId));
            if ($clean) {
                self::db()->save('shop_carts', array(
                    'user_id' => $userId,
                    'data' => json_encode($clean),
                    'updated_at' => date('Y-m-d H:i:s'),
                ));
            }
        } catch (Throwable $e) {
            error_log('Shop cart persistence failed: ' . $e->getMessage());
        }
    }

    /**
     * Carts untouched for the configured hours, with the products they hold.
     */
    public static function abandonedCartRecipients($hours = null, $limit = 50)
    {
        if ($hours === null) $hours = (int)self::cfg('abandoned_cart_hours', 24);
        $hours = (int)$hours;
        if ($hours < 1 || !self::ensureCartsTable()) return array();

        $limit = max(1, (int)$limit);
        $carts = self::table('carts');
        $users = self::db()->getFullTableName('users');
        $variants = self::table('variants');
        $products = self::table('products');
        $prices = self::table('variant_prices');
        $code = self::esc(self::activeCurrency()['code']);

        try {
            $rows = self::db()->query("SELECT c.`user_id`, c.`data`, c.`updated_at`,
                    u.`email`, u.`name`, u.`first_name`
                FROM `{$carts}` c
                INNER JOIN `{$users}` u ON u.`id` = c.`user_id`
                WHERE c.`data` IS NOT NULL AND c.`data` <> ''
                    AND u.`email` <> ''
                    AND c.`updated_at` IS NOT NULL
                    AND c.`updated_at` <= DATE_SUB(NOW(), INTERVAL {$hours} HOUR)
                    AND (c.`reminded_at` IS NULL OR c.`reminded_at` < c.`updated_at`)
                ORDER BY c.`updated_at` ASC
                LIMIT {$limit}");
        } catch (Throwable $e) {
            return array();
        }

        $recipients = array();
        foreach ($rows as $row) {
            $cart = self::cleanCart(json_decode((string)$row['data'], true));
            if (!$cart) continue;
            $ids = implode(',', array_map('intval', array_keys($cart)));
            try {
                $variantsRows = self::db()->query("SELECT v.`id` AS variant_id, v.`title` AS variant_title, v.`sku`,
                        p.`id` AS product_id, p.`title` AS product_title, p.`clean_url_title`,
                        vp.`price`
                    FROM `{$variants}` v
                    INNER JOIN `{$products}` p ON p.`id` = v.`product_id`
                    LEFT JOIN `{$prices}` vp ON vp.`variant_id` = v.`id` AND vp.`currency_code` = '{$code}'
                    WHERE v.`id` IN ({$ids}) AND v.`active` = 1 AND p.`active` = 1");
            } catch (Throwable $e) {
                continue;
            }

            $byId = array();
            foreach ($variantsRows as $variantRow) $byId[(int)$variantRow['variant_id']] = $variantRow;

            $lines = array();
            $total = 0.0;
            foreach ($cart as $variantId => $qty) {
                if (empty($byId[$variantId])) continue;
                $variantRow = $byId[$variantId];
                $price = (float)$variantRow['price'];
                if ($price <= 0) continue;
                $sum = $price * $qty;
                $total += $sum;
                $lines[] = array(
                    'variant_id' => (int)$variantId,
                    'product_id' => (int)$variantRow['product_id'],
                    'product_title' => $variantRow['product_title'],
                    'variant_title' => (string)$variantRow['variant_title'],
                    'sku' => (string)$variantRow['sku'],
                    'quantity' => (int)$qty,
                    'price' => $price,
                    'total' => $sum,
                    'url' => self::absoluteUrl(self::productUrl(array(
                        'id' => (int)$variantRow['product_id'],
                        'clean_url_title' => $variantRow['clean_url_title'],
                    ))),
                );
            }
            if (!$lines) continue;

            $name = trim((string)$row['first_name']);
            if ($name === '') $name = trim((string)$row['name']);
            $recipients[] = array(
                'user_id' => (int)$row['user_id'],
                'email' => (string)$row['email'],
                'name' => $name,
                'updated_at' => $row['updated_at'],
                'items' => $lines,
                'total' => $total,
            );
        }
        return $recipients;
    }

    public static function remindAbandonedCarts()
    {
        $recipients = self::abandonedCartRecipients();
        if (!$recipients) return true;

        $templatePath = ROOT . '/sys/settings/email_templates/';
        $code = self::activeCurrency()['code'];
        $cartUrl = self::absoluteUrl(get_url('/shop/cart/'));
        $carts = self::table('carts');

        foreach ($recipients as $recipient) {
            $itemsTable = '<table cellpadding="6" cellspacing="0" border="1" style="border-collapse:collapse;">'
                . '<tr><th>Товар</th><th>Артикул</th><th>Цена</th><th>Кол-во</th><th>Итого</th></tr>';
            foreach ($recipient['items'] as $item) {
                $itemsTable .= '<tr><td><a href="' . h($item['url']) . '">' . h($item['product_title']) . '</a>'
                    . ($item['variant_title'] !== '' ? '<br /><small>' . h($item['variant_title']) . '</small>' : '')
                    . '</td><td>' . h($item['sku']) . '</td><td>'
                    . h(self::formatPrice($item['price'], $code)) . '</td><td>'
                    . (int)$item['quantity'] . '</td><td>'
                    . h(self::formatPrice($item['total'], $code)) . '</td></tr>';
            }
            $itemsTable .= '</table>';
            $name = ($recipient['name'] !== '' ? h($recipient['name']) . ', вы' : 'Вы')
                . ' оставили товары в корзине магазина.';
            $mail = self::renderEmailTemplate('cart_reminder', array(
                'name' => $name,
                'items' => $itemsTable,
                'total' => h(self::formatPrice($recipient['total'], $code)),
                'cart_url' => h($cartUrl),
            ));

            try {
                $mailer = new AtmMail($templatePath);
                $mailer->prepare($mail['body']);
                if (@$mailer->sendMail($recipient['email'], $mail['subject'], array())) {
                    self::db()->query("UPDATE `{$carts}` SET `reminded_at` = NOW()
                        WHERE `user_id` = " . (int)$recipient['user_id']);
                }
            } catch (Throwable $e) {
                error_log('Shop cart reminder failed for user ' . (int)$recipient['user_id']);
            }
        }
        return true;
    }

    public static function cartCount()
    {
        return array_sum(self::getCart());
    }

    public static function addToCart($variantId, $qty)
    {
        $variantId = (int)$variantId;
        $qty = max(1, (int)$qty);
        if ($variantId < 1) {
            return array('ok' => false, 'message' => 'Товар не найден.');
        }

        $currency = self::activeCurrency();
        $variants = self::table('variants');
        $products = self::table('products');
        $prices = self::table('variant_prices');
        $code = self::esc($currency['code']);

        $rows = self::db()->query("SELECT v.`id`, v.`stock`, v.`active`,
                p.`id` AS product_id, p.`active` AS product_active, vp.`price`
            FROM `{$variants}` v
            INNER JOIN `{$products}` p ON p.`id` = v.`product_id`
            LEFT JOIN `{$prices}` vp ON vp.`variant_id` = v.`id` AND vp.`currency_code` = '{$code}'
            WHERE v.`id` = {$variantId}
            LIMIT 1");

        if (empty($rows[0])) {
            return array('ok' => false, 'message' => 'Товар не найден.');
        }

        $row = $rows[0];
        $price = (float)$row['price'];
        $stock = (int)$row['stock'];
        if (empty($row['active']) || empty($row['product_active'])) {
            return array('ok' => false, 'message' => 'Товар недоступен.');
        }
        if ($price <= 0) {
            return array('ok' => false, 'message' => 'Для этого товара не указана цена в активной валюте.');
        }
        if ($stock <= 0) {
            return array('ok' => false, 'message' => 'Товара нет в наличии.');
        }

        $cart = self::getCart();
        $current = isset($cart[$variantId]) ? (int)$cart[$variantId] : 0;
        $newQty = min($stock, $current + $qty);
        $cart[$variantId] = $newQty;
        self::saveCart($cart);

        $message = $newQty > $current ? 'Товар добавлен в корзину.' : 'В корзине уже максимальное доступное количество.';
        if ($newQty > $current) {
            self::recordFunnelEvent('add_to_cart', array('product_id' => (int)$row['product_id']));
        }
        return array('ok' => true, 'message' => $message, 'quantity' => $newQty);
    }

    public static function updateCart($quantities)
    {
        $cart = self::getCart();
        if (!is_array($quantities)) $quantities = array();

        foreach ($cart as $variantId => $oldQty) {
            if (!array_key_exists($variantId, $quantities)) continue;
            $qty = (int)$quantities[$variantId];
            if ($qty <= 0) {
                unset($cart[$variantId]);
                continue;
            }

            $variants = self::table('variants');
            $products = self::table('products');
            $prices = self::table('variant_prices');
            $code = self::esc(self::activeCurrency()['code']);
            $rows = self::db()->query("SELECT v.`stock`, v.`active`, p.`active` AS product_active, vp.`price`
                FROM `{$variants}` v
                INNER JOIN `{$products}` p ON p.`id` = v.`product_id`
                LEFT JOIN `{$prices}` vp ON vp.`variant_id` = v.`id` AND vp.`currency_code` = '{$code}'
                WHERE v.`id` = " . (int)$variantId . " LIMIT 1");
            if (empty($rows[0]) || empty($rows[0]['active']) || empty($rows[0]['product_active'])
                || (float)$rows[0]['price'] <= 0 || (int)$rows[0]['stock'] <= 0) {
                unset($cart[$variantId]);
                continue;
            }
            $cart[$variantId] = min((int)$rows[0]['stock'], $qty);
        }

        self::saveCart($cart);
    }

    public static function removeFromCart($variantId)
    {
        $variantId = (int)$variantId;
        $cart = self::getCart();
        if (isset($cart[$variantId])) unset($cart[$variantId]);
        self::saveCart($cart);
    }

    public static function cartItems($currencyCode = null, $prune = true)
    {
        $cart = self::getCart();
        if (empty($cart)) return array();

        $currency = $currencyCode ? self::currency($currencyCode) : self::activeCurrency();
        $code = self::esc($currency['code']);
        $ids = implode(',', array_map('intval', array_keys($cart)));

        $variants = self::table('variants');
        $products = self::table('products');
        $prices = self::table('variant_prices');

        $rows = self::db()->query("SELECT v.`id` AS variant_id, v.`product_id`, v.`title` AS variant_title,
                v.`sku`, v.`stock`, v.`image` AS variant_image, v.`active` AS variant_active,
                p.`title` AS product_title, p.`clean_url_title`, p.`category_id`,
                COALESCE(NULLIF(p.`image`, ''), (
                    SELECT pi.`image` FROM `" . self::table('product_images') . "` pi
                    WHERE pi.`product_id` = p.`id` ORDER BY pi.`position` ASC, pi.`id` ASC LIMIT 1
                )) AS product_image,
                p.`active` AS product_active, vp.`price` AS price
            FROM `{$variants}` v
            INNER JOIN `{$products}` p ON p.`id` = v.`product_id`
            LEFT JOIN `{$prices}` vp ON vp.`variant_id` = v.`id` AND vp.`currency_code` = '{$code}'
            WHERE v.`id` IN ({$ids})");

        $byId = array();
        foreach ($rows as $row) $byId[(int)$row['variant_id']] = $row;

        $items = array();
        $changed = false;
        foreach ($cart as $variantId => $qty) {
            $row = isset($byId[$variantId]) ? $byId[$variantId] : null;
            if (empty($row) || empty($row['variant_active']) || empty($row['product_active'])) {
                unset($cart[$variantId]);
                $changed = true;
                continue;
            }

            $price = (float)$row['price'];
            $stock = (int)$row['stock'];
            if ($price <= 0 || $stock <= 0) {
                unset($cart[$variantId]);
                $changed = true;
                continue;
            }

            $qty = min((int)$qty, $stock);
            if ($qty < 1) {
                unset($cart[$variantId]);
                $changed = true;
                continue;
            }
            if ($qty !== (int)$cart[$variantId]) {
                $cart[$variantId] = $qty;
                $changed = true;
            }

            $image = !empty($row['variant_image']) ? $row['variant_image'] : $row['product_image'];
            $items[$variantId] = array(
                'variant_id' => (int)$variantId,
                'product_id' => (int)$row['product_id'],
                'category_id' => (int)$row['category_id'],
                'product_title' => $row['product_title'],
                'variant_title' => !empty($row['variant_title']) ? $row['variant_title'] : 'Вариант #' . $variantId,
                'sku' => $row['sku'],
                'price' => $price,
                'price_formatted' => self::formatPrice($price, $currency['code']),
                'quantity' => $qty,
                'stock' => $stock,
                'total' => $price * $qty,
                'total_formatted' => self::formatPrice($price * $qty, $currency['code']),
                'url' => self::productUrl($row),
                'image_url' => self::imageUrl($image, true),
            );
        }

        if ($changed && $prune) self::saveCart($cart);

        return $items;
    }

    public static function cartTotals($items = null, $currencyCode = null)
    {
        if ($items === null) $items = self::cartItems();
        $currency = $currencyCode ? self::currency($currencyCode) : self::activeCurrency();
        $summary = ShopCartCalculator::summarize($items);
        $subtotal = $summary['subtotal'];
        $quantity = $summary['quantity'];

        $promoCode = self::promoCode();
        $promoError = '';
        $discount = 0.0;
        if ($promoCode !== '') {
            $promo = self::promoCheck($promoCode, $subtotal, $currency['code'], false, '', 0, $items);
            if ($promo['valid']) {
                $discount = $promo['discount'];
            } else {
                $promoError = $promo['error'];
            }
        }

        return array(
            'subtotal' => $subtotal,
            'subtotal_formatted' => self::formatPrice($subtotal, $currency['code']),
            'quantity' => $quantity,
            'promo_code' => $promoCode,
            'promo_error' => $promoError,
            'discount' => $discount,
            'discount_formatted' => self::formatPrice($discount, $currency['code']),
            'total' => $subtotal - $discount,
            'total_formatted' => self::formatPrice($subtotal - $discount, $currency['code']),
            'currency_code' => $currency['code'],
        );
    }

    /* ---------------------------------------------------------------------
     * Currency, payment and delivery helpers
     * ------------------------------------------------------------------ */

    public static function paymentMethods()
    {
        return ShopPaymentMethods::all((string)self::cfg('payment_methods', ''));
    }

    /**
     * @param float|null $subtotal cart goods total; enables the free delivery
     *                             threshold when the courier method is shown
     */
    public static function deliveryMethods($currencyCode = null, $subtotal = null)
    {
        return ShopDeliveryMethods::all($currencyCode, $subtotal);
    }

    public static function deliveryPrice($method, $currencyCode = null, $subtotal = null)
    {
        $methods = self::deliveryMethods($currencyCode, $subtotal);
        return isset($methods[$method]) ? (float)$methods[$method]['price'] : 0.0;
    }

    /**
     * One-line hint above the cart summary, empty when there is no threshold.
     */
    public static function freeDeliveryHint($subtotal, $currencyCode = null)
    {
        return ShopCheckoutRules::freeDeliveryHint($subtotal, $currencyCode);
    }

    /**
     * Per-currency minimum order total. 0 disables the check.
     */
    public static function minOrderTotal($currencyCode = null)
    {
        return ShopCheckoutRules::minOrderTotal($currencyCode);
    }

    public static function minOrderShortfall($subtotal, $currencyCode = null)
    {
        return ShopCheckoutRules::minOrderShortfall($subtotal, $currencyCode);
    }

    /**
     * One-line hint above the cart summary, empty when the minimum is met.
     */
    public static function minOrderHint($subtotal, $currencyCode = null)
    {
        return ShopCheckoutRules::minOrderHint($subtotal, $currencyCode);
    }

    /**
     * A customer may cancel only an order that is not paid or shipped yet.
     */
    public static function customerCanCancel($order)
    {
        return ShopOrderStatus::customerCanCancel($order);
    }

    public static function statuses()
    {
        return ShopOrderStatus::all();
    }

    public static function statusLabel($status)
    {
        return ShopOrderStatus::label($status);
    }

    /* ---------------------------------------------------------------------
     * Catalogue
     * ------------------------------------------------------------------ */

    public static function getProduct($idOrSlug, $activeOnly = true)
    {
        $products = self::table('products');
        $categories = self::table('categories');
        $where = '';
        if (is_numeric($idOrSlug)) {
            $where = 'p.`id` = ' . (int)$idOrSlug;
        } else {
            $where = "p.`clean_url_title` = '" . self::esc((string)$idOrSlug) . "'";
        }
        if ($activeOnly) $where .= ' AND p.`active` = 1';

        $rows = self::db()->query("SELECT p.*, c.`title` AS category_title, c.`clean_url_title` AS category_slug
            FROM `{$products}` p
            LEFT JOIN `{$categories}` c ON c.`id` = p.`category_id`
            WHERE {$where}
            LIMIT 1");
        if (empty($rows[0])) return false;

        $product = $rows[0];
        $product['id'] = (int)$product['id'];
        $product['category_id'] = (int)$product['category_id'];
        $product['url'] = self::productUrl($product);
        $product['image_url'] = self::imageUrl($product['image'], false);
        $product['category_url'] = $product['category_id'] > 0
            ? self::categoryUrl(array('id' => $product['category_id'], 'title' => $product['category_title']))
            : '';
        $product['description_html'] = self::formatDescription($product['description']);
        $product['short_description_html'] = nl2br(h((string)$product['short_description']));
        self::ensurePromoTables();
        $price = self::productMinPriceInfo($product['id']);
        $product['price_from'] = $price['price'];
        $product['price_from_formatted'] = $price['price'] > 0
            ? self::formatPrice($price['price']) : '';
        $product['price_from_compare'] = $price['compare'];
        $product['price_from_compare_formatted'] = $price['compare'] > 0
            ? self::formatPrice($price['compare']) : '';
        $product['price_discount_percent'] = $price['percent'];
        return $product;
    }

    public static function getProductImages($productId, $preview = true)
    {
        $productId = (int)$productId;
        if ($productId < 1) return array();

        $images = self::table('product_images');
        $rows = self::db()->query("SELECT * FROM `{$images}`
            WHERE `product_id` = {$productId}
            ORDER BY `position` ASC, `id` ASC");
        foreach ($rows as &$row) {
            $row['id'] = (int)$row['id'];
            $row['product_id'] = (int)$row['product_id'];
            $row['position'] = (int)$row['position'];
            $row['image_url'] = self::imageUrl($row['image'], $preview);
            $row['full_url'] = self::imageUrl($row['image'], false);
        }
        unset($row);
        return $rows;
    }

    private static function minPriceSql($code)
    {
        return "(SELECT MIN(vp.`price`) FROM `" . self::table('variants') . "` v
            INNER JOIN `" . self::table('variant_prices') . "` vp ON vp.`variant_id` = v.`id`
            WHERE v.`product_id` = p.`id` AND v.`active` = 1
                AND vp.`currency_code` = '{$code}' AND vp.`price` > 0)";
    }

    /**
     * WHERE conditions shared by the catalogue listing and the facet counts.
     *
     * @return array SQL condition strings, alias p for the product table
     */
    private static function productFilterWhere($filters, $minSql, $pav, $attributeValues)
    {
        $where = array();
        if (!isset($filters['active_only']) || !empty($filters['active_only'])) {
            $where[] = 'p.`active` = 1';
        }
        if (!empty($filters['ids'])) {
            $ids = array_values(array_unique(array_filter(array_map('intval', (array)$filters['ids']))));
            $where[] = $ids ? 'p.`id` IN (' . implode(',', $ids) . ')' : '1 = 0';
        }
        if (!empty($filters['category_ids'])) {
            $ids = array_map('intval', (array)$filters['category_ids']);
            $ids = array_filter($ids);
            if ($ids) $where[] = 'p.`category_id` IN (' . implode(',', $ids) . ')';
        }
        if (!empty($filters['attribute_value_ids'])) {
            $groups = self::getAttributeValueGroupsByIds($filters['attribute_value_ids']);
            foreach ($groups as $attributeId => $valueIds) {
                if (empty($valueIds)) continue;
                $where[] = "EXISTS (
                    SELECT 1 FROM `{$pav}` pav
                    INNER JOIN `{$attributeValues}` av ON av.`id` = pav.`attribute_value_id`
                    WHERE pav.`product_id` = p.`id`
                        AND av.`attribute_id` = " . (int)$attributeId . "
                        AND pav.`attribute_value_id` IN (" . implode(',', array_map('intval', $valueIds)) . ")
                )";
            }
        }
        if (!empty($filters['q'])) {
            $q = self::esc(trim((string)$filters['q']));
            if ($q !== '') {
                $variants = self::table('variants');
                $where[] = "(p.`title` LIKE '%{$q}%' OR p.`short_description` LIKE '%{$q}%'
                    OR p.`description` LIKE '%{$q}%'
                    OR EXISTS (SELECT 1 FROM `{$variants}` vs
                        WHERE vs.`product_id` = p.`id` AND vs.`sku` LIKE '%{$q}%'))";
            }
        }
        if (!empty($filters['featured'])) {
            $where[] = 'p.`featured` = 1';
        }
        $priceMin = isset($filters['price_min']) ? (float)$filters['price_min'] : 0.0;
        $priceMax = isset($filters['price_max']) ? (float)$filters['price_max'] : 0.0;
        if ($priceMin > 0) $where[] = "{$minSql} >= " . number_format($priceMin, 2, '.', '');
        if ($priceMax > 0) $where[] = "{$minSql} <= " . number_format($priceMax, 2, '.', '');
        if (!empty($filters['in_stock'])) {
            $where[] = "EXISTS (SELECT 1 FROM `" . self::table('variants') . "` v2
                WHERE v2.`product_id` = p.`id` AND v2.`active` = 1 AND v2.`stock` > 0)";
        }
        return $where;
    }

    public static function getProducts($filters = array())
    {
        self::ensurePromoTables();
        $products = self::table('products');
        $categories = self::table('categories');
        $pav = self::table('product_attribute_values');
        $attributeValues = self::table('attribute_values');
        $currency = isset($filters['currency']) ? self::currency($filters['currency']) : self::activeCurrency();
        $code = self::esc($currency['code']);
        $variants = self::table('variants');

        $minSql = self::minPriceSql($code);
        $compareSql = "(SELECT NULLIF(vp.`compare_price`, 0) FROM `" . self::table('variants') . "` v
            INNER JOIN `" . self::table('variant_prices') . "` vp ON vp.`variant_id` = v.`id`
            WHERE v.`product_id` = p.`id` AND v.`active` = 1
                AND vp.`currency_code` = '{$code}' AND vp.`price` > 0
            ORDER BY vp.`price` ASC, v.`id` ASC LIMIT 1)";
        $gallerySql = "(SELECT pi.`image` FROM `" . self::table('product_images') . "` pi
            WHERE pi.`product_id` = p.`id` ORDER BY pi.`position` ASC, pi.`id` ASC LIMIT 1)";

        $where = self::productFilterWhere($filters, $minSql, $pav, $attributeValues);
        $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

        $page = max(1, (int)(isset($filters['page']) ? $filters['page'] : 1));
        $limit = (int)(isset($filters['limit']) ? $filters['limit'] : self::cfg('per_page', 12));
        if ($limit < 1) $limit = 12;
        if ($limit > 100) $limit = 100;

        $sort = isset($filters['sort']) ? (string)$filters['sort'] : 'default';
        $orderSql = 'p.`position` ASC, p.`id` DESC';
        if ($sort === 'price_asc') $orderSql = 'min_price IS NULL ASC, min_price ASC, p.`id` DESC';
        if ($sort === 'price_desc') $orderSql = 'min_price IS NULL ASC, min_price DESC, p.`id` DESC';
        if ($sort === 'new') $orderSql = 'p.`created_at` DESC, p.`id` DESC';
        if ($sort === 'title') $orderSql = 'p.`title` ASC, p.`id` DESC';

        $countRows = self::db()->query("SELECT COUNT(*) AS cnt FROM `{$products}` p" . $whereSql);
        $total = !empty($countRows[0]['cnt']) ? (int)$countRows[0]['cnt'] : 0;

        $pages = (int)ceil($total / $limit);
        $rows = array();
        if ($page <= $pages) {
            $offset = ($page - 1) * $limit;
            $sortByPrice = $sort === 'price_asc' || $sort === 'price_desc';
            $innerPrice = $sortByPrice ? ", {$minSql} AS min_price" : '';
            $outerPrice = ($sortByPrice ? '' : ", {$minSql} AS min_price")
                . ", {$compareSql} AS min_compare_price";
            $rows = self::db()->query("SELECT p.*, c.`title` AS category_title, c.`clean_url_title` AS category_slug
                    {$outerPrice}, {$gallerySql} AS gallery_image
                FROM (
                    SELECT p.*{$innerPrice} FROM `{$products}` p
                    {$whereSql}
                    ORDER BY {$orderSql}
                    LIMIT {$limit} OFFSET {$offset}
                ) p
                LEFT JOIN `{$categories}` c ON c.`id` = p.`category_id`
                ORDER BY {$orderSql}");
        }

        $items = array();
        foreach ($rows as $row) {
            $row['id'] = (int)$row['id'];
            $row['category_id'] = (int)$row['category_id'];
            $row['url'] = self::productUrl($row);
            $image = !empty($row['image']) ? $row['image'] : $row['gallery_image'];
            $row['image_url'] = self::imageUrl($image, true);
            $row['category_url'] = $row['category_id'] > 0
                ? self::categoryUrl(array('id' => $row['category_id'], 'title' => $row['category_title']))
                : '';
            $minPrice = isset($row['min_price']) ? (float)$row['min_price'] : 0.0;
            $row['price_from'] = $minPrice;
            $row['price_from_formatted'] = $minPrice > 0 ? self::formatPrice($minPrice, $currency['code']) : '';
            $comparePrice = isset($row['min_compare_price']) ? (float)$row['min_compare_price'] : 0.0;
            if ($minPrice <= 0 || $comparePrice <= $minPrice) $comparePrice = 0.0;
            $row['price_from_compare'] = $comparePrice;
            $row['price_from_compare_formatted'] = $comparePrice > 0
                ? self::formatPrice($comparePrice, $currency['code']) : '';
            $row['price_discount_percent'] = $comparePrice > 0
                ? (int)round(100 - ($minPrice / $comparePrice * 100)) : 0;
            $row['short_description_html'] = nl2br(h((string)$row['short_description']));
            $items[] = $row;
        }

        return array(
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'limit' => $limit,
            'sort' => $sort,
        );
    }

    public static function productMinPrice($productId, $currencyCode = null)
    {
        $info = self::productMinPriceInfo($productId, $currencyCode);
        return $info['price'];
    }

    /**
     * Lowest active price for a product and, when the seller set an old price
     * on that same variant, the old price and the visible discount percent.
     */
    public static function productMinPriceInfo($productId, $currencyCode = null)
    {
        self::ensurePromoTables();
        $currency = $currencyCode ? self::currency($currencyCode) : self::activeCurrency();
        $code = self::esc($currency['code']);
        $variants = self::table('variants');
        $prices = self::table('variant_prices');
        $rows = self::db()->query("SELECT vp.`price`, vp.`compare_price`
            FROM `{$variants}` v
            INNER JOIN `{$prices}` vp ON vp.`variant_id` = v.`id`
            WHERE v.`product_id` = " . (int)$productId . "
                AND v.`active` = 1 AND vp.`currency_code` = '{$code}' AND vp.`price` > 0
            ORDER BY vp.`price` ASC, v.`id` ASC LIMIT 1");
        $price = !empty($rows[0]['price']) ? (float)$rows[0]['price'] : 0.0;
        $compare = !empty($rows[0]['compare_price']) ? (float)$rows[0]['compare_price'] : 0.0;
        if ($price <= 0 || $compare <= $price) $compare = 0.0;
        return array(
            'price' => $price,
            'compare' => $compare,
            'percent' => $compare > 0 ? (int)round(100 - ($price / $compare * 100)) : 0,
        );
    }

    public static function getProductVariants($productId, $currencyCode = null, $onlyAvailable = false)
    {
        $productId = (int)$productId;
        if ($productId < 1) return array();

        self::ensurePromoTables();
        $currency = $currencyCode ? self::currency($currencyCode) : self::activeCurrency();
        $code = self::esc($currency['code']);
        $variants = self::table('variants');
        $products = self::table('products');
        $prices = self::table('variant_prices');

        $rows = self::db()->query("SELECT v.*,
                COALESCE(NULLIF(p.`image`, ''), (
                    SELECT pi.`image` FROM `" . self::table('product_images') . "` pi
                    WHERE pi.`product_id` = p.`id` ORDER BY pi.`position` ASC, pi.`id` ASC LIMIT 1
                )) AS product_image,
                vp.`price` AS price, vp.`compare_price` AS compare_price
            FROM `{$variants}` v
            INNER JOIN `{$products}` p ON p.`id` = v.`product_id`
            LEFT JOIN `{$prices}` vp ON vp.`variant_id` = v.`id` AND vp.`currency_code` = '{$code}'
            WHERE v.`product_id` = {$productId} AND v.`active` = 1
            ORDER BY v.`position` ASC, v.`id` ASC");
        if (empty($rows)) return array();

        $ids = array();
        foreach ($rows as $row) $ids[] = (int)$row['id'];

        $labels = array();
        $valuesByVariant = array();
        $valueRows = self::db()->query("SELECT vv.`variant_id`, av.`title` AS value_title, a.`title` AS attr_title,
                a.`position` AS attr_position, av.`position` AS value_position
            FROM `" . self::table('variant_values') . "` vv
            INNER JOIN `" . self::table('attribute_values') . "` av ON av.`id` = vv.`attribute_value_id`
            INNER JOIN `" . self::table('attributes') . "` a ON a.`id` = av.`attribute_id`
            WHERE vv.`variant_id` IN (" . implode(',', $ids) . ")
            ORDER BY a.`position` ASC, a.`id` ASC, av.`position` ASC, av.`id` ASC");
        foreach ($valueRows as $valueRow) {
            $vid = (int)$valueRow['variant_id'];
            $labels[$vid][] = $valueRow['value_title'];
            $valuesByVariant[$vid][] = array(
                'attribute' => $valueRow['attr_title'],
                'value' => $valueRow['value_title'],
            );
        }

        $result = array();
        foreach ($rows as $row) {
            $vid = (int)$row['id'];
            $price = (float)$row['price'];
            $stock = (int)$row['stock'];
            $title = trim((string)$row['title']);
            if ($title === '') {
                $title = !empty($labels[$vid]) ? implode(' / ', $labels[$vid]) : 'Вариант #' . $vid;
            }
            $available = $price > 0 && $stock > 0;
            if ($onlyAvailable && !$available) continue;

            $image = !empty($row['image']) ? $row['image'] : $row['product_image'];
            $comparePrice = (float)$row['compare_price'];
            if ($price <= 0 || $comparePrice <= $price) $comparePrice = 0.0;
            $result[] = array(
                'id' => $vid,
                'product_id' => (int)$row['product_id'],
                'title' => $title,
                'sku' => $row['sku'],
                'stock' => $stock,
                'price' => $price,
                'price_formatted' => $price > 0 ? self::formatPrice($price, $currency['code']) : '',
                'compare_price' => $comparePrice,
                'compare_price_formatted' => $comparePrice > 0
                    ? self::formatPrice($comparePrice, $currency['code']) : '',
                'discount_percent' => $comparePrice > 0
                    ? (int)round(100 - ($price / $comparePrice * 100)) : 0,
                'available' => $available,
                'image_url' => self::imageUrl($image, false),
                'attributes' => isset($valuesByVariant[$vid]) ? $valuesByVariant[$vid] : array(),
            );
        }
        return $result;
    }

    public static function getCategories($activeOnly = true)
    {
        $categories = self::table('categories');
        $where = $activeOnly ? ' WHERE `active` = 1' : '';
        $rows = self::db()->query("SELECT * FROM `{$categories}`{$where} ORDER BY `position` ASC, `id` ASC");
        foreach ($rows as &$row) {
            $row['id'] = (int)$row['id'];
            $row['parent_id'] = (int)$row['parent_id'];
            $row['url'] = self::categoryUrl($row);
            $row['description_html'] = self::formatDescription($row['description']);
            $row['image_url'] = self::imageUrl($row['image'], true);
        }
        unset($row);
        return $rows;
    }

    public static function getCategory($id)
    {
        $categories = self::table('categories');
        $rows = self::db()->query("SELECT * FROM `{$categories}` WHERE `id` = " . (int)$id . " LIMIT 1");
        if (empty($rows[0])) return false;
        $rows[0]['id'] = (int)$rows[0]['id'];
        $rows[0]['parent_id'] = (int)$rows[0]['parent_id'];
        $rows[0]['url'] = self::categoryUrl($rows[0]);
        $rows[0]['description_html'] = self::formatDescription($rows[0]['description']);
        return $rows[0];
    }

    public static function categoryTree($categories = null, $parentId = 0)
    {
        if ($categories === null) $categories = self::getCategories(true);
        return ShopCategoryTree::build($categories, $parentId);
    }

    public static function categoryTreeHtml($tree, $currentId = 0, $level = 0)
    {
        return ShopCategoryTree::html($tree, $currentId, $level);
    }

    public static function categoryDescendantIds($categoryId)
    {
        return ShopCategoryTree::descendantIds(self::getCategories(false), $categoryId);
    }

    public static function categoryBreadcrumbs($categoryId)
    {
        return ShopCategoryTree::breadcrumbs(self::getCategories(false), $categoryId);
    }

    public static function productUrl($product)
    {
        if (is_object($product)) {
            $id = (int)$product->getId();
            $slug = (string)$product->getClean_url_title();
        } else {
            $id = (int)(isset($product['id']) ? $product['id'] : (isset($product['product_id']) ? $product['product_id'] : 0));
            $slug = (string)(isset($product['clean_url_title']) ? $product['clean_url_title'] : '');
        }

        if (Config::read('hlu') == 1 && $slug !== '') {
            $ext = (string)Config::read('hlu_extention');
            return get_url('/shop/' . $slug . $ext);
        }
        return get_url('/shop/view/' . $id . '/');
    }

    public static function categoryUrl($category)
    {
        $id = is_object($category) ? (int)$category->getId() : (int)$category['id'];
        return get_url('/shop/category/' . $id . '/');
    }

    /* ---------------------------------------------------------------------
     * Landing pages
     * ------------------------------------------------------------------ */

    public static function getLandingPages($activeOnly = true, $categoryId = 0)
    {
        $table = self::table('landing_pages');
        $links = self::table('landing_page_categories');
        $values = self::table('landing_page_attribute_values');
        $where = array();
        if ($activeOnly) $where[] = 'lp.`active` = 1';

        $categoryId = (int)$categoryId;
        if ($categoryId > 0) {
            $where[] = "EXISTS (
                SELECT 1 FROM `{$links}` lpc_filter
                WHERE lpc_filter.`landing_page_id` = lp.`id`
                    AND lpc_filter.`category_id` = {$categoryId}
            )";
        }

        $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $rows = self::db()->query("SELECT lp.*,
                (SELECT COUNT(*) FROM `{$links}` lpc_count
                    WHERE lpc_count.`landing_page_id` = lp.`id`) AS categories_count,
                (SELECT COUNT(*) FROM `{$values}` lpav_count
                    WHERE lpav_count.`landing_page_id` = lp.`id`) AS values_count
            FROM `{$table}` lp
            {$whereSql}
            ORDER BY lp.`position` ASC, lp.`id` ASC");

        foreach ($rows as &$row) {
            $row['id'] = (int)$row['id'];
            $row['active'] = (int)$row['active'];
            $row['position'] = (int)$row['position'];
            $row['categories_count'] = (int)$row['categories_count'];
            $row['values_count'] = (int)$row['values_count'];
            $row['url'] = self::landingUrl($row);
        }
        unset($row);
        return $rows;
    }

    public static function getLandingPage($idOrSlug, $activeOnly = true)
    {
        $table = self::table('landing_pages');
        if (is_numeric($idOrSlug)) {
            $where = '`id` = ' . (int)$idOrSlug;
        } else {
            $where = "`clean_url_title` = '" . self::esc((string)$idOrSlug) . "'";
        }
        if ($activeOnly) $where .= ' AND `active` = 1';

        $rows = self::db()->query("SELECT * FROM `{$table}` WHERE {$where} LIMIT 1");
        if (empty($rows[0])) return false;

        $landing = $rows[0];
        $landing['id'] = (int)$landing['id'];
        $landing['active'] = (int)$landing['active'];
        $landing['position'] = (int)$landing['position'];
        $landing['categories'] = self::getLandingPageCategories($landing['id'], $activeOnly);
        $landing['category_ids'] = array();
        foreach ($landing['categories'] as $category) {
            $landing['category_ids'][] = (int)$category['id'];
        }
        $landing['attribute_groups'] = self::getLandingPageAttributeGroups($landing['id'], false);
        $landing['attribute_value_ids'] = array();
        foreach ($landing['attribute_groups'] as $group) {
            foreach ($group['values'] as $value) {
                $landing['attribute_value_ids'][] = (int)$value['id'];
            }
        }
        $landing['url'] = self::landingUrl($landing);
        $landing['description_html'] = self::formatDescription($landing['description']);
        return $landing;
    }

    public static function getLandingPageCategories($landingId, $activeOnly = false)
    {
        $landingId = (int)$landingId;
        if ($landingId < 1) return array();

        $links = self::table('landing_page_categories');
        $categories = self::table('categories');
        $where = "l.`landing_page_id` = {$landingId}";
        if ($activeOnly) $where .= ' AND c.`active` = 1';
        $rows = self::db()->query("SELECT c.* FROM `{$links}` l
            INNER JOIN `{$categories}` c ON c.`id` = l.`category_id`
            WHERE {$where}
            ORDER BY c.`position` ASC, c.`id` ASC");
        foreach ($rows as &$row) {
            $row['id'] = (int)$row['id'];
            $row['parent_id'] = (int)$row['parent_id'];
            $row['url'] = self::categoryUrl($row);
        }
        unset($row);
        return $rows;
    }

    public static function getLandingPageAttributeGroups($landingId, $activeOnly = false)
    {
        $landingId = (int)$landingId;
        if ($landingId < 1) return array();

        $links = self::table('landing_page_attribute_values');
        $values = self::table('attribute_values');
        $attributes = self::table('attributes');
        $where = "l.`landing_page_id` = {$landingId}";
        if ($activeOnly) $where .= ' AND av.`active` = 1 AND a.`active` = 1';
        $rows = self::db()->query("SELECT av.`id` AS value_id, av.`attribute_id`,
                av.`title` AS value_title, a.`title` AS attribute_title,
                a.`position` AS attribute_position, av.`position` AS value_position
            FROM `{$links}` l
            INNER JOIN `{$values}` av ON av.`id` = l.`attribute_value_id`
            INNER JOIN `{$attributes}` a ON a.`id` = av.`attribute_id`
            WHERE {$where}
            ORDER BY a.`position` ASC, a.`id` ASC, av.`position` ASC, av.`id` ASC");

        $groups = array();
        foreach ($rows as $row) {
            $attributeId = (int)$row['attribute_id'];
            if (!isset($groups[$attributeId])) {
                $groups[$attributeId] = array(
                    'id' => $attributeId,
                    'title' => $row['attribute_title'],
                    'values' => array(),
                );
            }
            $groups[$attributeId]['values'][] = array(
                'id' => (int)$row['value_id'],
                'title' => $row['value_title'],
            );
        }
        return $groups;
    }

    public static function getLandingPagesForCategory($categoryId, $activeOnly = true)
    {
        $categoryId = (int)$categoryId;
        if ($categoryId < 1) return array();

        $links = self::table('landing_page_categories');
        $table = self::table('landing_pages');
        $where = "l.`category_id` = {$categoryId}";
        if ($activeOnly) $where .= ' AND lp.`active` = 1';
        $rows = self::db()->query("SELECT lp.* FROM `{$links}` l
            INNER JOIN `{$table}` lp ON lp.`id` = l.`landing_page_id`
            WHERE {$where}
            ORDER BY lp.`position` ASC, lp.`id` ASC");

        foreach ($rows as &$row) {
            $row['id'] = (int)$row['id'];
            $row['active'] = (int)$row['active'];
            $row['position'] = (int)$row['position'];
            $row['url'] = self::landingUrl($row);
        }
        unset($row);
        return $rows;
    }

    public static function landingUrl($landing)
    {
        $path = self::landingPath($landing);
        return get_url($path);
    }

    public static function landingPath($landing)
    {
        if (is_object($landing)) {
            $id = (int)$landing->getId();
            $slug = (string)$landing->getClean_url_title();
        } else {
            $id = (int)(isset($landing['id']) ? $landing['id'] : 0);
            $slug = (string)(isset($landing['clean_url_title']) ? $landing['clean_url_title'] : '');
        }
        return '/shop/landing/' . ($slug !== '' ? $slug : $id) . '/';
    }

    public static function syncLandingPageCategories($landingId, $categoryIds)
    {
        $landingId = (int)$landingId;
        if ($landingId < 1) return;

        $table = self::table('landing_page_categories');
        self::db()->delete($table, array('landing_page_id' => $landingId));
        $categoryIds = array_values(array_unique(array_filter(array_map('intval', (array)$categoryIds))));
        foreach ($categoryIds as $categoryId) {
            if ($categoryId < 1) continue;
            self::db()->save('shop_landing_page_categories', array(
                'landing_page_id' => $landingId,
                'category_id' => $categoryId,
            ));
        }
    }

    public static function syncLandingPageAttributeValues($landingId, $valueIds)
    {
        $landingId = (int)$landingId;
        if ($landingId < 1) return;

        $table = self::table('landing_page_attribute_values');
        self::db()->delete($table, array('landing_page_id' => $landingId));
        $valueIds = array_values(array_unique(array_filter(array_map('intval', (array)$valueIds))));
        foreach ($valueIds as $valueId) {
            if ($valueId < 1) continue;
            self::db()->save('shop_landing_page_attribute_values', array(
                'landing_page_id' => $landingId,
                'attribute_value_id' => $valueId,
            ));
        }
    }

    public static function imageUrl($filename, $preview = true)
    {
        $filename = trim((string)$filename);
        if ($filename === '') return '';
        if (preg_match('#^https?://#i', $filename)) return $filename;
        $filename = basename($filename);
        $folder = $preview ? 'preview' : 'full';
        // sys/files is closed by .htaccess on Apache, so images go through the
        // shared image endpoint which also negotiates WebP.
        return get_url('/img.php?src=' . rawurlencode('/sys/files/shop/' . $folder . '/' . $filename), false, false);
    }

    public static function slugify($text)
    {
        $text = trim(strip_tags((string)$text));
        if ($text === '') return 'item';
        $text = mb_strtolower($text, 'UTF-8');
        $map = array(
            'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e', 'ё' => 'e',
            'ж' => 'zh', 'з' => 'z', 'и' => 'i', 'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm',
            'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u',
            'ф' => 'f', 'х' => 'h', 'ц' => 'c', 'ч' => 'ch', 'ш' => 'sh', 'щ' => 'sch', 'ъ' => '',
            'ы' => 'y', 'ь' => '', 'э' => 'e', 'ю' => 'yu', 'я' => 'ya',
        );
        $text = strtr($text, $map);
        $text = preg_replace('/[^a-z0-9]+/u', '-', $text);
        $text = trim($text, '-');
        return $text !== '' ? $text : 'item';
    }

    public static function uniqueSlug($table, $title, $id = 0)
    {
        $base = self::slugify($title);
        $slug = $base;
        $counter = 2;
        $fullTable = self::table($table);
        $id = (int)$id;
        while (true) {
            $escaped = self::esc($slug);
            $sql = "SELECT `id` FROM `{$fullTable}` WHERE `clean_url_title` = '{$escaped}'";
            if ($id > 0) $sql .= " AND `id` <> {$id}";
            $sql .= ' LIMIT 1';
            $rows = self::db()->query($sql);
            if (empty($rows)) break;
            $slug = $base . '-' . $counter;
            $counter++;
        }
        return $slug;
    }

    /* ---------------------------------------------------------------------
     * Product attributes and generated variants
     * ------------------------------------------------------------------ */

    public static function getAttributeValueGroupsByIds($valueIds)
    {
        $valueIds = array_values(array_unique(array_filter(array_map('intval', (array)$valueIds))));
        if (!$valueIds) return array();

        $values = self::table('attribute_values');
        $rows = self::db()->query("SELECT `id`, `attribute_id` FROM `{$values}`
            WHERE `id` IN (" . implode(',', $valueIds) . ")");
        $groups = array();
        foreach ($rows as $row) {
            $attributeId = (int)$row['attribute_id'];
            if (!isset($groups[$attributeId])) $groups[$attributeId] = array();
            $groups[$attributeId][] = (int)$row['id'];
        }
        return $groups;
    }

    public static function getAttributes($activeOnly = false)
    {
        $table = self::table('attributes');
        $where = $activeOnly ? ' WHERE `active` = 1' : '';
        return self::db()->query("SELECT * FROM `{$table}`{$where} ORDER BY `position` ASC, `id` ASC");
    }

    public static function getAttributeValues($attributeId = null, $activeOnly = false)
    {
        $table = self::table('attribute_values');
        $where = array();
        if ($attributeId !== null) $where[] = '`attribute_id` = ' . (int)$attributeId;
        if ($activeOnly) $where[] = '`active` = 1';
        $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        return self::db()->query("SELECT * FROM `{$table}`{$whereSql} ORDER BY `position` ASC, `id` ASC");
    }

    public static function getProductAttributeValueIds($productId)
    {
        $table = self::table('product_attribute_values');
        $rows = self::db()->query("SELECT `attribute_value_id` FROM `{$table}`
            WHERE `product_id` = " . (int)$productId);
        $ids = array();
        foreach ($rows as $row) $ids[] = (int)$row['attribute_value_id'];
        return $ids;
    }

    public static function getProductAttributeGroups($productId)
    {
        $pav = self::table('product_attribute_values');
        $values = self::table('attribute_values');
        $attributes = self::table('attributes');
        $rows = self::db()->query("SELECT av.`id` AS value_id, av.`attribute_id`, av.`title` AS value_title,
                a.`title` AS attribute_title, a.`position` AS attribute_position,
                av.`position` AS value_position
            FROM `{$pav}` pav
            INNER JOIN `{$values}` av ON av.`id` = pav.`attribute_value_id`
            INNER JOIN `{$attributes}` a ON a.`id` = av.`attribute_id`
            WHERE pav.`product_id` = " . (int)$productId . "
                AND av.`active` = 1 AND a.`active` = 1
            ORDER BY a.`position` ASC, a.`id` ASC, av.`position` ASC, av.`id` ASC");

        $groups = array();
        foreach ($rows as $row) {
            $attributeId = (int)$row['attribute_id'];
            if (!isset($groups[$attributeId])) {
                $groups[$attributeId] = array(
                    'id' => $attributeId,
                    'title' => $row['attribute_title'],
                    'values' => array(),
                );
            }
            $groups[$attributeId]['values'][] = array(
                'id' => (int)$row['value_id'],
                'title' => $row['value_title'],
            );
        }
        return $groups;
    }

    /**
     * Attribute values that actually occur in the products of the given
     * categories. Used by the catalogue sidebar filters.
     *
     * @param array $filters current catalogue filters; attribute selections are
     *                       ignored so every value of a group keeps its count
     */
    public static function getCatalogAttributeGroups($categoryIds = array(), $filters = array())
    {
        $pav = self::table('product_attribute_values');
        $values = self::table('attribute_values');
        $attributes = self::table('attributes');
        $products = self::table('products');

        $categoryIds = array_values(array_unique(array_filter(array_map('intval', (array)$categoryIds))));
        $baseWhere = 'p.`active` = 1 AND av.`active` = 1 AND a.`active` = 1';
        if ($categoryIds) {
            $baseWhere .= ' AND p.`category_id` IN (' . implode(',', $categoryIds) . ')';
        }
        $baseRows = self::db()->query("SELECT DISTINCT av.`id` AS value_id, av.`attribute_id`,
                av.`title` AS value_title, a.`title` AS attribute_title,
                a.`position` AS attribute_position, av.`position` AS value_position
            FROM `{$pav}` pav
            INNER JOIN `{$values}` av ON av.`id` = pav.`attribute_value_id`
            INNER JOIN `{$attributes}` a ON a.`id` = av.`attribute_id`
            INNER JOIN `{$products}` p ON p.`id` = pav.`product_id`
            WHERE {$baseWhere}
            ORDER BY a.`position` ASC, a.`title` ASC, av.`position` ASC, av.`id` ASC");

        $groups = array();
        $groupByValue = array();
        foreach ($baseRows as $row) {
            $attributeId = (int)$row['attribute_id'];
            if (!isset($groups[$attributeId])) {
                $groups[$attributeId] = array(
                    'id' => $attributeId,
                    'title' => $row['attribute_title'],
                    'values' => array(),
                );
            }
            $valueId = (int)$row['value_id'];
            $groups[$attributeId]['values'][] = array(
                'id' => $valueId,
                'title' => $row['value_title'],
                'count' => 0,
            );
            $groupByValue[$valueId] = array($attributeId, count($groups[$attributeId]['values']) - 1);
        }

        // Counts respect price, stock and search filters; attribute selections
        // are ignored so every value of a group keeps its own count.
        $currency = isset($filters['currency']) ? self::currency($filters['currency']) : self::activeCurrency();
        $minSql = self::minPriceSql(self::esc($currency['code']));
        $filters = (array)$filters;
        $filters['category_ids'] = $categoryIds;
        $filters['active_only'] = true;
        unset($filters['attribute_value_ids']);
        $where = self::productFilterWhere($filters, $minSql, $pav, $values);
        $where[] = 'av.`active` = 1 AND a.`active` = 1';
        $whereSql = ' WHERE ' . implode(' AND ', $where);

        $countRows = self::db()->query("SELECT pav.`attribute_value_id` AS value_id,
                COUNT(DISTINCT p.`id`) AS product_count
            FROM `{$pav}` pav
            INNER JOIN `{$values}` av ON av.`id` = pav.`attribute_value_id`
            INNER JOIN `{$attributes}` a ON a.`id` = av.`attribute_id`
            INNER JOIN `{$products}` p ON p.`id` = pav.`product_id`
            {$whereSql}
            GROUP BY pav.`attribute_value_id`");
        foreach ($countRows as $row) {
            $valueId = (int)$row['value_id'];
            if (!isset($groupByValue[$valueId])) continue;
            list($attributeId, $index) = $groupByValue[$valueId];
            $groups[$attributeId]['values'][$index]['count'] = (int)$row['product_count'];
        }
        return $groups;
    }

    public static function syncProductAttributeValues($productId, $valueIds)
    {
        $productId = (int)$productId;
        if ($productId < 1) return;
        $table = self::table('product_attribute_values');
        self::db()->delete($table, array('product_id' => $productId));
        $valueIds = array_unique(array_map('intval', (array)$valueIds));
        foreach ($valueIds as $valueId) {
            if ($valueId < 1) continue;
            self::db()->save('shop_product_attribute_values', array(
                'product_id' => $productId,
                'attribute_value_id' => $valueId,
            ));
        }
    }

    public static function syncProductVariants($productId)
    {
        $productId = (int)$productId;
        if ($productId < 1) return 0;

        $productsTable = self::table('products');
        $productRows = self::db()->query("SELECT `id` FROM `{$productsTable}`
            WHERE `id` = {$productId} LIMIT 1");
        if (empty($productRows[0])) return 0;

        $groups = self::getProductAttributeGroups($productId);
        $combinations = array(array());
        foreach ($groups as $group) {
            if (empty($group['values'])) continue;
            $next = array();
            foreach ($combinations as $combination) {
                foreach ($group['values'] as $value) {
                    $next[] = array_merge($combination, array($value));
                }
            }
            $combinations = $next;
        }
        if (empty($combinations)) $combinations = array(array());

        $variantsTable = self::table('variants');
        $variantValuesTable = self::table('variant_values');
        $pricesTable = self::table('variant_prices');

        $existingRows = self::db()->query("SELECT v.*, vv.`attribute_value_id`
            FROM `{$variantsTable}` v
            LEFT JOIN `{$variantValuesTable}` vv ON vv.`variant_id` = v.`id`
            WHERE v.`product_id` = {$productId}");
        $existing = array();
        foreach ($existingRows as $row) {
            $variantId = (int)$row['id'];
            if (!isset($existing[$variantId])) {
                $existing[$variantId] = array('variant' => $row, 'values' => array());
            }
            if (!empty($row['attribute_value_id'])) {
                $existing[$variantId]['values'][] = (int)$row['attribute_value_id'];
            }
        }

        $existingByKey = array();
        foreach ($existing as $variantId => $data) {
            $ids = array_map('intval', $data['values']);
            sort($ids);
            $key = $ids ? implode('-', $ids) : '0';
            $existingByKey[$key] = $variantId;
        }

        $seen = array();
        $now = date('Y-m-d H:i:s');
        $currencies = self::currencies(false);

        foreach ($combinations as $combination) {
            $valueIds = array();
            $titles = array();
            foreach ($combination as $value) {
                $valueIds[] = (int)$value['id'];
                $titles[] = $value['title'];
            }
            sort($valueIds);
            $key = $valueIds ? implode('-', $valueIds) : '0';
            $title = $titles ? implode(' / ', $titles) : 'Стандарт';
            $seen[$key] = true;

            if (isset($existingByKey[$key])) {
                $variantId = (int)$existingByKey[$key];
                self::db()->save('shop_variants', array(
                    'product_id' => $productId,
                    'title' => $title,
                    'active' => 1,
                    'updated_at' => $now,
                ), array('id' => $variantId));
            } else {
                $variantId = (int)self::db()->save('shop_variants', array(
                    'product_id' => $productId,
                    'title' => $title,
                    'sku' => '',
                    'stock' => 0,
                    'image' => '',
                    'active' => 1,
                    'position' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ));
            }

            self::db()->delete($variantValuesTable, array('variant_id' => $variantId));
            foreach ($valueIds as $valueId) {
                self::db()->save('shop_variant_values', array(
                    'variant_id' => $variantId,
                    'attribute_value_id' => $valueId,
                ));
            }

            foreach ($currencies as $currency) {
                self::db()->query("INSERT IGNORE INTO `{$pricesTable}` (`variant_id`, `currency_code`, `price`)
                    VALUES ({$variantId}, '" . self::esc($currency['code']) . "', 0.00)");
            }
        }

        $activeIds = array();
        foreach ($existingByKey as $key => $variantId) {
            if (isset($seen[$key])) {
                $activeIds[] = (int)$variantId;
            } else {
                self::db()->save('shop_variants', array('active' => 0, 'updated_at' => $now), array('id' => (int)$variantId));
            }
        }

        return count($combinations);
    }

    /* ---------------------------------------------------------------------
     * Orders
     * ------------------------------------------------------------------ */

    public static function ensureOrderRequestsTable()
    {
        static $ready = false;
        if ($ready) return;

        $table = self::table('order_requests');
        try {
            self::db()->query("SELECT `order_id` FROM `{$table}` LIMIT 0");
        } catch (Throwable $e) {
            self::db()->query("CREATE TABLE IF NOT EXISTS `{$table}` (
            `attempt` CHAR(48) DEFAULT NULL,
            `order_id` INT UNSIGNED DEFAULT NULL,
            `customer_mail` VARCHAR(12) NOT NULL DEFAULT 'unknown',
            `admin_mail` VARCHAR(12) NOT NULL DEFAULT 'unknown',
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY `attempt` (`attempt`),
            UNIQUE KEY `order_id` (`order_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }
        // Older installations have no created_at; it only drives housekeeping.
        try {
            self::db()->query("SELECT `created_at` FROM `{$table}` LIMIT 0");
        } catch (Throwable $e) {
            try {
                self::db()->query("ALTER TABLE `{$table}` ADD COLUMN `created_at`
                    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP");
            } catch (Throwable $ignored) {}
        }
        $ready = true;
    }

    public static function orderForAttempt($attempt)
    {
        $table = self::table('order_requests');
        $orders = self::table('orders');
        $attempt = self::esc($attempt);
        $rows = self::db()->query("SELECT o.`id`, o.`access_token` FROM `{$table}` r
            JOIN `{$orders}` o ON o.`id` = r.`order_id` WHERE r.`attempt` = '{$attempt}'");
        return $rows[0] ?? null;
    }

    public static function orderTokenValid($order, $token)
    {
        $created = strtotime((string)$order['created_at']);
        $now = time();
        return $created !== false && $created <= $now && $created > $now - 30 * 86400
            && preg_match('/^[a-f0-9]{40}$/D', $token)
            && hash_equals((string)$order['access_token'], $token);
    }

    public static function orderMailStatuses($orderId)
    {
        self::ensureOrderRequestsTable();
        $table = self::table('order_requests');
        $id = (int)$orderId;
        $rows = self::db()->query("SELECT `customer_mail`, `admin_mail` FROM `{$table}` WHERE `order_id` = {$id}");
        return $rows[0] ?? array('customer_mail' => 'unknown', 'admin_mail' => 'unknown');
    }

    public static function getOrder($orderId)
    {
        $orders = self::table('orders');
        $rows = self::db()->query("SELECT * FROM `{$orders}` WHERE `id` = " . (int)$orderId . " LIMIT 1");
        if (empty($rows[0])) return false;
        $order = $rows[0];
        $order['id'] = (int)$order['id'];
        $order['number'] = self::orderNumber($order['id']);
        $order['status_label'] = self::statusLabel($order['status']);
        $order['subtotal_formatted'] = self::formatPrice($order['subtotal'], $order['currency_code']);
        $order['discount'] = isset($order['discount']) ? (float)$order['discount'] : 0.0;
        $order['discount_formatted'] = $order['discount'] > 0
            ? self::formatPrice($order['discount'], $order['currency_code']) : '';
        $order['promocode'] = isset($order['promocode']) ? (string)$order['promocode'] : '';
        $order['delivery_price_formatted'] = self::formatPrice($order['delivery_price'], $order['currency_code']);
        $order['total_formatted'] = self::formatPrice($order['total'], $order['currency_code']);
        $order['created_at_formatted'] = self::formatDate($order['created_at']);
        $order['tracking_number'] = isset($order['tracking_number']) ? (string)$order['tracking_number'] : '';
        $order['tracking_url'] = isset($order['tracking_url']) ? (string)$order['tracking_url'] : '';
        return $order;
    }

    public static function getOrderItems($orderId, $currencyCode = null)
    {
        $items = self::table('order_items');
        $rows = self::db()->query("SELECT * FROM `{$items}` WHERE `order_id` = " . (int)$orderId . " ORDER BY `id` ASC");
        foreach ($rows as &$row) {
            $row['id'] = (int)$row['id'];
            $row['price_formatted'] = self::formatPrice($row['price'], $currencyCode);
            $row['total_formatted'] = self::formatPrice($row['total'], $currencyCode);
        }
        unset($row);
        return $rows;
    }

    /**
     * Replace the order lines and keep the warehouse in sync. Existing lines
     * keep their captured price, new lines use the current price. Shipping and
     * discount can be corrected in the same call.
     *
     * @param array $quantities variant_id => quantity (0 removes the line)
     * @param float|null $deliveryPrice new shipping cost, null keeps the current
     * @param float|null $discountAmount new absolute discount, null keeps the current
     */
    public static function updateOrderItems($orderId, $quantities, &$error = null,
        $deliveryPrice = null, $discountAmount = null)
    {
        $orderId = (int)$orderId;
        $error = '';
        if ($orderId < 1) {
            $error = 'Заказ не найден.';
            return false;
        }

        $orders = self::table('orders');
        $itemsTable = self::table('order_items');
        $variants = self::table('variants');
        $products = self::table('products');
        $prices = self::table('variant_prices');

        $desired = array();
        foreach ((array)$quantities as $variantId => $qty) {
            $variantId = (int)$variantId;
            $qty = (int)$qty;
            if ($variantId < 1 || $qty < 0) continue;
            if ($qty > 999) $qty = 999;
            $desired[$variantId] = (isset($desired[$variantId]) ? $desired[$variantId] : 0) + $qty;
        }

        if (!self::ensureOrderStatusHistoryTable()) {
            $error = 'Не удалось подготовить историю заказов.';
            return false;
        }
        self::ensureInventoryTables();

        try {
            self::db()->query('START TRANSACTION');
            $rows = self::db()->query("SELECT * FROM `{$orders}` WHERE `id` = {$orderId} FOR UPDATE");
            $order = $rows[0] ?? null;
            if (!$order) throw new RuntimeException('Заказ не найден.');
            if (!empty($order['stock_released']) || $order['status'] === 'cancelled') {
                throw new RuntimeException('Отменённый заказ нельзя редактировать.');
            }

            $current = array();
            $itemRows = self::db()->query("SELECT * FROM `{$itemsTable}` WHERE `order_id` = {$orderId} ORDER BY `id` ASC");
            foreach ($itemRows as $item) {
                $variantId = (int)$item['variant_id'];
                if (!isset($current[$variantId])) {
                    $current[$variantId] = $item;
                    $current[$variantId]['quantity'] = 0;
                }
                $current[$variantId]['quantity'] += (int)$item['quantity'];
            }

            $allIds = array_values(array_unique(array_merge(array_keys($current), array_keys($desired))));
            foreach ($allIds as $variantId) {
                $oldQty = isset($current[$variantId]) ? (int)$current[$variantId]['quantity'] : 0;
                $newQty = isset($desired[$variantId]) ? (int)$desired[$variantId] : 0;
                $delta = $newQty - $oldQty;
                if ($delta > 0) {
                    $variantRows = self::db()->query("SELECT v.`id`, v.`stock`, v.`active` AS variant_active,
                            p.`active` AS product_active
                        FROM `{$variants}` v
                        INNER JOIN `{$products}` p ON p.`id` = v.`product_id`
                        WHERE v.`id` = {$variantId} FOR UPDATE");
                    $variant = $variantRows[0] ?? null;
                    if (!$variant || !$variant['variant_active'] || !$variant['product_active']) {
                        throw new RuntimeException('Позиция недоступна для добавления.');
                    }
                    if ((int)$variant['stock'] < $delta) {
                        throw new RuntimeException('Недостаточно остатка для добавления позиции.');
                    }
                    self::changeStock($variantId, -$delta, 'order_edit', $orderId, 0, 0, 'Изменение состава заказа');
                } elseif ($delta < 0) {
                    self::changeStock($variantId, abs($delta), 'order_edit', $orderId, 0, 0, 'Изменение состава заказа');
                }
            }

            self::db()->query("DELETE FROM `{$itemsTable}` WHERE `order_id` = {$orderId}");
            $subtotal = 0.0;
            $lines = 0;
            foreach ($desired as $variantId => $qty) {
                if ($qty < 1) continue;
                if (isset($current[$variantId])) {
                    $line = $current[$variantId];
                    $price = (float)$line['price'];
                    $productId = (int)$line['product_id'];
                    $productTitle = (string)$line['product_title'];
                    $variantTitle = (string)$line['variant_title'];
                    $sku = (string)$line['sku'];
                } else {
                    $code = self::esc($order['currency_code']);
                    $variantRows = self::db()->query("SELECT v.`id`, v.`title` AS variant_title, v.`sku`,
                            p.`id` AS product_id, p.`title` AS product_title, vp.`price`
                        FROM `{$variants}` v
                        INNER JOIN `{$products}` p ON p.`id` = v.`product_id`
                        INNER JOIN `{$prices}` vp ON vp.`variant_id` = v.`id`
                            AND vp.`currency_code` = '{$code}'
                        WHERE v.`id` = {$variantId} LIMIT 1");
                    $variant = $variantRows[0] ?? null;
                    if (!$variant) throw new RuntimeException('У выбранной позиции нет цены в валюте заказа.');
                    $price = (float)$variant['price'];
                    $productId = (int)$variant['product_id'];
                    $productTitle = (string)$variant['product_title'];
                    $variantTitle = (string)$variant['variant_title'];
                    $sku = (string)$variant['sku'];
                }
                $total = $price * $qty;
                $subtotal += $total;
                $lines++;
                self::db()->save('shop_order_items', array(
                    'order_id' => $orderId,
                    'product_id' => $productId,
                    'variant_id' => $variantId,
                    'product_title' => $productTitle,
                    'variant_title' => $variantTitle,
                    'sku' => $sku,
                    'price' => $price,
                    'quantity' => $qty,
                    'total' => $total,
                ));
            }
            if ($lines < 1) throw new RuntimeException('В заказе должна остаться хотя бы одна позиция.');

            $discount = $discountAmount === null
                ? (isset($order['discount']) ? (float)$order['discount'] : 0.0)
                : max(0.0, (float)$discountAmount);
            if ($discount > $subtotal) $discount = $subtotal;
            $delivery = $deliveryPrice === null
                ? (float)$order['delivery_price']
                : max(0.0, (float)$deliveryPrice);
            $total = $subtotal - $discount + $delivery;
            $now = date('Y-m-d H:i:s');
            self::db()->query("UPDATE `{$orders}` SET `subtotal` = " . number_format($subtotal, 2, '.', '')
                . ", `discount` = " . number_format($discount, 2, '.', '')
                . ", `delivery_price` = " . number_format($delivery, 2, '.', '')
                . ", `total` = " . number_format($total, 2, '.', '')
                . ", `updated_at` = '" . self::esc($now) . "' WHERE `id` = {$orderId}");

            self::logOrderStatus($orderId, (string)$order['status'], 'Состав заказа изменён администратором.');
            self::db()->query('COMMIT');
            return true;
        } catch (Throwable $e) {
            try { self::db()->query('ROLLBACK'); } catch (Throwable $ignored) {}
            $error = $e instanceof RuntimeException ? $e->getMessage() : 'Не удалось изменить состав заказа.';
            error_log('Shop order items update failed: ' . $e->getMessage());
            return false;
        }
    }

    public static function getUserOrders($userId)
    {
        $orders = self::table('orders');
        $rows = self::db()->query("SELECT * FROM `{$orders}` WHERE `user_id` = " . (int)$userId . "
            ORDER BY `created_at` DESC, `id` DESC");
        foreach ($rows as &$row) {
            $row['id'] = (int)$row['id'];
            $row['number'] = self::orderNumber($row['id']);
            $row['status_label'] = self::statusLabel($row['status']);
            $row['total_formatted'] = self::formatPrice($row['total'], $row['currency_code']);
            $row['created_at_formatted'] = self::formatDate($row['created_at']);
            $row['url'] = get_url('/shop/order/' . $row['id'] . '/');
        }
        unset($row);
        return $rows;
    }

    /**
     * Guest order lookup. Customers see orderNumber(), the table keeps the raw
     * id, so both forms are accepted, but only with the matching e-mail.
     */
    public static function orderByNumberAndEmail($number, $email)
    {
        $number = (int)preg_replace('/\D+/', '', (string)$number);
        $email = strtolower(trim((string)$email));
        if ($number < 1 || $email === '') return false;

        $ids = array($number);
        $offset = self::orderNumber(0);
        if ($number > $offset) $ids[] = $number - $offset;
        foreach ($ids as $id) {
            $order = self::getOrder($id);
            if ($order && strtolower((string)$order['email']) === $email) return $order;
        }
        return false;
    }

    public static function releaseOrderStock($orderId)
    {
        return self::setOrderStatus($orderId, 'cancelled');
    }

    public static function setOrderStatus($orderId, $status, $note = '', $changedBy = 0, $changedByName = '')
    {
        $statuses = self::statuses();
        if (!isset($statuses[$status])) return false;
        $orders = self::table('orders');
        $variants = self::table('variants');
        $orderId = (int)$orderId;
        $ready = self::ensureOrderStatusHistoryTable()
            && self::ensurePromoTables()
            && self::ensureInventoryTables()
            && self::ensureReturnTables();
        if (!$ready) return false;
        try {
            self::db()->query('START TRANSACTION');
            $rows = self::db()->query("SELECT * FROM `{$orders}` WHERE `id` = {$orderId} FOR UPDATE");
            if (empty($rows[0])) throw new RuntimeException('Order not found');
            $order = $rows[0];
            // Released stock cannot be reserved again by just changing a label.
            if (!empty($order['stock_released']) && $status !== 'cancelled') throw new RuntimeException('Cancelled order cannot be reopened');
            $released = !empty($order['stock_released']) ? 1 : 0;
            if ($status === 'cancelled' && !$released) {
                $itemsTable = self::table('order_items');
                $items = self::db()->query("SELECT `id`, `variant_id`, `quantity` FROM `{$itemsTable}` WHERE `order_id` = {$orderId} ORDER BY `variant_id`");
                usort($items, function ($a, $b) { return (int)$a['variant_id'] <=> (int)$b['variant_id']; });
                $returned = self::returnedQuantities($orderId);
                foreach ($items as $item) {
                    $variantId = (int)$item['variant_id'];
                    $qty = (int)$item['quantity'];
                    $itemId = (int)$item['id'];
                    // Items already received back must not be added to stock twice.
                    if (isset($returned[$itemId])) $qty -= (int)$returned[$itemId];
                    if ($variantId > 0 && $qty > 0) {
                        self::changeStock($variantId, $qty, 'cancel', $orderId, 0, 0, 'Отмена заказа');
                    }
                }
                // A cancelled order frees its promo redemption for another customer.
                if (!empty($order['promocode'])) {
                    self::db()->query("UPDATE `" . self::table('promocodes') . "`
                        SET `used_count` = GREATEST(`used_count` - 1, 0)
                        WHERE `code` = '" . self::esc($order['promocode']) . "'");
                }
                $released = 1;
            }
            self::db()->query("UPDATE `{$orders}` SET `status` = '" . self::esc($status) . "', `stock_released` = {$released},
                `updated_at` = '" . self::esc(date('Y-m-d H:i:s')) . "' WHERE `id` = {$orderId}");
            if (!self::logOrderStatus($orderId, $status, $note, $changedBy, $changedByName)) {
                throw new RuntimeException('Order status history write failed');
            }
            self::db()->query('COMMIT');
            if ($status === 'paid' || $status === 'completed') {
                self::loyaltyCreditOrder($orderId);
            } elseif ($status === 'refunded' || $status === 'cancelled') {
                self::loyaltyReverseOrder($orderId);
            }
            return true;
        } catch (Throwable $e) {
            try { self::db()->query('ROLLBACK'); } catch (Throwable $ignored) {}
            return false;
        }
    }

    /** @var bool */
    private static $orderStatusHistoryReady = false;

    /**
     * Older installations have no status history table. Create it on first use.
     */
    public static function ensureOrderStatusHistoryTable()
    {
        if (self::$orderStatusHistoryReady) return true;

        $table = self::table('order_status_history');
        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                self::db()->query("CREATE TABLE IF NOT EXISTS `{$table}` (
                    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `order_id` INT UNSIGNED NOT NULL DEFAULT 0,
                    `status` VARCHAR(32) NOT NULL DEFAULT '',
                    `comment` VARCHAR(255) NOT NULL DEFAULT '',
                    `user_id` INT UNSIGNED NOT NULL DEFAULT 0,
                    `user_name` VARCHAR(255) NOT NULL DEFAULT '',
                    `created_at` DATETIME DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    KEY `order_id` (`order_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                self::$orderStatusHistoryReady = true;
                return true;
            } catch (Throwable $e) {
                // Another request may be creating the same table: retry briefly.
                usleep(20000);
            }
        }

        return false;
    }

    public static function logOrderStatus($orderId, $status, $comment = '', $userId = 0, $userName = '', $createdAt = null)
    {
        if (!self::ensureOrderStatusHistoryTable()) return false;
        try {
            self::db()->save('shop_order_status_history', array(
                'order_id' => (int)$orderId,
                'status' => (string)$status,
                'comment' => mb_substr((string)$comment, 0, 255),
                'user_id' => (int)$userId,
                'user_name' => mb_substr((string)$userName, 0, 255),
                'created_at' => $createdAt ? $createdAt : date('Y-m-d H:i:s'),
            ));
            return true;
        } catch (Throwable $e) {
            error_log('Shop order status history write failed: ' . $e->getMessage());
            return false;
        }
    }

    public static function getOrderStatusHistory($orderId)
    {
        if (!self::ensureOrderStatusHistoryTable()) return array();

        $table = self::table('order_status_history');
        try {
            $rows = self::db()->query("SELECT * FROM `{$table}` WHERE `order_id` = " . (int)$orderId . " ORDER BY `id` ASC");
        } catch (Throwable $e) {
            return array();
        }
        foreach ($rows as &$row) {
            $row['id'] = (int)$row['id'];
            $row['order_id'] = (int)$row['order_id'];
            $row['status_label'] = self::statusLabel($row['status']);
            $row['created_at_formatted'] = self::formatDate($row['created_at']);
        }
        unset($row);
        return $rows;
    }

    /**
     * Cancel orders that were never paid for and return their stock.
     * "order_ttl_hours" = 0 keeps orders open forever.
     *
     * @return bool false when the run failed so fpsCron retries next request
     */
    public static function expireUnpaidOrders()
    {
        $hours = (int)self::cfg('order_ttl_hours', 72);
        if ($hours < 1) return true;

        try {
            self::ensureOrderBusinessColumns();
            $orders = self::table('orders');
            $cutoff = date('Y-m-d H:i:s', time() - $hours * 3600);
            $rows = self::db()->query("SELECT `id` FROM `{$orders}`
                WHERE `status` = 'new' AND (
                    (`reserved_until` IS NOT NULL AND `reserved_until` < NOW())
                    OR (`reserved_until` IS NULL AND `created_at` IS NOT NULL AND `created_at` < '" . self::esc($cutoff) . "')
                )
                ORDER BY `id` ASC LIMIT 50");
            foreach ($rows as $row) {
                $id = (int)$row['id'];
                $note = 'Автоматическая отмена: заказ не оплачен за ' . $hours . ' ч.';
                if (!self::setOrderStatus($id, 'cancelled', $note, 0, '')) continue;
                try {
                    $order = self::getOrder($id);
                    if ($order) {
                        self::sendOrderEmails($order,
                            self::getOrderItems($id, $order['currency_code']), 'customer', true);
                    }
                } catch (Throwable $e) {
                    error_log("Shop order {$id}: cancellation notification failed");
                }
            }
            self::cleanupOrderRequests();
        } catch (Throwable $e) {
            error_log('Shop order expiry failed: ' . $e->getMessage());
            return false;
        }
        return true;
    }

    private static function cleanupOrderRequests()
    {
        self::ensureOrderRequestsTable();
        $table = self::table('order_requests');
        try {
            self::db()->query("DELETE FROM `{$table}` WHERE `created_at` IS NOT NULL
                AND `created_at` < DATE_SUB(NOW(), INTERVAL 30 DAY)");
        } catch (Throwable $e) {
            error_log('Shop order request cleanup failed: ' . $e->getMessage());
        }
    }

    /* ---------------------------------------------------------------------
     * Editable email templates
     * ------------------------------------------------------------------ */

    /** @var bool */
    private static $emailTemplatesReady = false;

    public static function ensureEmailTemplatesTable()
    {
        if (self::$emailTemplatesReady) return true;

        try {
            self::db()->query("CREATE TABLE IF NOT EXISTS `" . self::table('email_templates') . "` (
                `code` VARCHAR(64) NOT NULL,
                `subject` VARCHAR(255) NOT NULL DEFAULT '',
                `body` MEDIUMTEXT,
                `updated_at` DATETIME DEFAULT NULL,
                PRIMARY KEY (`code`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Throwable $e) {
            return false;
        }

        self::$emailTemplatesReady = true;
        return true;
    }

    /**
     * Built-in letters. Placeholders in braces are replaced by renderEmailTemplate().
     */
    public static function defaultEmailTemplates()
    {
        return array(
            'order' => array(
                'title' => 'Заказ покупателю и администратору',
                'description' => 'Письмо после оформления заказа и при смене статуса.',
                'subject' => 'Заказ №{number}',
                'placeholders' => '{number}, {date}, {status}, {link}, {tracking}, {customer}, {delivery}, {items}, {totals}, {comment}',
                'body' => '<h2>Заказ №{number}</h2>'
                    . '<p>Дата: {date}<br />Статус: {status}</p>'
                    . '{link}{tracking}'
                    . '<p>{customer}</p>'
                    . '<p>{delivery}</p>'
                    . '{items}'
                    . '<p>{totals}</p>'
                    . '{comment}',
            ),
            'cart_reminder' => array(
                'title' => 'Напоминание о корзине',
                'description' => 'Письмо покупателю, который оставил товары в корзине.',
                'subject' => 'Ваша корзина ждёт',
                'placeholders' => '{name}, {items}, {total}, {cart_url}',
                'body' => '<h2>Ваша корзина ждёт</h2>'
                    . '<p>{name}</p>'
                    . '{items}'
                    . '<p>Сумма: <strong>{total}</strong></p>'
                    . '<p><a href="{cart_url}">Вернуться в корзину</a></p>',
            ),
            'stock_notification' => array(
                'title' => 'Товар снова в наличии',
                'description' => 'Письмо подписчику, который ждал поступления товара.',
                'subject' => 'Товар снова в наличии',
                'placeholders' => '{product}, {product_url}',
                'body' => '<h2>Товар снова в наличии</h2>'
                    . '<p>{product} снова доступен для заказа.</p>'
                    . '<p><a href="{product_url}">Открыть товар на сайте</a></p>',
            ),
            'low_stock' => array(
                'title' => 'Низкий остаток на складе',
                'description' => 'Служебное письмо администратору о заканчивающихся вариантах.',
                'subject' => 'Низкий остаток на складе',
                'placeholders' => '{threshold}, {items}',
                'body' => '<h2>Низкий остаток на складе</h2>'
                    . '<p>Порог уведомления: {threshold} шт.</p>'
                    . '{items}',
            ),
            'review_reply' => array(
                'title' => 'Ответ на отзыв',
                'description' => 'Письмо автору отзыва после ответа администратора.',
                'subject' => 'Ответ на ваш отзыв',
                'placeholders' => '{name}, {product}, {review}, {reply}, {product_url}',
                'body' => '<h2>Ответ на отзыв</h2>'
                    . '<p>{name}, здравствуйте!</p>'
                    . '<p>Магазин ответил на ваш отзыв о товаре «{product}».</p>'
                    . '<p>Ваш отзыв:<br />{review}</p>'
                    . '<p><strong>Ответ магазина:</strong><br />{reply}</p>'
                    . '<p><a href="{product_url}">Открыть страницу товара</a></p>',
            ),
        );
    }

    /**
     * Defaults merged with the rows saved by the administrator.
     */
    public static function emailTemplates()
    {
        $templates = self::defaultEmailTemplates();
        if (!self::ensureEmailTemplatesTable()) return $templates;

        $rows = self::db()->query("SELECT * FROM `" . self::table('email_templates') . "`");
        foreach ($rows as $row) {
            $code = (string)$row['code'];
            if (!isset($templates[$code])) continue;
            $templates[$code]['subject'] = (string)$row['subject'];
            $templates[$code]['body'] = (string)$row['body'];
            $templates[$code]['custom'] = true;
        }
        return $templates;
    }

    public static function emailTemplate($code)
    {
        $templates = self::emailTemplates();
        return isset($templates[$code]) ? $templates[$code] : $templates['order'];
    }

    public static function saveEmailTemplate($code, $subject, $body)
    {
        $defaults = self::defaultEmailTemplates();
        if (!isset($defaults[$code]) || !self::ensureEmailTemplatesTable()) return false;

        $subject = trim((string)$subject);
        if ($subject === '') $subject = $defaults[$code]['subject'];
        $body = (string)$body;
        if (trim(strip_tags($body)) === '') $body = $defaults[$code]['body'];
        try {
            $table = self::table('email_templates');
            self::db()->query("INSERT INTO `{$table}` (`code`, `subject`, `body`, `updated_at`)
                VALUES ('" . self::esc($code) . "', '" . self::esc(mb_substr($subject, 0, 255)) . "',
                    '" . self::esc($body) . "', NOW())
                ON DUPLICATE KEY UPDATE `subject` = VALUES(`subject`), `body` = VALUES(`body`),
                    `updated_at` = VALUES(`updated_at`)");
            return true;
        } catch (Throwable $e) {
            error_log('Shop email template save failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Drop the custom row so the built-in letter is used again.
     */
    public static function resetEmailTemplate($code)
    {
        if (!isset(self::defaultEmailTemplates()[$code]) || !self::ensureEmailTemplatesTable()) {
            return false;
        }
        try {
            self::db()->query("DELETE FROM `" . self::table('email_templates') . "`
                WHERE `code` = '" . self::esc($code) . "'");
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * @return array subject, body with {placeholders} replaced.
     */
    public static function renderEmailTemplate($code, $vars)
    {
        $template = self::emailTemplate($code);
        $replace = array();
        foreach ((array)$vars as $key => $value) {
            $replace['{' . $key . '}'] = (string)$value;
        }
        return array(
            'subject' => strtr((string)$template['subject'], $replace),
            'body' => strtr((string)$template['body'], $replace),
        );
    }

    public static function sendOrderEmails($order, $items, $recipient = null, $force = false)
    {
        $orderLink = !empty($order['access_token'])
            ? self::absoluteUrl(get_url('/shop/order/' . (int)$order['id'] . '/' . $order['access_token'] . '/'))
            : '';
        $link = $orderLink !== ''
            ? '<p><a href="' . h($orderLink) . '">Открыть заказ на сайте</a></p>' : '';
        $tracking = '';
        if (!empty($order['tracking_number'])) {
            $tracking = '<p>Отслеживание: ' . h($order['tracking_number']);
            if (!empty($order['tracking_url'])) {
                $tracking .= ' — <a href="' . h($order['tracking_url']) . '">открыть трек</a>';
            }
            $tracking .= '</p>';
        }
        $customer = 'Покупатель: ' . h($order['customer_name']) . '<br />'
            . 'E-mail: ' . h($order['email']) . '<br />'
            . 'Телефон: ' . h($order['phone']);
        $delivery = 'Доставка: ' . h($order['delivery_title']) . '<br />'
            . 'Адрес: ' . nl2br(h($order['address'])) . '<br />'
            . 'Оплата: ' . h($order['payment_title']);
        $itemsTable = '<table cellpadding="6" cellspacing="0" border="1" style="border-collapse:collapse;">'
            . '<tr><th>Товар</th><th>Артикул</th><th>Цена</th><th>Кол-во</th><th>Итого</th></tr>';
        foreach ($items as $item) {
            $itemsTable .= '<tr><td>' . h($item['product_title'])
                . ($item['variant_title'] !== '' ? '<br /><small>' . h($item['variant_title']) . '</small>' : '')
                . '</td><td>' . h($item['sku']) . '</td><td>'
                . h(self::formatPrice($item['price'], $order['currency_code'])) . '</td><td>'
                . (int)$item['quantity'] . '</td><td>'
                . h(self::formatPrice($item['total'], $order['currency_code'])) . '</td></tr>';
        }
        $itemsTable .= '</table>';
        $totals = 'Товары: ' . h(self::formatPrice($order['subtotal'], $order['currency_code'])) . '<br />';
        if (!empty($order['discount'])) {
            $totals .= 'Скидка' . (!empty($order['promocode']) ? ' по промокоду ' . h($order['promocode']) : '')
                . ': −' . h(self::formatPrice($order['discount'], $order['currency_code'])) . '<br />';
        }
        $totals .= 'Доставка: ' . h(self::formatPrice($order['delivery_price'], $order['currency_code'])) . '<br />'
            . '<strong>Итого: ' . h(self::formatPrice($order['total'], $order['currency_code'])) . '</strong>';
        $comment = !empty($order['comment'])
            ? '<p>Комментарий: ' . nl2br(h($order['comment'])) . '</p>' : '';

        $mail = self::renderEmailTemplate('order', array(
            'number' => self::orderNumber($order['id']),
            'date' => self::formatDate($order['created_at']),
            'status' => self::statusLabel($order['status']),
            'link' => $link,
            'tracking' => $tracking,
            'customer' => $customer,
            'delivery' => $delivery,
            'items' => $itemsTable,
            'totals' => $totals,
            'comment' => $comment,
        ));
        $body = $mail['body'];
        $subject = $mail['subject'];
        $templatePath = ROOT . '/sys/settings/email_templates/';

        $notify = trim((string)self::cfg('notify_email', ''));
        if ($notify === '') $notify = trim((string)Config::read('admin_email'));
        if (strcasecmp($notify, $order['email']) === 0) $notify = '';
        $recipients = array('customer' => $order['email'], 'admin' => $notify);
        if ($recipient !== null) {
            if (!isset($recipients[$recipient])) return false;
            $recipients = array($recipient => $recipients[$recipient]);
        }

        self::ensureOrderRequestsTable();
        $table = self::table('order_requests');
        $id = (int)$order['id'];
        $lock = 'atm_shop_mail_' . sha1($table . ':' . $id);
        $locked = self::db()->query("SELECT GET_LOCK('{$lock}', 0) AS acquired");
        if (empty($locked[0]['acquired'])) return false;

        $ok = true;
        try {
            $statuses = self::orderMailStatuses($id);
            foreach ($recipients as $type => $address) {
                $column = $type . '_mail';
                if ($statuses[$column] === 'sent' && !$force) continue;
                $state = 'skipped';
                if ($address !== '') {
                    $state = 'failed';
                    try {
                        $mailer = new AtmMail($templatePath);
                        $mailer->prepare($body);
                        $mailSubject = $subject . ($type === 'admin' ? ' (администратор)' : '');
                        if (@$mailer->sendMail($address, $mailSubject, array())) $state = 'sent';
                    } catch (Throwable $e) {
                        // Record the failed attempt below without logging the address.
                    }
                }
                self::db()->query("INSERT INTO `{$table}` (`order_id`, `{$column}`) VALUES ({$id}, '{$state}')
                    ON DUPLICATE KEY UPDATE `{$column}` = '{$state}'");
                if ($state !== 'sent') $ok = false;
                if ($state === 'failed') error_log("Shop order {$id}: {$type} notification failed");
            }
        } finally {
            self::db()->query("SELECT RELEASE_LOCK('{$lock}')");
        }
        return $ok;
    }

    /* ---------------------------------------------------------------------
     * Tracking, low stock
     * ------------------------------------------------------------------ */

    /** @var bool */
    private static $orderTrackingReady = false;

    /**
     * Older installations have no tracking columns: add them once.
     */
    public static function ensureOrderTrackingColumns()
    {
        if (self::$orderTrackingReady) return true;

        $table = self::table('orders');
        try {
            self::db()->query("SELECT `tracking_number` FROM `{$table}` LIMIT 1");
        } catch (Throwable $e) {
            try {
                self::db()->query("ALTER TABLE `{$table}`
                    ADD COLUMN `tracking_number` VARCHAR(128) NOT NULL DEFAULT '',
                    ADD COLUMN `tracking_url` VARCHAR(255) NOT NULL DEFAULT ''");
            } catch (Throwable $alterError) {
                return false;
            }
        }

        self::$orderTrackingReady = true;
        return true;
    }

    public static function cleanTrackingNumber($value)
    {
        return ShopTrackingValidator::number($value);
    }

    public static function trackingUrlValid($url)
    {
        return ShopTrackingValidator::url($url);
    }

    public static function setOrderTracking($orderId, $number, $url)
    {
        $orderId = (int)$orderId;
        if ($orderId < 1) return false;

        $number = self::cleanTrackingNumber($number);
        $url = trim((string)$url);
        if (!self::trackingUrlValid($url)) return false;
        if (!self::ensureOrderTrackingColumns()) return false;

        try {
            self::db()->save('shop_orders', array(
                'tracking_number' => $number,
                'tracking_url' => $url,
                'updated_at' => date('Y-m-d H:i:s'),
            ), array('id' => $orderId));
            return true;
        } catch (Throwable $e) {
            error_log('Shop tracking save failed: ' . $e->getMessage());
            return false;
        }
    }

    /** @var bool */
    private static $variantNotifyReady = false;

    public static function ensureVariantNotifyColumn()
    {
        if (self::$variantNotifyReady) return true;

        $table = self::table('variants');
        try {
            self::db()->query("SELECT `low_stock_notified` FROM `{$table}` LIMIT 1");
        } catch (Throwable $e) {
            try {
                self::db()->query("ALTER TABLE `{$table}`
                    ADD COLUMN `low_stock_notified` TINYINT(1) NOT NULL DEFAULT 0");
            } catch (Throwable $alterError) {
                return false;
            }
        }

        self::$variantNotifyReady = true;
        return true;
    }

    /**
     * One e-mail per low-stock episode. "low_stock_threshold" = 0 disables.
     *
     * @return bool false when the run failed so fpsCron retries next request
     */
    public static function lowStockAlert()
    {
        $threshold = (int)self::cfg('low_stock_threshold', 3);
        if ($threshold < 1) return true;
        if (!self::ensureVariantNotifyColumn()) return false;

        $variants = self::table('variants');
        $products = self::table('products');
        try {
            self::db()->query("UPDATE `{$variants}` SET `low_stock_notified` = 0
                WHERE `active` = 1 AND `stock` > {$threshold} AND `low_stock_notified` = 1");

            $rows = self::db()->query("SELECT v.`id`, v.`title`, v.`sku`, v.`stock`,
                    p.`title` AS product_title
                FROM `{$variants}` v
                INNER JOIN `{$products}` p ON p.`id` = v.`product_id`
                WHERE v.`active` = 1 AND p.`active` = 1
                    AND v.`stock` <= {$threshold} AND v.`low_stock_notified` = 0
                ORDER BY v.`stock` ASC, v.`id` ASC LIMIT 100");
            if (!$rows) return true;

            $notify = trim((string)self::cfg('notify_email', ''));
            if ($notify === '') $notify = trim((string)Config::read('admin_email'));
            if ($notify === '') return true;

            $itemsHtml = '<ul>';
            foreach ($rows as $row) {
                $itemsHtml .= '<li>' . h($row['product_title']) . ' — ' . h($row['title'])
                    . ($row['sku'] !== '' ? ' (' . h($row['sku']) . ')' : '')
                    . ': <strong>' . (int)$row['stock'] . '</strong></li>';
            }
            $itemsHtml .= '</ul>';
            $mail = self::renderEmailTemplate('low_stock', array(
                'threshold' => (int)$threshold,
                'items' => $itemsHtml,
            ));

            $mailer = new AtmMail(ROOT . '/sys/settings/email_templates/');
            $mailer->prepare($mail['body']);
            if (!@$mailer->sendMail($notify, $mail['subject'], array())) return false;

            $ids = array_map('intval', array_column($rows, 'id'));
            self::db()->query("UPDATE `{$variants}` SET `low_stock_notified` = 1
                WHERE `id` IN (" . implode(',', $ids) . ")");
        } catch (Throwable $e) {
            error_log('Shop low stock alert failed: ' . $e->getMessage());
            return false;
        }
        return true;
    }

    /* ---------------------------------------------------------------------
     * Reviews
     * ------------------------------------------------------------------ */

    /** @var bool */
    private static $reviewsTableReady = false;

    public static function ensureReviewsTable()
    {
        if (self::$reviewsTableReady) return true;

        $table = self::table('reviews');
        try {
            self::db()->query("CREATE TABLE IF NOT EXISTS `{$table}` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `product_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `user_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `name` VARCHAR(255) NOT NULL DEFAULT '',
                `email` VARCHAR(255) NOT NULL DEFAULT '',
                `rating` TINYINT UNSIGNED NOT NULL DEFAULT 0,
                `text` TEXT,
                `active` TINYINT(1) NOT NULL DEFAULT 0,
                `reply` TEXT,
                `replied_at` DATETIME DEFAULT NULL,
                `created_at` DATETIME DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `product_active` (`product_id`, `active`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Throwable $e) {
            return false;
        }

        self::$reviewsTableReady = true;
        return true;
    }

    /** @var bool */
    private static $reviewReplyReady = false;

    /** @var bool */
    private static $reviewExtrasReady = false;

    /**
     * Older installations have no reply columns: add them once.
     */
    public static function ensureReviewReplyColumns()
    {
        if (!self::ensureReviewsTable()) return false;
        if (self::$reviewReplyReady) return true;

        $table = self::table('reviews');
        try {
            self::db()->query("SELECT `reply` FROM `{$table}` LIMIT 1");
        } catch (Throwable $e) {
            try {
                self::db()->query("ALTER TABLE `{$table}`
                    ADD COLUMN `reply` TEXT NULL,
                    ADD COLUMN `replied_at` DATETIME DEFAULT NULL");
            } catch (Throwable $alterError) {
                return false;
            }
        }

        self::$reviewReplyReady = true;
        return true;
    }

    /**
     * Photos and "helpful" votes are optional, so the column and the vote table
     * are created on first use. An updated site needs no manual migration.
     */
    public static function ensureReviewExtras()
    {
        if (self::$reviewExtrasReady) return true;
        if (!self::ensureReviewsTable()) return false;

        $table = self::table('reviews');
        try {
            self::db()->query("SELECT `photos` FROM `{$table}` LIMIT 1");
        } catch (Throwable $e) {
            try {
                self::db()->query("ALTER TABLE `{$table}` ADD COLUMN `photos` TEXT NULL");
            } catch (Throwable $alterError) {
                return false;
            }
        }

        try {
            self::db()->query("CREATE TABLE IF NOT EXISTS `" . self::table('review_votes') . "` (
                `review_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `voter` VARCHAR(64) NOT NULL DEFAULT '',
                `created_at` DATETIME DEFAULT NULL,
                PRIMARY KEY (`review_id`, `voter`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Throwable $e) {
            return false;
        }

        self::$reviewExtrasReady = true;
        return true;
    }

    /**
     * Store a customer review photo. Mirrors the product image rules: the real
     * image type decides the extension, the file is re-encoded through the
     * thumbnail helper, and the name is random.
     *
     * @return string stored file name, empty string on failure
     */
    public static function saveReviewPhoto($tmp, &$error = null)
    {
        $error = '';
        $info = @getimagesize($tmp);
        if (!$info) {
            $error = 'Файл не является изображением.';
            return '';
        }
        $typeMap = array(
            IMAGETYPE_JPEG => 'jpg',
            IMAGETYPE_PNG => 'png',
            IMAGETYPE_GIF => 'gif',
            IMAGETYPE_WEBP => 'webp',
        );
        $type = isset($info[2]) ? (int)$info[2] : 0;
        if (!isset($typeMap[$type])) {
            $error = 'Допустимы только изображения JPG, PNG, GIF или WEBP.';
            return '';
        }
        if (function_exists('atm_validate_upload')
            && atm_validate_upload(array('name' => 'photo.' . $typeMap[$type], 'tmp_name' => $tmp), true) === false) {
            $error = 'Недопустимое изображение или превышен размер файла.';
            return '';
        }

        $dir = ROOT . '/sys/files/shop/reviews';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
            $error = 'Не удалось создать папку для фотографий.';
            return '';
        }

        $filename = '';
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $candidate = 'review_' . date('YmdHis') . '_' . mt_rand(100000, 999999) . '.' . $typeMap[$type];
            if (!file_exists($dir . '/' . $candidate)) {
                $filename = $candidate;
                break;
            }
        }
        if ($filename === '') {
            $error = 'Не удалось подобрать имя для фотографии.';
            return '';
        }

        $path = $dir . '/' . $filename;
        if (!@move_uploaded_file($tmp, $path)) {
            $error = 'Не удалось сохранить фотографию.';
            return '';
        }

        if (!function_exists('resampleImage')) include_once ROOT . '/sys/fnc/thumb.php';
        if (function_exists('resampleImage')) {
            // Downscale huge phone photos; keep the original on failure.
            @resampleImage($path, $path, 1200, 1200);
        }
        return $filename;
    }

    public static function reviewPhotoUrl($filename)
    {
        $filename = basename(trim((string)$filename));
        if ($filename === '') return '';
        return get_url('/img.php?src=' . rawurlencode('/sys/files/shop/reviews/' . $filename), false, false);
    }

    /**
     * One session, one vote per review. Returns the new counter value.
     */
    public static function markReviewHelpful($reviewId, $voter = '', $productId = 0)
    {
        $reviewId = (int)$reviewId;
        if ($reviewId < 1 || !self::ensureReviewExtras()) return false;

        try {
            $reviewWhere = "`id` = {$reviewId}";
            if ((int)$productId > 0) $reviewWhere .= " AND `product_id` = " . (int)$productId;
            $review = self::db()->query("SELECT `id` FROM `" . self::table('reviews') . "`
                WHERE {$reviewWhere} LIMIT 1");
        } catch (Throwable $e) {
            return false;
        }
        if (empty($review)) return false;

        $voter = trim((string)$voter);
        if ($voter === '') {
            $voter = substr(hash('sha256', session_id() . '|review'), 0, 64);
        }
        try {
            self::db()->query("INSERT IGNORE INTO `" . self::table('review_votes') . "`
                (`review_id`, `voter`, `created_at`) VALUES ({$reviewId}, '" . self::esc($voter) . "',
                '" . self::esc(date('Y-m-d H:i:s')) . "')");
            $rows = self::db()->query("SELECT COUNT(*) AS cnt FROM `" . self::table('review_votes') . "`
                WHERE `review_id` = {$reviewId}");
        } catch (Throwable $e) {
            return false;
        }
        return !empty($rows[0]['cnt']) ? (int)$rows[0]['cnt'] : 0;
    }

    public static function replyToReview($reviewId, $reply)
    {
        $reviewId = (int)$reviewId;
        if ($reviewId < 1) return false;
        if (!self::ensureReviewsTable() || !self::ensureReviewReplyColumns()) return false;

        $reply = trim((string)$reply);
        $reply = mb_substr($reply, 0, 5000);
        try {
            $table = self::table('reviews');
            $repliedAt = $reply === '' ? 'NULL' : 'NOW()';
            self::db()->query("UPDATE `{$table}` SET `reply` = '" . self::esc($reply) . "',
                `replied_at` = {$repliedAt} WHERE `id` = {$reviewId}");
        } catch (Throwable $e) {
            error_log('Shop review reply save failed: ' . $e->getMessage());
            return false;
        }

        if ($reply !== '') self::sendReviewReplyEmail($reviewId);
        return true;
    }

    public static function sendReviewReplyEmail($reviewId)
    {
        $reviewId = (int)$reviewId;
        if ($reviewId < 1 || !self::ensureReviewsTable()) return false;

        $rows = self::db()->query("SELECT r.*, p.`title` AS product_title,
                p.`clean_url_title` AS product_slug
            FROM `" . self::table('reviews') . "` r
            LEFT JOIN `" . self::table('products') . "` p ON p.`id` = r.`product_id`
            WHERE r.`id` = {$reviewId} LIMIT 1");
        $review = isset($rows[0]) ? $rows[0] : null;
        if (!$review || trim((string)$review['reply']) === ''
            || !filter_var($review['email'], FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $productUrl = (int)$review['product_id'] > 0
            ? self::absoluteUrl(self::productUrl(array(
                'id' => (int)$review['product_id'],
                'clean_url_title' => (string)$review['product_slug'],
            ))) : '';
        $mail = self::renderEmailTemplate('review_reply', array(
            'name' => h($review['name']),
            'product' => h($review['product_title']),
            'review' => nl2br(h($review['text'])),
            'reply' => nl2br(h($review['reply'])),
            'product_url' => h($productUrl),
        ));
        try {
            $mailer = new AtmMail(ROOT . '/sys/settings/email_templates/');
            $mailer->prepare($mail['body']);
            return @$mailer->sendMail($review['email'], $mail['subject'], array());
        } catch (Throwable $e) {
            error_log('Shop review reply email failed for review ' . $reviewId);
            return false;
        }
    }

    public static function ratingValid($rating)
    {
        $rating = (int)$rating;
        return $rating >= 1 && $rating <= 5;
    }

    public static function addReview($data)
    {
        if (!self::ensureReviewsTable()) return false;

        $data = (array)$data;
        $productId = (int)(isset($data['product_id']) ? $data['product_id'] : 0);
        $rating = (int)(isset($data['rating']) ? $data['rating'] : 0);
        $name = trim((string)(isset($data['name']) ? $data['name'] : ''));
        $email = trim((string)(isset($data['email']) ? $data['email'] : ''));
        $text = trim((string)(isset($data['text']) ? $data['text'] : ''));

        if ($productId < 1 || !self::ratingValid($rating) || $name === ''
            || $text === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        try {
            $photos = array();
            foreach ((array)(isset($data['photos']) ? $data['photos'] : array()) as $photo) {
                $photo = basename(trim((string)$photo));
                if ($photo !== '' && preg_match('/^[A-Za-z0-9._-]+$/', $photo)) $photos[] = $photo;
                if (count($photos) >= 3) break;
            }
            $row = array(
                'product_id' => $productId,
                'user_id' => (int)(isset($data['user_id']) ? $data['user_id'] : 0),
                'name' => mb_substr($name, 0, 255),
                'email' => mb_substr($email, 0, 255),
                'rating' => $rating,
                'text' => mb_substr($text, 0, 5000),
                'active' => (int)self::cfg('reviews_moderate', 1) === 1 ? 0 : 1,
                'created_at' => date('Y-m-d H:i:s'),
            );
            if ($photos && self::ensureReviewExtras()) {
                $row['photos'] = json_encode($photos, JSON_UNESCAPED_SLASHES);
            }
            self::db()->save('shop_reviews', $row);
            return true;
        } catch (Throwable $e) {
            error_log('Shop review save failed: ' . $e->getMessage());
            return false;
        }
    }

    public static function getProductReviews($productId, $onlyActive = true)
    {
        if (!self::ensureReviewsTable()) return array();

        $table = self::table('reviews');
        $where = '`product_id` = ' . (int)$productId;
        if ($onlyActive) $where .= ' AND `active` = 1';
        $rows = self::db()->query("SELECT * FROM `{$table}` WHERE {$where} ORDER BY `id` DESC LIMIT 100");

        // Mark reviews written by a customer who has a non-cancelled order
        // containing this product. One batch query for the whole list.
        $verifiedEmails = array();
        $verifiedUsers = array();
        try {
            $emails = array();
            $userIds = array();
            foreach ($rows as $row) {
                $email = mb_strtolower(trim((string)$row['email']));
                if ($email !== '') $emails[$email] = true;
                if ((int)$row['user_id'] > 0) $userIds[(int)$row['user_id']] = true;
            }
            $owners = array();
            if ($emails) {
                $owners[] = "LOWER(o.`email`) IN ('" . implode("','", array_map(array(self::class, 'esc'), array_keys($emails))) . "')";
            }
            if ($userIds) {
                $owners[] = 'o.`user_id` IN (' . implode(',', array_keys($userIds)) . ')';
            }
            if ($owners) {
                $bought = self::db()->query("SELECT DISTINCT o.`email`, o.`user_id`
                    FROM `" . self::table('order_items') . "` oi
                    INNER JOIN `" . self::table('orders') . "` o ON o.`id` = oi.`order_id`
                    WHERE oi.`product_id` = " . (int)$productId . "
                        AND o.`status` <> 'cancelled' AND (" . implode(' OR ', $owners) . ")");
                foreach ($bought as $orderRow) {
                    $email = mb_strtolower(trim((string)$orderRow['email']));
                    if ($email !== '') $verifiedEmails[$email] = true;
                    if ((int)$orderRow['user_id'] > 0) $verifiedUsers[(int)$orderRow['user_id']] = true;
                }
            }
        } catch (Throwable $e) {
            // Reviews still render without the badge if the order tables are missing.
        }

        $helpful = array();
        if ($rows && self::ensureReviewExtras()) {
            try {
                $ids = implode(',', array_map('intval', array_column($rows, 'id')));
                $votes = self::db()->query("SELECT `review_id`, COUNT(*) AS cnt
                    FROM `" . self::table('review_votes') . "`
                    WHERE `review_id` IN ({$ids}) GROUP BY `review_id`");
                foreach ($votes as $vote) $helpful[(int)$vote['review_id']] = (int)$vote['cnt'];
            } catch (Throwable $e) {
                // Counters are optional; reviews still render without them.
            }
        }

        foreach ($rows as &$row) {
            $row['id'] = (int)$row['id'];
            $row['rating'] = (int)$row['rating'];
            $row['created_at_formatted'] = self::formatDate($row['created_at']);
            $row['rating_stars'] = str_repeat('★', $row['rating']);
            $row['text_html'] = nl2br(h((string)$row['text']));
            $row['reply'] = isset($row['reply']) ? (string)$row['reply'] : '';
            $row['reply_html'] = $row['reply'] !== '' ? nl2br(h($row['reply'])) : '';
            $row['photo_urls'] = array();
            foreach ((array)json_decode(isset($row['photos']) ? (string)$row['photos'] : '', true) as $photo) {
                $url = self::reviewPhotoUrl($photo);
                if ($url !== '') $row['photo_urls'][] = $url;
            }
            $row['helpful_count'] = isset($helpful[$row['id']]) ? $helpful[$row['id']] : 0;
            $row['verified'] = isset($verifiedEmails[mb_strtolower(trim((string)$row['email']))])
                || ((int)$row['user_id'] > 0 && isset($verifiedUsers[(int)$row['user_id']]));
        }
        unset($row);
        return $rows;
    }

    /**
     * Average rating and count for many products in one query.
     *
     * @param array $productIds
     * @return array product id => array('avg' => float, 'count' => int)
     */
    public static function productRatings($productIds)
    {
        return ShopReviewRatings::all($productIds);
    }

    public static function productRating($productId)
    {
        return ShopReviewRatings::one($productId);
    }

    public static function getReviews($active = null, $limit = 200)
    {
        if (!self::ensureReviewsTable()) return array();

        $limit = max(1, min(500, (int)$limit));
        $where = $active === null ? '' : ' WHERE `active` = ' . (int)!empty($active);
        $table = self::table('reviews');
        $products = self::table('products');
        $rows = self::db()->query("SELECT r.*, p.`title` AS product_title, p.`clean_url_title` AS product_slug
            FROM `{$table}` r
            LEFT JOIN `{$products}` p ON p.`id` = r.`product_id`
            {$where}
            ORDER BY r.`id` DESC LIMIT {$limit}");
        foreach ($rows as &$row) {
            $row['id'] = (int)$row['id'];
            $row['product_id'] = (int)$row['product_id'];
            $row['rating'] = (int)$row['rating'];
            $row['rating_stars'] = str_repeat('★', $row['rating']);
            $row['created_at_formatted'] = self::formatDate($row['created_at']);
            $row['reply'] = isset($row['reply']) ? (string)$row['reply'] : '';
            $row['replied_at_formatted'] = !empty($row['replied_at'])
                ? self::formatDate($row['replied_at']) : '';
            $row['photo_urls'] = array();
            foreach ((array)json_decode(isset($row['photos']) ? (string)$row['photos'] : '', true) as $photo) {
                $url = self::reviewPhotoUrl($photo);
                if ($url !== '') $row['photo_urls'][] = $url;
            }
            if ($row['product_id'] > 0) {
                $row['product_url'] = self::productUrl(array(
                    'id' => $row['product_id'],
                    'clean_url_title' => (string)$row['product_slug'],
                ));
            }
        }
        unset($row);
        return $rows;
    }

    public static function setReviewActive($reviewId, $active)
    {
        if (!self::ensureReviewsTable()) return false;
        try {
            self::db()->save('shop_reviews', array('active' => (int)!empty($active)),
                array('id' => (int)$reviewId));
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    public static function deleteReview($reviewId)
    {
        if (!self::ensureReviewsTable()) return false;
        $reviewId = (int)$reviewId;

        $photos = array();
        if (self::ensureReviewExtras()) {
            try {
                $rows = self::db()->query("SELECT `photos` FROM `" . self::table('reviews') . "`
                    WHERE `id` = {$reviewId} LIMIT 1");
                $photos = (array)json_decode(isset($rows[0]['photos']) ? (string)$rows[0]['photos'] : '', true);
            } catch (Throwable $e) {
                $photos = array();
            }
        }

        try {
            self::db()->delete('shop_reviews', array('id' => $reviewId));
            foreach ($photos as $photo) {
                $photo = basename((string)$photo);
                if ($photo !== '') @unlink(ROOT . '/sys/files/shop/reviews/' . $photo);
            }
            if (self::$reviewExtrasReady) {
                self::db()->query("DELETE FROM `" . self::table('review_votes') . "` WHERE `review_id` = {$reviewId}");
            }
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    /* ---------------------------------------------------------------------
     * Stock subscriptions
     * ------------------------------------------------------------------ */

    /** @var bool */
    private static $stockSubscriptionsReady = false;

    /** @var bool */
    private static $relatedProductsReady = false;

    public static function ensureStockSubscriptionsTable()
    {
        if (self::$stockSubscriptionsReady) return true;

        $table = self::table('stock_subscriptions');
        try {
            self::db()->query("CREATE TABLE IF NOT EXISTS `{$table}` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `product_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `email` VARCHAR(255) NOT NULL DEFAULT '',
                `notified` TINYINT(1) NOT NULL DEFAULT 0,
                `created_at` DATETIME DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `product_email` (`product_id`, `email`),
                KEY `notified` (`notified`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Throwable $e) {
            return false;
        }

        self::$stockSubscriptionsReady = true;
        return true;
    }

    public static function addStockSubscription($productId, $email)
    {
        $productId = (int)$productId;
        $email = trim((string)$email);
        if ($productId < 1 || !filter_var($email, FILTER_VALIDATE_EMAIL)) return false;
        if (!self::ensureStockSubscriptionsTable()) return false;

        $table = self::table('stock_subscriptions');
        try {
            self::db()->query("INSERT INTO `{$table}` (`product_id`, `email`, `notified`, `created_at`)
                VALUES ({$productId}, '" . self::esc(mb_substr($email, 0, 255)) . "', 0, NOW())
                ON DUPLICATE KEY UPDATE `notified` = 0");
            return true;
        } catch (Throwable $e) {
            error_log('Shop stock subscription failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * E-mail waiting customers about products that are back in stock.
     * Request-driven, same as the order expiry.
     */
    public static function notifyStockSubscribers()
    {
        if (!self::ensureStockSubscriptionsTable()) return false;

        $subscriptions = self::table('stock_subscriptions');
        $products = self::table('products');
        $variants = self::table('variants');
        try {
            $rows = self::db()->query("SELECT s.`id`, s.`email`, s.`product_id`, p.`title`
                FROM `{$subscriptions}` s
                INNER JOIN `{$products}` p ON p.`id` = s.`product_id`
                WHERE s.`notified` = 0 AND p.`active` = 1
                    AND EXISTS (
                        SELECT 1 FROM `{$variants}` v
                        WHERE v.`product_id` = s.`product_id` AND v.`active` = 1 AND v.`stock` > 0
                    )
                ORDER BY s.`id` ASC LIMIT 100");
            if (!$rows) return true;

            $templatePath = ROOT . '/sys/settings/email_templates/';
            foreach ($rows as $row) {
                $productId = (int)$row['product_id'];
                $productUrl = self::absoluteUrl(self::productUrl(array('id' => $productId, 'clean_url_title' => '')));
                try {
                    $mailer = new AtmMail($templatePath);
                    $mail = self::renderEmailTemplate('stock_notification', array(
                        'product' => '«' . h($row['title']) . '»',
                        'product_url' => h($productUrl),
                    ));
                    $mailer->prepare($mail['body']);
                    if (@$mailer->sendMail($row['email'], $mail['subject'], array())) {
                        self::db()->query("UPDATE `{$subscriptions}` SET `notified` = 1
                            WHERE `id` = " . (int)$row['id']);
                    }
                } catch (Throwable $e) {
                    error_log('Shop stock notification failed for subscription ' . (int)$row['id']);
                }
            }
        } catch (Throwable $e) {
            error_log('Shop stock notifications failed: ' . $e->getMessage());
            return false;
        }
        return true;
    }

    /* ---------------------------------------------------------------------
     * Product recommendations
     * ------------------------------------------------------------------ */

    /**
     * Manual "customers also buy" picks, stored per product. The table is
     * created on first use so updated installations need no module reinstall.
     */
    public static function ensureRelatedProductsTable()
    {
        if (self::$relatedProductsReady) return true;

        try {
            self::db()->query("CREATE TABLE IF NOT EXISTS `" . self::table('related_products') . "` (
                `product_id` INT UNSIGNED NOT NULL,
                `related_product_id` INT UNSIGNED NOT NULL,
                `position` INT NOT NULL DEFAULT 0,
                PRIMARY KEY (`product_id`, `related_product_id`),
                KEY `related_product_id` (`related_product_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Throwable $e) {
            return false;
        }

        self::$relatedProductsReady = true;
        return true;
    }

    public static function relatedProductIds($productId)
    {
        $productId = (int)$productId;
        if ($productId < 1 || !self::ensureRelatedProductsTable()) return array();

        $rows = self::db()->query("SELECT `related_product_id` FROM `" . self::table('related_products') . "`
            WHERE `product_id` = {$productId}
            ORDER BY `position` ASC, `related_product_id` ASC");
        $ids = array();
        foreach ($rows as $row) $ids[] = (int)$row['related_product_id'];
        return $ids;
    }

    public static function syncRelatedProducts($productId, $relatedIds)
    {
        $productId = (int)$productId;
        if ($productId < 1 || !self::ensureRelatedProductsTable()) return false;

        $table = self::table('related_products');
        $products = self::table('products');
        self::db()->query("DELETE FROM `{$table}` WHERE `product_id` = {$productId}");

        $seen = array();
        $position = 0;
        foreach ((array)$relatedIds as $relatedId) {
            $relatedId = (int)$relatedId;
            if ($relatedId < 1 || $relatedId === $productId || isset($seen[$relatedId])) continue;
            $seen[$relatedId] = true;
            $exists = self::db()->query("SELECT `id` FROM `{$products}` WHERE `id` = {$relatedId} LIMIT 1");
            if (empty($exists[0])) continue;
            self::db()->query("INSERT INTO `{$table}` (`product_id`, `related_product_id`, `position`)
                VALUES ({$productId}, {$relatedId}, {$position})");
            $position++;
            if ($position >= 20) break;
        }
        return true;
    }

    public static function relatedProducts($productId, $limit = 4)
    {
        $limit = max(1, min(12, (int)$limit));
        $ids = array_slice(self::relatedProductIds($productId), 0, $limit);
        if (!$ids) return array();

        $result = self::getProducts(array('ids' => $ids, 'limit' => count($ids)));
        $byId = array();
        foreach ($result['items'] as $item) $byId[(int)$item['id']] = $item;

        $items = array();
        foreach ($ids as $id) {
            if (isset($byId[$id])) $items[] = $byId[$id];
        }
        return $items;
    }

    public static function similarProducts($product, $limit = 4)
    {
        $limit = max(1, min(12, (int)$limit));
        $productId = (int)(is_object($product) ? $product->getId() : (isset($product['id']) ? $product['id'] : 0));
        $categoryId = (int)(is_object($product) ? $product->getCategory_id() : (isset($product['category_id']) ? $product['category_id'] : 0));
        if ($productId < 1 || $categoryId < 1) return array();

        $result = self::getProducts(array(
            'category_ids' => array($categoryId),
            'limit' => $limit + 1,
        ));
        $items = array();
        foreach ($result['items'] as $item) {
            if ((int)$item['id'] === $productId) continue;
            $items[] = $item;
            if (count($items) >= $limit) break;
        }
        return $items;
    }

    const RECENT_KEY = 'shop_recently_viewed';

    /* ---------------------------------------------------------------------
     * Funnel analytics
     * ------------------------------------------------------------------ */

    /** @var bool */
    private static $funnelTableReady = false;

    public static function ensureFunnelTable()
    {
        if (self::$funnelTableReady) return true;

        try {
            self::db()->query("CREATE TABLE IF NOT EXISTS `" . self::table('funnel_events') . "` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `event` VARCHAR(32) NOT NULL DEFAULT '',
                `session_key` VARCHAR(64) NOT NULL DEFAULT '',
                `user_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `product_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `order_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `created_at` DATETIME DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `event_created` (`event`, `created_at`),
                KEY `session_key` (`session_key`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Throwable $e) {
            return false;
        }

        self::$funnelTableReady = true;
        return true;
    }

    /**
     * Request-driven funnel counter. Failures never break a storefront page.
     */
    public static function recordFunnelEvent($event, $data = array())
    {
        $event = preg_replace('/[^a-z_]/', '', strtolower((string)$event));
        if ($event === '' || !self::ensureFunnelTable()) return false;

        // Crawlers would inflate the funnel and never convert.
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? strtolower((string)$_SERVER['HTTP_USER_AGENT']) : '';
        if ($ua !== '') {
            foreach (array('bot', 'crawler', 'spider', 'slurp', 'headlesschrome', 'phantomjs',
                'python-requests', 'curl/', 'wget', 'go-http-client', 'okhttp') as $needle) {
                if (strpos($ua, $needle) !== false) return false;
            }
        }

        $data = (array)$data;
        $session = session_id();
        if ($session === '') $session = 'anon';
        try {
            self::db()->save('shop_funnel_events', array(
                'event' => $event,
                'session_key' => substr($session, 0, 64),
                'user_id' => (int)(isset($data['user_id']) ? $data['user_id'] : 0),
                'product_id' => (int)(isset($data['product_id']) ? $data['product_id'] : 0),
                'order_id' => (int)(isset($data['order_id']) ? $data['order_id'] : 0),
                'created_at' => date('Y-m-d H:i:s'),
            ));
            if (mt_rand(1, 100) === 1) {
                self::db()->query("DELETE FROM `" . self::table('funnel_events') . "`
                    WHERE `created_at` < DATE_SUB(NOW(), INTERVAL 180 DAY)");
            }
            return true;
        } catch (Throwable $e) {
            error_log('Shop funnel event failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * @return array ordered funnel steps with unique sessions and rates.
     */
    public static function funnelReport($days = 30)
    {
        $days = max(1, min(365, (int)$days));
        $steps = array(
            'product_view' => 'Просмотры товаров',
            'add_to_cart' => 'Добавили в корзину',
            'checkout_start' => 'Начали оформление',
            'order_created' => 'Оформили заказ',
        );
        $report = array();
        foreach ($steps as $event => $title) {
            $report[$event] = array('event' => $event, 'title' => $title, 'sessions' => 0, 'rate' => 0.0);
        }
        if (!self::ensureFunnelTable()) return $report;

        $since = date('Y-m-d H:i:s', strtotime('-' . ($days - 1) . ' days'));
        $rows = self::db()->query("SELECT `event`, COUNT(DISTINCT `session_key`) AS sessions
            FROM `" . self::table('funnel_events') . "`
            WHERE `created_at` >= '" . self::esc($since) . "'
            GROUP BY `event`");
        foreach ($rows as $row) {
            $event = (string)$row['event'];
            if (isset($report[$event])) $report[$event]['sessions'] = (int)$row['sessions'];
        }

        $first = $report['product_view']['sessions'];
        $previous = 0;
        foreach ($report as &$step) {
            if ($first > 0) $step['rate'] = round($step['sessions'] / $first * 100, 1);
            $step['step_rate'] = $previous > 0 ? round($step['sessions'] / $previous * 100, 1) : 0.0;
            $previous = $step['sessions'];
        }
        unset($step);
        return $report;
    }

    /**
     * Best viewed products with their cart conversion for the same period.
     */
    public static function funnelProducts($days = 30, $limit = 8)
    {
        $days = max(1, min(365, (int)$days));
        $limit = max(1, min(50, (int)$limit));
        if (!self::ensureFunnelTable()) return array();

        $events = self::table('funnel_events');
        $products = self::table('products');
        $since = date('Y-m-d H:i:s', strtotime('-' . ($days - 1) . ' days'));
        $rows = self::db()->query("SELECT e.`product_id`,
                COALESCE(p.`title`, CONCAT('#', e.`product_id`)) AS product_title,
                COUNT(DISTINCT CASE WHEN e.`event` = 'product_view' THEN e.`session_key` END) AS views,
                COUNT(DISTINCT CASE WHEN e.`event` = 'add_to_cart' THEN e.`session_key` END) AS carts
            FROM `{$events}` e
            LEFT JOIN `{$products}` p ON p.`id` = e.`product_id`
            WHERE e.`created_at` >= '" . self::esc($since) . "' AND e.`product_id` > 0
            GROUP BY e.`product_id`, p.`title`
            HAVING views > 0 OR carts > 0
            ORDER BY views DESC, carts DESC
            LIMIT {$limit}");

        $items = array();
        foreach ($rows as $row) {
            $views = (int)$row['views'];
            $carts = (int)$row['carts'];
            $items[] = array(
                'product_id' => (int)$row['product_id'],
                'product_title' => (string)$row['product_title'],
                'views' => $views,
                'carts' => $carts,
                'rate' => $views > 0 ? round($carts / $views * 100, 1) : 0.0,
            );
        }
        return $items;
    }

    public static function recordProductView($productId)
    {
        $productId = (int)$productId;
        if ($productId < 1) return;

        $list = isset($_SESSION[self::RECENT_KEY]) && is_array($_SESSION[self::RECENT_KEY])
            ? $_SESSION[self::RECENT_KEY] : array();
        $list = array_values(array_filter(array_map('intval', $list)));
        $list = array_values(array_diff($list, array($productId)));
        array_unshift($list, $productId);
        $_SESSION[self::RECENT_KEY] = array_slice($list, 0, 20);
    }

    public static function getRecentlyViewed($excludeId = 0, $limit = 4)
    {
        $limit = max(1, min(20, (int)$limit));
        $ids = isset($_SESSION[self::RECENT_KEY]) && is_array($_SESSION[self::RECENT_KEY])
            ? array_values(array_filter(array_map('intval', $_SESSION[self::RECENT_KEY]))) : array();
        $ids = array_values(array_diff($ids, array((int)$excludeId)));
        if (!$ids) return array();

        $result = self::getProducts(array('ids' => $ids, 'limit' => count($ids)));
        $byId = array();
        foreach ($result['items'] as $item) $byId[(int)$item['id']] = $item;

        $items = array();
        foreach ($ids as $id) {
            if (!isset($byId[$id])) continue;
            $items[] = $byId[$id];
            if (count($items) >= $limit) break;
        }
        return $items;
    }

    /* ---------------------------------------------------------------------
     * Request helpers
     * ------------------------------------------------------------------ */

    public static function csrfToken()
    {
        if (empty($_SESSION[self::CSRF_KEY])) {
            try {
                $_SESSION[self::CSRF_KEY] = bin2hex(random_bytes(24));
            } catch (Throwable $e) {
                $_SESSION[self::CSRF_KEY] = md5(uniqid('shop', true));
            }
        }
        return $_SESSION[self::CSRF_KEY];
    }

    public static function csrfCheck($token)
    {
        return !empty($_SESSION[self::CSRF_KEY]) && is_string($token)
            && hash_equals($_SESSION[self::CSRF_KEY], $token);
    }

    public static function flash($type, $message)
    {
        $_SESSION[self::FLASH_KEY] = array('type' => $type, 'message' => $message);
    }

    public static function getFlash()
    {
        if (empty($_SESSION[self::FLASH_KEY])) return null;
        $flash = $_SESSION[self::FLASH_KEY];
        unset($_SESSION[self::FLASH_KEY]);
        return $flash;
    }

    public static function currentUser()
    {
        if (empty($_SESSION['user'])) {
            return array('id' => 0, 'name' => '', 'email' => '', 'phone' => '');
        }
        $user = $_SESSION['user'];
        return array(
            'id' => !empty($user['id']) ? (int)$user['id'] : 0,
            'name' => !empty($user['name']) ? (string)$user['name'] : trim(
                (isset($user['first_name']) ? $user['first_name'] : '') . ' ' .
                (isset($user['last_name']) ? $user['last_name'] : '')
            ),
            'email' => !empty($user['email']) ? (string)$user['email'] : '',
            'phone' => !empty($user['telephone']) ? (string)$user['telephone'] : '',
        );
    }

    /* ---------------------------------------------------------------------
     * Favorites
     * ------------------------------------------------------------------ */

    /**
     * Older installations do not have the favorites table yet: create it on
     * first use instead of requiring a module reinstall.
     */
    public static function ensureFavoritesTable()
    {
        return ShopFavorites::ensureTable();
    }

    public static function favoriteIds($userId)
    {
        return ShopFavorites::ids($userId);
    }

    public static function isFavorite($userId, $productId)
    {
        return ShopFavorites::contains($userId, $productId);
    }

    public static function addFavorite($userId, $productId)
    {
        return ShopFavorites::add($userId, $productId);
    }

    public static function removeFavorite($userId, $productId)
    {
        return ShopFavorites::remove($userId, $productId);
    }

    public static function getFavorites($userId, $limit = 100)
    {
        return ShopFavorites::products($userId, $limit);
    }

    /* ---------------------------------------------------------------------
     * Product CSV import
     * ------------------------------------------------------------------ */

    /**
     * Product rows in the same column shape as importProductsCsv().
     */
    public static function exportProductsCsv()
    {
        $currency = self::activeCurrency();
        $code = self::esc($currency['code']);
        $products = self::table('products');
        $categories = self::table('categories');
        $variants = self::table('variants');
        $prices = self::table('variant_prices');

        $rows = self::db()->query("SELECT p.`id`, p.`title`, p.`clean_url_title`, p.`short_description`,
                p.`description`, p.`active`, p.`featured`, p.`meta_title`, p.`meta_description`,
                p.`meta_keywords`, c.`title` AS category_title,
                (SELECT MIN(vp.`price`) FROM `{$variants}` v
                    INNER JOIN `{$prices}` vp ON vp.`variant_id` = v.`id`
                    WHERE v.`product_id` = p.`id` AND v.`active` = 1
                        AND vp.`currency_code` = '{$code}' AND vp.`price` > 0) AS min_price,
                (SELECT v.`sku` FROM `{$variants}` v
                    INNER JOIN `{$prices}` vp ON vp.`variant_id` = v.`id`
                    WHERE v.`product_id` = p.`id` AND v.`active` = 1
                        AND vp.`currency_code` = '{$code}'
                    ORDER BY vp.`price` ASC, v.`id` ASC LIMIT 1) AS sku,
                (SELECT COALESCE(SUM(v.`stock`), 0) FROM `{$variants}` v
                    WHERE v.`product_id` = p.`id` AND v.`active` = 1) AS stock_total
            FROM `{$products}` p
            LEFT JOIN `{$categories}` c ON c.`id` = p.`category_id`
            ORDER BY p.`id` ASC");

        $items = array();
        foreach ($rows as $row) {
            $items[] = array(
                'title' => (string)$row['title'],
                'slug' => (string)$row['clean_url_title'],
                'category' => (string)$row['category_title'],
                'short_description' => (string)$row['short_description'],
                'description' => (string)$row['description'],
                'sku' => (string)$row['sku'],
                'price' => (float)$row['min_price'] > 0
                    ? number_format((float)$row['min_price'], 2, '.', '') : '',
                'stock' => (int)$row['stock_total'],
                'active' => (int)!empty($row['active']),
                'featured' => (int)!empty($row['featured']),
                'meta_title' => (string)$row['meta_title'],
                'meta_description' => (string)$row['meta_description'],
                'meta_keywords' => (string)$row['meta_keywords'],
            );
        }
        return $items;
    }

    /**
     * One row per variant and currency. Unlike exportProductsCsv() it keeps
     * every variant, so prices and stock can be edited in a spreadsheet.
     */
    public static function exportVariantsCsv()
    {
        $products = self::table('products');
        $variants = self::table('variants');
        $prices = self::table('variant_prices');

        $rows = self::db()->query("SELECT p.`title` AS product_title, p.`clean_url_title` AS product_slug,
                v.`id` AS variant_id, v.`title` AS variant_title, v.`sku`, v.`stock`,
                v.`active` AS variant_active, v.`position` AS variant_position,
                vp.`currency_code`, vp.`price`, vp.`compare_price`
            FROM `{$variants}` v
            INNER JOIN `{$products}` p ON p.`id` = v.`product_id`
            INNER JOIN `{$prices}` vp ON vp.`variant_id` = v.`id`
            ORDER BY p.`id` ASC, v.`position` ASC, v.`id` ASC, vp.`currency_code` ASC");

        $items = array();
        foreach ($rows as $row) {
            $items[] = array(
                'product_slug' => (string)$row['product_slug'],
                'product_title' => (string)$row['product_title'],
                'variant_id' => (int)$row['variant_id'],
                'variant_title' => (string)$row['variant_title'],
                'sku' => (string)$row['sku'],
                'stock' => (int)$row['stock'],
                'active' => (int)!empty($row['variant_active']),
                'position' => (int)$row['variant_position'],
                'currency' => (string)$row['currency_code'],
                'price' => number_format((float)$row['price'], 2, '.', ''),
                'compare_price' => (float)$row['compare_price'] > 0
                    ? number_format((float)$row['compare_price'], 2, '.', '') : '',
            );
        }
        return $items;
    }

    private static function variantCsvHeaderMap($header)
    {
        return ShopCsvParser::variantHeaderMap($header);
    }

    /**
     * Apply the variant export back. Rows are matched by variant_id; a column
     * missing from the header leaves that variant field untouched.
     */
    public static function importVariantsCsv($path)
    {
        $result = array('updated' => 0, 'skipped' => 0, 'errors' => array());
        $handle = @fopen((string)$path, 'rb');
        if (!$handle) {
            $result['errors'][] = 'Не удалось открыть файл.';
            return $result;
        }

        $firstLine = (string)fgets($handle);
        $delimiter = substr_count($firstLine, ';') >= substr_count($firstLine, ',') ? ';' : ',';
        rewind($handle);

        $variants = self::table('variants');
        $prices = self::table('variant_prices');
        $currencies = self::currencies(false);
        $fallback = self::activeCurrency();
        $now = date('Y-m-d H:i:s');

        $known = array();
        foreach (self::db()->query("SELECT `id` FROM `{$variants}`") as $row) {
            $known[(int)$row['id']] = true;
        }

        $map = null;
        $lines = 0;
        while (($row = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
            if ($map === null) {
                $map = self::variantCsvHeaderMap($row);
                if (!isset($map['variant_id'])) {
                    $result['errors'][] = 'В первой строке нет колонки variant_id.';
                    break;
                }
                continue;
            }

            $lines++;
            if ($lines > 5000) {
                $result['errors'][] = 'Обработаны первые 5000 строк.';
                break;
            }

            $cell = function ($field) use ($row, $map) {
                return isset($map[$field], $row[$map[$field]]) ? trim((string)$row[$map[$field]]) : null;
            };

            $variantId = (int)$cell('variant_id');
            if ($variantId < 1 || !isset($known[$variantId])) {
                $result['skipped']++;
                continue;
            }

            $update = array();
            $sku = $cell('sku');
            if ($sku !== null) $update['sku'] = mb_substr($sku, 0, 64);
            $stock = $cell('stock');
            if ($stock !== null) $update['stock'] = max(0, (int)$stock);
            $active = $cell('active');
            if ($active !== null && $active !== '') $update['active'] = self::csvFlag($active);
            if ($update) {
                $update['updated_at'] = $now;
                self::db()->save('shop_variants', $update, array('id' => $variantId));
            }

            $priceValue = $cell('price');
            if ($priceValue !== null && $priceValue !== '') {
                $code = strtoupper(trim((string)$cell('currency')));
                if ($code === '' || !isset($currencies[$code])) $code = $fallback['code'];
                $price = (float)str_replace(',', '.', $priceValue);
                if ($price < 0) $price = 0;
                $hasCompare = isset($map['compare_price']);
                $compare = $hasCompare ? (float)str_replace(',', '.', (string)$cell('compare_price')) : 0.0;
                if ($compare <= $price) $compare = 0;
                $priceSql = number_format($price, 2, '.', '');
                $compareSql = number_format($compare, 2, '.', '');
                $updateCompare = $hasCompare
                    ? ", `compare_price` = VALUES(`compare_price`)"
                    : '';
                self::db()->query("INSERT INTO `{$prices}` (`variant_id`, `currency_code`, `price`, `compare_price`)
                    VALUES ({$variantId}, '" . self::esc($code) . "', {$priceSql}, {$compareSql})
                    ON DUPLICATE KEY UPDATE `price` = VALUES(`price`){$updateCompare}");
            }

            $result['updated']++;
        }
        fclose($handle);
        return $result;
    }

    private static function csvHeaderMap($header)
    {
        $aliases = array(
            'title' => array('title', 'название', 'наименование'),
            'slug' => array('slug', 'url', 'clean_url_title', 'ссылка'),
            'category' => array('category', 'категория'),
            'short_description' => array('short_description', 'краткое описание', 'краткое_описание'),
            'description' => array('description', 'описание'),
            'sku' => array('sku', 'артикул'),
            'price' => array('price', 'цена'),
            'stock' => array('stock', 'остаток', 'количество'),
            'active' => array('active', 'активен', 'активность'),
            'featured' => array('featured', 'рекомендуемый', 'рекомендовать'),
            'meta_title' => array('meta_title', 'meta title', 'meta-заголовок'),
            'meta_description' => array('meta_description', 'meta description', 'meta-описание'),
            'meta_keywords' => array('meta_keywords', 'meta keywords', 'meta-ключевые слова'),
        );
        return ShopCsvParser::headerMap($header, $aliases);
    }

    private static function csvFlag($value)
    {
        return ShopCsvParser::flag($value);
    }

    /**
     * Import or update products from a CSV file. Existing products match by slug.
     */
    public static function importProductsCsv($path)
    {
        $result = array('created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => array());
        $handle = @fopen((string)$path, 'rb');
        if (!$handle) {
            $result['errors'][] = 'Не удалось открыть файл.';
            return $result;
        }

        $firstLine = (string)fgets($handle);
        $delimiter = substr_count($firstLine, ';') >= substr_count($firstLine, ',') ? ';' : ',';
        rewind($handle);

        $map = null;
        $rowNumber = 0;
        $now = date('Y-m-d H:i:s');
        $currency = self::activeCurrency();
        $products = self::table('products');
        $variants = self::table('variants');
        $prices = self::table('variant_prices');
        $categories = self::table('categories');

        while (($row = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
            $rowNumber++;
            if ($map === null) {
                $map = self::csvHeaderMap($row);
                if (!isset($map['title'])) {
                    $result['errors'][] = 'В первой строке нет колонки с названием товара.';
                    break;
                }
                continue;
            }

            $empty = true;
            foreach ($row as $value) {
                if (trim((string)$value) !== '') $empty = false;
            }
            if ($empty) continue;
            if ($result['created'] + $result['updated'] + $result['skipped'] >= 2000) {
                $result['errors'][] = 'Обработаны первые 2000 строк.';
                break;
            }

            $cell = function ($field) use ($row, $map) {
                return isset($map[$field], $row[$map[$field]]) ? trim((string)$row[$map[$field]]) : '';
            };

            try {
                $title = $cell('title');
                if ($title === '') throw new RuntimeException('пустое название');

                $slugSource = $cell('slug');
                if ($slugSource === '') $slugSource = $title;
                $slug = self::slugify($slugSource);
                if ($slug === '') throw new RuntimeException('пустой URL');

                $existingRows = self::db()->query("SELECT * FROM `{$products}`
                    WHERE `clean_url_title` = '" . self::esc($slug) . "' LIMIT 1");
                $existing = isset($existingRows[0]) ? $existingRows[0] : null;

                $categoryId = 0;
                $categoryValue = $cell('category');
                if ($categoryValue !== '') {
                    if (ctype_digit($categoryValue)) {
                        $categoryId = (int)$categoryValue;
                    } else {
                        $categoryRows = self::db()->query("SELECT `id` FROM `{$categories}`
                            WHERE `title` = '" . self::esc($categoryValue) . "'
                                OR `clean_url_title` = '" . self::esc(self::slugify($categoryValue)) . "'
                            LIMIT 1");
                        if (isset($categoryRows[0])) $categoryId = (int)$categoryRows[0]['id'];
                    }
                }

                $data = array(
                    'title' => $title,
                    'clean_url_title' => $existing ? $existing['clean_url_title'] : self::uniqueSlug('shop_products', $slugSource),
                    'updated_at' => $now,
                );
                if ($categoryValue !== '' || !$existing) $data['category_id'] = $categoryId;
                foreach (array('short_description', 'description', 'meta_title', 'meta_description', 'meta_keywords') as $field) {
                    if (isset($map[$field], $row[$map[$field]])) {
                        $data[$field] = (string)$row[$map[$field]];
                    }
                }
                if (isset($map['active']) && $cell('active') !== '') $data['active'] = self::csvFlag($cell('active'));
                if (isset($map['featured']) && $cell('featured') !== '') $data['featured'] = self::csvFlag($cell('featured'));

                if ($existing) {
                    $data['id'] = (int)$existing['id'];
                    self::db()->save('shop_products', $data);
                    $productId = (int)$existing['id'];
                } else {
                    $data['image'] = '';
                    $data['position'] = 0;
                    $data['views'] = 0;
                    $data['created_at'] = $now;
                    $productId = (int)self::db()->save('shop_products', $data);
                    if ($productId < 1) throw new RuntimeException('товар не сохранён');
                }

                $variantRows = self::db()->query("SELECT `id` FROM `{$variants}` WHERE `product_id` = {$productId}
                    ORDER BY `position` ASC, `id` ASC LIMIT 1");
                if (empty($variantRows[0])) {
                    // Imported products have no attributes, so one default variant is enough.
                    $variantId = (int)self::db()->save('shop_variants', array(
                        'product_id' => $productId,
                        'title' => 'Стандарт',
                        'sku' => '',
                        'stock' => 0,
                        'image' => '',
                        'active' => 1,
                        'position' => 0,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ));
                    if ($variantId < 1) throw new RuntimeException('вариант не создан');
                } else {
                    $variantId = (int)$variantRows[0]['id'];
                }

                self::db()->save('shop_variants', array(
                    'sku' => mb_substr($cell('sku'), 0, 64),
                    'stock' => max(0, (int)$cell('stock')),
                    'active' => 1,
                    'updated_at' => $now,
                ), array('id' => $variantId));

                $price = (float)str_replace(array(' ', ','), array('', '.'), $cell('price'));
                if ($price < 0) $price = 0.0;
                self::db()->query("INSERT INTO `{$prices}` (`variant_id`, `currency_code`, `price`)
                    VALUES ({$variantId}, '" . self::esc($currency['code']) . "', " . number_format($price, 2, '.', '') . ")
                    ON DUPLICATE KEY UPDATE `price` = VALUES(`price`)");

                if ($existing) $result['updated']++; else $result['created']++;
            } catch (Throwable $e) {
                $result['skipped']++;
                if (count($result['errors']) < 20) {
                    $result['errors'][] = 'Строка ' . $rowNumber . ': ' . $e->getMessage();
                }
            }
        }
        fclose($handle);

        return $result;
    }

    /* ---------------------------------------------------------------------
     * Warehouse, stock journal and reservations
     * ------------------------------------------------------------------ */

    /** @var bool */
    private static $inventoryReady = false;

    public static function ensureInventoryTables()
    {
        if (self::$inventoryReady) return true;
        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                self::db()->query("CREATE TABLE IF NOT EXISTS `" . self::table('warehouses') . "` (
                    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `title` VARCHAR(255) NOT NULL DEFAULT '',
                    `active` TINYINT(1) NOT NULL DEFAULT 1,
                    `position` INT NOT NULL DEFAULT 0,
                    `created_at` DATETIME DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    KEY `active_position` (`active`, `position`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                self::db()->query("CREATE TABLE IF NOT EXISTS `" . self::table('warehouse_stock') . "` (
                    `warehouse_id` INT UNSIGNED NOT NULL DEFAULT 1,
                    `variant_id` INT UNSIGNED NOT NULL DEFAULT 0,
                    `stock` INT NOT NULL DEFAULT 0,
                    PRIMARY KEY (`warehouse_id`, `variant_id`),
                    KEY `variant_id` (`variant_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                self::db()->query("CREATE TABLE IF NOT EXISTS `" . self::table('stock_movements') . "` (
                    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `variant_id` INT UNSIGNED NOT NULL DEFAULT 0,
                    `warehouse_id` INT UNSIGNED NOT NULL DEFAULT 0,
                    `delta` INT NOT NULL DEFAULT 0,
                    `stock_after` INT NOT NULL DEFAULT 0,
                    `reason` VARCHAR(32) NOT NULL DEFAULT '',
                    `order_id` INT UNSIGNED NOT NULL DEFAULT 0,
                    `return_id` INT UNSIGNED NOT NULL DEFAULT 0,
                    `user_id` INT UNSIGNED NOT NULL DEFAULT 0,
                    `comment` VARCHAR(255) NOT NULL DEFAULT '',
                    `created_at` DATETIME DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    KEY `variant_created` (`variant_id`, `created_at`),
                    KEY `order_id` (`order_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

                $now = date('Y-m-d H:i:s');
                self::db()->query("INSERT IGNORE INTO `" . self::table('warehouses') . "`
                    (`id`, `title`, `active`, `position`, `created_at`)
                    VALUES (1, 'Основной склад', 1, 10, '" . self::esc($now) . "')");
                self::db()->query("INSERT IGNORE INTO `" . self::table('warehouse_stock') . "`
                    (`warehouse_id`, `variant_id`, `stock`)
                    SELECT 1, v.`id`, v.`stock` FROM `" . self::table('variants') . "` v
                    LEFT JOIN `" . self::table('warehouse_stock') . "` ws
                        ON ws.`variant_id` = v.`id` AND ws.`warehouse_id` = 1
                    WHERE ws.`variant_id` IS NULL");
                self::$inventoryReady = true;
                return true;
            } catch (Throwable $e) {
                // Concurrent first use can race on CREATE TABLE IF NOT EXISTS.
                if ($attempt < 2) usleep(20000);
            }
        }
        return false;
    }

    public static function warehouses($activeOnly = false)
    {
        if (!self::ensureInventoryTables()) return array();
        $where = $activeOnly ? ' WHERE `active` = 1' : '';
        $rows = self::db()->query("SELECT * FROM `" . self::table('warehouses') . "`{$where}
            ORDER BY `position` ASC, `id` ASC");
        foreach ($rows as &$row) {
            $row['id'] = (int)$row['id'];
            $row['active'] = (int)$row['active'];
        }
        unset($row);
        return $rows;
    }

    public static function defaultWarehouseId()
    {
        $rows = self::db()->query("SELECT `id` FROM `" . self::table('warehouses') . "`
            WHERE `active` = 1 ORDER BY `position` ASC, `id` ASC LIMIT 1");
        return !empty($rows[0]['id']) ? (int)$rows[0]['id'] : 1;
    }

    public static function logStockMovement($variantId, $warehouseId, $delta, $stockAfter, $reason,
        $orderId = 0, $returnId = 0, $comment = '')
    {
        try {
            self::db()->save('shop_stock_movements', array(
                'variant_id' => (int)$variantId,
                'warehouse_id' => (int)$warehouseId,
                'delta' => (int)$delta,
                'stock_after' => (int)$stockAfter,
                'reason' => mb_substr((string)$reason, 0, 32),
                'order_id' => (int)$orderId,
                'return_id' => (int)$returnId,
                'user_id' => !empty($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : 0,
                'comment' => mb_substr((string)$comment, 0, 255),
                'created_at' => date('Y-m-d H:i:s'),
            ));
            return true;
        } catch (Throwable $e) {
            error_log('Shop stock movement write failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Change the aggregate variant stock and keep the warehouse allocation in
     * sync. Negative deltas are taken from warehouses with stock first.
     */
    public static function changeStock($variantId, $delta, $reason, $orderId = 0,
        $returnId = 0, $warehouseId = 0, $comment = '')
    {
        $variantId = (int)$variantId;
        $delta = (int)$delta;
        if ($variantId < 1 || $delta === 0) return false;
        if (!self::ensureInventoryTables()) return false;

        $rows = self::db()->query("SELECT `stock` FROM `" . self::table('variants') . "`
            WHERE `id` = {$variantId} FOR UPDATE");
        if (empty($rows[0])) return false;
        $before = (int)$rows[0]['stock'];
        $after = max(0, $before + $delta);
        $actual = $after - $before;
        if ($actual === 0) return true;

        self::db()->query("UPDATE `" . self::table('variants') . "`
            SET `stock` = {$after}, `updated_at` = '" . self::esc(date('Y-m-d H:i:s')) . "'
            WHERE `id` = {$variantId}");

        $defaultId = self::defaultWarehouseId();
        if ($actual > 0) {
            $target = (int)$warehouseId > 0 ? (int)$warehouseId : $defaultId;
            self::db()->query("INSERT INTO `" . self::table('warehouse_stock') . "`
                (`warehouse_id`, `variant_id`, `stock`) VALUES ({$target}, {$variantId}, {$actual})
                ON DUPLICATE KEY UPDATE `stock` = `stock` + {$actual}");
        } else {
            $need = abs($actual);
            $stockRows = self::db()->query("SELECT `warehouse_id`, `stock`
                FROM `" . self::table('warehouse_stock') . "`
                WHERE `variant_id` = {$variantId} AND `stock` > 0
                ORDER BY `warehouse_id` ASC FOR UPDATE");
            foreach ($stockRows as $row) {
                if ($need < 1) break;
                $take = min($need, (int)$row['stock']);
                self::db()->query("UPDATE `" . self::table('warehouse_stock') . "`
                    SET `stock` = `stock` - {$take}
                    WHERE `warehouse_id` = " . (int)$row['warehouse_id'] . " AND `variant_id` = {$variantId}");
                $need -= $take;
            }
            // Older rows may predate the warehouse mirror; keep the allocation
            // from going negative by draining the default warehouse last.
            if ($need > 0) {
                self::db()->query("UPDATE `" . self::table('warehouse_stock') . "`
                    SET `stock` = GREATEST(`stock` - {$need}, 0)
                    WHERE `warehouse_id` = {$defaultId} AND `variant_id` = {$variantId}");
            }
        }

        self::logStockMovement($variantId, (int)$warehouseId > 0 ? (int)$warehouseId : $defaultId,
            $actual, $after, $reason, $orderId, $returnId, $comment);
        return $after;
    }

    public static function warehouseStockRows($variantId)
    {
        if (!self::ensureInventoryTables()) return array();
        return self::db()->query("SELECT ws.*, w.`title`
            FROM `" . self::table('warehouse_stock') . "` ws
            INNER JOIN `" . self::table('warehouses') . "` w ON w.`id` = ws.`warehouse_id`
            WHERE ws.`variant_id` = " . (int)$variantId . "
            ORDER BY w.`position` ASC, w.`id` ASC");
    }

    public static function setWarehouseStock($warehouseId, $variantId, $stock, $reason = 'adjust', $comment = '')
    {
        $warehouseId = (int)$warehouseId;
        $variantId = (int)$variantId;
        $stock = max(0, (int)$stock);
        if ($warehouseId < 1 || $variantId < 1) return false;
        if (!self::ensureInventoryTables()) return false;

        try {
            self::db()->query('START TRANSACTION');
            $rows = self::db()->query("SELECT `stock` FROM `" . self::table('warehouse_stock') . "`
                WHERE `warehouse_id` = {$warehouseId} AND `variant_id` = {$variantId} FOR UPDATE");
            $old = !empty($rows[0]) ? (int)$rows[0]['stock'] : 0;
            if ($old !== $stock) {
                self::db()->query("INSERT INTO `" . self::table('warehouse_stock') . "`
                    (`warehouse_id`, `variant_id`, `stock`) VALUES ({$warehouseId}, {$variantId}, {$stock})
                    ON DUPLICATE KEY UPDATE `stock` = {$stock}");
                $delta = $stock - $old;
                $variant = self::db()->query("SELECT `stock` FROM `" . self::table('variants') . "`
                    WHERE `id` = {$variantId} FOR UPDATE");
                $after = max(0, (int)(isset($variant[0]['stock']) ? $variant[0]['stock'] : 0) + $delta);
                self::db()->query("UPDATE `" . self::table('variants') . "`
                    SET `stock` = {$after}, `updated_at` = '" . self::esc(date('Y-m-d H:i:s')) . "'
                    WHERE `id` = {$variantId}");
                self::logStockMovement($variantId, $warehouseId, $delta, $after, $reason, 0, 0, $comment);
            }
            self::db()->query('COMMIT');
            return true;
        } catch (Throwable $e) {
            try { self::db()->query('ROLLBACK'); } catch (Throwable $ignored) {}
            error_log('Shop warehouse stock update failed: ' . $e->getMessage());
            return false;
        }
    }

    public static function stockMovements($limit = 200)
    {
        if (!self::ensureInventoryTables()) return array();
        $limit = max(1, min(1000, (int)$limit));
        $rows = self::db()->query("SELECT m.*, v.`title` AS variant_title, v.`sku`,
                p.`title` AS product_title, w.`title` AS warehouse_title
            FROM `" . self::table('stock_movements') . "` m
            LEFT JOIN `" . self::table('variants') . "` v ON v.`id` = m.`variant_id`
            LEFT JOIN `" . self::table('products') . "` p ON p.`id` = v.`product_id`
            LEFT JOIN `" . self::table('warehouses') . "` w ON w.`id` = m.`warehouse_id`
            ORDER BY m.`id` DESC LIMIT {$limit}");
        foreach ($rows as &$row) {
            $row['delta'] = (int)$row['delta'];
            $row['created_at_formatted'] = self::formatDate($row['created_at']);
        }
        unset($row);
        return $rows;
    }

    /**
     * Explicit reservation deadline. Stock is already deducted when an order
     * is placed, so the deadline tells expireUnpaidOrders() when to release.
     */
    public static function ensureOrderBusinessColumns()
    {
        $orders = self::table('orders');
        foreach (array(
            'reserved_until' => "DATETIME DEFAULT NULL",
            'loyalty_discount' => "DECIMAL(12,2) NOT NULL DEFAULT 0.00",
            'bonus_spent' => "DECIMAL(12,2) NOT NULL DEFAULT 0.00",
            'bonus_earned' => "DECIMAL(12,2) NOT NULL DEFAULT 0.00",
            'loyalty_credited' => "TINYINT(1) NOT NULL DEFAULT 0",
            'bonus_refunded' => "TINYINT(1) NOT NULL DEFAULT 0",
            'referral_code' => "VARCHAR(64) NOT NULL DEFAULT ''",
        ) as $column => $definition) {
            try {
                self::db()->query("SELECT `{$column}` FROM `{$orders}` LIMIT 1");
            } catch (Throwable $e) {
                try {
                    self::db()->query("ALTER TABLE `{$orders}` ADD COLUMN `{$column}` {$definition}");
                } catch (Throwable $alterError) {
                }
            }
        }
        return true;
    }

    public static function reservationDeadline($from = null)
    {
        $hours = (int)self::cfg('order_ttl_hours', 72);
        if ($hours < 1) return null;
        $time = $from ? strtotime($from) : time();
        if (!$time) $time = time();
        return date('Y-m-d H:i:s', $time + $hours * 3600);
    }

    /* ---------------------------------------------------------------------
     * Returns and refunds
     * ------------------------------------------------------------------ */

    /** @var bool */
    private static $returnTablesReady = false;

    public static function ensureReturnTables()
    {
        if (self::$returnTablesReady) return true;
        try {
            self::db()->query("CREATE TABLE IF NOT EXISTS `" . self::table('order_returns') . "` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `order_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `status` VARCHAR(16) NOT NULL DEFAULT 'new',
                `reason` VARCHAR(255) NOT NULL DEFAULT '',
                `comment` TEXT,
                `amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                `stock_returned` TINYINT(1) NOT NULL DEFAULT 0,
                `created_by` INT UNSIGNED NOT NULL DEFAULT 0,
                `created_by_name` VARCHAR(255) NOT NULL DEFAULT '',
                `created_at` DATETIME DEFAULT NULL,
                `updated_at` DATETIME DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `order_id` (`order_id`),
                KEY `status` (`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            self::db()->query("CREATE TABLE IF NOT EXISTS `" . self::table('order_return_items') . "` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `return_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `order_item_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `variant_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `quantity` INT NOT NULL DEFAULT 0,
                `price` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                `total` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                PRIMARY KEY (`id`),
                KEY `return_id` (`return_id`),
                KEY `variant_id` (`variant_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            self::db()->query("CREATE TABLE IF NOT EXISTS `" . self::table('return_history') . "` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `return_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `status` VARCHAR(16) NOT NULL DEFAULT '',
                `comment` VARCHAR(255) NOT NULL DEFAULT '',
                `user_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `user_name` VARCHAR(255) NOT NULL DEFAULT '',
                `created_at` DATETIME DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `return_id` (`return_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Throwable $e) {
            return false;
        }
        self::$returnTablesReady = true;
        return true;
    }

    public static function returnStatuses()
    {
        return ShopReturnStatus::all();
    }

    public static function returnStatusLabel($status)
    {
        return ShopReturnStatus::label($status);
    }

    public static function logReturnStatus($returnId, $status, $comment = '', $userId = 0, $userName = '')
    {
        try {
            self::db()->save('shop_return_history', array(
                'return_id' => (int)$returnId,
                'status' => (string)$status,
                'comment' => mb_substr((string)$comment, 0, 255),
                'user_id' => (int)$userId,
                'user_name' => mb_substr((string)$userName, 0, 255),
                'created_at' => date('Y-m-d H:i:s'),
            ));
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * @param array $quantities order_item_id => quantity
     */
    public static function createOrderReturn($orderId, $quantities, $reason, $comment = '', &$error = null,
        $userId = 0, $userName = '')
    {
        $error = '';
        $orderId = (int)$orderId;
        if (!self::ensureReturnTables() || !self::ensureOrderBusinessColumns()) {
            $error = 'Не удалось подготовить таблицы возвратов.';
            return false;
        }
        if ($orderId < 1) {
            $error = 'Заказ не найден.';
            return false;
        }
        $desired = array();
        foreach ((array)$quantities as $itemId => $qty) {
            $itemId = (int)$itemId;
            $qty = (int)$qty;
            if ($itemId > 0 && $qty > 0) $desired[$itemId] = $qty;
        }
        if (!$desired) {
            $error = 'Укажите количество товаров к возврату.';
            return false;
        }

        try {
            self::db()->query('START TRANSACTION');
            $orderRows = self::db()->query("SELECT * FROM `" . self::table('orders') . "`
                WHERE `id` = {$orderId} FOR UPDATE");
            $order = $orderRows[0] ?? null;
            if (!$order) throw new RuntimeException('Заказ не найден.');
            if ($order['status'] === 'cancelled') throw new RuntimeException('По отменённому заказу возврат не оформляется.');

            $items = self::db()->query("SELECT * FROM `" . self::table('order_items') . "`
                WHERE `order_id` = {$orderId} ORDER BY `id` ASC");
            $byId = array();
            foreach ($items as $item) $byId[(int)$item['id']] = $item;
            if (!$byId) throw new RuntimeException('В заказе нет позиций.');

            $returned = array();
            $rows = self::db()->query("SELECT ri.`order_item_id`, SUM(ri.`quantity`) AS qty
                FROM `" . self::table('order_return_items') . "` ri
                INNER JOIN `" . self::table('order_returns') . "` r ON r.`id` = ri.`return_id`
                WHERE r.`order_id` = {$orderId} AND r.`status` <> 'cancelled'
                GROUP BY ri.`order_item_id`");
            foreach ($rows as $row) $returned[(int)$row['order_item_id']] = (int)$row['qty'];

            $lines = array();
            $amount = 0.0;
            foreach ($desired as $itemId => $qty) {
                if (empty($byId[$itemId])) throw new RuntimeException('Позиция заказа не найдена.');
                $item = $byId[$itemId];
                $available = (int)$item['quantity'] - (isset($returned[$itemId]) ? $returned[$itemId] : 0);
                if ($qty > $available) {
                    throw new RuntimeException('К возврату доступно меньше: ' . $item['product_title'] . '.');
                }
                $total = round((float)$item['price'] * $qty, 2);
                $amount += $total;
                $lines[] = array(
                    'order_item_id' => $itemId,
                    'variant_id' => (int)$item['variant_id'],
                    'quantity' => $qty,
                    'price' => (float)$item['price'],
                    'total' => $total,
                );
            }

            $now = date('Y-m-d H:i:s');
            $returnId = (int)self::db()->save('shop_order_returns', array(
                'order_id' => $orderId,
                'status' => 'new',
                'reason' => mb_substr((string)$reason, 0, 255),
                'comment' => (string)$comment,
                'amount' => $amount,
                'stock_returned' => 0,
                'created_by' => (int)$userId,
                'created_by_name' => mb_substr((string)$userName, 0, 255),
                'created_at' => $now,
                'updated_at' => $now,
            ));
            if ($returnId < 1) throw new RuntimeException('Возврат не создан.');
            foreach ($lines as $line) {
                $line['return_id'] = $returnId;
                self::db()->save('shop_order_return_items', $line);
            }
            self::logReturnStatus($returnId, 'new', 'Возврат оформлен. ' . (string)$reason, $userId, $userName);
            self::logOrderStatus($orderId, (string)$order['status'],
                'Оформлен возврат №' . $returnId . ' на ' . self::formatPrice($amount, $order['currency_code']),
                $userId, $userName);
            self::db()->query('COMMIT');
            return $returnId;
        } catch (Throwable $e) {
            try { self::db()->query('ROLLBACK'); } catch (Throwable $ignored) {}
            $error = $e instanceof RuntimeException ? $e->getMessage() : 'Не удалось оформить возврат.';
            error_log('Shop return create failed: ' . $e->getMessage());
            return false;
        }
    }

    public static function receiveOrderReturn($returnId, $comment = '', $userId = 0, $userName = '')
    {
        $returnId = (int)$returnId;
        if (!self::ensureReturnTables() || !self::ensureInventoryTables() || $returnId < 1) return false;
        try {
            self::db()->query('START TRANSACTION');
            $rows = self::db()->query("SELECT * FROM `" . self::table('order_returns') . "`
                WHERE `id` = {$returnId} FOR UPDATE");
            $return = $rows[0] ?? null;
            if (!$return || $return['status'] === 'cancelled') throw new RuntimeException('Возврат не найден.');
            if (!empty($return['stock_returned'])) {
                self::db()->query('COMMIT');
                return true;
            }
            $items = self::db()->query("SELECT * FROM `" . self::table('order_return_items') . "`
                WHERE `return_id` = {$returnId} ORDER BY `id` ASC");
            foreach ($items as $item) {
                if ((int)$item['variant_id'] > 0 && (int)$item['quantity'] > 0) {
                    self::changeStock((int)$item['variant_id'], (int)$item['quantity'], 'return',
                        (int)$return['order_id'], $returnId);
                }
            }
            self::db()->query("UPDATE `" . self::table('order_returns') . "`
                SET `status` = 'received', `stock_returned` = 1,
                    `updated_at` = '" . self::esc(date('Y-m-d H:i:s')) . "'
                WHERE `id` = {$returnId}");
            self::logReturnStatus($returnId, 'received', $comment !== '' ? $comment : 'Товары приняты на склад.', $userId, $userName);
            self::db()->query('COMMIT');

            $orderId = (int)$return['order_id'];
            $itemTotals = self::db()->query("SELECT COALESCE(SUM(`quantity`),0) AS qty
                FROM `" . self::table('order_items') . "` WHERE `order_id` = {$orderId}");
            $returned = array_sum(self::returnedQuantities($orderId));
            $ordered = (int)(isset($itemTotals[0]['qty']) ? $itemTotals[0]['qty'] : 0);
            if ($ordered > 0 && $returned >= $ordered) {
                self::setOrderStatus($orderId, 'returned', 'Возврат №' . $returnId . ': все товары приняты на склад.',
                    $userId, $userName);
            }
            return true;
        } catch (Throwable $e) {
            try { self::db()->query('ROLLBACK'); } catch (Throwable $ignored) {}
            error_log('Shop return receive failed: ' . $e->getMessage());
            return false;
        }
    }

    public static function refundOrderReturn($returnId, $comment = '', $userId = 0, $userName = '')
    {
        $returnId = (int)$returnId;
        if (!self::ensureReturnTables() || $returnId < 1) return false;
        try {
            self::db()->query('START TRANSACTION');
            $rows = self::db()->query("SELECT * FROM `" . self::table('order_returns') . "`
                WHERE `id` = {$returnId} FOR UPDATE");
            $return = $rows[0] ?? null;
            if (!$return || $return['status'] === 'cancelled') throw new RuntimeException('Возврат не найден.');
            if (empty($return['stock_returned'])) throw new RuntimeException('Сначала примите товары на склад.');
            if ($return['status'] === 'refunded') {
                self::db()->query('COMMIT');
                return true;
            }
            self::db()->query("UPDATE `" . self::table('order_returns') . "`
                SET `status` = 'refunded', `updated_at` = '" . self::esc(date('Y-m-d H:i:s')) . "'
                WHERE `id` = {$returnId}");
            self::logReturnStatus($returnId, 'refunded', $comment !== '' ? $comment : 'Деньги возвращены покупателю.', $userId, $userName);
            self::db()->query('COMMIT');

            $orderId = (int)$return['order_id'];
            $itemTotals = self::db()->query("SELECT COALESCE(SUM(`quantity`),0) AS qty
                FROM `" . self::table('order_items') . "` WHERE `order_id` = {$orderId}");
            $returned = array_sum(self::returnedQuantities($orderId));
            $ordered = (int)(isset($itemTotals[0]['qty']) ? $itemTotals[0]['qty'] : 0);
            if ($ordered > 0 && $returned >= $ordered) {
                self::setOrderStatus($orderId, 'refunded', 'Возврат №' . $returnId . ': деньги возвращены полностью.',
                    $userId, $userName);
            }
            return true;
        } catch (Throwable $e) {
            try { self::db()->query('ROLLBACK'); } catch (Throwable $ignored) {}
            error_log('Shop return refund failed: ' . $e->getMessage());
            return false;
        }
    }

    public static function cancelOrderReturn($returnId, $comment = '', $userId = 0, $userName = '')
    {
        $returnId = (int)$returnId;
        if (!self::ensureReturnTables() || $returnId < 1) return false;
        $rows = self::db()->query("SELECT * FROM `" . self::table('order_returns') . "`
            WHERE `id` = {$returnId} LIMIT 1");
        $return = $rows[0] ?? null;
        if (!$return || !empty($return['stock_returned']) || $return['status'] === 'cancelled') return false;
        self::db()->query("UPDATE `" . self::table('order_returns') . "`
            SET `status` = 'cancelled', `updated_at` = '" . self::esc(date('Y-m-d H:i:s')) . "'
            WHERE `id` = {$returnId}");
        self::logReturnStatus($returnId, 'cancelled', $comment !== '' ? $comment : 'Возврат отменён.', $userId, $userName);
        return true;
    }

    public static function getOrderReturns($orderId)
    {
        if (!self::ensureReturnTables()) return array();
        $orderId = (int)$orderId;
        $returns = self::db()->query("SELECT * FROM `" . self::table('order_returns') . "`
            WHERE `order_id` = {$orderId} ORDER BY `id` DESC");
        foreach ($returns as &$return) {
            $return['id'] = (int)$return['id'];
            $return['status_label'] = self::returnStatusLabel($return['status']);
            $return['items'] = self::db()->query("SELECT ri.*, oi.`product_title`, oi.`variant_title`, oi.`sku`
                FROM `" . self::table('order_return_items') . "` ri
                LEFT JOIN `" . self::table('order_items') . "` oi ON oi.`id` = ri.`order_item_id`
                WHERE ri.`return_id` = " . (int)$return['id'] . " ORDER BY ri.`id` ASC");
            $return['history'] = self::getReturnHistory((int)$return['id']);
            $return['amount_formatted'] = self::formatPrice($return['amount']);
            $return['created_at_formatted'] = self::formatDate($return['created_at']);
        }
        unset($return);
        return $returns;
    }

    public static function getReturnHistory($returnId)
    {
        if (!self::ensureReturnTables()) return array();
        $rows = self::db()->query("SELECT * FROM `" . self::table('return_history') . "`
            WHERE `return_id` = " . (int)$returnId . " ORDER BY `id` ASC");
        foreach ($rows as &$row) {
            $row['status_label'] = self::returnStatusLabel($row['status']);
            $row['created_at_formatted'] = self::formatDate($row['created_at']);
        }
        unset($row);
        return $rows;
    }

    public static function returnedQuantities($orderId)
    {
        if (!self::ensureReturnTables()) return array();
        $rows = self::db()->query("SELECT ri.`order_item_id`, SUM(ri.`quantity`) AS qty
            FROM `" . self::table('order_return_items') . "` ri
            INNER JOIN `" . self::table('order_returns') . "` r ON r.`id` = ri.`return_id`
            WHERE r.`order_id` = " . (int)$orderId . " AND r.`status` <> 'cancelled'
            GROUP BY ri.`order_item_id`");
        $result = array();
        foreach ($rows as $row) $result[(int)$row['order_item_id']] = (int)$row['qty'];
        return $result;
    }

    /* ---------------------------------------------------------------------
     * Loyalty, bonuses and referrals
     * ------------------------------------------------------------------ */

    /** @var bool */
    private static $loyaltyReady = false;

    public static function ensureLoyaltyTables()
    {
        if (self::$loyaltyReady) return true;
        try {
            self::db()->query("CREATE TABLE IF NOT EXISTS `" . self::table('loyalty_accounts') . "` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `email` VARCHAR(255) NOT NULL DEFAULT '',
                `user_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `balance` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                `total_spent` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                `referral_code` VARCHAR(32) NOT NULL DEFAULT '',
                `referred_by` VARCHAR(32) NOT NULL DEFAULT '',
                `referral_rewarded` TINYINT(1) NOT NULL DEFAULT 0,
                `created_at` DATETIME DEFAULT NULL,
                `updated_at` DATETIME DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `email` (`email`),
                UNIQUE KEY `referral_code` (`referral_code`),
                KEY `user_id` (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            self::db()->query("CREATE TABLE IF NOT EXISTS `" . self::table('loyalty_transactions') . "` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `account_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `delta` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                `reason` VARCHAR(64) NOT NULL DEFAULT '',
                `order_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `comment` VARCHAR(255) NOT NULL DEFAULT '',
                `created_at` DATETIME DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `account_id` (`account_id`),
                KEY `order_id` (`order_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Throwable $e) {
            return false;
        }
        self::$loyaltyReady = true;
        return true;
    }

    public static function loyaltyEnabled()
    {
        return (int)self::cfg('loyalty_enabled', 0) === 1;
    }

    public static function loyaltyEmail($email, $userId = 0)
    {
        $email = mb_strtolower(trim((string)$email));
        if ($email === '' && (int)$userId > 0) {
            $user = self::currentUser();
            if ((int)$user['id'] === (int)$userId) $email = mb_strtolower(trim((string)$user['email']));
        }
        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
    }

    public static function loyaltyAccount($email, $create = true, $referralCode = '', $userId = 0)
    {
        if (!self::ensureLoyaltyTables()) return false;
        $email = self::loyaltyEmail($email, $userId);
        if ($email === '') return false;
        $escaped = self::esc($email);
        $rows = self::db()->query("SELECT * FROM `" . self::table('loyalty_accounts') . "`
            WHERE `email` = '{$escaped}' LIMIT 1");
        if (!empty($rows[0])) {
            if ($userId > 0 && empty($rows[0]['user_id'])) {
                self::db()->query("UPDATE `" . self::table('loyalty_accounts') . "`
                    SET `user_id` = " . (int)$userId . " WHERE `id` = " . (int)$rows[0]['id']);
            }
            return $rows[0];
        }
        if (!$create) return false;

        $now = date('Y-m-d H:i:s');
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $code = strtoupper(substr(md5($email . microtime(true) . mt_rand()), 0, 8));
            try {
                $id = (int)self::db()->save('shop_loyalty_accounts', array(
                    'email' => $email,
                    'user_id' => (int)$userId,
                    'balance' => 0,
                    'total_spent' => 0,
                    'referral_code' => $code,
                    'referred_by' => substr(trim((string)$referralCode), 0, 32),
                    'referral_rewarded' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ));
                if ($id > 0) {
                    $welcome = (float)self::cfg('loyalty_welcome_bonus', 0);
                    if ($welcome > 0) {
                        self::loyaltyAdd($email, $welcome, 'welcome', 0, 'Приветственный бонус');
                    }
                    $rows = self::db()->query("SELECT * FROM `" . self::table('loyalty_accounts') . "`
                        WHERE `id` = {$id} LIMIT 1");
                    return $rows[0] ?? false;
                }
            } catch (Throwable $e) {
                // Referral code collision or a concurrent create: retry once more.
            }
            $rows = self::db()->query("SELECT * FROM `" . self::table('loyalty_accounts') . "`
                WHERE `email` = '{$escaped}' LIMIT 1");
            if (!empty($rows[0])) return $rows[0];
        }
        return false;
    }

    public static function loyaltyAdd($email, $delta, $reason, $orderId = 0, $comment = '')
    {
        if (!self::ensureLoyaltyTables()) return false;
        $delta = round((float)$delta, 2);
        if (abs($delta) < 0.005) return true;
        $account = self::loyaltyAccount($email, true);
        if (!$account) return false;
        $accountId = (int)$account['id'];
        if ($delta < 0 && (float)$account['balance'] + $delta < 0) return false;
        self::db()->query("UPDATE `" . self::table('loyalty_accounts') . "`
            SET `balance` = `balance` + " . number_format($delta, 2, '.', '') . ",
                `updated_at` = '" . self::esc(date('Y-m-d H:i:s')) . "'
            WHERE `id` = {$accountId}");
        self::db()->save('shop_loyalty_transactions', array(
            'account_id' => $accountId,
            'delta' => $delta,
            'reason' => mb_substr((string)$reason, 0, 64),
            'order_id' => (int)$orderId,
            'comment' => mb_substr((string)$comment, 0, 255),
            'created_at' => date('Y-m-d H:i:s'),
        ));
        return true;
    }

    public static function loyaltyBalance($email, $userId = 0)
    {
        if (!self::loyaltyEnabled()) return 0.0;
        $account = self::loyaltyAccount($email, false, '', $userId);
        return $account ? max(0, (float)$account['balance']) : 0.0;
    }

    public static function loyaltyTierPercent($totalSpent)
    {
        $raw = (string)self::cfg('loyalty_tiers', '');
        $totalSpent = (float)$totalSpent;
        $best = 0.0;
        foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
            $parts = explode('|', trim($line), 2);
            if (count($parts) !== 2) continue;
            $from = (float)str_replace(',', '.', trim($parts[0]));
            $percent = (float)str_replace(',', '.', trim($parts[1]));
            if ($percent > 0 && $percent <= 100 && $totalSpent >= $from && $percent > $best) {
                $best = $percent;
            }
        }
        return $best;
    }

    public static function loyaltyTierDiscount($email, $subtotal, $userId = 0)
    {
        if (!self::loyaltyEnabled()) return 0.0;
        $account = self::loyaltyAccount($email, false, '', $userId);
        if (!$account) return 0.0;
        $percent = self::loyaltyTierPercent((float)$account['total_spent']);
        return $percent > 0 ? round((float)$subtotal * $percent / 100, 2) : 0.0;
    }

    public static function loyaltyMaxBonus($email, $subtotal, $userId = 0)
    {
        if (!self::loyaltyEnabled()) return 0.0;
        $balance = self::loyaltyBalance($email, $userId);
        if ($balance <= 0) return 0.0;
        $percent = max(0.0, min(100.0, (float)self::cfg('loyalty_max_spend_percent', 30)));
        return round(min($balance, (float)$subtotal * $percent / 100), 2);
    }

    public static function loyaltyReferralLink($email, $userId = 0)
    {
        if (!self::loyaltyEnabled()) return '';
        $account = self::loyaltyAccount($email, true, '', $userId);
        if (!$account || empty($account['referral_code'])) return '';
        return self::absoluteUrl('/shop/?ref=' . rawurlencode($account['referral_code']));
    }

    public static function loyaltyFindByReferral($code)
    {
        if (!self::ensureLoyaltyTables()) return false;
        $code = strtoupper(preg_replace('/[^A-Z0-9]/', '', (string)$code));
        if ($code === '') return false;
        $rows = self::db()->query("SELECT * FROM `" . self::table('loyalty_accounts') . "`
            WHERE `referral_code` = '" . self::esc($code) . "' LIMIT 1");
        return $rows[0] ?? false;
    }

    /**
     * Pay out order bonuses once. Called after the status transaction commits.
     */
    public static function loyaltyCreditOrder($orderId)
    {
        $orderId = (int)$orderId;
        if ($orderId < 1 || !self::ensureLoyaltyTables()) return false;
        self::ensureOrderBusinessColumns();
        $orders = self::table('orders');
        try {
            $rows = self::db()->query("SELECT * FROM `{$orders}` WHERE `id` = {$orderId} LIMIT 1");
            $order = $rows[0] ?? null;
            if (!$order || !empty($order['loyalty_credited'])) return true;
            if (!self::loyaltyEnabled() || $order['status'] !== 'paid' && $order['status'] !== 'completed') return true;
            $email = self::loyaltyEmail($order['email'], (int)$order['user_id']);
            if ($email === '') return true;

            $base = max(0.0, (float)$order['subtotal'] - (float)$order['discount']
                - (float)($order['loyalty_discount'] ?? 0) - (float)($order['bonus_spent'] ?? 0));
            $percent = max(0.0, min(100.0, (float)self::cfg('loyalty_accrual_percent', 0)));
            $earned = round($base * $percent / 100, 2);
            $account = self::loyaltyAccount($email, true, (string)($order['referral_code'] ?? ''),
                (int)$order['user_id']);
            if (!$account) return false;

            self::db()->query("UPDATE `{$orders}` SET `bonus_earned` = " . number_format($earned, 2, '.', '') . ",
                `loyalty_credited` = 1 WHERE `id` = {$orderId}");
            self::db()->query("UPDATE `" . self::table('loyalty_accounts') . "`
                SET `total_spent` = `total_spent` + " . number_format(max(0, (float)$order['total']), 2, '.', '') . "
                WHERE `id` = " . (int)$account['id']);
            if ($earned > 0) self::loyaltyAdd($email, $earned, 'order', $orderId, 'Бонусы за заказ');

            if (!empty($account['referred_by']) && empty($account['referral_rewarded'])) {
                $referrer = self::loyaltyFindByReferral($account['referred_by']);
                if ($referrer && strtolower((string)$referrer['email']) !== $email) {
                    $reward = (float)self::cfg('loyalty_referral_bonus', 0);
                    if ($reward > 0) {
                        self::loyaltyAdd($referrer['email'], $reward, 'referral', $orderId,
                            'Бонус за приглашённого покупателя');
                    }
                    self::db()->query("UPDATE `" . self::table('loyalty_accounts') . "`
                        SET `referral_rewarded` = 1 WHERE `id` = " . (int)$account['id']);
                }
            }
            return true;
        } catch (Throwable $e) {
            error_log('Shop loyalty credit failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Undo earned bonuses, spend and total_spent for a cancelled or refunded
     * order. Idempotent.
     */
    public static function loyaltyReverseOrder($orderId)
    {
        $orderId = (int)$orderId;
        if ($orderId < 1 || !self::ensureLoyaltyTables()) return false;
        self::ensureOrderBusinessColumns();
        $orders = self::table('orders');
        try {
            $rows = self::db()->query("SELECT * FROM `{$orders}` WHERE `id` = {$orderId} FOR UPDATE");
            $order = $rows[0] ?? null;
            if (!$order) return false;
            $email = self::loyaltyEmail($order['email'], (int)$order['user_id']);
            if ($email === '') return true;
            $account = self::loyaltyAccount($email, false);
            if (!$account) return true;

            $earned = !empty($order['loyalty_credited']) ? (float)$order['bonus_earned'] : 0.0;
            if ($earned > 0) {
                self::loyaltyAdd($email, -$earned, 'order_reverse', $orderId, 'Отмена начисления по заказу');
                self::db()->query("UPDATE `" . self::table('loyalty_accounts') . "`
                    SET `total_spent` = GREATEST(`total_spent` - " . number_format(max(0, (float)$order['total']), 2, '.', '') . ", 0)
                    WHERE `id` = " . (int)$account['id']);
                self::db()->query("UPDATE `{$orders}` SET `loyalty_credited` = 0, `bonus_earned` = 0 WHERE `id` = {$orderId}");
            }
            $spent = (float)($order['bonus_spent'] ?? 0);
            if ($spent > 0 && empty($order['bonus_refunded'])) {
                self::loyaltyAdd($email, $spent, 'order_refund', $orderId, 'Возврат списанных бонусов');
                self::db()->query("UPDATE `{$orders}` SET `bonus_refunded` = 1 WHERE `id` = {$orderId}");
            }
            return true;
        } catch (Throwable $e) {
            error_log('Shop loyalty reverse failed: ' . $e->getMessage());
            return false;
        }
    }

    public static function loyaltyAccounts($limit = 200)
    {
        if (!self::ensureLoyaltyTables()) return array();
        $limit = max(1, min(1000, (int)$limit));
        $rows = self::db()->query("SELECT * FROM `" . self::table('loyalty_accounts') . "`
            ORDER BY `updated_at` DESC, `id` DESC LIMIT {$limit}");
        foreach ($rows as &$row) {
            $row['balance'] = (float)$row['balance'];
            $row['total_spent'] = (float)$row['total_spent'];
            $row['updated_at_formatted'] = self::formatDate($row['updated_at']);
        }
        unset($row);
        return $rows;
    }

    public static function loyaltyTransactions($limit = 200)
    {
        if (!self::ensureLoyaltyTables()) return array();
        $limit = max(1, min(1000, (int)$limit));
        $rows = self::db()->query("SELECT t.*, a.`email`
            FROM `" . self::table('loyalty_transactions') . "` t
            LEFT JOIN `" . self::table('loyalty_accounts') . "` a ON a.`id` = t.`account_id`
            ORDER BY t.`id` DESC LIMIT {$limit}");
        foreach ($rows as &$row) {
            $row['delta'] = (float)$row['delta'];
            $row['created_at_formatted'] = self::formatDate($row['created_at']);
        }
        unset($row);
        return $rows;
    }

    /* ---------------------------------------------------------------------
     * Request rate limits
     * ------------------------------------------------------------------ */

    public static function ensureRateLimitTable()
    {
        return ShopRateLimiter::ensureTable();
    }

    public static function rateLimitKey($action, $subject = '')
    {
        return ShopRateLimiter::key($action, $subject);
    }

    /**
     * Sliding fixed window counter. Returns false when the limit is reached.
     */
    public static function rateLimit($action, $limit, $seconds, $subject = '')
    {
        return ShopRateLimiter::allow($action, $limit, $seconds, $subject);
    }
}

/**
 * Request-driven cron hooks. Return false so fpsCron retries on the next hit.
 */
if (!function_exists('shopExpireOrders')) {
    function shopExpireOrders()
    {
        if (!class_exists('ShopHelper', false)) return false;
        return ShopHelper::expireUnpaidOrders();
    }
}

if (!function_exists('shopNotifyStock')) {
    function shopNotifyStock()
    {
        if (!class_exists('ShopHelper', false)) return false;
        return ShopHelper::notifyStockSubscribers();
    }
}

if (!function_exists('shopLowStockAlert')) {
    function shopLowStockAlert()
    {
        if (!class_exists('ShopHelper', false)) return false;
        return ShopHelper::lowStockAlert();
    }
}

if (!function_exists('shopRemindCarts')) {
    function shopRemindCarts()
    {
        if (!class_exists('ShopHelper', false)) return false;
        return ShopHelper::remindAbandonedCarts();
    }
}
