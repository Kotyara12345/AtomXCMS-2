<?php
##################################################
##												##
## @Author:       AtomX CMS                     ##
## @Version:      1.0                           ##
## @Project:      CMS                           ##
## @package       CMS AtomX                     ##
## @subpackege    Security center               ##
##################################################

include_once '../sys/boot.php';
include_once ROOT . '/admin/inc/adm_boot.php';

$Register = Register::getInstance();
$FpsDB = $Register['DB'];

if (empty($_SESSION['user'])) {
	redirect('/admin/');
}

$userId = (int)$_SESSION['user']['id'];
$userName = isset($_SESSION['user']['name']) ? (string)$_SESSION['user']['name'] : '';
$isAdmin = ((int)$_SESSION['user']['status'] === 4);

// Refresh the stored user row: the password may have been changed in
// another session, and the security page must verify the current one.
$currentUserRow = $FpsDB->select('users', DB_FIRST, array(
	'cond' => array('id' => $userId),
));
if (empty($currentUserRow)) {
	unset($_SESSION['user'], $_SESSION['adm_panel_authorize']);
	redirect('/admin/');
}
$_SESSION['user'] = $currentUserRow[0];
$userName = isset($_SESSION['user']['name']) ? (string)$_SESSION['user']['name'] : $userName;
$isAdmin = ((int)$_SESSION['user']['status'] === 4);


