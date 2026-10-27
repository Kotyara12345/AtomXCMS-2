<?php
##################################################
##												##
## @Author:       AtomX CMS                     ##
## @Version:      1.0                           ##
## @Project:      CMS                           ##
## @package       CMS AtomX                     ##
## @subpackege    Moderation overview           ##
##################################################

include_once '../sys/boot.php';
include_once ROOT . '/admin/inc/adm_boot.php';

$Register = Register::getInstance();
$FpsDB = $Register['DB'];

if (empty($_SESSION['user'])) {
	redirect('/admin/');
}

$materials = array(
	'news' => __('News'),
	'loads' => __('Loads'),
	'stat' => __('Articles'),
);

/*
 * Bulk moderation is intentionally handled here instead of adding a
 * second implementation to the material and comment lists. The models
 * are used so caches, files and subscriber notifications keep working.
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])
	&& $_POST['action'] === 'bulk_moderate') {
	$bulkModule = isset($_POST['module']) ? (string)$_POST['module'] : '';
	$decision = isset($_POST['decision']) ? (string)$_POST['decision'] : '';
	$allowedModules = array_merge(array_keys($materials), array('comments'));
	$allowedDecisions = array('confirmed', 'rejected', 'delete');
	$ids = isset($_POST['bulk_ids']) && is_array($_POST['bulk_ids']) ? $_POST['bulk_ids'] : array();
	$ids = array_values(array_unique(array_filter(array_map('intval', $ids), function ($id) {
		return $id > 0;
	})));
	$ids = array_slice($ids, 0, 50);

	if (!in_array($bulkModule, $allowedModules, true) || !in_array($decision, $allowedDecisions, true)) {
		$_SESSION['errors'] = __('Unknown action.');
		redirect('/admin/moderation.php');
	}
	$isComments = ($bulkModule === 'comments');
	$aclAction = $isComments ? 'commentsList' : 'materialsList';
	$allowedForAction = $Register['ModManager']->getAllowedModules($aclAction);
	if (($isComments && empty($allowedForAction))
		|| (!$isComments && !in_array($bulkModule, $allowedForAction, true))) {
		$_SESSION['errors'] = __('Permission denied');
		redirect('/admin/moderation.php');
	}
	if (empty($ids)) {
		$_SESSION['errors'] = __('Select at least one item.');
		redirect('/admin/moderation.php');
	}

	$done = 0;
	$failed = 0;
	$processedIds = array();
	try {
		if ($bulkModule === 'comments') {
			$Model = $Register['ModManager']->getModelInstance('Comments');
			$Cache = new Cache;
			foreach ($ids as $id) {
				try {
					$entity = $Model->getById($id);
					if (empty($entity)) {
						$failed++;
						continue;
					}
					if (!in_array((string)$entity->getModule(), $allowedForAction, true)) {
						$failed++;
						continue;
					}
					if ($decision === 'delete') {
						$entity->delete();
					} else {
						$entity->setPremoder($decision);
						$entity->save();
					}
					$processedIds[] = $id;
					$done++;
					if (is_callable(array($entity, 'getEntity_id'))) {
						$Cache->clean(
							CACHE_MATCHING_TAG,
							array(
								'module_' . $entity->getModule(),
								'record_id_' . $entity->getEntity_id(),
							)
						);
					}
				} catch (Exception $e) {
					$failed++;
				}
			}
		} else {
			$Model = $Register['ModManager']->getModelInstance($bulkModule);
			$Cache = new Cache;
			foreach ($ids as $id) {
				try {
					$entity = $Model->getById($id);
					if (empty($entity)) {
						$failed++;
						continue;
					}
					if ($decision === 'delete') {
						$entity->delete();
					} else {
						$wasPublished = ((int)$entity->getAvailable() === 1
							&& $entity->getPremoder() === 'confirmed');
						$entity->setPremoder($decision);
						$entity->save();
						if ($decision === 'confirmed' && !$wasPublished
							&& (int)$entity->getAvailable() === 1
							&& function_exists('atm_subscription_notify_material')) {
							atm_subscription_notify_material(
								$bulkModule,
								$entity,
								atm_subscription_get_entity_category_ids($bulkModule, $entity)
							);
						}
					}
					$processedIds[] = $id;
					$done++;
				} catch (Exception $e) {
					$failed++;
				}
			}
			$Cache->clean(CACHE_MATCHING_TAG, array('module_' . $bulkModule));
		}
		$Register['DB']->cleanSqlCache();
	} catch (Exception $e) {
		$failed = count($ids);
	}

	atm_admin_audit(
		'bulk_moderated',
		'module(' . $bulkModule . '), decision(' . $decision . '), count(' . $done . '), ids(' . implode(',', $processedIds) . ')',
		(int)$_SESSION['user']['id'],
		(string)$_SESSION['user']['name']
	);
	atm_admin_notification_clear_cache();

	if ($done > 0) {
		$_SESSION['message'] = $failed > 0
			? sprintf(__('Bulk moderation completed: %d processed, %d skipped.'), $done, $failed)
			: sprintf(__('Bulk moderation completed: %d processed.'), $done);
	} else {
		$_SESSION['errors'] = __('No items were processed.');
	}
	redirect('/admin/moderation.php');
}
$pending = array();
$pendingCounts = array();

foreach ($materials as $module => $label) {
	$table = $FpsDB->getFullTableName($module);
	$rows = array();
	try {
		$rows = $FpsDB->query(
			"SELECT `id`, `title`, `date`, `author_id` FROM `{$table}` "
			. "WHERE `premoder` = 'nochecked' ORDER BY `date` DESC LIMIT 20"
		);
	} catch (Exception $e) {
		$rows = array();
	}
	$pending[$module] = !empty($rows) ? $rows : array();
	try {
		$pendingCounts[$module] = (int)$FpsDB->select(
			$module,
			DB_COUNT,
			array('cond' => array('premoder' => 'nochecked'))
		);
	} catch (Exception $e) {
		$pendingCounts[$module] = 0;
	}
}

$comments = array();
$commentsCount = 0;
try {
	$commentsTable = $FpsDB->getFullTableName('comments');
	$comments = $FpsDB->query(
		"SELECT `id`, `module`, `entity_id`, `name`, `user_id`, `message`, `date` "
		. "FROM `{$commentsTable}` WHERE `premoder` = 'nochecked' "
		. "ORDER BY `date` DESC LIMIT 40"
	);
	$commentsCount = (int)$FpsDB->select(
		'comments',
		DB_COUNT,
		array('cond' => array('premoder' => 'nochecked'))
	);
} catch (Exception $e) {
	$comments = array();
	$commentsCount = 0;
}
if (empty($comments)) $comments = array();

$commentModules = array();
try {
	$moduleRows = $FpsDB->query(
		"SELECT `module`, COUNT(*) AS `cnt` FROM `{$commentsTable}` "
		. "WHERE `premoder` = 'nochecked' GROUP BY `module` ORDER BY `cnt` DESC"
	);
	if (!empty($moduleRows)) {
		foreach ($moduleRows as $moduleRow) {
			$module = isset($moduleRow['module']) ? (string)$moduleRow['module'] : '';
			if ($module === '') continue;
			$commentModules[$module] = isset($moduleRow['cnt']) ? (int)$moduleRow['cnt'] : 0;
		}
	}
} catch (Exception $e) {
	$commentModules = array();
}

$totalPending = array_sum($pendingCounts) + $commentsCount;

/**
 * @param string $date
 * @return string
 */
