<?php

require_once __DIR__ . '/shop.lib.php';

/**
 * Public storefront controller.
 */
#[\AllowDynamicProperties]
class ShopModule extends Module
{
    public $module_title = 'Магазин';
    public $template = 'shop';
    public $module = 'shop';

    public function __construct($params)
    {
        parent::__construct($params);
        // Prices, stock and the active currency are dynamic; keep the module
        // out of the page cache until the cache key includes those values.
        $this->cached = false;
    }

    protected function setModel()
    {
        if (class_exists('ShopModel')) {
            $this->Model = new ShopModel();
        }
    }

    protected function getGlobalMarkers($html = '')
    {
        $markers = parent::getGlobalMarkers($html);
        $markers['shop_cart_count'] = ShopHelper::cartCount();
        $markers['shop_cart_url'] = get_url('/shop/cart/');
        return $markers;
    }

    /* ---------------------------------------------------------------------
     * Catalogue
     * ------------------------------------------------------------------ */

    public function index($tag = null)
    {
        $this->ACL->turn(array('shop', 'view_catalog'));
        return $this->renderCatalog(null, 0, 'Каталог товаров');
    }

    public function category($id = null)
    {
        $this->ACL->turn(array('shop', 'view_catalog'));
        $id = (int)$id;
        if ($id < 1) redirect('/shop/');

        $category = ShopHelper::getCategory($id);
        if (!$category || empty($category['active'])) $this->Parser->showHttpError();

        return $this->renderCatalog($category, $id, $category['title']);
    }

    public function landing($id = null)
    {
        $this->ACL->turn(array('shop', 'view_catalog'));
        $slug = trim((string)$id);
        if ($slug === '') $this->Parser->showHttpError();

        $extension = (string)Config::read('hlu_extention');
        if ($extension !== '' && substr($slug, -strlen($extension)) === $extension) {
            $slug = substr($slug, 0, -strlen($extension));
        }

        $landing = ShopHelper::getLandingPage($slug, true);
        if (!$landing || empty($landing['categories'])) $this->Parser->showHttpError();

        return $this->renderCatalog(null, 0, $landing['title'], $landing);
    }

    public function search()
    {
        $this->ACL->turn(array('shop', 'view_catalog'));
        return $this->renderCatalog(null, 0, 'Поиск по каталогу');
    }

