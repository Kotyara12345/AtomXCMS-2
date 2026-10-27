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
$productTabs = array('dashboard', 'products', 'product', 'variants', 'attributes', 'attribute', 'categories', 'category');
$orderTabs = array('orders', 'order');
if (!in_array($tab, array_merge($productTabs, $orderTabs), true)) $tab = 'dashboard';
if (in_array($tab, $productTabs, true) && !$canProducts) $tab = 'orders';
if (in_array($tab, $orderTabs, true) && !$canOrders) $tab = 'dashboard';

/* -------------------------------------------------------------------------
 * POST actions
 * ---------------------------------------------------------------------- */

if (!empty($_SERVER['REQUEST_METHOD']) && strtoupper($_SERVER['REQUEST_METHOD']) === 'POST'
    && !empty($_POST['action'])) {
    $action = (string)$_POST['action'];
    $isOrderAction = $action === 'order_status';
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

        $values = isset($_POST['attributes']) ? (array)$_POST['attributes'] : array();
        ShopHelper::syncProductAttributeValues($id, $values);
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
        $DB->query("DELETE FROM `" . ShopHelper::table('products') . "` WHERE `id` = {$id}");
        if (!empty($product['image'])) shop_admin_delete_upload($product['image']);

        $_SESSION['message'] = 'Товар удалён.';
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
                $DB->query("INSERT INTO `" . ShopHelper::table('variant_prices') . "`
                    (`variant_id`, `currency_code`, `price`) VALUES
                    ({$variantId}, '" . ShopHelper::esc($code) . "', " . number_format($price, 2, '.', '') . ")
                    ON DUPLICATE KEY UPDATE `price` = VALUES(`price`)");
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
            if ((!empty($usedProducts[0]['cnt']) && (int)$usedProducts[0]['cnt'] > 0)
                || (!empty($usedVariants[0]['cnt']) && (int)$usedVariants[0]['cnt'] > 0)) {
                $_SESSION['errors'] = 'Атрибут используется в товарах и не может быть удалён.';
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
        if ((!empty($usedProducts[0]['cnt']) && (int)$usedProducts[0]['cnt'] > 0)
            || (!empty($usedVariants[0]['cnt']) && (int)$usedVariants[0]['cnt'] > 0)) {
            $_SESSION['errors'] = 'Значение используется в товарах и не может быть удалено.';
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
        if ((!empty($children[0]['cnt']) && (int)$children[0]['cnt'] > 0)
            || (!empty($products[0]['cnt']) && (int)$products[0]['cnt'] > 0)) {
            $_SESSION['errors'] = 'Категория содержит подкатегории или товары и не может быть удалена.';
            shop_admin_go(array('tab' => 'categories'));
        }
        $category = ShopHelper::getCategory($id);
        $DB->query("DELETE FROM `" . ShopHelper::table('categories') . "` WHERE `id` = {$id}");
        if ($category && !empty($category['image'])) shop_admin_delete_upload($category['image']);
        $_SESSION['message'] = 'Категория удалена.';
        shop_admin_go(array('tab' => 'categories'));
    }

    if ($action === 'order_status') {
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        $status = isset($_POST['status']) ? (string)$_POST['status'] : '';
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
        if (!ShopHelper::setOrderStatus($id, $status)) {
            $_SESSION['errors'] = 'Не удалось изменить статус заказа.';
        } else {
            $_SESSION['message'] = 'Статус заказа обновлён.';
        }
        shop_admin_go(array('tab' => 'order', 'id' => $id));
    }
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
} elseif ($tab === 'orders') {
    $output .= shop_admin_orders();
} elseif ($tab === 'order') {
    $output .= shop_admin_order();
}

$output .= '</div>';

include_once ROOT . '/admin/template/header.php';
echo $output;
include_once ROOT . '/admin/template/footer.php';

/* -------------------------------------------------------------------------
 * Shared helpers
 * ---------------------------------------------------------------------- */

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

function shop_admin_upload_image($field, $old = '')
{
    if (empty($_FILES[$field]) || !isset($_FILES[$field]['error'])
        || (int)$_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) {
        return $old;
    }
    if ((int)$_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
        $_SESSION['errors'] = 'Не удалось загрузить изображение.';
        return $old;
    }

    $tmp = $_FILES[$field]['tmp_name'];
    $info = @getimagesize($tmp);
    if (!$info) {
        $_SESSION['errors'] = 'Файл не является изображением.';
        return $old;
    }

    $extension = strtolower(pathinfo((string)$_FILES[$field]['name'], PATHINFO_EXTENSION));
    if (!in_array($extension, array('jpg', 'jpeg', 'png', 'gif'), true)) {
        $_SESSION['errors'] = 'Допустимы только изображения JPG, PNG или GIF.';
        return $old;
    }
    if ($extension === 'jpeg') $extension = 'jpg';

    $fullDir = ROOT . '/sys/files/shop/full';
    $previewDir = ROOT . '/sys/files/shop/preview';
    if (!is_dir($fullDir)) @mkdir($fullDir, 0775, true);
    if (!is_dir($previewDir)) @mkdir($previewDir, 0775, true);

    $filename = 'shop_' . date('YmdHis') . '_' . mt_rand(1000, 9999) . '.' . $extension;
    $fullPath = $fullDir . '/' . $filename;
    $previewPath = $previewDir . '/' . $filename;
    if (!move_uploaded_file($tmp, $fullPath)) {
        $_SESSION['errors'] = 'Не удалось сохранить изображение.';
        return $old;
    }

    if (!function_exists('resampleImage')) include_once ROOT . '/sys/fnc/thumb.php';
    if (!resampleImage($fullPath, $previewPath, 600, 600) && !copy($fullPath, $previewPath)) {
        @unlink($previewPath);
    }
    return $filename;
}

function shop_admin_delete_upload($filename)
{
    $filename = basename((string)$filename);
    if ($filename === '') return;
    @unlink(ROOT . '/sys/files/shop/full/' . $filename);
    @unlink(ROOT . '/sys/files/shop/preview/' . $filename);
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

function shop_admin_tabs($tab, $canProducts, $canOrders, $canSettings)
{
    $tabs = array();
    if ($canProducts || $canOrders) $tabs['dashboard'] = 'Обзор';
    if ($canProducts) {
        $tabs['products'] = 'Товары';
        $tabs['attributes'] = 'Атрибуты';
        $tabs['categories'] = 'Категории';
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
        'orders' => 0,
        'new_orders' => 0,
    );
    $map = array(
        'products' => 'products',
        'categories' => 'categories',
        'attributes' => 'attributes',
        'variants' => 'variants',
        'orders' => 'orders',
    );
    foreach ($map as $key => $table) {
        $rows = $DB->query("SELECT COUNT(*) AS cnt FROM `" . ShopHelper::table($table) . "`");
        $counts[$key] = !empty($rows[0]['cnt']) ? (int)$rows[0]['cnt'] : 0;
    }
    $newRows = $DB->query("SELECT COUNT(*) AS cnt FROM `" . ShopHelper::table('orders') . "` WHERE `status` = 'new'");
    $counts['new_orders'] = !empty($newRows[0]['cnt']) ? (int)$newRows[0]['cnt'] : 0;

    $recent = $DB->query("SELECT * FROM `" . ShopHelper::table('orders') . "` ORDER BY `id` DESC LIMIT 8");
    $lowStock = $DB->query("SELECT v.`id`, v.`title`, v.`sku`, v.`stock`, p.`id` AS product_id, p.`title` AS product_title
        FROM `" . ShopHelper::table('variants') . "` v
        INNER JOIN `" . ShopHelper::table('products') . "` p ON p.`id` = v.`product_id`
        WHERE v.`active` = 1 AND v.`stock` <= 0
        ORDER BY p.`title` ASC, v.`id` ASC LIMIT 8");

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
                <h2>Нет в наличии</h2>
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
    </div>

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
        <label><input type="checkbox" name="remove_image" value="1" form="shop-product-form" /> Удалить изображение</label>
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
                <textarea id="shop-product-description" name="description" rows="10"><?php echo h($product ? $product['description'] : ''); ?></textarea>
            </div>
            <div class="shop-admin-field">
                <label for="shop-product-image">Изображение</label>
                <input id="shop-product-image" type="file" name="image" accept="image/jpeg,image/png,image/gif" />
            </div>
            <div class="shop-admin-field shop-admin-checkboxes">
                <label><input type="checkbox" name="active" value="1"<?php echo $isActive ? ' checked="checked"' : ''; ?> /> Активен</label>
                <label><input type="checkbox" name="featured" value="1"<?php echo $isFeatured ? ' checked="checked"' : ''; ?> /> Рекомендуемый</label>
            </div>
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

    $rows = ShopHelper::db()->query("SELECT v.*, vp.`currency_code`, vp.`price`
        FROM `" . ShopHelper::table('variants') . "` v
        LEFT JOIN `" . ShopHelper::table('variant_prices') . "` vp ON vp.`variant_id` = v.`id`
        WHERE v.`product_id` = {$id}
        ORDER BY v.`position` ASC, v.`id` ASC");
    $variants = array();
    foreach ($rows as $row) {
        $variantId = (int)$row['id'];
        if (!isset($variants[$variantId])) {
            $row['prices'] = array();
            $variants[$variantId] = $row;
        }
        if (!empty($row['currency_code'])) {
            $variants[$variantId]['prices'][$row['currency_code']] = $row['price'];
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
                        <th<?php echo $code === $activeCurrency['code'] ? ' class="active-currency"' : ''; ?>><?php echo h($code); ?></th>
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
                <thead><tr><th>Название</th><th>Товаров</th><th>Позиция</th><th>Статус</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($categories as $category): ?>
                    <tr>
                        <td>
                            <span style="padding-left:<?php echo (int)$category['depth'] * 18; ?>px;"><?php echo $category['depth'] ? '— ' : ''; ?><a href="<?php echo h(shop_admin_url(array('tab' => 'category', 'id' => (int)$category['id']))); ?>"><?php echo h($category['title']); ?></a></span>
                        </td>
                        <td><?php echo (int)$category['products_count']; ?></td>
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
                <textarea id="shop-category-description" name="description" rows="5"><?php echo h($category ? $category['description'] : ''); ?></textarea>
            </div>
            <div class="shop-admin-field">
                <label for="shop-category-image">Изображение</label>
                <input id="shop-category-image" type="file" name="image" accept="image/jpeg,image/png,image/gif" />
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
    <?php
    return ob_get_clean();
}

function shop_admin_orders()
{
    $status = isset($_GET['status']) ? preg_replace('/[^a-z_]/', '', (string)$_GET['status']) : '';
    $statuses = ShopHelper::statuses();
    if ($status !== '' && !isset($statuses[$status])) $status = '';
    $q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
    $page = max(1, (int)(isset($_GET['page']) ? $_GET['page'] : 1));
    $limit = 30;

    $where = array();
    if ($status !== '') $where[] = "`status` = '" . ShopHelper::esc($status) . "'";
    if ($q !== '') {
        $escaped = ShopHelper::esc($q);
        $where[] = "(`customer_name` LIKE '%{$escaped}%' OR `email` LIKE '%{$escaped}%'
            OR `phone` LIKE '%{$escaped}%' OR CAST(`id` AS CHAR) LIKE '%{$escaped}%')";
    }
    $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

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
    $items = ShopHelper::getOrderItems($id, $order['currency_code']);

    ob_start(); ?>
    <div class="shop-admin-page-head">
        <h1>Заказ №<?php echo h($order['number']); ?></h1>
        <a href="<?php echo h(shop_admin_url(array('tab' => 'orders'))); ?>">← К заказам</a>
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
                <div><span>Доставка:</span><span><?php echo h($order['delivery_price_formatted']); ?></span></div>
                <div class="total"><span>Итого:</span><span><?php echo h($order['total_formatted']); ?></span></div>
            </div>
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
                <button type="submit" class="shop-admin-btn shop-admin-btn-primary">Обновить статус</button>
            </form>
            <?php if (!empty($order['stock_released'])): ?>
            <p class="shop-admin-muted">Остатки по этому заказу были возвращены на склад.</p>
            <?php endif; ?>
            <?php if ($order['status'] === 'cancelled'): ?>
            <p class="shop-admin-muted">Отменённый заказ нельзя вернуть в работу.</p>
            <?php endif; ?>
        </div>

        <div class="shop-admin-panel">
            <div class="shop-admin-panel-head"><h2>Покупатель</h2></div>
            <p><strong><?php echo h($order['customer_name']); ?></strong></p>
            <p><?php echo h($order['email']); ?><br /><?php echo h($order['phone']); ?></p>
            <p><a href="<?php echo h(get_url('/shop/order/' . $id . '/' . $order['access_token'] . '/')); ?>" target="_blank">Открыть на сайте</a></p>
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
    </div>
    <?php
    return ob_get_clean();
}
