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

$filter = isset($_GET['action']) ? trim((string)$_GET['action']) : '';
$actions = atm_admin_audit_actions();
if ($filter !== '' && !isset($actions[$filter])) {
	$filter = '';
}

$perPage = 50;
$total = atm_admin_audit_count($filter);
$pages = max(1, (int)ceil($total / $perPage));
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
if ($page > $pages) $page = $pages;
$offset = ($page - 1) * $perPage;
$rows = atm_admin_audit_list($perPage, $offset, $filter);

if (!function_exists('atm_audit_excerpt')) {
	/**
	 * @param string $text
	 * @param int $length
	 * @return string
	 */
	function atm_audit_excerpt($text, $length = 180)
	{
		$text = (string)$text;
		if (function_exists('mb_strimwidth')) {
			return mb_strimwidth($text, 0, $length, '…', 'UTF-8');
		}
		if (strlen($text) <= $length) return $text;
		return substr($text, 0, max(0, $length - 3)) . '...';
	}
}

/**
 * Build a pagination/filter URL.
 *
 * @param int $page
 * @return string
 */
function atm_audit_page_url($page)
{
	$params = array();
	if (!empty($_GET['action'])) $params['action'] = (string)$_GET['action'];
	if ($page > 1) $params['page'] = (int)$page;
	return 'audit_log.php' . (!empty($params) ? '?' . http_build_query($params) : '');
}

$pageTitle = __('Audit log');
$pageNav = $pageTitle;
$pageNavr = '';
include_once ROOT . '/admin/template/header.php';
?>


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
					<form method="GET" action="audit_log.php" class="server-config-toolbar">
						<select name="action">
							<option value=""><?php echo h(__('All events')); ?></option>
							<?php foreach ($actions as $actionKey => $actionLabel): ?>
								<option value="<?php echo h($actionKey); ?>"<?php echo $filter === $actionKey ? ' selected="selected"' : ''; ?>><?php echo h($actionLabel); ?></option>
							<?php endforeach; ?>
						</select>
						<button type="submit" class="atm-button"><?php echo h(__('Apply')); ?></button>
						<?php if ($filter !== ''): ?><a class="atm-button secondary" href="audit_log.php"><?php echo h(__('Reset')); ?></a><?php endif; ?>
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
						<tr>
							<td data-label="<?php echo h(__('Date')); ?>"><?php echo h(date('d.m.Y H:i:s', (int)$row['created'])); ?></td>
							<td data-label="<?php echo h(__('User')); ?>">
								<?php echo h($row['user_name']); ?>
								<?php if (!empty($row['user_id'])): ?><span class="comment">ID <?php echo (int)$row['user_id']; ?></span><?php endif; ?>
							</td>
							<td data-label="<?php echo h(__('Event')); ?>"><?php echo h(atm_admin_audit_label($row['action'])); ?></td>
							<td data-label="<?php echo h(__('Details')); ?>">
								<?php echo h(atm_audit_excerpt($row['details'])); ?>
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
</p>


<?php include_once ROOT . '/admin/template/footer.php'; ?>
