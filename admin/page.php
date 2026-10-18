<?php
##################################################
##												##
## Author:       Andrey Brykin (Drunya)         ##
## Version:      1.6.1                          ##
## Project:      CMS                            ##
## package       CMS AtomX                      ##
## subpackege    Admin Panel module             ##
## copyright     ©Andrey Brykin 2010-2013       ##
## @last mod.     2013/07/20                    ##
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



#[\AllowDynamicProperties]
class PagesAdminController {
	
	public $Model;
	
	
	public function __construct()
	{
		$Register = Register::getInstance();
		$this->Model = $Register['ModManager']->getModelInstance('Pages');
	}
	
	
	public function move_node($params)
	{
		if (intval($params['id']) < 2) return json_encode(array('status' => '0'));
		if (intval($params['ref']) < 1) $params['ref'] = 1;
		
		
		if ($params['copy']) {
			$parent = $this->Model->getById($params['ref']);
			$entity = $this->Model->getById($params['id']);
			$path = ('.' === $entity->getPath()) ? null : $entity->getPath();
			$tree = $this->Model->getCollection(array("`path` LIKE '" . $path . $entity->getId() . ".%'"));
			
			if (!empty($tree)) $tree = $this->buildPagesTree($tree);
			else $tree = array();
			
			$entity->setSub($tree);
			$tree = array($entity);
		
			$new_id = $this->copyNode($tree, $parent);
			return json_encode(array('status' => 1, 'id' => $new_id));
			
		} else {
			$this->Model->replace($params['id'], intval($params['ref']));
			return json_encode(array('status' => 1, 'id' => $params['id']));
		}
			
		return json_encode(array('status' => '0'));
	}
	
	
	private function copyNode($tree, $parent)
	{
		foreach($tree as $k => $v) {
			$path = ('.' === $parent->getPath()) ? null : $parent->getPath();
			$data = clone $v;
			
			$data->setId(false);
			$data->setParent_id($parent->getId());
			$data->setPath($path . $parent->getId() . '.');
			
			$data->save();
			$id = $data->getId();
			
			
			$sub = $v->getSub();
			if (!empty($sub)) {
				foreach($sub as $child) {
					$this->copyNode($child, $data);
				}
			}
		}
		return !empty($id) ? $id : false;
	}
	
	
	public function rename_node($params)
	{
		if (intval($params['id']) < 2 || empty($params['title'])) return json_encode(array('status' => '0'));
		
		$entity = $this->Model->getById($params['id']);
		$entity->setName($params['title']);
		$entity->save();
		return json_encode(array('status' => 1));
	}
	
	
	public function remove_node($params)
	{
		if (intval($params['id']) < 2) return json_encode(array('status' => '0'));
		
		$this->Model->delete($params['id']);
		return json_encode(array('status' => 1));
	}
	
	
	/**
	"operation" : "create_node", 
	"id" : data.rslt.parent.attr("id").replace("node_",""), 
	"position" : data.rslt.position,
	"title" : data.rslt.name,
	"type" : data.rslt.obj.attr("rel") 
	 */
	public function create_node($params)
	{
		if (intval($params['id']) < 1 || empty($params['title'])) return json_encode(array('status' => '0'));
		
		$parent = $this->Model->getById($params['id']);
		if (!empty($parent)) {

			$errors = array();
			
            if (empty($params['title']))
				$errors[] = sprintf(__('Empty field "%s"'), __('Title'));
			
			if (!empty($errors)) {
                return json_encode(array(
                    'status' => 0,
                    'errors' => $errors,
                ));
			}
		
			$path = ('.' === $parent->getPath()) ? null : $parent->getPath();
			$template = ($parent->getTemplate()) ? $parent->getTemplate() : '';
			
			$data = array(
				'path' => $path . $parent->getId() . '.',
				'name' => $params['title'],
				'title' => $params['title'],
				'visible' => '1',
				'parent_id' => $params['id'],
				'template' => $template,
			);

			$new_entity = new PagesEntity($data);
			$new_entity->save();
			if ($new_entity->getId()) return json_encode(array(
				'status' => 1,
				'id' => $new_entity->getId(),
			));
		}
		
		return json_encode(array('status' => '0'));
	}
	
	
	/**
	 * 
	 */
	public function get_children($params)
	{
		$out = array();
		if (!isset($params['id'])) return json_encode($out);
		
		
		if (0 != $params['id'])  {
			$parent = $this->Model->getById($params['id']);
			$path = ('.' === $parent->getPath()) ? null : $parent->getPath();
			$tree = $this->Model->getCollection(array("`path` LIKE '" . $path . $parent->getId() . ".%'"));
		
		
			
			if (!empty($tree)) {
				$tree = $this->buildPagesTree($tree);
			
				foreach($tree as $k => $v){
					$out[] = array(
						"attr" => array(
							"id" => "node_".$v->getId(), 
							"rel" => (false != $v->getSub()) ? "drive" : "default",
						),
						"data" => $v->getName(),
						"state" => (false != $v->getSub() || $params['id'] == 0) ? "closed" : ""
					);
				}
			}
		} else {
			$root = array(
				"attr" => array(
					"id" => "node_1", 
					"rel" => "drive",
				),
				"data" => 'root',
				"state" => "closed"
			);
			$out = array($root);
		}
		return json_encode($out);
	}
	
	
	