if (!function_exists('atm_security_excerpt')) {
	/**
	 * Shorten text without requiring mbstring.
	 *
	 * @param string $text
	 * @param int $length
	 * @return string
	 */
	function atm_security_excerpt($text, $length = 120)
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
 * Redirect back to the security page with a message.
 *
 * @param string $type
 * @param string $message
 * @param string $extra
 */
function atm_security_redirect($type, $message, $extra = '')
{
	$_SESSION[$type === 'error' ? 'errors' : 'message'] = $message;
	redirect('/admin/security.php' . $extra);
}


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$action = isset($_POST['action']) ? (string)$_POST['action'] : '';
	$csrf = isset($_POST['csrf_token']) ? (string)$_POST['csrf_token'] : '';

	if (!atm_admin_csrf_check($csrf)) {
		atm_security_redirect('error', __('Session expired, please reload the page'));
	}

	switch ($action) {
		case 'enable_2fa':
			$existingTwofa = atm_admin_2fa_get($userId);
			if (!empty($existingTwofa['enabled'])) {
				atm_security_redirect('error', __('Two-factor authentication is already enabled.'));
			}

			$secret = isset($_SESSION['admin_2fa_setup_secret'])
				? (string)$_SESSION['admin_2fa_setup_secret'] : '';
			$code = isset($_POST['code']) ? trim((string)$_POST['code']) : '';

			if ($secret === '') {
				atm_security_redirect('error', __('The setup session has expired. Please start again.'));
			}
			if ($code === '' || !atm_admin_totp_verify($secret, $code)) {
				atm_security_redirect('error', __('Invalid two-factor code'));
			}

			$backupCodes = atm_admin_2fa_backup_codes_generate(8);
			$saved = atm_admin_2fa_save(
				$userId,
				$secret,
				true,
				atm_admin_2fa_backup_codes_hash($backupCodes)
			);
			if (!$saved) {
				atm_security_redirect('error', __('Could not save the security settings. Please try again.'));
			}

			unset($_SESSION['admin_2fa_setup_secret']);
			$_SESSION['admin_2fa_new_backup_codes'] = $backupCodes;
			atm_admin_audit('2fa_enabled', 'backup codes: 8', $userId, $userName);
			atm_admin_session_revoke_others($userId);
			atm_security_redirect('message', __('Two-factor authentication enabled.'), '?codes=1');
			break;

		case 'disable_2fa':
			$password = isset($_POST['password']) ? (string)$_POST['password'] : '';
			$code = isset($_POST['code']) ? trim((string)$_POST['code']) : '';
			$row = atm_admin_2fa_get($userId);

			if (empty($row) || empty($row['enabled'])) {
				atm_security_redirect('error', __('Two-factor authentication is not enabled.'));
			}
			if ($password === '' || !atm_password_verify($password, $_SESSION['user']['passw'])) {
				atm_security_redirect('error', __('Invalid password'));
			}
			$usedBackup = false;
			$remaining = null;
			if ($code === '' || !atm_admin_2fa_verify_code($userId, $code, $usedBackup, $remaining)) {
				atm_security_redirect('error', __('Invalid two-factor code'));
			}

			atm_admin_2fa_delete($userId);
			atm_admin_audit('2fa_disabled', 'backup code used: ' . ($usedBackup ? 'yes' : 'no'), $userId, $userName);
			atm_security_redirect('message', __('Two-factor authentication disabled.'));
			break;

		case 'regenerate_codes':
			$password = isset($_POST['password']) ? (string)$_POST['password'] : '';
			$code = isset($_POST['code']) ? trim((string)$_POST['code']) : '';
			$row = atm_admin_2fa_get($userId);

			if (empty($row) || empty($row['enabled'])) {
				atm_security_redirect('error', __('Two-factor authentication is not enabled.'));
			}
			if ($password === '' || !atm_password_verify($password, $_SESSION['user']['passw'])) {
				atm_security_redirect('error', __('Invalid password'));
			}
			$usedBackup = false;
			$remaining = null;
			if ($code === '' || !atm_admin_2fa_verify_code($userId, $code, $usedBackup, $remaining)) {
				atm_security_redirect('error', __('Invalid two-factor code'));
			}

			$backupCodes = atm_admin_2fa_backup_codes_generate(8);
			if (!atm_admin_2fa_save($userId, $row['secret'], true, atm_admin_2fa_backup_codes_hash($backupCodes))) {
				atm_security_redirect('error', __('Could not save the security settings. Please try again.'));
			}

			$_SESSION['admin_2fa_new_backup_codes'] = $backupCodes;
			atm_admin_audit('2fa_codes', 'new backup codes: 8', $userId, $userName);
			atm_security_redirect('message', __('Backup codes regenerated.'), '?codes=1');
			break;

		case 'revoke_session':
			$hash = isset($_POST['session_hash']) ? strtolower(trim((string)$_POST['session_hash'])) : '';
			$targetUserId = $isAdmin && !empty($_POST['user_id'])
				? (int)$_POST['user_id'] : $userId;

			$found = null;
			if (!atm_admin_session_revoke($hash, $targetUserId, $found)) {
				atm_security_redirect('error', __('Session not found.'));
			}

			if (hash_equals(atm_admin_session_hash(), $hash)) {
				atm_admin_audit('session_revoked', 'current session', $userId, $userName);
				atm_admin_remember_forget();
				unset($_SESSION['user'], $_SESSION['adm_panel_authorize']);
				session_regenerate_id(true);
				$_SESSION['admin_login_notice'] = __('Your session has been terminated. Please sign in again.');
				redirect('/admin/');
			}

			atm_admin_audit(
				'session_revoked',
				'user id: ' . $targetUserId . ', ip: ' . (isset($found['ip']) ? $found['ip'] : ''),
				$userId,
				$userName
			);
			atm_security_redirect('message', __('Session terminated.'));
			break;

		case 'revoke_others':
			$count = atm_admin_session_revoke_others($userId);
			atm_admin_audit('session_revoked_all', 'terminated: ' . $count, $userId, $userName);
			atm_security_redirect('message', sprintf(__('Other sessions terminated: %d'), $count));
			break;

		case 'clear_attempts':
			if (!$isAdmin) {
				atm_security_redirect('error', __('Permission denied'));
			}
			atm_admin_login_clear_failures();
			atm_admin_audit('login_attempts_clear', '', $userId, $userName);
			atm_security_redirect('message', __('Login attempts cleared.'));
			break;

		case 'cleanup':
			if (!$isAdmin) {
				atm_security_redirect('error', __('Permission denied'));
			}
			$cleanup = atm_admin_security_cleanup(true);
			atm_security_redirect(
				'message',
				sprintf(
					__('Cleanup completed: sessions %d, audit %d, attempts %d, notifications %d.'),
					isset($cleanup['sessions']) ? (int)$cleanup['sessions'] : 0,
					isset($cleanup['audit']) ? (int)$cleanup['audit'] : 0,
					isset($cleanup['attempts']) ? (int)$cleanup['attempts'] : 0,
					isset($cleanup['notifications']) ? (int)$cleanup['notifications'] : 0
				)
			);
			break;

		case 'start_2fa':
			$_SESSION['admin_2fa_setup_secret'] = atm_admin_totp_secret();
			redirect('/admin/security.php?setup=1');
			break;

		default:
			atm_security_redirect('error', __('Unknown action.'));
			break;
	}
}


