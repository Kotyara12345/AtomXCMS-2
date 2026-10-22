<!DOCTYPE html>
<html lang="ru">
<head>
	<meta charset="UTF-8" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<title><?php echo $pageTitle; ?></title>
	<meta name="description" content="" />
	<meta name="keywords" content="" />
	<script src="<?php echo WWW_ROOT ?>/sys/js/jquery.js"></script>
	
	<script src="<?php echo WWW_ROOT ?>/sys/js/jquery.validate.js"></script>
	<script src="<?php echo WWW_ROOT ?>/sys/js/jquery-ui.min.js"></script>
	<script src="<?php echo WWW_ROOT ?>/admin/js/drunya.lib.js"></script>

	<script src="<?php echo WWW_ROOT ?>/sys/js/redactor/redactor.js"></script>
	<link rel="stylesheet" href="<?php echo WWW_ROOT ?>/sys/js/redactor/css/redactor.css" />
	
	<script src="<?php echo WWW_ROOT ?>/sys/js/jquery.cookie.js"></script>
	<script src="<?php echo WWW_ROOT ?>/sys/js/jquery.hotkeys.js"></script>
	<script src="<?php echo WWW_ROOT ?>/sys/js/jstree/jstree.min.js"></script>
	<link rel="stylesheet" href="<?php echo WWW_ROOT ?>/sys/js/jstree/themes/default/style.min.css" />
	
	<script src="<?php echo WWW_ROOT ?>/sys/js/fancybox/jquery.fancybox.js"></script>
	<link rel="stylesheet" href="<?php echo WWW_ROOT ?>/sys/js/fancybox/css/fancy.css" />
	
	<link rel="stylesheet" href="<?php echo WWW_ROOT ?>/admin/template/css/style.css?v=<?php echo filemtime(ROOT . '/admin/template/css/style.css'); ?>" />
	<!--<link rel="stylesheet" href="<?php echo WWW_ROOT ?>/admin/template/css/font-awesome.min.css" />-->
	
	<script>
	
	
	$(document).ready(function(){
		$("a.gallery").attr("data-fancybox", "gallery");
		//setTimeout(function(){
		//	$('#overlay').height($('#wrapper').height());
		//}, 2000);
		if (window.innerWidth > 1100) {
			$('#wrapper').css('min-height', ($('body').height() - 136)); // 136 - top+bottom wrapper padding
			$('body').height($('#wrapper').outerHeight() - 55); // 55 - side menu top indent
		}
		
		
		
		// get size to current scroll position
		function getScrollTop() {
				   var scrOfY = 0;
				   if( typeof( window.pageYOffset ) == "number" ) {
						   //Netscape compliant
						   scrOfY = window.pageYOffset;
				   } else if( document.body
				   && ( document.body.scrollLeft
				   || document.body.scrollTop ) ) {
						   //DOM compliant
						   scrOfY = document.body.scrollTop;
				   } else if( document.documentElement
				   && ( document.documentElement.scrollLeft
					|| document.documentElement.scrollTop ) ) {
						   //IE6 Strict
						   scrOfY = document.documentElement.scrollTop;
				   }
				   return scrOfY;
		}
		
		function setMenuPosition() {
			var position = getScrollTop();
			if (typeof position == 'undefined' || !position) return false;
			
			
			if (position > 106) {
				
				if ($('.headmenuwrap .crumbs').is(':visible')) $('.headmenuwrap .crumbs').hide();
				$('.headmenuwrap').stop().animate({
					'height': '55px',
					'z-index': 5,
					'top': parseInt(position) + 'px'
				}, 100);
				
			} else {
				if (!$('.headmenuwrap .crumbs').is(':visible')) $('.headmenuwrap .crumbs').show();
				$('.headmenuwrap').stop().animate({
					'height': '106px',
					'z-index': 1,
					'top': '0px'
				}, 500);
			}
			return false;
		}
		
		//window.addEventListener("scroll", setMenuPosition, false);
	});
	</script>
