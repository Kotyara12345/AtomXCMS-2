<?php
include_once ROOT . '/sys/inc/admin_security.lib.php';
include_once ROOT . '/sys/inc/server_config.lib.php';

$atmNotificationCounts = atm_admin_notification_counts();
$atmNotificationUserId = !empty($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : 0;
$atmNotificationSummary = atm_admin_notification_summary($atmNotificationCounts, $atmNotificationUserId);
$atmNotificationTotal = (int)$atmNotificationSummary['total'];
$atmNotificationNew = $atmNotificationSummary['moderation'];
$atmRecentNotifications = $atmNotificationUserId > 0
	? atm_admin_notifications_list($atmNotificationUserId, 3, true)
	: array();

$atmServerStatuses = atm_server_config_status_all();
$atmServerConfigured = 0;
$atmServerOk = 0;
$atmServerIssues = 0;
foreach ($atmServerStatuses as $atmServerStatus) {
	if (!empty($atmServerStatus['configured'])) {
		$atmServerConfigured++;
		if (!empty($atmServerStatus['ok'])) {
			$atmServerOk++;
		} else {
			$atmServerIssues++;
		}
	}
}
if ($atmServerIssues > 0) {
	$atmServerDot = ($atmServerOk > 0) ? 'warn' : 'error';
} else {
	$atmServerDot = ($atmServerConfigured > 0) ? 'ok' : 'error';
}

$atmServerLabels = array(
	'ok' => __('OK'),
	'empty' => __('Not configured'),
	'missing' => __('File not found'),
	'not_readable' => __('File is not readable'),
	'not_writable' => __('Read only'),
	'not_file' => __('Not a file'),
	'extension' => __('Unsupported file type'),
	'relative' => __('Path must be absolute'),
	'invalid' => __('Invalid path'),
);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
	<meta charset="UTF-8" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<link rel="icon" type="image/png" sizes="16x16" href="<?php echo WWW_ROOT ?>/admin/template/img/favicon.png?v=<?php echo filemtime(ROOT . '/admin/template/img/favicon.png'); ?>" />
	<title><?php echo $pageTitle; ?></title>
	<meta name="description" content="" />
	<meta name="keywords" content="" />
	<script src="<?php echo WWW_ROOT ?>/sys/js/jquery.js"></script>
	<script>
	window.ATM_CSRF_TOKEN = <?php echo json_encode(atm_admin_csrf_token()); ?>;
	(function ($) {
		if (!$ || !window.ATM_CSRF_TOKEN) return;

		function isSameOrigin(url) {
			var anchor = document.createElement('a');
			anchor.href = url || window.location.href;
			// Only attach the admin token to admin endpoints. This keeps
			// the token away from public site scripts on the same host.
			return anchor.host === window.location.host
				&& anchor.pathname.indexOf('/admin/') !== -1;
		}

		$.ajaxPrefilter(function (options) {
			var method = String(options.type || 'GET').toUpperCase();
			if (method !== 'POST' || !isSameOrigin(options.url)) return;

			var token = window.ATM_CSRF_TOKEN;
			if (typeof FormData !== 'undefined' && options.data instanceof FormData) {
				if (!options.data.has('csrf_token')) options.data.append('csrf_token', token);
				return;
			}
			if (typeof options.data === 'string') {
				if (options.data.indexOf('csrf_token=') === -1) {
					options.data += (options.data ? '&' : '') + 'csrf_token=' + encodeURIComponent(token);
				}
				return;
			}
			if ($.isArray(options.data)) {
				options.data.push({name: 'csrf_token', value: token});
				return;
			}
			if (options.data && typeof options.data === 'object') {
				options.data.csrf_token = token;
				return;
			}
			options.data = {csrf_token: token};
		});

		$(document).on('submit', 'form', function () {
			var $form = $(this);
			var method = String($form.attr('method') || 'GET').toUpperCase();
			if (method !== 'POST') return;
			if ($form.find('input[name="csrf_token"]').length) return;
			$('<input>', {
				type: 'hidden',
				name: 'csrf_token',
				value: window.ATM_CSRF_TOKEN
			}).appendTo($form);
		});
	})(window.jQuery);
	</script>
	
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

	<script>
	(function () {
		try {
			if (localStorage.getItem('atmAdminTheme') === 'dark') {
				document.documentElement.className += ' atm-dark';
			}
		} catch (e) {}
	})();
	</script>
	
	<link rel="stylesheet" href="<?php echo WWW_ROOT ?>/admin/template/css/style.css?v=<?php echo filemtime(ROOT . '/admin/template/css/style.css'); ?>" />
	<script src="<?php echo WWW_ROOT ?>/admin/js/admin-ui.js?v=<?php echo filemtime(ROOT . '/admin/js/admin-ui.js'); ?>" defer></script>
	<!--<link rel="stylesheet" href="<?php echo WWW_ROOT ?>/admin/template/css/font-awesome.min.css" />-->
	
	<script>
	
	
	$(document).ready(function(){
		$("a.gallery").attr("data-fancybox", "gallery");
		//setTimeout(function(){
		//	$('#overlay').height($('#wrapper').height());
		//}, 2000);
		if (window.innerWidth > 1200) {
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
	<a class="atm-skip-link" href="#content-wrapper"><?php echo h(__('Skip to content')); ?></a>
	<div id="atm-toasts" class="atm-toasts" role="region" aria-label="<?php echo h(__('Notifications')); ?>" aria-live="polite"></div>
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
					<li><a href="security.php">Безопасность</a></li>
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
				<div class="atm-notify">
					<button type="button" class="atm-icon-button atm-bell" id="atm-notify-toggle" aria-haspopup="true" aria-expanded="false" aria-controls="atm-notify-dropdown" aria-label="<?php echo h($atmNotificationTotal > 0 ? sprintf(__('Notifications: %d new'), $atmNotificationTotal) : __('Notifications')); ?>">
						<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false"><path fill="currentColor" d="M12 22a2.5 2.5 0 0 0 2.45-2h-4.9A2.5 2.5 0 0 0 12 22Zm7-5.2V11a7 7 0 0 0-5.5-6.84V3a1.5 1.5 0 0 0-3 0v1.16A7 7 0 0 0 5 11v5.8l-1.7 1.7a1 1 0 0 0 .7 1.7h16a1 1 0 0 0 .7-1.7Z"/></svg>
						<?php if ($atmNotificationTotal > 0): ?><span class="atm-badge"><?php echo (int)$atmNotificationTotal; ?></span><?php endif; ?>
						<span class="atm-status-dot <?php echo h($atmServerDot); ?>" aria-hidden="true"></span>
					</button>
					<div class="atm-dropdown atm-notify-dropdown" id="atm-notify-dropdown" role="menu" hidden>
						<div class="atm-dropdown-head"><?php echo h(__('Notifications')); ?></div>
						<?php if ($atmNotificationTotal < 1): ?>
							<div class="atm-empty"><?php echo h(__('No new notifications')); ?></div>
						<?php else: ?>
							<?php
							$atmNotifyItems = array(
								'news' => array('label' => __('News'), 'url' => 'materials_list.php?m=news&premoder=1'),
								'loads' => array('label' => __('Loads'), 'url' => 'materials_list.php?m=loads&premoder=1'),
								'stat' => array('label' => __('Articles'), 'url' => 'materials_list.php?m=stat&premoder=1'),
								'comments' => array('label' => __('Comments'), 'url' => 'moderation.php#comments'),
							);
							foreach ($atmNotifyItems as $atmNotifyKey => $atmNotifyItem):
								$atmNotifyCount = !empty($atmNotificationNew[$atmNotifyKey]) ? (int)$atmNotificationNew[$atmNotifyKey] : 0;
								if ($atmNotifyCount < 1) continue;
							?>
								<a class="atm-notify-item" role="menuitem" href="<?php echo h($atmNotifyItem['url']); ?>">
									<span><?php echo h($atmNotifyItem['label']); ?></span>
									<b><?php echo $atmNotifyCount; ?></b>
								</a>
							<?php endforeach; ?>
							<?php foreach ($atmRecentNotifications as $atmNotification): ?>
								<a class="atm-notify-item atm-notify-security" role="menuitem" href="<?php echo h(!empty($atmNotification['url']) ? $atmNotification['url'] : 'notifications.php'); ?>">
									<span>
										<?php echo h($atmNotification['title']); ?>
										<?php if (!empty($atmNotification['message'])): ?>
											<small><?php echo h($atmNotification['message']); ?></small>
										<?php endif; ?>
									</span>
									<b class="atm-notify-type <?php echo h($atmNotification['type']); ?>"></b>
								</a>
							<?php endforeach; ?>
							<form method="POST" action="notifications.php" class="atm-notify-read-form">
								<input type="hidden" name="action" value="mark_all_read" />
								<button type="submit" class="atm-dropdown-link"><?php echo h(__('Mark all as read')); ?></button>
							</form>
						<?php endif; ?>
						<div class="atm-dropdown-sep"></div>
						<div class="atm-server-status">
							<div class="atm-dropdown-subhead"><?php echo h(__('Server status')); ?></div>
							<?php foreach ($atmServerStatuses as $atmServerType => $atmServerStatus): ?>
								<?php
								$atmServerOkRow = !empty($atmServerStatus['ok']);
								$atmServerConfiguredRow = !empty($atmServerStatus['configured']);
								$atmServerRowClass = $atmServerOkRow ? 'ok' : ($atmServerConfiguredRow ? 'error' : 'empty');
								$atmServerCode = $atmServerOkRow ? 'ok' : (string)$atmServerStatus['code'];
								$atmServerLabel = isset($atmServerLabels[$atmServerCode]) ? $atmServerLabels[$atmServerCode] : __('Error');
								?>
								<div class="atm-server-row">
									<span class="atm-dot <?php echo h($atmServerRowClass); ?>" aria-hidden="true"></span>
									<span class="atm-server-name"><?php echo h($atmServerType === 'apache' ? 'Apache' : 'Nginx'); ?></span>
									<span class="atm-server-value"><?php echo h($atmServerLabel); ?></span>
									<?php if ($atmServerOkRow && !empty($atmServerStatus['info']['mtime'])): ?>
										<small><?php echo h(__('Modified')); ?>: <?php echo h(date('d.m.Y H:i', (int)$atmServerStatus['info']['mtime'])); ?></small>
									<?php endif; ?>
								</div>
							<?php endforeach; ?>
							<?php if (!empty($_SESSION['user']) && (int)$_SESSION['user']['status'] === 4): ?>
								<a class="atm-dropdown-link" role="menuitem" href="server_config.php"><?php echo h(__('Web server configuration')); ?></a>
							<?php endif; ?>
						</div>
						<a class="atm-dropdown-all" role="menuitem" href="notifications.php"><?php echo h(__('All notifications')); ?></a>
					</div>
				</div>

				<div class="atm-user-menu">
					<button type="button" class="atm-user-toggle" id="atm-user-toggle" aria-haspopup="true" aria-expanded="false" aria-controls="atm-user-dropdown" aria-label="<?php echo h(__('User menu')); ?>">
						<span class="ava"><img src="<?php echo $ava_path; ?>" alt="" /></span>
						<span class="name">
							<span class="atm-user-name"><?php echo h($_SESSION['user']['name']); ?></span>
							<span class="atm-user-group"><?php echo h($group_title); ?></span>
						</span>
						<span class="atm-caret" aria-hidden="true"></span>
					</button>
					<div class="atm-dropdown atm-user-dropdown" id="atm-user-dropdown" role="menu" hidden>
						<div class="atm-dropdown-head atm-user-head">
							<b><?php echo h($_SESSION['user']['name']); ?></b>
							<small><?php echo h($group_title); ?></small>
						</div>
						<a class="atm-dropdown-link" role="menuitem" href="profile.php"><?php echo h(__('Profile')); ?></a>
						<a class="atm-dropdown-link" role="menuitem" href="security.php"><?php echo h(__('Security center')); ?></a>
						<a class="atm-dropdown-link" role="menuitem" href="moderation.php"><?php echo h(__('Moderation')); ?></a>
						<?php if ((int)$_SESSION['user']['status'] === 4): ?>
							<div class="atm-dropdown-sep"></div>
							<a class="atm-dropdown-link" role="menuitem" href="settings.php?m=sys"><?php echo h(__('Common settings')); ?></a>
							<a class="atm-dropdown-link" role="menuitem" href="audit_log.php"><?php echo h(__('Audit log')); ?></a>
						<?php endif; ?>
						<div class="atm-dropdown-sep"></div>
						<button type="button" class="atm-theme-toggle" id="atm-theme-toggle" aria-pressed="false">
							<span class="atm-theme-symbol" aria-hidden="true">◐</span>
							<span class="atm-theme-dark-label"><?php echo h(__('Dark theme')); ?></span>
							<span class="atm-theme-light-label"><?php echo h(__('Light theme')); ?></span>
							<span class="atm-switch" aria-hidden="true"></span>
						</button>
					</div>
				</div>

				<a
					href="<?php echo WWW_ROOT; ?>/"
					class="go-site"
					target="_blank"
					rel="noopener noreferrer"
					title="<?php echo h(__('Go to site')); ?>"
					aria-label="<?php echo h(__('Go to site')); ?>"
				>
					<span class="go-site-label-full"><?php echo h(__('Go to site')); ?></span>
					<span class="go-site-label-short"><?php echo h(__('Site')); ?></span>
				</a>
				<a href="exit.php" class="exit" title="<?php echo h(__('Logout')); ?>" aria-label="<?php echo h(__('Logout')); ?>"></a>
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
				<div class="icon <?php echo h(!empty($modData['icon']) ? $modData['icon'] : $modKey); ?>"></div>
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
		  '<a href="security.php"><?php echo __('Security center'); ?></a>',
		  '<a href="settings.php?m=secure"><?php echo __('Security settings'); ?></a>',
		  '<a href="ip_ban.php"><?php echo __('Bans by IP'); ?></a>',
		  '<a href="dump.php"><?php echo __('Backup control'); ?></a>',
		  <?php if (!empty($_SESSION['user']) && (int)$_SESSION['user']['status'] === 4): ?>
		  '<a href="server_config.php"><?php echo __('Web server configuration'); ?></a>',
		  '<a href="audit_log.php"><?php echo __('Audit log'); ?></a>',
		  <?php endif; ?>
		  'sep',
		  '<a href="system_log.php"><?php echo __('Action log'); ?></a>'
		  <?php if (!empty($_SESSION['user']) && (int)$_SESSION['user']['status'] === 4): ?>,
		  '<a href="errors_log.php"><?php echo __('Errors log'); ?></a>'
		  <?php endif; ?>
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
				return window.innerWidth <= 1200;
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
		
		<script>
		(function($) {
			function cleanLabel(value) {
				return $.trim(String(value).replace(/\s+/g, ' '));
			}

			function prepareMobileTables() {
				$('table.mobile-cards').each(function() {
					var $table = $(this);
					var $head = $table.find('.mobile-table-head').first();

					if (!$head.length) {
						$head = $table.find('tr').filter(function() {
							return $(this).children('th').length > 0;
						}).first();
					}
					if (!$head.length) return;

					var labels = [];
					$head.children('th').each(function() {
						var label = cleanLabel($(this).text());
						var colspan = parseInt($(this).attr('colspan'), 10) || 1;
						for (var i = 0; i < colspan; i++) labels.push(label);
					});

					$table.find('tr').not($head).each(function() {
						if ($(this).children('th').length) return;

						var index = 0;
						$(this).children('td').each(function() {
							var $cell = $(this);
							var colspan = parseInt($cell.attr('colspan'), 10) || 1;

							if (typeof $cell.attr('data-label') === 'undefined') {
								$cell.attr('data-label', labels[index] || '');
							}
							index += colspan;
						});
					});
				});
			}

			$(prepareMobileTables);
		})(jQuery);
		</script>

		<div class="center-wrapper">
			<table class="side-separator" cellpadding="0" cellspacing="0" width="100%" >
				<tr>
					<td id="side-menu-td" width="237" min-height="100%">
					</td>
					<td style="position:relative;">
						<div id="content-wrapper" tabindex="-1">

<?php if (!empty($serverMessage)): ?>
    <!-- AtomX Server Notification -->
    <div class="warning <?php echo $serverMessage['type'] ?>">
        <?php echo $serverMessage['message'] ?>
    </div>
    <!-- /AtomX Server Notification -->
<?php endif  ?>
<?php if (!empty($_SESSION['message'])): ?>
    <div class="warning ok atm-flash">
		<?php echo $_SESSION['message'] ?>
	</div>
    <?php unset($_SESSION['message']); ?>
<?php endif; if (!empty($_SESSION['errors'])): ?>
    <div class="warning error atm-flash">
		<?php echo $_SESSION['errors'] ?>
	</div>
<?php unset($_SESSION['errors']); endif; ?>