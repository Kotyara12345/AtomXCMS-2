<?php
// All writes are confined to randomly prefixed fixtures, removed in finally.
$dsn=getenv('ATM_TEST_DSN');
if(!$dsn){if(getenv('ATM_TEST_REQUIRED')==='1')exit(1);echo "SKIP: ATM_TEST_DSN is not set\n";exit;}
try{$pdo=new PDO($dsn,getenv('ATM_TEST_USER'),getenv('ATM_TEST_PASS'),array(PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION));}
catch(Throwable $e){if(getenv('ATM_TEST_REQUIRED')==='1')throw $e;echo "SKIP: test database unavailable\n";exit;}
class Register extends ArrayObject {private static $instance;public static function getInstance(){return self::$instance ?: (self::$instance=new self);}}
class Config {public static function read($key,$module=null){return $key==='active'?1:null;}}
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
        'shop_orders'=>'id int PRIMARY KEY, status varchar(32), stock_released int, updated_at datetime, created_at datetime',
        'shop_variants'=>'id int AUTO_INCREMENT PRIMARY KEY, stock int, product_id int, title varchar(255), sku varchar(64), image varchar(255), active int, position int, created_at datetime, updated_at datetime',
        'shop_order_items'=>'id int PRIMARY KEY, order_id int, variant_id int, quantity int, price decimal(12,2), total decimal(12,2)',
        'shop_order_status_history'=>'id int AUTO_INCREMENT PRIMARY KEY, order_id int, status varchar(32), comment varchar(255), user_id int, user_name varchar(255), created_at datetime',
        'shop_order_requests'=>'attempt char(48) DEFAULT NULL, order_id int DEFAULT NULL, customer_mail varchar(12) NOT NULL DEFAULT \'unknown\', admin_mail varchar(12) NOT NULL DEFAULT \'unknown\', created_at datetime DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY attempt (attempt), UNIQUE KEY order_id (order_id)',
        'shop_reviews'=>'id int AUTO_INCREMENT PRIMARY KEY, product_id int, user_id int, name varchar(255), email varchar(255), rating int, text text, active int, created_at datetime',
        'shop_stock_subscriptions'=>'id int AUTO_INCREMENT PRIMARY KEY, product_id int, email varchar(255), notified int, created_at datetime, UNIQUE KEY product_email (product_id, email)',
        'shop_carts'=>'user_id int PRIMARY KEY, data mediumtext, updated_at datetime, reminded_at datetime',
        'shop_currencies'=>'id int AUTO_INCREMENT PRIMARY KEY, code varchar(3), title varchar(64), symbol varchar(16), position int, enabled int, UNIQUE KEY code (code)',
        'shop_products'=>'id int AUTO_INCREMENT PRIMARY KEY, category_id int, title varchar(255), clean_url_title varchar(255), short_description text, description mediumtext, image varchar(255), active int, featured int, position int, views int, meta_title varchar(255), meta_description text, meta_keywords text, created_at datetime, updated_at datetime, UNIQUE KEY clean_url_title (clean_url_title)',
        'shop_variant_prices'=>'variant_id int, currency_code varchar(3), price decimal(12,2), PRIMARY KEY (variant_id, currency_code)',
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

    $db->query("INSERT INTO `{$prefix}shop_orders` VALUES(1,'new',0,NOW(),NOW())");
    $db->query("INSERT INTO `{$prefix}shop_variants` (`id`,`stock`) VALUES(1,10)");
    $db->query("INSERT INTO `{$prefix}shop_order_items` VALUES(1,1,1,2,5,10)");
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

    $db->query("INSERT INTO `{$prefix}shop_orders` VALUES(2,'new',0,NOW(),DATE_SUB(NOW(),INTERVAL 4 DAY))");
    $db->query("INSERT INTO `{$prefix}shop_variants` (`id`,`stock`) VALUES(2,5)");
    $db->query("INSERT INTO `{$prefix}shop_order_items` VALUES(2,2,2,2,5,10)");
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
    $db->query("INSERT INTO `{$prefix}shop_variants` (`id`,`stock`,`product_id`,`title`,`sku`,`active`,`position`,`created_at`,`updated_at`) VALUES(3,10,1,'Стандарт','SKU-1',1,0,NOW(),NOW())");
    $db->query("INSERT INTO `{$prefix}shop_variant_prices` VALUES(3,'RUB',500.00)");
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

    echo "CMS visibility and concurrent order checks passed.\n";
}finally{
    if($pdo->inTransaction())$pdo->rollBack();
    foreach($children as$child){foreach($child[1]as$pipe)if(is_resource($pipe))fclose($pipe);if(is_resource($child[0])){proc_terminate($child[0]);proc_close($child[0]);}}
    foreach(array_reverse($tables)as$table)$pdo->exec("DROP TABLE IF EXISTS `$table`");
}