	/**
	 * Get array with tree ierarhy
	 */
	private function buildPagesTree($pages, $tree = array())
	{
		if (!empty($tree)) {
			foreach ($tree as $tk => $tv) {
			
			
				$sub = array();
				foreach ($pages as $pk => $pv) {
				
				
					$path = $tv->getPath();
					if ('.' === $path) $path = '';
					if ($pv->getPath() === $path . $tv->getId() . '.') {
						unset($pages [$pk]);
						$sub[] = $pv;
					}
				}
				if (!empty($sub)) $sub = $this->buildPagesTree($pages, $sub);
				$tv->setSub($sub);
			}
			
			
		} else {
			$lowest = false;
			foreach ($pages as $pk => $pv) {
				$path = $pv->getPath();

				if (false === $lowest || substr_count($path, '.') < substr_count($lowest, '.')) {
					$lowest = $path;
				}
			}
			

			if (false !== $lowest) {
				foreach ($pages as $k => $page) {
					if ($lowest === $page->getPath()) {
						unset($pages[$k]);
						$tree[] = $page;
					}
				}

				$tree = $this->buildPagesTree($pages, $tree);
			}
		}
		
		return $tree;
	}
	
	
	public function get($params)
	{
		$entity = $this->Model->getById(intval($params['id']))->asArray();
		if (empty($entity)) return json_encode(array('status' => '0'));
		
		return json_encode(array(
			'status' => '1',
			'data' => $entity,
		));
	}
	
	
	/**
	 *
	 */
	public function save($params)
	{
        $errors = array();

        if (empty($params['title']))
            $errors[] = sprintf(__('Empty field "%s"'), __('Title'));
        if (empty($params['content']))
            $errors[] = sprintf(__('Empty field "%s"'), __('Content'));

        if (!empty($errors)) {
            return json_encode(array(
                'status' => 0,
                'errors' => $errors,
            ));
        }


		if (!empty($params['id'])) {
			$id = intval($params['id']);
			$entity = $this->Model->getById(intval($params['id']));
			if (empty($entity)) {
                return json_encode(array(
                    'status' => '0',
                    'errors' => array(__('Record not found'))
                ));
            }

			$entity->setName($params['name']);
			$entity->setTitle($params['title']);
			$entity->setUrl($params['url']);
			$entity->setPosition($params['position'] ? $params['position'] : 0);
			$entity->setVisible(!empty($params['visible']) ? '1' : '0');
			$entity->setMeta_title($params['meta_title']);
			$entity->setMeta_keywords($params['meta_keywords']);
			$entity->setMeta_description($params['meta_description']);
			$entity->setPublish(!empty($params['publish']) ? '1' : '0');
			$entity->setTemplate($params['template']);
			$entity->setContent($params['content']);
			$entity->save();
			
			
			
		} else {
			$data = array(
				'name' => $params['name'],
				'title' => $params['title'],
				'template' => $params['template'],
				'visible' => (!empty($params['visible'])) ? '1' : '0',
				'position' => $params['position'],
				'meta_title' => $params['meta_title'],
				'meta_keywords' => $params['meta_keywords'],
				'meta_description' => $params['meta_description'],
				'content' => $params['content'],
				'url' => $params['url'],
				'publish' => (!empty($params['publish'])) ? '1' : '0',
				'template' => $params['template'],
			);
			$id = $this->Model->add($data);

			if (empty($id)) {
                return json_encode(array(
                    'status' => '0',
                    'errors' => array(__('Some error occurred'))
                ));
            }
			return json_encode(array('status' => '1', 'id' => $id));
		}
		

		return json_encode(array('status' => '1', 'id' => $id));
	}
}