if (isset($_GET['cancel_setup'])) {
	unset($_SESSION['admin_2fa_setup_secret']);
	redirect('/admin/security.php');
}
if (isset($_GET['reset_setup'])) {
	$_SESSION['admin_2fa_setup_secret'] = atm_admin_totp_secret();
	redirect('/admin/security.php?setup=1');
}


$twofa = atm_admin_2fa_get($userId);
$twofaEnabled = !empty($twofa['enabled']) && !empty($twofa['secret']);
$setupSecret = (!$twofaEnabled && isset($_SESSION['admin_2fa_setup_secret']))
	? (string)$_SESSION['admin_2fa_setup_secret'] : '';
$showCodes = isset($_GET['codes']) && !empty($_SESSION['admin_2fa_new_backup_codes']);
$newBackupCodes = $showCodes ? $_SESSION['admin_2fa_new_backup_codes'] : array();
if ($showCodes) {
	unset($_SESSION['admin_2fa_new_backup_codes']);
}

$sessions = atm_admin_session_list($isAdmin ? null : $userId, 100);
$currentHash = atm_admin_session_hash();
$failures = $isAdmin ? atm_admin_login_failures(30) : array();
$recentAudit = $isAdmin ? atm_admin_audit_list(10) : array();

$pageTitle = __('Security center');
$pageNav = $pageTitle;
$pageNavr = '';
include_once ROOT . '/admin/template/header.php';
?>


<?php if ($showCodes): ?>
<div class="list">
	<div class="title"><?php echo h(__('Backup codes')); ?></div>
	<div class="level1">
		<div class="items">
			<div class="setting-item">
				<div class="left">
					<?php echo h(__('Save these backup codes in a safe place.')); ?>
					<span class="comment"><?php echo h(__('Each code can be used once if the authenticator app is unavailable. They will not be shown again.')); ?></span>
				</div>
				<div class="right">
					<pre class="atm-backup-codes" id="atm-new-backup-codes"><?php echo h(implode("\n", $newBackupCodes)); ?></pre>
					<button type="button" class="atm-button secondary" data-atm-copy-target="#atm-new-backup-codes"><?php echo h(__('Copy codes')); ?></button>
					<a class="atm-button" href="security.php"><?php echo h(__('I saved the codes')); ?></a>
				</div>
				<div class="clear"></div>
			</div>
		</div>
	</div>
</div>
<?php endif; ?>


