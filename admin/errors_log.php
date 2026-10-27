<?php
##################################################
##												##
## Author:       Andrey Brykin (Drunya)         ##
## Version:      1.0                            ##
## Project:      CMS                            ##
## package      CMS AtomX                      ##
## subpackege    Admin Panel module             ##
## copyright     (c) Andrey Brykin 2010-2014    ##
##################################################

include_once '../sys/boot.php';
include_once ROOT . '/admin/inc/adm_boot.php';

if (empty($_SESSION['user']) || (int)$_SESSION['user']['status'] !== 4) {
	$_SESSION['errors'] = __('Permission denied');
	redirect('/admin/');
}

$logPath = ROOT . '/sys/logs/php_errors.log';
$maxLogSize = 5 * 1024 * 1024;
$keepArchives = 3;

if (!function_exists('atm_errors_log_rotate')) {
	/**
	 * Rotate the PHP error log when it becomes too large.
	 *
	 * @param string $path
	 * @param int $maxBytes
	 * @param int $keep
	 * @return bool
	 */
	function atm_errors_log_rotate($path, $maxBytes = 5242880, $keep = 3)
	{
		if (!is_file($path) || !is_readable($path) || !is_writable(dirname($path))) {
			return false;
		}
		$size = @filesize($path);
		if ($size === false || $size < $maxBytes) return false;

		for ($i = $keep - 1; $i >= 1; $i--) {
			$from = $path . '.' . $i;
			$to = $path . '.' . ($i + 1);
			if (is_file($from)) {
				if (is_file($to)) @unlink($to);
				@rename($from, $to);
			}
		}
		if (is_file($path . '.1')) @unlink($path . '.1');
		if (!@rename($path, $path . '.1')) return false;
		@file_put_contents($path, '');
		@chmod($path, 0600);
		return true;
	}
}

if (!function_exists('atm_errors_log_tail')) {
	/**
	 * Read the last chunk of a log file without loading a huge file.
	 *
	 * @param string $path
	 * @param int $maxBytes
	 * @return string
	 */
	function atm_errors_log_tail($path, $maxBytes = 2097152)
	{
		if (!is_file($path) || !is_readable($path)) return '';
		$size = @filesize($path);
		if ($size === false || $size < 1) return '';
		$handle = @fopen($path, 'rb');
		if ($handle === false) return '';
		$offset = max(0, $size - $maxBytes);
		if ($offset > 0) {
			fseek($handle, $offset);
			// Discard the first, possibly incomplete, line.
			fgets($handle);
		}
		$data = stream_get_contents($handle);
		fclose($handle);
		return $data === false ? '' : $data;
	}
}

$size = (is_file($logPath) ? (int)@filesize($logPath) : 0);
$mtime = (is_file($logPath) ? (int)@filemtime($logPath) : 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
	$action = (string)$_POST['action'];
	if (!atm_admin_csrf_check(isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '')) {
		$_SESSION['errors'] = __('Session expired, please reload the page');
		redirect('/admin/errors_log.php');
	}

	if ($action === 'clear') {
		if (!is_file($logPath) || @file_put_contents($logPath, '') !== false) {
			@chmod($logPath, 0600);
			atm_admin_audit(
				'error_log_cleared',
				'file size(' . $size . ')',
				(int)$_SESSION['user']['id'],
				(string)$_SESSION['user']['name']
			);
			$_SESSION['message'] = __('The error log was cleared.');
		} else {
			$_SESSION['errors'] = __('Could not clear the error log.');
		}
		redirect('/admin/errors_log.php');
	}

	if ($action === 'rotate') {
		if (atm_errors_log_rotate($logPath, 0, $keepArchives)) {
			atm_admin_audit(
				'error_log_rotated',
				'file size(' . $size . ')',
				(int)$_SESSION['user']['id'],
				(string)$_SESSION['user']['name']
			);
			$_SESSION['message'] = __('The error log was rotated.');
		} else {
			$_SESSION['errors'] = __('Could not rotate the error log.');
		}
		redirect('/admin/errors_log.php');
	}

	if ($action === 'download') {
		$data = atm_errors_log_tail($logPath, 10 * 1024 * 1024);
		atm_admin_audit(
			'error_log_downloaded',
			'file size(' . $size . ')',
			(int)$_SESSION['user']['id'],
			(string)$_SESSION['user']['name']
		);
		header('Content-Type: text/plain; charset=utf-8');
		header('Content-Disposition: attachment; filename="php_errors-' . date('Ymd-His') . '.log"');
		header('X-Content-Type-Options: nosniff');
		echo $data;
		exit;
	}
}

// Automatic rotation keeps the log bounded even if nobody presses the button.
if ($size >= $maxLogSize) {
	if (atm_errors_log_rotate($logPath, $maxLogSize, $keepArchives)) {
		atm_admin_audit(
			'error_log_rotated',
			'file size(' . $size . ')',
			(int)$_SESSION['user']['id'],
			(string)$_SESSION['user']['name']
		);
		$_SESSION['message'] = __('The error log was rotated automatically.');
		redirect('/admin/errors_log.php');
	}
}

$content = atm_errors_log_tail($logPath);
$lines = $content === '' ? array() : preg_split('/\r\n|\r|\n/', rtrim($content, "\r\n"));
$lines = array_values(array_reverse($lines));
$perPage = 200;
$totalLines = count($lines);
$pages = max(1, (int)ceil($totalLines / $perPage));
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
if ($page > $pages) $page = $pages;
$pageLines = array_slice($lines, ($page - 1) * $perPage, $perPage);

