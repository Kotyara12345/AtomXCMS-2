<?php
/*---------------------------------------------\
|											   |
| @Author:       Andrey Brykin (Drunya)        |
| @Version:      1.4.0                         |
| @Project:      CMS                           |
| @Package       AtomX CMS                     |
| @subpackege    Sitemap generator             |
| @copyright     ©Andrey Brykin 2010-2011      |
| @last mod      2011/11/06                    |
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
if (function_exists('set_time_limit')) @set_time_limit(0);
if (function_exists('ignor_user_abort')) @ignor_user_abort();





/**
 * @author      Andrey Brykin
 * @year        2011
 * @url         http://atomx.net
 * @package     AtomX CMS
 * @subpackage  Sitemap Generator
 * @copyright   ©Andrey Brykin
 */
#[\AllowDynamicProperties]
class FpsSitemapGen {

	public $output;
	
	private $base;
	
	private $uniqUrl = array();
	
	private $DB;
	


	public function __construct($params = array()) {
        $Register = Register::getInstance();
		$this->base = $this->detectBase();
		$this->uniqUrl[] = $this->base . $this->installPath();
		$this->DB = $Register['DB'];
	}
	
	
	/**
	 * Scheme and host the current request was made with. Falls back to
	 * localhost when the host is missing.
	 *
	 * @return string e.g. "https://www.example.com"
	 */
	private function detectBase() {
		$scheme = function_exists('atm_sitemap_scheme') ? atm_sitemap_scheme() : 'http';

		if (function_exists('atm_request_origin')) {
			$origin = atm_request_origin(false);
			if ($origin !== '') {
				$host = substr($origin, strpos($origin, '://') + 3);
				// Drop the port that belongs to the other scheme.
				$host = preg_replace('/:' . ($scheme === 'https' ? '80' : '443') . '$/', '', $host);
				return $scheme . '://' . $host;
			}
		}

		$host = '';
		if (!empty($_SERVER['HTTP_HOST'])) {
			$host = (string)$_SERVER['HTTP_HOST'];
		} elseif (!empty($_SERVER['SERVER_NAME'])) {
			$host = (string)$_SERVER['SERVER_NAME'];
		}
		$host = trim($host);
		if ($host === '' || !preg_match('/^(?:[a-z0-9._\-]+|\[[0-9a-f:.]+\])(?::[0-9]+)?$/i', $host)) {
			$host = 'localhost';
		}
		// Drop the port that belongs to the other scheme.
		$host = preg_replace('/:' . ($scheme === 'https' ? '80' : '443') . '$/', '', $host);

		return $scheme . '://' . $host;
	}


	/**
	 * Install subdirectory below the document root, e.g. "/cms" or "".
	 *
	 * @return string
	 */
	private function installPath() {
		if (!defined('WWW_ROOT')) return '';
		return '/' . trim((string)WWW_ROOT, '/');
	}
	
	
	/**
	 * Whether a link already carries its own scheme and host.
	 *
	 * @param string $link
	 * @return bool
	 */
	private function isAbsoluteUrl($link) {
		return (bool)preg_match('#^(?:[a-z][a-z0-9+.\-]*:)?//#i', $link);
	}
	
	
	/**
	 * Create sitemap. Uses Data Base data and users groups rules.
	 * Sitemap urls must be allowed for search bots.
	 * 
	 */
	public function createMap($type = 'xml') {
		$this->getLinks();
		$this->finalizeLinks();
		
		
		if ($type === 'xml') {
			$this->output = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
				. '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"' . "\n"
				. 'xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"' . "\n"
				. 'xsi:schemaLocation="http://www.sitemaps.org/schemas/sitemap/0.9' . "\n"
				. 'http://www.sitemaps.org/schemas/sitemap/0.9/sitemap.xsd">' . "\n";
				
				foreach ($this->uniqUrl as $page) {
					if (!$this->isAbsoluteUrl($page)) $page = $this->base . $page;
					$this->output .= '<url>' . "\n"
						. '<loc>' . $page . '</loc>' . "\n"
						. '<changefreq>daily</changefreq>' . "\n"
						. '</url>' . "\n";
				}
				
				$this->output .= '</urlset>';
		} else {
			//TODO
		}
		
		file_put_contents(ROOT . '/sitemap.xml', $this->output);
	}
	
	
	
	/**
	 * Delete dublicate and quote chars
	 */
	private function finalizeLinks() {
		$entities = array(
			'&' => '&amp;',
			'"' => '&quot;',
			'\'' => '&apos;',
			'<' => '&lt;',
			'>' => '&gt;',
		);
	
		$this->uniqUrl = array_unique($this->uniqUrl);
		foreach ($this->uniqUrl as $key => $link) {
			//$link = trim($link, '/');
			$link = str_replace(array_keys($entities), $entities, $link);
			$this->uniqUrl[$key] = $this->isAbsoluteUrl($link)
				? $link
				: $this->base . get_url($link);
		}
	}
	
	
	
