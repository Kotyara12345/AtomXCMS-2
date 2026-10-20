<?php
##################################################
##												##
## @Author:       Andrey Brykin (Drunya)        ##
## @Version:      1.6.1                         ##
## @Project:      CMS                           ##
## @package       CMS AtomX                     ##
## @subpackege    Admin Panel module            ##
## @copyright     ©Andrey Brykin 2010-2013      ##
## @last mod.     2013/06/15                    ##
##################################################


##################################################
##												##
## any partial or not partial extension         ##
## CMS AtomX,without the consent of the         ##
## author, is illegal                           ##
##################################################
## Любое распространение                        ##
## CMS AtomX или ее частей,                     ##
## без согласия автора, является не законным    ##
##################################################


include_once '../sys/boot.php';
include_once ROOT . '/admin/inc/adm_boot.php';
$pageTitle = __('List of materials');
$Register = Register::getInstance();


$allowed_mods = $Register['ModManager']->getAllowedModules('materialsList');
$allowed_actions = array('edit', 'delete', 'index', 'premoder');


if (empty($_GET['m']) || !in_array($_GET['m'], $allowed_mods)) redirect('/admin/');
$module = $_GET['m'];

$action = (!empty($_GET['ac'])) ? $_GET['ac'] : 'index';
if (empty($action) && !in_array($action, $allowed_actions)) $action = 'index';


$Controll = new MaterialsList;
list($output, $pages) = $Controll->{$action}($module);






#[\AllowDynamicProperties]
class MaterialsList {
	public $pageTitle;
	
	
	public function __construct() {
		$this->pageTitle = __('List of materials');
	}


