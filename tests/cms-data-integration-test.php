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
}
function cms_check($ok,$message){if(!$ok)throw new RuntimeException($message);}
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
        'shop_orders'=>'id int PRIMARY KEY, status varchar(32), stock_released int, updated_at datetime',
        'shop_variants'=>'id int PRIMARY KEY, stock int',
        'shop_order_items'=>'id int PRIMARY KEY, order_id int, variant_id int, quantity int, price decimal(12,2), total decimal(12,2)'
    );
    foreach($schemas as$name=>$schema){$table=$db->getFullTableName($name);$db->query("CREATE TABLE `$table` ($schema) ENGINE=InnoDB");$tables[]=$table;}
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

    $db->query("INSERT INTO `{$prefix}shop_orders` VALUES(1,'new',0,NOW())");
    $db->query("INSERT INTO `{$prefix}shop_variants` VALUES(1,10)");
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
    echo "CMS visibility and concurrent order checks passed.\n";
}finally{
    if($pdo->inTransaction())$pdo->rollBack();
    foreach($children as$child){foreach($child[1]as$pipe)if(is_resource($pipe))fclose($pipe);if(is_resource($child[0])){proc_terminate($child[0]);proc_close($child[0]);}}
    foreach(array_reverse($tables)as$table)$pdo->exec("DROP TABLE IF EXISTS `$table`");
}
