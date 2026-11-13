<?php
/**
 * Static checks for the admin-side shop features: product CSV export, admin
 * order creation, review replies, catalog filters and editable letters.
 *
 *     php tests/shop-admin-features-test.php
 */

$root = dirname(__DIR__);
$assertions = 0;
$failures = array();

function atm_test_assert($condition, $message)
{
    global $assertions, $failures;
    $assertions++;
    if (!$condition) {
        $failures[] = $message;
    }
}

$library = (string)file_get_contents($root . '/modules/shop/shop.lib.php');
foreach (array(
    'function exportProductsCsv',
    'function exportVariantsCsv',
    'function defaultEmailTemplates',
    'function emailTemplates',
    'function saveEmailTemplate',
    'function resetEmailTemplate',
    'function renderEmailTemplate',
    'function replyToReview',
    'function sendReviewReplyEmail',
    'ensureReviewReplyColumns',
    'function getCatalogAttributeGroups',
    'function updateOrderItems',
    'function recordFunnelEvent',
    'function funnelReport',
) as $needle) {
    atm_test_assert(strpos($library, $needle) !== false,
        'modules/shop/shop.lib.php is missing ' . $needle . '.');
}
foreach (array("'order'", "'cart_reminder'", "'stock_notification'", "'low_stock'", "'review_reply'") as $needle) {
    atm_test_assert(strpos($library, $needle) !== false,
        'No built-in email template ' . $needle . '.');
}

$admin = (string)file_get_contents($root . '/admin/shop.php');
foreach (array(
    'exportProductsCsv',
    'exportVariantsCsv',
    'order_create',
    'Создать заказ',
    'shop_admin_order_form',
    'review_reply',
    'shop_admin_emails',
    'email_template_save',
    'email_template_reset',
    'product_copy',
    'shop_admin_copy_upload',
    'order_items_save',
    'funnelReport',
    'Экспорт вариантов',
) as $needle) {
    atm_test_assert(strpos($admin, $needle) !== false,
        'admin/shop.php is missing ' . $needle . '.');
}

$module = (string)file_get_contents($root . '/modules/shop/index.php');
foreach (array("'price_min'", "'price_max'", "'in_stock'", 'filter_price_min', 'attribute_filter_groups', 'public function yml', 'recordFunnelEvent') as $needle) {
    atm_test_assert(strpos($module, $needle) !== false,
        'modules/shop/index.php is missing ' . $needle . '.');
}

$html = (string)file_get_contents($root . '/modules/shop/install_template/html/list.html');
foreach (array('name="price_min"', 'name="price_max"', 'name="in_stock"', 'filter_active', 'name="attr[') as $needle) {
    atm_test_assert(strpos($html, $needle) !== false,
        'list.html is missing ' . $needle . '.');
}

$product = (string)file_get_contents($root . '/modules/shop/install_template/html/product.html');
atm_test_assert(strpos($product, 'review.reply_html') !== false,
    'product.html does not show the shop reply to a review.');

$schema = (string)file_get_contents($root . '/modules/shop/install_db.php');
atm_test_assert(strpos($schema, 'shop_email_templates') !== false,
    'install_db.php does not create the email templates table.');
atm_test_assert(strpos($schema, '`reply` TEXT') !== false,
    'install_db.php does not create the review reply column.');
atm_test_assert(strpos($schema, 'shop_funnel_events') !== false,
    'install_db.php does not create the funnel events table.');

if ($failures) {
    echo "FAIL\n- " . implode("\n- ", $failures) . "\n";
    echo $assertions . " assertions\n";
    exit(1);
}

echo "OK\n" . $assertions . " assertions\n";