$jstree = new PagesAdminController;
if(!empty($_REQUEST['operation']) && strpos($_REQUEST['operation'], '_') !== 0 && method_exists($jstree, $_REQUEST['operation'])) {
	header("HTTP/1.0 200 OK");
	header('Content-type: application/json; charset=utf-8');
	header("Cache-Control: no-cache, must-revalidate");
	header("Expires: Mon, 26 Jul 1997 05:00:00 GMT");
	header("Pragma: no-cache");
	echo $jstree->{$_REQUEST["operation"]}($_REQUEST);
	die();
}



$Register = Register::getInstance();
$FpsDB = $Register['DB'];
$pageTitle = __('Pages editor');
$pageNav = $pageTitle;
$pageNavr = __('Pages') . ' &raquo; [' . __('Editing') . ']';



include_once ROOT . '/admin/template/header.php';
?>


<div class="white">
	<div class="pages-tree">
		<div class="title"><?php echo __('Pages') ?></div>
		<div class="wrapper" style="height:861px;">
			<div class="tree-wrapper">
				<div id="pageTree"></div>
			</div>
		</div>
		<!--<div style="width:100%;">&nbsp;</div>-->
	</div>
	
	

	<div style="display:none;" class="ajax-wrapper" id="ajax-loader"><div class="loader"></div></div>
	<form id="FpsForm" style="opacity:1;" method="POST" action="">


	
	<div class="list pages-form">
		<div class="title"><?php echo __('Pages editor') ?></div>
		<div class="level1">
			<div class="items">
				<div class="setting-item">
					<div class="left">
						<?php echo __('Page') ?>
					</div>
					<div class="right">
						<input type="text" name="name" value="">
						<input type="hidden" name="id" value="">
					</div>
					<div class="clear"></div>
				</div>
				<div class="setting-item">
					<div class="left">
						<?php echo __('Title') ?>
					</div>
					<div class="right">
						<input type="text" name="title" value="">
						<input type="hidden" name="id" value="">
					</div>
					<div class="clear"></div>
				</div>
				<div class="setting-item">
					<div class="left">
						URL
					</div>
					<div class="right">
						<input type="text" name="url" value="">
					</div>
					<div class="clear"></div>
				</div>
				<div class="setting-item">
					<div class="left">
						<?php echo __('In new window') ?>
					</div>
					<div class="right">
						<input id="checkbox1" type="checkbox" name="visible" value="1" checked="checked">
						<label for="checkbox1"></label>
					</div>
					<div class="clear"></div>
				</div>
				<div class="setting-item">
					<div class="left">
						<?php echo __('Position in the menu') ?>
					</div>
					<div class="right">
						<input style="width:40px;" type="text" name="position" value="">
					</div>
					<div class="clear"></div>
				</div>
				<div class="setting-item">
					<div class="left">
						Meta title
					</div>
					<div class="right">
						<input type="text" name="meta_title" value="">
					</div>
					<div class="clear"></div>
				</div>
				<div class="setting-item">
					<div class="left">
						Meta keywords
					</div>
					<div class="right">
						<textarea style="height:50px;" name="meta_keywords"></textarea>
					</div>
					<div class="clear"></div>
				</div>
				<div class="setting-item">
					<div class="left">
						Meta description
					</div>
					<div class="right">
						<textarea style="height:50px;" name="meta_description"></textarea>
					</div>
					<div class="clear"></div>
				</div>
				<div class="setting-item">
					<div class="left">
						<?php echo __('Status') ?>
						<span class="comment"><?php echo __('Enable/Disable') ?></span>
					</div>
					<div class="right">
						<input id="checkbox2" type="checkbox" name="publish" value="1" checked="checked">
						<label for="checkbox2"></label>
					</div>
					<div class="clear"></div>
				</div>
				<div class="setting-item">
					<div class="left">
						<?php echo __('Template') ?>
					</div>
					<div class="right">
						<input type="text" name="template" value="">
					</div>
					<div class="clear"></div>
				</div>
				<div class="setting-item">
					<div class="left">
						<?php echo __('Template marker') ?>
						<span class="comment"><?php echo __('for use in the templates') ?></span>
					</div>
					<div class="right">
						<input style="width:60px; text-align:center;" disabled="true" type="text" name="dinamic_tag" value="">
					</div>
					<div class="clear"></div>
				</div>
				<div class="setting-item">
					<div class="center">
						
						<textarea contenteditable="true" style="min-height:300px;" id="mainTextarea" name="content"></textarea>
					</div>
					<div class="clear"></div>
				</div>
				<div class="setting-item">
					<div class="left">
					</div>
					<div class="right">
						<input class="save-button" type="submit" name="send" value="<?php echo __('Save') ?>" />
					</div>
					<div class="clear"></div>
				</div>
			</div>
		</div>
	</div>
	</form>
	<div class="clear"></div>

