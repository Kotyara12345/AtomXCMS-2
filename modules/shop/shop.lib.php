<?php

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
     * Render an administrator-entered description.
     *
     * The shop uses the same WysiBB editor as news articles, so new values are
     * BBCode. Older values may be plain text or HTML saved by the previous
     * editor; both remain supported.
     */
    public static function formatDescription($text)
    {
        $text = (string)$text;
        if (trim($text) === '') return '';

        // Legacy descriptions saved by the Redactor-based editor.
        if (preg_match('/<\/?[a-z][^>]*>/i', $text)) {
            return self::sanitizeDescriptionHtml($text);
        }

        return self::parseBbcodeDescription($text);
    }

    private static function parseBbcodeDescription($text)
    {
        $register = self::register();
        if (!empty($register['PrintText'])) {
            return $register['PrintText']->parseBBCodes($text);
        }
        if (class_exists('PrintText')) {
            $parser = new PrintText();
            return $parser->parseBBCodes($text);
        }
        return nl2br(h($text));
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
        $html = self::formatDescription($text);
        $html = preg_replace('#<br\s*/?>#i', ' ', $html);
        $html = preg_replace('#</?(?:p|div|li|h[1-6]|blockquote|tr|pre|ul|ol|table)\b[^>]*>#i', ' ', $html);
        $text = trim(strip_tags($html));
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text);
        return trim((string)$text);
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
        if (empty($value) || $value === '0000-00-00 00:00:00') return '';
        $timestamp = strtotime($value);
        return $timestamp ? date('d.m.Y H:i', $timestamp) : (string)$value;
    }

    public static function orderNumber($id)
    {
        return 100000 + (int)$id;
    }

    /* ---------------------------------------------------------------------
     * Cart
     * ------------------------------------------------------------------ */

    public static function getCart()
    {
        $cart = isset($_SESSION[self::CART_KEY]) && is_array($_SESSION[self::CART_KEY])
            ? $_SESSION[self::CART_KEY] : array();
        $clean = array();
        foreach ($cart as $variantId => $qty) {
            $variantId = (int)$variantId;
            $qty = (int)$qty;
            if ($variantId > 0 && $qty > 0) $clean[$variantId] = $qty;
        }
        return $clean;
    }

    public static function saveCart($cart)
    {
        $_SESSION[self::CART_KEY] = $cart;
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
                p.`title` AS product_title, p.`clean_url_title`,
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

    public static function cartTotals($items = null)
    {
        if ($items === null) $items = self::cartItems();
        $subtotal = 0.0;
        $quantity = 0;
        foreach ($items as $item) {
            $subtotal += (float)$item['total'];
            $quantity += (int)$item['quantity'];
        }
        return array(
            'subtotal' => $subtotal,
            'subtotal_formatted' => self::formatPrice($subtotal),
            'quantity' => $quantity,
        );
    }

    /* ---------------------------------------------------------------------
     * Currency, payment and delivery helpers
     * ------------------------------------------------------------------ */

    public static function paymentMethods()
    {
        $raw = (string)self::cfg('payment_methods', '');
        $lines = preg_split('/\r\n|\r|\n/', $raw);
        $methods = array();
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') continue;
            $parts = explode('|', $line, 2);
            $code = strtolower(trim($parts[0]));
            if (!preg_match('/^[a-z0-9_\-]+$/', $code)) continue;
            $title = isset($parts[1]) && trim($parts[1]) !== '' ? trim($parts[1]) : $code;
            $methods[$code] = array('code' => $code, 'title' => $title);
        }
        if (empty($methods)) {
            $methods = array(
                'cash' => array('code' => 'cash', 'title' => 'Наличными при получении'),
                'transfer' => array('code' => 'transfer', 'title' => 'Банковский перевод'),
            );
        }
        return $methods;
    }

    public static function deliveryMethods($currencyCode = null)
    {
        $currency = $currencyCode ? self::currency($currencyCode) : self::activeCurrency();
        $methods = array();

        if ((int)self::cfg('pickup_enabled', 1) === 1) {
            $methods['pickup'] = array(
                'code' => 'pickup',
                'title' => 'Самовывоз',
                'price' => 0.0,
                'price_formatted' => 'Бесплатно',
            );
        }

        if ((int)self::cfg('courier_enabled', 1) === 1) {
            $key = 'delivery_price_' . strtolower($currency['code']);
            $price = max(0, (float)self::cfg($key, 0));
            $methods['courier'] = array(
                'code' => 'courier',
                'title' => 'Доставка',
                'price' => $price,
                'price_formatted' => $price > 0 ? self::formatPrice($price, $currency['code']) : 'Бесплатно',
            );
        }

        if (empty($methods)) {
            $methods['pickup'] = array(
                'code' => 'pickup',
                'title' => 'Самовывоз',
                'price' => 0.0,
                'price_formatted' => 'Бесплатно',
            );
        }

        return $methods;
    }

    public static function deliveryPrice($method, $currencyCode = null)
    {
        $methods = self::deliveryMethods($currencyCode);
        return isset($methods[$method]) ? (float)$methods[$method]['price'] : 0.0;
    }

    public static function statuses()
    {
        return array(
            'new' => 'Новый',
            'confirmed' => 'Подтверждён',
            'paid' => 'Оплачен',
            'shipped' => 'Отправлен',
            'completed' => 'Выполнен',
            'cancelled' => 'Отменён',
        );
    }

    public static function statusLabel($status)
    {
        $statuses = self::statuses();
        return isset($statuses[$status]) ? $statuses[$status] : $status;
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
        $product['price_from'] = self::productMinPrice($product['id']);
        $product['price_from_formatted'] = $product['price_from'] > 0
            ? self::formatPrice($product['price_from']) : '';
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

    public static function getProducts($filters = array())
    {
        $products = self::table('products');
        $categories = self::table('categories');
        $pav = self::table('product_attribute_values');
        $attributeValues = self::table('attribute_values');
        $currency = isset($filters['currency']) ? self::currency($filters['currency']) : self::activeCurrency();
        $code = self::esc($currency['code']);

        $where = array();
        if (!isset($filters['active_only']) || !empty($filters['active_only'])) {
            $where[] = 'p.`active` = 1';
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
                $where[] = "(p.`title` LIKE '%{$q}%' OR p.`short_description` LIKE '%{$q}%' OR p.`description` LIKE '%{$q}%')";
            }
        }
        if (!empty($filters['featured'])) {
            $where[] = 'p.`featured` = 1';
        }
        $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

        $page = max(1, (int)(isset($filters['page']) ? $filters['page'] : 1));
        $limit = (int)(isset($filters['limit']) ? $filters['limit'] : self::cfg('per_page', 12));
        if ($limit < 1) $limit = 12;
        if ($limit > 100) $limit = 100;
        $offset = ($page - 1) * $limit;

        $sort = isset($filters['sort']) ? (string)$filters['sort'] : 'default';
        $orderSql = 'p.`position` ASC, p.`id` DESC';
        if ($sort === 'price_asc') $orderSql = 'min_price IS NULL ASC, min_price ASC, p.`id` DESC';
        if ($sort === 'price_desc') $orderSql = 'min_price IS NULL ASC, min_price DESC, p.`id` DESC';
        if ($sort === 'new') $orderSql = 'p.`created_at` DESC, p.`id` DESC';
        if ($sort === 'title') $orderSql = 'p.`title` ASC';

        $minSql = "(SELECT MIN(vp.`price`) FROM `" . self::table('variants') . "` v
            INNER JOIN `" . self::table('variant_prices') . "` vp ON vp.`variant_id` = v.`id`
            WHERE v.`product_id` = p.`id` AND v.`active` = 1
                AND vp.`currency_code` = '{$code}' AND vp.`price` > 0)";
        $gallerySql = "(SELECT pi.`image` FROM `" . self::table('product_images') . "` pi
            WHERE pi.`product_id` = p.`id` ORDER BY pi.`position` ASC, pi.`id` ASC LIMIT 1)";

        $countRows = self::db()->query("SELECT COUNT(*) AS cnt FROM `{$products}` p" . $whereSql);
        $total = !empty($countRows[0]['cnt']) ? (int)$countRows[0]['cnt'] : 0;

        $rows = self::db()->query("SELECT p.*, c.`title` AS category_title, c.`clean_url_title` AS category_slug,
                {$minSql} AS min_price, {$gallerySql} AS gallery_image
            FROM `{$products}` p
            LEFT JOIN `{$categories}` c ON c.`id` = p.`category_id`
            {$whereSql}
            ORDER BY {$orderSql}
            LIMIT {$limit} OFFSET {$offset}");

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
            $row['short_description_html'] = nl2br(h((string)$row['short_description']));
            $items[] = $row;
        }

        return array(
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'pages' => $limit > 0 ? (int)ceil($total / $limit) : 0,
            'limit' => $limit,
            'sort' => $sort,
        );
    }

    public static function productMinPrice($productId, $currencyCode = null)
    {
        $currency = $currencyCode ? self::currency($currencyCode) : self::activeCurrency();
        $code = self::esc($currency['code']);
        $variants = self::table('variants');
        $prices = self::table('variant_prices');
        $rows = self::db()->query("SELECT MIN(vp.`price`) AS min_price
            FROM `{$variants}` v
            INNER JOIN `{$prices}` vp ON vp.`variant_id` = v.`id`
            WHERE v.`product_id` = " . (int)$productId . "
                AND v.`active` = 1 AND vp.`currency_code` = '{$code}' AND vp.`price` > 0");
        return !empty($rows[0]['min_price']) ? (float)$rows[0]['min_price'] : 0.0;
    }

    public static function getProductVariants($productId, $currencyCode = null, $onlyAvailable = false)
    {
        $productId = (int)$productId;
        if ($productId < 1) return array();

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
                vp.`price` AS price
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
            $result[] = array(
                'id' => $vid,
                'product_id' => (int)$row['product_id'],
                'title' => $title,
                'sku' => $row['sku'],
                'stock' => $stock,
                'price' => $price,
                'price_formatted' => $price > 0 ? self::formatPrice($price, $currency['code']) : '',
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
        $byParent = array();
        foreach ($categories as $category) {
            $pid = (int)$category['parent_id'];
            if (!isset($byParent[$pid])) $byParent[$pid] = array();
            $byParent[$pid][] = $category;
        }
        return self::buildCategoryBranch($byParent, (int)$parentId);
    }

    private static function buildCategoryBranch($byParent, $parentId)
    {
        $branch = array();
        if (empty($byParent[$parentId])) return $branch;
        foreach ($byParent[$parentId] as $category) {
            $category['children'] = self::buildCategoryBranch($byParent, (int)$category['id']);
            $branch[] = $category;
        }
        return $branch;
    }

    public static function categoryTreeHtml($tree, $currentId = 0, $level = 0)
    {
        if (empty($tree)) return '';
        $html = $level === 0 ? '<ul class="shop-cats">' : '<ul>';
        foreach ($tree as $category) {
            $active = (int)$category['id'] === (int)$currentId ? ' class="active"' : '';
            $html .= '<li' . $active . '><a href="' . h($category['url']) . '">'
                . h($category['title']) . '</a>';
            if (!empty($category['children'])) {
                $html .= self::categoryTreeHtml($category['children'], $currentId, $level + 1);
            }
            $html .= '</li>';
        }
        $html .= '</ul>';
        return $html;
    }

    public static function categoryDescendantIds($categoryId)
    {
        $categoryId = (int)$categoryId;
        if ($categoryId < 1) return array();
        $categories = self::getCategories(false);
        $children = array();
        foreach ($categories as $category) {
            $children[(int)$category['parent_id']][] = (int)$category['id'];
        }
        $ids = array($categoryId);
        $queue = array($categoryId);
        while ($queue) {
            $current = array_shift($queue);
            if (empty($children[$current])) continue;
            foreach ($children[$current] as $childId) {
                if (!in_array($childId, $ids, true)) {
                    $ids[] = $childId;
                    $queue[] = $childId;
                }
            }
        }
        return $ids;
    }

    public static function categoryBreadcrumbs($categoryId)
    {
        $categoryId = (int)$categoryId;
        $categories = self::getCategories(false);
        $byId = array();
        foreach ($categories as $category) $byId[(int)$category['id']] = $category;
        $crumbs = array();
        $guard = 0;
        while ($categoryId > 0 && isset($byId[$categoryId]) && $guard < 20) {
            array_unshift($crumbs, $byId[$categoryId]);
            $categoryId = (int)$byId[$categoryId]['parent_id'];
            $guard++;
        }
        return $crumbs;
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
        return get_url('/sys/files/shop/' . $folder . '/' . rawurlencode($filename));
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
        $order['delivery_price_formatted'] = self::formatPrice($order['delivery_price'], $order['currency_code']);
        $order['total_formatted'] = self::formatPrice($order['total'], $order['currency_code']);
        $order['created_at_formatted'] = self::formatDate($order['created_at']);
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

    public static function releaseOrderStock($orderId)
    {
        $order = self::getOrder($orderId);
        if (!$order || !empty($order['stock_released'])) return true;

        $items = self::getOrderItems($orderId);
        $variants = self::table('variants');
        $orders = self::table('orders');
        try {
            self::db()->query('START TRANSACTION');
            foreach ($items as $item) {
                $variantId = (int)$item['variant_id'];
                $qty = (int)$item['quantity'];
                if ($variantId < 1 || $qty < 1) continue;
                self::db()->query("UPDATE `{$variants}` SET `stock` = `stock` + {$qty} WHERE `id` = {$variantId}");
            }
            self::db()->query("UPDATE `{$orders}` SET `stock_released` = 1, `updated_at` = '" . self::esc(date('Y-m-d H:i:s')) . "'
                WHERE `id` = " . (int)$orderId);
            self::db()->query('COMMIT');
            return true;
        } catch (Throwable $e) {
            try { self::db()->query('ROLLBACK'); } catch (Throwable $ignored) {}
            return false;
        }
    }

    public static function setOrderStatus($orderId, $status)
    {
        $statuses = self::statuses();
        if (!isset($statuses[$status])) return false;
        $order = self::getOrder($orderId);
        if (!$order) return false;

        if ($status === 'cancelled' && empty($order['stock_released'])) {
            if (!self::releaseOrderStock($orderId)) return false;
        }

        self::db()->save('shop_orders', array(
            'status' => $status,
            'updated_at' => date('Y-m-d H:i:s'),
        ), array('id' => (int)$orderId));
        return true;
    }

    public static function sendOrderEmails($order, $items)
    {
        $body = '<h2>Заказ №' . h(self::orderNumber($order['id'])) . '</h2>'
            . '<p>Дата: ' . h(self::formatDate($order['created_at'])) . '</p>'
            . '<p>Статус: ' . h(self::statusLabel($order['status'])) . '</p>'
            . '<p>Покупатель: ' . h($order['customer_name']) . '<br />'
            . 'E-mail: ' . h($order['email']) . '<br />'
            . 'Телефон: ' . h($order['phone']) . '</p>'
            . '<p>Доставка: ' . h($order['delivery_title']) . '<br />'
            . 'Адрес: ' . nl2br(h($order['address'])) . '<br />'
            . 'Оплата: ' . h($order['payment_title']) . '</p>'
            . '<table cellpadding="6" cellspacing="0" border="1" style="border-collapse:collapse;">'
            . '<tr><th>Товар</th><th>Артикул</th><th>Цена</th><th>Кол-во</th><th>Итого</th></tr>';
        foreach ($items as $item) {
            $body .= '<tr><td>' . h($item['product_title'])
                . ($item['variant_title'] !== '' ? '<br /><small>' . h($item['variant_title']) . '</small>' : '')
                . '</td><td>' . h($item['sku']) . '</td><td>'
                . h(self::formatPrice($item['price'], $order['currency_code'])) . '</td><td>'
                . (int)$item['quantity'] . '</td><td>'
                . h(self::formatPrice($item['total'], $order['currency_code'])) . '</td></tr>';
        }
        $body .= '</table>'
            . '<p>Товары: ' . h(self::formatPrice($order['subtotal'], $order['currency_code'])) . '<br />'
            . 'Доставка: ' . h(self::formatPrice($order['delivery_price'], $order['currency_code'])) . '<br />'
            . '<strong>Итого: ' . h(self::formatPrice($order['total'], $order['currency_code'])) . '</strong></p>';
        if (!empty($order['comment'])) {
            $body .= '<p>Комментарий: ' . nl2br(h($order['comment'])) . '</p>';
        }

        $subject = 'Заказ №' . self::orderNumber($order['id']);
        $templatePath = ROOT . '/sys/settings/email_templates/';

        try {
            if (!empty($order['email'])) {
                $mailer = new AtmMail($templatePath);
                $mailer->prepare($body);
                @$mailer->sendMail($order['email'], $subject, array());
            }
        } catch (Throwable $e) {
            // A mail failure must not break checkout.
        }

        $notify = trim((string)self::cfg('notify_email', ''));
        if ($notify === '') $notify = trim((string)Config::read('admin_email'));
        if ($notify !== '' && strcasecmp($notify, $order['email']) !== 0) {
            try {
                $mailer = new AtmMail($templatePath);
                $mailer->prepare($body);
                @$mailer->sendMail($notify, $subject . ' (администратор)', array());
            } catch (Throwable $e) {
                // Ignore mail problems.
            }
        }
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
}
