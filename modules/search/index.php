<?php
/*-----------------------------------------------\
| 												 |
|  @Author:       Andrey Brykin (Drunya)         |
|  @Version:      1.9                            |
|  @Project:      CMS                            |
|  @package       CMS AtomX                      |
|  @subpackege    Search Module                  |
|  @copyright     ©Andrey Brykin 2010-2013       |
|  @last mod.     2017/10/13                     |
\-----------------------------------------------*/

/*-----------------------------------------------\
| 												 |
|  any partial or not partial extension          |
|  CMS AtomX,without the consent of the          |
|  author, is illegal                            |
|------------------------------------------------|
|  Любое распространение                         |
|  CMS AtomX или ее частей,                      |
|  без согласия автора, является не законным     |
\-----------------------------------------------*/




#[\AllowDynamicProperties]
class SearchModule extends Module {

	/**
	 * @module_title  title of module
	 */
	public $module_title = 'Поиск';

	/**
	 * @module module indentifier
	 */
	public $module = 'search';

	/**
	 * @var int
	 */
	private $minInputStr = 5;

	/**
	 * @var array
	 */
	private $searchTerms = array();

	/**
	 * @var array
	 */
	public $tables = array('posts', 'forums', 'forum_cat', 'themes', 'stat', 'news', 'loads');

	/**
	 * @var boolean
	 */
	private $returnForm = true;

	/**
	 * @return string - $this->_view
	 *
	 * Doing search and build page with results
	 */
	public function index() {
		// the index build and the query parser must use the same minimum length
		$minInput = $this->Register['Config']->read('min_lenght', $this->module);
		if (!empty($minInput))
			$this->minInputStr = (int) $minInput;

		// Shop products are indexed only when the module is installed and enabled.
		if ($this->__isShopSearchAvailable() && !in_array('shop_products', $this->tables, true)) {
			$this->tables[] = 'shop_products';
		}

		//check index
		$this->__checkIndex();

		$html = null;
		$error = null;
		$results = null;

		$availableModules = array('forum', 'news', 'stat', 'loads');
		if ($this->__isShopSearchAvailable()) {
			$availableModules[] = 'shop';
		}

		if (isset($_POST['m'])) {
			$modules = array();
			foreach ((array) $_POST['m'] as $m) {
				if (in_array($m, $availableModules, true) && !in_array($m, $modules, true))
					$modules[] = $m;
			}
		} else {
			$modules = $availableModules;
		}
		$_SESSION['m'] = $modules;

		
		if (isset($_POST['search']) || isset($_GET['search'])) {
			$str = (isset($_POST['search'])) ? h($_POST['search']) : '';
			if (empty($str))
				$str = (isset($_GET['search'])) ? h($_GET['search']) : '';
			if (!is_string($str))
				$str = (string) $str;
			$str = trim($str);
			

			if (empty($str) || mb_strlen($str) < $this->minInputStr)
				$error = $error . '<li>' . sprintf(__('Very small query'), $this->minInputStr) . '</li>';



			$_SESSION['search_query'] = $str;
			if (!empty($error)) {
				$_SESSION['FpsForm'] = array();
				$_SESSION['FpsForm']['errors'] = $error;
				redirect($this->getModuleURL());
			}

			
			$str = Plugins::intercept('before_search', $str);
			$results = $this->__search($str, $modules);
			$results = Plugins::intercept('search_results', $results);
			$results = array_values(array_filter((array)$results, function ($result) {
				return is_object($result) && atm_search_result_visible($result->asArray());
			}));
			
			
			if (count($results) && is_array($results)) {
				foreach ($results as $result) {
					$announce = $this->__highlightAnnounce($result->getIndex(), $str);
					$title = h($result->getEntity_title());
					$entry_url = get_url('/' . $result->getModule() . $result->getEntity_view() . $result->getEntity_id());
					$result->setEntry_url($entry_url);
					$result->setTitle($title);
					$result->setAnnounce($announce);
				}
			} else {
				$error = __('No results'); // TODO
			}
		} else {
			$_SESSION['search_query'] = '';
		}



		// Nav block
		$nav = array();
		$nav['navigation'] = get_link(__('Home'), '/') . __('Separator') . $this->module_title;
		$this->_globalize($nav);


		if (!empty($str))
            $this->addToPageMetaContext('entity_title', $str);


		$this->returnForm = false;
		$form = $this->form();
		$source = $this->render('search_list.html', array('context' => array(
				'results' => $results,
				'form' => $form,
				'error' => $error,
				)));
		
		// Cache data in __search(), never rendered snippets that bypass access checks.

		return $this->_view($source);
	}

