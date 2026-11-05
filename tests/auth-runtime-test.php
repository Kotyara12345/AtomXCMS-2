<?php
$root = dirname(__DIR__);
class Register extends ArrayObject {
    private static $instance;
    public static function getInstance() { return self::$instance ?: (self::$instance = new self); }
}
class AuthDB {
    public $updates=0;
    public $queries=array();
    public function getFullTableName($name) { return $name; }
    public function query($sql) { $this->queries[]=$sql; if(strpos($sql,'UPDATE')===0) $this->updates++; return array(); }
}
function atm_user_remember_login() { return array('id'=>7,'status'=>1,'unix_last_visit'=>100,'activation'=>0,'locked'=>0); }
function atm_user_remember_forget() { throw new RuntimeException('Valid token was discarded'); }
function check_auth($ok,$message) { if(!$ok) throw new RuntimeException($message); }
require $root.'/sys/inc/userauth.class.php';
require $root.'/sys/inc/bootstrap.class.php';
$db=new AuthDB; Register::getInstance()['DB']=$db;
$bootstrap=(new ReflectionClass('Bootstrap'))->newInstanceWithoutConstructor();
$_SESSION=array(); $_COOKIE=array('atm_user_remember'=>str_repeat('a',64));
$bootstrap->initUser();
check_auth(isset($_SESSION['user']['id']) && $_SESSION['user']['id']===7, 'Remember token did not restore user');
check_auth($db->updates===1, 'First visit was not recorded');
$bootstrap->initUser();check_auth($db->updates===1, 'Every request still writes last_visit');
$_SESSION['last_visit_write']['time']-=61;
$bootstrap->initUser();check_auth($db->updates===2, 'Visit was not refreshed after interval');
$_SESSION['user']['id']=8;
$bootstrap->initUser();check_auth($db->updates===3, 'A different account reused visit throttle');
check_auth(strpos($db->queries[0], 'WHERE last_post > FROM_UNIXTIME(100)')!==false, 'Topic lookup lost indexed date predicate');
echo "Authentication runtime checks passed.\n";
