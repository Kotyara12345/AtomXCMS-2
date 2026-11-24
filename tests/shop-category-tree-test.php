<?php
function h($v) { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }
require_once dirname(__DIR__) . '/modules/shop/ShopCategoryTree.class.php';
$tree = ShopCategoryTree::build(array(array('id'=>1,'parent_id'=>0,'title'=>'Root','url'=>'/r'),array('id'=>2,'parent_id'=>1,'title'=>'Child','url'=>'/c')));
if (count($tree) !== 1 || count($tree[0]['children']) !== 1 || strpos(ShopCategoryTree::html($tree, 2), 'class="active"') === false) exit(1);
if (ShopCategoryTree::descendantIds(array(array('id'=>1,'parent_id'=>0),array('id'=>2,'parent_id'=>1),array('id'=>3,'parent_id'=>2)), 1) !== array(1, 2, 3)) exit(1);
$crumbs = ShopCategoryTree::breadcrumbs(array(array('id'=>1,'parent_id'=>0),array('id'=>2,'parent_id'=>1)), 2);
if (count($crumbs) !== 2 || (int)$crumbs[0]['id'] !== 1) exit(1);
echo "OK\n";