</div>




<script>

$(document).ready(function(){
	redactor_ = $('#mainTextarea').redactor({
		//css: '/template/vrc/css/style_redactor.css', 
		iframe: true,
		cleanup: false,
		autoclear: false, 
		autoformat: false, 
		convert_links: false, 
		convertLinks: false, 
		convertDivs: false, 
		init_clear: false,
		remove_styles: false,
		remove_classes: false,
		imageGetJson: '/img_uploader.php',
		imageUpload: '/img_uploader.php',
		fileUpload: '/img_uploader.php',
		autoresize: true,
		deniedTags: [],
		removeEmptyTags: false,
		phpTags: true,
		uploadCrossDomain: true,
		visual: true
	});
	
	
	
	
	$.validator.addMethod('chars', function(value, element){
		return value.match(/[ \da-z\-_]*/i);
	}, "Don't use special chars");
	$("#FpsForm").validate({
		submitHandler: function(){
			submitForm();
		},
		rules: {
			name: {
				required: true,
				chars: true,
				maxlength: 250,
				minlength: 1,
			},
			title: {
				maxlength: 1000,
				minlength: 1,
			},
			url: {
				chars: true,
				maxlength: 250,
				minlength: 1,
			},
			meta_title: {
				maxlength: 250,
				minlength: 1,
			},
			meta_keywords: {
				maxlength: 250,
				minlength: 1,
			},
			meta_description: {
				maxlength: 250,
				minlength: 1,
				chars: true
			},
			position: {
				maxlength: 11,
				minlength: 1,
				number: true
			},
			visible: {
				max: 1,
				number: true
			},
			publish: {
				max: 1,
				number: true
			},
			template: {
				maxlength: 50,
				minlength: 1,
				chars: true
			},
			content: {
				maxlength: 50000,
				minlength: 1
			},
		}
	});
});