	/**
	 * Shop products are searchable only when the shop module is installed and enabled.
	 *
	 * @return boolean
	 */
	private function __isShopSearchAvailable() {
		if (empty($this->Register['ModManager'])) {
			return false;
		}
		return ($this->Register['ModManager']->moduleExists('shop')
			&& (string) $this->Register['Config']->read('active', 'shop') === '1');
	}

	/**
	 * @return string search form
	 */
	public function form() {
		$markers = array(
			'action' => $this->getModuleURL(),
			'search' => '',
			'forum' => '0',
			'news' => '0',
			'stat' => '0',
			'loads' => '0',
			'shop' => '0',
			'shop_available' => '',
			'shop_title' => '',
		);

		if ($this->__isShopSearchAvailable()) {
			$markers['shop_available'] = '1';
			$markers['shop_title'] = (string) $this->Register['Config']->read('title', 'shop');
			if ($markers['shop_title'] === '') {
				$markers['shop_title'] = 'Магазин';
			}
		}


		//if an errors
		if (isset($_SESSION['FpsForm'])) {
			$markers['info'] = $this->render('infomessage.html', array('context' => array(
					'message' => $_SESSION['FpsForm']['errors'],
					)));
			unset($_SESSION['FpsForm']);
		}

		$markers['search'] = $_SESSION['search_query'];

		foreach ($_SESSION['m'] as $m) {
			$markers[$m] = 'checked';
		}

		$source = $this->render('search_form.html', array('context' => $markers));
		return ($this->returnForm) ? $this->_view($source) : $source;
	}

	/**
	 * @return boolean
	 */
	private function __checkIndex() {
		$meta_file = $this->getTmpPath('meta.dat');
		$indexVersion = 4;
		$shopIndexed = $this->__isShopSearchAvailable() ? 1 : 0;
		$meta = array();
		if (file_exists($meta_file) && is_readable($meta_file)) {
			$meta = @unserialize(file_get_contents($meta_file), array('allowed_classes' => false));
			if (!is_array($meta)) {
				$meta = array();
			}
			if (!empty($meta['expire']) && $meta['expire'] > time() && $this->__indexIsFilled()
					&& isset($meta['version']) && (int) $meta['version'] === $indexVersion
					&& isset($meta['shop']) && (int) $meta['shop'] === $shopIndexed) {
				return true;
			}
		} else {
			touchDir($this->getTmpPath());
		}

		try { AtmJobs::enqueue('search.rebuild', array(), 'search'); }
		catch (Throwable $e) { error_log('AtomX cannot queue search rebuild'); }
		return false;
	}

