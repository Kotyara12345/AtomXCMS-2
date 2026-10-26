<?php
##################################################
##												##
## @Author:       AtomX CMS                     ##
## @Version:      1.0                           ##
## @Project:      CMS                           ##
## @package       CMS AtomX                     ##
## @subpackege    Administrator profile         ##
##################################################

include_once '../sys/boot.php';
include_once ROOT . '/admin/inc/adm_boot.php';

$Register = Register::getInstance();
$FpsDB = $Register['DB'];

if (empty($_SESSION['user'])) {
	redirect('/admin/');
}

$userId = (int)$_SESSION['user']['id'];
$currentUserRow = $FpsDB->select('users', DB_FIRST, array(
	'cond' => array('id' => $userId),
));
if (empty($currentUserRow)) {
	unset($_SESSION['user'], $_SESSION['adm_panel_authorize']);
	redirect('/admin/');
}
$_SESSION['user'] = $currentUserRow[0];

$userName = isset($_SESSION['user']['name']) ? (string)$_SESSION['user']['name'] : '';
$userEmail = isset($_SESSION['user']['email']) ? (string)$_SESSION['user']['email'] : '';
$isAdmin = ((int)$_SESSION['user']['status'] === 4);

$groupInfo = $Register['ACL']->get_user_group((int)$_SESSION['user']['status']);
$groupTitle = !empty($groupInfo['title']) ? $groupInfo['title'] : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$action = isset($_POST['action']) ? (string)$_POST['action'] : '';
	$csrf = isset($_POST['csrf_token']) ? (string)$_POST['csrf_token'] : '';

	if (!atm_admin_csrf_check($csrf)) {
		$_SESSION['errors'] = __('Session expired, please reload the page');
		redirect('/admin/profile.php');
	}

	if ($action === 'save_profile') {
		$newName = isset($_POST['name']) ? trim((string)$_POST['name']) : '';
		$newEmail = isset($_POST['email']) ? trim((string)$_POST['email']) : '';
		$errors = array();

		if ($newName === '') {
			$errors[] = __('Fill in the "Name" field');
		} elseif (strlen($newName) > 32) {
			$errors[] = __('The name must not be longer than 32 characters.');
		} elseif (preg_match('/[\x00-\x1F\x7F]/', $newName)) {
			$errors[] = __('The name contains invalid characters.');
		}

		if ($newEmail !== '' && (!filter_var($newEmail, FILTER_VALIDATE_EMAIL) || strlen($newEmail) > 64)) {
			$errors[] = __('Please enter a valid e-mail address.');
		}

		if ($newName !== $userName) {
			$duplicate = $FpsDB->select('users', DB_FIRST, array(
				'cond' => array('name' => $newName),
			));
			if (!empty($duplicate) && (int)$duplicate[0]['id'] !== $userId) {
				$errors[] = sprintf(__('The name %s is already in use.'), $newName);
			}
		}

		if (!empty($errors)) {
			$_SESSION['errors'] = implode('<br />', array_map('h', $errors));
			redirect('/admin/profile.php');
		}

		$before = array('name' => $userName, 'email' => $userEmail);
		$FpsDB->query(
			"UPDATE `" . $FpsDB->getFullTableName('users') . "` SET "
			. "`name` = '" . $FpsDB->escape($newName) . "', "
			. "`email` = '" . $FpsDB->escape($newEmail) . "' "
			. "WHERE `id` = " . $userId
		);
		$FpsDB->cleanSqlCache();

		$_SESSION['user']['name'] = $newName;
		$_SESSION['user']['email'] = $newEmail;
		atm_admin_session_touch($_SESSION['user']);
		$auditDetails = array();
		if ($before['name'] !== $newName) {
			$auditDetails[] = 'логин: ' . $before['name'] . ' → ' . $newName;
		}
		if ($before['email'] !== $newEmail) {
			$auditDetails[] = 'e-mail: ' . ($before['email'] !== '' ? $before['email'] : '—') . ' → ' . ($newEmail !== '' ? $newEmail : '—');
		}
		if (empty($auditDetails)) $auditDetails[] = 'без изменений';
		atm_admin_audit('profile_updated', implode('; ', $auditDetails), $userId, $newName);

		$_SESSION['message'] = __('Profile updated.');
		redirect('/admin/profile.php');
	}

	if ($action === 'change_password') {
		$current = isset($_POST['current_password']) ? (string)$_POST['current_password'] : '';
		$new = isset($_POST['new_password']) ? (string)$_POST['new_password'] : '';
		$confirm = isset($_POST['confirm_password']) ? (string)$_POST['confirm_password'] : '';
		$minLength = (int)Config::read('min_password_lenght');
		if ($minLength < 5) $minLength = 5;
		$errors = array();

		if ($current === '' || !atm_password_verify($current, $_SESSION['user']['passw'])) {
			$errors[] = __('Invalid password');
		}
		if (strlen($new) < $minLength) {
			$errors[] = sprintf(__('The password must contain at least %d characters.'), $minLength);
		}
		if ($new !== $confirm) {
			$errors[] = __('Passwords are different');
		}

		if (!empty($errors)) {
			$_SESSION['errors'] = implode('<br />', array_map('h', $errors));
			redirect('/admin/profile.php');
		}

		$newHash = atm_password_hash($new);
		$FpsDB->query(
			"UPDATE `" . $FpsDB->getFullTableName('users') . "` SET "
			. "`passw` = '" . $FpsDB->escape($newHash) . "' "
			. "WHERE `id` = " . $userId
		);
		$FpsDB->cleanSqlCache();
		$_SESSION['user']['passw'] = $newHash;

		if (function_exists('atm_admin_remember_purge')) {
			atm_admin_remember_purge($userId);
		}
		atm_admin_session_revoke_others($userId);
		atm_admin_audit('password_changed', '', $userId, $userName);

		$_SESSION['message'] = __('Password changed.');
		redirect('/admin/profile.php');
	}
}