$(function () {
	// The server still answers in the legacy jsTree 1.x format
	// ({attr:{id,rel}, data:"title", state:"closed"}); convert it to jsTree 3 nodes.
	function convertNodes(items) {
		if (!items || !items.length) return [];
		return $.map(items, function (item) {
			var node = {
				id: (item.attr && item.attr.id) ? String(item.attr.id).replace('node_', '') : undefined,
				text: item.data,
				type: (item.attr && item.attr.rel) ? item.attr.rel : 'default'
			};
			// "closed" means the node may have children - let jsTree load them on demand
			if (item.state === 'closed') node.children = true;
			return node;
		});
	}

	function treeInstance() {
		return $("#pageTree").jstree(true);
	}

	function refreshTree(nodeId) {
		var inst = treeInstance();
		if (!inst) return;
		if (!nodeId || nodeId === '#') inst.refresh();
		else inst.refresh(nodeId);
	}

$("#pageTree")
	.jstree({
		core: {
			multiple: false,
			check_callback: true,
			data: function (node, cb) {
				$.ajax({
					url: "page.php",
					type: "POST",
					dataType: "json",
					data: {
						operation: "get_children",
						id: (node.id === "#") ? 0 : node.id
					}
				}).done(function (data) {
					cb(convertNodes(data));
				}).fail(function () {
					cb([]);
				});
			}
		},
		plugins: ["dnd", "types", "contextmenu", "state"],
		types: {
			"#": { valid_children: ["drive", "folder", "default"] },
			default: { valid_children: ["drive", "folder", "default"] },
			folder: { valid_children: ["drive", "folder", "default"] },
			drive: { valid_children: ["drive", "folder", "default"] }
		},
		contextmenu: {
			items: function ($node) {
				var items = $.jstree.defaults.contextmenu.items.call(this, $node);
				delete items.ccp;
				return items;
			}
		},
		state: { key: "atomx_pages_tree" }
	})
	.bind("ready.jstree", function () {
		// Same default branch as the legacy tree: open the root and the first pages
		var inst = treeInstance();
		if (!inst) return;
		inst.open_node("1", function () {
			$.each(["2", "3"], function (i, id) {
				if (inst.get_node(id)) inst.open_node(id);
			});
		});
	})
	.bind("create_node.jstree", function (e, data) {
		$.post(
			"page.php",
			{
				operation: "create_node",
				id: (data.parent === "#") ? 1 : data.parent,
				position: data.position,
				title: data.node.text,
				type: data.node.type
			},
			function (r) {
				var inst = treeInstance();
				if (r.status) {
					if (inst && r.id) inst.set_id(data.node, String(r.id));
				} else {
					if (r.errors && r.errors.length) {
						alert(r.errors.join("\n"));
					}
					refreshTree("#");
				}
			}
		);
	})
	.bind("rename_node.jstree", function (e, data) {
		$.post(
			"page.php",
			{
				operation: "rename_node",
				id: data.node.id,
				title: data.text
			},
			function (r) {
				var inst = treeInstance();
				if (!r.status) {
					if (inst && typeof data.old !== 'undefined') inst.set_text(data.node, data.old);
					else refreshTree(data.node.parent);
				}
			}
		);
	})
	.bind("delete_node.jstree", function (e, data) {
		$.post(
			"page.php",
			{
				operation: "remove_node",
				id: data.node.id
			},
			function (r) {
				if (!r.status) refreshTree(data.parent);
			}
		);
	})
	.bind("move_node.jstree", function (e, data) {
		$.post(
			"page.php",
			{
				operation: "move_node",
				id: data.node.id,
				ref: (data.parent === "#") ? 1 : data.parent,
				position: data.position,
				title: data.node.text,
				copy: 0
			},
			function (r) {
				var inst = treeInstance();
				if (!r.status) {
					refreshTree("#");
				} else if (inst && r.id && String(r.id) !== String(data.node.id)) {
					inst.set_id(data.node, String(r.id));
				}
			}
		);
	})
	.bind("select_node.jstree", function (event, data) {
		fillForm(data.node.id);
	});

});




/**
 * Get entity and fill form fields
 */