	public function rebuildIndexStep($state = array()) {
        $min = (int)Config::read('min_lenght', 'search');
        if ($min > 0) $this->minInputStr = $min;
        if ($this->__isShopSearchAvailable() && !in_array('shop_products', $this->tables, true)) $this->tables[] = 'shop_products';
        touchDir($this->getTmpPath());
        if (!$state) {
            $live = $this->DB->getFullTableName('search_index');
            $latest = $this->DB->query("SELECT MAX(`date`) AS latest FROM `{$live}`");
            $stamp = max(time(), !empty($latest[0]['latest']) ? strtotime($latest[0]['latest']) + 1 : 0);
            $state = array('table'=>0,'cursor'=>0,'stamp'=>date('Y-m-d H:i:s', $stamp),
                'dirty'=>(string)@file_get_contents($this->getTmpPath('dirty.dat')));
        }
        $next = $this->__createIndex($state);
        if ($next === false) return $state;
        if (is_array($next)) return $next;
        if ((string)@file_get_contents($this->getTmpPath('dirty.dat')) !== $state['dirty']) return array();
        $meta = serialize(array('expire'=>time() + max(1, (int)Config::read('index_interval', 'search')) * 86400,
            'version'=>4,'shop'=>$this->__isShopSearchAvailable() ? 1 : 0,'built'=>bin2hex(random_bytes(8))));
        $tmp = tempnam($this->getTmpPath(), '.meta-');
        if ($tmp === false) throw new RuntimeException('Cannot save search metadata');
        try { if (file_put_contents($tmp, $meta) !== strlen($meta) || !rename($tmp, $this->getTmpPath('meta.dat'))) throw new RuntimeException('Cannot save search metadata'); }
        finally { if (is_file($tmp)) @unlink($tmp); }
        return null;
    }

	/**
	 * @return boolean
	 *
	 * True if the search index contains at least one record.
	 * The index can be empty even when meta.dat says "fresh"
	 * (for example after moving the database), so the search
	 * must rebuild it instead of showing no results at all.
	 */
	private function __indexIsFilled() {
		$records = $this->Model->getCollection(array(), array('limit' => 1, 'page' => 1));
		return !empty($records);
	}

	/**
	 * @param string $str
	 * @return array
	 *
	 * Send request and return search results
	 */
	private function __search($str, $modules) {
		$this->searchTerms = array();
		$str = htmlspecialchars_decode((string) $str, ENT_QUOTES);
		$words = preg_split('#\s+#u', trim($str), -1, PREG_SPLIT_NO_EMPTY);
		if (!$words) {
			$words = array();
		}

		$terms = array();
		foreach ($words as $word) {
			foreach ($this->__searchWordVariants($word) as $variant) {
				$key = mb_strtolower($variant, 'UTF-8');
				if (!isset($terms[$key])) {
					$terms[$key] = $variant;
				}
			}
		}
		$this->searchTerms = array_values($terms);

		if (count($this->searchTerms) < 1) {
			return array();
		}
		$string = resc(implode('* ', $this->searchTerms) . '*');
		
		//query
		$limit = intval($this->Register['Config']->read('per_page', $this->module));
		if ($limit < 1)
			$limit = 10;
		
		sort($modules);
		$access = array_intersect_key($_SESSION, array_flip(array_filter(array_keys($_SESSION), function ($key) { return strpos($key, 'access_forum_') === 0; })));
		$meta = @file_get_contents($this->getTmpPath('meta.dat'));
		$key = $this->cacheKey . '_' . hash('sha256', serialize(array('search-v4', $string, $modules, $limit,
			isset($_SESSION['user']) ? $_SESSION['user'] : array(), $access, Config::read('language'), $meta)));
		if ($this->cached && $this->Cache->check($key)) {
			$rows = @unserialize($this->Cache->read($key), array('allowed_classes'=>false));
			if (is_array($rows)) {
				$results = array();
				foreach ($rows as $row) if (is_array($row) && atm_search_result_visible($row)) $results[] = new SearchEntity($row);
				if (count($results) === count($rows)) return $results;
			}
		}
		$results = $this->Model->getSearchResults($string, $limit, $modules);
		if ($this->cached) {
			$rows = array();
			foreach ($results as $result) $rows[] = $result->asArray();
			$this->Cache->write(serialize($rows), $key, array('module_search'), array('lifetime'=>60));
		}
		
		return $results;
	}

