<?php
// All writes are confined to randomly prefixed fixtures, removed in finally.
$dsn=getenv('ATM_TEST_DSN');
if(!$dsn){if(getenv('ATM_TEST_REQUIRED')==='1')exit(1);echo "SKIP: ATM_TEST_DSN is not set\n";exit;}
try{$pdo=new PDO($dsn,getenv('ATM_TEST_USER'),getenv('ATM_TEST_PASS'),array(PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION));}
catch(Throwable $e){if(getenv('ATM_TEST_REQUIRED')==='1')throw $e;echo "SKIP: test database unavailable\n";exit;}
class Register extends ArrayObject {private static $instance;public static function getInstance(){return self::$instance ?: (self::$instance=new self);}}
class Config {public static function read($key,$module=null){return $key==='active'?1:null;}}
function h($value){return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');}
class CmsFixtureDB {
    public $pdo;public $prefix;
    public function __construct($pdo,$prefix){$this->pdo=$pdo;$this->prefix=$prefix;}
    public function getFullTableName($name){return $this->prefix.$name;}
    public function escape($value){return substr($this->pdo->quote((string)$value),1,-1);}
    public function query($sql){$s=$this->pdo->query($sql);return $s->columnCount()?$s->fetchAll(PDO::FETCH_ASSOC):array();}
    public function save($table,$values,$params=array()){
        $table=$this->getFullTableName($table);$values=(array)$values;
        if(!empty($values['id'])){
            $params=array('id'=>(int)$values['id']);unset($values['id']);
        }
        if(!empty($params)){
            $sets=array();foreach($values as$key=>$value)$sets[]="`{$key}`=".$this->pdo->quote((string)$value);
            $where=array();foreach($params as$key=>$value)$where[]="`{$key}`=".$this->pdo->quote((string)$value);
            $this->pdo->exec("UPDATE `{$table}` SET ".implode(',',$sets)." WHERE ".implode(' AND ',$where));
            return !empty($params['id']) ? (int)$params['id'] : 0;
        }
        $columns=array_keys($values);
        $marks=implode(',',array_fill(0,count($columns),'?'));
        $stmt=$this->pdo->prepare("INSERT INTO `{$table}` (`".implode('`,`',$columns)."`) VALUES ({$marks})");
        $stmt->execute(array_values($values));
        return (int)$this->pdo->lastInsertId();
    }
    public function delete($table,$params=array()){
        $table=$this->getFullTableName($table);
        $where=array();foreach($params as$key=>$value)$where[]="`{$key}`=".$this->pdo->quote((string)$value);
        $this->pdo->exec("DELETE FROM `{$table}`".($where?' WHERE '.implode(' AND ',$where):''));
        return true;
    }
}
function cms_check($ok,$message){if(!$ok)throw new RuntimeException($message);}
if(!function_exists('get_url')){function get_url($url,$notRoot=false,$useLang=true){return '/'.ltrim((string)$url,'/');}}
$root=dirname(__DIR__);require $root.'/sys/inc/acl.class.php';require $root.'/sys/fnc/search.lib.php';require $root.'/modules/shop/shop.lib.php';
$prefix='cmsfix_'.bin2hex(random_bytes(6)).'_';$db=new CmsFixtureDB($pdo,$prefix);$tables=array();$children=array();
Register::getInstance()['DB']=$db;
$acl=(new ReflectionClass('ACL'))->newInstanceWithoutConstructor();
$rules=array();foreach(array('news.view_materials','forum.view_themes','forum.view_forums','forum.view_forums_list') as $key)$rules[$key]=array('groups'=>array(0,1),'users'=>array());
foreach(array('rules'=>$rules,'forumsModerators'=>array())as$key=>$value){$p=new ReflectionProperty('ACL',$key);$p->setAccessible(true);$p->setValue($acl,$value);}
Register::getInstance()['ACL']=$acl;$_SESSION=array();
try{
    $schemas=array(
        'news'=>'id int PRIMARY KEY, available int, premoder varchar(32), category_id int',
        'news_categories'=>'id int PRIMARY KEY, no_access varchar(255)',
        'themes'=>'id int PRIMARY KEY, id_forum int, group_access varchar(255)',
        'posts'=>'id int PRIMARY KEY, id_theme int',
        'forums'=>'id int PRIMARY KEY, parent_forum_id int, lock_posts int, lock_passwd varchar(64)',
        'shop_orders'=>'id int PRIMARY KEY, status varchar(32), stock_released int, updated_at datetime, created_at datetime, subtotal decimal(12,2) NOT NULL DEFAULT 0, discount decimal(12,2) NOT NULL DEFAULT 0, promocode varchar(64) NOT NULL DEFAULT \'\', delivery_price decimal(12,2) NOT NULL DEFAULT 0, total decimal(12,2) NOT NULL DEFAULT 0, currency_code varchar(3) NOT NULL DEFAULT \'RUB\'',
        'shop_variants'=>'id int AUTO_INCREMENT PRIMARY KEY, stock int, product_id int, title varchar(255), sku varchar(64), image varchar(255), active int, position int, created_at datetime, updated_at datetime',
        'shop_order_items'=>'id int AUTO_INCREMENT PRIMARY KEY, order_id int, variant_id int, quantity int, price decimal(12,2), total decimal(12,2), product_id int NOT NULL DEFAULT 0, product_title varchar(255) NOT NULL DEFAULT \'\', variant_title varchar(255) NOT NULL DEFAULT \'\', sku varchar(64) NOT NULL DEFAULT \'\'',
        'shop_order_status_history'=>'id int AUTO_INCREMENT PRIMARY KEY, order_id int, status varchar(32), comment varchar(255), user_id int, user_name varchar(255), created_at datetime',
        'shop_order_requests'=>'attempt char(48) DEFAULT NULL, order_id int DEFAULT NULL, customer_mail varchar(12) NOT NULL DEFAULT \'unknown\', admin_mail varchar(12) NOT NULL DEFAULT \'unknown\', created_at datetime DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY attempt (attempt), UNIQUE KEY order_id (order_id)',
        'shop_reviews'=>'id int AUTO_INCREMENT PRIMARY KEY, product_id int, user_id int, name varchar(255), email varchar(255), rating int, text text, active int, created_at datetime',
        'shop_email_templates'=>'code varchar(64) PRIMARY KEY, subject varchar(255), body mediumtext, updated_at datetime',
        'shop_stock_subscriptions'=>'id int AUTO_INCREMENT PRIMARY KEY, product_id int, email varchar(255), notified int, created_at datetime, UNIQUE KEY product_email (product_id, email)',
        'shop_carts'=>'user_id int PRIMARY KEY, data mediumtext, updated_at datetime, reminded_at datetime',
        'shop_currencies'=>'id int AUTO_INCREMENT PRIMARY KEY, code varchar(3), title varchar(64), symbol varchar(16), position int, enabled int, UNIQUE KEY code (code)',
        'shop_products'=>'id int AUTO_INCREMENT PRIMARY KEY, category_id int, title varchar(255), clean_url_title varchar(255), short_description text, description mediumtext, image varchar(255), active int, featured int, position int, views int, meta_title varchar(255), meta_description text, meta_keywords text, created_at datetime, updated_at datetime, UNIQUE KEY clean_url_title (clean_url_title)',
        'shop_categories'=>'id int AUTO_INCREMENT PRIMARY KEY, parent_id int NOT NULL DEFAULT 0, title varchar(255), clean_url_title varchar(255), description text, image varchar(255), active int NOT NULL DEFAULT 1, position int NOT NULL DEFAULT 0, created_at datetime, updated_at datetime',
        'shop_product_images'=>'id int AUTO_INCREMENT PRIMARY KEY, product_id int, image varchar(255), position int, created_at datetime',
        'shop_attributes'=>'id int PRIMARY KEY, title varchar(255), position int, active int',
        'shop_attribute_values'=>'id int PRIMARY KEY, attribute_id int, title varchar(255), position int, active int',
        'shop_variant_values'=>'variant_id int, attribute_value_id int, PRIMARY KEY (variant_id, attribute_value_id)',
        'shop_variant_prices'=>'variant_id int, currency_code varchar(3), price decimal(12,2), compare_price decimal(12,2) NOT NULL DEFAULT 0, PRIMARY KEY (variant_id, currency_code)',
        'shop_related_products'=>'product_id int, related_product_id int, position int NOT NULL DEFAULT 0, PRIMARY KEY (product_id, related_product_id)',
        'shop_product_attribute_values'=>'product_id int, attribute_value_id int, PRIMARY KEY (product_id, attribute_value_id)',
        'shop_funnel_events'=>'id int AUTO_INCREMENT PRIMARY KEY, event varchar(32), session_key varchar(64), user_id int, product_id int, order_id int, created_at datetime, KEY event_created (event, created_at)',
        'shop_promocodes'=>'id int AUTO_INCREMENT PRIMARY KEY, code varchar(64) NOT NULL DEFAULT \'\', discount_type varchar(16) NOT NULL DEFAULT \'percent\', value decimal(12,2) NOT NULL DEFAULT 0, currency_code varchar(3) NOT NULL DEFAULT \'RUB\', min_total decimal(12,2) NOT NULL DEFAULT 0, max_uses int unsigned NOT NULL DEFAULT 0, used_count int unsigned NOT NULL DEFAULT 0, starts_at datetime DEFAULT NULL, ends_at datetime DEFAULT NULL, active int NOT NULL DEFAULT 1, created_at datetime DEFAULT NULL, updated_at datetime DEFAULT NULL, UNIQUE KEY code (code)',
        'users'=>'id int PRIMARY KEY, name varchar(255), first_name varchar(255), email varchar(255), passw varchar(255), status int'
    );
    foreach($schemas as$name=>$schema){$table=$db->getFullTableName($name);$db->query("CREATE TABLE `$table` ($schema) ENGINE=InnoDB");$tables[]=$table;}
    $db->query("INSERT INTO `{$prefix}shop_currencies` VALUES(1,'RUB','Рубль','RUB',10,1)");
    $db->query("INSERT INTO `{$prefix}news_categories` VALUES(1,'')");
    $db->query("INSERT INTO `{$prefix}news` VALUES(1,1,'confirmed',1)");
    $result=array('entity_table'=>'news','entity_id'=>1);
    cms_check(atm_search_result_visible($result),'Published news invisible');
    $db->query("UPDATE `{$prefix}news` SET available=0");cms_check(!atm_search_result_visible($result),'Hidden news leaked');
    $db->query("UPDATE `{$prefix}news` SET available=1,premoder='nochecked'");cms_check(!atm_search_result_visible($result),'Unmoderated news leaked');
    $db->query("UPDATE `{$prefix}news` SET premoder='confirmed'");$db->query("UPDATE `{$prefix}news_categories` SET no_access='0'");
    cms_check(!atm_search_result_visible($result),'Restricted category leaked to guest');
    $_SESSION['user']=array('status'=>1);cms_check(atm_search_result_visible($result),'Allowed category invisible');
    $db->query("INSERT INTO `{$prefix}themes` VALUES(1,1,'')");$db->query("INSERT INTO `{$prefix}forums` VALUES(1,0,0,'secret')");
    $db->query("INSERT INTO `{$prefix}posts` VALUES(1,1)");
    $result=array('entity_table'=>'posts','entity_id'=>1,'source_id'=>1);
    cms_check(!atm_search_result_visible($result),'Password forum leaked');
    $_SESSION['access_forum_1']=true;cms_check(atm_search_result_visible($result),'Unlocked forum invisible');
    $db->query("UPDATE `{$prefix}themes` SET group_access='1'");cms_check(!atm_search_result_visible($result),'Restricted topic leaked');
    $db->query("UPDATE `{$prefix}themes` SET group_access=''");$db->query("DELETE FROM `{$prefix}posts` WHERE id=1");cms_check(!atm_search_result_visible($result),'Deleted post remained visible');

    $db->query("INSERT INTO `{$prefix}shop_orders` (`id`,`status`,`stock_released`,`created_at`,`updated_at`) VALUES(1,'new',0,NOW(),NOW())");
    $db->query("INSERT INTO `{$prefix}shop_variants` (`id`,`stock`) VALUES(1,10)");
    $db->query("INSERT INTO `{$prefix}shop_order_items` (`id`,`order_id`,`variant_id`,`quantity`,`price`,`total`) VALUES(1,1,1,2,5,10)");
    $pdo->beginTransaction();$pdo->query("SELECT * FROM `{$prefix}shop_orders` WHERE id=1 FOR UPDATE");
    for($i=0;$i<2;$i++){
        $process=proc_open(array(PHP_BINARY,$root.'/tests/shop-stock-worker.php',$prefix),array(0=>array('pipe','r'),1=>array('pipe','w'),2=>array('pipe','w')),$pipes);
        if(!is_resource($process))throw new RuntimeException('Cannot start concurrent stock test');
        fclose($pipes[0]);stream_set_timeout($pipes[1],15);$children[]=array($process,$pipes);
        cms_check(trim((string)fgets($pipes[1]))==='ready','Stock worker did not connect');
    }
    $pdo->commit();
    foreach($children as$child){$out=stream_get_contents($child[1][1]);$error=stream_get_contents($child[1][2]);fclose($child[1][1]);fclose($child[1][2]);cms_check(proc_close($child[0])===0,'Concurrent cancellation failed: '.$error);}
    $children=array();
    cms_check((int)$pdo->query("SELECT stock FROM `{$prefix}shop_variants` WHERE id=1")->fetchColumn()===12,'Stock was released more than once');
    cms_check(!ShopHelper::setOrderStatus(1,'new'),'Cancelled order reopened without a reservation');
    $history=$pdo->query("SELECT status FROM `{$prefix}shop_order_status_history` WHERE order_id=1")->fetchAll(PDO::FETCH_COLUMN);
    cms_check(in_array('cancelled',$history,true),'Cancellation was not recorded in the status history');

    $db->query("INSERT INTO `{$prefix}shop_orders` (`id`,`status`,`stock_released`,`created_at`,`updated_at`) VALUES(2,'new',0,DATE_SUB(NOW(),INTERVAL 4 DAY),NOW())");
    $db->query("INSERT INTO `{$prefix}shop_variants` (`id`,`stock`) VALUES(2,5)");
    $db->query("INSERT INTO `{$prefix}shop_order_items` (`id`,`order_id`,`variant_id`,`quantity`,`price`,`total`) VALUES(2,2,2,2,5,10)");
    cms_check(ShopHelper::expireUnpaidOrders()===true,'Unpaid order expiry run failed');
    cms_check($pdo->query("SELECT status FROM `{$prefix}shop_orders` WHERE id=2")->fetchColumn()==='cancelled','Unpaid order was not cancelled after its term');
    cms_check((int)$pdo->query("SELECT stock FROM `{$prefix}shop_variants` WHERE id=2")->fetchColumn()===7,'Expired order stock was not released');

    cms_check(ShopHelper::addReview(array('product_id'=>1,'name'=>'Иван','email'=>'ivan@example.com','rating'=>4,'text'=>'Хороший товар'))===true,'Review was not saved');
    $reviewId=(int)$pdo->query("SELECT id FROM `{$prefix}shop_reviews` ORDER BY id DESC LIMIT 1")->fetchColumn();
    cms_check($reviewId>0,'Review id was not generated');
    cms_check(ShopHelper::productRating(1)['count']===0,'Unapproved review was counted');
    cms_check(ShopHelper::setReviewActive($reviewId,true)===true,'Review was not approved');
    $rating=ShopHelper::productRating(1);
    cms_check($rating['count']===1 && abs($rating['avg']-4.0)<0.01,'Approved review rating is wrong');
    cms_check(ShopHelper::addStockSubscription(1,'wait@example.com')===true,'Stock subscription was not saved');
    cms_check(ShopHelper::addStockSubscription(1,'wait@example.com')===true,'Duplicate stock subscription failed');
    cms_check((int)$pdo->query("SELECT COUNT(*) FROM `{$prefix}shop_stock_subscriptions`")->fetchColumn()===1,'Duplicate stock subscription was stored');

    cms_check(ShopHelper::setOrderTracking(1,'TRACK-1','https://track.example.com/1')===true,'Tracking data was not saved');
    $tracked=ShopHelper::getOrder(1);
    cms_check($tracked['tracking_number']==='TRACK-1' && $tracked['tracking_url']==='https://track.example.com/1','Tracking data was not stored');
    cms_check(ShopHelper::setOrderTracking(1,'X','javascript:alert(1)')===false,'Unsafe tracking URL accepted');

    $_SESSION['user']=array('id'=>77);
    ShopHelper::saveCart(array(5=>2,6=>1));
    cms_check((int)$pdo->query("SELECT COUNT(*) FROM `{$prefix}shop_carts` WHERE user_id=77")->fetchColumn()===1,'Cart was not saved to the database');
    $_SESSION['shop_cart']=array();
    cms_check(ShopHelper::getCart()===array(5=>2,6=>1),'Cart was not restored from the database');
    ShopHelper::saveCart(array());
    cms_check((int)$pdo->query("SELECT COUNT(*) FROM `{$prefix}shop_carts` WHERE user_id=77")->fetchColumn()===0,'Cleared cart remained in the database');

    $db->query("INSERT INTO `{$prefix}users` VALUES(5,'Иван','Иван','ivan@example.com','',1)");
    $db->query("INSERT INTO `{$prefix}shop_products` (`id`,`category_id`,`title`,`clean_url_title`,`short_description`,`description`,`image`,`active`,`featured`,`position`,`views`,`meta_title`,`meta_description`,`meta_keywords`,`created_at`,`updated_at`) VALUES(1,0,'Кружка','kruzhka','','','',1,0,0,0,'','','',NOW(),NOW())");
    $db->query("INSERT INTO `{$prefix}shop_products` (`id`,`category_id`,`title`,`clean_url_title`,`short_description`,`description`,`image`,`active`,`featured`,`position`,`views`,`meta_title`,`meta_description`,`meta_keywords`,`created_at`,`updated_at`) VALUES(2,0,'Чайник','chaynik-related','','','',1,0,0,0,'','','',NOW(),NOW())");
    $db->query("INSERT INTO `{$prefix}shop_variants` (`id`,`stock`,`product_id`,`title`,`sku`,`active`,`position`,`created_at`,`updated_at`) VALUES(3,10,1,'Стандарт','SKU-1',1,0,NOW(),NOW())");
    $db->query("INSERT INTO `{$prefix}shop_variant_prices` VALUES(3,'RUB',500.00,0.00)");
    $db->query("INSERT INTO `{$prefix}shop_carts` (`user_id`,`data`,`updated_at`,`reminded_at`) VALUES(5,'{\"3\":2}',DATE_SUB(NOW(),INTERVAL 30 HOUR),NULL)");

    $recipients=ShopHelper::abandonedCartRecipients(24);
    cms_check(count($recipients)===1,'Abandoned cart was not selected');
    cms_check($recipients[0]['email']==='ivan@example.com','Abandoned cart e-mail is wrong');
    cms_check($recipients[0]['name']==='Иван','Abandoned cart customer name is wrong');
    cms_check(abs($recipients[0]['total']-1000.0)<0.01,'Abandoned cart total is wrong');
    cms_check(count($recipients[0]['items'])===1 && $recipients[0]['items'][0]['quantity']===2,'Abandoned cart items are wrong');
    cms_check(ShopHelper::abandonedCartRecipients(48)===array(),'Recent cart was treated as abandoned');
    $db->query("UPDATE `{$prefix}shop_carts` SET `reminded_at`=NOW() WHERE user_id=5");
    cms_check(ShopHelper::abandonedCartRecipients(24)===array(),'Reminded cart was selected again');

    $csv=tempnam(sys_get_temp_dir(),'shopcsv');
    file_put_contents($csv,"\xEF\xBB\xBFНазвание;Артикул;Цена;Остаток;Активен;Категория\nЧайник;SKU-10;750,50;4;да;0\n");
    $firstImport=ShopHelper::importProductsCsv($csv);
    file_put_contents($csv,"Название;Артикул;Цена;Остаток;Активен;Категория\nЧайник;SKU-11;900,00;7;да;0\n");
    $secondImport=ShopHelper::importProductsCsv($csv);
    unlink($csv);
    cms_check($firstImport['created']===1,'CSV import did not create a product: '.implode('; ',$firstImport['errors']));
    cms_check($secondImport['updated']===1 && $secondImport['created']===0,'CSV import did not update the product: '.implode('; ',$secondImport['errors']));
    $imported=$pdo->query("SELECT v.`sku`,v.`stock`,vp.`price`,p.`active` FROM `{$prefix}shop_products` p
        INNER JOIN `{$prefix}shop_variants` v ON v.`product_id`=p.`id`
        INNER JOIN `{$prefix}shop_variant_prices` vp ON vp.`variant_id`=v.`id` AND vp.`currency_code`='RUB'
        WHERE p.`clean_url_title`='chaynik'")->fetch(PDO::FETCH_ASSOC);
    cms_check(!empty($imported),'Imported product or variant is missing');
    cms_check($imported['sku']==='SKU-11' && (int)$imported['stock']===7 && abs((float)$imported['price']-900.0)<0.01,'Imported variant values are wrong');
    cms_check((int)$imported['active']===1,'Imported product is not active');

    $db->query("INSERT INTO `{$prefix}shop_promocodes` (`id`,`code`,`discount_type`,`value`,`currency_code`,`min_total`,`max_uses`,`used_count`,`starts_at`,`ends_at`,`active`,`created_at`,`updated_at`) VALUES
        (1,'SALE10','percent',10,'RUB',100,2,0,NULL,NULL,1,NOW(),NOW()),
        (2,'FIX500','fixed',500,'RUB',0,0,0,NULL,NULL,1,NOW(),NOW()),
        (3,'USDONLY','fixed',5,'USD',0,0,0,NULL,NULL,1,NOW(),NOW()),
        (4,'OLD','percent',10,'RUB',0,0,0,DATE_SUB(NOW(),INTERVAL 2 DAY),DATE_SUB(NOW(),INTERVAL 1 DAY),1,NOW(),NOW()),
        (5,'LIMIT','percent',10,'RUB',0,1,1,NULL,NULL,1,NOW(),NOW()),
        (6,'OFF','percent',10,'RUB',0,0,0,NULL,NULL,0,NOW(),NOW())");
    $promo=ShopHelper::promoCheck('sale10',1000,'RUB');
    cms_check($promo['valid'] && abs($promo['discount']-100.0)<0.01,'Percent promo discount is wrong');
    cms_check(!ShopHelper::promoCheck('SALE10',50,'RUB')['valid'],'Promo minimum total was ignored');
    $promo=ShopHelper::promoCheck('FIX500',800,'RUB');
    cms_check($promo['valid'] && abs($promo['discount']-500.0)<0.01,'Fixed promo discount is wrong');
    $promo=ShopHelper::promoCheck('FIX500',300,'RUB');
    cms_check($promo['valid'] && abs($promo['discount']-300.0)<0.01,'Fixed promo discount was not capped by the subtotal');
    cms_check(!ShopHelper::promoCheck('USDONLY',800,'RUB')['valid'],'Promo in another currency was accepted');
    cms_check(!ShopHelper::promoCheck('OLD',800,'RUB')['valid'],'Expired promo was accepted');
    cms_check(!ShopHelper::promoCheck('LIMIT',800,'RUB')['valid'],'Exhausted promo was accepted');
    cms_check(!ShopHelper::promoCheck('OFF',800,'RUB')['valid'],'Inactive promo was accepted');

    ShopHelper::setPromoCode('sale10');
    cms_check(ShopHelper::promoCode()==='SALE10','Promo code was not normalized in the session');
    $_SESSION['shop_cart']=array(3=>2);
    $totals=ShopHelper::cartTotals();
    cms_check(abs($totals['discount']-100.0)<0.01 && abs($totals['total']-900.0)<0.01,'Cart totals ignore the promo code');
    ShopHelper::clearPromoCode();
    cms_check(ShopHelper::promoCode()==='','Promo code was not cleared');

    $db->query("UPDATE `{$prefix}shop_variant_prices` SET `compare_price`=700.00 WHERE `variant_id`=3");
    $price=ShopHelper::productMinPriceInfo(1,'RUB');
    cms_check(abs($price['compare']-700.0)<0.01 && $price['percent']===29,'Old price info is wrong');
    $variantList=ShopHelper::getProductVariants(1,'RUB');
    cms_check(!empty($variantList[0]) && abs($variantList[0]['compare_price']-700.0)<0.01 && $variantList[0]['discount_percent']===29,
        'Variant old price is not exposed to the storefront');

    cms_check(ShopHelper::syncRelatedProducts(1,array(2,2,1,999))===true,'Related products were not saved');
    cms_check(ShopHelper::relatedProductIds(1)===array(2),'Related product links are wrong');
    $related=ShopHelper::relatedProducts(1,4);
    cms_check(count($related)===1 && (int)$related[0]['id']===2 && $related[0]['title']==='Чайник',
        'Related products are not returned in manual order');
    ShopHelper::syncRelatedProducts(1,array());
    cms_check(ShopHelper::relatedProductIds(1)===array(),'Related products were not cleared');

    $cheap=ShopHelper::getProducts(array('currency'=>'RUB','price_max'=>600));
    cms_check(count($cheap['items'])===1 && (int)$cheap['items'][0]['id']===1,'Price filter kept the wrong products');
    $expensive=ShopHelper::getProducts(array('currency'=>'RUB','price_min'=>800));
    cms_check(count($expensive['items'])===1 && $expensive['items'][0]['title']==='Чайник','Price min filter kept the wrong products');
    $inStock=ShopHelper::getProducts(array('currency'=>'RUB','in_stock'=>1));
    cms_check(count($inStock['items'])===2,'In-stock filter hid products with stock');
    $db->query("UPDATE `{$prefix}shop_variants` SET `stock`=0 WHERE `id`=3");
    $inStock=ShopHelper::getProducts(array('currency'=>'RUB','in_stock'=>1));
    cms_check(count($inStock['items'])===1 && $inStock['items'][0]['title']==='Чайник','In-stock filter kept an out-of-stock product');
    $db->query("UPDATE `{$prefix}shop_variants` SET `stock`=10 WHERE `id`=3");

    $export=ShopHelper::exportProductsCsv();
    cms_check(count($export)>=2 && $export[0]['slug']==='kruzhka' && abs((float)$export[0]['price']-500.0)<0.01,
        'Product CSV export is wrong');
    cms_check(isset($export[0]['title'],$export[1]['meta_keywords']),'Product CSV export misses import columns');

    $db->query("INSERT INTO `{$prefix}shop_attributes` VALUES(1,'Цвет',0,1)");
    $db->query("INSERT INTO `{$prefix}shop_attribute_values` VALUES(7,1,'Красный',0,1)");
    $db->query("INSERT INTO `{$prefix}shop_product_attribute_values` VALUES(1,7)");
    $groups=ShopHelper::getCatalogAttributeGroups(array());
    cms_check(isset($groups[1]) && $groups[1]['title']==='Цвет' && (int)$groups[1]['values'][0]['count']===1,
        'Catalog attribute groups are wrong');
    $byAttribute=ShopHelper::getProducts(array('currency'=>'RUB','attribute_value_ids'=>array(7)));
    cms_check(count($byAttribute['items'])===1 && (int)$byAttribute['items'][0]['id']===1,
        'Attribute filter kept the wrong products');

    $variantExport=ShopHelper::exportVariantsCsv();
    $exportedVariant=null;
    foreach($variantExport as$row){if($row['variant_id']===3 && $row['currency']==='RUB'){$exportedVariant=$row;break;}}
    cms_check($exportedVariant!==null && abs((float)$exportedVariant['price']-500.0)<0.01 && $exportedVariant['product_slug']==='kruzhka',
        'Variant CSV export is wrong');
    cms_check(array_key_exists('compare_price',$exportedVariant),'Variant CSV export misses the old price column');

    ShopHelper::recordFunnelEvent('product_view',array('product_id'=>1));
    ShopHelper::recordFunnelEvent('add_to_cart',array('product_id'=>1));
    ShopHelper::recordFunnelEvent('order_created',array('order_id'=>1));
    $funnel=ShopHelper::funnelReport(30);
    cms_check((int)$funnel['product_view']['sessions']===1 && (int)$funnel['order_created']['sessions']===1,
        'Funnel events were not counted');
    cms_check($funnel['order_created']['rate']===100.0,'Funnel conversion rate is wrong');


    cms_check(ShopHelper::saveEmailTemplate('order','Заказ {number}','<p>Номер {number}</p>')===true,'Email template was not saved');
    $tpl=ShopHelper::renderEmailTemplate('order',array('number'=>'42'));
    cms_check($tpl['subject']==='Заказ 42' && $tpl['body']==='<p>Номер 42</p>','Email template placeholders are wrong');
    cms_check(ShopHelper::resetEmailTemplate('order')===true,'Email template was not reset');
    $tpl=ShopHelper::renderEmailTemplate('order',array('number'=>'42'));
    cms_check($tpl['subject']==='Заказ №42','Email template reset kept the custom subject');

    cms_check(ShopHelper::replyToReview($reviewId,'Спасибо за отзыв!')===true,'Review reply was not saved');
    $review=ShopHelper::getProductReviews(1,true);
    cms_check(!empty($review[0]['reply_html']) && strpos($review[0]['reply_html'],'Спасибо')!==false,
        'Review reply is not exposed to the storefront');
    cms_check(ShopHelper::replyToReview($reviewId,'')===true,'Review reply was not cleared');
    $review=ShopHelper::getProductReviews(1,true);
    cms_check($review[0]['reply_html']==='','Cleared review reply is still visible');

    $db->query("UPDATE `{$prefix}shop_promocodes` SET `used_count`=1 WHERE `code`='SALE10'");
    $db->query("INSERT INTO `{$prefix}shop_orders` (`id`,`status`,`stock_released`,`created_at`,`updated_at`,`subtotal`,`discount`,`promocode`,`delivery_price`,`total`) VALUES(3,'new',0,NOW(),NOW(),1000,100,'SALE10',0,900)");
    $db->query("INSERT INTO `{$prefix}shop_order_items` (`id`,`order_id`,`variant_id`,`quantity`,`price`,`total`) VALUES(3,3,3,1,1000,1000)");
    $editError='';
    cms_check(ShopHelper::updateOrderItems(3,array(3=>2),$editError)===true,'Order items were not updated: '.$editError);
    cms_check((int)$pdo->query("SELECT stock FROM `{$prefix}shop_variants` WHERE id=3")->fetchColumn()===9,
        'Order item update did not reserve stock');
    $edited=ShopHelper::getOrder(3);
    cms_check(abs((float)$edited['subtotal']-2000.0)<0.01 && abs((float)$edited['total']-1900.0)<0.01,
        'Order totals were not recalculated');
    $editError='';
    cms_check(ShopHelper::updateOrderItems(3,array(),$editError)===false && $editError!=='',
        'Empty order composition was accepted');
    cms_check((int)$pdo->query("SELECT stock FROM `{$prefix}shop_variants` WHERE id=3")->fetchColumn()===9,
        'Rejected order edit changed the stock');
    cms_check(ShopHelper::updateOrderItems(3,array(3=>1))===true,'Order items were not restored');
    cms_check((int)$pdo->query("SELECT stock FROM `{$prefix}shop_variants` WHERE id=3")->fetchColumn()===10,
        'Restoring order quantity did not return stock');
    cms_check(ShopHelper::setOrderStatus(3,'cancelled')===true,'Promo order was not cancelled');
    cms_check((int)$pdo->query("SELECT `used_count` FROM `{$prefix}shop_promocodes` WHERE `code`='SALE10'")->fetchColumn()===0,'Cancelled order did not free the promo redemption');

    echo "CMS visibility and concurrent order checks passed.\n";
}finally{
    if($pdo->inTransaction())$pdo->rollBack();
    foreach($children as$child){foreach($child[1]as$pipe)if(is_resource($pipe))fclose($pipe);if(is_resource($child[0])){proc_terminate($child[0]);proc_close($child[0]);}}
    foreach(array_reverse($tables)as$table)$pdo->exec("DROP TABLE IF EXISTS `$table`");
}
