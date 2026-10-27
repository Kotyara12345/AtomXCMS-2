<?php
##################################################
##												##
## @Author:       AtomX CMS                     ##
## @Version:      1.0                           ##
## @Project:      CMS                           ##
## @package       CMS AtomX                     ##
## @subpackege    Admin notifications           ##
##################################################

include_once '../sys/boot.php';
include_once ROOT . '/admin/inc/adm_boot.php';

// Moderation notifications are useful to every admin role, so the
// notification center is available to all authenticated administrators.
if (empty($_SESSION['user'])) {
	redirect('/admin/');
}

$userId = (int)$_SESSION['user']['id'];
$counts = atm_admin_notification_counts();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])
	&& $_POST['action'] === 'mark_all_read') {
	if (atm_admin_notification_mark_all_read($userId, $counts)) {
		atm_admin_audit(
			'notifications_read',
			'user id(' . $userId . ')',
			$userId,
			(string)$_SESSION['user']['name']
		);
		$_SESSION['message'] = __('All notifications are marked as read.');
	} else {
		$_SESSION['errors'] = __('Could not mark notifications as read.');
	}
	redirect('/admin/notifications.php');
}

$summary = atm_admin_notification_summary($counts, $userId);
$notifications = atm_admin_notifications_list($userId, 100);
$moderationItems = array(
	'news' => array('label' => __('News'), 'url' => 'materials_list.php?m=news&premoder=1'),
	'loads' => array('label' => __('Loads'), 'url' => 'materials_list.php?m=loads&premoder=1'),
	'stat' => array('label' => __('Articles'), 'url' => 'materials_list.php?m=stat&premoder=1'),
	'comments' => array('label' => __('Comments'), 'url' => 'moderation.php#comments'),
);

$pageTitle = __('Notifications');
$pageNav = $pageTitle;
$pageNavr = '';

include_once ROOT . '/admin/template/header.php';
?>

<style>
.notifications-summary { display:flex; gap:12px; flex-wrap:wrap; margin:0 0 16px; }
.notifications-card { flex:1 1 180px; min-width:170px; padding:16px 18px; background:#fff; border:1px solid #d9e1e8; border-radius:6px; }
.notifications-card b { display:block; margin-bottom:4px; font-size:26px; line-height:1.1; color:#2f5f8a; }
.notifications-card span { color:#667; }
.notifications-card.warn b { color:#b36b00; }
.notifications-card.error b { color:#b21f2d; }
.notifications-list { overflow-wrap:anywhere; }
.notifications-item { display:flex; gap:12px; padding:12px 0; border-bottom:1px solid #edf1f4; }
.notifications-item:last-child { border-bottom:0; }
.notifications-item-main { flex:1; min-width:0; }
.notifications-item-title { font-weight:bold; }
.notifications-item-title a { color:#2f5f8a; text-decoration:none; }
.notifications-item-title a:hover { text-decoration:underline; }
.notifications-item-message { margin-top:4px; color:#556; font-size:13px; }
.notifications-item-meta { margin-top:5px; color:#8a96a0; font-size:12px; }
.notifications-item-status { flex:0 0 auto; }
.notifications-type { display:inline-block; min-width:74px; padding:3px 8px; border-radius:10px; text-align:center; font-size:11px; color:#456; background:#eef3f6; }
.notifications-type.warning { color:#8a5700; background:#fff2d9; }
.notifications-type.error { color:#a01622; background:#ffe3e6; }
.notifications-type.success { color:#3f6f00; background:#eaf7d8; }
.notifications-empty { padding:22px 0; color:#7b8790; }
.notifications-actions { margin:0 0 16px; }
.notifications-pending { margin:0 0 16px; padding:0; list-style:none; }
.notifications-pending li { padding:6px 0; }
.notifications-pending a { color:#2f5f8a; }
@media screen and (max-width: 700px) {
	.notifications-card { min-width:0; flex-basis:calc(50% - 6px); }
	.notifications-item { flex-direction:column; gap:6px; }
	.notifications-item-status { order:-1; }
}
</style>

<div class="notifications-summary">
	<div class="notifications-card<?php echo $summary['moderation_total'] > 0 ? ' warn' : ''; ?>">
		<b><?php echo (int)$summary['moderation_total']; ?></b>
		<span><?php echo h(__('New moderation items')); ?></span>
	</div>
	<div class="notifications-card<?php echo $summary['security'] > 0 ? ' error' : ''; ?>">
		<b><?php echo (int)$summary['security']; ?></b>
		<span><?php echo h(__('Unread security notifications')); ?></span>
	</div>
	<div class="notifications-card">
		<b><?php echo (int)$summary['total']; ?></b>
		<span><?php echo h(__('Total unread')); ?></span>
	</div>
</div>

<?php if ($summary['total'] > 0): ?>
	<div class="notifications-actions">
		<form method="post" action="notifications.php">
			<input type="hidden" name="csrf_token" value="<?php echo h(atm_admin_csrf_token()); ?>" />
			<input type="hidden" name="action" value="mark_all_read" />
			<button type="submit" class="save-button"><?php echo h(__('Mark all as read')); ?></button>
		</form>
	</div>
<?php endif; ?>

<div class="list">
	<div class="title"><?php echo h(__('Moderation')); ?></div>
	<div class="level1">
		<div class="items">
			<ul class="notifications-pending">
				<?php foreach ($moderationItems as $key => $item): ?>
					<?php
					$newCount = !empty($summary['moderation'][$key]) ? (int)$summary['moderation'][$key] : 0;
					$totalCount = !empty($counts[$key]) ? (int)$counts[$key] : 0;
					?>
					<li>
						<a href="<?php echo h($item['url']); ?>"><?php echo h($item['label']); ?></a>
						— <?php echo sprintf(__('new: %d, pending: %d'), $newCount, $totalCount); ?>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
	</div>
</div>

<div class="list">
	<div class="title"><?php echo h(__('Notification history')); ?></div>
	<div class="level1">
		<div class="items notifications-list">
			<?php if (empty($notifications)): ?>
				<div class="notifications-empty"><?php echo h(__('No security notifications yet.')); ?></div>
			<?php else: ?>
				<?php foreach ($notifications as $notification): ?>
					<?php
					$type = preg_match('/^[a-z0-9_-]+$/i', (string)$notification['type'])
						? (string)$notification['type'] : 'info';
					$unread = empty($notification['read_at']);
					$url = !empty($notification['url']) ? (string)$notification['url'] : 'security.php';
					?>
					<div class="notifications-item">
						<div class="notifications-item-status">
							<span class="notifications-type <?php echo h($type); ?>">
								<?php echo $unread ? h(__('New')) : h(__('Read')); ?>
							</span>
						</div>
						<div class="notifications-item-main">
							<div class="notifications-item-title">
								<a href="<?php echo h($url); ?>"><?php echo h($notification['title']); ?></a>
							</div>
							<?php if (!empty($notification['message'])): ?>
								<div class="notifications-item-message"><?php echo nl2br(h($notification['message'])); ?></div>
							<?php endif; ?>
							<div class="notifications-item-meta">
								<?php echo h(date('d.m.Y H:i:s', (int)$notification['created'])); ?>
								<?php if (!empty($notification['read_at'])): ?>
									&middot; <?php echo sprintf(__('read %s'), h(date('d.m.Y H:i:s', (int)$notification['read_at']))); ?>
								<?php endif; ?>
							</div>
						</div>
					</div>
				<?php endforeach; ?>
			<?php endif; ?>
		</div>
	</div>
</div>

<?php
include_once 'template/footer.php';
?>