    private function renderCatalog($category, $categoryId, $pageTitle, $landing = null)
    {
        $currency = ShopHelper::activeCurrency();
        $q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
        $sort = isset($_GET['sort']) ? (string)$_GET['sort'] : 'default';
        $allowedSorts = array('default', 'price_asc', 'price_desc', 'new', 'title');
        if (!in_array($sort, $allowedSorts, true)) $sort = 'default';

        $page = max(1, (int)(isset($_GET['page']) ? $_GET['page'] : 1));
        $perPage = max(1, (int)ShopHelper::cfg('per_page', 12));

        $categoryIds = array();
        if ($landing) {
            foreach ($landing['categories'] as $landingCategory) {
                foreach (ShopHelper::categoryDescendantIds((int)$landingCategory['id']) as $id) {
                    $categoryIds[] = (int)$id;
                }
            }
            $categoryIds = array_values(array_unique($categoryIds));
        } elseif ($categoryId > 0) {
            $categoryIds = ShopHelper::categoryDescendantIds($categoryId);
        }

        $filters = array(
            'category_ids' => $categoryIds,
            'q' => $q,
            'page' => $page,
            'limit' => $perPage,
            'sort' => $sort,
            'currency' => $currency['code'],
        );
        if ($landing && !empty($landing['attribute_value_ids'])) {
            $filters['attribute_value_ids'] = $landing['attribute_value_ids'];
        }
        $result = ShopHelper::getProducts($filters);

        if ($landing) {
            $baseUrl = ShopHelper::landingPath($landing);
        } elseif ($categoryId > 0) {
            $baseUrl = '/shop/category/' . $categoryId . '/';
        } else {
            $baseUrl = '/shop/';
        }
        $query = array();
        if ($q !== '') $query['q'] = $q;
        if ($sort !== 'default') $query['sort'] = $sort;
        if ($query) $baseUrl .= '?' . http_build_query($query);

        list($pagination, $currentPage) = pagination($result['total'], $perPage, $baseUrl);
        $this->Register['pages'] = $pagination;
        $this->Register['page'] = $currentPage;
        $this->Register['pagescnt'] = $result['pages'];
        $this->Register['totalitems'] = $result['total'];
        $this->addToPageMetaContext('page', $currentPage);

        $this->categories = $this->buildCategoriesHtml($categoryId);
        $this->page_title = h($pageTitle) . ' — ' . h(Config::read('title', 'shop'));
        if ($landing) {
            if (!empty($landing['meta_title'])) $this->page_title = h($landing['meta_title']);
            $this->addToPageMetaContext('landing_title', h($landing['title']));
            $description = trim(ShopHelper::descriptionText(!empty($landing['meta_description'])
                ? $landing['meta_description'] : $landing['description']));
            if ($description !== '') $this->page_meta_description = h($description);
            if (!empty($landing['meta_keywords'])) $this->page_meta_keywords = h($landing['meta_keywords']);
        } elseif ($category) {
            if (!empty($category['meta_title'])) $this->page_title = h($category['meta_title']);
            $this->addToPageMetaContext('category_title', h($category['title']));
            $description = trim(ShopHelper::descriptionText(!empty($category['meta_description'])
                ? $category['meta_description'] : $category['description']));
            if ($description !== '') $this->page_meta_description = h($description);
            if (!empty($category['meta_keywords'])) $this->page_meta_keywords = h($category['meta_keywords']);
        }

        $sortOptions = $this->buildSortOptions($categoryId, $q, $sort, $landing);
        $breadcrumbs = $landing
            ? $this->buildLandingBreadcrumbs($landing)
            : $this->buildCatalogBreadcrumbs($category);
        $landingPages = (!$landing && $categoryId > 0)
            ? ShopHelper::getLandingPagesForCategory($categoryId, true)
            : array();
        $filterGroups = $this->buildLandingFilterGroups($landing);
        $searchPath = $landing
            ? ShopHelper::landingPath($landing)
            : ($categoryId > 0 ? '/shop/category/' . $categoryId . '/' : '/shop/');

        $source = $this->render('list.html', array(
            'products' => $result['items'],
            'total' => $result['total'],
            'shop_pagination' => $pagination,
            'sort_options' => $sortOptions,
            'search_query' => $q,
            'category' => $category,
            'category_id' => $categoryId,
            'landing' => $landing,
            'landing_filter_groups' => $filterGroups,
            'landing_pages' => $landingPages,
            'categories_html' => $this->categories,
            'breadcrumbs' => $breadcrumbs,
            'currency' => $currency,
            'shop_url' => get_url('/shop/'),
            'cart_url' => get_url('/shop/cart/'),
            'search_action' => get_url($searchPath),
            'flash' => ShopHelper::getFlash(),
            'empty_text' => $landing ? 'По выбранным свойствам товары не найдены.' : 'Товары не найдены.',
        ));

        return $this->_view($source);
    }