	/**
	 * build all posible links
	 */
	public function getLinks() {
		// Unique pages (Module pages)
		if (Config::read('active', 'pages')) {
			$pagesModel = new PagesModel();
			$htmlpages = $pagesModel->getCollection(array('`id` != 1', 'publish' => '1'));
			if (count($htmlpages) > 0) {
				foreach ($htmlpages as $htmlpage) {
					$this->uniqUrl[] = $pagesModel->buildUrl($htmlpage->getId());
				}
			}
		}
		
		
		// news, stat, loads, foto
		$hluex = Config::read('hlu_extention');
		$hluactive = Config::read('hlu');
		foreach (array('news', 'stat', 'loads', 'foto') as $mkey) {
			if (Config::read('active', $mkey)) {
				$this->uniqUrl[] = $mkey;
			
				$entities = $this->DB->select($mkey, DB_ALL, array());
				$entitiesc = $this->DB->select($mkey . '_categories', DB_ALL, array());

				if (count($entitiesc) > 0) {
					foreach ($entitiesc as $entityc) {
						$action = '/category/';
						$this->uniqUrl[] = $mkey . $action . $entityc['id'];
						
						
						$cntmat = 0;
						if (count($entities) > 0) {
							foreach ($entities as $val) {
								if ($val['category_id'] == $entityc['id']) {
									$cntmat++;
									continue;
								}
							}
							
							$per_page = Config::read('per_page', $mkey);
							$per_page = intval($per_page); 
							if ($per_page < 1) $per_page = 1;
							$pages = ceil($cntmat / $per_page);
							
							for ($i = 2; $i <= $pages; $i++) {
								$this->uniqUrl[] = $mkey . $action . $entityc['id'] . '?page=' . $i;
							}
						}
					}
				}
				
				if (count($entities) > 0) {
					foreach ($entities as $entity) {
							
						$hlufile = ROOT . '/sys/tmp/hlu_' . $mkey . '/' . $entity['id'] . '.dat';
						if ($mkey != 'foto' && $hluactive == 1 && file_exists($hlufile)) {
							$hludata = file_get_contents($hlufile);
							if ($hludata) $this->uniqUrl[] = $mkey . '/' . $hludata . $hluex;
						} else {
							$this->uniqUrl[] = $mkey . '/view/' . $entity['id'];
						}
					}
				}
			}
		}
		
		
		// forum
		if (Config::read('active', 'forum')) {
			$this->uniqUrl[] = '/forum/';
			
			$cats = $this->DB->select('forum_cat', DB_ALL);
			$forums = $this->DB->select('forums', DB_ALL);
			$themes = $this->DB->select('themes', DB_ALL);

			if (count($cats) > 0) {
				foreach ($cats as $cat) {
					$this->uniqUrl[] = '/forum/index/' . $cat['id'];
				}
			}
			
			if (count($forums) > 0) {
				foreach ($forums as $forum) {
					$this->uniqUrl[] = '/forum/view_forum/' . $forum['id'];
					if ($forum['themes'] > 1) {
						$per_page = Config::read('themes_per_page', 'forum');
						$per_page = intval($per_page); 
						if ($per_page < 1) $per_page = 1;
						$pages = ceil($forum['themes'] / $per_page);
						
						for ($i = 2; $i <= $pages; $i++) {
							$this->uniqUrl[] = '/forum/view_forum/' . $forum['id'] . '?page=' . $i;
						}
					}
				}
			}
			
			if (count($themes) > 0) {
				foreach ($themes as $theme) {
					$this->uniqUrl[] = '/forum/view_theme/' . $theme['id'];
					if ($theme['posts'] > 1) {
						$per_page = Config::read('posts_per_page', 'forum');
						$per_page = intval($per_page); 
						if ($per_page < 1) $per_page = 1;
						$pages = ceil($theme['posts'] / $per_page);
						
						for ($i = 2; $i <= $pages; $i++) {
							$this->uniqUrl[] = '/forum/view_theme/' . $theme['id'] . '/?page=' . $i;
						}
					}
				}
				
				$cnt = count($themes);
				$per_page = Config::read('themes_per_page', 'forum');
				$per_page = intval($per_page); 
				if ($per_page < 1) $per_page = 1;
				$pages = ceil($cnt / $per_page);
				
				$this->uniqUrl[] = '/forum/last_posts/';
				for ($i = 2; $i <= $pages; $i++) {
					$this->uniqUrl[] = '/forum/last_posts/?page=' . $i;
				}
			}
			
			// forum user_posts
			$users =  $this->DB->select('users', DB_ALL, array(
				'joins' => array(
					array(
						'type' => 'LEFT',
						'alias' => 'b',
						'table' => 'posts',
						'cond' => 'b.`id_author` = a.`id`',
					),
					array(
						'type' => 'LEFT',
						'alias' => 'c',
						'table' => 'themes',
						'cond' => 'c.`id` = b.`id_theme`',
					),
				),
				'alias' => 'a',
				'fields' => array(
					'`a`.`id` as id',
					'COUNT(DISTINCT(c.`id`)) as cnt',
				),
				'group' => '`a`.`id`',
			));

			if (count($users) > 0) {
				foreach ($users as $user) {
					$this->uniqUrl[] = '/forum/user_posts/' . $user['id'];

					$per_page = Config::read('themes_per_page', 'forum');
					$per_page = intval($per_page); 
					if ($per_page < 1) $per_page = 1;
					$pages = ceil($user['cnt'] / $per_page);
					
					for ($i = 2; $i <= $pages; $i++) {
						$this->uniqUrl[] = '/forum/user_posts/' . $user['id'] . '/?page=' . $i;
					}
				}
			}
		}
		
		
		// Users
		if (Config::read('active', 'users')) {
			$this->uniqUrl[] = '/users/';
			
			$users = $this->DB->select('users', DB_ALL);

			if (count($users) > 0) {
				foreach ($users as $user) {
					$this->uniqUrl[] = '/users/info/' . $user['id'];
				}
				
				$per_page = Config::read('users_per_page', 'users');
				$per_page = intval($per_page); 
				if ($per_page < 1) $per_page = 1;
				$pages = ceil(count($users) / $per_page);
				
				for ($i = 2; $i <= $pages; $i++) {
					$this->uniqUrl[] = '/users/index/?page=' . $i;
				}
			}
		}
	}

}