$pageTitle = __('Profile');
$pageNav = $pageTitle;
$pageNavr = '';
include_once ROOT . '/admin/template/header.php';
?>


<div class="list">
	<div class="title"><?php echo h(__('Profile')); ?></div>
	<div class="level1">
		<div class="head">
			<div class="title settings"><?php echo h(__('Account')); ?></div>
			<div class="title-r"><?php echo h($userName); ?></div>
			<div class="clear"></div>
		</div>
		<div class="items">
			<div class="setting-item">
				<div class="left"><?php echo h(__('User ID')); ?></div>
				<div class="right"><?php echo $userId; ?></div>
				<div class="clear"></div>
			</div>
			<div class="setting-item">
				<div class="left"><?php echo h(__('Group')); ?></div>
				<div class="right"><?php echo h($groupTitle); ?></div>
				<div class="clear"></div>
			</div>
			<div class="setting-item">
				<div class="left"><?php echo h(__('Security')); ?></div>
				<div class="right"><a class="atm-button secondary" href="security.php"><?php echo h(__('Security center')); ?></a></div>
				<div class="clear"></div>
			</div>
		</div>
	</div>
</div>


<form method="POST" action="profile.php" autocomplete="on">
<div class="list">
	<div class="title"><?php echo h(__('Edit profile')); ?></div>
	<div class="level1">
		<div class="items">
			<input type="hidden" name="csrf_token" value="<?php echo h(atm_admin_csrf_token()); ?>" />
			<input type="hidden" name="action" value="save_profile" />
			<div class="setting-item">
				<div class="left">
					<?php echo h(__('Login')); ?>
					<span class="comment"><?php echo h(__('Used to sign in to the admin panel.')); ?></span>
				</div>
				<div class="right"><input type="text" name="name" value="<?php echo h($userName); ?>" maxlength="32" autocomplete="username" required /></div>
				<div class="clear"></div>
			</div>
			<div class="setting-item">
				<div class="left">
					<?php echo h(__('E-mail')); ?>
					<span class="comment"><?php echo h(__('Administrator email address.')); ?></span>
				</div>
				<div class="right"><input type="email" name="email" value="<?php echo h($userEmail); ?>" maxlength="64" autocomplete="email" /></div>
				<div class="clear"></div>
			</div>
			<div class="setting-item">
				<div class="left"></div>
				<div class="right"><input class="save-button" type="submit" value="<?php echo h(__('Save')); ?>" /></div>
				<div class="clear"></div>
			</div>
		</div>
	</div>
</div>
</form>


<form method="POST" action="profile.php" autocomplete="off">
<div class="list">
	<div class="title"><?php echo h(__('Change password')); ?></div>
	<div class="level1">
		<div class="items">
			<input type="hidden" name="csrf_token" value="<?php echo h(atm_admin_csrf_token()); ?>" />
			<input type="hidden" name="action" value="change_password" />
			<div class="setting-item">
				<div class="left">
					<?php echo h(__('Current password')); ?>
				</div>
				<div class="right"><input type="password" name="current_password" autocomplete="current-password" required /></div>
				<div class="clear"></div>
			</div>
			<div class="setting-item">
				<div class="left">
					<?php echo h(__('New password')); ?>
					<span class="comment"><?php echo h(sprintf(__('At least %d characters.'), max(5, (int)Config::read('min_password_lenght')))); ?></span>
				</div>
				<div class="right"><input type="password" name="new_password" autocomplete="new-password" required /></div>
				<div class="clear"></div>
			</div>
			<div class="setting-item">
				<div class="left"><?php echo h(__('Repeat password')); ?></div>
				<div class="right"><input type="password" name="confirm_password" autocomplete="new-password" required /></div>
				<div class="clear"></div>
			</div>
			<div class="setting-item">
				<div class="left"></div>
				<div class="right"><input class="save-button" type="submit" value="<?php echo h(__('Save')); ?>" /></div>
				<div class="clear"></div>
			</div>
		</div>
	</div>
</div>
</form>


<?php include_once ROOT . '/admin/template/footer.php'; ?>
