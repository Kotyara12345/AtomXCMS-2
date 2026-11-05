<?php
class Module {}
class Config { public static function read($key,$module=null) { return $key==='per_page'?10:'en'; } }
class SearchEntity {
    private $row;
    public function __construct($row) { $this->row=$row; }
    public function asArray() { return $this->row; }
}
class SearchCache {
    public $values=array();
    public function check($key) { return isset($this->values[$key]); }
    public function read($key) { return $this->values[$key]; }
    public function write($data,$key,$tags,$options) { $this->values[$key]=$data; }
}
class SearchConfig { public function read($key,$module=null) { return Config::read($key,$module); } }
class SearchTestModel {
    public $calls=0;
    public function getSearchResults($query,$limit,$modules) {
        $this->calls++;
        return $GLOBALS['visible'] ? array(new SearchEntity(array('entity_id'=>1,'entity_table'=>'news','index'=>'public text'))) : array();
    }
}
function resc($value) { return $value; }
function atm_search_result_visible($row) { return $GLOBALS['visible']; }
function search_check($ok,$message) { if(!$ok) throw new RuntimeException($message); }
require dirname(__DIR__).'/modules/search/index.php';
class SearchFixture extends SearchModule { public function getTmpPath($file=null) { return sys_get_temp_dir().'/atomx-no-such-search-meta-'.$file; } }
$module=new SearchFixture;
$module->Register=array('Config'=>new SearchConfig);
$module->Model=new SearchTestModel;$module->Cache=new SearchCache;
$module->cached=true;$module->cacheKey='search';
$_SESSION=array('user'=>array('id'=>7,'status'=>1));$visible=true;
$method=new ReflectionMethod('SearchModule','__search');$method->setAccessible(true);
$method->invoke($module,'public',array('news'));
$method->invoke($module,'public',array('news'));
search_check($module->Model->calls===1,'Repeated search did not hit the same cache key');
$method->invoke($module,'public',array('stat'));
search_check($module->Model->calls===2,'Different module filter reused cached results');
$visible=false;
$results=$method->invoke($module,'public',array('news'));
search_check($results===array(),'Cached search leaked a newly hidden material');
search_check($module->cacheKey==='search','Search mutated its base cache key');
echo "Search cache runtime checks passed.\n";
