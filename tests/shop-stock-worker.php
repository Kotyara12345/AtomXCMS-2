<?php
if(PHP_SAPI!=='cli')exit;
class Register extends ArrayObject {
    private static $instance;
    public static function getInstance(){return self::$instance ?: (self::$instance=new self);}
}
class StockDB {
    public $pdo;public $prefix;
    public function __construct($pdo,$prefix){$this->pdo=$pdo;$this->prefix=$prefix;}
    public function getFullTableName($name){return $this->prefix.$name;}
    public function escape($value){return substr($this->pdo->quote((string)$value),1,-1);}
    public function query($sql){$s=$this->pdo->query($sql);return $s->columnCount()?$s->fetchAll(PDO::FETCH_ASSOC):array();}
}
try {
    $pdo=new PDO(getenv('ATM_TEST_DSN'),getenv('ATM_TEST_USER'),getenv('ATM_TEST_PASS'),array(PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION));
    Register::getInstance()['DB']=new StockDB($pdo,$argv[1]);
    require dirname(__DIR__).'/modules/shop/shop.lib.php';
    echo "ready\n";flush();
    if(!ShopHelper::setOrderStatus(1,'cancelled'))exit(1);
    echo "cancelled\n";
}catch(Throwable $e){fwrite(STDERR,get_class($e)."\n");exit(1);}
