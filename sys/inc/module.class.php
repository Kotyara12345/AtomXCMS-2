<?php
/*-----------------------------------------------\
| 												 |
|  @Author:       Andrey Brykin (Drunya)         |
|  @Email:        drunyacoder@gmail.com          |
|  @Site:         http://atomx.net			     |
|  @Version:      1.6.2                          |
|  @Project:      CMS                            |
|  @package       CMS AtomX                      |
|  @Subpackege    Module Class                   |
|  @Copyright     ©Andrey Brykin 		         |
|  @Last mod.     2014/03/14                     |
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
class Module {

	/**
	* @page_title title of the page
	*/
	public $page_title = '';
	/**
	* @var string 
	*/
	public $page_meta_keywords;
	/**
	* @var string
	*/
	public $page_meta_description;
	/**
	* @template layout for module
	*/
	public $template = 'default';
	/**
	* @categories list of categories
	*/
	public $categories = '';
	/**
	* @module_title title for module
	*/
	public $module_title = '';
	/**
	* @module current module
	*/
	public $module = '';
	/**
	* @cacheTags Cache tags
	*/
	public $cacheTags = array();
	/**
	* @cached   use the cache engine
	*/
	protected $cached = true;
	
	/**
	* Materials of the module can be bound to several categories
	* at once. Modules that support this enable the flag and keep
	* the additional category ids in the `categories_bind` table.
	*/
	protected $useBoundCategories = false;
	
	/**
	* @var (str)   comments block
	*/
	protected $comments = '';
	
	/**
	* @var (str)   add comments form
	*/
	protected $comments_form = '';
	
	/**
	* @var    database object
	*/
	protected $DB;
	/**
	* uses for work with actions log
	*
	* @var    logination object
	*/
	protected $Log;
	/**
	* uses for work with parser (chuncks, snippets, global markers ...)
	*
	* @var    parser object
	*/
	protected $Parser;
	
	/**
	* Access control list
	*
	* @var object
	*/
	protected $ACL;
	
	/**
	 * Object for work with text
	 */
	protected $Textarier;
	
	/**
	 * @var object
	 */
	protected $AddFields = false; 
	
	/**
	 * if true - counter not worck
	 *
	 * @var boolean
	 */
	public $counter = true;
	
	/**
	 * Use wrapper?
	 *
	 * @var boolean
	 */
	protected $wrap = true;

    /**
     * @var object
     */
    public $Register;
	
	/**
	 * @var object
	 */
	public $Model;

    /**
     * @var array
     */
    private $pageMetaContext = array(
        'module' => '',
        'category_title' => '',
        'entity_title' => '',
        'page' => '',
    );
	 
	
	
	/**
	 * @var array
	 */
	protected $globalMarkers = array(
		'module' => '',
		'navigation' => '',
		'pagination' => '',
		'meta' => '',
		'add_link' => '',
		'comments_pagination' => '',
		'comments' => '',
        'comments_form' => '',
		'fps_curr_page' => 1,
		'fps_pagescnt' => 1,
		'fps_totalitems' => 0,
	);
	
	
	
	
	/**
	 * @param array $params - array with modul, action and params
	 *
	 * Initialize needed objects adn set needed variables
	 */
    public function __construct($params)
    {
        $this->Register = Register::getInstance();
        $this->Register['module'] = $params[0];
        $this->Register['action'] = $params[1];
        $this->Register['params'] = $params;
		
		if (is_callable(array($this, '_getValidateRules')))
			$this->Register['Validate']->setRules($this->_getValidateRules());
		$this->Register['Validate']->setModule($this->module);
        $this->setModel();
		
        //init needed objects. Core...
		// Use for templater (layout)
		$this->template = $this->module;
		$this->View = clone $this->Register['Viewer'];
		$this->View->setLayout($this->template);
		$this->Parser = new Document_Parser;
		$this->Parser->templateDir = $this->template;
		
		$this->DB = $this->Register['DB'];
		$this->Textarier = new PrintText;
		if (Config::read('secure.system_log')) $this->Log = new Logination;

		// init aditional fields
		if ($this->Register['Config']->read('use_additional_fields')) {
			$this->AddFields = new FpsAdditionalFields;
			$this->AddFields->module = $this->module;
		}

		
		//init Access Control List
		$this->ACL = $this->Register['ACL'];
		$this->_beforeRender();
		
		if ($this->Register['Config']->read('active', $params[0]) == 0) {
            $this->Parser->showHttpError();
		}
		
		$this->page_title = ($this->Register['Config']->read('title', $this->module))
            ? h(Config::read('title', $this->module)) : h(Config::read('title'));
		$this->params = $params;
		
		//cache
		$this->Cache = new Cache;
		if ($this->Register['Config']->read('cache') == 1) {
			$this->cached = true;
			$this->cacheKey = $this->getKeyForCache($params);
			$this->setCacheTag(array('module_' . $params[0]));
			if (!empty($params[1])) $this->setCacheTag(array('action_' . $params[1]));
		} else {
			$this->cached = false;
		}
		
		//meta tags
		$this->page_meta_keywords = h(Config::read('keywords', $this->module));
		$this->page_meta_description = h(Config::read('description', $this->module));
		
		if (empty($this->page_meta_keywords)) {
			$this->page_meta_keywords = h(Config::read('meta_keywords'));
		}
		if (empty($this->page_meta_description)) {
			$this->page_meta_description = h(Config::read('meta_description'));
		}
	}
	
	
	
	protected function setModel()
	{
		$class = ucfirst($this->module) . 'Model';
		if (class_exists($class)) $this->Model = new $class();
	}
	
	
	/**
	 * Uses for before render
	 * All code in this function will be worked before
	 * begin render page and launch controller(module)
	 *
	 * @return none
	 */
	protected function _beforeRender()
    {
        $this->addToPageMetaContext('module', ucfirst($this->module));

        if (
            (!empty($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST')
            || substr($_SERVER['PHP_SELF'], 1, 5) === 'admin'
            || in_array($this->module, array('chat', 'rating'))
        ) {
            $this->counter = false;
        }

		if (isset($_SESSION['page'])) unset($_SESSION['page']);
		if (isset($_SESSION['pagecnt'])) unset($_SESSION['pagecnt']);
	}
	

	/**
	 * Uses for after render
	 * All code in this function will be worked after
	 * render page.
	 *
	 * @return none
	 */
	protected function _afterRender()
    {
		// Cron
		if (Config::read('auto_sitemap')) {
			fpsCron('createSitemap', 86400);
		}
		
		
		/*
		* counter ( if active )
		* and if we not in admin panel (see _beforeRender())
		*/
		if ($this->counter === false) return;
		

        include_once ROOT . '/modules/statistics/index.php';
        if (Config::read('active', 'statistics') == 1) {
            StatisticsModule::index();
        } else {
            StatisticsModule::viewOffCounter();
        }
	}
	

	/**
	* @param string $content  data for parse and view
	* @access   protected
	*/
	protected function _view($content)
    {
        $Register = Register::getInstance();

		if (!empty($this->template) && $this->wrap == true) {
            Plugins::intercept('before_parse_layout', $this);
			

			$this->View->setLayout($this->template);
			$markers = $this->getGlobalMarkers(file_get_contents($this->View->getTemplateFilePath('main.html')));
            $markers['content'] = $content;
			
			
			// Cache global markers
			if ($this->cached) {
				if ($this->Cache->check($this->cacheKey . '_global_markers')) {
					$gdata = $this->Cache->read($this->cacheKey . '_global_markers');
					$this->globalMarkers = array_merge($this->globalMarkers, unserialize($gdata));
				} else {
					$gdata = serialize($this->globalMarkers);
					$this->Cache->write($gdata, $this->cacheKey . '_global_markers', $this->cacheTags);
				}
			}
			
			
			$boot_time = round(getMicroTime() - $Register['fps_boot_start_time'], 4);
			$markers = array_merge($markers, array('boot_time' => $boot_time));

			$output = $this->render('main.html', $markers);
		} else {
            $output = $content;
		}

		$this->_afterRender();

		if (function_exists('atm_webp_rewrite_html')) {
			$output = atm_webp_rewrite_html($output);
		}

		echo $output;

		if (Config::read('debug_mode') == 1) {
            echo AtmDebug::getBody();
		}
        die();
	}


	public function render($fileName, array $markers = array())
	{
        $additionalMarkers = $this->getGlobalMarkers();
        $this->_globalize($additionalMarkers);
		$source = $this->View->view($fileName, array_merge($markers, $this->globalMarkers));
		return $source;
	}
	
	
	public function renderString($string, array $markers = array())
	{
        $additionalMarkers = $this->getGlobalMarkers();
        $this->_globalize($additionalMarkers);
		$source = $this->View->parseTemplate($string, array_merge($markers, $this->globalMarkers));
		return $source;
	}


    protected function getGlobalMarkers($html = '')
    {
        $Register = Register::getInstance();
		$markers1 = $this->Parser->getGlobalMarkers($html);
        $markers2 = array(
            'module' => $this->module,
            'action' => $Register['action'],
            'params' => $Register['params'],
            'title' => $this->getCompleteMetaTag($this->page_title),
            'meta_title' => $this->getCompleteMetaTag($this->page_title),
            'meta_description' => $this->getCompleteMetaTag($this->page_meta_description),
            'meta_keywords' => $this->getCompleteMetaTag($this->page_meta_keywords),
            'canonical' => h($this->getCanonicalUrl()),
            'module_title' => $this->module_title,
            'categories' => $this->categories,
            'comments' => $this->comments,
			'comments_form' => $this->comments_form,
            'fps_curr_page' => (!empty($Register['page'])) ? intval($Register['page']) : 1,
            'fps_pagescnt' => (!empty($Register['pagescnt'])) ? intval($Register['pagescnt']) : 1,
            'fps_totalitems' => (isset($Register['totalitems'])) ? intval($Register['totalitems']) : 0,
            'fps_user' => (!empty($_SESSION['user'])) ? $_SESSION['user'] : array(),
			'is_home_page' => $Register['is_home_page'] ? true : false,
        );
		$markers = array_merge($markers1, $markers2);
        //pr($markers);
        return $markers;
    }


    /**
     * Save markers. Get list of markers
     * and content wthat will be instead markers
     * Before view this markers will be replaced in
     * all content
     *
     * @param array $markers - market->value
     * @return none
     */
	protected function _globalize($markers = array())
    {
		$this->globalMarkers = array_merge($this->globalMarkers, $markers);
	}
	

	/**
	* @param  mixed $tag
    * @return boolean
	*/
	public function setCacheTag($tag)
    {
		if ((Config::read('cache') == true || Config::read('cache') == 1) && $this->cached === true) {
			if (is_array($tag)) {
				foreach ($tag as $_tag) {
					$this->setCacheTag($_tag);
				}
			} else {
				$this->cacheTags[] = $tag;
				return true;
			}
		}
		return false;
	}
	

	/**
	* create unique id for cache file
     *
	* @param array $params <module>[ action [ param1 [ param2 ]]]
    * @return string
	*/
	private function getKeyForCache($params)
    {
		$cacheId = '';
		foreach ($params as $value) {
			if (is_array($value)) {
				foreach ($value as $_value) {
					$cacheId = $cacheId . $_value . '_';
				}
				continue;
			}
			$cacheId = $cacheId . $value . '_';
		}
		
		if (!empty($_GET['page']) && is_numeric($_GET['page'])) {
				$cacheId = $cacheId . intval($_GET['page']) . '_';
		}
		if (!empty($_GET['order'])) {
			$order = (string)$_GET['order'];
			if (!empty($order)) {
				$order = substr($order, 0, 10);
				$cacheId .= $order . '_';
			}
		}
		if (!empty($_GET['asc'])) {
			$cacheId .= 'asc_';
		}
		$cacheId = (!empty($_SESSION['user']['status'])) ? $cacheId . $_SESSION['user']['status'] : $cacheId . 'guest';
		return $cacheId;
	}
	

	/**
	 * Build categories list ({CATEGORIES})
	 *
	 * @param mixed $cat_id
	 * @return string.
	 */
	protected function _getCatsTree($cat_id = false, $url_sufix = '')
	{
		// Check cache
		if ($this->cached && $this->Cache->check('category_' . $this->cacheKey)) {
			$this->categories = $this->Cache->read('category_' . $this->cacheKey);
			return;
		}
	
	
		// get mat id
		$id = (!empty($cat_id)) ? intval($cat_id) : false;
		if ($id < 1) $id = false;
		
	
		// Get current action
		if (empty($this->params[1])) $action = 'index';
		else $action = trim($this->params[1]);
		$output = '';
		
		
		// type o tree
		if (!empty($id)) {
			switch ($action) {
				case 'category':
				case 'view':
					$conditions = array('parent_id' => intval($id));
					$cats = $this->DB->select($this->module . '_categories', DB_ALL,
					array('cond' => $conditions));
					break;
				default:
					break;
			}
		}
		if (empty($cats)) {
            if ($id) {
                $current_cat = $this->DB->select($this->module . '_categories', DB_ALL, array(
                    'cond' => array(
                        'id' => $id,
                    ),
                ));
            }
			if (!empty($current_cat[0])) {
				$cats = $this->DB->select($this->module . '_categories', DB_ALL, array(
					'cond' => array(
						'parent_id' => $current_cat[0]['parent_id'],
					),
				));
			} else {
				$cats = $this->DB->select($this->module . '_categories', DB_ALL, array(
					'cond' => array(
						'parent_id = 0 OR parent_id IS NULL',
					),
				));
			}
		}
		
		
		// Build list
		if (count($cats) > 0) {
			foreach ($cats as $cat) {
				$output .= '<li>' . get_link(h($cat['title']), '/' . $this->module
                        . '/category/' . $cat['id'] . $url_sufix) . '</li>';
			}
		}
		
		
		$this->categories = '<ul class="atm-categories">' . $output . '</ul>';
		
		if ($this->cached)
			$this->Cache->write($this->categories, 'category_' . $this->cacheKey
			, array('module_' . $this->module, 'category_block'));
	}
	

	protected  function _buildBreadCrumbs($cat_id = false)
    {
		$tree = array();
		$output = '<ul>';
		
		// Check cache
		if ($this->cached && $this->Cache->check('category_tree_' . $this->cacheKey)) {
			$tree = $this->Cache->read('category_tree_' . $this->cacheKey);
			$tree = json_decode($tree, true);
			return $tree;
		} else {
			$tree = $this->DB->select($this->module . '_categories', DB_ALL);
			
			if ($this->cached)
				$this->Cache->write(json_encode($this->categories), 'category_tree_' . $this->cacheKey
				, array('module_' . $this->module, 'category_block'));
		}
		
		
		if (!empty($tree) && count($tree) > 0) {
			$output = $this->_buildBreadCrumbsNode($tree, $cat_id);
			return $output;
		}
		return '';
	}
	
	
	/**
	 * Build bread crumbs
	 * Use separator for separate links
	 * 
	 * @param array $tree
	 * @param mixed $cat_id
	 * @param mixed $parent_id
	 * @return string
	 */
	protected  function _buildBreadCrumbsNode($tree, $cat_id = false, $parent_id = false)
    {
		$output = '';
		
		
		if (empty($cat_id)) {
			$output = h($this->module_title);	
			
		} else {
			foreach ($tree as $key => $node) {
				if ($node['id'] == $cat_id  && !$parent_id) {
					$output = h($node['title']);
					if (!empty($node['parent_id']) && $node['parent_id'] != 0) {
						$output = $this->_buildBreadCrumbsNode($tree, $cat_id, $node['parent_id']) . $output;
					}
					break;
				} else if ($parent_id && $parent_id == $node['id']) {
					$output = get_link(h($node['title']), '/' . $this->module . '/category/' . $node['id']) . __('Separator');
					if (!empty($node['parent_id']) && $node['parent_id'] != 0) {
						$output = $this->_buildBreadCrumbsNode($tree, $cat_id, $node['parent_id']) . $output;
					}
					break;
				}
			}
			
			if (!$parent_id)
				$output = get_link(h($this->module_title), '/' . $this->module . '/') . __('Separator') . $output;
		}
		
		if (true && !$parent_id) $output = get_link(__('Home'), '/') . __('Separator') . $output;
		return $output;
	}
	

	/**
	 * Build categories list for select input
	 *
	 * @param array $cats
	 * @param mixed $curr_category
	 * @param mixed $id
	 * @param string $sep
	 * @return string
	 */
	protected function _buildSelector($cats, $curr_category = false, $id = false, $sep = '- ')
    {
		$out = '';
		foreach ($cats as $key => $cat) {
			$parent_id = $cat->getParent_id();
			if (($id === false && empty($parent_id))
			|| (!empty($id) && $parent_id == $id)) {
				$out .= '<option value="' . $cat->getId() . '" ' 
				. (($curr_category !== false && $curr_category == $cat->getId()) ? "selected=\"selected\"" : "") 
				. '>' . $sep . h($cat->getTitle()) . '</option>';
				unset($cats[$key]);
				$out .= $this->_buildSelector($cats, $curr_category, $cat->getId(), $sep . '- ');
			}
		}
		
		return $out;
	}
	
	
	/**
	 * Build the options of a multiple categories list. Used to attach a
	 * material to several categories at once.
	 *
	 * @param mixed $cats
	 * @param array $selected ids of the selected categories
	 * @param mixed $id
	 * @param string $sep
	 * @return string
	 */
	protected function _buildMultiSelector($cats, $selected = array(), $id = false, $sep = '- ')
	{
		$selected = array_map('strval', (array)$selected);
		$out = '';
		foreach ($cats as $key => $cat) {
			$parent_id = $cat->getParent_id();
			if (($id === false && empty($parent_id))
			|| (!empty($id) && $parent_id == $id)) {
				$out .= '<option value="' . $cat->getId() . '"'
					. (in_array(strval($cat->getId()), $selected) ? ' selected="selected"' : '')
					. '>' . $sep . h($cat->getTitle()) . '</option>' . "\n";
				unset($cats[$key]);
				$out .= $this->_buildMultiSelector($cats, $selected, $cat->getId(), $sep . '- ');
			}
		}
		
		return $out;
	}
	
	
	/**
	 * The add/edit forms have no separate "main section" field: the first
	 * selected section becomes the main one. Validation rules and the
	 * `category_id` field still use `cats_selector`, so fill it from the
	 * posted sections before validation runs.
	 *
	 * @param int $currentMainId the current main section (on update)
	 */
	protected function _setMainCategoryFromSelection($currentMainId = 0)
	{
		if (!empty($_POST['cats_selector'])) return;
		if (empty($_POST['extra_cats']) || !is_array($_POST['extra_cats'])) return;
		
		$ids = array();
		foreach ($_POST['extra_cats'] as $categoryId) {
			$categoryId = intval($categoryId);
			if ($categoryId > 0) $ids[] = $categoryId;
		}
		if (empty($ids)) return;
		
		// Keep the current main section if it is still selected.
		$currentMainId = (int)$currentMainId;
		if ($currentMainId > 0 && in_array($currentMainId, $ids, true)) {
			$_POST['cats_selector'] = $currentMainId;
			return;
		}
		
		$_POST['cats_selector'] = $ids[0];
	}
	
	
	/**
	 * Ids of the categories the material is additionally bound to
	 * (the main category lives in the `category_id` field).
	 *
	 * @param int $entityId
	 * @return array
	 */
	protected function _getBoundCategoryIds($entityId)
	{
		$entityId = (int)$entityId;
		if ($entityId < 1 || !$this->useBoundCategories) return array();
		
		atm_ensure_categories_bind_table();
		$rows = $this->DB->select('categories_bind', DB_ALL, array(
			'cond' => array('module' => $this->module, 'entity_id' => $entityId),
			'order' => 'category_id ASC',
		));
		
		$ids = array();
		if (!empty($rows)) {
			foreach ($rows as $row) {
				$ids[] = (int)$row['category_id'];
			}
		}
		return $ids;
	}
	
	
	/**
	 * Store the categories the material is additionally bound to.
	 * The main category must not be passed here.
	 *
	 * @param int $entityId
	 * @param array $categoryIds
	 */
	protected function _saveBoundCategoryIds($entityId, $categoryIds)
	{
		$entityId = (int)$entityId;
		if ($entityId < 1 || !$this->useBoundCategories) return;
		
		atm_ensure_categories_bind_table();
		$this->DB->delete('categories_bind', array(
			'module' => $this->module,
			'entity_id' => $entityId,
		));
		
		$categoryIds = array_unique(array_map('intval', (array)$categoryIds));
		foreach ($categoryIds as $categoryId) {
			if ($categoryId < 1) continue;
			$this->DB->save('categories_bind', array(
				'module' => $this->module,
				'entity_id' => $entityId,
				'category_id' => $categoryId,
			));
		}
		$this->DB->cleanSqlCache();
	}
	
	
	/**
	 * All categories of the material: the main one and the additional
	 * ones. Returns an array of entities indexed by category id.
	 *
	 * @param object $entity
	 * @return array
	 */
	protected function _getEntityCategories($entity)
	{
		$categories = array();
		$mainId = (int)$entity->getCategory_id();
		if ($entity->getCategory()) $categories[$mainId] = $entity->getCategory();
		
		if ($this->useBoundCategories) {
			$boundIds = $this->_getBoundCategoryIds($entity->getId());
			if ($boundIds) {
				$categoryModel = $this->Register['ModManager']->getModelInstance($this->module . 'Categories');
				foreach ($boundIds as $boundId) {
					if (isset($categories[$boundId])) continue;
					$category = $categoryModel->getById($boundId);
					if ($category) $categories[$boundId] = $category;
				}
			}
		}
		
		return $categories;
	}
	
	
	/**
	 * Html links to the given categories (array of entities).
	 *
	 * @param array $categories
	 * @return string
	 */
	protected function _buildCategoriesLinks($categories)
	{
		$links = array();
		foreach ($categories as $category) {
			$links[] = get_link(h($category->getTitle()), '/' . $this->module . '/category/' . $category->getId());
		}
		return implode(', ', $links);
	}
	
	
	/**
	 * Fill the `categories_links` marker of every material with the
	 * links to all its categories (main and additional), using a
	 * couple of batch queries instead of one query per record.
	 *
	 * @param array $records
	 */
	protected function _setCategoriesLinksMarker($records)
	{
		if (empty($records) || !$this->useBoundCategories) return;
		
		atm_ensure_categories_bind_table();
		$entityIds = array();
		$catsByEntity = array();
		$allCatIds = array();
		foreach ($records as $record) {
			$entityId = (int)$record->getId();
			$entityIds[] = $entityId;
			$catId = (int)$record->getCategory_id();
			$catsByEntity[$entityId] = array($catId);
			$allCatIds[$catId] = $catId;
		}
		
		$bindTable = $this->DB->getFullTableName('categories_bind');
		$module = $this->DB->escape($this->module);
		$rows = $this->DB->query("SELECT `entity_id`, `category_id` FROM `{$bindTable}`"
			. " WHERE `module` = '{$module}' AND `entity_id` IN (" . implode(', ', $entityIds) . ")"
			. " ORDER BY `category_id` ASC");
		foreach ($rows as $row) {
			$entityId = (int)$row['entity_id'];
			$catId = (int)$row['category_id'];
			if (!isset($catsByEntity[$entityId]) || in_array($catId, $catsByEntity[$entityId])) continue;
			$catsByEntity[$entityId][] = $catId;
			$allCatIds[$catId] = $catId;
		}
		
		$categoryModel = $this->Register['ModManager']->getModelInstance($this->module . 'Categories');
		$catMap = array();
		foreach ($categoryModel->getCollection(array('id' => array_values($allCatIds))) as $category) {
			$catMap[$category->getId()] = $category;
		}
		
		foreach ($records as $record) {
			$categories = array();
			foreach ($catsByEntity[(int)$record->getId()] as $catId) {
				if (!empty($catMap[$catId])) $categories[] = $catMap[$catId];
			}
			$markers = (array)$record->getAdd_markers();
			$markers['categories_links'] = $this->_buildCategoriesLinks($categories);
			$record->setAdd_markers($markers);
		}
	}


    public function showInfoMessage($message, $queryString = null, $requestIsOk = false)
	{
		// AJAX request
		if (isset($_GET['ajax'])) {
			$data = array();
			$data['redirect'] = get_url($queryString);
			if ($requestIsOk) $data = array('result' => $message);
			else $data = array('errors' => wrap_errors($message));
			return $this->showAjaxResponse($data);
		}
		
		
		header( 'Refresh: ' . $this->Register['Config']->read('redirect_delay') . '; url=http://' . get_server_host() . get_url($queryString));
		$output = $this->render('infomessagegrand.html', array('data' => array('info_message' => $message, 'error_message' => null)));
		if (function_exists('atm_webp_rewrite_html')) {
			$output = atm_webp_rewrite_html($output);
		}
		echo $output;
		die();
	}

	
	public function upload_attaches()
	{
		$this->counter = false;
		$key = ($this->module === 'forum') ? 'add_posts' : 'add_materials';
		if (!$this->ACL->turn(array($this->module, $key), false) ||
		!$this->ACL->turn(array($this->module, 'use_attaches'), false)) {
			$this->showAjaxResponse(array(
				'errors' => $this->Register['DocParser']->wrapErrors(__('Permission denied')),
				'result' => '0'
			));
		}
		
		
		$attachModel = $this->Register['ModManager']->getModelInstance($this->module . 'Attaches');
        $errors = $this->Register['Validate']->check($this->Register['action']);
		
		if (!empty($_FILES) && is_array($_FILES)) {
			$cnt = 0;
			$new_file_size = 0;
			foreach ($_FILES as $name => $file) {
				if (preg_match('#^attach\d+$#', $name)) {
					$cnt++;
					$new_file_size += $file['size'];
				}
			}
		}
		if ($cnt > Config::read('max_attaches', $this->module))
			$errors[] = sprintf(__('You can upload only %s file(s)'), Config::read('max_attaches', $this->module));
		
		if (!empty($_SESSION['user']['id'])) {
			$old_files_size = $attachModel->getUserOveralFilesSize($_SESSION['user']['id']);
			$overal_files_size = intval($new_file_size) + $old_files_size;
			$max_overal_size = Config::read('max_all_attaches_size', $this->module) * 1024 * 1024;
		} else {
			$overal_files_size = intval($new_file_size);
			$max_overal_size = Config::read('max_guest_attaches_size', $this->module) * 1024 * 1024;
		}
		
		if ($overal_files_size > $max_overal_size)
			$errors[] = sprintf(__('Max overal files size is %s Mb'), $max_overal_size / 1024 / 1024);
		

		if (!empty($errors)) $this->showAjaxResponse(array(
			'errors' => $this->Register['DocParser']->wrapErrors($errors),
			'result' => '0'
		));
		
		$attaches = downloadAtomAttaches($this->module);
		$this->showAjaxResponse($attaches);
	}
	
	
	public function get_attaches()
	{
		$this->counter = false;
		if (empty($_SESSION['user']['id'])) $this->showAjaxResponse(array());
		$user_id = $_SESSION['user']['id'];
		
		$attachModel = $this->Register['ModManager']->getModelInstance($this->module . 'Attaches');
		$attachModel->bindModel('user');
		$attaches = $attachModel->getCollection(array('user_id' => $user_id));
		if ($attaches) {
			foreach ($attaches as $k => &$attach) {
				// delete collizions
				if (!file_exists(ROOT . '/sys/files/' . $this->module . '/' . $attach->getFilename())) {
					$attach->delete();
					unset($attaches[$k]);
				} else {
					$attach = $attach->asArray();
				}
			}
		}
		$this->showAjaxResponse($attaches);
	}
	
	
	public function delete_attach($id)
	{
		$this->counter = false;
		if (!$this->ACL->turn(array($this->module, 'delete_attaches'), false)) {
			$this->showAjaxResponse(array(
				'errors' => __('Permission denied'), 
				'result' => '0'
			));
		}
			
		if (empty($_SESSION['user']['id'])) $this->showAjaxResponse(array());
		$user_id = $_SESSION['user']['id'];
		
		$attachModel = $this->Register['ModManager']->getModelInstance($this->module . 'Attaches');
		$attach = $attachModel->getById($id);
		
		$errors = '';
		if ($user_id !== $attach->getUser_id())
			$errors .= '<li>' . __('Permission denied') . '</li>';
			
		if (!empty($errors)) {
			$this->showAjaxResponse(array(
				'result' => '0', 
				'errors' => $this->Register['DocParser']->wrapErrors($errors),
			));
		}
			
		if ($attach) {
			$filename = $attach->getFilename();
			if (!empty($filename) && file_exists(ROOT . '/sys/files/' . $this->module . '/' . $filename)) {
				atm_webp_delete(ROOT . '/sys/files/' . $this->module . '/' . $filename);
				_unlink(ROOT . '/sys/files/' . $this->module . '/' . $filename);
			}
			$attach->delete();
		}
		$this->showAjaxResponse(array('result' => '1'));
	}
	
	
	
	// Функция возвращает путь к модулю
    public function getModuleURL($page = null)
	{
		$url = '/' . $this->module . '/' . $page . '/';
		$url = get_url($url);
		return $url;
	}
	
	
	
	// get path to tmp files of current module
	public function getTmpPath($file = null)
	{
		$path = '/sys/tmp/' . $this->module . '/' . (!empty($file) ? $file : '');
		return ROOT . $path;
	}
	
	
	
	public function showAjaxResponse($array)
	{
        header('Content-type: application/json');
		echo json_encode($array); die();
	}


    public function getEntryId($id)
    {
        // HLU title of the entity
        if (!is_numeric($id)) {
            $clean_url_extention = Config::read('hlu_extention');
            $id = $this->Model->getIdByHluTitle(str_replace($clean_url_extention, '', $id));
        }
        return intval($id);
    }
	
	
	protected function _getDeniSectionsCond($categoryId = null, $group = null)
	{
		$group = (!$group && !empty($_SESSION['user']['status'])) ? $_SESSION['user']['status'] : 0;
		$sectionModel = $this->Register['ModManager']->getModelInstance($this->module . 'Categories');
		
		$where = array("CONCAT(',', `no_access`, ',') NOT LIKE '%,$group,%'");
		if (intval($categoryId) > 0) {
			$where['OR'] = array(
				"CONCAT('.', `path`) LIKE '%." . intval($categoryId) . ".%'",
				'id' => intval($categoryId),
			);
		}
		
		$deni_sections = $sectionModel->getCollection($where);

		$ids = array();
		if ($deni_sections) {
			foreach ($deni_sections as $deni_section) {
				$ids[] = $deni_section->getId();
			}
		}
		$ids = (count($ids)) ? implode(', ', $ids) : 'NULL';
		
		// A material may be attached to several categories at once.
		// It stays visible while at least one of its categories is allowed.
		if ($this->useBoundCategories) {
			atm_ensure_categories_bind_table();
			$bindTable = $this->DB->getFullTableName('categories_bind');
			$module = $this->DB->escape($this->module);
			return "(`category_id` IN ({$ids}) OR `id` IN ("
				. "SELECT `entity_id` FROM `{$bindTable}`"
				. " WHERE `module` = '{$module}' AND `category_id` IN ({$ids})"
				. "))";
		}
		
		return "`category_id` IN ({$ids})";
	}
	

    protected function addToPageMetaContext($key, $value)
    {
        if (array_key_exists($key, $this->pageMetaContext))
            $this->pageMetaContext[$key] = $value;
    }


    /**
     * Build an absolute canonical URL for the current page.
     *
     * Regular pages canonicalize to themselves. Pagination and search
     * pages use separate admin settings because their preferred canonical
     * target depends on the site's SEO strategy.
     *
     * @return string
     */
    protected function getCanonicalUrl()
    {
        $Register = Register::getInstance();

        // Some modules keep the current page only in a local variable and
        // do not write it to the registry. Fall back to the request and
        // clamp the value to the page count reported by pagination().
        $page = 1;
        if (!empty($Register['page'])) {
            $page = max(1, intval($Register['page']));
        } else {
            if (isset($_GET['page'])) {
                $page = max(1, intval($_GET['page']));
            }
            if (isset($Register['pagescnt'])) {
                $page = min($page, max(1, intval($Register['pagescnt'])));
            }
        }

        $hasPageParam = isset($_GET['page']) && $_GET['page'] !== '';
        $isSearch = ($this->module === 'search');
        $isPagination = !$isSearch && ($hasPageParam || $page > 1);

        if ($isSearch) {
            $mode = Config::read('canonical_search');
            if (!in_array($mode, array('base', 'self', 'disabled'), true)) {
                $mode = 'base';
            }
        } elseif ($isPagination) {
            $mode = Config::read('canonical_pagination');
            if (!in_array($mode, array('self', 'first', 'disabled'), true)) {
                $mode = 'self';
            }
        } else {
            $mode = Config::read('canonical_regular');
            if (!in_array($mode, array('self', 'disabled'), true)) {
                $mode = 'self';
            }
        }

        if ($mode === 'disabled') {
            return '';
        }

        $origin = $this->getCanonicalOrigin();
        $path = $this->getCanonicalPath();
        if ($origin === '' || $path === '') {
            return '';
        }

        $url = $origin . $path;

        if ($isSearch && $mode === 'self') {
            $query = $this->getCanonicalQuery(array('page'));
            if ($query !== '') {
                $url .= '?' . $query;
            }
        } elseif ($isPagination && $mode === 'self' && $page > 1) {
            $url .= '?page=' . $page;
        }

        return $url;
    }


    /**
     * Absolute scheme and host for canonical links.
     *
     * @return string
     */
    protected function getCanonicalOrigin()
    {
        $host = get_server_host();
        if (!preg_match('/^(?:[a-z0-9._\-]+|\[[0-9a-f:.]+\])(?::[0-9]+)?$/i', $host)) {
            return '';
        }

        $https = (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off');
        if (!$https && !empty($_SERVER['REQUEST_SCHEME'])) {
            $https = (strtolower($_SERVER['REQUEST_SCHEME']) === 'https');
        }
        if (!$https && !empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
            $proto = strtok($_SERVER['HTTP_X_FORWARDED_PROTO'], ',');
            $https = ($proto !== false && strtolower(trim($proto)) === 'https');
        }
        if (!$https && !empty($_SERVER['SERVER_PORT']) && intval($_SERVER['SERVER_PORT']) === 443) {
            $https = true;
        }

        return ($https ? 'https://' : 'http://') . $host;
    }


    /**
     * Current request path without a query string, normalized in the same
     * way as the CMS builds front-end URLs.
     *
     * @return string
     */
    protected function getCanonicalPath()
    {
        $requestUri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '/';
        $path = parse_url($requestUri, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            $path = '/';
        }

        $path = preg_replace('/[\x00-\x1F\x7F]+/', '', $path);
        $path = preg_replace('#/+#', '/', $path);
        if ($path === '' || $path[0] !== '/') {
            $path = '/' . $path;
        }

        if ($path !== '/') {
            $path = rtrim($path, '/');
            if (!preg_match('#\.[a-z0-9]{1,10}$#i', $path)) {
                $path .= '/';
            }
        }

        return $path;
    }


    /**
     * Query string for a self-canonical search page.
     *
     * @param array $exclude
     * @return string
     */
    protected function getCanonicalQuery(array $exclude = array())
    {
        $params = $_GET;
        foreach ($exclude as $key) {
            unset($params[$key]);
        }

        if (empty($params)) {
            return '';
        }

        return http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }


    protected function getCompleteMetaTag($tag)
    {
        try {
            $tag = $this->View->parseTemplate($tag, $this->pageMetaContext);
        } catch (Exception $e) {
            $tag = preg_replace('#(\{.*\})#i', '', $tag);
        }
        return $tag;
    }
}