function fillForm(id){
	FpsLib.showLoader();
	var form = $('#FpsForm');
	// Clear form fields. After it, we can create new page
	if (id < 2) {
		$(form).find('input[type="text"], input[type="hidden"]').each(function(){
			$(this).val('');
		});
		$(form).find('input[name="visible"]').prop('checked', false);
		$(form).find('textarea[name="content"]').val('');
		$('div.redactor_editor').html('');
		
		FpsLib.hideLoader();
		return;
	}
	
	
	
	
	$.get('page.php?operation=get&id='+id, function(data){
		var status = data.status; data = data.data;
		$(form).find('input[name="name"]').val(data.name);
		$(form).find('input[name="title"]').val(data.title);
		$(form).find('input[name="id"]').val(data.id);
		$(form).find('input[name="url"]').val(data.url);
		
		if (data.visible == 1)
			$(form).find('input[name="visible"]').prop('checked', true);
		else
			$(form).find('input[name="visible"]').prop('checked', false);
			
		if (data.publish == 1)
			$(form).find('input[name="publish"]').prop('checked', true);
		else
			$(form).find('input[name="publish"]').prop('checked', false);
			
		$(form).find('input[name="position"]').val(data.position);
		$(form).find('input[name="meta_title"]').val(data.meta_title);
		$(form).find('textarea[name="meta_keywords"]').html(data.meta_keywords);
		$(form).find('textarea[name="meta_description"]').html(data.meta_description);
		$(form).find('input[name="template"]').val(data.template);
		$(form).find('input[name="dinamic_tag"]').val('[~ '+data.id+' ~]');
		
		
		if (data.content.match(/<script[^>]*>[\S\s]*<\/script>/gi)) {
			//$('#mainTextarea').data("redactor").opts;
			if ($('#mainTextarea').data("redactor").opts.visual)
				$('#mainTextarea').data("redactor").toggle();
			$(form).find('textarea[name="content"]').val(data.content);
		} else {
			$(form).find('textarea[name="content"]').val(data.content);
			$('#mainTextarea').data("redactor").setCode(data.content);
			//$('div.redactor_editor').html(data.content);
		}
		
		 
		FpsLib.hideLoader();
	});
}

/**
 * Save changes or create page
 */
function submitForm(){
	FpsLib.showLoader();
	var form = $('#FpsForm');
	var id = $(form).find('input[name="id"]').val();
	$.post(
		'page.php?operation=save&id='+id, 
		$(form).serialize(), 
		function(data) {
			
			
            if (data.errors && data.errors.length) {
                alert(data.errors.join("\n"));
            } else {
				var tree = $("#pageTree").jstree(true);
				if (tree) tree.refresh();
				if (typeof data.id != 'undefined') setTimeout('fillForm('+data.id+')', 1000);
			}
			
			FpsLib.hideLoader();
		}
	);
}

</script>



<ul class="markers">
	<li><div class="global-marks">{{ content }}</div> - Основной контент страницы</li>
	<li><div class="global-marks">{{ title }}</div> - Заголовок страницы</li>
	<li><div class="global-marks">{{ description }}</div> - Содержание Мета-тега description</li>
	<li><div class="global-marks">{{ fps_wday }}</div> - День кратко</li>
	<li><div class="global-marks">{{ fps_date }}</div> - Дата</li>
	<li><div class="global-marks">{{ fps_time }}</div> - Время</li>
	<li><div class="global-marks">{{ headmenu }}</div> - Верхнее меню</li>
	<li><div class="global-marks">{{ fps_user_name }}</div> - Ник текущего пользователя (Для не авторизованного - Гость)</li>
	<li><div class="global-marks">{{ fps_user_group }}</div> - Группа текущего пользователя (Для не авторизованного - Гости)</li>
	<li><div class="global-marks">{{ categories }}</div> - Список категорий раздела</li>
	<li><div class="global-marks">{{ counter }}</div> - Встроенный счетчик посещаемости AtomX</li>
	<li><div class="global-marks">{{ fps_year }}</div> - Год</li>
	<li><div class="global-marks">{{ powered_by }}</div> - AtomX CMS</li>
	<li><div class="global-marks">{{ comments }}</div> - Комментарии к материалу и форма добавления комментариев <b>(если предусмотренно)</b></li>
	<li><div class="global-marks">{{ personal_page_link }}</div> - URL на свою персональную страницу или на страницу регистрации, если не авторизован</li>
</ul>




<?php
include_once 'template/footer.php';