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
<div class="list">
	<div class="title"><?php echo h($label); ?></div>
	<div class="level1">
		<div class="items">
			<table class="grid mobile-cards" cellspacing="0" width="100%">
				<tr class="mobile-table-head">
					<th><?php echo h(__('Title')); ?></th>
					<th><?php echo h(__('Author ID')); ?></th>
					<th><?php echo h(__('Date')); ?></th>
				</tr>
				<?php if (empty($pending[$module])): ?>
					<tr><td colspan="3"><?php echo h(__('No pending materials.')); ?></td></tr>
				<?php else: ?>
					<?php foreach ($pending[$module] as $row): ?>
						<tr>
							<td data-label="<?php echo h(__('Title')); ?>"><?php echo h($row['title']); ?></td>
							<td data-label="<?php echo h(__('Author ID')); ?>"><?php echo (int)$row['author_id']; ?></td>
							<td data-label="<?php echo h(__('Date')); ?>"><?php echo h(atm_moderation_date($row['date'])); ?></td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</table>
			<p><a class="atm-button secondary" href="materials_list.php?m=<?php echo h($module); ?>&amp;premoder=1"><?php echo h(__('Open moderation list')); ?></a></p>
		</div>
	</div>
</div>
<?php endforeach; ?>


<div class="list" id="comments-list">
	<div class="title"><?php echo h(__('Comments')); ?></div>
	<div class="level1">
		<div class="items">
			<table class="grid mobile-cards" cellspacing="0" width="100%">
				<tr class="mobile-table-head">
					<th><?php echo h(__('Comment')); ?></th>
					<th><?php echo h(__('Module')); ?></th>
					<th><?php echo h(__('Author')); ?></th>
					<th><?php echo h(__('Date')); ?></th>
					<th></th>
				</tr>
				<?php if (empty($comments)): ?>
					<tr><td colspan="5"><?php echo h(__('No pending comments.')); ?></td></tr>
				<?php else: ?>
					<?php foreach ($comments as $comment): ?>
						<tr>
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
		</div>
	</div>
</div>


<?php include_once ROOT . '/admin/template/footer.php'; ?>
