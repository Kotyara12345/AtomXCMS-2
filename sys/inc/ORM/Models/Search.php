<?php
/*---------------------------------------------\
|											   |
| @Author:       Andrey Brykin (Drunya)        |
| @Version:      1.3                           |
| @Project:      CMS                           |
| @Package       AtomX CMS                     |
| @subpackege    News Model                    |
| @copyright     ©Andrey Brykin 2010-2013      |
| @last mod      2013/11/11                    |
|----------------------------------------------|
|											   |
| any partial or not partial extension         |
| CMS AtomX,without the consent of the         |
| author, is illegal                           |
|----------------------------------------------|
| Любое распространение                        |
| CMS AtomX или ее частей,                     |
| без согласия автора, является не законным    |
\---------------------------------------------*/



/**
 *
 */
#[\AllowDynamicProperties]
class SearchModel extends FpsModel
{
	public $Table = 'search_index';

    protected $RelatedEntities = array();

	

    public function truncateTable()
    {
        $this->getDbDriver()->query("TRUNCATE `" . $this->getDbDriver()->getFullTableName('search_index') . "`");
    }
	
	
	
	public function getSearchResults($search, $limit, $modules)
	{
		if (empty($modules)) {
			return array();
		}

		$limit = max(1, min(100, (int)$limit));
		$search = $this->getDbDriver()->escape(htmlspecialchars_decode((string)$search, ENT_QUOTES));
		$modules = array_values(array_intersect((array)$modules, array('forum','news','stat','loads','shop')));
		if (!$modules) return array();
		$lmsql = '';
		if (is_array($modules)) {
			$lmsql .= '(';
			foreach ($modules as $module) {
				if ($module != $modules[0]) {
					$lmsql .= ' OR ';
				}
				$lmsql .= '`module` = \''.$module.'\'';
			}
			$lmsql .= ') AND';
		}
		$results = array();
		for ($offset = 0; $offset < 10000 && count($results) < $limit; $offset += 100) {
		$batch = $this->getDbDriver()->query("
			SELECT * FROM `" . $this->getDbDriver()->getFullTableName('search_index') . "`
			WHERE ".$lmsql." MATCH (`index`) AGAINST ('" . $search . "' IN BOOLEAN MODE)
			ORDER BY MATCH (`index`) AGAINST ('" . $search . "' IN BOOLEAN MODE) DESC, `id` ASC LIMIT " . $offset . ",100");
		foreach ($batch as $res) {
			if (atm_search_result_visible($res)) $results[] = new SearchEntity($res);
			if (count($results) >= $limit) break;
		}
		if (count($batch) < 100) break;
		}
			
		return $results;
	}
}
