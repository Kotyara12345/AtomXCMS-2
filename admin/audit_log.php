<?php
##################################################
##												##
## @Author:       AtomX CMS                     ##
## @Version:      1.0                           ##
## @Project:      CMS                           ##
## @package       CMS AtomX                     ##
## @subpackege    Admin audit trail             ##
##################################################

include_once '../sys/boot.php';
include_once ROOT . '/admin/inc/adm_boot.php';

if (empty($_SESSION['user']) || (int)$_SESSION['user']['status'] !== 4) {
	$_SESSION['errors'] = __('Permission denied');
	redirect('/admin/');
}

$actions = atm_admin_audit_actions();
$filter = isset($_GET['action']) ? trim((string)$_GET['action']) : '';
if ($filter !== '' && !isset($actions[$filter])) {
	$filter = '';
}

$filterUser = isset($_GET['user']) ? trim((string)$_GET['user']) : '';
$filterQ = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$filterFrom = isset($_GET['from']) ? trim((string)$_GET['from']) : '';
$filterTo = isset($_GET['to']) ? trim((string)$_GET['to']) : '';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterFrom)) $filterFrom = '';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterTo)) $filterTo = '';
if ($filterFrom !== '' && $filterTo !== '' && $filterFrom > $filterTo) {
	$swap = $filterFrom;
	$filterFrom = $filterTo;
	$filterTo = $swap;
}
$filters = array(
	'user' => $filterUser,
	'q' => $filterQ,
	'from' => $filterFrom,
	'to' => $filterTo,
);

if (!function_exists('atm_audit_excerpt')) {
	/**
	 * @param string $text
	 * @param int $length
	 * @return string
	 */
	function atm_audit_excerpt($text, $length = 260)
	{
		$text = trim((string)$text);
		if (function_exists('mb_strimwidth')) {
			return mb_strimwidth($text, 0, $length, '...', 'UTF-8');
		}
		if (strlen($text) <= $length) return $text;
		return substr($text, 0, max(0, $length - 3)) . '...';
	}
}

if (!function_exists('atm_audit_query_params')) {
	/**
	 * @return array
	 */
	function atm_audit_query_params()
	{
		$params = array();
		foreach (array('action', 'user', 'q', 'from', 'to') as $key) {
			if (isset($_GET[$key]) && trim((string)$_GET[$key]) !== '') {
				$params[$key] = trim((string)$_GET[$key]);
			}
		}
		return $params;
	}
}

if (!function_exists('atm_audit_page_url')) {
	/**
	 * Build a pagination URL.
	 *
	 * @param int $page
	 * @return string
	 */
	function atm_audit_page_url($page)
	{
		$params = atm_audit_query_params();
		if ($page > 1) $params['page'] = (int)$page;
		return 'audit_log.php' . (!empty($params) ? '?' . http_build_query($params) : '');
	}
}

if (!function_exists('atm_audit_export_url')) {
	/**
	 * Build the CSV export URL with the current filters.
	 *
	 * @return string
	 */
	function atm_audit_export_url()
	{
		$params = atm_audit_query_params();
		$params['export'] = 'csv';
		return 'audit_log.php?' . http_build_query($params);
	}
}