	/**
	 * Build search variants for both keyboard layouts.
	 *
	 * If a word was typed on the wrong layout, searching only the original
	 * string finds nothing. The original word and both layout conversions are
	 * searched, so the same query works for Russian and English content.
	 *
	 * @param string $word
	 * @return array
	 */
	private function __searchWordVariants($word) {
		$variants = array(
			$word,
			strtr($word, $this->__keyboardMap('latin')),
			strtr($word, $this->__keyboardMap('cyrillic')),
		);

		// Also try the word without edge punctuation before converting it.
		// This covers a layout word wrapped in quotes or followed by an ordinary
		// comma or period, while the variants above still keep punctuation keys
		// that are letters.
		$withoutEdgePunctuation = preg_replace('#^[^\p{L}]+|[^\p{L}]+$#u', '', $word);
		if ($withoutEdgePunctuation !== null
				&& $withoutEdgePunctuation !== ''
				&& $withoutEdgePunctuation !== $word) {
			$variants[] = strtr($withoutEdgePunctuation, $this->__keyboardMap('latin'));
			$variants[] = strtr($withoutEdgePunctuation, $this->__keyboardMap('cyrillic'));
		}

		$result = array();

		foreach ($variants as $variant) {
			$variant = $this->__filterText($variant);
			if ($variant === false || mb_strlen($variant) < $this->minInputStr) {
				continue;
			}
			$key = mb_strtolower($variant, 'UTF-8');
			if (!isset($result[$key])) {
				$result[$key] = $variant;
			}
		}

		return array_values($result);
	}

	/**
	 * Return a character map for the standard Russian keyboard layout.
	 *
	 * @param string $direction latin (English -> Russian) or cyrillic (Russian -> English)
	 * @return array
	 */
	private function __keyboardMap($direction) {
		static $latinToCyrillic = null;
		static $cyrillicToLatin = null;

		if ($latinToCyrillic === null) {
			$latinToCyrillic = array(
				'q' => 'й', 'w' => 'ц', 'e' => 'у', 'r' => 'к', 't' => 'е',
				'y' => 'н', 'u' => 'г', 'i' => 'ш', 'o' => 'щ', 'p' => 'з',
				'[' => 'х', ']' => 'ъ', 'a' => 'ф', 's' => 'ы', 'd' => 'в',
				'f' => 'а', 'g' => 'п', 'h' => 'р', 'j' => 'о', 'k' => 'л',
				'l' => 'д', ';' => 'ж', "'" => 'э', 'z' => 'я', 'x' => 'ч',
				'c' => 'с', 'v' => 'м', 'b' => 'и', 'n' => 'т', 'm' => 'ь',
				',' => 'б', '.' => 'ю', '`' => 'ё',
				'Q' => 'Й', 'W' => 'Ц', 'E' => 'У', 'R' => 'К', 'T' => 'Е',
				'Y' => 'Н', 'U' => 'Г', 'I' => 'Ш', 'O' => 'Щ', 'P' => 'З',
				'{' => 'Х', '}' => 'Ъ', 'A' => 'Ф', 'S' => 'Ы', 'D' => 'В',
				'F' => 'А', 'G' => 'П', 'H' => 'Р', 'J' => 'О', 'K' => 'Л',
				'L' => 'Д', ':' => 'Ж', '"' => 'Э', 'Z' => 'Я', 'X' => 'Ч',
				'C' => 'С', 'V' => 'М', 'B' => 'И', 'N' => 'Т', 'M' => 'Ь',
				'<' => 'Б', '>' => 'Ю', '~' => 'Ё',
			);
			$cyrillicToLatin = array_flip($latinToCyrillic);
		}

		if ($direction === 'latin') {
			return $latinToCyrillic;
		}
		if ($direction === 'cyrillic') {
			return $cyrillicToLatin;
		}

		return array();
	}