$archives = array();
for ($i = 1; $i <= $keepArchives; $i++) {
	$archivePath = $logPath . '.' . $i;
	if (is_file($archivePath)) {
		$archives[] = array(
			'name' => basename($archivePath),
			'size' => (int)@filesize($archivePath),
			'mtime' => (int)@filemtime($archivePath),
		);
	}
}

$pageTitle = __('Errors log');
$pageNav = $pageTitle;
$pageNavr = '';
include_once ROOT . '/admin/template/header.php';
?>

<style>
.error-log-toolbar { display:flex; flex-wrap:wrap; gap:8px; align-items:center; margin:0 0 14px; }
.error-log-toolbar form { display:inline-block; margin:0; }
.error-log-meta { color:#6d7882; font-size:12px; }
.error-log-content { margin:0; padding:12px; min-height:420px; max-height:800px; overflow:auto; background:#fbfcfd; border:1px solid #e1e7eb; border-radius:4px; white-space:pre-wrap; overflow-wrap:anywhere; font:12px/1.55 Consolas, monospace; }
.error-log-line { display:block; padding:1px 0; }
.error-log-line.warn { background:#fff8e1; }
.error-log-line.error { background:#fff0f1; }
.error-log-empty { padding:22px; color:#7b8790; }
.error-log-archives { margin:12px 0 0; padding:0; list-style:none; color:#6d7882; font-size:12px; }
.error-log-archives li { padding:2px 0; }
@media screen and (max-width: 700px) {
	.error-log-toolbar .atm-button { width:100%; box-sizing:border-box; text-align:center; }
	.error-log-content { min-height:300px; max-height:65vh; }
}
</style>

<div class="list">
	<div class="title"><?php echo h(__('Errors log')); ?></div>
	<div class="level1">
		<div class="items">
			<div class="setting-item">
				<div class="left">
					<?php echo h(__('PHP error log')); ?>
					<span class="comment"><?php echo h(__('The log is rotated automatically at 5 MB; the last three archives are kept.')); ?></span>
				</div>
				<div class="right">
					<div class="error-log-meta">
						<?php echo h(sprintf(__('Size: %s'), atm_admin_format_size($size))); ?>
						&middot;
						<?php echo h(sprintf(__('Lines: %d'), $totalLines)); ?>
						<?php if ($mtime > 0): ?>
							&middot; <?php echo h(sprintf(__('Last modified: %s'), date('d.m.Y H:i:s', $mtime))); ?>
						<?php endif; ?>
					</div>
					<div class="error-log-toolbar" style="margin-top:10px;">
						<form method="post" action="errors_log.php">
							<input type="hidden" name="csrf_token" value="<?php echo h(atm_admin_csrf_token()); ?>" />
							<input type="hidden" name="action" value="download" />
							<button type="submit" class="atm-button secondary"><?php echo h(__('Download')); ?></button>
						</form>
						<form method="post" action="errors_log.php">
							<input type="hidden" name="csrf_token" value="<?php echo h(atm_admin_csrf_token()); ?>" />
							<input type="hidden" name="action" value="rotate" />
							<button type="submit" class="atm-button secondary"><?php echo h(__('Rotate log')); ?></button>
						</form>
						<form method="post" action="errors_log.php">
							<input type="hidden" name="csrf_token" value="<?php echo h(atm_admin_csrf_token()); ?>" />
							<input type="hidden" name="action" value="clear" />
							<button
								type="submit"
								class="atm-button danger"
								data-atm-danger
								data-atm-confirm="<?php echo h(__('Clear the PHP error log? This action cannot be undone.')); ?>"
								data-atm-confirm-ok="<?php echo h(__('Clear')); ?>"
							><?php echo h(__('Clean log')); ?></button>
						</form>
					</div>
				</div>
				<div class="clear"></div>
			</div>
		</div>
		<div class="items">
			<?php if (empty($pageLines)): ?>
				<div class="error-log-empty"><?php echo h(__('No records')); ?></div>
			<?php else: ?>
				<pre class="error-log-content"><?php
					foreach ($pageLines as $line) {
						$class = '';
						if (stripos($line, 'fatal error') !== false || stripos($line, 'parse error') !== false) {
							$class = ' error';
						} elseif (stripos($line, 'warning') !== false || stripos($line, 'deprecated') !== false || stripos($line, 'notice') !== false) {
							$class = ' warn';
						}
						echo '<span class="error-log-line' . $class . '">' . h($line) . "</span>\n";
					}
				?></pre>
			<?php endif; ?>
			<?php if ($pages > 1): ?>
				<div class="pagination">
					<?php if ($page > 1): ?><a class="pages" href="errors_log.php?page=<?php echo $page - 1; ?>">&laquo;</a><?php endif; ?>
					<?php
					$start = max(1, $page - 3);
					$end = min($pages, $page + 3);
					for ($i = $start; $i <= $end; $i++):
					?>
						<?php if ($i === $page): ?><strong><?php echo $i; ?></strong><?php else: ?><a class="pages" href="errors_log.php?page=<?php echo $i; ?>"><?php echo $i; ?></a><?php endif; ?>
					<?php endfor; ?>
					<?php if ($page < $pages): ?><a class="pages" href="errors_log.php?page=<?php echo $page + 1; ?>">&raquo;</a><?php endif; ?>
				</div>
			<?php endif; ?>
			<?php if (!empty($archives)): ?>
				<ul class="error-log-archives">
					<?php foreach ($archives as $archive): ?>
						<li>
							<?php echo h($archive['name']); ?>
							— <?php echo h(atm_admin_format_size($archive['size'])); ?>
							<?php if (!empty($archive['mtime'])): ?>(<?php echo h(date('d.m.Y H:i', $archive['mtime'])); ?>)<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
	</div>
</div>

<?php include_once ROOT . '/admin/template/footer.php'; ?>