function atm_moderation_date($date)
{
	$timestamp = strtotime((string)$date);
	if (!$timestamp) return (string)$date;
	return date('d.m.Y H:i', $timestamp);
}

/**
 * @param string $text
 * @param int $length
 * @return string
 */
function atm_moderation_excerpt($text, $length = 140)
{
	$text = trim(preg_replace('/\s+/u', ' ', (string)$text));
	if (function_exists('mb_strimwidth')) {
		return mb_strimwidth($text, 0, $length, '…', 'UTF-8');
	}
	if (strlen($text) <= $length) return $text;
	return substr($text, 0, max(0, $length - 3)) . '...';
}

$pageTitle = __('Moderation');
$pageNav = $pageTitle;
$pageNavr = '';
include_once ROOT . '/admin/template/header.php';
?>

<style>
.moderation-bulk-bar { display:flex; flex-wrap:wrap; gap:8px; margin:0 0 10px; }
.moderation-bulk-bar .atm-button { cursor:pointer; }
.moderation-check { width:38px; text-align:center; }
.moderation-check input { width:16px; height:16px; vertical-align:middle; }
@media screen and (max-width: 700px) {
	.moderation-bulk-bar { position:sticky; top:0; z-index:2; padding:8px 0; background:#fff; }
	.moderation-check { width:auto; text-align:left; }
}
</style>


<div class="list">
	<div class="title"><?php echo h(__('Moderation')); ?></div>
	<div class="level1">
		<div class="head">
			<div class="title settings"><?php echo h(__('Awaiting review')); ?></div>
			<div class="title-r"><?php echo h(sprintf(__('Total: %d'), $totalPending)); ?></div>
			<div class="clear"></div>
		</div>
		<div class="items">
			<?php foreach ($materials as $module => $label): ?>
				<div class="setting-item">
					<div class="left"><?php echo h($label); ?></div>
					<div class="right">
						<a class="atm-button secondary" href="materials_list.php?m=<?php echo h($module); ?>&amp;premoder=1">
							<?php echo h(__('Open moderation list')); ?> (<?php echo isset($pendingCounts[$module]) ? (int)$pendingCounts[$module] : 0; ?>)
						</a>
					</div>
					<div class="clear"></div>
				</div>
			<?php endforeach; ?>
			<div class="setting-item" id="comments">
				<div class="left"><?php echo h(__('Comments')); ?></div>
				<div class="right">
					<span class="atm-status-pill <?php echo $commentsCount > 0 ? 'warn' : 'ok'; ?>"><?php echo (int)$commentsCount; ?></span>
					<?php if (empty($commentModules)): ?>
						<span class="atm-muted"><?php echo h(__('No pending comments.')); ?></span>
					<?php else: ?>
						<?php foreach ($commentModules as $module => $count): ?>
							<a class="atm-button secondary" href="comments_list.php?m=<?php echo h($module); ?>&amp;premoder=1">
								<?php echo h(__($module)); ?> (<?php echo (int)$count; ?>)
							</a>
						<?php endforeach; ?>
					<?php endif; ?>
				</div>
				<div class="clear"></div>
			</div>
		</div>
	</div>
</div>


<?php foreach ($materials as $module => $label): ?>
<div class="list" id="moderation-<?php echo h($module); ?>">
	<div class="title"><?php echo h($label); ?></div>
	<div class="level1">
		<div class="items">
			<form method="post" action="moderation.php" class="moderation-bulk-form" data-atm-no-dirty>
				<input type="hidden" name="action" value="bulk_moderate" />
				<input type="hidden" name="module" value="<?php echo h($module); ?>" />
				<div class="moderation-bulk-bar">
					<button type="submit" class="atm-button" name="decision" value="confirmed"><?php echo h(__('Approve selected')); ?></button>
					<button type="submit" class="atm-button secondary" name="decision" value="rejected"><?php echo h(__('Reject selected')); ?></button>
					<button
						type="submit"
						class="atm-button danger"
						name="decision"
						value="delete"
						data-atm-danger
						data-atm-confirm="<?php echo h(__('Delete the selected items? This action cannot be undone.')); ?>"
						data-atm-confirm-ok="<?php echo h(__('Delete')); ?>"
					><?php echo h(__('Delete selected')); ?></button>
				</div>
				<table class="grid mobile-cards" cellspacing="0" width="100%">
					<tr class="mobile-table-head">
						<th class="moderation-check"><input type="checkbox" data-atm-select-all aria-label="<?php echo h(__('Select all')); ?>" /></th>
						<th><?php echo h(__('Title')); ?></th>
						<th><?php echo h(__('Author ID')); ?></th>
						<th><?php echo h(__('Date')); ?></th>
					</tr>
					<?php if (empty($pending[$module])): ?>
						<tr><td colspan="4"><?php echo h(__('No pending materials.')); ?></td></tr>
					<?php else: ?>
						<?php foreach ($pending[$module] as $row): ?>
							<tr>
								<td class="moderation-check" data-label="">
									<input type="checkbox" name="bulk_ids[]" value="<?php echo (int)$row['id']; ?>" data-atm-select-item aria-label="<?php echo h(sprintf(__('Select %s'), $row['title'])); ?>" />
								</td>
								<td data-label="<?php echo h(__('Title')); ?>"><?php echo h($row['title']); ?></td>
								<td data-label="<?php echo h(__('Author ID')); ?>"><?php echo (int)$row['author_id']; ?></td>
								<td data-label="<?php echo h(__('Date')); ?>"><?php echo h(atm_moderation_date($row['date'])); ?></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</table>
			</form>
			<p><a class="atm-button secondary" href="materials_list.php?m=<?php echo h($module); ?>&amp;premoder=1"><?php echo h(__('Open moderation list')); ?></a></p>
		</div>
	</div>
</div>
<?php endforeach; ?>


<div class="list" id="comments-list">
	<div class="title"><?php echo h(__('Comments')); ?></div>
	<div class="level1">
		<div class="items">
			<form method="post" action="moderation.php" class="moderation-bulk-form" data-atm-no-dirty>
				<input type="hidden" name="action" value="bulk_moderate" />
				<input type="hidden" name="module" value="comments" />
				<div class="moderation-bulk-bar">
					<button type="submit" class="atm-button" name="decision" value="confirmed"><?php echo h(__('Approve selected')); ?></button>
					<button type="submit" class="atm-button secondary" name="decision" value="rejected"><?php echo h(__('Reject selected')); ?></button>
					<button
						type="submit"
						class="atm-button danger"
						name="decision"
						value="delete"
						data-atm-danger
						data-atm-confirm="<?php echo h(__('Delete the selected comments? This action cannot be undone.')); ?>"
						data-atm-confirm-ok="<?php echo h(__('Delete')); ?>"
					><?php echo h(__('Delete selected')); ?></button>
				</div>
				<table class="grid mobile-cards" cellspacing="0" width="100%">
					<tr class="mobile-table-head">
						<th class="moderation-check"><input type="checkbox" data-atm-select-all aria-label="<?php echo h(__('Select all')); ?>" /></th>
						<th><?php echo h(__('Comment')); ?></th>
						<th><?php echo h(__('Module')); ?></th>
						<th><?php echo h(__('Author')); ?></th>
						<th><?php echo h(__('Date')); ?></th>
						<th></th>
					</tr>
					<?php if (empty($comments)): ?>
						<tr><td colspan="6"><?php echo h(__('No pending comments.')); ?></td></tr>
					<?php else: ?>
						<?php foreach ($comments as $comment): ?>
							<tr>
								<td class="moderation-check" data-label="">
									<input type="checkbox" name="bulk_ids[]" value="<?php echo (int)$comment['id']; ?>" data-atm-select-item aria-label="<?php echo h(sprintf(__('Select comment by %s'), $comment['name'])); ?>" />
								</td>
								<td data-label="<?php echo h(__('Comment')); ?>"><?php echo h(atm_moderation_excerpt($comment['message'])); ?></td>
								<td data-label="<?php echo h(__('Module')); ?>"><?php echo h(!empty($comment['module']) ? __($comment['module']) : ''); ?></td>
								<td data-label="<?php echo h(__('Author')); ?>"><?php echo h($comment['name']); ?></td>
								<td data-label="<?php echo h(__('Date')); ?>"><?php echo h(atm_moderation_date($comment['date'])); ?></td>
								<td data-label="">
									<a class="atm-button secondary" href="comments_list.php?m=<?php echo h($comment['module']); ?>&amp;premoder=1"><?php echo h(__('Moderate')); ?></a>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</table>
			</form>
		</div>
	</div>
</div>


<?php include_once ROOT . '/admin/template/footer.php'; ?>