</head> 
<body>
	<div class="headmenuwrap">
		<div class="headmenu">
			<button type="button" id="mobile-menu-toggle" class="mobile-menu-toggle" aria-controls="side-menu" aria-expanded="false" aria-label="<?php echo h(__('Menu')); ?>">
				<span></span><span></span><span></span>
			</button>
			<a href="<?php echo WWW_ROOT; ?>/admin/"><div class="logo"></div></a>
			<div class="menu" id="topmenu">
				<ul>
					<li><a href="#">Общее</a></li>
					<li><a href="#">Плагины</a></li>
					<li><a href="#">Сниппеты</a></li>
					<li><a href="#">Дизайн</a></li>
					<li><a href="#">Статистика</a></li>
					<li><a href="#">Безопасность</a></li>
					<li><a href="#">Дополнительно</a></li>
					<li><a href="#">Помощь</a></li>
					<div class="clear"></div>
				</ul>
			</div>
			<div class="userbar">
				<?php
				if (!empty($_SESSION['user'])) {
					
					$ava_path = (file_exists(ROOT . '/sys/avatars/' . $_SESSION['user']['id'] . '.jpg'))
					? WWW_ROOT . '/sys/avatars/' . $_SESSION['user']['id'] . '.jpg'
					:  WWW_ROOT . '/sys/img/noavatar.png';
				
				}

				
                // Used below
				$isMainPage = true;
                $serverMessage = (!empty($isMainPage))
                    ? AtmApiService::getServerMessage() : '';
		

				$new_ver = AtmApiService::getLastVersion();
				$newVersion = ($new_ver)
					? '<a href="https://github.com/Drunyacoder/AtomXCMS-2/releases" title="Next version">' . __('New version of AtomX') . '</a>'
					: '';
				
				
				$group_info = $Register['ACL']->get_user_group($_SESSION['user']['status']);
				$group_title = $group_info['title'];
				
				?>
				<div class="ava"><img src="<?php echo $ava_path; ?>" alt="user ava" title="user ava" /></div>
				<div class="name"><a href="#"><?php echo h($_SESSION['user']['name']); ?></a><span><?php echo h($group_title) ?></span></div>
				<a href="exit.php" class="exit"></a>
			</div>
			<div class="clear"></div>
		</div>
		<div class="rcrumbs">
			<?php echo (!empty($pageNavr)) ? $pageNavr : ''; ?>
		</div>
		<div class="crumbs">
			<?php echo (!empty($pageNav)) ? $pageNav : ''; ?>
		</div>
	</div>
	<div id="side-menu" class="side-menu">
		<div class="mobile-sidebar-head">
			<span><?php echo __('Menu'); ?></span>
			<button type="button" class="mobile-menu-close" aria-label="<?php echo h(__('Close menu')); ?>">&times;</button>
		</div>
		<div class="search">
			<form>
				<div class="input"><input type="text" name="search" placeholder="Search..." /></div>
				<input class="submit-butt" type="submit" name="send" value="" />
			</form>
		</div>
		<div id="mobile-topmenu" class="mobile-topmenu"></div>
		<div class="mobile-menu-title"><?php echo __('Admin modules'); ?></div>
		<ul>
		
		
		
		<?php
		$modsInstal = new FpsModuleInstaller;
		$nsmods = $modsInstal->checkNewModules();

		if (count($nsmods)):
			foreach ($nsmods as $mk => $mv):
		?>	
		
			<li>
				<div class="icon new-module"></div>
                <div class="sub-opener" onClick="subMenu('sub<?php echo $mk; ?>')"></div>
                <a href="#"><?php echo $mk; ?></a>
				<div class="clear"></div>
				<div id="sub<?php echo $mk; ?>" class="sub">
					<div class="shadow">
						<ul>
							<li><a href="<?php echo WWW_ROOT; ?>/admin?install=<?php echo $mk ?>">Install</a></li>
						</ul>
					</div>
				</div>
			</li>
		<?php
			endforeach;
		endif;




		$modules = getAdmFrontMenuParams();

		foreach ($modules as $modKey => $modData): 
			if (!empty($nsmods) && array_key_exists($modKey, $nsmods)) continue;
		?>
		
			<li>
				<div class="icon <?php echo $modKey ?>"></div>
                <div class="sub-opener" onClick="subMenu('sub<?php echo $modKey ?>')"></div>
                <a href="<?php echo $modData['url']; ?>"><?php echo $modData['ankor']; ?></a>
				<div class="clear"></div>
				<div id="sub<?php echo $modKey ?>" class="sub">
					<div class="shadow">
						<ul>
							<?php foreach ($modData['sub'] as $url => $ankor): ?>
							<li><a href="<?php echo get_url('/admin/' . $url); ?>"><?php echo $ankor; ?></a></li>
							<?php endforeach; ?>
						</ul>
					</div>
				</div>
			</li>
		<?php endforeach; ?>
		</ul>
		<div class="clear"></div>
	</div>
	<div id="mobile-menu-overlay" class="mobile-menu-overlay" aria-hidden="true"></div>
	<script>
		var FpsAdmPanel = {};
		FpsAdmPanel.sidePanel = 'visible';
		function hideSide(){
			if(FpsAdmPanel.sidePanel == 'visible') {
				$('#side-menu').animate({left:'-300px'}, 500); 
				$('#side-menu-td').animate({width:'0px'}, 500);
				FpsAdmPanel.sidePanel = 'hidden';
			} else {
				$('#side-menu').animate({left:'3%'}, 500); 
				$('#side-menu-td').animate({width:'237px'}, 500);
				FpsAdmPanel.sidePanel = 'visible';
			}
		}
	</script>
	<div id="side-menu-label" onClick="hideSide();">
		<a href="#">Спрятать</a>
	</div>
	<div id="wrapper">

		<!-- AdminBar -->
		<script>

		document.top_menu = new drunyaMenu([
		['<?php echo __('General'); ?>',
		  [
		  '<a href="/admin"><?php echo __('Main page'); ?></a>',
		  'sep',
		  '<span><?php echo __('Version of AtomX'); ?> [ <b><?php echo FPS_VERSION ?></b> ]</span>',
		  <?php if ($new_ver): ?>
		  '<span><?php echo $newVersion; ?> [ <?php echo $new_ver; ?> ]</span>',
		  <?php endif; ?>
		  'sep',
		  '<a href="/admin/settings.php?m=sys"><?php echo __('Common settings'); ?></a>',
		  '<a href="/admin/clean_cache.php"><?php echo __('Clean cache'); ?></a>'
		  ]],

		['<?php echo __('Plugins'); ?>',
		  [
		  '<a href="/admin/plugins.php"><?php echo __('List'); ?></a>',
		  '<a href="/admin/get_plugins.php?a=ed">Установка</a>'
		  ]],

		  
		  
		['<?php echo __('Snippets'); ?>',
		  [
		  '<a href="/admin/snippets.php"><?php echo __('Create'); ?></a>',
		  '<a href="/admin/snippets.php?a=ed"><?php echo __('Edit'); ?></a>'
		  ]],

		  
		 
		['<?php echo __('Design'); ?>',
		  [
		  '<a href="design.php?d=default&t=main"><?php echo __('General design and css'); ?></a>',
		  '<a href="menu_editor.php"><?php echo __('Menu editor'); ?></a>'
		  ]],
		  
		  
		['<?php echo __('Statistic'); ?>',
		  [
		  '<a href="/admin/statistic.php"><?php echo __('View'); ?></a>',
		  '<a href="/admin/settings.php?m=statistics"><?php echo __('Settings of module'); ?></a>'
		  ]],



		['<?php echo __('Security'); ?>',
		  [
		  '<a href="settings.php?m=secure"><?php echo __('Security settings'); ?></a>',
		  '<a href="ip_ban.php"><?php echo __('Bans by IP'); ?></a>',
		  '<a href="dump.php"><?php echo __('Backup control'); ?></a>',
		  'sep',
		  '<a href="system_log.php"><?php echo __('Action log'); ?></a>',
		  '<a href="errors_log.php"><?php echo __('Errors log'); ?></a>'
		  ]],
		  
		  
		['<?php echo __('Additional'); ?>',
		  [
		  '<a href="settings.php?m=hlu"><?php echo __('SEO settings'); ?></a>',
		  '<a href="settings.php?m=rss"><?php echo __('RSS settings'); ?></a>',
		  '<a href="settings.php?m=sitemap"><?php echo __('Sitemap settings'); ?></a>',
		  '<a href="settings.php?m=watermark"><?php echo __('Watermark settings'); ?></a>',
		  '<a href="settings.php?m=autotags"><?php echo __('Autotags settings'); ?></a>',
		  '<a href="settings.php?m=links"><?php echo __('Links settings'); ?></a>'
		  ]],
		  

		['<?php echo __('Help'); ?>',
		  [
		  '<a href="http://atomx.net" target="_blank"><?php echo __('AtomX CMS Community'); ?></a>',
		  '<a href="faq.php"><?php echo __('FAQ'); ?></a>',
		  'sep',
		  '<a href="authors.php"><?php echo __('Dev. Team'); ?></a>',
		  ]]
		]);

		</script>
		<!-- /AdminBar -->
		
		<script>
		(function($) {
			function isMobile() {
				return window.innerWidth <= 1100;
			}

			function buildMobileTopMenu() {
				var $source = $('#topmenu > ul');
				var $target = $('#mobile-topmenu');
				if (!$source.length || !$target.length) return;

				var $groups = $('<ul class="mobile-menu-groups"></ul>');

				$source.children('li').each(function(index) {
					var $item = $(this);
					var $sourceLink = $item.children('a').first();
					var label = $.trim($sourceLink.text());
					if (!label) return;

					var $sub = $item.children('.sub').first();
					var id = 'mobile-top-sub-' + index;
					var $group = $('<li class="mobile-menu-group"></li>');
					var $link = $('<a href="#" class="mobile-top-link"></a>');

					$link.text(label).attr({
						'aria-controls': id,
						'aria-expanded': 'false'
					});

					if ($sub.length) {
						var $content = $sub.find('.shadow').first();
						if (!$content.length) $content = $sub;

						var $subMenu = $('<div class="mobile-top-sub" id="' + id + '" aria-hidden="true"></div>');
						$subMenu.append($content.children().clone());
						$group.append($link).append($subMenu);
					} else {
						$link.attr('href', $sourceLink.attr('href') || '#');
						$group.append($link);
					}

					$groups.append($group);
				});

				$target.empty().append($groups);

				$target.on('click', '.mobile-top-link', function(event) {
					var $link = $(this);
					var id = $link.attr('aria-controls');
					if (!id) return;

					event.preventDefault();
					var $sub = $('#' + id);
					var expanded = $link.attr('aria-expanded') === 'true';

					$target.find('.mobile-top-link').not(this).attr('aria-expanded', 'false');
					$target.find('.mobile-top-sub').not($sub).slideUp(150).attr('aria-hidden', 'true');

					if (expanded) {
						$sub.slideUp(150).attr('aria-hidden', 'true');
						$link.attr('aria-expanded', 'false');
					} else {
						$sub.slideDown(150).attr('aria-hidden', 'false');
						$link.attr('aria-expanded', 'true');
					}
				});
			}

			function openMobileMenu() {
				if (!isMobile()) return;

				$('#mobile-topmenu .mobile-top-sub').hide().attr('aria-hidden', 'true');
				$('#mobile-topmenu .mobile-top-link').attr('aria-expanded', 'false');
				$('body').addClass('mobile-menu-open');
				$('#mobile-menu-toggle').attr('aria-expanded', 'true');
			}

			function closeMobileMenu() {
				$('body').removeClass('mobile-menu-open');
				$('#mobile-menu-toggle').attr('aria-expanded', 'false');
			}

			$(function() {
				buildMobileTopMenu();

				$('#mobile-menu-toggle').on('click', function() {
					if ($('body').hasClass('mobile-menu-open')) {
						closeMobileMenu();
					} else {
						openMobileMenu();
					}
				});

				$('.mobile-menu-close, #mobile-menu-overlay').on('click', closeMobileMenu);

				$('#side-menu').on('click', 'a', function() {
					if ($(this).hasClass('mobile-top-link')) return;
					if (isMobile()) closeMobileMenu();
				});

				$(window).on('resize orientationchange', function() {
					if (!isMobile()) closeMobileMenu();
				});

				$(document).on('keydown', function(event) {
					if (event.key === 'Escape' && $('body').hasClass('mobile-menu-open')) {
						closeMobileMenu();
					}
				});
			});
		})(jQuery);
		</script>
		
		<div class="center-wrapper">
			<table class="side-separator" cellpadding="0" cellspacing="0" width="100%" >
				<tr>
					<td id="side-menu-td" width="237" min-height="100%">
					</td>
					<td style="position:relative;">
						<div id="content-wrapper">

<?php if (!empty($serverMessage)): ?>
    <!-- AtomX Server Notification -->
    <div class="warning <?php echo $serverMessage['type'] ?>">
        <?php echo $serverMessage['message'] ?>
    </div>
    <!-- /AtomX Server Notification -->
<?php endif  ?>
<?php if (!empty($_SESSION['message'])): ?>
    <div class="warning ok">
		<?php echo $_SESSION['message'] ?>
	</div>
    <?php unset($_SESSION['message']); ?>
<?php endif; if (!empty($_SESSION['errors'])): ?>
    <div class="warning error">
		<?php echo $_SESSION['errors'] ?>
	</div>
<?php unset($_SESSION['errors']); endif; ?>