if (!function_exists('atm_audit_csv_value')) {
	/**
	 * Protect exported cells from spreadsheet formula execution.
	 *
	 * @param mixed $value
	 * @return string
	 */
	function atm_audit_csv_value($value)
	{
		$value = (string)$value;
		if (preg_match('/^[=+\-@\t\r]/', $value)) {
			$value = "'" . $value;
		}
		return $value;
	}
}

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
	$rows = atm_admin_audit_list(10000, 0, $filter, $filters);
	$filename = 'admin-audit-' . date('Ymd-His') . '.csv';

	header('Content-Type: text/csv; charset=utf-8');
	header('Content-Disposition: attachment; filename="' . $filename . '"');
	header('X-Content-Type-Options: nosniff');

	$out = fopen('php://output', 'w');
	if ($out === false) exit;
	fwrite($out, "\xEF\xBB\xBF");
	fputcsv($out, array(
		__('Date'),
		__('User'),
		__('Event'),
		__('Details'),
		__('IP address'),
		__('User agent'),
	), ';');
	foreach ($rows as $row) {
		fputcsv($out, array(
			date('d.m.Y H:i:s', (int)$row['created']),
			atm_audit_csv_value($row['user_name']),
			atm_audit_csv_value(atm_admin_audit_label($row['action'])),
			atm_audit_csv_value(atm_admin_audit_details($row['details'])),
			atm_audit_csv_value($row['ip']),
			atm_audit_csv_value($row['user_agent']),
		), ';');
	}
	fclose($out);
	atm_admin_audit(
		'audit_export',
		'action(' . $filter . '), user(' . $filterUser . '), q(' . $filterQ . '), rows(' . count($rows) . ')',
		(int)$_SESSION['user']['id'],
		(string)$_SESSION['user']['name']
	);
	exit;
}

$perPage = 50;
$total = atm_admin_audit_count($filter, $filters);
$pages = max(1, (int)ceil($total / $perPage));
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
if ($page > $pages) $page = $pages;
$offset = ($page - 1) * $perPage;
$rows = atm_admin_audit_list($perPage, $offset, $filter, $filters);

$pageTitle = __('Audit log');
$pageNav = $pageTitle;
$pageNavr = '';
include_once ROOT . '/admin/template/header.php';
?>

