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

    /** @var bool */
    private $isProductPage = false;

    /** @var string */
    private $ogImageUrl = '';

    /** @var array */
    private $breadcrumbData = array();

    /** @var string */
    private $productStructuredData = '';

    /** @var string */
    private $prevPageUrl = '';

    /** @var string */
    private $nextPageUrl = '';

    /** @var array */
    private $productRating = array('avg' => 0.0, 'count' => 0);

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

    /**
     * The shipped layouts print the "open_graph" marker inside <head>.
     * It carries the shop's Open Graph tags, JSON-LD and pagination links.
     */
    protected function getOpenGraphMarkup()
    {
        $markup = parent::getOpenGraphMarkup();

        if ($markup !== '' && $this->ogImageUrl !== '') {
            $markup = '<meta property="og:image" content="' . h($this->ogImageUrl) . '" />'
                . "\n" . $markup;
        }

        $links = '';
        if ($this->prevPageUrl !== '') {
            $links .= '<link rel="prev" href="' . h($this->prevPageUrl) . '" />' . "\n";
        }
        if ($this->nextPageUrl !== '') {
            $links .= '<link rel="next" href="' . h($this->nextPageUrl) . '" />' . "\n";
        }

        return $markup . $links . $this->productStructuredData
            . $this->buildBreadcrumbStructuredData();
    }

    protected function getOpenGraphType()
    {
        return $this->isProductPage ? 'product' : parent::getOpenGraphType();
    }

    /**
     * Internal catalogue search must not compete with category pages.
     */
    protected function getRobotsMeta()
    {
        if (isset($_GET['q']) && trim((string)$_GET['q']) !== '') {
            return 'noindex, follow';
        }
        return parent::getRobotsMeta();
    }

    /**
     * Unpaid orders return their stock after the configured time.
     * Request-driven like the sitemap cron; no system scheduler needed.
     */
    protected function _afterRender()
    {
        if (function_exists('fpsCron') && (int)ShopHelper::cfg('active', 1) === 1) {
            fpsCron('shopExpireOrders', 900);
            fpsCron('shopNotifyStock', 900);
            fpsCron('shopLowStockAlert', 86400);
        }
        parent::_afterRender();
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
        $perPage = min(100, max(1, (int)ShopHelper::cfg('per_page', 12)));

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
        $this->attachRatings($result['items']);
        $featuredProducts = array();
        if (!$landing && $categoryId === 0 && $q === '' && $page === 1) {
            $featuredResult = ShopHelper::getProducts(array(
                'featured' => 1,
                'limit' => 4,
                'currency' => $currency['code'],
            ));
            $this->attachRatings($featuredResult['items']);
            $featuredProducts = $featuredResult['items'];
        }

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
        $this->prevPageUrl = $currentPage > 1
            ? $this->pageUrl($baseUrl, $currentPage - 1) : '';
        $this->nextPageUrl = $currentPage < $result['pages']
            ? $this->pageUrl($baseUrl, $currentPage + 1) : '';
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
        if ($category && !empty($category['image'])) {
            $this->ogImageUrl = $this->absoluteUrl(ShopHelper::imageUrl($category['image'], false));
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
            'featured_products' => $featuredProducts,
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
            'favorites_url' => get_url('/shop/favorites/'),
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

        $this->isProductPage = true;
        $this->ogImageUrl = !empty($product['image_url'])
            ? $this->absoluteUrl($product['image_url']) : '';
        $reviews = ShopHelper::getProductReviews($product['id'], true);
        $this->productRating = ShopHelper::productRating($product['id']);
        $this->productStructuredData = $this->buildProductStructuredData($product, $variants, $currency, $gallery);
        ShopHelper::recordProductView($product['id']);

        $similarProducts = ShopHelper::similarProducts($product, 4);
        $this->attachRatings($similarProducts);
        $recentlyViewed = ShopHelper::getRecentlyViewed($product['id'], 4);
        $this->attachRatings($recentlyViewed);

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

        $user = ShopHelper::currentUser();
        $userId = (int)$user['id'];
        $isFavorite = $userId > 0 && ShopHelper::isFavorite($userId, $product['id']);

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
            'favorites_url' => get_url('/shop/favorites/'),
            'is_logged_in' => $userId > 0,
            'is_favorite' => $isFavorite,
            'favorite_action' => get_url($isFavorite
                ? '/shop/remove_from_favorites/' : '/shop/add_to_favorites/'),
            'login_url' => get_url('/users/login_form/'),
            'reviews' => $reviews,
            'rating' => $this->productRating,
            'rating_stars' => $this->productRating['count'] > 0
                ? str_repeat('★', max(1, (int)round($this->productRating['avg']))) : '',
            'rating_avg_formatted' => $this->productRating['count'] > 0
                ? number_format($this->productRating['avg'], 1, ',', '') : '',
            'reviews_enabled' => (int)ShopHelper::cfg('reviews_enabled', 1) === 1,
            'review_action' => get_url('/shop/add_review/'),
            'stock_notify_action' => get_url('/shop/notify_stock/'),
            'can_notify_stock' => !$inStock,
            'user_name' => $user['name'],
            'user_email' => $user['email'],
            'similar_products' => $similarProducts,
            'recently_viewed' => $recentlyViewed,
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
     * Favorites
     * ------------------------------------------------------------------ */

    public function favorites()
    {
        $this->ACL->turn(array('shop', 'view_catalog'));
        $userId = $this->requireFavoriteUser();
        $result = ShopHelper::getFavorites($userId);

        $source = $this->render('favorites.html', array(
            'products' => $result['items'],
            'total' => $result['total'],
            'categories_html' => $this->buildCategoriesHtml(0),
            'breadcrumbs' => $this->buildSimpleBreadcrumbs('Избранное'),
            'remove_action' => get_url('/shop/remove_from_favorites/'),
            'csrf_token' => ShopHelper::csrfToken(),
            'flash' => ShopHelper::getFlash(),
            'shop_url' => get_url('/shop/'),
            'cart_url' => get_url('/shop/cart/'),
            'favorites_url' => get_url('/shop/favorites/'),
        ));

        $this->page_title = 'Избранное — ' . h(Config::read('title', 'shop'));
        return $this->_view($source);
    }

    public function add_to_favorites()
    {
        $this->ACL->turn(array('shop', 'view_catalog'));
        $this->handleFavoriteAction(true);
    }

    public function remove_from_favorites()
    {
        $this->ACL->turn(array('shop', 'view_catalog'));
        $this->handleFavoriteAction(false);
    }

    public function add_review()
    {
        $this->ACL->turn(array('shop', 'view_catalog'));
        if (empty($_SERVER['REQUEST_METHOD']) || strtoupper($_SERVER['REQUEST_METHOD']) !== 'POST') {
            redirect('/shop/');
        }

        $productId = isset($_POST['product_id']) ? (int)$_POST['product_id'] : 0;
        $product = $productId > 0 ? ShopHelper::getProduct($productId, true) : false;
        if (!$product) {
            ShopHelper::flash('error', 'Товар не найден.');
            redirect('/shop/');
        }
        if (!ShopHelper::csrfCheck(isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '')) {
            ShopHelper::flash('error', 'Сессия истекла. Попробуйте ещё раз.');
            $this->redirectBack($product['url']);
        }
        if ((int)ShopHelper::cfg('reviews_enabled', 1) !== 1) {
            ShopHelper::flash('error', 'Отзывы отключены.');
            $this->redirectBack($product['url']);
        }

        $user = ShopHelper::currentUser();
        $name = trim((string)(isset($_POST['name']) ? $_POST['name'] : ''));
        $email = trim((string)(isset($_POST['email']) ? $_POST['email'] : ''));
        if ($name === '' && $user['name'] !== '') $name = $user['name'];
        if ($email === '' && $user['email'] !== '') $email = $user['email'];

        $saved = ShopHelper::addReview(array(
            'product_id' => $productId,
            'user_id' => (int)$user['id'],
            'name' => $name,
            'email' => $email,
            'rating' => isset($_POST['rating']) ? (int)$_POST['rating'] : 0,
            'text' => isset($_POST['text']) ? (string)$_POST['text'] : '',
        ));

        if (!$saved) {
            ShopHelper::flash('error', 'Заполните имя, e-mail, оценку и текст отзыва.');
        } elseif ((int)ShopHelper::cfg('reviews_moderate', 1) === 1) {
            ShopHelper::flash('success', 'Спасибо! Отзыв появится после проверки.');
        } else {
            ShopHelper::flash('success', 'Спасибо за отзыв!');
        }
        $this->redirectBack($product['url']);
    }

    public function notify_stock()
    {
        $this->ACL->turn(array('shop', 'view_catalog'));
        if (empty($_SERVER['REQUEST_METHOD']) || strtoupper($_SERVER['REQUEST_METHOD']) !== 'POST') {
            redirect('/shop/');
        }

        $productId = isset($_POST['product_id']) ? (int)$_POST['product_id'] : 0;
        $product = $productId > 0 ? ShopHelper::getProduct($productId, true) : false;
        if (!$product) {
            ShopHelper::flash('error', 'Товар не найден.');
            redirect('/shop/');
        }
        if (!ShopHelper::csrfCheck(isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '')) {
            ShopHelper::flash('error', 'Сессия истекла. Попробуйте ещё раз.');
            $this->redirectBack($product['url']);
        }

        $email = trim((string)(isset($_POST['email']) ? $_POST['email'] : ''));
        if ($email === '') $email = ShopHelper::currentUser()['email'];

        if (ShopHelper::addStockSubscription($productId, $email)) {
            ShopHelper::flash('success', 'Сообщим, когда товар снова появится в наличии.');
        } else {
            ShopHelper::flash('error', 'Укажите корректный e-mail.');
        }
        $this->redirectBack($product['url']);
    }

    private function handleFavoriteAction($add)
    {
        if (empty($_SERVER['REQUEST_METHOD']) || strtoupper($_SERVER['REQUEST_METHOD']) !== 'POST') {
            redirect('/shop/');
        }

        $userId = $this->requireFavoriteUser();
        if (!ShopHelper::csrfCheck(isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '')) {
            ShopHelper::flash('error', 'Сессия истекла. Попробуйте ещё раз.');
            $this->redirectBack($add ? '/shop/' : '/shop/favorites/');
        }

        $productId = isset($_POST['product_id']) ? (int)$_POST['product_id'] : 0;
        $product = $productId > 0 ? ShopHelper::getProduct($productId, true) : false;
        if (!$product) {
            ShopHelper::flash('error', 'Товар не найден.');
            $this->redirectBack('/shop/');
        }

        if ($add) {
            ShopHelper::addFavorite($userId, $productId);
            ShopHelper::flash('success', 'Товар добавлен в избранное.');
        } else {
            ShopHelper::removeFavorite($userId, $productId);
            ShopHelper::flash('success', 'Товар удалён из избранного.');
        }

        $this->redirectBack($add ? '/shop/' : '/shop/favorites/');
    }

    private function requireFavoriteUser()
    {
        $user = ShopHelper::currentUser();
        if (empty($user['id'])) {
            ShopHelper::flash('error', 'Войдите, чтобы пользоваться избранным.');
            redirect('/users/login_form/');
        }
        return (int)$user['id'];
    }

    /* ---------------------------------------------------------------------
     * Checkout
     * ------------------------------------------------------------------ */

    public function checkout()
    {
        $this->ACL->turn(array('shop', 'place_orders'));
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
        $attempt = $_POST['csrf_token'] ?? '';
        $quote = is_string($attempt) && preg_match('/^[a-f0-9]{48}$/D', $attempt)
            ? ($_SESSION['shop_checkout'][$attempt] ?? null) : null;
        $user = ShopHelper::currentUser();
        if (!$quote || $quote['user_id'] !== (int)$user['id']) {
            ShopHelper::flash('error', 'Форма оформления устарела. Проверьте заказ и повторите попытку.');
            redirect('/shop/checkout/');
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
        if ($old['phone'] === '') {
            $errors[] = 'Укажите телефон.';
        } elseif (!ShopHelper::phoneValid($old['phone'])) {
            $errors[] = 'Телефон должен содержать от 7 до 15 цифр.';
        }

        try {
            ShopHelper::ensureOrderRequestsTable();
            ShopHelper::ensureOrderStatusHistoryTable();
            $existing = ShopHelper::orderForAttempt($attempt);
        } catch (Throwable $e) {
            error_log('Shop checkout storage unavailable: ' . $e->getMessage());
            return $this->renderCheckout($old, array('Не удалось оформить заказ. Попробуйте позже.'), $attempt);
        }
        if ($existing) $this->finishCheckout($existing, $attempt, false);

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

        $items = ShopHelper::cartItems($currency['code'], false);
        $deliveryPrice = $deliveryMethods[$old['delivery_method']]['price'];
        $changes = array();
        foreach ($quote['items'] as $id => $previous) {
            if (!isset($items[$id])) {
                $changes[] = 'Позиция больше недоступна: ' . $previous['title'] . '.';
            } elseif ($previous['quantity'] !== $items[$id]['quantity'] || $previous['price'] !== $items[$id]['price']) {
                $changes[] = 'Цена или количество изменились: ' . $previous['title'] . '.';
            }
        }
        foreach (array_diff_key($items, $quote['items']) as $item) {
            $changes[] = 'В корзину добавлена позиция: ' . $item['product_title'] . '.';
        }
        if ($quote['currency'] !== $currency['code']) $changes[] = 'Изменилась валюта заказа.';
        if (($quote['delivery'][$old['delivery_method']] ?? null) !== $deliveryPrice) {
            $changes[] = 'Изменилась стоимость или доступность выбранной доставки.';
        }
        if (array_column($items, 'quantity', 'variant_id') != ShopHelper::getCart()) {
            $changes[] = 'Остатки изменились. Корзина обновлена.';
        }
        if ($changes) {
            $changes[] = 'Проверьте обновлённый заказ и подтвердите его ещё раз.';
            return $this->renderCheckout($old, $changes);
        }

        $subtotal = array_sum(array_column($items, 'total'));
        $total = $subtotal + $deliveryPrice;
        $now = date('Y-m-d H:i:s');
        $DB = ShopHelper::db();
        $variants = ShopHelper::table('variants');
        $products = ShopHelper::table('products');
        $prices = ShopHelper::table('variant_prices');
        $requests = ShopHelper::table('order_requests');
        $code = ShopHelper::esc($currency['code']);
        $change = null;
        ksort($items);
        try {
            $token = bin2hex(random_bytes(20));
            $DB->query('START TRANSACTION');
            // Claim the attempt before taking stock locks. The claim rolls back with the order.
            $DB->query("INSERT INTO `{$requests}` (`attempt`) VALUES ('{$attempt}')");
            foreach ($items as $item) {
                $variantId = (int)$item['variant_id'];
                $qty = (int)$item['quantity'];
                $productId = (int)$item['product_id'];
                $rows = $DB->query("SELECT v.`stock`, v.`active` AS variant_active, p.`active` AS product_active, vp.`price`
                    FROM `{$variants}` v
                    JOIN `{$products}` p ON p.`id` = v.`product_id`
                    JOIN `{$prices}` vp ON vp.`variant_id` = v.`id` AND vp.`currency_code` = '{$code}'
                    WHERE v.`id` = {$variantId} AND p.`id` = {$productId} FOR UPDATE");
                $row = $rows[0] ?? null;
                if (!$row || !$row['variant_active'] || !$row['product_active']
                    || (int)$row['stock'] < $qty || (float)$row['price'] !== $item['price']) {
                    $change = 'Изменилась цена или доступность позиции: ' . $item['product_title'] . '. Проверьте заказ и подтвердите его ещё раз.';
                    throw new RuntimeException($change);
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

            if ($orderId < 1) throw new RuntimeException('Order ID was not generated');

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
            $DB->query("UPDATE `{$requests}` SET `order_id` = {$orderId}, `customer_mail` = 'pending', `admin_mail` = 'pending' WHERE `attempt` = '{$attempt}'");
            ShopHelper::logOrderStatus($orderId, 'new', 'Заказ оформлен на сайте.', (int)$user['id'], (string)$user['name'], $now);
            $DB->query('COMMIT');
        } catch (Throwable $e) {
            try {
                $DB->query('ROLLBACK');
                $existing = ShopHelper::orderForAttempt($attempt);
            } catch (Throwable $lookupError) {
                error_log('Shop checkout recovery failed: ' . $lookupError->getMessage());
            }
            if ($existing) $this->finishCheckout($existing, $attempt, false);
            if ($change === null) error_log('Shop checkout failed: ' . $e->getMessage());
            return $this->renderCheckout($old, array($change ?? 'Не удалось оформить заказ. Попробуйте ещё раз.'),
                $change === null ? $attempt : null);
        }

        $this->finishCheckout(array('id' => $orderId, 'access_token' => $token), $attempt, true);
    }

    private function finishCheckout($order, $attempt, $created)
    {
        $quote = $_SESSION['shop_checkout'][$attempt];
        if (empty($quote['completed']) && array_map(function ($item) {
            return $item['quantity'];
        }, $quote['items']) == ShopHelper::getCart()) {
            ShopHelper::saveCart(array());
        }
        $_SESSION['shop_checkout'][$attempt]['completed'] = true;
        $_SESSION['shop_last_order'] = array('id' => (int)$order['id'], 'token' => $order['access_token']);
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

        if ($created) {
            try {
                $order = ShopHelper::getOrder($order['id']);
                if ($order) ShopHelper::sendOrderEmails($order, ShopHelper::getOrderItems($order['id'], $order['currency_code']));
            } catch (Throwable $e) {
                error_log('Shop order notification failed: ' . $e->getMessage());
            }
        }
        redirect('/shop/success/');
    }

    private function renderCheckout($old, $errors, $attempt = null)
    {
        $currency = ShopHelper::activeCurrency();
        header('Cache-Control: private, no-store');
        $cart = ShopHelper::getCart();
        $items = ShopHelper::cartItems($currency['code']);
        if (empty($items)) {
            ShopHelper::flash('error', $cart || $errors ? 'Товары в корзине больше недоступны. Проверьте наличие в каталоге.' : 'Корзина пуста.');
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

        if (ShopHelper::getCart() != $cart) {
            $errors[] = 'Остатки изменились. Проверьте количество и состав обновлённой корзины.';
        }
        $quoteItems = array();
        foreach ($items as $id => $item) {
            $quoteItems[$id] = array(
                'title' => $item['product_title'],
                'price' => $item['price'],
                'quantity' => $item['quantity'],
            );
        }
        $quote = array(
            'user_id' => (int)ShopHelper::currentUser()['id'],
            'currency' => $currency['code'],
            'items' => $quoteItems,
            'delivery' => array_column($deliveryMethods, 'price', 'code'),
        );
        if ($attempt === null || ($_SESSION['shop_checkout'][$attempt] ?? null) !== $quote) {
            $attempt = bin2hex(random_bytes(24));
            $_SESSION['shop_checkout'][$attempt] = $quote;
            $_SESSION['shop_checkout'] = array_slice($_SESSION['shop_checkout'], -20, null, true);
        }

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
            'csrf_token' => $attempt,
            'cart_url' => get_url('/shop/cart/'),
            'shop_url' => get_url('/shop/'),
            'breadcrumbs' => $this->buildSimpleBreadcrumbs('Оформление заказа'),
        ));

        return $this->_view($source);
    }

    public function success()
    {
        $this->ACL->turn(array('shop', 'view_catalog'));
        header('Cache-Control: private, no-store');
        header('Referrer-Policy: no-referrer');
        if (empty($_SESSION['shop_last_order']['id'])) redirect('/shop/');
        $orderId = (int)$_SESSION['shop_last_order']['id'];
        $order = ShopHelper::getOrder($orderId);
        if (!$order || !hash_equals($order['access_token'], (string)($_SESSION['shop_last_order']['token'] ?? ''))) redirect('/shop/');
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
        header('Cache-Control: private, no-store');
        header('Referrer-Policy: no-referrer');
        $order = $id > 0 ? ShopHelper::getOrder($id) : false;
        if (!$order) $this->Parser->showHttpError();

        $user = ShopHelper::currentUser();
        $isOwner = !empty($user['id']) && (int)$order['user_id'] === (int)$user['id'];
        $token = (string)$token;
        $isToken = ShopHelper::orderTokenValid($order, $token);
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

    private function redirectBack($fallback = '/shop/cart/')
    {
        $return = isset($_POST['return']) ? (string)$_POST['return'] : '';
        if ($return === '' || strpos($return, '/shop/') !== 0
            || strpos($return, '//') !== false
            || strpos($return, '\\') !== false
            || strpos($return, "\r") !== false
            || strpos($return, "\n") !== false) {
            $return = $fallback;
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
        $trail = array(
            array('title' => 'Главная', 'url' => get_url('/')),
            array('title' => 'Магазин', 'url' => get_url('/shop/')),
        );
        if ($category) {
            foreach (ShopHelper::categoryBreadcrumbs($category['id']) as $crumb) {
                $trail[] = array('title' => $crumb['title'], 'url' => $crumb['url']);
            }
        }
        return $this->renderBreadcrumbs($trail);
    }

    private function buildLandingBreadcrumbs($landing)
    {
        $trail = array(
            array('title' => 'Главная', 'url' => get_url('/')),
            array('title' => 'Магазин', 'url' => get_url('/shop/')),
        );
        foreach ($landing['categories'] as $category) {
            $trail[] = array('title' => $category['title'], 'url' => $category['url']);
        }
        $trail[] = array('title' => $landing['title'], 'url' => '');
        return $this->renderBreadcrumbs($trail);
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
        $trail = array(
            array('title' => 'Главная', 'url' => get_url('/')),
            array('title' => 'Магазин', 'url' => get_url('/shop/')),
        );
        if (!empty($product['category_id'])) {
            foreach (ShopHelper::categoryBreadcrumbs($product['category_id']) as $crumb) {
                $trail[] = array('title' => $crumb['title'], 'url' => $crumb['url']);
            }
        }
        $trail[] = array('title' => $product['title'], 'url' => '');
        return $this->renderBreadcrumbs($trail);
    }

    private function buildSimpleBreadcrumbs($title)
    {
        return $this->renderBreadcrumbs(array(
            array('title' => 'Главная', 'url' => get_url('/')),
            array('title' => 'Магазин', 'url' => get_url('/shop/')),
            array('title' => $title, 'url' => ''),
        ));
    }

    /* ---------------------------------------------------------------------
     * SEO helpers
     * ------------------------------------------------------------------ */

    private function renderBreadcrumbs(array $trail)
    {
        $this->breadcrumbData = $trail;
        $parts = array();
        foreach ($trail as $crumb) {
            $title = h($crumb['title']);
            $parts[] = empty($crumb['url'])
                ? '<span>' . $title . '</span>'
                : '<a href="' . h($crumb['url']) . '">' . $title . '</a>';
        }
        return '<div class="shop-breadcrumbs">' . implode(' / ', $parts) . '</div>';
    }

    private function pageUrl($baseUrl, $page)
    {
        $baseUrl = (string)$baseUrl;
        $delimiter = strpos($baseUrl, '?') === false ? '?' : '&';
        return $this->absoluteUrl(get_url($baseUrl . $delimiter . 'page=' . (int)$page));
    }

    private function absoluteUrl($url)
    {
        $url = trim((string)$url);
        if ($url === '') return '';
        if (preg_match('#^https?://#i', $url)) return $url;

        $origin = $this->getCanonicalOrigin();
        if ($origin === '') return '';

        if ($url[0] !== '/') $url = '/' . $url;
        return $origin . $url;
    }

    private function buildProductStructuredData($product, $variants, $currency, $gallery)
    {
        $url = $this->absoluteUrl($product['url']);
        if ($url === '') return '';

        $images = array();
        if ($this->ogImageUrl !== '') $images[] = $this->ogImageUrl;
        foreach ($gallery as $image) {
            $full = $this->absoluteUrl($image['full_url']);
            if ($full !== '') $images[] = $full;
        }
        $images = array_values(array_unique($images));

        $description = trim(ShopHelper::descriptionText(
            !empty($product['meta_description'])
                ? $product['meta_description']
                : (!empty($product['short_description']) ? $product['short_description'] : $product['description'])
        ));

        $sku = '';
        $prices = array();
        $available = false;
        foreach ($variants as $variant) {
            if ($sku === '' && !empty($variant['sku'])) $sku = (string)$variant['sku'];
            if (!empty($variant['available'])) $available = true;
            if ((float)$variant['price'] > 0) $prices[] = (float)$variant['price'];
        }

        $data = array(
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            'name' => (string)$product['title'],
            'url' => $url,
        );
        if ($description !== '') $data['description'] = $description;
        if ($sku !== '') $data['sku'] = $sku;
        if ($images) $data['image'] = $images;

        if ($prices) {
            $data['offers'] = array(
                '@type' => 'AggregateOffer',
                'url' => $url,
                'priceCurrency' => (string)$currency['code'],
                'lowPrice' => number_format(min($prices), 2, '.', ''),
                'highPrice' => number_format(max($prices), 2, '.', ''),
                'offerCount' => count($prices),
                'availability' => $available
                    ? 'https://schema.org/InStock'
                    : 'https://schema.org/OutOfStock',
            );
        }

        if (!empty($this->productRating['count'])) {
            $data['aggregateRating'] = array(
                '@type' => 'AggregateRating',
                'ratingValue' => number_format((float)$this->productRating['avg'], 1, '.', ''),
                'reviewCount' => (int)$this->productRating['count'],
                'bestRating' => 5,
                'worstRating' => 1,
            );
        }

        return ShopHelper::jsonLdScript($data);
    }

    /**
     * Attach review stars to catalogue cards in one extra query.
     */
    private function attachRatings(array &$items)
    {
        if (!$items) return;

        $ratings = ShopHelper::productRatings(array_column($items, 'id'));
        foreach ($items as &$item) {
            $id = (int)$item['id'];
            $rating = isset($ratings[$id]) ? $ratings[$id] : array('avg' => 0.0, 'count' => 0);
            $item['rating_avg'] = $rating['avg'];
            $item['rating_avg_formatted'] = $rating['count'] > 0
                ? number_format($rating['avg'], 1, ',', '') : '';
            $item['rating_count'] = $rating['count'];
            $item['rating_stars'] = $rating['count'] > 0
                ? str_repeat('★', max(1, (int)round($rating['avg']))) : '';
        }
        unset($item);
    }

    private function buildBreadcrumbStructuredData()
    {
        $items = array();
        foreach ($this->breadcrumbData as $position => $crumb) {
            $item = array(
                '@type' => 'ListItem',
                'position' => $position + 1,
                'name' => (string)$crumb['title'],
            );
            $url = $this->absoluteUrl(isset($crumb['url']) ? $crumb['url'] : '');
            if ($url !== '') $item['item'] = $url;
            $items[] = $item;
        }
        if (count($items) < 2) return '';

        return ShopHelper::jsonLdScript(array(
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => $items,
        ));
    }
}

