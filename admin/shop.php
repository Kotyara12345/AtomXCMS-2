<?php
/**
 * Shop administration.
 *
 * The public module follows the CMS module contract. The admin screen is a
 * standalone page because the shop needs several related entities (products,
 * attributes, generated variants, categories and orders) and a single tabbed
 * workspace is easier to use than a generic material list.
 */

include_once '../sys/boot.php';
include_once ROOT . '/admin/inc/adm_boot.php';
require_once ROOT . '/modules/shop/shop.lib.php';

$Register = Register::getInstance();
$DB = $Register['DB'];
$ACL = $Register['ACL'];
$pageTitle = 'Магазин';

if (Config::read('active', 'shop') === null) {
    $_SESSION['errors'] = 'Модуль магазина ещё не установлен. Установите его из списка модулей.';
    redirect('/admin/');
}

$canProducts = $ACL->turn(array('shop', 'manage_products'), false);
$canOrders = $ACL->turn(array('shop', 'manage_orders'), false);
$canSettings = $ACL->turn(array('shop', 'manage_settings'), false);

if (!$canProducts && !$canOrders) {
    $_SESSION['errors'] = 'Недостаточно прав для управления магазином.';
    redirect('/admin/');
}

$tab = isset($_GET['tab']) ? preg_replace('/[^a-z_]/', '', (string)$_GET['tab']) : 'dashboard';
$productTabs = array('dashboard', 'products', 'product', 'variants', 'attributes', 'attribute', 'categories', 'category', 'landings', 'landing', 'reviews', 'emails', 'promocodes', 'promocode');
$orderTabs = array('orders', 'order', 'order_create');
if (!in_array($tab, array_merge($productTabs, $orderTabs), true)) $tab = 'dashboard';
if (in_array($tab, $productTabs, true) && !$canProducts) $tab = 'orders';
if (in_array($tab, $orderTabs, true) && !$canOrders) $tab = 'dashboard';

/* -------------------------------------------------------------------------
 * POST actions
 * ---------------------------------------------------------------------- */