    public function view($id = null)
    {
        $this->ACL->turn(array('shop', 'view_catalog'));
        $id = $this->getEntryId($id);
        if ($id < 1) $this->Parser->showHttpError();

        $product = ShopHelper::getProduct($id, true);
        if (!$product) $this->Parser->showHttpError();

        $gallery = ShopHelper::getProductImages($product['id'], true);
        if (empty($product['image_url']) && !empty($gallery)) {
            $product['image_url'] = $gallery[0]['full_url'];
            array_shift($gallery);
        }

        $currency = ShopHelper::activeCurrency();
        $variants = ShopHelper::getProductVariants($product['id'], $currency['code']);
        $firstAvailable = true;
        foreach ($variants as &$variant) {
            $variant['checked'] = ($firstAvailable && !empty($variant['available'])) ? 'checked="checked"' : '';
            $variant['disabled'] = empty($variant['available']) ? 'disabled="disabled"' : '';
            if (!empty($variant['available'])) $firstAvailable = false;
        }
        unset($variant);
        $attributes = ShopHelper::getProductAttributeGroups($product['id']);
        $stockTotal = 0;
        $inStock = false;
        foreach ($variants as $variant) {
            $stockTotal += (int)$variant['stock'];
            if (!empty($variant['available'])) $inStock = true;
        }

        $this->DB->query("UPDATE `" . ShopHelper::table('products') . "`
            SET `views` = `views` + 1 WHERE `id` = " . (int)$product['id']);

        $this->page_title = !empty($product['meta_title'])
            ? h($product['meta_title'])
            : h($product['title']) . ' — ' . h(Config::read('title', 'shop'));
        $this->addToPageMetaContext('entity_title', h($product['title']));
        if (!empty($product['meta_description'])) {
            $this->page_meta_description = h($product['meta_description']);
        } else {
            $this->page_meta_description = h(mb_substr(strip_tags((string)$product['short_description']), 0, 300));
        }
        if (!empty($product['meta_keywords'])) $this->page_meta_keywords = h($product['meta_keywords']);

        $this->categories = $this->buildCategoriesHtml($product['category_id']);
        $breadcrumbs = $this->buildProductBreadcrumbs($product);

        $source = $this->render('product.html', array(
            'product' => $product,
            'gallery' => $gallery,
            'variants' => $variants,
            'attributes' => $attributes,
            'stock_total' => $stockTotal,
            'in_stock' => $inStock,
            'currency' => $currency,
            'categories_html' => $this->categories,
            'breadcrumbs' => $breadcrumbs,
            'form_action' => get_url('/shop/add_to_cart/'),
            'return_url' => $product['url'],
            'csrf_token' => ShopHelper::csrfToken(),
            'flash' => ShopHelper::getFlash(),
            'shop_url' => get_url('/shop/'),
            'cart_url' => get_url('/shop/cart/'),
        ));

        return $this->_view($source);
    }

    /* ---------------------------------------------------------------------
     * Cart
     * ------------------------------------------------------------------ */

    public function cart()
    {
        $this->ACL->turn(array('shop', 'view_catalog'));
        $currency = ShopHelper::activeCurrency();
        $items = ShopHelper::cartItems($currency['code']);
        $totals = ShopHelper::cartTotals($items);

        $source = $this->render('cart.html', array(
            'items' => $items,
            'totals' => $totals,
            'currency' => $currency,
            'update_action' => get_url('/shop/update_cart/'),
            'remove_action' => get_url('/shop/remove_from_cart/'),
            'checkout_url' => get_url('/shop/checkout/'),
            'shop_url' => get_url('/shop/'),
            'cart_url' => get_url('/shop/cart/'),
            'csrf_token' => ShopHelper::csrfToken(),
            'flash' => ShopHelper::getFlash(),
            'breadcrumbs' => $this->buildSimpleBreadcrumbs('Корзина'),
        ));

        return $this->_view($source);
    }

    public function add_to_cart()
    {
        $this->ACL->turn(array('shop', 'view_catalog'));
        if (empty($_SERVER['REQUEST_METHOD']) || strtoupper($_SERVER['REQUEST_METHOD']) !== 'POST') {
            redirect('/shop/cart/');
        }

        if (!ShopHelper::csrfCheck(isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '')) {
            ShopHelper::flash('error', 'Сессия истекла. Попробуйте ещё раз.');
            $this->redirectBack();
        }

        $variantId = isset($_POST['variant_id']) ? (int)$_POST['variant_id'] : 0;
        $qty = isset($_POST['qty']) ? (int)$_POST['qty'] : 1;
        $result = ShopHelper::addToCart($variantId, $qty);
        ShopHelper::flash($result['ok'] ? 'success' : 'error', $result['message']);
        $this->redirectBack();
    }

    public function update_cart()
    {
        $this->ACL->turn(array('shop', 'view_catalog'));
        if (empty($_SERVER['REQUEST_METHOD']) || strtoupper($_SERVER['REQUEST_METHOD']) !== 'POST') {
            redirect('/shop/cart/');
        }
        if (!ShopHelper::csrfCheck(isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '')) {
            ShopHelper::flash('error', 'Сессия истекла. Попробуйте ещё раз.');
            redirect('/shop/cart/');
        }
        ShopHelper::updateCart(isset($_POST['qty']) ? $_POST['qty'] : array());
        ShopHelper::flash('success', 'Корзина обновлена.');
        redirect('/shop/cart/');
    }

    public function remove_from_cart()
    {
        $this->ACL->turn(array('shop', 'view_catalog'));
        if (empty($_SERVER['REQUEST_METHOD']) || strtoupper($_SERVER['REQUEST_METHOD']) !== 'POST') {
            redirect('/shop/cart/');
        }
        if (!ShopHelper::csrfCheck(isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '')) {
            ShopHelper::flash('error', 'Сессия истекла. Попробуйте ещё раз.');
            redirect('/shop/cart/');
        }
        ShopHelper::removeFromCart(isset($_POST['variant_id']) ? $_POST['variant_id'] : 0);
        ShopHelper::flash('success', 'Товар удалён из корзины.');
        redirect('/shop/cart/');
    }

    /* ---------------------------------------------------------------------
     * Checkout
     * ------------------------------------------------------------------ */

    public function checkout()
    {
        $this->ACL->turn(array('shop', 'place_orders'));
        $items = ShopHelper::cartItems();
        if (empty($items)) {
            ShopHelper::flash('error', 'Корзина пуста.');
            redirect('/shop/cart/');
        }

        $user = ShopHelper::currentUser();
        return $this->renderCheckout(array(
            'customer_name' => $user['name'],
            'email' => $user['email'],
            'phone' => $user['phone'],
            'address' => '',
            'comment' => '',
            'delivery_method' => '',
            'payment_method' => '',
        ), array());
    }

    public function place_order()
    {
        $this->ACL->turn(array('shop', 'place_orders'));
        if (empty($_SERVER['REQUEST_METHOD']) || strtoupper($_SERVER['REQUEST_METHOD']) !== 'POST') {
            redirect('/shop/checkout/');
        }
        if (!ShopHelper::csrfCheck(isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '')) {
            ShopHelper::flash('error', 'Сессия истекла. Попробуйте ещё раз.');
            redirect('/shop/checkout/');
        }

        $items = ShopHelper::cartItems();
        if (empty($items)) {
            ShopHelper::flash('error', 'Корзина пуста.');
            redirect('/shop/cart/');
        }

        $old = array(
            'customer_name' => trim((string)(isset($_POST['customer_name']) ? $_POST['customer_name'] : '')),
            'email' => trim((string)(isset($_POST['email']) ? $_POST['email'] : '')),
            'phone' => trim((string)(isset($_POST['phone']) ? $_POST['phone'] : '')),
            'address' => trim((string)(isset($_POST['address']) ? $_POST['address'] : '')),
            'comment' => trim((string)(isset($_POST['comment']) ? $_POST['comment'] : '')),
            'delivery_method' => (string)(isset($_POST['delivery_method']) ? $_POST['delivery_method'] : ''),
            'payment_method' => (string)(isset($_POST['payment_method']) ? $_POST['payment_method'] : ''),
        );

        $errors = array();
        if ($old['customer_name'] === '') $errors[] = 'Укажите имя.';
        if ($old['email'] === '' || !filter_var($old['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Укажите корректный e-mail.';
        }
        if ($old['phone'] === '') $errors[] = 'Укажите телефон.';

        $currency = ShopHelper::activeCurrency();
        $deliveryMethods = ShopHelper::deliveryMethods($currency['code']);
        $paymentMethods = ShopHelper::paymentMethods();
        if (!isset($deliveryMethods[$old['delivery_method']])) {
            $errors[] = 'Выберите способ доставки.';
        }
        if (!isset($paymentMethods[$old['payment_method']])) {
            $errors[] = 'Выберите способ оплаты.';
        }
        $old['delivery_method'] = isset($deliveryMethods[$old['delivery_method']])
            ? $old['delivery_method'] : key($deliveryMethods);
        $old['payment_method'] = isset($paymentMethods[$old['payment_method']])
            ? $old['payment_method'] : key($paymentMethods);

        if ($old['delivery_method'] === 'courier' && $old['address'] === '') {
            $errors[] = 'Укажите адрес доставки.';
        }
        if (empty($_POST['agree'])) {
            $errors[] = 'Подтвердите согласие с условиями заказа.';
        }

        if (!empty($errors)) {
            return $this->renderCheckout($old, $errors);
        }

        $subtotal = 0.0;
        foreach ($items as $item) $subtotal += (float)$item['total'];
        $deliveryPrice = ShopHelper::deliveryPrice($old['delivery_method'], $currency['code']);
        $total = $subtotal + $deliveryPrice;
        $user = ShopHelper::currentUser();
        $now = date('Y-m-d H:i:s');
        try {
            $token = bin2hex(random_bytes(20));
        } catch (Throwable $e) {
            $token = md5(uniqid('shop-order', true));
        }

        $DB = ShopHelper::db();
        $variants = ShopHelper::table('variants');
        $orderId = 0;
        try {
            $DB->query('START TRANSACTION');
            foreach ($items as $item) {
                $variantId = (int)$item['variant_id'];
                $qty = (int)$item['quantity'];
                $rows = $DB->query("SELECT `stock`, `active` FROM `{$variants}`
                    WHERE `id` = {$variantId} FOR UPDATE");
                if (empty($rows[0]) || empty($rows[0]['active']) || (int)$rows[0]['stock'] < $qty) {
                    throw new Exception('Недостаточно товара «' . $item['product_title'] . '».');
                }
                $DB->query("UPDATE `{$variants}` SET `stock` = `stock` - {$qty}
                    WHERE `id` = {$variantId}");
            }

            $orderId = (int)$DB->save('shop_orders', array(
                'user_id' => (int)$user['id'],
                'status' => 'new',
                'currency_code' => $currency['code'],
                'subtotal' => $subtotal,
                'delivery_price' => $deliveryPrice,
                'total' => $total,
                'customer_name' => mb_substr($old['customer_name'], 0, 255),
                'email' => mb_substr($old['email'], 0, 255),
                'phone' => mb_substr($old['phone'], 0, 64),
                'address' => $old['address'],
                'comment' => $old['comment'],
                'delivery_method' => $old['delivery_method'],
                'delivery_title' => $deliveryMethods[$old['delivery_method']]['title'],
                'payment_method' => $old['payment_method'],
                'payment_title' => $paymentMethods[$old['payment_method']]['title'],
                'access_token' => $token,
                'stock_released' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ));

            foreach ($items as $item) {
                $DB->save('shop_order_items', array(
                    'order_id' => $orderId,
                    'product_id' => (int)$item['product_id'],
                    'variant_id' => (int)$item['variant_id'],
                    'product_title' => $item['product_title'],
                    'variant_title' => $item['variant_title'],
                    'sku' => $item['sku'],
                    'price' => (float)$item['price'],
                    'quantity' => (int)$item['quantity'],
                    'total' => (float)$item['total'],
                ));
            }
            $DB->query('COMMIT');
        } catch (Throwable $e) {
            try { $DB->query('ROLLBACK'); } catch (Throwable $ignored) {}
            return $this->renderCheckout($old, array($e->getMessage()));
        }

        $order = ShopHelper::getOrder($orderId);
        $orderItems = ShopHelper::getOrderItems($orderId, $currency['code']);
        if ($order) ShopHelper::sendOrderEmails($order, $orderItems);

        ShopHelper::saveCart(array());
        $_SESSION['shop_last_order'] = array('id' => $orderId, 'token' => $token);
        redirect('/shop/success/');
    }

    private function renderCheckout($old, $errors)
    {
        $currency = ShopHelper::activeCurrency();
        $items = ShopHelper::cartItems($currency['code']);
        if (empty($items)) {
            ShopHelper::flash('error', 'Корзина пуста.');
            redirect('/shop/cart/');
        }

        $deliveryMethods = ShopHelper::deliveryMethods($currency['code']);
        $paymentMethods = ShopHelper::paymentMethods();
        $deliveryMethod = isset($deliveryMethods[$old['delivery_method']])
            ? $old['delivery_method'] : key($deliveryMethods);
        $paymentMethod = isset($paymentMethods[$old['payment_method']])
            ? $old['payment_method'] : key($paymentMethods);
        $subtotal = 0.0;
        foreach ($items as $item) $subtotal += (float)$item['total'];
        $deliveryPrice = ShopHelper::deliveryPrice($deliveryMethod, $currency['code']);
        $total = $subtotal + $deliveryPrice;

        $flash = ShopHelper::getFlash();
        $errorsHtml = '';
        if ($flash && $flash['type'] === 'error') $errors[] = $flash['message'];
        foreach ($errors as $error) {
            $errorsHtml .= '<div class="shop-alert shop-alert-error">' . h($error) . '</div>';
        }
        if ($flash && $flash['type'] === 'success') {
            $errorsHtml .= '<div class="shop-alert shop-alert-success">' . h($flash['message']) . '</div>';
        }

        $this->page_title = 'Оформление заказа — ' . h(Config::read('title', 'shop'));
        $source = $this->render('checkout.html', array(
            'items' => $items,
            'currency' => $currency,
            'subtotal_raw' => $subtotal,
            'subtotal_formatted' => ShopHelper::formatPrice($subtotal, $currency['code']),
            'delivery_price_formatted' => ShopHelper::formatPrice($deliveryPrice, $currency['code']),
            'total_formatted' => ShopHelper::formatPrice($total, $currency['code']),
            'delivery_method' => $deliveryMethod,
            'payment_method' => $paymentMethod,
            'delivery_methods' => $deliveryMethods,
            'payment_methods' => $paymentMethods,
            'old' => $old,
            'errors_html' => $errorsHtml,
            'form_action' => get_url('/shop/place_order/'),
            'csrf_token' => ShopHelper::csrfToken(),
            'cart_url' => get_url('/shop/cart/'),
            'shop_url' => get_url('/shop/'),
            'breadcrumbs' => $this->buildSimpleBreadcrumbs('Оформление заказа'),
        ));

        return $this->_view($source);
    }

    public function success()
    {
        $this->ACL->turn(array('shop', 'view_catalog'));
        if (empty($_SESSION['shop_last_order']['id'])) redirect('/shop/');
        $orderId = (int)$_SESSION['shop_last_order']['id'];
        $order = ShopHelper::getOrder($orderId);
        if (!$order) redirect('/shop/');
        $items = ShopHelper::getOrderItems($orderId, $order['currency_code']);

        $this->page_title = 'Заказ оформлен — ' . h(Config::read('title', 'shop'));
        $source = $this->render('success.html', array(
            'order' => $order,
            'items' => $items,
            'order_url' => get_url('/shop/order/' . $orderId . '/' . $order['access_token'] . '/'),
            'shop_url' => get_url('/shop/'),
            'orders_url' => get_url('/shop/orders/'),
            'breadcrumbs' => $this->buildSimpleBreadcrumbs('Заказ оформлен'),
        ));
        return $this->_view($source);
    }

    public function orders()
    {
        $this->ACL->turn(array('shop', 'view_catalog'));
        $user = ShopHelper::currentUser();
        if (empty($user['id'])) {
            ShopHelper::flash('error', 'Войдите, чтобы увидеть свои заказы.');
            redirect('/users/login_form/');
        }
        $orders = ShopHelper::getUserOrders($user['id']);
        $this->page_title = 'Мои заказы — ' . h(Config::read('title', 'shop'));
        $source = $this->render('orders.html', array(
            'orders' => $orders,
            'shop_url' => get_url('/shop/'),
            'breadcrumbs' => $this->buildSimpleBreadcrumbs('Мои заказы'),
        ));
        return $this->_view($source);
    }

    public function order($id = null, $token = null)
    {
        $this->ACL->turn(array('shop', 'view_catalog'));
        $id = (int)$id;
        $order = $id > 0 ? ShopHelper::getOrder($id) : false;
        if (!$order) $this->Parser->showHttpError();

        $user = ShopHelper::currentUser();
        $isOwner = !empty($user['id']) && (int)$order['user_id'] === (int)$user['id'];
        $token = (string)$token;
        $isToken = $token !== '' && !empty($order['access_token'])
            && hash_equals((string)$order['access_token'], $token);
        $isAdmin = $this->ACL->turn(array('shop', 'manage_orders'), false);
        if (!$isOwner && !$isToken && !$isAdmin) $this->Parser->showHttpError();

        $items = ShopHelper::getOrderItems($id, $order['currency_code']);
        $this->page_title = 'Заказ №' . $order['number'] . ' — ' . h(Config::read('title', 'shop'));
        $source = $this->render('order.html', array(
            'order' => $order,
            'items' => $items,
            'shop_url' => get_url('/shop/'),
            'orders_url' => get_url('/shop/orders/'),
            'breadcrumbs' => $this->buildSimpleBreadcrumbs('Заказ №' . $order['number']),
        ));
        return $this->_view($source);
    }

    /* ---------------------------------------------------------------------
     * Helpers
     * ------------------------------------------------------------------ */

    public function getEntryId($id)
    {
        if (is_numeric($id)) return (int)$id;
        $slug = (string)$id;
        $extension = (string)Config::read('hlu_extention');
        if ($extension !== '' && substr($slug, -strlen($extension)) === $extension) {
            $slug = substr($slug, 0, -strlen($extension));
        }
        $product = ShopHelper::getProduct($slug, true);
        return $product ? (int)$product['id'] : 0;
    }

    private function redirectBack()
    {
        $return = isset($_POST['return']) ? (string)$_POST['return'] : '';
        if ($return === '' || strpos($return, '/shop/') !== 0
            || strpos($return, '//') !== false
            || strpos($return, '\\') !== false
            || strpos($return, "\r") !== false
            || strpos($return, "\n") !== false) {
            $return = '/shop/cart/';
        }
        redirect($return);
    }

    private function buildCategoriesHtml($currentId = 0)
    {
        $tree = ShopHelper::categoryTree();
        $html = '<div class="shop-sidebar-title">Категории</div>';
        $html .= '<ul class="shop-cats shop-cats-all"><li><a href="' . h(get_url('/shop/')) . '">Все товары</a></li></ul>';
        $html .= ShopHelper::categoryTreeHtml($tree, $currentId);
        return $html;
    }

    private function buildSortOptions($categoryId, $q, $selected, $landing = null)
    {
        if ($landing) {
            $base = ShopHelper::landingPath($landing);
        } else {
            $base = $categoryId > 0 ? '/shop/category/' . $categoryId . '/' : '/shop/';
        }
        $options = array(
            'default' => 'По умолчанию',
            'price_asc' => 'Сначала дешёвые',
            'price_desc' => 'Сначала дорогие',
            'new' => 'Сначала новые',
            'title' => 'По названию',
        );
        $result = array();
        foreach ($options as $value => $title) {
            $query = array();
            if ($q !== '') $query['q'] = $q;
            if ($value !== 'default') $query['sort'] = $value;
            $url = $base . ($query ? '?' . http_build_query($query) : '');
            $result[] = array(
                'value' => $value,
                'title' => $title,
                'url' => get_url($url),
                'selected' => $value === $selected,
            );
        }
        return $result;
    }

    private function buildCatalogBreadcrumbs($category)
    {
        $html = '<div class="shop-breadcrumbs"><a href="' . h(get_url('/')) . '">Главная</a> / '
            . '<a href="' . h(get_url('/shop/')) . '">Магазин</a>';
        if ($category) {
            $crumbs = ShopHelper::categoryBreadcrumbs($category['id']);
            foreach ($crumbs as $crumb) {
                $html .= ' / <a href="' . h($crumb['url']) . '">' . h($crumb['title']) . '</a>';
            }
        }
        $html .= '</div>';
        return $html;
    }

    private function buildLandingBreadcrumbs($landing)
    {
        $html = '<div class="shop-breadcrumbs"><a href="' . h(get_url('/')) . '">Главная</a> / '
            . '<a href="' . h(get_url('/shop/')) . '">Магазин</a>';
        $categoryLinks = array();
        foreach ($landing['categories'] as $category) {
            $categoryLinks[] = '<a href="' . h($category['url']) . '">' . h($category['title']) . '</a>';
        }
        if ($categoryLinks) $html .= ' / ' . implode(', ', $categoryLinks);
        $html .= ' / <span>' . h($landing['title']) . '</span></div>';
        return $html;
    }

    private function buildLandingFilterGroups($landing)
    {
        if (!$landing || empty($landing['attribute_groups'])) return array();
        $result = array();
        foreach ($landing['attribute_groups'] as $group) {
            $titles = array();
            foreach ($group['values'] as $value) $titles[] = $value['title'];
            if (!$titles) continue;
            $result[] = array(
                'id' => (int)$group['id'],
                'title' => $group['title'],
                'value_titles' => implode(', ', $titles),
            );
        }
        return $result;
    }

    private function buildProductBreadcrumbs($product)
    {
        $html = '<div class="shop-breadcrumbs"><a href="' . h(get_url('/')) . '">Главная</a> / '
            . '<a href="' . h(get_url('/shop/')) . '">Магазин</a>';
        if (!empty($product['category_id'])) {
            $crumbs = ShopHelper::categoryBreadcrumbs($product['category_id']);
            foreach ($crumbs as $crumb) {
                $html .= ' / <a href="' . h($crumb['url']) . '">' . h($crumb['title']) . '</a>';
            }
        }
        $html .= ' / <span>' . h($product['title']) . '</span></div>';
        return $html;
    }

    private function buildSimpleBreadcrumbs($title)
    {
        return '<div class="shop-breadcrumbs"><a href="' . h(get_url('/')) . '">Главная</a> / '
            . '<a href="' . h(get_url('/shop/')) . '">Магазин</a> / <span>' . h($title) . '</span></div>';
    }
}