<div class="list">
	<div class="title"><?php echo h(__('Two-factor authentication')); ?></div>
	<div class="level1">
		<div class="items">
			<div class="setting-item">
				<div class="left">
					<?php echo h(__('Authenticator app')); ?>
					<span class="comment"><?php echo h(__('Protect your account with a one-time code from Google Authenticator, Aegis, 1Password or another TOTP application.')); ?></span>
				</div>
				<div class="right">
					<?php if ($twofaEnabled): ?>
						<p><span class="atm-status-pill ok"><?php echo h(__('Enabled')); ?></span></p>
						<p class="comment"><?php echo h(__('A code from the authenticator app is required after the password.')); ?></p>

						<form method="POST" action="security.php" class="atm-inline-form" data-atm-no-dirty>
							<input type="hidden" name="csrf_token" value="<?php echo h(atm_admin_csrf_token()); ?>" />
							<input type="hidden" name="action" value="regenerate_codes" />
							<p><input type="password" name="password" placeholder="<?php echo h(__('Current password')); ?>" autocomplete="current-password" required /></p>
							<p><input type="text" name="code" placeholder="<?php echo h(__('Code or backup code')); ?>" autocomplete="one-time-code" required /></p>
							<button type="submit" class="atm-button secondary"><?php echo h(__('Regenerate backup codes')); ?></button>
						</form>

						<hr class="atm-separator" />

						<form method="POST" action="security.php" class="atm-inline-form" data-atm-no-dirty>
							<input type="hidden" name="csrf_token" value="<?php echo h(atm_admin_csrf_token()); ?>" />
							<input type="hidden" name="action" value="disable_2fa" />
							<p><input type="password" name="password" placeholder="<?php echo h(__('Current password')); ?>" autocomplete="current-password" required /></p>
							<p><input type="text" name="code" placeholder="<?php echo h(__('Code or backup code')); ?>" autocomplete="one-time-code" required /></p>
							<button type="submit" class="atm-button danger" data-atm-confirm="<?php echo h(__('Disable two-factor authentication? The account will be protected by the password only.')); ?>" data-atm-danger data-atm-confirm-ok="<?php echo h(__('Disable')); ?>"><?php echo h(__('Disable 2FA')); ?></button>
						</form>
					<?php elseif ($setupSecret !== ''): ?>
						<p><span class="atm-status-pill warn"><?php echo h(__('Setup in progress')); ?></span></p>
						<p><?php echo h(__('Add this secret to your authenticator app:')); ?></p>
						<p><code class="atm-code" id="atm-2fa-secret"><?php echo h($setupSecret); ?></code>
							<button type="button" class="atm-button secondary" data-atm-copy-target="#atm-2fa-secret"><?php echo h(__('Copy')); ?></button>
						</p>
						<p class="comment"><?php echo h(__('Account')); ?>: <?php echo h($userName); ?> · <?php echo h(__('Issuer')); ?>: AtomX</p>
						<p><a href="<?php echo h(atm_admin_2fa_otpauth_url($setupSecret, $userName . '@' . (isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'AtomX'))); ?>"><?php echo h(__('Open in authenticator app')); ?></a></p>
						<p class="comment"><?php echo h(__('If the link does not open, enter the secret manually. Codes are refreshed every 30 seconds.')); ?></p>

						<form method="POST" action="security.php" data-atm-no-dirty>
							<input type="hidden" name="csrf_token" value="<?php echo h(atm_admin_csrf_token()); ?>" />
							<input type="hidden" name="action" value="enable_2fa" />
							<p><input type="text" name="code" placeholder="<?php echo h(__('Enter the 6-digit code')); ?>" inputmode="numeric" autocomplete="one-time-code" autofocus required /></p>
							<button type="submit" class="atm-button"><?php echo h(__('Enable 2FA')); ?></button>
							<a class="atm-button secondary" href="?cancel_setup=1"><?php echo h(__('Cancel')); ?></a>
							<a class="atm-muted" href="?reset_setup=1"><?php echo h(__('Generate another secret')); ?></a>
						</form>
					<?php else: ?>
						<p><span class="atm-status-pill"><?php echo h(__('Disabled')); ?></span></p>
						<p class="comment"><?php echo h(__('Two-factor authentication adds a second step to the login form.')); ?></p>
						<form method="POST" action="security.php" data-atm-no-dirty>
							<input type="hidden" name="csrf_token" value="<?php echo h(atm_admin_csrf_token()); ?>" />
							<input type="hidden" name="action" value="start_2fa" />
							<button type="submit" class="atm-button"><?php echo h(__('Enable 2FA')); ?></button>
						</form>
					<?php endif; ?>
				</div>
				<div class="clear"></div>
			</div>
		</div>
	</div>
</div>