if (!empty($_SERVER['REQUEST_METHOD']) && strtoupper($_SERVER['REQUEST_METHOD']) === 'POST'
    && !empty($_POST['action'])) {
    $action = (string)$_POST['action'];
    $isOrderAction = in_array($action, array('order_status', 'order_mail', 'order_tracking', 'order_create', 'order_items_save'), true);
    if (($isOrderAction && !$canOrders) || (!$isOrderAction && !$canProducts)) {
        $_SESSION['errors'] = 'Недостаточно прав для этого действия.';
        redirect('/admin/shop.php?tab=dashboard');
    }

    if ($action === 'product_save') {
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        $existing = $id > 0 ? ShopHelper::getProduct($id, false) : false;
        if ($id > 0 && !$existing) {
            $_SESSION['errors'] = 'Товар не найден.';
            shop_admin_go(array('tab' => 'products'));
        }

        $title = trim((string)(isset($_POST['title']) ? $_POST['title'] : ''));
        if ($title === '') {
            $_SESSION['errors'] = 'Укажите название товара.';
            shop_admin_go(array('tab' => 'product', 'id' => $id));
        }

        $slugSource = trim((string)(isset($_POST['clean_url_title']) ? $_POST['clean_url_title'] : ''));
        if ($slugSource === '') $slugSource = $title;
        $slug = ShopHelper::uniqueSlug('shop_products', $slugSource, $id);

        $oldImage = $existing && !empty($existing['image']) ? $existing['image'] : '';
        $removeImage = !empty($_POST['remove_image']);
        $newImage = shop_admin_upload_image('image', $removeImage ? '' : $oldImage);
        if ($newImage !== $oldImage) {
            if ($oldImage !== '') shop_admin_delete_upload($oldImage);
        }

        $now = date('Y-m-d H:i:s');
        $data = array(
            'category_id' => isset($_POST['category_id']) ? (int)$_POST['category_id'] : 0,
            'title' => $title,
            'clean_url_title' => $slug,
            'short_description' => trim((string)(isset($_POST['short_description']) ? $_POST['short_description'] : '')),
            'description' => (string)(isset($_POST['description']) ? $_POST['description'] : ''),
            'image' => $newImage,
            'active' => !empty($_POST['active']) ? 1 : 0,
            'featured' => !empty($_POST['featured']) ? 1 : 0,
            'position' => isset($_POST['position']) ? (int)$_POST['position'] : 0,
            'views' => $existing && isset($existing['views']) ? (int)$existing['views'] : 0,
            'meta_title' => trim((string)(isset($_POST['meta_title']) ? $_POST['meta_title'] : '')),
            'meta_description' => trim((string)(isset($_POST['meta_description']) ? $_POST['meta_description'] : '')),
            'meta_keywords' => trim((string)(isset($_POST['meta_keywords']) ? $_POST['meta_keywords'] : '')),
            'created_at' => $existing && !empty($existing['created_at']) ? $existing['created_at'] : $now,
            'updated_at' => $now,
        );
        if ($id > 0) $data['id'] = $id;

        $saveResult = $DB->save('shop_products', $data);
        if ($id < 1) $id = (int)$saveResult;

        $removeGallery = isset($_POST['remove_gallery']) ? (array)$_POST['remove_gallery'] : array();
        if ($removeGallery) shop_admin_remove_product_gallery($id, $removeGallery);
        shop_admin_upload_product_gallery($id);

        $values = isset($_POST['attributes']) ? (array)$_POST['attributes'] : array();
        ShopHelper::syncProductAttributeValues($id, $values);
        $related = isset($_POST['related']) ? (array)$_POST['related'] : array();
        ShopHelper::syncRelatedProducts($id, $related);
        ShopHelper::syncProductVariants($id);

        $_SESSION['message'] = 'Товар сохранён. Настройте цены и остатки вариантов.';
        shop_admin_go(array('tab' => 'variants', 'id' => $id));
    }

    if ($action === 'product_delete') {
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        $product = $id > 0 ? ShopHelper::getProduct($id, false) : false;
        if (!$product) {
            $_SESSION['errors'] = 'Товар не найден.';
            shop_admin_go(array('tab' => 'products'));
        }

        $variantRows = $DB->query("SELECT `id` FROM `" . ShopHelper::table('variants') . "` WHERE `product_id` = {$id}");
        $variantIds = array();
        foreach ($variantRows as $variantRow) $variantIds[] = (int)$variantRow['id'];
        if ($variantIds) {
            $ids = implode(',', $variantIds);
            $DB->query("DELETE FROM `" . ShopHelper::table('variant_prices') . "` WHERE `variant_id` IN ({$ids})");
            $DB->query("DELETE FROM `" . ShopHelper::table('variant_values') . "` WHERE `variant_id` IN ({$ids})");
            $DB->query("DELETE FROM `" . ShopHelper::table('variants') . "` WHERE `id` IN ({$ids})");
        }
        $DB->query("DELETE FROM `" . ShopHelper::table('product_attribute_values') . "` WHERE `product_id` = {$id}");
        ShopHelper::ensureRelatedProductsTable();
        $DB->query("DELETE FROM `" . ShopHelper::table('related_products') . "`
            WHERE `product_id` = {$id} OR `related_product_id` = {$id}");
        shop_admin_delete_product_gallery($id);
        $DB->query("DELETE FROM `" . ShopHelper::table('products') . "` WHERE `id` = {$id}");
        if (!empty($product['image'])) shop_admin_delete_upload($product['image']);

        $_SESSION['message'] = 'Товар удалён.';
        shop_admin_go(array('tab' => 'products'));
    }

    if ($action === 'product_copy') {
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        $product = $id > 0 ? ShopHelper::getProduct($id, false) : false;
        if (!$product) {
            $_SESSION['errors'] = 'Товар не найден.';
            shop_admin_go(array('tab' => 'products'));
        }

        $now = date('Y-m-d H:i:s');
        $title = $product['title'] . ' (копия)';
        $slug = ShopHelper::uniqueSlug('shop_products', $title, 0);
        $image = !empty($product['image']) ? shop_admin_copy_upload($product['image']) : '';
        $newId = (int)$DB->save('shop_products', array(
            'category_id' => (int)$product['category_id'],
            'title' => $title,
            'clean_url_title' => $slug,
            'short_description' => (string)$product['short_description'],
            'description' => (string)$product['description'],
            'image' => $image,
            'active' => 0,
            'featured' => 0,
            'position' => (int)$product['position'],
            'views' => 0,
            'meta_title' => (string)$product['meta_title'],
            'meta_description' => (string)$product['meta_description'],
            'meta_keywords' => (string)$product['meta_keywords'],
            'created_at' => $now,
            'updated_at' => $now,
        ));
        if ($newId < 1) {
            $_SESSION['errors'] = 'Не удалось скопировать товар.';
            shop_admin_go(array('tab' => 'products'));
        }

        $gallery = $DB->query("SELECT * FROM `" . ShopHelper::table('product_images') . "`
            WHERE `product_id` = {$id} ORDER BY `position` ASC, `id` ASC");
        foreach ($gallery as $galleryImage) {
            $copy = !empty($galleryImage['image']) ? shop_admin_copy_upload($galleryImage['image']) : '';
            if ($copy === '') continue;
            $DB->query("INSERT INTO `" . ShopHelper::table('product_images') . "`
                (`product_id`, `image`, `position`, `created_at`)
                VALUES ({$newId}, '" . ShopHelper::esc($copy) . "', " . (int)$galleryImage['position'] . ", '{$now}')");
        }

        foreach (ShopHelper::getProductAttributeValueIds($id) as $valueId) {
            $DB->query("INSERT INTO `" . ShopHelper::table('product_attribute_values') . "`
                (`product_id`, `attribute_value_id`) VALUES ({$newId}, " . (int)$valueId . ")");
        }

        ShopHelper::ensureRelatedProductsTable();
        $position = 0;
        foreach (ShopHelper::relatedProductIds($id) as $relatedId) {
            $DB->query("INSERT INTO `" . ShopHelper::table('related_products') . "`
                (`product_id`, `related_product_id`, `position`)
                VALUES ({$newId}, " . (int)$relatedId . ", {$position})");
            $position++;
        }

        $variants = $DB->query("SELECT * FROM `" . ShopHelper::table('variants') . "`
            WHERE `product_id` = {$id} ORDER BY `position` ASC, `id` ASC");
        foreach ($variants as $variant) {
            $sku = (string)$variant['sku'];
            $newVariantId = (int)$DB->save('shop_variants', array(
                'product_id' => $newId,
                'title' => (string)$variant['title'],
                'sku' => $sku !== '' ? $sku . '-copy' : '',
                'stock' => (int)$variant['stock'],
                'low_stock_notified' => 0,
                'image' => (string)$variant['image'],
                'active' => (int)$variant['active'],
                'position' => (int)$variant['position'],
                'created_at' => $now,
                'updated_at' => $now,
            ));
            if ($newVariantId < 1) continue;

            $DB->query("INSERT INTO `" . ShopHelper::table('variant_values') . "` (`variant_id`, `attribute_value_id`)
                SELECT {$newVariantId}, `attribute_value_id` FROM `" . ShopHelper::table('variant_values') . "`
                WHERE `variant_id` = " . (int)$variant['id']);
            $DB->query("INSERT INTO `" . ShopHelper::table('variant_prices') . "`
                (`variant_id`, `currency_code`, `price`, `compare_price`)
                SELECT {$newVariantId}, `currency_code`, `price`, `compare_price`
                FROM `" . ShopHelper::table('variant_prices') . "`
                WHERE `variant_id` = " . (int)$variant['id']);
        }

        $_SESSION['message'] = 'Товар скопирован. Копия скрыта — проверьте название, цену и остатки.';
        shop_admin_go(array('tab' => 'product', 'id' => $newId));
    }

    if ($action === 'product_import') {
        $file = isset($_FILES['csv']) ? $_FILES['csv'] : null;
        if (empty($file) || empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            $_SESSION['errors'] = 'Выберите CSV-файл для импорта.';
            shop_admin_go(array('tab' => 'products'));
        }
        if (!empty($file['error']) || (int)$file['size'] > 5 * 1024 * 1024) {
            $_SESSION['errors'] = 'Файл не загружен или больше 5 МБ.';
            shop_admin_go(array('tab' => 'products'));
        }

        $result = ShopHelper::importProductsCsv($file['tmp_name']);
        $message = 'Импорт завершён. Создано: ' . (int)$result['created']
            . ', обновлено: ' . (int)$result['updated']
            . ', пропущено: ' . (int)$result['skipped'] . '.';
        if ($result['errors']) {
            $errors = array_map('h', array_slice($result['errors'], 0, 10));
            $_SESSION['errors'] = $message . ' ' . implode(' ', $errors);
        } else {
            $_SESSION['message'] = $message;
        }
        shop_admin_go(array('tab' => 'products'));
    }

    if ($action === 'variants_save') {
        $productId = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        $product = $productId > 0 ? ShopHelper::getProduct($productId, false) : false;
        if (!$product) {
            $_SESSION['errors'] = 'Товар не найден.';
            shop_admin_go(array('tab' => 'products'));
        }

        $variantsPost = isset($_POST['variants']) && is_array($_POST['variants']) ? $_POST['variants'] : array();
        $currencies = ShopHelper::currencies(false);
        $now = date('Y-m-d H:i:s');
        foreach ($variantsPost as $variantId => $data) {
            $variantId = (int)$variantId;
            $rows = $DB->query("SELECT `id` FROM `" . ShopHelper::table('variants') . "`
                WHERE `id` = {$variantId} AND `product_id` = {$productId} LIMIT 1");
            if (empty($rows[0])) continue;

            $DB->save('shop_variants', array(
                'sku' => mb_substr(trim((string)(isset($data['sku']) ? $data['sku'] : '')), 0, 64),
                'stock' => max(0, (int)(isset($data['stock']) ? $data['stock'] : 0)),
                'active' => !empty($data['active']) ? 1 : 0,
                'updated_at' => $now,
            ), array('id' => $variantId));

            foreach ($currencies as $code => $currency) {
                $price = isset($data['prices'][$code]) ? (float)str_replace(',', '.', $data['prices'][$code]) : 0;
                if ($price < 0) $price = 0;
                $compare = isset($data['compare_prices'][$code]) ? (float)str_replace(',', '.', $data['compare_prices'][$code]) : 0;
                if ($compare <= $price) $compare = 0;
                $DB->query("INSERT INTO `" . ShopHelper::table('variant_prices') . "`
                    (`variant_id`, `currency_code`, `price`, `compare_price`) VALUES
                    ({$variantId}, '" . ShopHelper::esc($code) . "', " . number_format($price, 2, '.', '') . ",
                    " . number_format($compare, 2, '.', '') . ")
                    ON DUPLICATE KEY UPDATE `price` = VALUES(`price`), `compare_price` = VALUES(`compare_price`)");
            }
        }

        $_SESSION['message'] = 'Варианты сохранены.';
        shop_admin_go(array('tab' => 'variants', 'id' => $productId));
    }

    if ($action === 'variants_sync') {
        $productId = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        if ($productId < 1) {
            $_SESSION['errors'] = 'Товар не найден.';
            shop_admin_go(array('tab' => 'products'));
        }
        $count = ShopHelper::syncProductVariants($productId);
        $_SESSION['message'] = 'Комбинации вариантов пересозданы. Вариантов: ' . (int)$count . '.';
        shop_admin_go(array('tab' => 'variants', 'id' => $productId));
    }

    if ($action === 'attribute_save') {
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        $title = trim((string)(isset($_POST['title']) ? $_POST['title'] : ''));
        if ($title === '') {
            $_SESSION['errors'] = 'Укажите название атрибута.';
            shop_admin_go(array('tab' => $id > 0 ? 'attribute' : 'attributes', 'id' => $id));
        }
        $now = date('Y-m-d H:i:s');
        $data = array(
            'title' => $title,
            'position' => isset($_POST['position']) ? (int)$_POST['position'] : 0,
            'active' => !empty($_POST['active']) ? 1 : 0,
            'updated_at' => $now,
        );
        if ($id > 0) {
            $data['id'] = $id;
            $DB->save('shop_attributes', $data);
        } else {
            $data['created_at'] = $now;
            $id = (int)$DB->save('shop_attributes', $data);
        }
        shop_admin_resync_attribute_products($id);
        $_SESSION['message'] = 'Атрибут сохранён.';
        shop_admin_go(array('tab' => 'attribute', 'id' => $id));
    }

    if ($action === 'attribute_delete') {
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        $values = $DB->query("SELECT `id` FROM `" . ShopHelper::table('attribute_values') . "` WHERE `attribute_id` = {$id}");
        $valueIds = array();
        foreach ($values as $value) $valueIds[] = (int)$value['id'];
        if ($valueIds) {
            $ids = implode(',', $valueIds);
            $usedProducts = $DB->query("SELECT COUNT(*) AS cnt FROM `" . ShopHelper::table('product_attribute_values') . "`
                WHERE `attribute_value_id` IN ({$ids})");
            $usedVariants = $DB->query("SELECT COUNT(*) AS cnt FROM `" . ShopHelper::table('variant_values') . "`
                WHERE `attribute_value_id` IN ({$ids})");
            $usedLandings = $DB->query("SELECT COUNT(*) AS cnt FROM `" . ShopHelper::table('landing_page_attribute_values') . "`
                WHERE `attribute_value_id` IN ({$ids})");
            if ((!empty($usedProducts[0]['cnt']) && (int)$usedProducts[0]['cnt'] > 0)
                || (!empty($usedVariants[0]['cnt']) && (int)$usedVariants[0]['cnt'] > 0)
                || (!empty($usedLandings[0]['cnt']) && (int)$usedLandings[0]['cnt'] > 0)) {
                $_SESSION['errors'] = 'Атрибут используется в товарах или посадочных страницах и не может быть удалён.';
                shop_admin_go(array('tab' => 'attribute', 'id' => $id));
            }
            $DB->query("DELETE FROM `" . ShopHelper::table('attribute_values') . "` WHERE `id` IN ({$ids})");
        }
        $DB->query("DELETE FROM `" . ShopHelper::table('attributes') . "` WHERE `id` = {$id}");
        $_SESSION['message'] = 'Атрибут удалён.';
        shop_admin_go(array('tab' => 'attributes'));
    }

    if ($action === 'value_save') {
        $attributeId = isset($_POST['attribute_id']) ? (int)$_POST['attribute_id'] : 0;
        $id = isset($_POST['value_id']) ? (int)$_POST['value_id'] : 0;
        if ($id > 0) {
            $valueRows = $DB->query("SELECT `attribute_id` FROM `" . ShopHelper::table('attribute_values') . "`
                WHERE `id` = {$id} LIMIT 1");
            if (empty($valueRows[0])) {
                $_SESSION['errors'] = 'Значение атрибута не найдено.';
                shop_admin_go(array('tab' => 'attributes'));
            }
            $attributeId = (int)$valueRows[0]['attribute_id'];
        }
        $title = trim((string)(isset($_POST['title']) ? $_POST['title'] : ''));
        if ($attributeId < 1 || $title === '') {
            $_SESSION['errors'] = 'Укажите значение атрибута.';
            shop_admin_go(array('tab' => 'attribute', 'id' => $attributeId));
        }
        $now = date('Y-m-d H:i:s');
        $data = array(
            'attribute_id' => $attributeId,
            'title' => $title,
            'position' => isset($_POST['position']) ? (int)$_POST['position'] : 0,
            'active' => !empty($_POST['active']) ? 1 : 0,
            'updated_at' => $now,
        );
        if ($id > 0) {
            $data['id'] = $id;
            $DB->save('shop_attribute_values', $data);
        } else {
            $data['created_at'] = $now;
            $id = (int)$DB->save('shop_attribute_values', $data);
        }
        shop_admin_resync_value_products($id);
        $_SESSION['message'] = 'Значение атрибута сохранено.';
        shop_admin_go(array('tab' => 'attribute', 'id' => $attributeId));
    }

    if ($action === 'value_delete') {
        $id = isset($_POST['value_id']) ? (int)$_POST['value_id'] : 0;
        $attributeId = isset($_POST['attribute_id']) ? (int)$_POST['attribute_id'] : 0;
        $usedProducts = $DB->query("SELECT COUNT(*) AS cnt FROM `" . ShopHelper::table('product_attribute_values') . "`
            WHERE `attribute_value_id` = {$id}");
        $usedVariants = $DB->query("SELECT COUNT(*) AS cnt FROM `" . ShopHelper::table('variant_values') . "`
            WHERE `attribute_value_id` = {$id}");
        $usedLandings = $DB->query("SELECT COUNT(*) AS cnt FROM `" . ShopHelper::table('landing_page_attribute_values') . "`
            WHERE `attribute_value_id` = {$id}");
        if ((!empty($usedProducts[0]['cnt']) && (int)$usedProducts[0]['cnt'] > 0)
            || (!empty($usedVariants[0]['cnt']) && (int)$usedVariants[0]['cnt'] > 0)
            || (!empty($usedLandings[0]['cnt']) && (int)$usedLandings[0]['cnt'] > 0)) {
            $_SESSION['errors'] = 'Значение используется в товарах или посадочных страницах и не может быть удалено.';
            shop_admin_go(array('tab' => 'attribute', 'id' => $attributeId));
        }
        $DB->query("DELETE FROM `" . ShopHelper::table('attribute_values') . "` WHERE `id` = {$id}");
        $_SESSION['message'] = 'Значение атрибута удалено.';
        shop_admin_go(array('tab' => 'attribute', 'id' => $attributeId));
    }

    if ($action === 'category_save') {
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        $existing = $id > 0 ? ShopHelper::getCategory($id) : false;
        if ($id > 0 && !$existing) {
            $_SESSION['errors'] = 'Категория не найдена.';
            shop_admin_go(array('tab' => 'categories'));
        }
        $title = trim((string)(isset($_POST['title']) ? $_POST['title'] : ''));
        if ($title === '') {
            $_SESSION['errors'] = 'Укажите название категории.';
            shop_admin_go(array('tab' => 'category', 'id' => $id));
        }
        $parentId = isset($_POST['parent_id']) ? (int)$_POST['parent_id'] : 0;
        if ($parentId < 0) $parentId = 0;
        if ($parentId === $id) $parentId = 0;
        if ($id > 0 && in_array($parentId, ShopHelper::categoryDescendantIds($id), true)) {
            $parentId = 0;
        }

        $slugSource = trim((string)(isset($_POST['clean_url_title']) ? $_POST['clean_url_title'] : ''));
        if ($slugSource === '') $slugSource = $title;
        $slug = ShopHelper::uniqueSlug('shop_categories', $slugSource, $id);

        $oldImage = $existing && !empty($existing['image']) ? $existing['image'] : '';
        $removeImage = !empty($_POST['remove_image']);
        $newImage = shop_admin_upload_image('image', $removeImage ? '' : $oldImage);
        if ($newImage !== $oldImage && $oldImage !== '') shop_admin_delete_upload($oldImage);

        $now = date('Y-m-d H:i:s');
        $data = array(
            'parent_id' => $parentId,
            'title' => $title,
            'clean_url_title' => $slug,
            'description' => (string)(isset($_POST['description']) ? $_POST['description'] : ''),
            'image' => $newImage,
            'position' => isset($_POST['position']) ? (int)$_POST['position'] : 0,
            'active' => !empty($_POST['active']) ? 1 : 0,
            'meta_title' => trim((string)(isset($_POST['meta_title']) ? $_POST['meta_title'] : '')),
            'meta_description' => trim((string)(isset($_POST['meta_description']) ? $_POST['meta_description'] : '')),
            'meta_keywords' => trim((string)(isset($_POST['meta_keywords']) ? $_POST['meta_keywords'] : '')),
            'created_at' => $existing && !empty($existing['created_at']) ? $existing['created_at'] : $now,
            'updated_at' => $now,
        );
        if ($id > 0) $data['id'] = $id;
        $DB->save('shop_categories', $data);

        $_SESSION['message'] = 'Категория сохранена.';
        shop_admin_go(array('tab' => 'categories'));
    }

    if ($action === 'category_delete') {
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        $children = $DB->query("SELECT COUNT(*) AS cnt FROM `" . ShopHelper::table('categories') . "`
            WHERE `parent_id` = {$id}");
        $products = $DB->query("SELECT COUNT(*) AS cnt FROM `" . ShopHelper::table('products') . "`
            WHERE `category_id` = {$id}");
        $landings = $DB->query("SELECT COUNT(*) AS cnt FROM `" . ShopHelper::table('landing_page_categories') . "`
            WHERE `category_id` = {$id}");
        if ((!empty($children[0]['cnt']) && (int)$children[0]['cnt'] > 0)
            || (!empty($products[0]['cnt']) && (int)$products[0]['cnt'] > 0)
            || (!empty($landings[0]['cnt']) && (int)$landings[0]['cnt'] > 0)) {
            $_SESSION['errors'] = 'Категория содержит подкатегории, товары или посадочные страницы и не может быть удалена.';
            shop_admin_go(array('tab' => 'categories'));
        }
        $category = ShopHelper::getCategory($id);
        $DB->query("DELETE FROM `" . ShopHelper::table('categories') . "` WHERE `id` = {$id}");
        if ($category && !empty($category['image'])) shop_admin_delete_upload($category['image']);
        $_SESSION['message'] = 'Категория удалена.';
        shop_admin_go(array('tab' => 'categories'));
    }

    if ($action === 'landing_save') {
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        $existing = $id > 0 ? ShopHelper::getLandingPage($id, false) : false;
        if ($id > 0 && !$existing) {
            $_SESSION['errors'] = 'Посадочная страница не найдена.';
            shop_admin_go(array('tab' => 'landings'));
        }

        $title = trim((string)(isset($_POST['title']) ? $_POST['title'] : ''));
        if ($title === '') {
            $_SESSION['errors'] = 'Укажите название посадочной страницы.';
            shop_admin_go(array('tab' => 'landing', 'id' => $id));
        }

        $categoryIds = isset($_POST['category_ids']) ? (array)$_POST['category_ids'] : array();
        $categoryIds = array_values(array_unique(array_filter(array_map('intval', $categoryIds))));
        $availableCategoryIds = array();
        foreach (ShopHelper::getCategories(false) as $category) {
            $availableCategoryIds[] = (int)$category['id'];
        }
        $categoryIds = array_values(array_intersect($categoryIds, $availableCategoryIds));
        if (!$categoryIds) {
            $_SESSION['errors'] = 'Выберите хотя бы одну категорию.';
            shop_admin_go(array('tab' => 'landing', 'id' => $id));
        }

        $valueIds = isset($_POST['attribute_value_ids']) ? (array)$_POST['attribute_value_ids'] : array();
        $valueIds = array_values(array_unique(array_filter(array_map('intval', $valueIds))));
        $availableValueIds = array();
        foreach (ShopHelper::getAttributeValues(null, false) as $value) {
            $availableValueIds[] = (int)$value['id'];
        }
        $valueIds = array_values(array_intersect($valueIds, $availableValueIds));
        if (!$valueIds) {
            $_SESSION['errors'] = 'Выберите хотя бы одно значение свойства товара.';
            shop_admin_go(array('tab' => 'landing', 'id' => $id));
        }

        $slugSource = trim((string)(isset($_POST['clean_url_title']) ? $_POST['clean_url_title'] : ''));
        if ($slugSource === '') $slugSource = $title;
        $slug = ShopHelper::uniqueSlug('shop_landing_pages', $slugSource, $id);

        $now = date('Y-m-d H:i:s');
        $data = array(
            'title' => $title,
            'clean_url_title' => $slug,
            'description' => (string)(isset($_POST['description']) ? $_POST['description'] : ''),
            'active' => !empty($_POST['active']) ? 1 : 0,
            'position' => isset($_POST['position']) ? (int)$_POST['position'] : 0,
            'meta_title' => trim((string)(isset($_POST['meta_title']) ? $_POST['meta_title'] : '')),
            'meta_description' => trim((string)(isset($_POST['meta_description']) ? $_POST['meta_description'] : '')),
            'meta_keywords' => trim((string)(isset($_POST['meta_keywords']) ? $_POST['meta_keywords'] : '')),
            'created_at' => $existing && !empty($existing['created_at']) ? $existing['created_at'] : $now,
            'updated_at' => $now,
        );
        if ($id > 0) $data['id'] = $id;
        $saveResult = $DB->save('shop_landing_pages', $data);
        if ($id < 1) $id = (int)$saveResult;

        ShopHelper::syncLandingPageCategories($id, $categoryIds);
        ShopHelper::syncLandingPageAttributeValues($id, $valueIds);

        $_SESSION['message'] = 'Посадочная страница сохранена.';
        shop_admin_go(array('tab' => 'landings'));
    }

    if ($action === 'landing_delete') {
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        $landing = $id > 0 ? ShopHelper::getLandingPage($id, false) : false;
        if (!$landing) {
            $_SESSION['errors'] = 'Посадочная страница не найдена.';
            shop_admin_go(array('tab' => 'landings'));
        }

        $DB->delete(ShopHelper::table('landing_page_categories'), array('landing_page_id' => $id));
        $DB->delete(ShopHelper::table('landing_page_attribute_values'), array('landing_page_id' => $id));
        $DB->delete(ShopHelper::table('landing_pages'), array('id' => $id));
        $_SESSION['message'] = 'Посадочная страница удалена.';
        shop_admin_go(array('tab' => 'landings'));
    }

    if ($action === 'review_approve' || $action === 'review_unpublish' || $action === 'review_delete') {
        $reviewId = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        if ($reviewId < 1) {
            $_SESSION['errors'] = 'Отзыв не найден.';
            shop_admin_go(array('tab' => 'reviews'));
        }
        if ($action === 'review_delete') {
            $ok = ShopHelper::deleteReview($reviewId);
        } else {
            $ok = ShopHelper::setReviewActive($reviewId, $action === 'review_approve');
        }
        if ($ok) {
            $_SESSION['message'] = $action === 'review_delete' ? 'Отзыв удалён.'
                : ($action === 'review_approve' ? 'Отзыв опубликован.' : 'Отзыв снят с публикации.');
        } else {
            $_SESSION['errors'] = 'Не удалось изменить отзыв.';
        }
        shop_admin_go(array('tab' => 'reviews'));
    }

    if ($action === 'review_reply') {
        $reviewId = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        if ($reviewId < 1) {
            $_SESSION['errors'] = 'Отзыв не найден.';
            shop_admin_go(array('tab' => 'reviews'));
        }
        $reply = isset($_POST['reply']) ? trim((string)$_POST['reply']) : '';
        if (ShopHelper::replyToReview($reviewId, $reply)) {
            $_SESSION['message'] = $reply === '' ? 'Ответ удалён.' : 'Ответ сохранён.';
        } else {
            $_SESSION['errors'] = 'Не удалось сохранить ответ.';
        }
        shop_admin_go(array('tab' => 'reviews'));
    }

    if ($action === 'email_template_save') {
        $code = isset($_POST['code']) ? preg_replace('/[^a-z_]/', '', (string)$_POST['code']) : '';
        $subject = isset($_POST['subject']) ? trim((string)$_POST['subject']) : '';
        $body = isset($_POST['body']) ? (string)$_POST['body'] : '';
        if (ShopHelper::saveEmailTemplate($code, $subject, $body)) {
            $_SESSION['message'] = 'Шаблон письма сохранён.';
        } else {
            $_SESSION['errors'] = 'Не удалось сохранить шаблон.';
        }
        shop_admin_go(array('tab' => 'emails', 'code' => $code));
    }

    if ($action === 'email_template_reset') {
        $code = isset($_POST['code']) ? preg_replace('/[^a-z_]/', '', (string)$_POST['code']) : '';
        if (ShopHelper::resetEmailTemplate($code)) {
            $_SESSION['message'] = 'Шаблон возвращён к встроенному тексту.';
        } else {
            $_SESSION['errors'] = 'Не удалось сбросить шаблон.';
        }
        shop_admin_go(array('tab' => 'emails', 'code' => $code));
    }

    if ($action === 'promocode_save') {
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        $existing = false;
        if ($id > 0) {
            $rows = $DB->query("SELECT * FROM `" . ShopHelper::table('promocodes') . "` WHERE `id` = {$id} LIMIT 1");
            $existing = $rows[0] ?? false;
            if (!$existing) {
                $_SESSION['errors'] = 'Промокод не найден.';
                shop_admin_go(array('tab' => 'promocodes'));
            }
        }

        $code = ShopHelper::promoNormalize(isset($_POST['code']) ? $_POST['code'] : '');
        if ($code === '') {
            $_SESSION['errors'] = 'Укажите код промокода: латинские буквы, цифры, дефис или подчёркивание.';
            shop_admin_go(array('tab' => 'promocode', 'id' => $id));
        }
        $duplicate = $DB->query("SELECT `id` FROM `" . ShopHelper::table('promocodes') . "`
            WHERE `code` = '" . ShopHelper::esc($code) . "' AND `id` <> {$id} LIMIT 1");
        if (!empty($duplicate[0])) {
            $_SESSION['errors'] = 'Промокод с таким кодом уже существует.';
            shop_admin_go(array('tab' => 'promocode', 'id' => $id));
        }

        $type = (isset($_POST['discount_type']) && $_POST['discount_type'] === 'fixed')
            ? 'fixed' : 'percent';
        $value = (float)str_replace(',', '.', (string)(isset($_POST['value']) ? $_POST['value'] : '0'));
        if ($value <= 0 || ($type === 'percent' && $value > 100)) {
            $_SESSION['errors'] = $type === 'percent'
                ? 'Процент скидки должен быть больше 0 и не больше 100.'
                : 'Сумма скидки должна быть больше 0.';
            shop_admin_go(array('tab' => 'promocode', 'id' => $id));
        }

        $currencies = ShopHelper::currencies(false);
        $currencyCode = strtoupper(trim((string)(isset($_POST['currency_code']) ? $_POST['currency_code'] : '')));
        if (!isset($currencies[$currencyCode])) $currencyCode = ShopHelper::activeCurrency()['code'];

        $minTotal = (float)str_replace(',', '.', (string)(isset($_POST['min_total']) ? $_POST['min_total'] : '0'));
        if ($minTotal < 0) $minTotal = 0;
        $maxUses = max(0, (int)(isset($_POST['max_uses']) ? $_POST['max_uses'] : 0));
        $startsAt = shop_admin_datetime(isset($_POST['starts_at']) ? $_POST['starts_at'] : '');
        $endsAt = shop_admin_datetime(isset($_POST['ends_at']) ? $_POST['ends_at'] : '');
        if ($startsAt === false || $endsAt === false) {
            $_SESSION['errors'] = 'Проверьте даты начала и окончания.';
            shop_admin_go(array('tab' => 'promocode', 'id' => $id));
        }
        if ($startsAt !== null && $endsAt !== null && $endsAt < $startsAt) {
            $_SESSION['errors'] = 'Дата окончания не может быть раньше даты начала.';
            shop_admin_go(array('tab' => 'promocode', 'id' => $id));
        }

        ShopHelper::ensurePromoTables();
        $now = date('Y-m-d H:i:s');
        $table = ShopHelper::table('promocodes');
        $valueSql = number_format($value, 2, '.', '');
        $minSql = number_format($minTotal, 2, '.', '');
        $startsSql = $startsAt === null ? 'NULL' : "'" . ShopHelper::esc($startsAt) . "'";
        $endsSql = $endsAt === null ? 'NULL' : "'" . ShopHelper::esc($endsAt) . "'";
        $codeSql = ShopHelper::esc($code);
        $typeSql = ShopHelper::esc($type);
        $currencySql = ShopHelper::esc($currencyCode);
        $active = !empty($_POST['active']) ? 1 : 0;
        if ($id > 0) {
            $DB->query("UPDATE `{$table}` SET
                `code` = '{$codeSql}', `discount_type` = '{$typeSql}', `value` = {$valueSql},
                `currency_code` = '{$currencySql}', `min_total` = {$minSql}, `max_uses` = {$maxUses},
                `starts_at` = {$startsSql}, `ends_at` = {$endsSql}, `active` = {$active},
                `updated_at` = '" . ShopHelper::esc($now) . "'
                WHERE `id` = {$id}");
        } else {
            $DB->query("INSERT INTO `{$table}`
                (`code`, `discount_type`, `value`, `currency_code`, `min_total`, `max_uses`,
                    `used_count`, `starts_at`, `ends_at`, `active`, `created_at`, `updated_at`)
                VALUES ('{$codeSql}', '{$typeSql}', {$valueSql}, '{$currencySql}', {$minSql}, {$maxUses},
                    0, {$startsSql}, {$endsSql}, {$active}, '" . ShopHelper::esc($now) . "',
                    '" . ShopHelper::esc($now) . "')");
        }

        $_SESSION['message'] = 'Промокод сохранён.';
        shop_admin_go(array('tab' => 'promocodes'));
    }

    if ($action === 'promocode_delete') {
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        $rows = $DB->query("SELECT `id` FROM `" . ShopHelper::table('promocodes') . "` WHERE `id` = {$id} LIMIT 1");
        if (empty($rows[0])) {
            $_SESSION['errors'] = 'Промокод не найден.';
            shop_admin_go(array('tab' => 'promocodes'));
        }
        $DB->query("DELETE FROM `" . ShopHelper::table('promocodes') . "` WHERE `id` = {$id}");
        $_SESSION['message'] = 'Промокод удалён. Заказы сохранили свою скидку.';
        shop_admin_go(array('tab' => 'promocodes'));
    }

    if ($action === 'order_create') {
        $name = trim((string)($_POST['customer_name'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        $phone = trim((string)($_POST['phone'] ?? ''));
        $address = trim((string)($_POST['address'] ?? ''));
        $comment = trim((string)($_POST['comment'] ?? ''));
        $errors = array();
        if ($name === '') $errors[] = 'Укажите имя покупателя.';
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Укажите корректный e-mail.';
        }
        if ($phone === '' || !ShopHelper::phoneValid($phone)) {
            $errors[] = 'Телефон должен содержать от 7 до 15 цифр.';
        }

        $currency = ShopHelper::activeCurrency();
        $deliveryMethods = ShopHelper::deliveryMethods($currency['code']);
        $paymentMethods = ShopHelper::paymentMethods();
        $deliveryMethod = (string)($_POST['delivery_method'] ?? '');
        $paymentMethod = (string)($_POST['payment_method'] ?? '');
        if (!isset($deliveryMethods[$deliveryMethod])) $errors[] = 'Выберите способ доставки.';
        if (!isset($paymentMethods[$paymentMethod])) $errors[] = 'Выберите способ оплаты.';
        if (isset($deliveryMethods[$deliveryMethod]) && $deliveryMethod === 'courier' && $address === '') {
            $errors[] = 'Укажите адрес доставки.';
        }

        $requested = array();
        foreach ((array)($_POST['items'] ?? array()) as $line) {
            $variantId = (int)($line['variant_id'] ?? 0);
            $qty = (int)($line['quantity'] ?? 0);
            if ($variantId < 1 || $qty < 1) continue;
            $requested[$variantId] = min(999, (isset($requested[$variantId]) ? $requested[$variantId] : 0) + $qty);
        }
        if (!$requested) $errors[] = 'Добавьте хотя бы один товар.';

        if ($errors) {
            $_SESSION['errors'] = implode(' ', $errors);
            shop_admin_go(array('tab' => 'order_create'));
        }

        $DB = ShopHelper::db();
        $variants = ShopHelper::table('variants');
        $products = ShopHelper::table('products');
        $prices = ShopHelper::table('variant_prices');
        $code = ShopHelper::esc($currency['code']);
        $now = date('Y-m-d H:i:s');
        $token = bin2hex(random_bytes(20));
        $subtotal = 0.0;
        $lines = array();
        $orderId = 0;

        try {
            ShopHelper::ensureOrderStatusHistoryTable();
            $DB->query('START TRANSACTION');
            ksort($requested);
            foreach ($requested as $variantId => $qty) {
                $rows = $DB->query("SELECT v.`stock`, v.`title` AS variant_title, v.`sku`,
                        v.`active` AS variant_active, p.`id` AS product_id, p.`title` AS product_title,
                        p.`active` AS product_active, vp.`price`
                    FROM `{$variants}` v
                    JOIN `{$products}` p ON p.`id` = v.`product_id`
                    JOIN `{$prices}` vp ON vp.`variant_id` = v.`id` AND vp.`currency_code` = '{$code}'
                    WHERE v.`id` = " . (int)$variantId . " FOR UPDATE");
                $row = $rows[0] ?? null;
                if (!$row || !$row['variant_active'] || !$row['product_active'] || (int)$row['stock'] < $qty) {
                    throw new RuntimeException('Позиция недоступна: ' . (isset($row['product_title']) ? $row['product_title'] : '#' . $variantId));
                }
                $price = (float)$row['price'];
                $total = $price * $qty;
                $subtotal += $total;
                $lines[] = array(
                    'product_id' => (int)$row['product_id'],
                    'variant_id' => (int)$variantId,
                    'product_title' => (string)$row['product_title'],
                    'variant_title' => (string)$row['variant_title'],
                    'sku' => (string)$row['sku'],
                    'price' => $price,
                    'quantity' => $qty,
                    'total' => $total,
                );
                $DB->query("UPDATE `{$variants}` SET `stock` = `stock` - {$qty} WHERE `id` = " . (int)$variantId);
            }

            $orderId = (int)$DB->save('shop_orders', array(
                'user_id' => 0,
                'status' => 'new',
                'currency_code' => $currency['code'],
                'subtotal' => $subtotal,
                'discount' => 0,
                'promocode' => '',
                'delivery_price' => (float)$deliveryMethods[$deliveryMethod]['price'],
                'total' => $subtotal + (float)$deliveryMethods[$deliveryMethod]['price'],
                'customer_name' => mb_substr($name, 0, 255),
                'email' => mb_substr($email, 0, 255),
                'phone' => mb_substr($phone, 0, 64),
                'address' => $address,
                'comment' => $comment,
                'delivery_method' => $deliveryMethod,
                'delivery_title' => $deliveryMethods[$deliveryMethod]['title'],
                'payment_method' => $paymentMethod,
                'payment_title' => $paymentMethods[$paymentMethod]['title'],
                'access_token' => $token,
                'stock_released' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ));
            if ($orderId < 1) throw new RuntimeException('Order ID was not generated');

            foreach ($lines as $line) {
                $line['order_id'] = $orderId;
                $DB->save('shop_order_items', $line);
            }
            $adminUserId = !empty($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : 0;
            $adminUserName = !empty($_SESSION['user']['name']) ? (string)$_SESSION['user']['name'] : '';
            ShopHelper::logOrderStatus($orderId, 'new', 'Заказ создан администратором.', $adminUserId, $adminUserName, $now);
            $DB->query('COMMIT');
        } catch (Throwable $e) {
            try { $DB->query('ROLLBACK'); } catch (Throwable $rollbackError) {}
            error_log('Shop admin order create failed: ' . $e->getMessage());
            $_SESSION['errors'] = $e instanceof RuntimeException ? $e->getMessage()
                : 'Не удалось создать заказ. Попробуйте ещё раз.';
            shop_admin_go(array('tab' => 'order_create'));
        }

        if (!empty($_POST['notify'])) {
            try {
                $order = ShopHelper::getOrder($orderId);
                if ($order) {
                    ShopHelper::sendOrderEmails($order,
                        ShopHelper::getOrderItems($orderId, $order['currency_code']));
                }
            } catch (Throwable $e) {
                error_log("Shop order {$orderId}: create notification failed");
            }
        }

        $_SESSION['message'] = 'Заказ создан.';
        shop_admin_go(array('tab' => 'order', 'id' => $orderId));
    }

    if ($action === 'order_items_save') {
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        $order = $id > 0 ? ShopHelper::getOrder($id) : false;
        if (!$order) {
            $_SESSION['errors'] = 'Заказ не найден.';
            shop_admin_go(array('tab' => 'orders'));
        }

        $quantities = array();
        foreach ((array)($_POST['qty'] ?? array()) as $variantId => $qty) {
            $variantId = (int)$variantId;
            $qty = (int)$qty;
            if ($variantId > 0) $quantities[$variantId] = $qty;
        }
        $newVariants = isset($_POST['new_variant']) ? (array)$_POST['new_variant'] : array();
        $newQuantities = isset($_POST['new_quantity']) ? (array)$_POST['new_quantity'] : array();
        foreach ($newVariants as $index => $variantId) {
            $variantId = (int)$variantId;
            $qty = isset($newQuantities[$index]) ? (int)$newQuantities[$index] : 0;
            if ($variantId < 1 || $qty < 1) continue;
            $quantities[$variantId] = (isset($quantities[$variantId]) ? (int)$quantities[$variantId] : 0) + $qty;
        }

        $error = '';
        if (ShopHelper::updateOrderItems($id, $quantities, $error)) {
            $_SESSION['message'] = 'Состав заказа обновлён.';
        } else {
            $_SESSION['errors'] = $error !== '' ? $error : 'Не удалось изменить состав заказа.';
        }
        shop_admin_go(array('tab' => 'order', 'id' => $id));
    }

    if ($action === 'order_mail') {
        $id = (int)($_POST['id'] ?? 0);
        $recipient = $_POST['recipient'] ?? '';
        $order = $id > 0 ? ShopHelper::getOrder($id) : false;
        if (!$order || !in_array($recipient, array('customer', 'admin'), true)) {
            $_SESSION['errors'] = 'Заказ или получатель не найден.';
            shop_admin_go(array('tab' => 'orders'));
        }
        try {
            $items = ShopHelper::getOrderItems($id, $order['currency_code']);
            $sent = ShopHelper::sendOrderEmails($order, $items, $recipient);
        } catch (Throwable $e) {
            error_log("Shop order {$id}: could not process notification");
            $sent = false;
        }
        if ($sent) {
            $_SESSION['message'] = 'Уведомление передано почтовой службе.';
        } else {
            $_SESSION['errors'] = 'Уведомление не отправлено. Проверьте адрес, настройки почты и статус отправки.';
        }
        shop_admin_go(array('tab' => 'order', 'id' => $id));
    }

    if ($action === 'order_status') {
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        $status = isset($_POST['status']) ? (string)$_POST['status'] : '';
        $note = isset($_POST['note']) && is_string($_POST['note']) ? trim($_POST['note']) : '';
        $notify = !empty($_POST['notify']);
        $order = $id > 0 ? ShopHelper::getOrder($id) : false;
        if (!$order) {
            $_SESSION['errors'] = 'Заказ не найден.';
            shop_admin_go(array('tab' => 'orders'));
        }
        $statuses = ShopHelper::statuses();
        if (!isset($statuses[$status])) {
            $_SESSION['errors'] = 'Неизвестный статус заказа.';
            shop_admin_go(array('tab' => 'order', 'id' => $id));
        }
        if ($order['status'] === 'cancelled' && $status !== 'cancelled') {
            $_SESSION['errors'] = 'Отменённый заказ нельзя вернуть в работу. Создайте новый заказ.';
            shop_admin_go(array('tab' => 'order', 'id' => $id));
        }
        if ($order['status'] === $status) {
            $_SESSION['message'] = 'Статус заказа не изменился.';
            shop_admin_go(array('tab' => 'order', 'id' => $id));
        }
        $adminUserId = !empty($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : 0;
        $adminUserName = !empty($_SESSION['user']['name']) ? (string)$_SESSION['user']['name'] : '';
        if (!ShopHelper::setOrderStatus($id, $status, $note, $adminUserId, $adminUserName)) {
            $_SESSION['errors'] = 'Не удалось изменить статус заказа.';
        } else {
            $_SESSION['message'] = 'Статус заказа обновлён.';
            if ($notify) {
                try {
                    $updated = ShopHelper::getOrder($id);
                    $sent = $updated && ShopHelper::sendOrderEmails($updated,
                        ShopHelper::getOrderItems($id, $updated['currency_code']), 'customer', true);
                    if (!$sent) $_SESSION['message'] .= ' Письмо покупателю отправить не удалось.';
                } catch (Throwable $e) {
                    error_log("Shop order {$id}: status notification failed");
                    $_SESSION['message'] .= ' Письмо покупателю отправить не удалось.';
                }
            }
        }
        shop_admin_go(array('tab' => 'order', 'id' => $id));
    }

    if ($action === 'order_tracking') {
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        $order = $id > 0 ? ShopHelper::getOrder($id) : false;
        if (!$order) {
            $_SESSION['errors'] = 'Заказ не найден.';
            shop_admin_go(array('tab' => 'orders'));
        }

        $number = isset($_POST['tracking_number']) ? (string)$_POST['tracking_number'] : '';
        $url = isset($_POST['tracking_url']) ? (string)$_POST['tracking_url'] : '';
        if (!ShopHelper::trackingUrlValid($url)) {
            $_SESSION['errors'] = 'Ссылка на трек должна начинаться с http:// или https://.';
            shop_admin_go(array('tab' => 'order', 'id' => $id));
        }
        if (!ShopHelper::setOrderTracking($id, $number, $url)) {
            $_SESSION['errors'] = 'Не удалось сохранить данные отслеживания.';
        } else {
            $_SESSION['message'] = 'Данные отслеживания сохранены.';
            if (!empty($_POST['notify'])) {
                try {
                    $updated = ShopHelper::getOrder($id);
                    $sent = $updated && ShopHelper::sendOrderEmails($updated,
                        ShopHelper::getOrderItems($id, $updated['currency_code']), 'customer', true);
                    if (!$sent) $_SESSION['message'] .= ' Письмо покупателю отправить не удалось.';
                } catch (Throwable $e) {
                    error_log("Shop order {$id}: tracking notification failed");
                    $_SESSION['message'] .= ' Письмо покупателю отправить не удалось.';
                }
            }
        }
        shop_admin_go(array('tab' => 'order', 'id' => $id));
    }
}

/* -------------------------------------------------------------------------
 * Exports
 * ---------------------------------------------------------------------- */

if ($tab === 'products' && isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="shop-products-' . date('Ymd-His') . '.csv"');
    header('X-Content-Type-Options: nosniff');
    echo "\xEF\xBB\xBF";

    $out = fopen('php://output', 'w');
    fputcsv($out, array(
        'title', 'slug', 'category', 'short_description', 'description', 'sku', 'price',
        'stock', 'active', 'featured', 'meta_title', 'meta_description', 'meta_keywords',
    ), ';');
    foreach (ShopHelper::exportProductsCsv() as $row) {
        fputcsv($out, array_map('shop_admin_csv_cell', array(
            $row['title'], $row['slug'], $row['category'], $row['short_description'],
            $row['description'], $row['sku'], $row['price'], $row['stock'],
            $row['active'], $row['featured'], $row['meta_title'],
            $row['meta_description'], $row['meta_keywords'],
        )), ';');
    }
    fclose($out);
    exit;
}

if ($tab === 'products' && isset($_GET['export']) && $_GET['export'] === 'variants') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="shop-variants-' . date('Ymd-His') . '.csv"');
    header('X-Content-Type-Options: nosniff');
    echo "\xEF\xBB\xBF";

    $out = fopen('php://output', 'w');
    fputcsv($out, array(
        'product_slug', 'product_title', 'variant_id', 'variant_title', 'sku', 'stock',
        'active', 'position', 'currency', 'price', 'compare_price',
    ), ';');
    foreach (ShopHelper::exportVariantsCsv() as $row) {
        fputcsv($out, array_map('shop_admin_csv_cell', array(
            $row['product_slug'], $row['product_title'], $row['variant_id'],
            $row['variant_title'], $row['sku'], $row['stock'], $row['active'],
            $row['position'], $row['currency'], $row['price'], $row['compare_price'],
        )), ';');
    }
    fclose($out);
    exit;
}

if ($tab === 'orders' && isset($_GET['export']) && $_GET['export'] === 'csv') {
    list($whereSql) = shop_admin_orders_where();
    $rows = ShopHelper::db()->query("SELECT * FROM `" . ShopHelper::table('orders') . "`{$whereSql}
        ORDER BY `id` DESC");

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="shop-orders-' . date('Ymd-His') . '.csv"');
    header('X-Content-Type-Options: nosniff');
    echo "\xEF\xBB\xBF";

    $out = fopen('php://output', 'w');
    fputcsv($out, array(
        'Номер', 'Дата', 'Статус', 'Покупатель', 'E-mail', 'Телефон', 'Доставка',
        'Адрес', 'Оплата', 'Товары', 'Промокод', 'Скидка', 'Доставка, сумма', 'Итого', 'Валюта',
        'Отслеживание', 'Ссылка', 'Комментарий',
    ), ';');
    foreach ($rows as $order) {
        fputcsv($out, array_map('shop_admin_csv_cell', array(
            ShopHelper::orderNumber($order['id']),
            ShopHelper::formatDate($order['created_at']),
            ShopHelper::statusLabel($order['status']),
            $order['customer_name'],
            $order['email'],
            $order['phone'],
            $order['delivery_title'],
            $order['address'],
            $order['payment_title'],
            $order['subtotal'],
            isset($order['promocode']) ? $order['promocode'] : '',
            isset($order['discount']) ? $order['discount'] : 0,
            $order['delivery_price'],
            $order['total'],
            $order['currency_code'],
            isset($order['tracking_number']) ? $order['tracking_number'] : '',
            isset($order['tracking_url']) ? $order['tracking_url'] : '',
            $order['comment'],
        )), ';');
    }
    fclose($out);
    exit;
}

if ($tab === 'order' && !empty($_GET['print'])) {
    header('Content-Type: text/html; charset=utf-8');
    header('X-Robots-Tag: noindex');
    echo shop_admin_order_print();
    exit;
}

/* -------------------------------------------------------------------------
 * Views
 * ---------------------------------------------------------------------- */

$shopCssVersion = @filemtime(ROOT . '/admin/template/css/shop.css');
$output = '<link rel="stylesheet" href="' . h(WWW_ROOT . '/admin/template/css/shop.css' . ($shopCssVersion ? '?v=' . $shopCssVersion : '')) . '" />';
$output .= '<div class="shop-admin">';
$output .= shop_admin_tabs($tab, $canProducts, $canOrders, $canSettings);

if ($tab === 'dashboard') {
    $output .= shop_admin_dashboard($canProducts, $canOrders);
} elseif ($tab === 'products') {
    $output .= shop_admin_products();
} elseif ($tab === 'product') {
    $output .= shop_admin_product_form();
} elseif ($tab === 'variants') {
    $output .= shop_admin_variants();
} elseif ($tab === 'attributes') {
    $output .= shop_admin_attributes();
} elseif ($tab === 'attribute') {
    $output .= shop_admin_attribute_form();
} elseif ($tab === 'categories') {
    $output .= shop_admin_categories();
} elseif ($tab === 'category') {
    $output .= shop_admin_category_form();
} elseif ($tab === 'landings') {
    $output .= shop_admin_landings();
} elseif ($tab === 'landing') {
    $output .= shop_admin_landing_form();
} elseif ($tab === 'reviews') {
    $output .= shop_admin_reviews();
} elseif ($tab === 'emails') {
    $output .= shop_admin_emails();
} elseif ($tab === 'promocodes') {
    $output .= shop_admin_promocodes();
} elseif ($tab === 'promocode') {
    $output .= shop_admin_promocode_form();
} elseif ($tab === 'orders') {
    $output .= shop_admin_orders();
} elseif ($tab === 'order') {
    $output .= shop_admin_order();
} elseif ($tab === 'order_create') {
    $output .= shop_admin_order_form();
}

$output .= '</div>';

if (in_array($tab, array('product', 'category', 'landing'), true)) {
    $output .= shop_admin_editor_script();
}

include_once ROOT . '/admin/template/header.php';
echo $output;
include_once ROOT . '/admin/template/footer.php';

/* -------------------------------------------------------------------------
 * Shared helpers
 * ---------------------------------------------------------------------- */

function shop_admin_orders_where()
{
    $status = isset($_GET['status']) ? preg_replace('/[^a-z_]/', '', (string)$_GET['status']) : '';
    $statuses = ShopHelper::statuses();
    if ($status !== '' && !isset($statuses[$status])) $status = '';
    $q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';

    $where = array();
    if ($status !== '') $where[] = "`status` = '" . ShopHelper::esc($status) . "'";
    if ($q !== '') {
        $escaped = ShopHelper::esc($q);
        $where[] = "(`customer_name` LIKE '%{$escaped}%' OR `email` LIKE '%{$escaped}%'
            OR `phone` LIKE '%{$escaped}%' OR CAST(`id` AS CHAR) LIKE '%{$escaped}%')";
    }
    return array($where ? ' WHERE ' . implode(' AND ', $where) : '', $status, $q);
}

function shop_admin_csv_cell($value)
{
    $value = (string)$value;
    if ($value !== '' && strpos('=+-@', $value[0]) !== false) return "'" . $value;
    return $value;
}

/** Empty input means "no date"; false means the value could not be parsed. */
function shop_admin_datetime($value)
{
    $value = trim((string)$value);
    if ($value === '') return null;
    $time = strtotime(str_replace('T', ' ', $value));
    return $time ? date('Y-m-d H:i:s', $time) : false;
}

function shop_admin_order_print()
{
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    $order = $id > 0 ? ShopHelper::getOrder($id) : false;
    if (!$order) return '<!doctype html><html lang="ru"><head><meta charset="utf-8" /><title>Заказ не найден</title></head><body><p>Заказ не найден.</p></body></html>';

    $items = ShopHelper::getOrderItems($id, $order['currency_code']);
    $site = (string)Config::read('title');
    $email = (string)Config::read('admin_email');

    ob_start(); ?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8" />
<meta name="robots" content="noindex" />
<title>Счёт №<?php echo h($order['number']); ?></title>
<style>
    body { margin: 0; padding: 24px; font: 14px/1.45 Arial, sans-serif; color: #222; background: #fff; }
    .invoice { max-width: 800px; margin: 0 auto; }
    .invoice-head { display: flex; justify-content: space-between; gap: 20px; border-bottom: 2px solid #222; padding-bottom: 12px; }
    .invoice-head h1 { margin: 0 0 4px; font-size: 22px; }
    .invoice-muted { color: #666; }
    .invoice-grid { display: flex; flex-wrap: wrap; gap: 24px; margin: 18px 0; }
    .invoice-grid > div { flex: 1 1 240px; }
    table { width: 100%; border-collapse: collapse; margin: 16px 0; }
    th, td { border: 1px solid #bbb; padding: 7px 9px; text-align: left; vertical-align: top; }
    th { background: #f2f2f2; }
    td.num, th.num { text-align: right; }
    .invoice-totals { width: 320px; margin-left: auto; }
    .invoice-totals div { display: flex; justify-content: space-between; padding: 4px 0; }
    .invoice-totals .total { border-top: 1px solid #222; margin-top: 4px; padding-top: 8px; font-weight: bold; }
    .invoice-actions { margin: 0 0 18px; }
    .invoice-actions button { padding: 8px 16px; cursor: pointer; }
    @media print {
        body { padding: 0; }
        .invoice-actions { display: none; }
    }
</style>
</head>
<body>
<div class="invoice">
    <div class="invoice-actions"><button type="button" onclick="window.print()">Печать</button></div>
    <div class="invoice-head">
        <div>
            <h1>Счёт №<?php echo h($order['number']); ?></h1>
            <div class="invoice-muted">от <?php echo h($order['created_at_formatted']); ?></div>
        </div>
        <div>
            <strong><?php echo h($site); ?></strong>
            <?php if ($email !== ''): ?><div class="invoice-muted"><?php echo h($email); ?></div><?php endif; ?>
            <div class="invoice-muted">Статус: <?php echo h($order['status_label']); ?></div>
        </div>
    </div>

    <div class="invoice-grid">
        <div>
            <strong>Покупатель</strong><br />
            <?php echo h($order['customer_name']); ?><br />
            <?php echo h($order['email']); ?><br />
            <?php echo h($order['phone']); ?>
        </div>
        <div>
            <strong>Доставка</strong><br />
            <?php echo h($order['delivery_title']); ?><br />
            <?php echo nl2br(h($order['address'])); ?>
        </div>
        <div>
            <strong>Оплата</strong><br />
            <?php echo h($order['payment_title']); ?>
            <?php if ($order['tracking_number'] !== ''): ?>
            <br /><strong>Трек:</strong> <?php echo h($order['tracking_number']); ?>
            <?php endif; ?>
        </div>
    </div>

    <table>
        <thead><tr><th>Товар</th><th>Артикул</th><th class="num">Цена</th><th class="num">Кол-во</th><th class="num">Итого</th></tr></thead>
        <tbody>
        <?php foreach ($items as $item): ?>
            <tr>
                <td><?php echo h($item['product_title']); ?><?php if ($item['variant_title'] !== ''): ?><div class="invoice-muted"><?php echo h($item['variant_title']); ?></div><?php endif; ?></td>
                <td><?php echo h($item['sku']); ?></td>
                <td class="num"><?php echo h($item['price_formatted']); ?></td>
                <td class="num"><?php echo (int)$item['quantity']; ?></td>
                <td class="num"><?php echo h($item['total_formatted']); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <div class="invoice-totals">
        <div><span>Товары:</span><span><?php echo h($order['subtotal_formatted']); ?></span></div>
        <?php if (!empty($order['discount'])): ?>
        <div><span>Скидка<?php echo $order['promocode'] !== '' ? ' (' . h($order['promocode']) . ')' : ''; ?>:</span><span>−<?php echo h($order['discount_formatted']); ?></span></div>
        <?php endif; ?>
        <div><span>Доставка:</span><span><?php echo h($order['delivery_price_formatted']); ?></span></div>
        <div class="total"><span>Итого:</span><span><?php echo h($order['total_formatted']); ?></span></div>
    </div>

    <?php if (!empty($order['comment'])): ?>
    <p><strong>Комментарий:</strong><br /><?php echo nl2br(h($order['comment'])); ?></p>
    <?php endif; ?>
</div>
</body>
</html>
    <?php
    return ob_get_clean();
}

function shop_admin_url($params = array())
{
    if (!isset($params['tab'])) $params['tab'] = 'dashboard';
    return get_url('/admin/shop.php?' . http_build_query($params));
}

function shop_admin_go($params = array())
{
    redirect('/admin/shop.php?' . http_build_query($params));
}

function shop_admin_csrf()
{
    return '<input type="hidden" name="csrf_token" value="' . h(atm_admin_csrf_token()) . '" />';
}

function shop_admin_editor_script()
{
    $base = h(WWW_ROOT . '/sys/plugins/wysibb/public');
    $emojiBase = h(WWW_ROOT . '/sys/js/emoji');
    return '<link rel="stylesheet" href="' . $base . '/wbbtheme.css" />'
        . '<link rel="stylesheet" href="' . $emojiBase . '/emoji-picker.css" />'
        . '<script src="' . $base . '/jquery.wysibb.min.js"></script>'
        . '<script src="' . $base . '/fapos2.js"></script>'
        . '<script src="' . $emojiBase . '/emoji-picker.js"></script>'
        . '<script>'
        . '$(function () {'
        . '    $(".shop-admin-editor").wysibb();'
        . '});'
        . '</script>';
}

function shop_admin_currency_codes()
{
    return array_keys(ShopHelper::currencies(false));
}

function shop_admin_resync_attribute_products($attributeId)
{
    $attributeId = (int)$attributeId;
    if ($attributeId < 1) return;
    $DB = ShopHelper::db();
    $rows = $DB->query("SELECT DISTINCT pav.`product_id`
        FROM `" . ShopHelper::table('product_attribute_values') . "` pav
        INNER JOIN `" . ShopHelper::table('attribute_values') . "` av ON av.`id` = pav.`attribute_value_id`
        WHERE av.`attribute_id` = {$attributeId}");
    foreach ($rows as $row) {
        $productId = (int)$row['product_id'];
        if ($productId > 0) ShopHelper::syncProductVariants($productId);
    }
}

function shop_admin_resync_value_products($valueId)
{
    $valueId = (int)$valueId;
    if ($valueId < 1) return;
    $DB = ShopHelper::db();
    $rows = $DB->query("SELECT DISTINCT `product_id`
        FROM `" . ShopHelper::table('product_attribute_values') . "`
        WHERE `attribute_value_id` = {$valueId}");
    foreach ($rows as $row) {
        $productId = (int)$row['product_id'];
        if ($productId > 0) ShopHelper::syncProductVariants($productId);
    }
}

function shop_admin_image_error($message)
{
    if (!empty($_SESSION['errors'])) {
        $_SESSION['errors'] .= '<br />' . $message;
    } else {
        $_SESSION['errors'] = $message;
    }
}

function shop_admin_save_uploaded_image($tmp)
{
    $info = @getimagesize($tmp);
    if (!$info) {
        shop_admin_image_error('Файл не является изображением.');
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
        shop_admin_image_error('Допустимы только изображения JPG, PNG, GIF или WEBP.');
        return '';
    }
    $extension = $typeMap[$type];
    if (atm_validate_upload(array('name'=>'image.'.$extension,'tmp_name'=>$tmp), true) === false) {
        shop_admin_image_error('Недопустимое изображение или превышен размер файла.');
        return '';
    }

    $fullDir = ROOT . '/sys/files/shop/full';
    $previewDir = ROOT . '/sys/files/shop/preview';
    if (!is_dir($fullDir)) @mkdir($fullDir, 0775, true);
    if (!is_dir($previewDir)) @mkdir($previewDir, 0775, true);

    $filename = '';
    for ($attempt = 0; $attempt < 10; $attempt++) {
        $candidate = 'shop_' . date('YmdHis') . '_' . mt_rand(100000, 999999) . '.' . $extension;
        if (!file_exists($fullDir . '/' . $candidate) && !file_exists($previewDir . '/' . $candidate)) {
            $filename = $candidate;
            break;
        }
    }
    if ($filename === '') {
        shop_admin_image_error('Не удалось подобрать имя для изображения.');
        return '';
    }

    $fullPath = $fullDir . '/' . $filename;
    $previewPath = $previewDir . '/' . $filename;
    if (!@move_uploaded_file($tmp, $fullPath)) {
        shop_admin_image_error('Не удалось сохранить изображение.');
        return '';
    }

    if (!function_exists('resampleImage')) include_once ROOT . '/sys/fnc/thumb.php';
    if (!resampleImage($fullPath, $previewPath, 600, 600) && !@copy($fullPath, $previewPath)) {
        @unlink($previewPath);
    }
    return $filename;
}

function shop_admin_copy_upload($filename)
{
    $filename = basename(trim((string)$filename));
    if ($filename === '') return '';

    $fullDir = ROOT . '/sys/files/shop/full';
    $previewDir = ROOT . '/sys/files/shop/preview';
    $source = $fullDir . '/' . $filename;
    if (!is_file($source)) return '';

    $extension = strtolower((string)pathinfo($filename, PATHINFO_EXTENSION));
    if (!in_array($extension, array('jpg', 'jpeg', 'png', 'gif', 'webp'), true)) return '';

    $copy = '';
    for ($attempt = 0; $attempt < 10; $attempt++) {
        $candidate = 'shop_' . date('YmdHis') . '_' . mt_rand(100000, 999999) . '.' . $extension;
        if (!file_exists($fullDir . '/' . $candidate)) {
            $copy = $candidate;
            break;
        }
    }
    if ($copy === '' || !@copy($source, $fullDir . '/' . $copy)) return '';

    $previewSource = $previewDir . '/' . $filename;
    $previewTarget = $previewDir . '/' . $copy;
    if (!is_file($previewSource) || !@copy($previewSource, $previewTarget)) {
        if (!function_exists('resampleImage')) include_once ROOT . '/sys/fnc/thumb.php';
        if (!resampleImage($fullDir . '/' . $copy, $previewTarget, 600, 600)) {
            @copy($fullDir . '/' . $copy, $previewTarget);
        }
    }
    return $copy;
}

function shop_admin_upload_image($field, $old = '')
{
    if (empty($_FILES[$field]) || !isset($_FILES[$field]['error'])
        || (int)$_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) {
        return $old;
    }
    if ((int)$_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
        shop_admin_image_error('Не удалось загрузить изображение.');
        return $old;
    }

    $filename = shop_admin_save_uploaded_image($_FILES[$field]['tmp_name']);
    return $filename !== '' ? $filename : $old;
}

function shop_admin_upload_product_gallery($productId)
{
    $productId = (int)$productId;
    if ($productId < 1 || empty($_FILES['gallery']) || !isset($_FILES['gallery']['name'])) return 0;

    $names = is_array($_FILES['gallery']['name']) ? $_FILES['gallery']['name'] : array($_FILES['gallery']['name']);
    $tmpNames = isset($_FILES['gallery']['tmp_name']) && is_array($_FILES['gallery']['tmp_name'])
        ? $_FILES['gallery']['tmp_name'] : array();
    $errors = isset($_FILES['gallery']['error']) && is_array($_FILES['gallery']['error'])
        ? $_FILES['gallery']['error'] : array();

    $DB = ShopHelper::db();
    $positionRows = $DB->query("SELECT COALESCE(MAX(`position`), -1) AS max_position
        FROM `" . ShopHelper::table('product_images') . "`
        WHERE `product_id` = {$productId}");
    $position = !empty($positionRows[0]['max_position']) ? (int)$positionRows[0]['max_position'] + 1 : 0;

    $added = 0;
    foreach ($names as $index => $name) {
        $error = isset($errors[$index]) ? (int)$errors[$index] : UPLOAD_ERR_NO_FILE;
        if ($error === UPLOAD_ERR_NO_FILE) continue;
        if ($error !== UPLOAD_ERR_OK) {
            shop_admin_image_error('Не удалось загрузить изображение галереи.');
            continue;
        }
        $tmp = isset($tmpNames[$index]) ? $tmpNames[$index] : '';
        if ($tmp === '') continue;
        $filename = shop_admin_save_uploaded_image($tmp);
        if ($filename === '') continue;

        $DB->save('shop_product_images', array(
            'product_id' => $productId,
            'image' => $filename,
            'position' => $position++,
            'created_at' => date('Y-m-d H:i:s'),
        ));
        $added++;
    }
    return $added;
}

function shop_admin_delete_upload($filename)
{
    $filename = basename((string)$filename);
    if ($filename === '') return;
    @unlink(ROOT . '/sys/files/shop/full/' . $filename);
    @unlink(ROOT . '/sys/files/shop/preview/' . $filename);
}

function shop_admin_remove_product_gallery($productId, $imageIds)
{
    $productId = (int)$productId;
    $imageIds = array_values(array_unique(array_filter(array_map('intval', (array)$imageIds))));
    if ($productId < 1 || !$imageIds) return;

    $DB = ShopHelper::db();
    $mainRows = $DB->query("SELECT `image` FROM `" . ShopHelper::table('products') . "`
        WHERE `id` = {$productId}");
    $mainImage = !empty($mainRows[0]['image']) ? $mainRows[0]['image'] : '';

    $rows = $DB->query("SELECT `id`, `image` FROM `" . ShopHelper::table('product_images') . "`
        WHERE `product_id` = {$productId} AND `id` IN (" . implode(',', $imageIds) . ")");
    foreach ($rows as $row) {
        $DB->query("DELETE FROM `" . ShopHelper::table('product_images') . "`
            WHERE `id` = " . (int)$row['id'] . " AND `product_id` = {$productId}");
        if ($mainImage === '' || $mainImage !== $row['image']) {
            shop_admin_delete_upload($row['image']);
        }
    }
}

function shop_admin_delete_product_gallery($productId)
{
    $productId = (int)$productId;
    if ($productId < 1) return;
    $DB = ShopHelper::db();
    $rows = $DB->query("SELECT `id` FROM `" . ShopHelper::table('product_images') . "`
        WHERE `product_id` = {$productId}");
    $ids = array();
    foreach ($rows as $row) $ids[] = (int)$row['id'];
    if ($ids) shop_admin_remove_product_gallery($productId, $ids);
}

function shop_admin_category_options($selected, $excludeIds = array(), $includeRoot = true)
{
    $categories = ShopHelper::getCategories(false);
    $byId = array();
    foreach ($categories as $category) $byId[(int)$category['id']] = $category;
    $children = array();
    foreach ($categories as $category) {
        $children[(int)$category['parent_id']][] = (int)$category['id'];
    }

    $html = $includeRoot ? '<option value="0">— Корень —</option>' : '';
    $walk = function ($parentId, $level) use (&$walk, $byId, $children, $selected, $excludeIds) {
        if (empty($children[$parentId])) return '';
        $out = '';
        foreach ($children[$parentId] as $id) {
            if (in_array($id, $excludeIds, true)) continue;
            $category = $byId[$id];
            $out .= '<option value="' . $id . '"' . ((int)$selected === $id ? ' selected="selected"' : '') . '>'
                . str_repeat('— ', $level) . h($category['title']) . '</option>';
            $out .= $walk($id, $level + 1);
        }
        return $out;
    };
    return $html . $walk(0, 0);
}

function shop_admin_category_checkboxes($selectedIds = array())
{
    $selectedIds = array_values(array_unique(array_filter(array_map('intval', (array)$selectedIds))));
    $categories = ShopHelper::getCategories(false);
    $byId = array();
    $children = array();
    foreach ($categories as $category) {
        $id = (int)$category['id'];
        $byId[$id] = $category;
        $children[(int)$category['parent_id']][] = $id;
    }

    $walk = function ($parentId, $level) use (&$walk, $byId, $children, $selectedIds) {
        if (empty($children[$parentId])) return '';
        $out = '';
        foreach ($children[$parentId] as $id) {
            $category = $byId[$id];
            $checked = in_array($id, $selectedIds, true) ? ' checked="checked"' : '';
            $out .= '<label class="shop-admin-check-line" style="padding-left:' . ($level * 18) . 'px">'
                . '<input type="checkbox" name="category_ids[]" value="' . $id . '"' . $checked . ' /> '
                . h($category['title'])
                . (empty($category['active']) ? ' <span class="shop-admin-muted">(скрыта)</span>' : '')
                . '</label>';
            $out .= $walk($id, $level + 1);
        }
        return $out;
    };

    return $walk(0, 0);
}

function shop_admin_tabs($tab, $canProducts, $canOrders, $canSettings)
{
    $tabs = array();
    if ($canProducts || $canOrders) $tabs['dashboard'] = 'Обзор';
    if ($canProducts) {
        $tabs['products'] = 'Товары';
        $tabs['attributes'] = 'Атрибуты';
        $tabs['categories'] = 'Категории';
        $tabs['landings'] = 'Посадочные страницы';
        $tabs['reviews'] = 'Отзывы';
        $tabs['emails'] = 'Письма';
        $tabs['promocodes'] = 'Промокоды';
    }
    if ($canOrders) $tabs['orders'] = 'Заказы';

    $html = '<div class="shop-admin-tabs">';
    foreach ($tabs as $key => $title) {
        $html .= '<a class="shop-admin-tab' . ($tab === $key ? ' active' : '') . '" href="'
            . h(shop_admin_url(array('tab' => $key))) . '">' . h($title) . '</a>';
    }
    $html .= '<span class="shop-admin-tabs-right">';
    if ($canSettings) $html .= '<a href="' . h(get_url('/admin/settings.php?m=shop')) . '">Настройки</a> ';
    if ($canProducts) $html .= '<a href="' . h(get_url('/admin/design.php?m=shop')) . '">Дизайн</a>';
    $html .= '</span></div>';
    return $html;
}

function shop_admin_status_badge($status, $label)
{
    return '<span class="shop-admin-status shop-admin-status-' . h($status) . '">' . h($label) . '</span>';
}

function shop_admin_dashboard($canProducts, $canOrders)
{
    $DB = ShopHelper::db();
    $counts = array(
        'products' => 0,
        'categories' => 0,
        'attributes' => 0,
        'variants' => 0,
        'landings' => 0,
        'orders' => 0,
        'new_orders' => 0,
    );
    $map = array(
        'products' => 'products',
        'categories' => 'categories',
        'attributes' => 'attributes',
        'variants' => 'variants',
        'landings' => 'landing_pages',
        'orders' => 'orders',
    );
    foreach ($map as $key => $table) {
        $rows = $DB->query("SELECT COUNT(*) AS cnt FROM `" . ShopHelper::table($table) . "`");
        $counts[$key] = !empty($rows[0]['cnt']) ? (int)$rows[0]['cnt'] : 0;
    }
    $newRows = $DB->query("SELECT COUNT(*) AS cnt FROM `" . ShopHelper::table('orders') . "` WHERE `status` = 'new'");
    $counts['new_orders'] = !empty($newRows[0]['cnt']) ? (int)$newRows[0]['cnt'] : 0;

    $report = array(
        'currency' => ShopHelper::activeCurrency(),
        'today' => 0.0,
        'week' => 0.0,
        'month' => 0.0,
        'days' => array(),
        'products' => array(),
        'categories' => array(),
    );
    if ($canOrders) {
        $ordersTable = ShopHelper::table('orders');
        $itemsTable = ShopHelper::table('order_items');
        $code = ShopHelper::esc($report['currency']['code']);
        $today = date('Y-m-d 00:00:00');
        $week = date('Y-m-d 00:00:00', strtotime('-6 days'));
        $month = date('Y-m-d 00:00:00', strtotime('-29 days'));

        $sumRows = $DB->query("SELECT
            COALESCE(SUM(CASE WHEN `created_at` >= '{$today}' THEN `total` ELSE 0 END), 0) AS today,
            COALESCE(SUM(CASE WHEN `created_at` >= '{$week}' THEN `total` ELSE 0 END), 0) AS week,
            COALESCE(SUM(CASE WHEN `created_at` >= '{$month}' THEN `total` ELSE 0 END), 0) AS month
            FROM `{$ordersTable}`
            WHERE `status` <> 'cancelled' AND `currency_code` = '{$code}'");
        if (!empty($sumRows[0])) {
            $report['today'] = (float)$sumRows[0]['today'];
            $report['week'] = (float)$sumRows[0]['week'];
            $report['month'] = (float)$sumRows[0]['month'];
        }

        $since14 = date('Y-m-d 00:00:00', strtotime('-13 days'));
        for ($i = 13; $i >= 0; $i--) {
            $day = date('Y-m-d', strtotime("-{$i} days"));
            $report['days'][$day] = array(
                'label' => date('d.m', strtotime($day)),
                'count' => 0,
                'total' => 0.0,
            );
        }
        $dayRows = $DB->query("SELECT DATE(`created_at`) AS day, COUNT(*) AS cnt, SUM(`total`) AS total
            FROM `{$ordersTable}`
            WHERE `status` <> 'cancelled' AND `currency_code` = '{$code}'
                AND `created_at` >= '{$since14}'
            GROUP BY DATE(`created_at`)");
        foreach ($dayRows as $row) {
            $day = (string)$row['day'];
            if (!isset($report['days'][$day])) continue;
            $report['days'][$day]['count'] = (int)$row['cnt'];
            $report['days'][$day]['total'] = (float)$row['total'];
        }

        $report['products'] = $DB->query("SELECT oi.`product_title`,
                SUM(oi.`quantity`) AS qty, SUM(oi.`total`) AS total
            FROM `{$itemsTable}` oi
            INNER JOIN `{$ordersTable}` o ON o.`id` = oi.`order_id`
            WHERE o.`status` <> 'cancelled' AND o.`currency_code` = '{$code}'
            GROUP BY oi.`product_title`
            ORDER BY total DESC LIMIT 5");

        $report['categories'] = $DB->query("SELECT c.`title`,
                SUM(oi.`quantity`) AS qty, SUM(oi.`total`) AS total
            FROM `{$itemsTable}` oi
            INNER JOIN `{$ordersTable}` o ON o.`id` = oi.`order_id`
            LEFT JOIN `" . ShopHelper::table('variants') . "` v ON v.`id` = oi.`variant_id`
            LEFT JOIN `" . ShopHelper::table('products') . "` p ON p.`id` = v.`product_id`
            LEFT JOIN `" . ShopHelper::table('categories') . "` c ON c.`id` = p.`category_id`
            WHERE o.`status` <> 'cancelled' AND o.`currency_code` = '{$code}'
            GROUP BY c.`id`, c.`title`
            ORDER BY total DESC LIMIT 5");
    }

    $recent = $DB->query("SELECT * FROM `" . ShopHelper::table('orders') . "` ORDER BY `id` DESC LIMIT 8");
    $funnel = ShopHelper::funnelReport(30);
    $lowStockThreshold = max(0, (int)ShopHelper::cfg('low_stock_threshold', 3));
    $lowStock = $DB->query("SELECT v.`id`, v.`title`, v.`sku`, v.`stock`, p.`id` AS product_id, p.`title` AS product_title
        FROM `" . ShopHelper::table('variants') . "` v
        INNER JOIN `" . ShopHelper::table('products') . "` p ON p.`id` = v.`product_id`
        WHERE v.`active` = 1 AND v.`stock` <= {$lowStockThreshold}
        ORDER BY v.`stock` ASC, p.`title` ASC, v.`id` ASC LIMIT 8");

    ob_start(); ?>
    <div class="shop-admin-cards">
        <?php if ($canOrders): ?>
        <a class="shop-admin-card" href="<?php echo h(shop_admin_url(array('tab' => 'orders'))); ?>">
            <span class="shop-admin-card-value"><?php echo $counts['orders']; ?></span>
            <span class="shop-admin-card-label">Всего заказов</span>
        </a>
        <a class="shop-admin-card shop-admin-card-accent" href="<?php echo h(shop_admin_url(array('tab' => 'orders', 'status' => 'new'))); ?>">
            <span class="shop-admin-card-value"><?php echo $counts['new_orders']; ?></span>
            <span class="shop-admin-card-label">Новых заказов</span>
        </a>
        <?php endif; ?>
        <?php if ($canProducts): ?>
        <a class="shop-admin-card" href="<?php echo h(shop_admin_url(array('tab' => 'products'))); ?>">
            <span class="shop-admin-card-value"><?php echo $counts['products']; ?></span>
            <span class="shop-admin-card-label">Товаров</span>
        </a>
        <a class="shop-admin-card" href="<?php echo h(shop_admin_url(array('tab' => 'categories'))); ?>">
            <span class="shop-admin-card-value"><?php echo $counts['categories']; ?></span>
            <span class="shop-admin-card-label">Категорий</span>
        </a>
        <a class="shop-admin-card" href="<?php echo h(shop_admin_url(array('tab' => 'landings'))); ?>">
            <span class="shop-admin-card-value"><?php echo $counts['landings']; ?></span>
            <span class="shop-admin-card-label">Посадочные</span>
        </a>
        <a class="shop-admin-card" href="<?php echo h(shop_admin_url(array('tab' => 'attributes'))); ?>">
            <span class="shop-admin-card-value"><?php echo $counts['attributes']; ?></span>
            <span class="shop-admin-card-label">Атрибутов</span>
        </a>
        <a class="shop-admin-card" href="<?php echo h(shop_admin_url(array('tab' => 'products'))); ?>">
            <span class="shop-admin-card-value"><?php echo $counts['variants']; ?></span>
            <span class="shop-admin-card-label">Вариантов</span>
        </a>
        <?php endif; ?>
    </div>

    <div class="shop-admin-panel">
        <div class="shop-admin-panel-head">
            <h2>Воронка за 30 дней</h2>
            <span class="shop-admin-muted">Уникальные сессии</span>
        </div>
        <table class="grid shop-admin-table shop-admin-funnel">
            <thead><tr><th>Этап</th><th>Сессий</th><th>От просмотров</th><th>С предыдущего шага</th></tr></thead>
            <tbody>
            <?php foreach ($funnel as $step): ?>
                <tr>
                    <td><?php echo h($step['title']); ?></td>
                    <td><?php echo (int)$step['sessions']; ?></td>
                    <td><?php echo $step['rate'] > 0 ? h($step['rate'] . '%') : '—'; ?></td>
                    <td><?php echo $step['step_rate'] > 0 ? h($step['step_rate'] . '%') : '—'; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p class="shop-admin-muted">Счётчики ведутся на страницах магазина без внешних сервисов. Старые события старше 180 дней удаляются автоматически.</p>
        <p><a href="<?php echo h(get_url('/shop/yml/')); ?>" target="_blank">Открыть YML-фид для маркетплейсов</a></p>
    </div>

    <?php if ($canOrders): ?>
    <div class="shop-admin-cards">
        <div class="shop-admin-card">
            <span class="shop-admin-card-value"><?php echo h(ShopHelper::formatPrice($report['today'], $report['currency']['code'])); ?></span>
            <span class="shop-admin-card-label">Выручка за сегодня</span>
        </div>
        <div class="shop-admin-card">
            <span class="shop-admin-card-value"><?php echo h(ShopHelper::formatPrice($report['week'], $report['currency']['code'])); ?></span>
            <span class="shop-admin-card-label">Выручка за 7 дней</span>
        </div>
        <div class="shop-admin-card">
            <span class="shop-admin-card-value"><?php echo h(ShopHelper::formatPrice($report['month'], $report['currency']['code'])); ?></span>
            <span class="shop-admin-card-label">Выручка за 30 дней</span>
        </div>
    </div>

    <div class="shop-admin-columns">
        <div class="shop-admin-panel">
            <div class="shop-admin-panel-head"><h2>Продажи за 14 дней</h2><span class="shop-admin-muted"><?php echo h($report['currency']['code']); ?></span></div>
            <table class="grid shop-admin-table">
                <thead><tr><th>Дата</th><th>Заказов</th><th>Сумма</th></tr></thead>
                <tbody>
                <?php foreach ($report['days'] as $day): ?>
                    <tr>
                        <td><?php echo h($day['label']); ?></td>
                        <td><?php echo (int)$day['count']; ?></td>
                        <td><?php echo h(ShopHelper::formatPrice($day['total'], $report['currency']['code'])); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="shop-admin-panel">
            <div class="shop-admin-panel-head"><h2>Топ товаров</h2></div>
            <?php if ($report['products']): ?>
            <table class="grid shop-admin-table">
                <thead><tr><th>Товар</th><th>Кол-во</th><th>Сумма</th></tr></thead>
                <tbody>
                <?php foreach ($report['products'] as $product): ?>
                    <tr>
                        <td><?php echo h($product['product_title']); ?></td>
                        <td><?php echo (int)$product['qty']; ?></td>
                        <td><?php echo h(ShopHelper::formatPrice($product['total'], $report['currency']['code'])); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
            <div class="shop-admin-empty">Продаж пока нет.</div>
            <?php endif; ?>

            <div class="shop-admin-panel-head"><h2>Топ категорий</h2></div>
            <?php if ($report['categories']): ?>
            <table class="grid shop-admin-table">
                <thead><tr><th>Категория</th><th>Кол-во</th><th>Сумма</th></tr></thead>
                <tbody>
                <?php foreach ($report['categories'] as $category): ?>
                    <tr>
                        <td><?php echo h($category['title'] !== null && $category['title'] !== '' ? $category['title'] : 'Без категории'); ?></td>
                        <td><?php echo (int)$category['qty']; ?></td>
                        <td><?php echo h(ShopHelper::formatPrice($category['total'], $report['currency']['code'])); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
            <div class="shop-admin-empty">Продаж пока нет.</div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="shop-admin-columns">
        <div class="shop-admin-panel">
            <div class="shop-admin-panel-head">
                <h2>Последние заказы</h2>
                <?php if ($canOrders): ?><a href="<?php echo h(shop_admin_url(array('tab' => 'orders'))); ?>">Все заказы</a><?php endif; ?>
            </div>
            <?php if ($recent): ?>
            <table class="grid shop-admin-table">
                <thead><tr><th>Заказ</th><th>Покупатель</th><th>Статус</th><th>Сумма</th></tr></thead>
                <tbody>
                <?php foreach ($recent as $order): ?>
                    <tr>
                        <td><a href="<?php echo h(shop_admin_url(array('tab' => 'order', 'id' => (int)$order['id']))); ?>">№<?php echo h(ShopHelper::orderNumber($order['id'])); ?></a></td>
                        <td><?php echo h($order['customer_name']); ?><div class="shop-admin-muted"><?php echo h($order['email']); ?></div></td>
                        <td><?php echo shop_admin_status_badge($order['status'], ShopHelper::statusLabel($order['status'])); ?></td>
                        <td><?php echo h(ShopHelper::formatPrice($order['total'], $order['currency_code'])); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
            <div class="shop-admin-empty">Заказов пока нет.</div>
            <?php endif; ?>
        </div>

        <?php if ($canProducts): ?>
        <div class="shop-admin-panel">
            <div class="shop-admin-panel-head">
                <h2>Низкий остаток</h2>
                <a href="<?php echo h(shop_admin_url(array('tab' => 'products'))); ?>">Товары</a>
            </div>
            <?php if ($lowStock): ?>
            <table class="grid shop-admin-table">
                <thead><tr><th>Товар</th><th>Вариант</th><th>Остаток</th></tr></thead>
                <tbody>
                <?php foreach ($lowStock as $variant): ?>
                    <tr>
                        <td><a href="<?php echo h(shop_admin_url(array('tab' => 'variants', 'id' => (int)$variant['product_id']))); ?>"><?php echo h($variant['product_title']); ?></a></td>
                        <td><?php echo h($variant['title']); ?><div class="shop-admin-muted"><?php echo h($variant['sku']); ?></div></td>
                        <td><?php echo (int)$variant['stock']; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
            <div class="shop-admin-empty">Все варианты в наличии.</div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}

function shop_admin_products()
{
    $q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
    $page = max(1, (int)(isset($_GET['page']) ? $_GET['page'] : 1));
    $limit = 30;
    $result = ShopHelper::getProducts(array(
        'active_only' => false,
        'q' => $q,
        'page' => $page,
        'limit' => $limit,
        'sort' => 'new',
    ));

    $ids = array();
    foreach ($result['items'] as $item) $ids[] = (int)$item['id'];
    $variantCounts = array();
    if ($ids) {
        $rows = ShopHelper::db()->query("SELECT `product_id`, COUNT(*) AS cnt FROM `"
            . ShopHelper::table('variants') . "` WHERE `active` = 1 AND `product_id` IN ("
            . implode(',', $ids) . ") GROUP BY `product_id`");
        foreach ($rows as $row) $variantCounts[(int)$row['product_id']] = (int)$row['cnt'];
    }

    $base = '/admin/shop.php?tab=products' . ($q !== '' ? '&q=' . rawurlencode($q) : '');
    list($pagination, $currentPage) = pagination($result['total'], $limit, $base);

    ob_start(); ?>
    <div class="shop-admin-toolbar">
        <form method="get" action="<?php echo h(get_url('/admin/shop.php')); ?>">
            <input type="hidden" name="tab" value="products" />
            <input type="text" name="q" value="<?php echo h($q); ?>" placeholder="Название товара" />
            <button type="submit" class="shop-admin-btn">Найти</button>
        </form>
        <a class="shop-admin-btn shop-admin-btn-primary" href="<?php echo h(shop_admin_url(array('tab' => 'product', 'id' => 0))); ?>">Добавить товар</a>
        <a class="shop-admin-btn" href="<?php echo h(shop_admin_url(array('tab' => 'products', 'export' => 'csv'))); ?>">Экспорт CSV</a>
        <a class="shop-admin-btn" href="<?php echo h(shop_admin_url(array('tab' => 'products', 'export' => 'variants'))); ?>">Экспорт вариантов</a>
        <form method="post" action="" enctype="multipart/form-data" class="shop-admin-inline-action">
            <?php echo shop_admin_csrf(); ?>
            <input type="hidden" name="action" value="product_import" />
            <input type="hidden" name="tab" value="products" />
            <input type="file" name="csv" accept=".csv,text/csv" required="required" />
            <button type="submit" class="shop-admin-btn">Импорт CSV</button>
        </form>
    </div>
    <details class="shop-admin-import-hint">
        <summary>Формат CSV</summary>
        <p>Разделитель «;» или «,». Колонки: title, slug, category, short_description, description, sku, price, stock, active, featured, meta_title, meta_description, meta_keywords. Товар ищется по slug и обновляется; строка без совпадения создаёт новый товар с одним вариантом.</p>
        <p>Экспорт выгружает по одной строке на товар: артикул, цена и остаток берутся у самого дешёвого активного варианта в текущей валюте.</p>
        <p>«Экспорт вариантов» выгружает каждую комбинацию варианта и валюты: артикул, остаток, цена и старая цена.</p>
    </details>

    <?php if ($result['items']): ?>
    <table class="grid shop-admin-table">
        <thead>
            <tr>
                <th>ID</th>
                <th>Фото</th>
                <th>Товар</th>
                <th>Категория</th>
                <th>Варианты</th>
                <th>Цена от</th>
                <th>Статус</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($result['items'] as $item): ?>
            <tr>
                <td><?php echo (int)$item['id']; ?></td>
                <td>
                    <?php if (!empty($item['image_url'])): ?>
                    <img class="shop-admin-thumb" src="<?php echo h($item['image_url']); ?>" alt="" />
                    <?php else: ?>
                    <span class="shop-admin-noimg">—</span>
                    <?php endif; ?>
                </td>
                <td>
                    <a class="shop-admin-title-link" href="<?php echo h(shop_admin_url(array('tab' => 'product', 'id' => (int)$item['id']))); ?>"><?php echo h($item['title']); ?></a>
                    <div class="shop-admin-muted"><?php echo h($item['clean_url_title']); ?></div>
                </td>
                <td><?php echo h($item['category_title']); ?></td>
                <td><?php echo isset($variantCounts[(int)$item['id']]) ? $variantCounts[(int)$item['id']] : 0; ?></td>
                <td><?php echo $item['price_from_formatted'] !== '' ? h($item['price_from_formatted']) : '—'; ?></td>
                <td><?php echo !empty($item['active']) ? '<span class="shop-admin-badge ok">Активен</span>' : '<span class="shop-admin-badge off">Скрыт</span>'; ?></td>
                <td class="shop-admin-actions">
                    <a href="<?php echo h(shop_admin_url(array('tab' => 'variants', 'id' => (int)$item['id']))); ?>">Варианты</a>
                    <a href="<?php echo h($item['url']); ?>" target="_blank">На сайте</a>
                    <form method="post" action="" onsubmit="return confirm('Создать копию товара?');">
                        <?php echo shop_admin_csrf(); ?>
                        <input type="hidden" name="action" value="product_copy" />
                        <input type="hidden" name="tab" value="products" />
                        <input type="hidden" name="id" value="<?php echo (int)$item['id']; ?>" />
                        <button type="submit" class="shop-admin-link">Копия</button>
                    </form>
                    <form method="post" action="" onsubmit="return confirm('Удалить товар вместе с вариантами?');">
                        <?php echo shop_admin_csrf(); ?>
                        <input type="hidden" name="action" value="product_delete" />
                        <input type="hidden" name="tab" value="products" />
                        <input type="hidden" name="id" value="<?php echo (int)$item['id']; ?>" />
                        <button type="submit" class="shop-admin-link-danger">Удалить</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php else: ?>
    <div class="shop-admin-empty">Товары не найдены. <a href="<?php echo h(shop_admin_url(array('tab' => 'product', 'id' => 0))); ?>">Добавить первый товар</a>.</div>
    <?php endif; ?>

    <?php if ($pagination): ?><div class="shop-admin-pagination"><?php echo $pagination; ?></div><?php endif; ?>
    <?php
    return ob_get_clean();
}

function shop_admin_product_form()
{
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    $product = $id > 0 ? ShopHelper::getProduct($id, false) : false;
    if ($id > 0 && !$product) {
        return '<div class="shop-admin-empty">Товар не найден.</div>';
    }

    $categories = ShopHelper::getCategories(false);
    $selectedValues = $id > 0 ? ShopHelper::getProductAttributeValueIds($id) : array();
    $attributes = ShopHelper::getAttributes(true);
    $gallery = $id > 0 ? ShopHelper::getProductImages($id, true) : array();
    ShopHelper::ensureRelatedProductsTable();
    $relatedIds = $id > 0 ? ShopHelper::relatedProductIds($id) : array();
    $relatedOptions = ShopHelper::db()->query("SELECT `id`, `title` FROM `" . ShopHelper::table('products') . "`"
        . ($id > 0 ? " WHERE `id` <> {$id}" : '') . " ORDER BY `title` ASC LIMIT 500");
    $isActive = $product ? !empty($product['active']) : true;
    $isFeatured = $product ? !empty($product['featured']) : false;

    ob_start(); ?>
    <div class="shop-admin-page-head">
        <h1><?php echo $product ? 'Редактирование товара' : 'Новый товар'; ?></h1>
        <a href="<?php echo h(shop_admin_url(array('tab' => 'products'))); ?>">← К списку товаров</a>
    </div>

    <?php if (!empty($product['image'])): ?>
    <div class="shop-admin-current-image">
        <img src="<?php echo h(ShopHelper::imageUrl($product['image'], true)); ?>" alt="" />
        <label><input type="checkbox" name="remove_image" value="1" form="shop-product-form" /> Удалить главное изображение</label>
    </div>
    <?php endif; ?>

    <form id="shop-product-form" method="post" action="" enctype="multipart/form-data">
        <?php echo shop_admin_csrf(); ?>
        <input type="hidden" name="action" value="product_save" />
        <input type="hidden" name="tab" value="product" />
        <input type="hidden" name="id" value="<?php echo (int)$id; ?>" />

        <div class="shop-admin-form-grid">
            <div class="shop-admin-field">
                <label for="shop-product-title">Название *</label>
                <input id="shop-product-title" type="text" name="title" required="required" value="<?php echo h($product ? $product['title'] : ''); ?>" />
            </div>
            <div class="shop-admin-field">
                <label for="shop-product-slug">URL (slug)</label>
                <input id="shop-product-slug" type="text" name="clean_url_title" value="<?php echo h($product ? $product['clean_url_title'] : ''); ?>" placeholder="Заполнится из названия" />
            </div>
            <div class="shop-admin-field">
                <label for="shop-product-category">Категория</label>
                <select id="shop-product-category" name="category_id">
                    <?php echo shop_admin_category_options($product ? (int)$product['category_id'] : 0); ?>
                </select>
            </div>
            <div class="shop-admin-field">
                <label for="shop-product-position">Позиция</label>
                <input id="shop-product-position" type="number" name="position" value="<?php echo $product ? (int)$product['position'] : 0; ?>" />
            </div>
            <div class="shop-admin-field shop-admin-field-wide">
                <label for="shop-product-short">Краткое описание</label>
                <textarea id="shop-product-short" name="short_description" rows="3"><?php echo h($product ? $product['short_description'] : ''); ?></textarea>
            </div>
            <div class="shop-admin-field shop-admin-field-wide">
                <label for="shop-product-description">Описание</label>
                <textarea id="shop-product-description" class="shop-admin-editor" name="description" rows="10"><?php echo h($product ? $product['description'] : ''); ?></textarea>
            </div>
            <div class="shop-admin-field">
                <label for="shop-product-image">Главное изображение</label>
                <input id="shop-product-image" type="file" name="image" accept="image/jpeg,image/png,image/gif,image/webp" />
                <span class="shop-admin-file-note">JPG, PNG, GIF или WebP.</span>
            </div>
            <div class="shop-admin-field shop-admin-checkboxes">
                <label><input type="checkbox" name="active" value="1"<?php echo $isActive ? ' checked="checked"' : ''; ?> /> Активен</label>
                <label><input type="checkbox" name="featured" value="1"<?php echo $isFeatured ? ' checked="checked"' : ''; ?> /> Рекомендуемый</label>
            </div>
        </div>

        <div class="shop-admin-section">
            <h2>Галерея товара</h2>
            <p class="shop-admin-muted">Дополнительные изображения показываются на странице товара под главным фото.</p>
            <?php if ($gallery): ?>
            <div class="shop-admin-gallery-grid">
                <?php foreach ($gallery as $galleryImage): ?>
                <label class="shop-admin-gallery-item">
                    <img src="<?php echo h($galleryImage['image_url']); ?>" alt="" />
                    <span><input type="checkbox" name="remove_gallery[]" value="<?php echo (int)$galleryImage['id']; ?>" /> Удалить</span>
                </label>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <div class="shop-admin-field">
                <label for="shop-product-gallery">Добавить изображения</label>
                <input id="shop-product-gallery" type="file" name="gallery[]" accept="image/jpeg,image/png,image/gif,image/webp" multiple="multiple" />
                <span class="shop-admin-file-note">Можно выбрать сразу несколько файлов. JPG, PNG, GIF или WebP.</span>
            </div>
        </div>

        <div class="shop-admin-section">
            <h2>С этим покупают</h2>
            <p class="shop-admin-muted">Ручная подборка для блока «С этим покупают» на странице товара. Удерживайте Ctrl (Cmd), чтобы выбрать несколько.</p>
            <?php if ($relatedOptions): ?>
            <div class="shop-admin-field">
                <select name="related[]" multiple="multiple" size="10" class="shop-admin-select-multiple">
                    <?php foreach ($relatedOptions as $option): ?>
                    <option value="<?php echo (int)$option['id']; ?>"<?php echo in_array((int)$option['id'], $relatedIds, true) ? ' selected="selected"' : ''; ?>><?php echo h($option['title']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php else: ?>
            <div class="shop-admin-empty">Других товаров пока нет.</div>
            <?php endif; ?>
        </div>

        <div class="shop-admin-section">
            <h2>Атрибуты товара</h2>
            <p class="shop-admin-muted">Отметьте значения атрибутов. Варианты и цены будут созданы автоматически после сохранения.</p>
            <?php if ($attributes): ?>
                <?php foreach ($attributes as $attribute): ?>
                    <?php $values = ShopHelper::getAttributeValues((int)$attribute['id'], true); ?>
                    <?php if (!$values) continue; ?>
                    <fieldset class="shop-admin-attribute-group">
                        <legend><?php echo h($attribute['title']); ?></legend>
                        <div class="shop-admin-attribute-values">
                            <?php foreach ($values as $value): ?>
                            <label>
                                <input type="checkbox" name="attributes[]" value="<?php echo (int)$value['id']; ?>"<?php echo in_array((int)$value['id'], $selectedValues, true) ? ' checked="checked"' : ''; ?> />
                                <?php echo h($value['title']); ?>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </fieldset>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="shop-admin-empty">Атрибутов пока нет. <a href="<?php echo h(shop_admin_url(array('tab' => 'attributes'))); ?>">Создать атрибут</a>.</div>
            <?php endif; ?>
        </div>

        <div class="shop-admin-section">
            <h2>Метаданные</h2>
            <div class="shop-admin-form-grid">
                <div class="shop-admin-field">
                    <label>Meta title</label>
                    <input type="text" name="meta_title" value="<?php echo h($product ? $product['meta_title'] : ''); ?>" />
                </div>
                <div class="shop-admin-field">
                    <label>Meta keywords</label>
                    <input type="text" name="meta_keywords" value="<?php echo h($product ? $product['meta_keywords'] : ''); ?>" />
                </div>
                <div class="shop-admin-field shop-admin-field-wide">
                    <label>Meta description</label>
                    <textarea name="meta_description" rows="3"><?php echo h($product ? $product['meta_description'] : ''); ?></textarea>
                </div>
            </div>
        </div>

        <div class="shop-admin-form-actions">
            <button type="submit" class="shop-admin-btn shop-admin-btn-primary">Сохранить и перейти к вариантам</button>
            <a class="shop-admin-btn" href="<?php echo h(shop_admin_url(array('tab' => 'products'))); ?>">Отмена</a>
        </div>
    </form>
    <?php
    return ob_get_clean();
}

function shop_admin_variants()
{
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    $product = $id > 0 ? ShopHelper::getProduct($id, false) : false;
    if (!$product) return '<div class="shop-admin-empty">Товар не найден.</div>';

    ShopHelper::ensurePromoTables();
    $rows = ShopHelper::db()->query("SELECT v.*, vp.`currency_code`, vp.`price`, vp.`compare_price`
        FROM `" . ShopHelper::table('variants') . "` v
        LEFT JOIN `" . ShopHelper::table('variant_prices') . "` vp ON vp.`variant_id` = v.`id`
        WHERE v.`product_id` = {$id}
        ORDER BY v.`position` ASC, v.`id` ASC");
    $variants = array();
    foreach ($rows as $row) {
        $variantId = (int)$row['id'];
        if (!isset($variants[$variantId])) {
            $row['prices'] = array();
            $row['compare_prices'] = array();
            $variants[$variantId] = $row;
        }
        if (!empty($row['currency_code'])) {
            $variants[$variantId]['prices'][$row['currency_code']] = $row['price'];
            $variants[$variantId]['compare_prices'][$row['currency_code']] = $row['compare_price'];
        }
    }
    $currencies = ShopHelper::currencies(false);
    $activeCurrency = ShopHelper::activeCurrency();
    $groups = ShopHelper::getProductAttributeGroups($id);

    ob_start(); ?>
    <div class="shop-admin-page-head">
        <h1>Варианты: <?php echo h($product['title']); ?></h1>
        <a href="<?php echo h(shop_admin_url(array('tab' => 'product', 'id' => $id))); ?>">← Редактировать товар</a>
    </div>

    <?php if ($groups): ?>
    <div class="shop-admin-note">
        Комбинации: 
        <?php $groupTitles = array(); foreach ($groups as $group) { $groupTitles[] = $group['title']; } echo h(implode(' × ', $groupTitles)); ?>.
        Активная валюта: <strong><?php echo h($activeCurrency['code']); ?></strong>.
    </div>
    <?php else: ?>
    <div class="shop-admin-note">У товара нет атрибутов, поэтому используется вариант «Стандарт».</div>
    <?php endif; ?>

    <?php if ($variants): ?>
    <form method="post" action="">
        <?php echo shop_admin_csrf(); ?>
        <input type="hidden" name="action" value="variants_save" />
        <input type="hidden" name="tab" value="variants" />
        <input type="hidden" name="id" value="<?php echo $id; ?>" />

        <div class="shop-admin-table-wrap">
            <table class="grid shop-admin-table shop-admin-variants-table">
                <thead>
                    <tr>
                        <th>Вариант</th>
                        <th>Артикул</th>
                        <th>Остаток</th>
                        <th>Активен</th>
                        <?php foreach ($currencies as $code => $currency): ?>
                        <th<?php echo $code === $activeCurrency['code'] ? ' class="active-currency"' : ''; ?>><?php echo h($code); ?><div class="shop-admin-muted">цена / старая</div></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($variants as $variant): ?>
                    <tr class="<?php echo empty($variant['active']) ? 'shop-admin-variant-inactive' : ''; ?>">
                        <td>
                            <strong><?php echo h($variant['title']); ?></strong>
                            <?php if (empty($variant['active'])): ?><div class="shop-admin-muted">неактивен</div><?php endif; ?>
                        </td>
                        <td><input type="text" name="variants[<?php echo (int)$variant['id']; ?>][sku]" value="<?php echo h($variant['sku']); ?>" /></td>
                        <td><input class="shop-admin-stock-input" type="number" min="0" name="variants[<?php echo (int)$variant['id']; ?>][stock]" value="<?php echo (int)$variant['stock']; ?>" /></td>
                        <td><input type="checkbox" name="variants[<?php echo (int)$variant['id']; ?>][active]" value="1"<?php echo !empty($variant['active']) ? ' checked="checked"' : ''; ?> /></td>
                        <?php foreach ($currencies as $code => $currency): ?>
                        <td<?php echo $code === $activeCurrency['code'] ? ' class="active-currency"' : ''; ?>>
                            <input class="shop-admin-price-input" type="number" min="0" step="0.01" name="variants[<?php echo (int)$variant['id']; ?>][prices][<?php echo h($code); ?>]" value="<?php echo h(isset($variant['prices'][$code]) ? $variant['prices'][$code] : '0.00'); ?>" />
                            <input class="shop-admin-price-input" type="number" min="0" step="0.01" name="variants[<?php echo (int)$variant['id']; ?>][compare_prices][<?php echo h($code); ?>]" value="<?php echo h(isset($variant['compare_prices'][$code]) ? $variant['compare_prices'][$code] : '0.00'); ?>" placeholder="Старая цена" title="Старая цена для показа скидки" />
                        </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="shop-admin-form-actions">
            <button type="submit" class="shop-admin-btn shop-admin-btn-primary">Сохранить варианты</button>
            <a class="shop-admin-btn" href="<?php echo h(shop_admin_url(array('tab' => 'products'))); ?>">К товарам</a>
        </div>
    </form>
    <form method="post" action="" onsubmit="return confirm('Пересоздать комбинации? Существующие цены и остатки сохранятся для совпадающих комбинаций.');" class="shop-admin-inline-action">
        <?php echo shop_admin_csrf(); ?>
        <input type="hidden" name="action" value="variants_sync" />
        <input type="hidden" name="tab" value="variants" />
        <input type="hidden" name="id" value="<?php echo $id; ?>" />
        <button type="submit" class="shop-admin-btn">Пересоздать комбинации</button>
    </form>
    <?php else: ?>
    <div class="shop-admin-empty">
        Варианты не созданы. Выберите атрибуты в товаре и сохраните его.
        <form method="post" action="" class="shop-admin-inline-action">
            <?php echo shop_admin_csrf(); ?>
            <input type="hidden" name="action" value="variants_sync" />
            <input type="hidden" name="tab" value="variants" />
            <input type="hidden" name="id" value="<?php echo $id; ?>" />
            <button type="submit" class="shop-admin-btn shop-admin-btn-primary">Создать вариант «Стандарт»</button>
        </form>
    </div>
    <?php endif; ?>
    <?php
    return ob_get_clean();
}

function shop_admin_attributes()
{
    $attributes = ShopHelper::db()->query("SELECT a.*,
        (SELECT COUNT(*) FROM `" . ShopHelper::table('attribute_values') . "` av WHERE av.`attribute_id` = a.`id`) AS values_count,
        (SELECT COUNT(DISTINCT pav.`product_id`) FROM `" . ShopHelper::table('product_attribute_values') . "` pav
            INNER JOIN `" . ShopHelper::table('attribute_values') . "` av2 ON av2.`id` = pav.`attribute_value_id`
            WHERE av2.`attribute_id` = a.`id`) AS products_count
        FROM `" . ShopHelper::table('attributes') . "` a ORDER BY a.`position` ASC, a.`id` ASC");

    ob_start(); ?>
    <div class="shop-admin-page-head">
        <h1>Атрибуты</h1>
        <a href="<?php echo h(shop_admin_url(array('tab' => 'products'))); ?>">← К товарам</a>
    </div>

    <div class="shop-admin-columns shop-admin-columns-narrow">
        <div class="shop-admin-panel">
            <div class="shop-admin-panel-head"><h2>Список атрибутов</h2></div>
            <?php if ($attributes): ?>
            <table class="grid shop-admin-table">
                <thead><tr><th>Название</th><th>Значений</th><th>Товаров</th><th>Позиция</th><th>Статус</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($attributes as $attribute): ?>
                    <tr>
                        <td><a href="<?php echo h(shop_admin_url(array('tab' => 'attribute', 'id' => (int)$attribute['id']))); ?>"><?php echo h($attribute['title']); ?></a></td>
                        <td><?php echo (int)$attribute['values_count']; ?></td>
                        <td><?php echo (int)$attribute['products_count']; ?></td>
                        <td><?php echo (int)$attribute['position']; ?></td>
                        <td><?php echo !empty($attribute['active']) ? '<span class="shop-admin-badge ok">Активен</span>' : '<span class="shop-admin-badge off">Скрыт</span>'; ?></td>
                        <td class="shop-admin-actions">
                            <a href="<?php echo h(shop_admin_url(array('tab' => 'attribute', 'id' => (int)$attribute['id']))); ?>">Изменить</a>
                            <form method="post" action="" onsubmit="return confirm('Удалить атрибут?');">
                                <?php echo shop_admin_csrf(); ?>
                                <input type="hidden" name="action" value="attribute_delete" />
                                <input type="hidden" name="tab" value="attributes" />
                                <input type="hidden" name="id" value="<?php echo (int)$attribute['id']; ?>" />
                                <button type="submit" class="shop-admin-link-danger">Удалить</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
            <div class="shop-admin-empty">Атрибутов пока нет.</div>
            <?php endif; ?>
        </div>

        <div class="shop-admin-panel">
            <div class="shop-admin-panel-head"><h2>Новый атрибут</h2></div>
            <form method="post" action="" class="shop-admin-simple-form">
                <?php echo shop_admin_csrf(); ?>
                <input type="hidden" name="action" value="attribute_save" />
                <input type="hidden" name="tab" value="attributes" />
                <input type="hidden" name="id" value="0" />
                <label>Название *<input type="text" name="title" required="required" /></label>
                <label>Позиция<input type="number" name="position" value="0" /></label>
                <label class="shop-admin-inline-check"><input type="checkbox" name="active" value="1" checked="checked" /> Активен</label>
                <button type="submit" class="shop-admin-btn shop-admin-btn-primary">Создать атрибут</button>
            </form>
            <p class="shop-admin-muted">Например: «Цвет», «Размер», «Материал».</p>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

function shop_admin_attribute_form()
{
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    $rows = ShopHelper::db()->query("SELECT * FROM `" . ShopHelper::table('attributes') . "`
        WHERE `id` = {$id} LIMIT 1");
    if (empty($rows[0])) return '<div class="shop-admin-empty">Атрибут не найден.</div>';
    $attribute = $rows[0];
    $values = ShopHelper::db()->query("SELECT av.*,
        (SELECT COUNT(*) FROM `" . ShopHelper::table('product_attribute_values') . "` pav WHERE pav.`attribute_value_id` = av.`id`) AS products_count
        FROM `" . ShopHelper::table('attribute_values') . "` av
        WHERE av.`attribute_id` = {$id} ORDER BY av.`position` ASC, av.`id` ASC");

    ob_start(); ?>
    <div class="shop-admin-page-head">
        <h1>Атрибут: <?php echo h($attribute['title']); ?></h1>
        <a href="<?php echo h(shop_admin_url(array('tab' => 'attributes'))); ?>">← К атрибутам</a>
    </div>

    <div class="shop-admin-columns shop-admin-columns-narrow">
        <div class="shop-admin-panel">
            <div class="shop-admin-panel-head"><h2>Свойства атрибута</h2></div>
            <form method="post" action="" class="shop-admin-simple-form">
                <?php echo shop_admin_csrf(); ?>
                <input type="hidden" name="action" value="attribute_save" />
                <input type="hidden" name="tab" value="attribute" />
                <input type="hidden" name="id" value="<?php echo $id; ?>" />
                <label>Название *<input type="text" name="title" required="required" value="<?php echo h($attribute['title']); ?>" /></label>
                <label>Позиция<input type="number" name="position" value="<?php echo (int)$attribute['position']; ?>" /></label>
                <label class="shop-admin-inline-check"><input type="checkbox" name="active" value="1"<?php echo !empty($attribute['active']) ? ' checked="checked"' : ''; ?> /> Активен</label>
                <button type="submit" class="shop-admin-btn shop-admin-btn-primary">Сохранить</button>
            </form>
            <form method="post" action="" onsubmit="return confirm('Удалить атрибут и все его значения?');" class="shop-admin-inline-action">
                <?php echo shop_admin_csrf(); ?>
                <input type="hidden" name="action" value="attribute_delete" />
                <input type="hidden" name="tab" value="attributes" />
                <input type="hidden" name="id" value="<?php echo $id; ?>" />
                <button type="submit" class="shop-admin-btn shop-admin-btn-danger">Удалить атрибут</button>
            </form>
        </div>

        <div class="shop-admin-panel">
            <div class="shop-admin-panel-head"><h2>Новое значение</h2></div>
            <form method="post" action="" class="shop-admin-simple-form">
                <?php echo shop_admin_csrf(); ?>
                <input type="hidden" name="action" value="value_save" />
                <input type="hidden" name="tab" value="attribute" />
                <input type="hidden" name="attribute_id" value="<?php echo $id; ?>" />
                <input type="hidden" name="value_id" value="0" />
                <label>Название *<input type="text" name="title" required="required" /></label>
                <label>Позиция<input type="number" name="position" value="0" /></label>
                <label class="shop-admin-inline-check"><input type="checkbox" name="active" value="1" checked="checked" /> Активно</label>
                <button type="submit" class="shop-admin-btn shop-admin-btn-primary">Добавить значение</button>
            </form>
        </div>
    </div>

    <div class="shop-admin-panel">
        <div class="shop-admin-panel-head"><h2>Значения атрибута</h2></div>
        <?php if ($values): ?>
        <div class="shop-admin-value-list">
            <?php foreach ($values as $value): ?>
            <div class="shop-admin-value-row">
                <form method="post" action="" class="shop-admin-value-form">
                    <?php echo shop_admin_csrf(); ?>
                    <input type="hidden" name="action" value="value_save" />
                    <input type="hidden" name="tab" value="attribute" />
                    <input type="hidden" name="attribute_id" value="<?php echo $id; ?>" />
                    <input type="hidden" name="value_id" value="<?php echo (int)$value['id']; ?>" />
                    <input class="shop-admin-value-title" type="text" name="title" value="<?php echo h($value['title']); ?>" required="required" />
                    <label>Позиция <input class="shop-admin-position-input" type="number" name="position" value="<?php echo (int)$value['position']; ?>" /></label>
                    <label class="shop-admin-inline-check"><input type="checkbox" name="active" value="1"<?php echo !empty($value['active']) ? ' checked="checked"' : ''; ?> /> Активно</label>
                    <span class="shop-admin-muted">Товаров: <?php echo (int)$value['products_count']; ?></span>
                    <button type="submit" class="shop-admin-btn shop-admin-btn-small">Сохранить</button>
                </form>
                <form method="post" action="" onsubmit="return confirm('Удалить значение?');" class="shop-admin-value-delete">
                    <?php echo shop_admin_csrf(); ?>
                    <input type="hidden" name="action" value="value_delete" />
                    <input type="hidden" name="tab" value="attribute" />
                    <input type="hidden" name="attribute_id" value="<?php echo $id; ?>" />
                    <input type="hidden" name="value_id" value="<?php echo (int)$value['id']; ?>" />
                    <button type="submit" class="shop-admin-link-danger">Удалить</button>
                </form>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="shop-admin-empty">У атрибута пока нет значений.</div>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}

function shop_admin_categories()
{
    $categories = ShopHelper::getCategories(false);
    $byId = array();
    foreach ($categories as $category) $byId[(int)$category['id']] = $category;
    $productCounts = array();
    $rows = ShopHelper::db()->query("SELECT `category_id`, COUNT(*) AS cnt FROM `"
        . ShopHelper::table('products') . "` GROUP BY `category_id`");
    foreach ($rows as $row) $productCounts[(int)$row['category_id']] = (int)$row['cnt'];

    $landingCounts = array();
    $rows = ShopHelper::db()->query("SELECT `category_id`, COUNT(*) AS cnt FROM `"
        . ShopHelper::table('landing_page_categories') . "` GROUP BY `category_id`");
    foreach ($rows as $row) $landingCounts[(int)$row['category_id']] = (int)$row['cnt'];

    foreach ($categories as &$category) {
        $depth = 0;
        $parent = (int)$category['parent_id'];
        $guard = 0;
        while ($parent > 0 && isset($byId[$parent]) && $guard < 20) {
            $depth++;
            $parent = (int)$byId[$parent]['parent_id'];
            $guard++;
        }
        $category['depth'] = $depth;
        $category['products_count'] = isset($productCounts[(int)$category['id']]) ? $productCounts[(int)$category['id']] : 0;
        $category['landings_count'] = isset($landingCounts[(int)$category['id']]) ? $landingCounts[(int)$category['id']] : 0;
    }
    unset($category);

    ob_start(); ?>
    <div class="shop-admin-page-head">
        <h1>Категории</h1>
        <a href="<?php echo h(shop_admin_url(array('tab' => 'products'))); ?>">← К товарам</a>
    </div>

    <div class="shop-admin-columns shop-admin-columns-narrow">
        <div class="shop-admin-panel">
            <div class="shop-admin-panel-head"><h2>Дерево категорий</h2></div>
            <?php if ($categories): ?>
            <table class="grid shop-admin-table">
                <thead><tr><th>Название</th><th>Товаров</th><th>Подборки</th><th>Позиция</th><th>Статус</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($categories as $category): ?>
                    <tr>
                        <td>
                            <span style="padding-left:<?php echo (int)$category['depth'] * 18; ?>px;"><?php echo $category['depth'] ? '— ' : ''; ?><a href="<?php echo h(shop_admin_url(array('tab' => 'category', 'id' => (int)$category['id']))); ?>"><?php echo h($category['title']); ?></a></span>
                        </td>
                        <td><?php echo (int)$category['products_count']; ?></td>
                        <td><a href="<?php echo h(shop_admin_url(array('tab' => 'landings', 'category_id' => (int)$category['id']))); ?>"><?php echo (int)$category['landings_count']; ?></a></td>
                        <td><?php echo (int)$category['position']; ?></td>
                        <td><?php echo !empty($category['active']) ? '<span class="shop-admin-badge ok">Активна</span>' : '<span class="shop-admin-badge off">Скрыта</span>'; ?></td>
                        <td class="shop-admin-actions">
                            <a href="<?php echo h(shop_admin_url(array('tab' => 'category', 'id' => (int)$category['id']))); ?>">Изменить</a>
                            <form method="post" action="" onsubmit="return confirm('Удалить категорию?');">
                                <?php echo shop_admin_csrf(); ?>
                                <input type="hidden" name="action" value="category_delete" />
                                <input type="hidden" name="tab" value="categories" />
                                <input type="hidden" name="id" value="<?php echo (int)$category['id']; ?>" />
                                <button type="submit" class="shop-admin-link-danger">Удалить</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
            <div class="shop-admin-empty">Категорий пока нет.</div>
            <?php endif; ?>
        </div>

        <div class="shop-admin-panel">
            <div class="shop-admin-panel-head"><h2>Новая категория</h2></div>
            <form method="post" action="" class="shop-admin-simple-form">
                <?php echo shop_admin_csrf(); ?>
                <input type="hidden" name="action" value="category_save" />
                <input type="hidden" name="tab" value="categories" />
                <input type="hidden" name="id" value="0" />
                <label>Название *<input type="text" name="title" required="required" /></label>
                <label>Родительская категория<select name="parent_id"><?php echo shop_admin_category_options(0); ?></select></label>
                <label>Позиция<input type="number" name="position" value="0" /></label>
                <label class="shop-admin-inline-check"><input type="checkbox" name="active" value="1" checked="checked" /> Активна</label>
                <button type="submit" class="shop-admin-btn shop-admin-btn-primary">Создать категорию</button>
            </form>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

function shop_admin_category_form()
{
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    $category = $id > 0 ? ShopHelper::getCategory($id) : false;
    if ($id > 0 && !$category) return '<div class="shop-admin-empty">Категория не найдена.</div>';
    $exclude = $id > 0 ? ShopHelper::categoryDescendantIds($id) : array();
    $isActive = $category ? !empty($category['active']) : true;
    $categoryLandings = $category ? ShopHelper::getLandingPages(false, (int)$category['id']) : array();

    ob_start(); ?>
    <div class="shop-admin-page-head">
        <h1><?php echo $category ? 'Редактирование категории' : 'Новая категория'; ?></h1>
        <a href="<?php echo h(shop_admin_url(array('tab' => 'categories'))); ?>">← К категориям</a>
    </div>

    <?php if ($category && !empty($category['image'])): ?>
    <div class="shop-admin-current-image">
        <img src="<?php echo h(ShopHelper::imageUrl($category['image'], true)); ?>" alt="" />
        <label><input type="checkbox" name="remove_image" value="1" form="shop-category-form" /> Удалить изображение</label>
    </div>
    <?php endif; ?>

    <form id="shop-category-form" method="post" action="" enctype="multipart/form-data">
        <?php echo shop_admin_csrf(); ?>
        <input type="hidden" name="action" value="category_save" />
        <input type="hidden" name="tab" value="category" />
        <input type="hidden" name="id" value="<?php echo (int)$id; ?>" />
        <div class="shop-admin-form-grid">
            <div class="shop-admin-field">
                <label for="shop-category-title">Название *</label>
                <input id="shop-category-title" type="text" name="title" required="required" value="<?php echo h($category ? $category['title'] : ''); ?>" />
            </div>
            <div class="shop-admin-field">
                <label for="shop-category-slug">URL (slug)</label>
                <input id="shop-category-slug" type="text" name="clean_url_title" value="<?php echo h($category ? $category['clean_url_title'] : ''); ?>" placeholder="Заполнится из названия" />
            </div>
            <div class="shop-admin-field">
                <label for="shop-category-parent">Родительская категория</label>
                <select id="shop-category-parent" name="parent_id"><?php echo shop_admin_category_options($category ? (int)$category['parent_id'] : 0, $exclude); ?></select>
            </div>
            <div class="shop-admin-field">
                <label for="shop-category-position">Позиция</label>
                <input id="shop-category-position" type="number" name="position" value="<?php echo $category ? (int)$category['position'] : 0; ?>" />
            </div>
            <div class="shop-admin-field shop-admin-field-wide">
                <label for="shop-category-description">Описание</label>
                <textarea id="shop-category-description" class="shop-admin-editor" name="description" rows="5"><?php echo h($category ? $category['description'] : ''); ?></textarea>
            </div>
            <div class="shop-admin-field">
                <label for="shop-category-image">Изображение</label>
                <input id="shop-category-image" type="file" name="image" accept="image/jpeg,image/png,image/gif,image/webp" />
            </div>
            <div class="shop-admin-field shop-admin-checkboxes">
                <label><input type="checkbox" name="active" value="1"<?php echo $isActive ? ' checked="checked"' : ''; ?> /> Активна</label>
            </div>
            <div class="shop-admin-field">
                <label>Meta title</label>
                <input type="text" name="meta_title" value="<?php echo h($category ? $category['meta_title'] : ''); ?>" />
            </div>
            <div class="shop-admin-field">
                <label>Meta keywords</label>
                <input type="text" name="meta_keywords" value="<?php echo h($category ? $category['meta_keywords'] : ''); ?>" />
            </div>
            <div class="shop-admin-field shop-admin-field-wide">
                <label>Meta description</label>
                <textarea name="meta_description" rows="3"><?php echo h($category ? $category['meta_description'] : ''); ?></textarea>
            </div>
        </div>
        <div class="shop-admin-form-actions">
            <button type="submit" class="shop-admin-btn shop-admin-btn-primary">Сохранить категорию</button>
            <a class="shop-admin-btn" href="<?php echo h(shop_admin_url(array('tab' => 'categories'))); ?>">Отмена</a>
        </div>
    </form>

    <?php if ($category): ?>
    <div class="shop-admin-panel shop-admin-category-landings">
        <div class="shop-admin-panel-head">
            <h2>Посадочные страницы категории</h2>
            <a href="<?php echo h(shop_admin_url(array('tab' => 'landing', 'id' => 0, 'category_id' => (int)$category['id']))); ?>">+ Создать</a>
        </div>
        <?php if ($categoryLandings): ?>
        <table class="grid shop-admin-table">
            <thead><tr><th>Название</th><th>URL</th><th>Статус</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($categoryLandings as $categoryLanding): ?>
                <tr>
                    <td><a class="shop-admin-title-link" href="<?php echo h(shop_admin_url(array('tab' => 'landing', 'id' => (int)$categoryLanding['id']))); ?>"><?php echo h($categoryLanding['title']); ?></a></td>
                    <td class="shop-admin-muted"><?php echo h($categoryLanding['url']); ?></td>
                    <td><?php echo !empty($categoryLanding['active']) ? '<span class="shop-admin-badge ok">Активна</span>' : '<span class="shop-admin-badge off">Скрыта</span>'; ?></td>
                    <td><a href="<?php echo h(shop_admin_url(array('tab' => 'landing', 'id' => (int)$categoryLanding['id']))); ?>">Изменить</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
        <div class="shop-admin-empty">У категории пока нет посадочных страниц.</div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    <?php
    return ob_get_clean();
}

function shop_admin_landings()
{
    $categoryId = isset($_GET['category_id']) ? (int)$_GET['category_id'] : 0;
    $category = $categoryId > 0 ? ShopHelper::getCategory($categoryId) : false;
    if ($categoryId > 0 && !$category) $categoryId = 0;
    $landings = ShopHelper::getLandingPages(false, $categoryId);

    ob_start(); ?>
    <div class="shop-admin-page-head">
        <h1>Посадочные страницы</h1>
        <a class="shop-admin-btn shop-admin-btn-primary" href="<?php echo h(shop_admin_url(array('tab' => 'landing', 'id' => 0, 'category_id' => $categoryId))); ?>">+ Создать страницу</a>
    </div>

    <p class="shop-admin-help">
        Посадочная страница показывает товары из выбранных категорий с фильтром по значениям свойств.
        Внутри одного свойства значения объединяются по «ИЛИ», разные свойства — по «И».
    </p>

    <div class="shop-admin-toolbar">
        <form method="get" action="<?php echo h(get_url('/admin/shop.php')); ?>">
            <input type="hidden" name="tab" value="landings" />
            <label>Категория:
                <select name="category_id">
                    <option value="0">Все категории</option>
                    <?php echo shop_admin_category_options($categoryId, array(), false); ?>
                </select>
            </label>
            <button type="submit" class="shop-admin-btn">Применить</button>
            <?php if ($categoryId > 0): ?>
                <a class="shop-admin-btn" href="<?php echo h(shop_admin_url(array('tab' => 'landings'))); ?>">Сбросить</a>
            <?php endif; ?>
        </form>
    </div>

    <?php if ($landings): ?>
    <div class="shop-admin-table-wrap">
        <table class="shop-admin-table">
            <thead>
            <tr>
                <th>Название</th>
                <th>URL</th>
                <th>Категории</th>
                <th>Свойства</th>
                <th>Позиция</th>
                <th>Статус</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($landings as $landing): ?>
                <?php
                $landingFull = ShopHelper::getLandingPage((int)$landing['id'], false);
                $categoryTitles = array();
                $valueTitles = array();
                if ($landingFull) {
                    foreach ($landingFull['categories'] as $landingCategory) {
                        $categoryTitles[] = $landingCategory['title'];
                    }
                    foreach ($landingFull['attribute_groups'] as $group) {
                        foreach ($group['values'] as $value) {
                            $valueTitles[] = $group['title'] . ': ' . $value['title'];
                        }
                    }
                }
                ?>
                <tr>
                    <td><a class="shop-admin-title-link" href="<?php echo h(shop_admin_url(array('tab' => 'landing', 'id' => (int)$landing['id']))); ?>"><?php echo h($landing['title']); ?></a></td>
                    <td class="shop-admin-muted"><a href="<?php echo h($landing['url']); ?>" target="_blank" rel="noopener"><?php echo h($landing['url']); ?></a></td>
                    <td><?php echo h(implode(', ', $categoryTitles)); ?></td>
                    <td><?php echo h(implode('; ', $valueTitles)); ?></td>
                    <td><?php echo (int)$landing['position']; ?></td>
                    <td><?php echo !empty($landing['active']) ? '<span class="shop-admin-badge ok">Активна</span>' : '<span class="shop-admin-badge off">Скрыта</span>'; ?></td>
                    <td class="shop-admin-actions">
                        <a href="<?php echo h(shop_admin_url(array('tab' => 'landing', 'id' => (int)$landing['id']))); ?>">Изменить</a>
                        <form method="post" action="" onsubmit="return confirm('Удалить посадочную страницу?');">
                            <?php echo shop_admin_csrf(); ?>
                            <input type="hidden" name="action" value="landing_delete" />
                            <input type="hidden" name="tab" value="landings" />
                            <input type="hidden" name="id" value="<?php echo (int)$landing['id']; ?>" />
                            <button type="submit" class="shop-admin-link-danger">Удалить</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
        <div class="shop-admin-empty">
            <?php echo $categoryId > 0 ? 'У выбранной категории пока нет посадочных страниц.' : 'Посадочных страниц пока нет.'; ?>
        </div>
    <?php endif; ?>
    <?php
    return ob_get_clean();
}

function shop_admin_landing_form()
{
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    $landing = $id > 0 ? ShopHelper::getLandingPage($id, false) : false;
    if ($id > 0 && !$landing) return '<div class="shop-admin-empty">Посадочная страница не найдена.</div>';

    $selectedCategoryIds = $landing ? $landing['category_ids'] : array();
    if (!$landing && isset($_GET['category_id'])) {
        $selectedCategoryIds = array((int)$_GET['category_id']);
    }
    $selectedValueIds = $landing ? $landing['attribute_value_ids'] : array();
    $attributes = ShopHelper::getAttributes(false);
    $attributeValues = ShopHelper::getAttributeValues(null, false);
    $valuesByAttribute = array();
    foreach ($attributeValues as $value) {
        $attributeId = (int)$value['attribute_id'];
        if (!isset($valuesByAttribute[$attributeId])) $valuesByAttribute[$attributeId] = array();
        $valuesByAttribute[$attributeId][] = $value;
    }

    ob_start(); ?>
    <div class="shop-admin-page-head">
        <h1><?php echo $landing ? 'Редактирование посадочной страницы' : 'Новая посадочная страница'; ?></h1>
        <a href="<?php echo h(shop_admin_url(array('tab' => 'landings'))); ?>">← К посадочным страницам</a>
    </div>

    <?php if ($landing): ?>
        <p class="shop-admin-help">Публичный адрес: <a href="<?php echo h($landing['url']); ?>" target="_blank" rel="noopener"><?php echo h($landing['url']); ?></a></p>
    <?php endif; ?>

    <form id="shop-landing-form" method="post" action="">
        <?php echo shop_admin_csrf(); ?>
        <input type="hidden" name="action" value="landing_save" />
        <input type="hidden" name="tab" value="landing" />
        <input type="hidden" name="id" value="<?php echo (int)$id; ?>" />

        <div class="shop-admin-form-grid">
            <div class="shop-admin-field">
                <label for="shop-landing-title">Название *</label>
                <input id="shop-landing-title" type="text" name="title" required="required" value="<?php echo h($landing ? $landing['title'] : ''); ?>" />
            </div>
            <div class="shop-admin-field">
                <label for="shop-landing-slug">URL (slug)</label>
                <input id="shop-landing-slug" type="text" name="clean_url_title" value="<?php echo h($landing ? $landing['clean_url_title'] : ''); ?>" placeholder="Заполнится из названия" />
            </div>
            <div class="shop-admin-field">
                <label for="shop-landing-position">Позиция</label>
                <input id="shop-landing-position" type="number" name="position" value="<?php echo $landing ? (int)$landing['position'] : 0; ?>" />
            </div>
            <div class="shop-admin-field">
                <label class="shop-admin-inline-check">
                    <input type="checkbox" name="active" value="1"<?php echo (!$landing || !empty($landing['active'])) ? ' checked="checked"' : ''; ?> />
                    Активна
                </label>
            </div>
        </div>

        <div class="shop-admin-field shop-admin-field-wide shop-admin-landing-section">
            <label>Категории *</label>
            <p class="shop-admin-help">Можно выбрать одну или несколько категорий. Будут показаны также товары из их подкатегорий.</p>
            <div class="shop-admin-check-tree">
                <?php
                $categoryCheckboxes = shop_admin_category_checkboxes($selectedCategoryIds);
                echo $categoryCheckboxes !== '' ? $categoryCheckboxes : '<span class="shop-admin-muted">Сначала создайте хотя бы одну категорию.</span>';
                ?>
            </div>
        </div>

        <div class="shop-admin-field shop-admin-field-wide shop-admin-landing-section">
            <label>Значения свойств товаров *</label>
            <p class="shop-admin-help">
                Выберите значения свойств, по которым будет строиться подборка.
                Значения одного свойства объединяются по «ИЛИ» (например, красный или синий),
                а разные свойства — по «И» (например, красный и шерсть).
            </p>
            <?php if ($attributes && $valuesByAttribute): ?>
                <div class="shop-admin-landing-values">
                    <?php foreach ($attributes as $attribute): ?>
                        <?php
                        $attributeId = (int)$attribute['id'];
                        if (empty($valuesByAttribute[$attributeId])) continue;
                        ?>
                        <fieldset class="shop-admin-attribute-values">
                            <legend><?php echo h($attribute['title']); ?></legend>
                            <?php foreach ($valuesByAttribute[$attributeId] as $value): ?>
                                <label class="shop-admin-check-line">
                                    <input type="checkbox" name="attribute_value_ids[]" value="<?php echo (int)$value['id']; ?>"<?php echo in_array((int)$value['id'], array_map('intval', $selectedValueIds), true) ? ' checked="checked"' : ''; ?> />
                                    <?php echo h($value['title']); ?>
                                    <?php if (empty($value['active'])): ?><span class="shop-admin-muted">(неактивно)</span><?php endif; ?>
                                </label>
                            <?php endforeach; ?>
                        </fieldset>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="shop-admin-empty">
                    У товаров ещё нет свойств и значений.
                    <a href="<?php echo h(shop_admin_url(array('tab' => 'attributes'))); ?>">Перейти к атрибутам</a>.
                </div>
            <?php endif; ?>
        </div>

        <div class="shop-admin-form-grid">
            <div class="shop-admin-field shop-admin-field-wide">
                <label for="shop-landing-description">Описание</label>
                <textarea id="shop-landing-description" class="shop-admin-editor" name="description" rows="6" placeholder="Текст над списком товаров"><?php echo h($landing ? $landing['description'] : ''); ?></textarea>
            </div>
            <div class="shop-admin-field">
                <label for="shop-landing-meta-title">Meta title</label>
                <input id="shop-landing-meta-title" type="text" name="meta_title" value="<?php echo h($landing ? $landing['meta_title'] : ''); ?>" />
            </div>
            <div class="shop-admin-field">
                <label for="shop-landing-meta-keywords">Meta keywords</label>
                <input id="shop-landing-meta-keywords" type="text" name="meta_keywords" value="<?php echo h($landing ? $landing['meta_keywords'] : ''); ?>" />
            </div>
            <div class="shop-admin-field shop-admin-field-wide">
                <label for="shop-landing-meta-description">Meta description</label>
                <textarea id="shop-landing-meta-description" name="meta_description" rows="3"><?php echo h($landing ? $landing['meta_description'] : ''); ?></textarea>
            </div>
        </div>

        <div class="shop-admin-form-actions">
            <button type="submit" class="shop-admin-btn shop-admin-btn-primary">Сохранить страницу</button>
            <a class="shop-admin-btn" href="<?php echo h(shop_admin_url(array('tab' => 'landings'))); ?>">Отмена</a>
        </div>
    </form>
    <?php
    return ob_get_clean();
}

function shop_admin_reviews()
{
    ShopHelper::ensureReviewReplyColumns();
    $reviews = ShopHelper::getReviews(null, 300);
    $pending = 0;
    foreach ($reviews as $review) {
        if (empty($review['active'])) $pending++;
    }

    ob_start();
    ?>
    <div class="shop-admin-panel">
        <div class="shop-admin-panel-head"><h2>Отзывы покупателей</h2></div>
        <p class="shop-admin-muted">На проверке: <?php echo (int)$pending; ?>. Отзывы с оценкой показываются на карточке товара после публикации.</p>
        <?php if (!$reviews): ?>
            <p>Отзывов пока нет.</p>
        <?php else: ?>
        <table class="grid shop-admin-table">
            <thead><tr><th>Товар</th><th>Автор</th><th>Оценка</th><th>Отзыв</th><th>Дата</th><th>Статус</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($reviews as $review): ?>
                <tr>
                    <td>
                        <?php if (!empty($review['product_url'])): ?>
                        <a class="shop-admin-title-link" href="<?php echo h($review['product_url']); ?>" target="_blank"><?php echo h($review['product_title']); ?></a>
                        <?php else: ?>
                        <span class="shop-admin-muted">Товар удалён</span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo h($review['name']); ?><div class="shop-admin-muted"><?php echo h($review['email']); ?></div></td>
                    <td><?php echo h($review['rating_stars']); ?></td>
                    <td>
                        <?php echo h($review['text']); ?>
                        <?php if (!empty($review['reply'])): ?>
                        <div class="shop-admin-review-reply">
                            <strong>Ответ магазина<?php echo $review['replied_at_formatted'] !== '' ? ' от ' . h($review['replied_at_formatted']) : ''; ?>:</strong>
                            <?php echo nl2br(h($review['reply'])); ?>
                        </div>
                        <?php endif; ?>
                        <details class="shop-admin-review-reply-form">
                            <summary><?php echo !empty($review['reply']) ? 'Изменить ответ' : 'Ответить'; ?></summary>
                            <form method="post" action="">
                                <?php echo shop_admin_csrf(); ?>
                                <input type="hidden" name="action" value="review_reply" />
                                <input type="hidden" name="id" value="<?php echo (int)$review['id']; ?>" />
                                <textarea name="reply" rows="3" maxlength="5000" placeholder="Публичный ответ магазина"><?php echo h($review['reply']); ?></textarea>
                                <button type="submit" class="shop-admin-btn shop-admin-btn-primary">Сохранить ответ</button>
                                <?php if (!empty($review['reply'])): ?>
                                <span class="shop-admin-muted">Пустой ответ удалит его.</span>
                                <?php endif; ?>
                            </form>
                        </details>
                    </td>
                    <td><?php echo h($review['created_at_formatted']); ?></td>
                    <td><?php echo !empty($review['active']) ? 'Опубликован' : 'На проверке'; ?></td>
                    <td>
                        <form method="post" action="" class="shop-admin-inline-form">
                            <?php echo shop_admin_csrf(); ?>
                            <input type="hidden" name="id" value="<?php echo (int)$review['id']; ?>" />
                            <?php if (!empty($review['active'])): ?>
                            <button type="submit" name="action" value="review_unpublish" class="shop-admin-btn">Снять</button>
                            <?php else: ?>
                            <button type="submit" name="action" value="review_approve" class="shop-admin-btn shop-admin-btn-primary">Опубликовать</button>
                            <?php endif; ?>
                            <button type="submit" name="action" value="review_delete" class="shop-admin-btn shop-admin-btn-danger">Удалить</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}

function shop_admin_emails()
{
    $templates = ShopHelper::emailTemplates();
    $selected = isset($_GET['code']) ? preg_replace('/[^a-z_]/', '', (string)$_GET['code']) : 'order';
    if (!isset($templates[$selected])) $selected = 'order';
    $template = $templates[$selected];

    ob_start(); ?>
    <div class="shop-admin-page-head">
        <h1>Письма магазина</h1>
    </div>
    <p class="shop-admin-muted">Тема и текст письма. Подстановки в фигурных скобках заменяются данными заказа или отзыва. Пустой шаблон использует встроенный текст.</p>

    <div class="shop-admin-emails">
        <ul class="shop-admin-email-list">
            <?php foreach ($templates as $code => $item): ?>
            <li>
                <a class="<?php echo $code === $selected ? 'active' : ''; ?>" href="<?php echo h(shop_admin_url(array('tab' => 'emails', 'code' => $code))); ?>">
                    <?php echo h($item['title']); ?>
                    <?php if (empty($item['custom'])): ?><span class="shop-admin-muted">встроенный</span><?php endif; ?>
                </a>
            </li>
            <?php endforeach; ?>
        </ul>

        <div class="shop-admin-panel">
            <div class="shop-admin-panel-head"><h2><?php echo h($template['title']); ?></h2></div>
            <p class="shop-admin-muted"><?php echo h($template['description']); ?></p>
            <form method="post" action="" class="shop-admin-simple-form">
                <?php echo shop_admin_csrf(); ?>
                <input type="hidden" name="action" value="email_template_save" />
                <input type="hidden" name="code" value="<?php echo h($selected); ?>" />
                <label>Тема письма
                    <input type="text" name="subject" maxlength="255" value="<?php echo h($template['subject']); ?>" />
                </label>
                <label>Текст письма (HTML)
                    <textarea name="body" rows="18" class="shop-admin-code"><?php echo h($template['body']); ?></textarea>
                </label>
                <p class="shop-admin-muted">Подстановки: <?php echo h($template['placeholders']); ?></p>
                <button type="submit" class="shop-admin-btn shop-admin-btn-primary">Сохранить</button>
            </form>
            <?php if (!empty($template['custom'])): ?>
            <form method="post" action="" class="shop-admin-inline-action" onsubmit="return confirm('Вернуть встроенный текст письма?');">
                <?php echo shop_admin_csrf(); ?>
                <input type="hidden" name="action" value="email_template_reset" />
                <input type="hidden" name="code" value="<?php echo h($selected); ?>" />
                <button type="submit" class="shop-admin-btn">Сбросить к встроенному</button>
            </form>
            <?php endif; ?>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

function shop_admin_order_form()
{
    $currency = ShopHelper::activeCurrency();
    $deliveryMethods = ShopHelper::deliveryMethods($currency['code']);
    $paymentMethods = ShopHelper::paymentMethods();
    $code = ShopHelper::esc($currency['code']);
    $variants = ShopHelper::db()->query("SELECT v.`id`, v.`title` AS variant_title, v.`sku`, v.`stock`,
            p.`title` AS product_title, vp.`price`
        FROM `" . ShopHelper::table('variants') . "` v
        INNER JOIN `" . ShopHelper::table('products') . "` p ON p.`id` = v.`product_id`
        INNER JOIN `" . ShopHelper::table('variant_prices') . "` vp
            ON vp.`variant_id` = v.`id` AND vp.`currency_code` = '{$code}'
        WHERE v.`active` = 1 AND p.`active` = 1
        ORDER BY p.`title` ASC, v.`position` ASC, v.`id` ASC LIMIT 500");
    $deliveryKeys = array_keys($deliveryMethods);
    $paymentKeys = array_keys($paymentMethods);
    $firstDelivery = $deliveryKeys ? $deliveryKeys[0] : '';
    $firstPayment = $paymentKeys ? $paymentKeys[0] : '';

    ob_start(); ?>
    <div class="shop-admin-page-head">
        <h1>Новый заказ</h1>
        <a href="<?php echo h(shop_admin_url(array('tab' => 'orders'))); ?>">← К заказам</a>
    </div>
    <p class="shop-admin-muted">Заказ создаётся от имени магазина: остатки списываются сразу, покупателю можно отправить письмо.</p>

    <form method="post" action="" class="shop-admin-simple-form shop-admin-order-create">
        <?php echo shop_admin_csrf(); ?>
        <input type="hidden" name="action" value="order_create" />
        <input type="hidden" name="tab" value="order_create" />

        <h2>Покупатель</h2>
        <div class="shop-admin-field-grid">
            <label>Имя
                <input type="text" name="customer_name" maxlength="255" required="required" />
            </label>
            <label>E-mail
                <input type="email" name="email" maxlength="255" required="required" />
            </label>
            <label>Телефон
                <input type="text" name="phone" maxlength="64" required="required" />
            </label>
            <label>Адрес
                <input type="text" name="address" maxlength="255" />
            </label>
        </div>

        <h2>Доставка и оплата</h2>
        <div class="shop-admin-field-grid">
            <label>Доставка
                <select name="delivery_method">
                    <?php foreach ($deliveryMethods as $key => $method): ?>
                    <option value="<?php echo h($key); ?>"<?php echo $key === $firstDelivery ? ' selected="selected"' : ''; ?>><?php echo h($method['title'] . ' — ' . ShopHelper::formatPrice($method['price'], $currency['code'])); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Оплата
                <select name="payment_method">
                    <?php foreach ($paymentMethods as $key => $method): ?>
                    <option value="<?php echo h($key); ?>"<?php echo $key === $firstPayment ? ' selected="selected"' : ''; ?>><?php echo h($method['title']); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>

        <h2>Товары</h2>
        <?php if ($variants): ?>
        <?php for ($i = 0; $i < 5; $i++): ?>
        <div class="shop-admin-order-line">
            <select name="items[<?php echo $i; ?>][variant_id]">
                <option value="0">— товар —</option>
                <?php foreach ($variants as $variant): ?>
                <option value="<?php echo (int)$variant['id']; ?>"><?php echo h($variant['product_title'] . ' — ' . $variant['variant_title']); ?><?php echo $variant['sku'] !== '' ? h(' (' . $variant['sku'] . ')') : ''; ?> — <?php echo h(ShopHelper::formatPrice($variant['price'], $currency['code'])); ?>, <?php echo (int)$variant['stock']; ?> шт.</option>
                <?php endforeach; ?>
            </select>
            <input type="number" name="items[<?php echo $i; ?>][quantity]" min="0" max="999" value="0" />
        </div>
        <?php endfor; ?>
        <?php else: ?>
        <div class="shop-admin-empty">Нет активных товаров с ценой в <?php echo h($currency['code']); ?>.</div>
        <?php endif; ?>

        <label>Комментарий
            <textarea name="comment" rows="3"></textarea>
        </label>
        <label class="shop-admin-inline-check">
            <input type="checkbox" name="notify" value="1" checked="checked" />
            Отправить письмо о заказе покупателю
        </label>
        <button type="submit" class="shop-admin-btn shop-admin-btn-primary">Создать заказ</button>
    </form>
    <?php
    return ob_get_clean();
}

function shop_admin_promocodes()
{
    ShopHelper::ensurePromoTables();
    $rows = ShopHelper::db()->query("SELECT * FROM `" . ShopHelper::table('promocodes') . "`
        ORDER BY `active` DESC, `id` DESC");

    ob_start(); ?>
    <div class="shop-admin-page-head">
        <h1>Промокоды</h1>
        <a class="shop-admin-btn shop-admin-btn-primary" href="<?php echo h(shop_admin_url(array('tab' => 'promocode'))); ?>">Новый промокод</a>
    </div>

    <p class="shop-admin-muted">Скидка применяется к стоимости товаров. Доставка считается после скидки и не уменьшается. Лимит 0 означает отсутствие ограничения.</p>

    <?php if (!$rows): ?>
    <div class="shop-admin-empty">Промокодов пока нет.</div>
    <?php else: ?>
    <table class="grid shop-admin-table">
        <thead><tr><th>Код</th><th>Скидка</th><th>Мин. сумма</th><th>Применений</th><th>Период</th><th>Статус</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $row): ?>
            <?php
            $value = (float)$row['value'];
            $discountLabel = $row['discount_type'] === 'fixed'
                ? ShopHelper::formatPrice($value, $row['currency_code'])
                : rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.') . '%';
            $period = array();
            if (!empty($row['starts_at'])) $period[] = 'с ' . ShopHelper::formatDate($row['starts_at']);
            if (!empty($row['ends_at'])) $period[] = 'по ' . ShopHelper::formatDate($row['ends_at']);
            ?>
            <tr>
                <td><strong><?php echo h($row['code']); ?></strong></td>
                <td><?php echo h($discountLabel); ?></td>
                <td><?php echo (float)$row['min_total'] > 0 ? h(ShopHelper::formatPrice($row['min_total'], $row['currency_code'])) : '—'; ?></td>
                <td><?php echo (int)$row['used_count']; ?><?php echo (int)$row['max_uses'] > 0 ? ' / ' . (int)$row['max_uses'] : ''; ?></td>
                <td><?php echo $period ? h(implode(' ', $period)) : '—'; ?></td>
                <td><?php echo !empty($row['active']) ? 'Активен' : 'Выключен'; ?></td>
                <td>
                    <a class="shop-admin-btn" href="<?php echo h(shop_admin_url(array('tab' => 'promocode', 'id' => (int)$row['id']))); ?>">Изменить</a>
                    <form method="post" action="" class="shop-admin-inline-form" onsubmit="return confirm('Удалить промокод? Уже оформленные заказы сохранят скидку.');">
                        <?php echo shop_admin_csrf(); ?>
                        <input type="hidden" name="action" value="promocode_delete" />
                        <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>" />
                        <button type="submit" class="shop-admin-btn shop-admin-btn-danger">Удалить</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
    <?php
    return ob_get_clean();
}

function shop_admin_promocode_form()
{
    ShopHelper::ensurePromoTables();
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    $activeCurrency = ShopHelper::activeCurrency();
    $promo = array(
        'id' => 0,
        'code' => '',
        'discount_type' => 'percent',
        'value' => '',
        'currency_code' => $activeCurrency['code'],
        'min_total' => '0.00',
        'max_uses' => 0,
        'used_count' => 0,
        'starts_at' => null,
        'ends_at' => null,
        'active' => 1,
    );
    if ($id > 0) {
        $rows = ShopHelper::db()->query("SELECT * FROM `" . ShopHelper::table('promocodes') . "` WHERE `id` = {$id} LIMIT 1");
        if (empty($rows[0])) return '<div class="shop-admin-empty">Промокод не найден.</div>';
        $promo = $rows[0];
    }
    $currencies = ShopHelper::currencies(false);

    ob_start(); ?>
    <div class="shop-admin-page-head">
        <h1><?php echo $id > 0 ? 'Промокод ' . h($promo['code']) : 'Новый промокод'; ?></h1>
        <a href="<?php echo h(shop_admin_url(array('tab' => 'promocodes'))); ?>">← К промокодам</a>
    </div>

    <form method="post" action="" class="shop-admin-form">
        <?php echo shop_admin_csrf(); ?>
        <input type="hidden" name="action" value="promocode_save" />
        <input type="hidden" name="id" value="<?php echo (int)$promo['id']; ?>" />

        <div class="shop-admin-form-row">
            <label for="shop-promo-code">Код *</label>
            <input id="shop-promo-code" type="text" name="code" maxlength="64" required="required"
                value="<?php echo h($promo['code']); ?>" placeholder="SALE10" />
            <div class="shop-admin-muted">Латинские буквы, цифры, дефис и подчёркивание. Покупатель вводит код без учёта регистра.</div>
        </div>

        <div class="shop-admin-form-row">
            <label for="shop-promo-type">Тип скидки *</label>
            <select id="shop-promo-type" name="discount_type">
                <option value="percent"<?php echo $promo['discount_type'] === 'fixed' ? '' : ' selected="selected"'; ?>>Процент от суммы товаров</option>
                <option value="fixed"<?php echo $promo['discount_type'] === 'fixed' ? ' selected="selected"' : ''; ?>>Фиксированная сумма</option>
            </select>
        </div>

        <div class="shop-admin-form-row">
            <label for="shop-promo-value">Размер скидки *</label>
            <input id="shop-promo-value" type="number" min="0" step="0.01" name="value" required="required"
                value="<?php echo h($promo['value']); ?>" />
            <div class="shop-admin-muted">Для процента: от 0 до 100. Для фиксированной суммы: в валюте ниже.</div>
        </div>

        <div class="shop-admin-form-row">
            <label for="shop-promo-currency">Валюта фиксированной скидки</label>
            <select id="shop-promo-currency" name="currency_code">
                <?php foreach ($currencies as $code => $currency): ?>
                <option value="<?php echo h($code); ?>"<?php echo $promo['currency_code'] === $code ? ' selected="selected"' : ''; ?>><?php echo h($code . ' — ' . $currency['title']); ?></option>
                <?php endforeach; ?>
            </select>
            <div class="shop-admin-muted">Процентная скидка действует во всех валютах.</div>
        </div>

        <div class="shop-admin-form-row">
            <label for="shop-promo-min">Минимальная сумма товаров</label>
            <input id="shop-promo-min" type="number" min="0" step="0.01" name="min_total"
                value="<?php echo h($promo['min_total']); ?>" />
            <div class="shop-admin-muted">0 — без ограничения.</div>
        </div>

        <div class="shop-admin-form-row">
            <label for="shop-promo-max">Лимит применений</label>
            <input id="shop-promo-max" type="number" min="0" name="max_uses" value="<?php echo (int)$promo['max_uses']; ?>" />
            <div class="shop-admin-muted">0 — без ограничения. Уже применён: <?php echo (int)$promo['used_count']; ?>.</div>
        </div>

        <div class="shop-admin-form-row">
            <label for="shop-promo-start">Действует с</label>
            <input id="shop-promo-start" type="datetime-local" name="starts_at"
                value="<?php echo !empty($promo['starts_at']) ? h(date('Y-m-d\TH:i', strtotime($promo['starts_at']))) : ''; ?>" />
        </div>

        <div class="shop-admin-form-row">
            <label for="shop-promo-end">Действует по</label>
            <input id="shop-promo-end" type="datetime-local" name="ends_at"
                value="<?php echo !empty($promo['ends_at']) ? h(date('Y-m-d\TH:i', strtotime($promo['ends_at']))) : ''; ?>" />
        </div>

        <label class="shop-admin-inline-check">
            <input type="checkbox" name="active" value="1"<?php echo !empty($promo['active']) ? ' checked="checked"' : ''; ?> />
            Промокод активен
        </label>

        <div class="shop-admin-form-actions">
            <button type="submit" class="shop-admin-btn shop-admin-btn-primary">Сохранить</button>
            <a class="shop-admin-btn" href="<?php echo h(shop_admin_url(array('tab' => 'promocodes'))); ?>">Отмена</a>
        </div>
    </form>
    <?php
    return ob_get_clean();
}

function shop_admin_orders()
{
    list($whereSql, $status, $q) = shop_admin_orders_where();
    $page = max(1, (int)(isset($_GET['page']) ? $_GET['page'] : 1));
    $limit = 30;

    $countRows = ShopHelper::db()->query("SELECT COUNT(*) AS cnt FROM `" . ShopHelper::table('orders') . "`{$whereSql}");
    $total = !empty($countRows[0]['cnt']) ? (int)$countRows[0]['cnt'] : 0;
    $offset = ($page - 1) * $limit;
    $orders = ShopHelper::db()->query("SELECT * FROM `" . ShopHelper::table('orders') . "`{$whereSql}
        ORDER BY `id` DESC LIMIT {$limit} OFFSET {$offset}");

    $query = array('tab' => 'orders');
    if ($status !== '') $query['status'] = $status;
    if ($q !== '') $query['q'] = $q;
    $base = '/admin/shop.php?' . http_build_query($query);
    list($pagination, $currentPage) = pagination($total, $limit, $base);

    ob_start(); ?>
    <div class="shop-admin-page-head">
        <h1>Заказы</h1>
    </div>

    <div class="shop-admin-toolbar">
        <form method="get" action="<?php echo h(get_url('/admin/shop.php')); ?>">
            <input type="hidden" name="tab" value="orders" />
            <input type="text" name="q" value="<?php echo h($q); ?>" placeholder="Номер, имя, e-mail, телефон" />
            <select name="status">
                <option value="">Все статусы</option>
                <?php foreach (ShopHelper::statuses() as $code => $label): ?>
                <option value="<?php echo h($code); ?>"<?php echo $status === $code ? ' selected="selected"' : ''; ?>><?php echo h($label); ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="shop-admin-btn">Фильтр</button>
        </form>
        <a class="shop-admin-btn" href="<?php echo h(shop_admin_url(array('tab' => 'orders', 'status' => $status, 'q' => $q, 'export' => 'csv'))); ?>">Экспорт CSV</a>
        <a class="shop-admin-btn shop-admin-btn-primary" href="<?php echo h(shop_admin_url(array('tab' => 'order_create'))); ?>">Создать заказ</a>
    </div>

    <?php if ($orders): ?>
    <table class="grid shop-admin-table">
        <thead><tr><th>Заказ</th><th>Дата</th><th>Покупатель</th><th>Статус</th><th>Сумма</th><th>Доставка</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($orders as $order): ?>
            <tr>
                <td><a class="shop-admin-title-link" href="<?php echo h(shop_admin_url(array('tab' => 'order', 'id' => (int)$order['id']))); ?>">№<?php echo h(ShopHelper::orderNumber($order['id'])); ?></a></td>
                <td><?php echo h(ShopHelper::formatDate($order['created_at'])); ?></td>
                <td><?php echo h($order['customer_name']); ?><div class="shop-admin-muted"><?php echo h($order['email']); ?></div></td>
                <td><?php echo shop_admin_status_badge($order['status'], ShopHelper::statusLabel($order['status'])); ?></td>
                <td><?php echo h(ShopHelper::formatPrice($order['total'], $order['currency_code'])); ?></td>
                <td><?php echo h($order['delivery_title']); ?></td>
                <td><a href="<?php echo h(shop_admin_url(array('tab' => 'order', 'id' => (int)$order['id']))); ?>">Открыть</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php else: ?>
    <div class="shop-admin-empty">Заказы не найдены.</div>
    <?php endif; ?>

    <?php if ($pagination): ?><div class="shop-admin-pagination"><?php echo $pagination; ?></div><?php endif; ?>
    <?php
    return ob_get_clean();
}

function shop_admin_order()
{
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    $order = $id > 0 ? ShopHelper::getOrder($id) : false;
    if (!$order) return '<div class="shop-admin-empty">Заказ не найден.</div>';
    ShopHelper::ensureOrderTrackingColumns();
    $items = ShopHelper::getOrderItems($id, $order['currency_code']);

    try {
        $mail = ShopHelper::orderMailStatuses($id);
    } catch (Throwable $e) {
        $mail = array();
    }
    $mailLabels = array(
        'unknown' => 'Нет данных', 'pending' => 'Ожидает отправки', 'sent' => 'Принято почтовой службой',
        'failed' => 'Ошибка отправки', 'skipped' => 'Нет отдельного адреса получателя',
    );
    $editable = empty($order['stock_released']) && $order['status'] !== 'cancelled';
    $addVariants = array();
    if ($editable) {
        $code = ShopHelper::esc($order['currency_code']);
        $addVariants = ShopHelper::db()->query("SELECT v.`id`, v.`title` AS variant_title, v.`sku`, v.`stock`,
                p.`title` AS product_title, vp.`price`
            FROM `" . ShopHelper::table('variants') . "` v
            INNER JOIN `" . ShopHelper::table('products') . "` p ON p.`id` = v.`product_id`
            INNER JOIN `" . ShopHelper::table('variant_prices') . "` vp
                ON vp.`variant_id` = v.`id` AND vp.`currency_code` = '{$code}'
            WHERE v.`active` = 1 AND p.`active` = 1
            ORDER BY p.`title` ASC, v.`position` ASC, v.`id` ASC LIMIT 500");
    }

    ob_start(); ?>
    <div class="shop-admin-page-head">
        <h1>Заказ №<?php echo h($order['number']); ?></h1>
        <a href="<?php echo h(shop_admin_url(array('tab' => 'orders'))); ?>">← К заказам</a>
        <a href="<?php echo h(shop_admin_url(array('tab' => 'order', 'id' => $id, 'print' => '1'))); ?>" target="_blank">Печать счёта</a>
    </div>

    <div class="shop-admin-order-grid">
        <div class="shop-admin-panel">
            <div class="shop-admin-panel-head"><h2>Состав заказа</h2></div>
            <table class="grid shop-admin-table">
                <thead><tr><th>Товар</th><th>Артикул</th><th>Цена</th><th>Кол-во</th><th>Итого</th></tr></thead>
                <tbody>
                <?php foreach ($items as $item): ?>
                    <tr>
                        <td><strong><?php echo h($item['product_title']); ?></strong><div class="shop-admin-muted"><?php echo h($item['variant_title']); ?></div></td>
                        <td><?php echo h($item['sku']); ?></td>
                        <td><?php echo h($item['price_formatted']); ?></td>
                        <td><?php echo (int)$item['quantity']; ?></td>
                        <td><?php echo h($item['total_formatted']); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <div class="shop-admin-totals">
                <div><span>Товары:</span><span><?php echo h($order['subtotal_formatted']); ?></span></div>
                <?php if (!empty($order['discount'])): ?>
                <div><span>Скидка<?php echo $order['promocode'] !== '' ? ' (' . h($order['promocode']) . ')' : ''; ?>:</span><span>−<?php echo h($order['discount_formatted']); ?></span></div>
                <?php endif; ?>
                <div><span>Доставка:</span><span><?php echo h($order['delivery_price_formatted']); ?></span></div>
                <div class="total"><span>Итого:</span><span><?php echo h($order['total_formatted']); ?></span></div>
            </div>

            <?php if ($editable): ?>
            <details class="shop-admin-order-edit">
                <summary>Изменить состав заказа</summary>
                <form method="post" action="" class="shop-admin-simple-form">
                    <?php echo shop_admin_csrf(); ?>
                    <input type="hidden" name="action" value="order_items_save" />
                    <input type="hidden" name="id" value="<?php echo $id; ?>" />
                    <table class="grid shop-admin-table">
                        <thead><tr><th>Товар</th><th>Цена</th><th>Кол-во</th></tr></thead>
                        <tbody>
                        <?php foreach ($items as $item): ?>
                            <tr>
                                <td>
                                    <?php echo h($item['product_title']); ?>
                                    <div class="shop-admin-muted"><?php echo h($item['variant_title']); ?> <?php echo h($item['sku']); ?></div>
                                </td>
                                <td><?php echo h($item['price_formatted']); ?></td>
                                <td><input type="number" name="qty[<?php echo (int)$item['variant_id']; ?>]" min="0" max="999" value="<?php echo (int)$item['quantity']; ?>" /></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <p class="shop-admin-muted">Ноль удаляет позицию. Остатки склада пересчитываются автоматически.</p>
                    <?php if ($addVariants): ?>
                    <h3>Добавить товар</h3>
                    <?php for ($i = 0; $i < 3; $i++): ?>
                    <div class="shop-admin-order-line">
                        <select name="new_variant[<?php echo $i; ?>]">
                            <option value="0">— товар —</option>
                            <?php foreach ($addVariants as $variant): ?>
                            <option value="<?php echo (int)$variant['id']; ?>"><?php echo h($variant['product_title'] . ' — ' . $variant['variant_title']); ?><?php echo $variant['sku'] !== '' ? h(' (' . $variant['sku'] . ')') : ''; ?> — <?php echo h(ShopHelper::formatPrice($variant['price'], $order['currency_code'])); ?>, <?php echo (int)$variant['stock']; ?> шт.</option>
                            <?php endforeach; ?>
                        </select>
                        <input type="number" name="new_quantity[<?php echo $i; ?>]" min="0" max="999" value="0" />
                    </div>
                    <?php endfor; ?>
                    <?php endif; ?>
                    <button type="submit" class="shop-admin-btn shop-admin-btn-primary">Сохранить состав</button>
                </form>
            </details>
            <?php endif; ?>
        </div>

        <div class="shop-admin-panel">
            <div class="shop-admin-panel-head"><h2>Статус</h2></div>
            <p><?php echo shop_admin_status_badge($order['status'], $order['status_label']); ?></p>
            <form method="post" action="" class="shop-admin-simple-form">
                <?php echo shop_admin_csrf(); ?>
                <input type="hidden" name="action" value="order_status" />
                <input type="hidden" name="tab" value="order" />
                <input type="hidden" name="id" value="<?php echo $id; ?>" />
                <label>Новый статус
                    <select name="status">
                        <?php foreach (ShopHelper::statuses() as $code => $label): ?>
                        <option value="<?php echo h($code); ?>"<?php echo $order['status'] === $code ? ' selected="selected"' : ''; ?>><?php echo h($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Комментарий к статусу
                    <input type="text" name="note" maxlength="255" placeholder="Например: согласовано по телефону" />
                </label>
                <label class="shop-admin-inline-check">
                    <input type="checkbox" name="notify" value="1" checked="checked" />
                    Отправить письмо покупателю об изменении статуса
                </label>
                <button type="submit" class="shop-admin-btn shop-admin-btn-primary">Обновить статус</button>
            </form>
            <?php if (!empty($order['stock_released'])): ?>
            <p class="shop-admin-muted">Остатки по этому заказу были возвращены на склад.</p>
            <?php endif; ?>
            <?php if ($order['status'] === 'cancelled'): ?>
            <p class="shop-admin-muted">Отменённый заказ нельзя вернуть в работу.</p>
            <?php endif; ?>
            <?php $history = ShopHelper::getOrderStatusHistory($id); ?>
            <?php if ($history): ?>
            <h3>История статусов</h3>
            <ul class="shop-admin-history">
                <?php foreach ($history as $entry): ?>
                <li>
                    <strong><?php echo h($entry['status_label']); ?></strong>
                    <span class="shop-admin-muted"><?php echo h($entry['created_at_formatted']); ?></span>
                    <?php if (!empty($entry['user_name'])): ?>
                    <span class="shop-admin-muted">— <?php echo h($entry['user_name']); ?></span>
                    <?php endif; ?>
                    <?php if (!empty($entry['comment'])): ?>
                    <div><?php echo h($entry['comment']); ?></div>
                    <?php endif; ?>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </div>

        <div class="shop-admin-panel">
            <div class="shop-admin-panel-head"><h2>Покупатель</h2></div>
            <p><strong><?php echo h($order['customer_name']); ?></strong></p>
            <p><?php echo h($order['email']); ?><br /><?php echo h($order['phone']); ?></p>
            <p><a href="<?php echo h(get_url('/shop/order/' . $id . '/')); ?>" target="_blank">Открыть на сайте</a></p>
        </div>

        <div class="shop-admin-panel">
            <div class="shop-admin-panel-head"><h2>Уведомления</h2></div>
            <?php if (!$mail): ?>
                <p>Не удалось получить статусы уведомлений.</p>
            <?php endif; ?>
            <?php foreach (array('customer' => 'Покупатель', 'admin' => 'Администратор') as $recipient => $label): ?>
                <?php if (!isset($mail[$recipient . '_mail'])) continue; ?>
                <?php $state = $mail[$recipient . '_mail']; ?>
                <p><?php echo h($label . ' — ' . $mailLabels[$state]); ?></p>
                <?php if ($state !== 'sent'): ?>
                <form method="post" action="">
                    <?php echo shop_admin_csrf(); ?>
                    <input type="hidden" name="action" value="order_mail" />
                    <input type="hidden" name="id" value="<?php echo $id; ?>" />
                    <input type="hidden" name="recipient" value="<?php echo h($recipient); ?>" />
                    <button type="submit" class="shop-admin-btn">Отправить письмо — <?php echo h($label); ?></button>
                </form>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>

        <div class="shop-admin-panel">
            <div class="shop-admin-panel-head"><h2>Доставка и оплата</h2></div>
            <p><strong><?php echo h($order['delivery_title']); ?></strong></p>
            <?php if (!empty($order['address'])): ?><div class="shop-admin-pre"><?php echo h($order['address']); ?></div><?php endif; ?>
            <p><strong><?php echo h($order['payment_title']); ?></strong></p>
            <?php if (!empty($order['comment'])): ?>
            <h3>Комментарий</h3>
            <div class="shop-admin-pre"><?php echo h($order['comment']); ?></div>
            <?php endif; ?>
        </div>

        <div class="shop-admin-panel">
            <div class="shop-admin-panel-head"><h2>Отслеживание</h2></div>
            <form method="post" action="" class="shop-admin-simple-form">
                <?php echo shop_admin_csrf(); ?>
                <input type="hidden" name="action" value="order_tracking" />
                <input type="hidden" name="id" value="<?php echo $id; ?>" />
                <label>Трек-номер
                    <input type="text" name="tracking_number" maxlength="128" value="<?php echo h($order['tracking_number']); ?>" />
                </label>
                <label>Ссылка на трек
                    <input type="url" name="tracking_url" maxlength="255" value="<?php echo h($order['tracking_url']); ?>" placeholder="https://..." />
                </label>
                <label class="shop-admin-inline-check">
                    <input type="checkbox" name="notify" value="1" />
                    Отправить письмо покупателю с трек-номером
                </label>
                <button type="submit" class="shop-admin-btn shop-admin-btn-primary">Сохранить</button>
            </form>
        </div>
    </div>
    <?php
    return ob_get_clean();
}
