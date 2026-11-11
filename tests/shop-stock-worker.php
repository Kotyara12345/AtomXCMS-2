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
    public function save($table,$values,$params=array()){
        $table=$this->getFullTableName($table);$values=(array)$values;
        if(!empty($values['id'])){$params=array('id'=>(int)$values['id']);unset($values['id']);}
        if(!empty($params)){
            $sets=array();foreach($values as$k=>$v)$sets[]="`{$k}`=".$this->pdo->quote((string)$v);
            $where=array();foreach($params as$k=>$v)$where[]="`{$k}`=".$this->pdo->quote((string)$v);
            $this->pdo->exec("UPDATE `{$table}` SET ".implode(',',$sets)." WHERE ".implode(' AND ',$where));
            return !empty($params['id'])?(int)$params['id']:0;
        }
        $cols=array_keys($values);$marks=implode(',',array_fill(0,count($cols),'?'));
        $st=$this->pdo->prepare("INSERT INTO `{$table}` (`".implode('`,`',$cols)."`) VALUES ({$marks})");
        $st->execute(array_values($values));return (int)$this->pdo->lastInsertId();
    }
}
try {
    $pdo=new PDO(getenv('ATM_TEST_DSN'),getenv('ATM_TEST_USER'),getenv('ATM_TEST_PASS'),array(PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION));
    Register::getInstance()['DB']=new StockDB($pdo,$argv[1]);
    require dirname(__DIR__).'/modules/shop/shop.lib.php';
    echo "ready\n";flush();
    if(!ShopHelper::setOrderStatus(1,'cancelled'))exit(1);
    echo "cancelled\n";
}catch(Throwable $e){fwrite(STDERR,get_class($e)."\n");exit(1);}