<div class="list">
	<div class="title"><?php echo h(__('Active sessions')); ?></div>
	<div class="level1">
		<div class="items">
			<div class="setting-item">
				<div class="left">
					<?php echo h(__('Signed-in administrators')); ?>
					<span class="comment"><?php echo h(__('Session identifiers are stored as hashes. A terminated session can no longer access the panel.')); ?></span>
				</div>
				<div class="right">
					<form method="POST" action="security.php" data-atm-no-dirty>
						<input type="hidden" name="csrf_token" value="<?php echo h(atm_admin_csrf_token()); ?>" />
						<input type="hidden" name="action" value="revoke_others" />
						<button type="submit" class="atm-button secondary" data-atm-confirm="<?php echo h(__('Terminate all other sessions?')); ?>"><?php echo h(__('Terminate other sessions')); ?></button>
					</form>
				</div>
				<div class="clear"></div>
			</div>
		</div>
		<div class="items">
			<table class="grid mobile-cards" cellspacing="0" width="100%">
				<tr class="mobile-table-head">
					<?php if ($isAdmin): ?><th><?php echo h(__('User')); ?></th><?php endif; ?>
					<th><?php echo h(__('IP address')); ?></th>
					<th><?php echo h(__('Browser')); ?></th>
					<th><?php echo h(__('Last activity')); ?></th>
					<th></th>
				</tr>
				<?php if (empty($sessions)): ?>
					<tr><td colspan="<?php echo $isAdmin ? 5 : 4; ?>"><?php echo h(__('No active sessions.')); ?></td></tr>
				<?php else: ?>
					<?php foreach ($sessions as $session): ?>
						<?php $isCurrent = hash_equals($currentHash, (string)$session['session_hash']); ?>
						<tr>
							<?php if ($isAdmin): ?>
								<td data-label="<?php echo h(__('User')); ?>">
									<?php echo h($session['user_name']); ?>
									<span class="comment">ID <?php echo (int)$session['user_id']; ?></span>
								</td>
							<?php endif; ?>
							<td data-label="<?php echo h(__('IP address')); ?>"><?php echo h($session['ip']); ?></td>
							<td data-label="<?php echo h(__('Browser')); ?>">
								<span title="<?php echo h($session['user_agent']); ?>"><?php echo h(atm_security_excerpt((string)$session['user_agent'], 70)); ?></span>
							</td>
							<td data-label="<?php echo h(__('Last activity')); ?>">
								<?php echo h(date('d.m.Y H:i', (int)$session['last_seen'])); ?>
								<?php if ($isCurrent): ?><span class="atm-status-pill ok"><?php echo h(__('Current')); ?></span><?php endif; ?>
							</td>
							<td data-label="">
								<form method="POST" action="security.php" class="atm-inline-form" data-atm-no-dirty>
									<input type="hidden" name="csrf_token" value="<?php echo h(atm_admin_csrf_token()); ?>" />
									<input type="hidden" name="action" value="revoke_session" />
									<input type="hidden" name="session_hash" value="<?php echo h($session['session_hash']); ?>" />
									<?php if ($isAdmin): ?><input type="hidden" name="user_id" value="<?php echo (int)$session['user_id']; ?>" /><?php endif; ?>
									<button type="submit" class="atm-button secondary" data-atm-confirm="<?php echo h($isCurrent ? __('Terminate your current session? You will be signed out.') : __('Terminate this session?')); ?>" data-atm-confirm-ok="<?php echo h(__('Terminate')); ?>"><?php echo h(__('Terminate')); ?></button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</table>
		</div>
	</div>
</div>