	public function index($module) {
		$output = '';
		$Register = Register::getInstance();
		$model = $Register['ModManager']->getModelInstance($_GET['m']);
		
		$where = (!empty($_GET['premoder'])) ? array('premoder' => 'nochecked') : array();

		$total = $model->getTotal(array('cond' => $where));
		list ($pages, $page) = pagination($total, 20, '/admin/materials_list.php?m=' . $module
            . (!empty($_GET['order']) ? '&order=' . $_GET['order'] : '')
            . (!empty($_GET['asc']) ? '&asc=1' : ''));

		
		$model->bindModel('author');
		$materials = $model->getCollection($where, array(
			'page' => $page,
			'limit' => 20,
			'order' => $model->getOrderParam(),
		));

		
		if (empty($materials)) 
			$output = '<div class="setting-item"><div class="left"><b>' 
			. __('Materials not found') . '</b></div><div class="clear"></div></div>';


		foreach ($materials as $mat) {
			$output .= '<div class="setting-item"><div class="left">';
			$output .= '<a style="font-weight:bold; margin-bottom:5px;" href="' 
				. get_url('/admin/materials_list.php?m=' . $module . '&ac=edit&id=' 
				. $mat->getId()) . '">' . h($mat->getTitle()) . '</a>';
			$output .= '<br />(' . $mat->getAuthor()->getName() . ')';
			$output .= '</div><div style="width:60%;" class="right">';
			
			
			if ($module == 'foto') {
				$AtmFoto = h(preg_replace('#[^\w\d ]+#ui', ' ', $mat->getTitle()));
				$AtmFoto = '<img alt="' . $AtmFoto . '" src="' . WWW_ROOT . '/sys/files/' . $module . '/preview/' 
					. $mat->getFilename() . '" width="150px" />';
				$AtmFoto = '<a target="_blank" href="' . WWW_ROOT . '/sys/files/' . $module . '/full/' 
					. $mat->getFilename() . '">' . $AtmFoto . '</a>';
				
				$output .= $AtmFoto;

			} else {
				$output .= h(mb_substr($mat->getMain(), 0, 500));
			}
			$output .= '<br /><span class="comment">' . AtmDateTime::getSimpleDate($mat->getDate()) . '</span>';
			
			
			// rejected - отвергнуто
			// confirmed - подтвердил
			// nochecked - не проверено
			if (!empty($_GET['premoder'])) {
				$output .= '</div><div class="unbordered-buttons">
				<a href="' . get_url('/admin/materials_list.php?m=' . $module . '&ac=premoder&status=rejected&id=' . $mat->getId()) . '" class="off"></a>' .
				'<a href="' . get_url('/admin/materials_list.php?m=' . $module . '&ac=premoder&status=confirmed&id=' . $mat->getId()) . '" class="on"></a>
				</div><div class="clear"></div></div>';
			} else {
				$output .= '</div><div class="unbordered-buttons">
				<a href="' . get_url('/admin/materials_list.php?m=' . $module . '&ac=delete&id=' . $mat->getId()) . '" class="delete"></a>' .
				'<a href="' . get_url('/admin/materials_list.php?m=' . $module . '&ac=edit&id=' . $mat->getId()) . '" class="edit"></a>
				</div><div class="clear"></div></div>';
			}
		}
		
		return array($output, $pages);
	}
	
	
	function premoder($module){
		$Register = Register::getInstance();
		$Model = $Register['ModManager']->getModelInstance($module);
		$entity = $Model->getById(intval($_GET['id']));
		if (!empty($entity)) {
			
			$status = $_GET['status'];
			if (!in_array($status, array('rejected', 'confirmed'))) $status = 'nochecked';
			
			$entity->setPremoder($status);
			$entity->save();
			$_SESSION['message'] = __('Saved');
			
			
			//clean cache
			$Cache = new Cache;
			$Cache->clean(CACHE_MATCHING_ANY_TAG, array('module_' . $module));
		} else {
			$_SESSION['errors'] = __('Some error occurred');
		}
		redirect('/admin/materials_list.php?m=' . $module . '&premoder=1');
	}
	
	
	public function delete($module) {
		$Register = Register::getInstance();
		
		$model = $Register['ModManager']->getModelInstance($_GET['m']);
		$id = intval($_GET['id']);
		$entity = $model->getById($id);
		
		if (!empty($entity)) {
			if ($module == 'foto') {
				_unlink(ROOT . '/sys/files/' . $module . '/full/' . $entity->getFilename());
				_unlink(ROOT . '/sys/files/' . $module . '/preview/' . $entity->getFilename());
				$entity->delete();
				
			} else {
				$entity->delete();
			}
			
			$_SESSION['message'] = __('Material has been delete');
		}
		
		redirect('/admin/materials_list.php?m=' . $module);
	}
	
	
	public function edit($module) {
		$this->pageTitle .= ' - ' . __('Edit');
	
		$output = '';
		$error = '';
		$Register = Register::getInstance();
		$model = $Register['ModManager']->getModelInstance($_GET['m']);

		
		$id = intval($_GET['id']);
		$entity = $model->getById($id);
		if (empty($entity)) redirect('/admin/materials_list.php?m=' . $module);
		
		$selectedCats = array();
		$title = $entity->getTitle();
		$main = $entity->getMain();
		$email = $entity->getSourse_email();
		
		// only news and articles have sections; the multi categories
		// support can be switched off in the module settings
		$supportsCats = in_array($module, array('news', 'stat'), true);
		$useMultiCats = $supportsCats && atm_use_multicategories($module);
		$singleCatId = 0;
		
		
		if (!empty($_POST)) {
			$selectedCats = array();
			if (!empty($_POST['extra_cats']) && is_array($_POST['extra_cats'])) {
				foreach ($_POST['extra_cats'] as $catId) {
					$catId = intval($catId);
					if ($catId > 0) $selectedCats[$catId] = $catId;
				}
			}
			$selectedCats = array_values($selectedCats);
			
			$title = isset($_POST['title']) ? $_POST['title'] : $title;
			$main = isset($_POST['main']) ? $_POST['main'] : $main;
			$email = isset($_POST['email']) ? $_POST['email'] : $email;
			
			if ($supportsCats) {
				if ($useMultiCats) {
					if (empty($selectedCats)) $error = __('Select at least one section');
				} else {
					$singleCatId = isset($_POST['category_id']) ? intval($_POST['category_id']) : 0;
					if ($singleCatId < 1) $error = __('Select at least one section');
				}
			}
			
			if (empty($error)) {
				$entity->setTitle($title);
				$entity->setMain($main);
				$entity->setSourse_email($email);
				
				if ($useMultiCats) {
					// keep the current main section if it is still selected
					$mainCatId = (int)$entity->getCategory_id();
					if (!in_array($mainCatId, $selectedCats, true)) $mainCatId = $selectedCats[0];
					$entity->setCategory_id($mainCatId);
				} elseif ($supportsCats) {
					$entity->setCategory_id($singleCatId);
				}
				$entity->save();
				
				if ($useMultiCats) {
					$this->saveBoundCategories($module, $id, array_values(array_diff($selectedCats, array($mainCatId))));
				}
				
				$Cache = new Cache;
				$Cache->clean(CACHE_MATCHING_ANY_TAG, array('module_' . $module));
				$Register['DB']->cleanSqlCache();
				
				$_SESSION['message'] = __('Saved');
				redirect('/admin/materials_list.php?m=' . $module);
			}
		}
		
		$catsOptions = '';
		if ($supportsCats) {
			$categories = $Register['ModManager']->getModelInstance($module . 'Categories')->getCollection();
		}
		if ($useMultiCats) {
			if (empty($selectedCats)) {
				$selectedCats = $this->getBoundCategories($module, $id);
				$selectedCats[] = (int)$entity->getCategory_id();
			}
			$selectedCats = array_values(array_unique(array_map('intval', $selectedCats)));
			$catsOptions = $this->buildCategoryOptions($categories, $selectedCats);
		} elseif ($supportsCats) {
			if (empty($_POST)) $singleCatId = (int)$entity->getCategory_id();
			$catsOptions = $this->buildCategoryOptions($categories, array($singleCatId));
		}
		
		if (!empty($error)) {
			$output .= '<div class="setting-item"><div class="left"></div><div class="right">'
				. '<span style="color:#c00;font-weight:bold;">' . h($error) . '</span>'
				. '</div><div class="clear"></div></div>';
		}
		
		$output .= '
		<div class="setting-item"><div class="left">
			' . __('Title') . '
		</div><div class="right">
			<input type="text" name="title" value="'.h($title).'" />
		</div><div class="clear"></div></div>
		<div class="setting-item"><div class="left">
			' . __('Text of material') . '
		</div><div class="right">
			<textarea id="editor" style="height:300px;width:100%;" name="main">'.h($main).'</textarea>
		</div><div class="clear"></div></div>';
		
		if ($useMultiCats) {
			$output .= '<div class="setting-item"><div class="left">
			' . __('Sections') . '
		</div><div class="right">
			<select name="extra_cats[]" multiple="multiple" size="10" style="min-width:320px;height:auto;padding:4px;">' . $catsOptions . '</select>
			<div style="margin-top:4px;font-size:11px;color:#777;">' . __('Multi select hint') . '</div>
		</div><div class="clear"></div></div>
		<style>select[name="extra_cats[]"] option:checked {background: linear-gradient(#3875d7, #3875d7);color: #fff;}</style>';
		} elseif ($supportsCats) {
			$output .= '<div class="setting-item"><div class="left">
			' . __('Main section') . '
		</div><div class="right">
			<select name="category_id" style="width:320px;height:auto;padding:4px;">' . $catsOptions . '</select>
		</div><div class="clear"></div></div>';
		}
		
		$output .= '<div class="setting-item"><div class="left">
			' . __('Email') . '
		</div><div class="right">
			<input type="text" name="email" value="'.h($email).'" />
		</div><div class="clear"></div></div>
		<div class="setting-item">
			<div class="left">
			</div>
			<div class="right">
				<input class="save-button" type="submit" name="send" value="' . __('Save') . '" />
			</div>
			<div class="clear"></div>
		</div>
		<link rel="stylesheet" href="' . WWW_ROOT . '/sys/plugins/wysibb/public/wbbtheme.css" />
		<script src="' . WWW_ROOT . '/sys/plugins/wysibb/public/jquery.wysibb.min.js"></script>
		<script src="' . WWW_ROOT . '/sys/plugins/wysibb/public/fapos2.js"></script>
		<script>
		$(document).ready(function() {
			$("#editor").wysibb();
		});
		</script>';
		
		
		return array($output, '');
	}
	
	
	/**
	 * Categories tree of the module as a <select> options list.
	 */
	private function buildCategoryOptions($cats, $selected = array(), $parentId = 0, $level = 0)
	{
		$out = '';
		$selected = array_map('intval', (array)$selected);
		foreach ($cats as $key => $cat) {
			$catParent = $cat->getParent_id();
			$matches = ($parentId === 0) ? empty($catParent) : ($catParent == $parentId);
			if ($matches) {
				$out .= '<option value="' . intval($cat->getId()) . '"'
					. (in_array((int)$cat->getId(), $selected) ? ' selected="selected"' : '')
					. '>' . str_repeat('- ', $level) . h($cat->getTitle()) . '</option>' . "\n";
				unset($cats[$key]);
				$out .= $this->buildCategoryOptions($cats, $selected, $cat->getId(), $level + 1);
			}
		}
		return $out;
	}
	
	
	/**
	 * Additional categories of the material.
	 */
	private function getBoundCategories($module, $entityId)
	{
		$Register = Register::getInstance();
		atm_ensure_categories_bind_table();
		$rows = $Register['DB']->select('categories_bind', DB_ALL, array(
			'cond' => array('module' => $module, 'entity_id' => (int)$entityId),
			'order' => 'category_id ASC',
		));
		
		$ids = array();
		if (!empty($rows)) {
			foreach ($rows as $row) $ids[] = (int)$row['category_id'];
		}
		return $ids;
	}
	
	
	/**
	 * Store the additional categories of the material.
	 */
	private function saveBoundCategories($module, $entityId, $categoryIds)
	{
		$Register = Register::getInstance();
		atm_ensure_categories_bind_table();
		$Register['DB']->delete('categories_bind', array(
			'module' => $module,
			'entity_id' => (int)$entityId,
		));
		
		$categoryIds = array_unique(array_map('intval', (array)$categoryIds));
		foreach ($categoryIds as $categoryId) {
			if ($categoryId < 1) continue;
			$Register['DB']->save('categories_bind', array(
				'module' => $module,
				'entity_id' => (int)$entityId,
				'category_id' => $categoryId,
			));
		}
	}
}




$pageNav = $Controll->pageTitle;
$pageNavr = '';
include_once ROOT . '/admin/template/header.php';

?>


<form method="POST" action="" enctype="multipart/form-data">
<div class="list">
	<div class="title"><?php echo $pageNav; ?></div>
    <div class="add-cat-butt">
        <select onChange="window.location.href='/admin/materials_list.php?m=<?php echo $_GET['m'] ?>&order='+this.value;">
            <option><?php echo __('Ordering') ?></option>
            <option value="views"><?php echo __('Views') ?> (↓)</option>
            <option value="views&asc=1"><?php echo __('Views') ?> (↑)</option>
            <option value="comments"><?php echo __('Comments') ?> (↓)</option>
            <option value="comments&asc=1"><?php echo __('Comments') ?> (↑)</option>
            <option value="date"><?php echo __('Date') ?> (↓)</option>
            <option value="date&asc=1"><?php echo __('Date') ?> (↑)</option>
        </select>
    </div>
	<div class="level1">
		<div class="head">
			<div class="title settings"><?php echo __('Title') ?></div>
			<div class="title-r"><?php echo __('Text of material') ?></div>
			<div class="clear"></div>
		</div>
		<div class="items">
			<?php echo $output; ?>

		</div>
	</div>
</div>
<?php if (!empty($pages)) { ?><div class="pagination"><?php echo $pages ?></div><?php } ?>
</form>



<?php include_once 'template/footer.php'; ?>
