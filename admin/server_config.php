<?php
##################################################
##												##
## @Author:       AtomX CMS                     ##
## @Version:      1.0                           ##
## @Project:      CMS                           ##
## @package       CMS AtomX                     ##
## @subpackege    Web server configuration      ##
##################################################


include_once '../sys/boot.php';
include_once ROOT . '/admin/inc/adm_boot.php';
include_once ROOT . '/sys/inc/server_config.lib.php';


/*
 * Editing web server configuration is available to administrators only.
 */
if (empty($_SESSION['user']) || (int)$_SESSION['user']['status'] !== 4) {
	$_SESSION['errors'] = __('Permission denied');
	redirect('/admin/');
}


if (!function_exists('atm_server_config_admin_error')) {
	/**
	 * Translate a machine-readable path validation code.
	 *
	 * @param string $code
	 * @return string
	 */
	function atm_server_config_admin_error($code)
	{
		switch ($code) {
			case 'bad_type':
				return __('Unknown configuration type.');
			case 'empty':
				return __('The configuration file path is empty.');
			case 'invalid':
				return __('The configuration file path is invalid.');
			case 'relative':
				return __('The configuration file path must be absolute.');
			case 'extension':
				return __('Only configuration files (.conf and Apache .htaccess) can be edited.');
			case 'missing':
				return __('The file was not found or is not accessible. Check the path, permissions and open_basedir.');
			case 'not_file':
				return __('The path does not point to a file.');
			case 'not_readable':
				return __('The file is not readable. Check the permissions and open_basedir.');
			case 'not_writable':
				return __('The file is not writable. Changes can not be saved.');
			case 'read_failed':
				return __('The file could not be read.');
			case 'too_large':
				return __('The file is too large to edit (maximum 2 MB).');
			case 'backup_dir':
				return __('The backup directory could not be created. The file was not changed.');
			case 'backup_failed':
				return __('The backup copy could not be created. The file was not changed.');
			case 'write_failed':
				return __('The file could not be saved. The backup copy was restored.');
			case 'settings_write':
				return __('The path settings could not be saved (no write access to sys/settings).');
			case 'path_mismatch':
				return __('The configuration file path has changed. Reload the page and try again.');
		}

		return __('Unknown error.');
	}
}


$types = array(
	'apache' => array(
		'title' => __('Apache configuration'),
		'note' => __('By default this is the site .htaccess file. You can also specify httpd.conf or a virtual host file if PHP has access to it.'),
	),
	'nginx' => array(
		'title' => __('Nginx configuration'),
		'note' => __('Specify the Nginx virtual host file of this site. The global nginx.conf should only be edited by experienced administrators.'),
	),
);

$settings = atm_server_config_read_settings();
$errors = array();
$pathOverrides = array();
$contentOverrides = array();

$checks = array();
foreach ($types as $type => $meta) {
	$checks[$type] = atm_server_config_check_path($type, isset($settings[$type]) ? $settings[$type] : '');
}

$action = isset($_POST['action']) ? (string)$_POST['action'] : '';
$type = isset($_POST['type']) ? (string)$_POST['type'] : '';
$typeIsValid = array_key_exists($type, $types);

if ($action === 'save_path' && $typeIsValid) {
	$pathOverrides[$type] = isset($_POST['path']) ? trim((string)$_POST['path']) : '';
}

if ($action === 'save_file' && $typeIsValid) {
	$contentOverrides[$type] = isset($_POST['content']) ? (string)$_POST['content'] : '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action !== '') {
	$csrfToken = isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '';

	if (!atm_admin_csrf_check($csrfToken)) {
		$errors[] = __('Session expired, please reload the page');
	} elseif (!$typeIsValid) {
		$errors[] = __('Unknown configuration type.');
	} elseif ($action === 'save_path') {
		$path = isset($pathOverrides[$type]) ? $pathOverrides[$type] : '';
		$newSettings = $settings;

		if ($path === '') {
			$newSettings[$type] = '';
			if (!atm_server_config_write_settings($newSettings)) {
				$errors[] = atm_server_config_admin_error('settings_write');
			} else {
				$_SESSION['message'] = __('The path was saved.');
				redirect('/admin/server_config.php');
			}
		} else {
			$check = atm_server_config_check_path($type, $path);
			if (!$check['ok']) {
				$errors[] = atm_server_config_admin_error($check['code']);
			} else {
				$newSettings[$type] = $check['path'];
				if (!atm_server_config_write_settings($newSettings)) {
					$errors[] = atm_server_config_admin_error('settings_write');
				} else {
					$_SESSION['message'] = __('The path was saved.');
					redirect('/admin/server_config.php');
				}
			}
		}
	} elseif ($action === 'save_file') {
		$postedPath = isset($_POST['path']) ? trim((string)$_POST['path']) : '';
		$content = isset($contentOverrides[$type]) ? $contentOverrides[$type] : '';
		$check = $checks[$type];

		if (!$check['ok']) {
			$errors[] = atm_server_config_admin_error($check['code']);
		} elseif ($postedPath !== $check['path']) {
			$errors[] = atm_server_config_admin_error('path_mismatch');
		} else {
			$currentContent = atm_server_config_read_file($check['path'], $readCode);
			if ($currentContent === false) {
				$errors[] = atm_server_config_admin_error($readCode);
			} elseif ($currentContent === $content) {
				$_SESSION['message'] = __('No changes to save.');
				redirect('/admin/server_config.php');
			} else {
				$info = array();
				if (!atm_server_config_save_file($type, $check['path'], $content, $info)) {
					$errors[] = atm_server_config_admin_error($info['code']);
				} else {
					if (Config::read('system_log', 'secure') && class_exists('Logination')) {
						$Log = new Logination();
						$Log->write(
							'editing server configuration',
							'type(' . $type . '), file(' . $check['path'] . '), backup(' . $info['backup'] . ')'
						);
					}

					$_SESSION['message'] = sprintf(
						__('The file was saved. Backup: %s'),
						h($info['backup'])
					);
					redirect('/admin/server_config.php');
				}
			}
		}
	} else {
		$errors[] = __('Unknown action.');
	}
}