<style>
.audit-filters { display:flex; flex-wrap:wrap; gap:8px; align-items:center; }
.audit-filters label { display:flex; align-items:center; gap:5px; color:#556; font-size:12px; }
.audit-filters input[type="text"], .audit-filters input[type="date"], .audit-filters select { height:32px; box-sizing:border-box; }
.audit-filters input[type="text"] { width:150px; }
.audit-filters input[type="date"] { width:145px; }
.audit-filters .audit-filter-actions { display:flex; gap:6px; flex-wrap:wrap; }
.audit-export { margin-left:auto; }
.audit-details { min-width:240px; }
.audit-details .comment { display:block; margin-top:3px; }
@media screen and (max-width: 900px) {
	.audit-filters input[type="text"], .audit-filters input[type="date"], .audit-filters select { width:100%; }
	.audit-filters label { width:100%; flex-direction:column; align-items:stretch; }
	.audit-export { margin-left:0; }
}
</style>

<div class="list">
	<div class="title"><?php echo h(__('Audit log')); ?></div>
	<div class="level1">
		<div class="head">
			<div class="title settings"><?php echo h(__('Security events')); ?></div>
			<div class="title-r"><?php echo h(sprintf(__('Total: %d'), $total)); ?></div>
			<div class="clear"></div>
		</div>
		<div class="items">
			<div class="setting-item">
				<div class="left">
					<?php echo h(__('Filter')); ?>
					<span class="comment"><?php echo h(__('The audit trail is stored in the database and is not tied to the file action log.')); ?></span>
				</div>
				<div class="right">
					<form method="GET" action="audit_log.php" class="audit-filters">
						<label>
							<span><?php echo h(__('Event')); ?></span>
							<select name="action">
								<option value=""><?php echo h(__('All events')); ?></option>
								<?php foreach ($actions as $actionKey => $actionLabel): ?>
									<option value="<?php echo h($actionKey); ?>"<?php echo $filter === $actionKey ? ' selected="selected"' : ''; ?>><?php echo h($actionLabel); ?></option>
								<?php endforeach; ?>
							</select>
						</label>
						<label>
							<span><?php echo h(__('User')); ?></span>
							<input type="text" name="user" value="<?php echo h($filterUser); ?>" autocomplete="off" />
						</label>
						<label>
							<span><?php echo h(__('Search')); ?></span>
							<input type="text" name="q" value="<?php echo h($filterQ); ?>" autocomplete="off" />
						</label>
						<label>
							<span><?php echo h(__('From')); ?></span>
							<input type="date" name="from" value="<?php echo h($filterFrom); ?>" />
						</label>
						<label>
							<span><?php echo h(__('To')); ?></span>
							<input type="date" name="to" value="<?php echo h($filterTo); ?>" />
						</label>
						<span class="audit-filter-actions">
							<button type="submit" class="atm-button"><?php echo h(__('Apply')); ?></button>
							<?php if ($filter !== '' || $filterUser !== '' || $filterQ !== '' || $filterFrom !== '' || $filterTo !== ''): ?>
								<a class="atm-button secondary" href="audit_log.php"><?php echo h(__('Reset')); ?></a>
							<?php endif; ?>
							<a class="atm-button secondary audit-export" href="<?php echo h(atm_audit_export_url()); ?>"><?php echo h(__('Export CSV')); ?></a>
						</span>
					</form>
				</div>
				<div class="clear"></div>
			</div>
		</div>
		<div class="items">
			<table class="grid mobile-cards" cellspacing="0" width="100%">
				<tr class="mobile-table-head">
					<th><?php echo h(__('Date')); ?></th>
					<th><?php echo h(__('User')); ?></th>
					<th><?php echo h(__('Event')); ?></th>
					<th><?php echo h(__('Details')); ?></th>
					<th><?php echo h(__('IP address')); ?></th>
				</tr>
				<?php if (empty($rows)): ?>
					<tr><td colspan="5"><?php echo h(__('No security events yet.')); ?></td></tr>
				<?php else: ?>
					<?php foreach ($rows as $row): ?>
						<?php $readableDetails = atm_admin_audit_details($row['details']); ?>
						<tr>
							<td data-label="<?php echo h(__('Date')); ?>"><?php echo h(date('d.m.Y H:i:s', (int)$row['created'])); ?></td>
							<td data-label="<?php echo h(__('User')); ?>">
								<?php echo h($row['user_name']); ?>
								<?php if (!empty($row['user_id'])): ?><span class="comment">ID <?php echo (int)$row['user_id']; ?></span><?php endif; ?>
							</td>
							<td data-label="<?php echo h(__('Event')); ?>"><?php echo h(atm_admin_audit_label($row['action'])); ?></td>
							<td data-label="<?php echo h(__('Details')); ?>" class="audit-details">
								<?php echo h(atm_audit_excerpt($readableDetails)); ?>
								<?php if (!empty($row['user_agent'])): ?>
									<span class="comment" title="<?php echo h($row['user_agent']); ?>"><?php echo h(atm_audit_excerpt($row['user_agent'], 90)); ?></span>
								<?php endif; ?>
							</td>
							<td data-label="<?php echo h(__('IP address')); ?>"><?php echo h($row['ip']); ?></td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</table>

			<?php if ($pages > 1): ?>
				<div class="pagination">
					<?php if ($page > 1): ?><a class="pages" href="<?php echo h(atm_audit_page_url($page - 1)); ?>">&laquo;</a><?php endif; ?>
					<?php
					$start = max(1, $page - 3);
					$end = min($pages, $page + 3);
					for ($i = $start; $i <= $end; $i++):
					?>
						<?php if ($i === $page): ?>
							<strong><?php echo $i; ?></strong>
						<?php else: ?>
							<a class="pages" href="<?php echo h(atm_audit_page_url($i)); ?>"><?php echo $i; ?></a>
						<?php endif; ?>
					<?php endfor; ?>
					<?php if ($page < $pages): ?><a class="pages" href="<?php echo h(atm_audit_page_url($page + 1)); ?>">&raquo;</a><?php endif; ?>
				</div>
			<?php endif; ?>
		</div>
	</div>
</div>

<p>
	<a class="atm-button secondary" href="security.php"><?php echo h(__('Security center')); ?></a>
	<a class="atm-button secondary" href="system_log.php"><?php echo h(__('Action log')); ?></a>
	<a class="atm-button secondary" href="notifications.php"><?php echo h(__('Notifications')); ?></a>
</p>

<?php include_once ROOT . '/admin/template/footer.php'; ?>