<?php if ($isAdmin): ?>
<div class="list">
	<div class="title"><?php echo h(__('Recent login attempts')); ?></div>
	<div class="level1">
		<div class="items">
			<div class="setting-item">
				<div class="left">
					<?php echo h(__('Failed logins')); ?>
					<span class="comment"><?php echo h(__('Five failed attempts in 15 minutes temporarily block the login form.')); ?></span>
				</div>
				<div class="right">
					<form method="POST" action="security.php" data-atm-no-dirty>
						<input type="hidden" name="csrf_token" value="<?php echo h(atm_admin_csrf_token()); ?>" />
						<input type="hidden" name="action" value="clear_attempts" />
						<button type="submit" class="atm-button secondary" data-atm-confirm="<?php echo h(__('Clear the failed login list?')); ?>"><?php echo h(__('Clear')); ?></button>
					</form>
				</div>
				<div class="clear"></div>
			</div>
		</div>
		<div class="items">
			<table class="grid mobile-cards" cellspacing="0" width="100%">
				<tr class="mobile-table-head">
					<th><?php echo h(__('Date')); ?></th>
					<th><?php echo h(__('Login')); ?></th>
					<th><?php echo h(__('IP address')); ?></th>
				</tr>
				<?php if (empty($failures)): ?>
					<tr><td colspan="3"><?php echo h(__('No failed login attempts.')); ?></td></tr>
				<?php else: ?>
					<?php foreach ($failures as $failure): ?>
						<tr>
							<td data-label="<?php echo h(__('Date')); ?>"><?php echo h(date('d.m.Y H:i:s', (int)$failure['created'])); ?></td>
							<td data-label="<?php echo h(__('Login')); ?>"><?php echo h($failure['login']); ?></td>
							<td data-label="<?php echo h(__('IP address')); ?>"><?php echo h($failure['ip']); ?></td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</table>
		</div>
	</div>
</div>


<div class="list">
	<div class="title"><?php echo h(__('Recent security events')); ?></div>
	<div class="level1">
		<div class="items">
			<table class="grid mobile-cards" cellspacing="0" width="100%">
				<tr class="mobile-table-head">
					<th><?php echo h(__('Date')); ?></th>
					<th><?php echo h(__('User')); ?></th>
					<th><?php echo h(__('Event')); ?></th>
					<th><?php echo h(__('Details')); ?></th>
					<th><?php echo h(__('IP address')); ?></th>
				</tr>
				<?php if (empty($recentAudit)): ?>
					<tr><td colspan="5"><?php echo h(__('No security events yet.')); ?></td></tr>
				<?php else: ?>
					<?php foreach ($recentAudit as $entry): ?>
						<tr>
							<td data-label="<?php echo h(__('Date')); ?>"><?php echo h(date('d.m.Y H:i', (int)$entry['created'])); ?></td>
							<td data-label="<?php echo h(__('User')); ?>"><?php echo h($entry['user_name']); ?></td>
							<td data-label="<?php echo h(__('Event')); ?>"><?php echo h(atm_admin_audit_label($entry['action'])); ?></td>
							<td data-label="<?php echo h(__('Details')); ?>"><?php echo h(atm_security_excerpt(atm_admin_audit_details($entry['details']), 120)); ?></td>
							<td data-label="<?php echo h(__('IP address')); ?>"><?php echo h($entry['ip']); ?></td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</table>
			<p><a class="atm-button secondary" href="audit_log.php"><?php echo h(__('Open audit log')); ?></a></p>
		</div>
	</div>
</div>
<div class="list">
	<div class="title"><?php echo h(__('Data retention')); ?></div>
	<div class="level1">
		<div class="items">
			<div class="setting-item">
				<div class="left">
					<?php echo h(__('Automatic security data cleanup')); ?>
					<span class="comment"><?php echo h(__('Expired sessions, audit records, login attempts and notifications are removed automatically once a day.')); ?></span>
				</div>
				<div class="right">
					<form method="POST" action="security.php" data-atm-no-dirty>
						<input type="hidden" name="csrf_token" value="<?php echo h(atm_admin_csrf_token()); ?>" />
						<input type="hidden" name="action" value="cleanup" />
						<button type="submit" class="atm-button secondary"><?php echo h(__('Run cleanup now')); ?></button>
					</form>
				</div>
				<div class="clear"></div>
			</div>
		</div>
	</div>
</div>
<?php endif; ?>


<?php include_once ROOT . '/admin/template/footer.php'; ?>