if (!empty($errors)) {
	$_SESSION['errors'] = implode('<br />', $errors);
}


/*
 * Load the current contents for the editor. If a save attempt failed,
 * the submitted text is kept in the textarea so the administrator does
 * not lose the changes.
 */
$contents = array();
$readErrors = array();
foreach ($types as $type => $meta) {
	if (!$checks[$type]['ok']) {
		continue;
	}

	if (array_key_exists($type, $contentOverrides)) {
		$contents[$type] = $contentOverrides[$type];
		continue;
	}

	$content = atm_server_config_read_file($checks[$type]['path'], $readCode);
	if ($content === false) {
		$readErrors[$type] = $readCode;
	} else {
		$contents[$type] = $content;
	}
}


$pageTitle = __('Web server configuration');
$pageNav = $pageTitle;
$pageNavr = '';

include_once ROOT . '/admin/template/header.php';

$openBasedir = trim((string)ini_get('open_basedir'));
?>


<style>
.server-config-path-form .right { width:77%; box-sizing:border-box; }
.server-config-path-input { width:calc(100% - 150px); max-width:780px; margin-right:10px; }
.server-config-small-button { width:auto; height:32px; padding:0 18px; vertical-align:middle; }
.server-config-candidates { margin:8px 0 0; padding:0; background:none; }
.server-config-candidates div { margin:2px 0; padding:0; font-size:12px; }
.server-config-candidates a { color:#3573a8; text-decoration:underline; cursor:pointer; }
.server-config-status { line-height:20px; }
.server-config-ok { color:#5a8f00; }
.server-config-error { color:#c00; }
.server-config-editor-note { margin-top:8px; }
@media screen and (max-width: 1200px) {
	.server-config-path-form .right { width:100%; }
	.server-config-path-input { width:100%; margin:0 0 10px; }
}
</style>


<div class="warning">
	<?php echo __('Warning! Incorrect web server configuration can make the site unavailable. A backup copy is created before every save. Reload the web server only after checking the configuration.'); ?>
</div>

<?php if ($openBasedir !== ''): ?>
<div class="warning clean">
	<?php echo sprintf(
		__('PHP open_basedir is set to: %s. Files outside these directories are not accessible.'),
		h($openBasedir)
	); ?>
</div>
<?php endif; ?>


<?php foreach ($types as $type => $meta): ?>
	<?php
	$check = $checks[$type];
	$displayPath = isset($pathOverrides[$type]) ? $pathOverrides[$type] : $settings[$type];
	$candidates = array();
	foreach (atm_server_config_candidates($type) as $candidate) {
		if (@is_file($candidate)) {
			$candidates[] = $candidate;
		}
	}
	$candidates = array_slice(array_values(array_unique($candidates)), 0, 8);
	?>
	<div class="list" id="<?php echo h($type); ?>">
		<div class="title"><?php echo h($meta['title']); ?></div>
		<div class="level1">
			<div class="items">

				<form class="setting-item server-config-path-form" method="post" action="server_config.php">
					<input type="hidden" name="csrf_token" value="<?php echo h(atm_admin_csrf_token()); ?>" />
					<input type="hidden" name="action" value="save_path" />
					<input type="hidden" name="type" value="<?php echo h($type); ?>" />

					<div class="left">
						<?php echo __('Configuration file path'); ?>
					</div>
					<div class="right">
						<input
							class="server-config-path-input"
							type="text"
							name="path"
							id="server-config-<?php echo h($type); ?>-path"
							value="<?php echo h($displayPath); ?>"
							autocomplete="off"
						/>
						<input
							class="save-button server-config-small-button"
							type="submit"
							value="<?php echo h(__('Apply path')); ?>"
						/>
						<div class="comment"><?php echo h($meta['note']); ?></div>

						<?php if (!empty($candidates)): ?>
							<div class="comment"><?php echo __('Detected paths'); ?>:</div>
							<div class="server-config-candidates">
								<?php foreach ($candidates as $candidate): ?>
									<div>
										<a
											href="#"
											class="server-config-use-path"
											data-target="server-config-<?php echo h($type); ?>-path"
											data-path="<?php echo h($candidate); ?>"
										><?php echo h($candidate); ?></a>
									</div>
								<?php endforeach; ?>
							</div>
						<?php endif; ?>
					</div>
					<div class="clear"></div>
				</form>

				<div class="setting-item">
					<div class="center">
						<?php if (!$check['ok']): ?>
							<span class="server-config-error">
								<?php echo h(atm_server_config_admin_error($check['code'])); ?>
							</span>
						<?php else: ?>
							<?php $fileInfo = atm_server_config_file_info($check['path']); ?>
							<div class="server-config-status">
								<span class="server-config-ok"><?php echo __('The file is available for editing.'); ?></span>
								&middot;
								<?php echo sprintf(__('Size: %s'), h(atm_server_config_format_size($fileInfo['size']))); ?>
								&middot;
								<?php echo sprintf(__('Permissions: %s'), h($fileInfo['perms'])); ?>
								<?php if (!empty($fileInfo['mtime'])): ?>
									&middot;
									<?php echo sprintf(
										__('Last modified: %s'),
										h(date('Y-m-d H:i', $fileInfo['mtime']))
									); ?>
								<?php endif; ?>
								<?php if (!$check['writable']): ?>
									<br /><span class="server-config-error"><?php echo __('The file is not writable. Changes can not be saved.'); ?></span>
								<?php endif; ?>
							</div>
						<?php endif; ?>

						<?php if (isset($readErrors[$type])): ?>
							<br /><span class="server-config-error">
								<?php echo h(atm_server_config_admin_error($readErrors[$type])); ?>
							</span>
						<?php endif; ?>
					</div>
				</div>

				<?php if ($check['ok'] && isset($contents[$type])): ?>
					<?php if ($check['writable']): ?>
						<form class="setting-item server-config-file-form" method="post" action="server_config.php">
							<input type="hidden" name="csrf_token" value="<?php echo h(atm_admin_csrf_token()); ?>" />
							<input type="hidden" name="action" value="save_file" />
							<input type="hidden" name="type" value="<?php echo h($type); ?>" />
							<input type="hidden" name="path" value="<?php echo h($check['path']); ?>" />

							<div class="center">
								<textarea
									class="server-config-editor"
									name="content"
									wrap="off"
								><?php echo h($contents[$type]); ?></textarea>
								<div class="server-config-editor-note" style="text-align:right;">
									<input
										class="save-button"
										type="submit"
										value="<?php echo h(__('Save file')); ?>"
									/>
								</div>
								<div class="comment">
									<?php echo __('Reload the web server manually after saving the configuration.'); ?>
								</div>
							</div>
						</form>
					<?php else: ?>
						<div class="setting-item">
							<div class="center">
								<textarea
									class="server-config-editor"
									name="content"
									wrap="off"
									readonly="readonly"
								><?php echo h($contents[$type]); ?></textarea>
								<div class="comment">
									<?php echo __('The file is not writable. Changes can not be saved.'); ?>
								</div>
							</div>
						</div>
					<?php endif; ?>
				<?php endif; ?>

			</div>
		</div>
	</div>
<?php endforeach; ?>


<div class="warning clean">
	<?php echo sprintf(
		__('Backups are stored in %s.'),
		h(ROOT . DS . 'sys' . DS . 'logs' . DS . 'server_config_backups')
	); ?>
</div>


<link rel="stylesheet" href="js/codemirror/codemirror.css" />
<link rel="stylesheet" href="js/codemirror/theme/solarized.css" />
<script src="js/codemirror/codemirror.js"></script>
<script>
$(document).ready(function() {
	$('.server-config-use-path').on('click', function(event) {
		event.preventDefault();
		var target = $(this).attr('data-target');
		var path = $(this).attr('data-path');
		if (target && path) {
			$('#' + target).val(path);
		}
	});

	if (typeof CodeMirror === 'undefined') {
		return;
	}

	var editors = [];
	$('.server-config-editor').each(function() {
		var textarea = this;
		var readOnly = $(textarea).attr('readonly') ? true : false;
		var editor = CodeMirror.fromTextArea(textarea, {
			theme: 'solarized',
			lineNumbers: true,
			lineWrapping: window.innerWidth <= 1200,
			readOnly: readOnly,
			indentUnit: 4,
			tabSize: 4
		});
		editor.setSize('100%', readOnly ? 420 : 520);
		editors.push(editor);
	});

	$('form.server-config-file-form').on('submit', function() {
		for (var i = 0; i < editors.length; i++) {
			editors[i].save();
		}
	});
});
</script>


<?php
include_once 'template/footer.php';
?>
