<?php

class Module{}
class Expr{public function __construct($value){}}
class Config{public static function read($key,$module=null){return $key==='active'?0:1;}}
class IndexManager{public function moduleExists($name){return false;}public function getModelInstance($name){return new stdClass;}public function getModelNameFromModule($name){return 'IndexNews';}}
class IndexRecord{private $id;public function __construct($id=1){$this->id=$id;}public function getTitle(){return 'Public title';}public function getMain(){return 'Public body';}public function getTags(){return '';}public function getId(){return $this->id;}}
class IndexNews{public function getCollection($conditions,$params){$cursor=(int)substr(end($conditions),5);$rows=array();for($id=$cursor+1;$id<=min(30,$cursor+10);$id++)$rows[]=new IndexRecord($id);return $rows;}}

class IndexDB{
    public $saves=0;public $fail=true;public $live='previous';public $staged='';public $swaps=0;
    public function getFullTableName($table){return 'test_'.$table;}
    public function escape($value){return addslashes($value);} public function query($sql){if(preg_match('/CREATE|RENAME|DROP|TRUNCATE/',$sql))throw new RuntimeException('DDL is not allowed');return array();}
    public function save($table,$data){if($this->fail)throw new RuntimeException('Simulated indexing failure');$this->staged=$data['index'];$this->saves++;}
}
function touchDir($path){if(!is_dir($path))mkdir($path,0755,true);}
function atm_search_result_visible($row,$indexing=false){return true;}
require dirname(__DIR__).'/modules/search/index.php';
class IndexFixture extends SearchModule{public $directory;public function getTmpPath($file=null){return $this->directory.'/'.($file?:'');}}
$directory=sys_get_temp_dir().'/atomx-index-test-'.bin2hex(random_bytes(8));mkdir($directory);
$module=new IndexFixture;$module->directory=$directory;$module->tables=array('news');$module->DB=new IndexDB;$module->Register=array('ModManager'=>new IndexManager);
try{
    $failed=false;try{$module->rebuildIndexStep();}catch(RuntimeException $e){$failed=true;}
    if(!$failed || $module->DB->live!=='previous' || $module->DB->swaps!==0)throw new RuntimeException('Failed rebuild replaced the active index');
    $module->DB->fail=false;
    $state=array();$steps=0;
    do{$before=$module->DB->saves;$state=$module->rebuildIndexStep($state);if($module->DB->saves-$before>10)throw new RuntimeException('Unbounded indexing slice');$steps++;if($steps>10)throw new RuntimeException('Index did not finish');}while(is_array($state));
    if($module->DB->saves!==30 || $steps<3)throw new RuntimeException('Incremental index skipped records');
    echo "Shared-hosting incremental search checks passed.\n";
}finally{foreach(glob($directory.'/*')as$file)unlink($file);rmdir($directory);}