	/**
	 * Build an announce snippet for a result and highlight the matched term.
	 *
	 * @param string $text
	 * @param string $fallback
	 * @return string
	 */
	private function __highlightAnnounce($text, $fallback) {
		$pattern = $this->__searchPattern();
		if ($pattern === '') {
			$pattern = preg_quote((string) $fallback, '#');
		}
		if ($pattern === '') {
			return h($text);
		}

		if (preg_match('#(.{0,100}(?:' . $pattern . ').{0,100})#miu', $text, $match)) {
			$announce = $match[1];
		} else {
			$announce = mb_substr($text, 0, 150);
		}

		return preg_replace('#(' . $pattern . ')#iu', '<strong>$1</strong>', h($announce));
	}

	/**
	 * Build a regular expression pattern from all query variants.
	 *
	 * @return string
	 */
	private function __searchPattern() {
		$patterns = array();
		foreach ($this->searchTerms as $term) {
			$term = (string) $term;
			if ($term !== '') {
				$patterns[] = preg_quote($term, '#');
			}
		}
		usort($patterns, function ($a, $b) {
			return mb_strlen($b, 'UTF-8') - mb_strlen($a, 'UTF-8');
		});
		return implode('|', $patterns);
	}

	/**
	 *
	 *
	 * Create index for search engine
	 */
	private function __createIndex($state) {
        touchDir($this->getTmpPath());
        $lock_file = $this->getTmpPath('lock.dat');

        // Use an advisory lock instead of a plain file-existence check so two
        // concurrent requests cannot rebuild the same index at once.
        $lock_handle = @fopen($lock_file, 'c');
        if (!$lock_handle) return false;
        if (!flock($lock_handle, LOCK_EX | LOCK_NB)) {
            fclose($lock_handle);
            return false;
        }


        $build = 'search_index';
        $live = $this->DB->getFullTableName($build);
        try {

		// cache of topic titles (forum messages are titled with their topic)
		$themesModel = $this->Register['ModManager']->getModelInstance('Themes');
		$themeTitles = array();

		foreach (array_slice($this->tables, (int)$state['table'], 1) as $table) {
			// the forum sections table ('forums') is handled by the ForumModel class
			// and shop products by the module's ShopModel class.
			if ($table === 'forums') {
				$className = 'ForumModel';
			} elseif ($table === 'shop_products') {
				$className = 'ShopModel';
			} else {
				$className = $this->Register['ModManager']->getModelNameFromModule($table);
			}
			if (!class_exists($className)) { $state['table']++; $state['cursor'] = 0; return $state; }
			$Model = new $className;
			$conditions = ($table === 'shop_products') ? array('active' => 1) : array();
			if (in_array($table, array('news','stat','loads'), true)) $conditions = array('available'=>1,'premoder'=>'confirmed');
			
			{
                $conditions[] = 'id > ' . (int)$state['cursor'];
                $records = $Model->getCollection($conditions, array('limit'=>10, 'order'=>'id ASC'));


				if (count($records) && is_array($records)) {
					foreach ($records as $rec) {
                        $state['cursor'] = (int)$rec->getId();

						switch ($table) {
							case 'news':
							case 'stat':
							case 'loads':
								$text = $rec->getTitle() . ' ' . $rec->getMain() . ' ' . $rec->getTags();
								if (mb_strlen($text) < $this->minInputStr || !is_string($text))
									continue 2;
								$entity_view = '/view/';
								$module = $table;
								$entity_id = $rec->getId();
								$entity_title = $rec->getTitle();
								break;

							case 'shop_products':
								// Shop descriptions may contain the same BBCode as news.
								$text = $rec->getTitle() . ' ' . $rec->getShort_description() . ' ' . $rec->getDescription();
								if (!is_string($text))
									continue 2;
								$text = preg_replace('#<[^>]*>|\[[^\]]*\]#u', ' ', $text);
								if (!is_string($text) || mb_strlen($text) < $this->minInputStr)
									continue 2;
								$entity_view = '/view/';
								$module = 'shop';
								$entity_id = $rec->getId();
								$entity_title = $rec->getTitle();
								break;

							case 'forums':
								// forum sections: the section name and its description
								$text = $rec->getTitle() . ' ' . $rec->getDescription();
								if (mb_strlen($text) < $this->minInputStr || !is_string($text))
									continue 2;
								$entity_view = '/view_forum/';
								$module = 'forum';
								$entity_id = $rec->getId();
								$entity_title = $rec->getTitle();
								break;

							case 'forum_cat':
								// forum categories: the category name
								$text = $rec->getTitle();
								if (mb_strlen($text) < $this->minInputStr || !is_string($text))
									continue 2;
								$entity_view = '/index/';
								$module = 'forum';
								$entity_id = $rec->getId();
								$entity_title = $rec->getTitle();
								break;

							case 'themes':
								// topics: the topic title and its description
								$text = $rec->getTitle() . ' ' . $rec->getDescription();
								if (mb_strlen($text) < $this->minInputStr || !is_string($text))
									continue 2;
								$entity_view = '/view_theme/';
								$module = 'forum';
								$entity_id = $rec->getId();
								$entity_title = $rec->getTitle();
								break;

							case 'posts':
								// messages: the message text, the result title is the topic title
								$text = $rec->getMessage();
								$entity_view = '/view_theme/';
								$module = 'forum';
								$entity_id = $rec->getId_theme();
								$entity_title = '';
								if ($entity_id) {
									if (!array_key_exists($entity_id, $themeTitles)) {
										$theme = $themesModel->getById($entity_id);
										$themeTitles[$entity_id] = ($theme) ? $theme->getTitle() : '';
									}
									$entity_title = $themeTitles[$entity_id];
								}
								break;

							default:
								$text = $rec->gettitle() . ' ' . $rec->getMain() . ' ' . $rec->getTags();
								if (mb_strlen($text) < $this->minInputStr || !is_string($text))
									continue 2;
								$entity_view = '/view/';
								$module = $table;
								$entity_id = $rec->getId();
								$entity_title = $rec->getTitle();
								break;
						}


						//we must update record if an exists
						$data = array(
							'source_id' => (int)$rec->getId(),
							'index' => $text,
							'entity_id' => $entity_id,
							'entity_title' => $entity_title,
							'entity_table' => $table === 'posts' ? 'posts:' . (int)$rec->getId() : $table,
							'entity_view' => $entity_view,
							'module' => $module,
							'date' => $state['stamp'],
						);
						if (atm_search_result_visible($data, true)) {
                            unset($data['source_id']);
                            $existing = $this->DB->query("SELECT `id` FROM `{$live}` WHERE `entity_table` = '" . $data['entity_table'] . "' AND `entity_id` = " . (int)$entity_id . " LIMIT 1");
                            if (!empty($existing[0]['id'])) $data['id'] = (int)$existing[0]['id'];
                            $this->DB->save($build, $data);
                        }
					}
				}
			}
		}

        if (isset($records) && count($records) < 10) { $state['table']++; $state['cursor'] = 0; }
        if ($state['table'] < count($this->tables)) return $state;
        $this->DB->query("DELETE FROM `{$live}` WHERE `date` <> '" . $this->DB->escape($state['stamp']) . "'");
        return null;
        } finally { flock($lock_handle, LOCK_UN); fclose($lock_handle); }

	}

	/**
	 * @param string $str
	 * @return string
	 *
	 * Cut HTML and BB tags. Also another chars
	 */
	private function __filterText($str) {
		$str = preg_replace('#<[^>]*>|\[[^\]]*\]#u', '', $str);
		if ($str === null) {
			return false;
		}
		$str = preg_replace('#[^\p{L}\s]+#u', '', $str);
		if ($str === null) {
			return false;
		}
		$str = trim($str);
		return ($str !== '') ? $str : false;
	}

}
