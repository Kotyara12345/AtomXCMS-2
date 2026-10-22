<?php
/**
 * Mailing subscriptions for news and articles.
 *
 * Guests and registered users can subscribe to the general newsletter.
 * Registered users can additionally select categories in their profile.
 * One table is used for all targets: an empty module means the general
 * newsletter, otherwise module/category identify a category subscription.
 */

function atm_subscription_db()
{
	$Register = Register::getInstance();
	return (!empty($Register['DB'])) ? $Register['DB'] : null;
}


/**
 * Return the full subscriptions table name and create the table on demand.
 *
 * @return string|null
 */
function atm_subscription_table()
{
	static $table = null;
	static $checked = false;

	if ($checked) return $table;
	$checked = true;

	$db = atm_subscription_db();
	if (empty($db)) return null;

	$table = $db->getFullTableName('mailing_subscriptions');
	try {
		$exists = $db->query("SHOW TABLES LIKE '" . $db->escape($table) . "'");
		if (!empty($exists)) return $table;

		$db->query("CREATE TABLE IF NOT EXISTS `{$table}` (
  `id` int(11) NOT NULL auto_increment,
  `user_id` int(11) NOT NULL default '0',
  `email` varchar(190) NOT NULL default '',
  `owner_key` varchar(200) NOT NULL default '',
  `module` varchar(32) NOT NULL default '',
  `category_id` int(11) NOT NULL default '0',
  `token` char(64) NOT NULL default '',
  `active` tinyint(1) NOT NULL default '1',
  `created_at` datetime NOT NULL default CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL default CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `owner_target` (`owner_key`, `module`, `category_id`),
  UNIQUE KEY `token` (`token`),
  KEY `email` (`email`),
  KEY `target` (`module`, `category_id`, `active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci");
	} catch (Exception $e) {
		$table = null;
	}

	return $table;
}


function atm_subscription_token()
{
	try {
		return bin2hex(random_bytes(32));
	} catch (Exception $e) {
		// Fall through to a less strong but still unique token.
	}
	if (function_exists('openssl_random_pseudo_bytes')) {
		$bytes = openssl_random_pseudo_bytes(32);
		if ($bytes !== false) return bin2hex($bytes);
	}
	return md5(uniqid(mt_rand(), true));
}


function atm_subscription_now()
{
	return date('Y-m-d H:i:s');
}


/**
 * Validate and normalize an e-mail address.
 *
 * @param string $email
 * @return string normalized address or an empty string
 */
function atm_subscription_normalize_email($email)
{
	$email = trim((string)$email);
	if ($email === '') return '';
	if (function_exists('mb_strtolower')) {
		$email = mb_strtolower($email, 'UTF-8');
	} else {
		$email = strtolower($email);
	}
	if (strlen($email) > 190) return '';
	if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return '';
	return $email;
}


function atm_subscription_owner_key($userId, $email)
{
	$userId = (int)$userId;
	return ($userId > 0) ? 'u:' . $userId : 'e:' . $email;
}


function atm_subscription_get_row($ownerKey, $module, $categoryId)
{
	$db = atm_subscription_db();
	$table = atm_subscription_table();
	if (empty($db) || empty($table)) return null;

	$ownerKey = $db->escape($ownerKey);
	$module = $db->escape($module);
	$categoryId = (int)$categoryId;
	$rows = $db->query("SELECT * FROM `{$table}`
		WHERE `owner_key` = '{$ownerKey}'
		AND `module` = '{$module}'
		AND `category_id` = {$categoryId}
		LIMIT 1");

	return !empty($rows) ? $rows[0] : null;
}


/**
 * Insert or reactivate one subscription target.
 *
 * @return string|false token
 */
function atm_subscription_store($userId, $email, $module, $categoryId, $active = 1)
{
	$db = atm_subscription_db();
	$table = atm_subscription_table();
	$email = atm_subscription_normalize_email($email);
	if (empty($db) || empty($table) || $email === '') return false;

	$userId = (int)$userId;
	$categoryId = (int)$categoryId;
	$module = (string)$module;
	$active = $active ? 1 : 0;
	$ownerKey = atm_subscription_owner_key($userId, $email);
	$now = atm_subscription_now();
	$token = atm_subscription_token();

	$row = atm_subscription_get_row($ownerKey, $module, $categoryId);
	if (!empty($row)) {
		if (empty($row['token'])) $row['token'] = $token;
		$id = (int)$row['id'];
		$emailEsc = $db->escape($email);
		$tokenEsc = $db->escape($row['token']);
		$db->query("UPDATE `{$table}` SET
			`user_id` = {$userId},
			`email` = '{$emailEsc}',
			`token` = '{$tokenEsc}',
			`active` = {$active},
			`updated_at` = '{$now}'
			WHERE `id` = {$id}");
		return $row['token'];
	}

	// If a guest subscribed with this address, attach the existing record
	// to the registered account instead of creating a duplicate.
	if ($userId > 0) {
		$emailEsc = $db->escape($email);
		$moduleEsc = $db->escape($module);
		$guestRows = $db->query("SELECT * FROM `{$table}`
			WHERE `email` = '{$emailEsc}'
			AND `user_id` = 0
			AND `module` = '{$moduleEsc}'
			AND `category_id` = {$categoryId}
			LIMIT 1");
		if (!empty($guestRows)) {
			$guest = $guestRows[0];
			if (empty($guest['token'])) $guest['token'] = $token;
			$id = (int)$guest['id'];
			$ownerEsc = $db->escape($ownerKey);
			$tokenEsc = $db->escape($guest['token']);
			$db->query("UPDATE `{$table}` SET
				`user_id` = {$userId},
				`owner_key` = '{$ownerEsc}',
				`token` = '{$tokenEsc}',
				`active` = {$active},
				`updated_at` = '{$now}'
				WHERE `id` = {$id}");
			return $guest['token'];
		}
	}

	$emailEsc = $db->escape($email);
	$ownerEsc = $db->escape($ownerKey);
	$moduleEsc = $db->escape($module);
	$db->query("INSERT INTO `{$table}` (
		`user_id`, `email`, `owner_key`, `module`, `category_id`,
		`token`, `active`, `created_at`, `updated_at`
	) VALUES (
		{$userId}, '{$emailEsc}', '{$ownerEsc}', '{$moduleEsc}', {$categoryId},
		'{$token}', {$active}, '{$now}', '{$now}'
	)");

	return $token;
}


/**
 * Deactivate one exact target if it exists.
 */
function atm_subscription_deactivate($userId, $email, $module, $categoryId)
{
	$db = atm_subscription_db();
	$table = atm_subscription_table();
	if (empty($db) || empty($table)) return false;

	$ownerKey = atm_subscription_owner_key($userId, $email);
	$row = atm_subscription_get_row($ownerKey, $module, $categoryId);
	if (empty($row)) return false;

	$id = (int)$row['id'];
	$now = atm_subscription_now();
	$db->query("UPDATE `{$table}` SET `active` = 0, `updated_at` = '{$now}' WHERE `id` = {$id}");
	return true;
}


/**
 * Subscribe an address to the general newsletter.
 *
 * @return array with keys ok, token, is_new
 */
function atm_subscription_subscribe_general($email, $userId = 0)
{
	$email = atm_subscription_normalize_email($email);
	if ($email === '') {
		return array('ok' => false, 'token' => '', 'is_new' => false);
	}

	$userId = (int)$userId;
	try {
		$ownerKey = atm_subscription_owner_key($userId, $email);
		$existing = atm_subscription_get_row($ownerKey, '', 0);
		$isNew = empty($existing) || empty($existing['active']);
		$token = atm_subscription_store($userId, $email, '', 0, 1);
	} catch (Exception $e) {
		return array('ok' => false, 'token' => '', 'is_new' => false);
	}

	if ($token === false) {
		return array('ok' => false, 'token' => '', 'is_new' => false);
	}
	return array('ok' => true, 'token' => $token, 'is_new' => $isNew);
}


function atm_subscription_unsubscribe_user_general($userId, $email)
{
	$userId = (int)$userId;
	if ($userId < 1) return false;

	$db = atm_subscription_db();
	$table = atm_subscription_table();
	if (empty($db) || empty($table)) return false;

	$ownerKey = 'u:' . $userId;
	$ownerEsc = $db->escape($ownerKey);
	$now = atm_subscription_now();
	$db->query("UPDATE `{$table}` SET `active` = 0, `updated_at` = '{$now}'
		WHERE `owner_key` = '{$ownerEsc}' AND `module` = '' AND `category_id` = 0");
	return true;
}


/**
 * Unsubscribe every address record associated with a token.
 *
 * @return bool
 */
function atm_subscription_unsubscribe_token($token)
{
	$token = trim((string)$token);
	if (!preg_match('/^[a-f0-9]{64}$/i', $token)) return false;

	$db = atm_subscription_db();
	$table = atm_subscription_table();
	if (empty($db) || empty($table)) return false;

	try {
		$tokenEsc = $db->escape($token);
		$rows = $db->query("SELECT * FROM `{$table}` WHERE `token` = '{$tokenEsc}' LIMIT 1");
		if (empty($rows)) return false;

		$row = $rows[0];
		$now = atm_subscription_now();
		$userId = (int)$row['user_id'];
		$emailEsc = $db->escape($row['email']);

		if ($userId > 0) {
			$ownerEsc = $db->escape('u:' . $userId);
			$db->query("UPDATE `{$table}` SET `active` = 0, `updated_at` = '{$now}'
				WHERE `owner_key` = '{$ownerEsc}'
				OR (`user_id` = 0 AND `email` = '{$emailEsc}')");
		} else {
			$db->query("UPDATE `{$table}` SET `active` = 0, `updated_at` = '{$now}'
				WHERE `email` = '{$emailEsc}'");
		}
	} catch (Exception $e) {
		return false;
	}

	return true;
}


function atm_subscription_user_general_active($userId)
{
	$userId = (int)$userId;
	if ($userId < 1) return false;
	try {
		$row = atm_subscription_get_row('u:' . $userId, '', 0);
	} catch (Exception $e) {
		return false;
	}
	return !empty($row) && !empty($row['active']);
}


function atm_subscription_user_category_ids($userId, $module)
{
	$db = atm_subscription_db();
	$table = atm_subscription_table();
	$userId = (int)$userId;
	if (empty($db) || empty($table) || $userId < 1) return array();

	$ownerEsc = $db->escape('u:' . $userId);
	$moduleEsc = $db->escape((string)$module);
	try {
		$rows = $db->query("SELECT `category_id` FROM `{$table}`
			WHERE `owner_key` = '{$ownerEsc}'
			AND `module` = '{$moduleEsc}'
			AND `active` = 1");
	} catch (Exception $e) {
		return array();
	}
	$ids = array();
	foreach ($rows as $row) {
		$id = (int)$row['category_id'];
		if ($id > 0) $ids[$id] = $id;
	}
	return array_values($ids);
}


/**
 * All categories of a material: the main one and the additional bindings.
 */
function atm_subscription_get_entity_category_ids($module, $entity)
{
	$ids = array();
	if (is_object($entity) && is_callable(array($entity, 'getCategory_id'))) {
		$main = (int)$entity->getCategory_id();
		if ($main > 0) $ids[$main] = $main;
	}
	if (!is_object($entity) || !is_callable(array($entity, 'getId'))) {
		return array_values($ids);
	}

	if (function_exists('atm_ensure_categories_bind_table')) {
		atm_ensure_categories_bind_table();
	}

	$db = atm_subscription_db();
	if (empty($db)) return array_values($ids);

	$table = $db->getFullTableName('categories_bind');
	$moduleEsc = $db->escape((string)$module);
	$entityId = (int)$entity->getId();
	try {
		$rows = $db->query("SELECT `category_id` FROM `{$table}`
			WHERE `module` = '{$moduleEsc}' AND `entity_id` = {$entityId}");
		foreach ($rows as $row) {
			$id = (int)$row['category_id'];
			if ($id > 0) $ids[$id] = $id;
		}
	} catch (Exception $e) {
		// The bindings table is optional on very old installations.
	}

	return array_values($ids);
}


/**
 * Category IDs the current user may see in a module.
 */
function atm_subscription_allowed_category_ids($module)
{
	$Register = Register::getInstance();
	if (empty($Register['ModManager'])) return array();

	$ids = array();
	try {
		$model = $Register['ModManager']->getModelInstance($module . 'Categories');
		$categories = $model->getCollection();
		foreach ($categories as $category) {
			$access = is_callable(array($category, 'getNo_access')) ? (string)$category->getNo_access() : '';
			if (class_exists('ACL') && !ACL::checkCategoryAccess($access)) continue;
			$id = (int)$category->getId();
			if ($id > 0) $ids[$id] = $id;
		}
	} catch (Exception $e) {
		return array();
	}
	return array_values($ids);
}


/**
 * Save the subscription block from the profile form.
 *
 * @param int $userId
 * @param string $email
 * @param bool $general
 * @param array $categories module => category IDs
 * @return bool
 */
function atm_subscription_save_user_preferences($userId, $email, $general, $categories)
{
	$db = atm_subscription_db();
	$table = atm_subscription_table();
	$userId = (int)$userId;
	$email = atm_subscription_normalize_email($email);
	if (empty($db) || empty($table) || $userId < 1 || $email === '') return false;

	try {
		$ownerKey = 'u:' . $userId;
		$ownerEsc = $db->escape($ownerKey);
		$existingRows = $db->query("SELECT * FROM `{$table}` WHERE `owner_key` = '{$ownerEsc}'");
		$existing = array();
		foreach ($existingRows as $row) {
			$key = $row['module'] . '|' . (int)$row['category_id'];
			$existing[$key] = $row;
		}

		$desired = array();
		if ($general) $desired['|0'] = array('', 0);
		foreach (array('news', 'stat') as $module) {
			$allowed = atm_subscription_allowed_category_ids($module);
			$posted = isset($categories[$module]) ? (array)$categories[$module] : array();
			foreach ($posted as $categoryId) {
				$categoryId = (int)$categoryId;
				if ($categoryId > 0 && in_array($categoryId, $allowed, true)) {
					$desired[$module . '|' . $categoryId] = array($module, $categoryId);
				}
			}
		}

		foreach ($desired as $target) {
			atm_subscription_store($userId, $email, $target[0], $target[1], 1);
		}

		foreach ($existing as $key => $row) {
			if (!isset($desired[$key]) && !empty($row['active'])) {
				$id = (int)$row['id'];
				$now = atm_subscription_now();
				$db->query("UPDATE `{$table}` SET `active` = 0, `updated_at` = '{$now}' WHERE `id` = {$id}");
			}
		}

		// Keep the stored address current; the owner key does not depend on it.
		$emailEsc = $db->escape($email);
		$now = atm_subscription_now();
		$db->query("UPDATE `{$table}` SET `email` = '{$emailEsc}', `updated_at` = '{$now}'
			WHERE `owner_key` = '{$ownerEsc}'");
	} catch (Exception $e) {
		return false;
	}

	return true;
}


function atm_subscription_render_category_tree($categories, $parentId, $selected, $module, $depth = 0)
{
	$html = '';
	foreach ($categories as $category) {
		$categoryParent = is_callable(array($category, 'getParent_id')) ? (int)$category->getParent_id() : 0;
		if ($categoryParent !== (int)$parentId) continue;
		$id = (int)$category->getId();
		if ($id < 1) continue;

		$title = is_callable(array($category, 'getTitle')) ? $category->getTitle() : ('#' . $id);
		$checked = in_array($id, $selected, true) ? ' checked="checked"' : '';
		$html .= '<li style="margin-left:' . ($depth * 18) . 'px;">'
			. '<label><input type="checkbox" name="mailing[categories][' . h($module) . '][]"'
			. ' value="' . $id . '"' . $checked . ' /> ' . h($title) . '</label>';
		$children = atm_subscription_render_category_tree($categories, $id, $selected, $module, $depth + 1);
		if ($children !== '') $html .= '<ul>' . $children . '</ul>';
		$html .= '</li>';
	}
	return $html;
}


/**
 * Build the mailing block for the user profile form.
 */
function atm_subscription_render_user_settings($userId)
{
	$userId = (int)$userId;
	$general = atm_subscription_user_general_active($userId);
	$html = '<div class="mailing-settings">';
	$html .= '<label><input type="checkbox" name="mailing[general]" value="1"'
		. ($general ? ' checked="checked"' : '') . ' /> '
		. h(__('General newsletter about new news and articles')) . '</label>';

	$Register = Register::getInstance();
	foreach (array('news', 'stat') as $module) {
		$moduleTitle = ($module === 'news') ? __('News') : __('Article');
		$html .= '<div class="mailing-category-group">';
		$html .= '<b>' . h(sprintf(__('%s categories'), $moduleTitle)) . '</b>';
		$categories = array();
		try {
			$model = $Register['ModManager']->getModelInstance($module . 'Categories');
			$categories = $model->getCollection();
		} catch (Exception $e) {
			$categories = array();
		}

		$visible = array();
		foreach ($categories as $category) {
			$access = is_callable(array($category, 'getNo_access')) ? (string)$category->getNo_access() : '';
			if (class_exists('ACL') && !ACL::checkCategoryAccess($access)) continue;
			$visible[] = $category;
		}

		$selected = atm_subscription_user_category_ids($userId, $module);
		$list = atm_subscription_render_category_tree($visible, 0, $selected, $module);
		if ($list === '') {
			$html .= '<div class="mailing-empty">' . h(__('No categories')) . '</div>';
		} else {
			$html .= '<ul class="mailing-categories">' . $list . '</ul>';
		}
		$html .= '</div>';
	}
	$html .= '<div class="mailing-note">'
		. h(__('You can unsubscribe from the general newsletter at any time using the link in the e-mail.'))
		. '</div>';
	$html .= '</div>';
	return $html;
}


/**
 * Resolve unique recipients for a material.
 *
 * General rows (module = '') receive every new material. Category rows
 * receive only materials from the matching module and category.
 */
function atm_subscription_get_recipients($module, $categoryIds)
{
	$db = atm_subscription_db();
	$table = atm_subscription_table();
	if (empty($db) || empty($table)) return array();

	$categoryIds = array_map('intval', (array)$categoryIds);
	$rows = $db->query("SELECT * FROM `{$table}` WHERE `active` = 1");
	if (empty($rows)) return array();

	$userIds = array();
	foreach ($rows as $row) {
		$userId = (int)$row['user_id'];
		if ($userId > 0) $userIds[$userId] = $userId;
	}

	$users = array();
	if (!empty($userIds)) {
		$usersTable = $db->getFullTableName('users');
		$ids = implode(',', array_map('intval', array_values($userIds)));
		try {
			$userRows = $db->query("SELECT `id`, `name`, `email` FROM `{$usersTable}` WHERE `id` IN ({$ids})");
			foreach ($userRows as $userRow) {
				$users[(int)$userRow['id']] = $userRow;
			}
		} catch (Exception $e) {
			$users = array();
		}
	}

	$recipients = array();
	foreach ($rows as $row) {
		$rowModule = (string)$row['module'];
		$categoryId = (int)$row['category_id'];
		$isGeneral = ($rowModule === '');
		$isCategory = ($rowModule === (string)$module && in_array($categoryId, $categoryIds, true));
		if (!$isGeneral && !$isCategory) continue;

		$userId = (int)$row['user_id'];
		$email = !empty($row['email']) ? $row['email'] : '';
		$name = '';
		if ($userId > 0) {
			if (empty($users[$userId])) continue;
			$email = $users[$userId]['email'];
			$name = $users[$userId]['name'];
		}
		$email = atm_subscription_normalize_email($email);
		if ($email === '' || isset($recipients[$email])) continue;

		$recipients[$email] = array(
			'name'  => $name,
			'token' => $row['token'],
		);
	}

	return $recipients;
}


function atm_subscription_unsubscribe_url($token)
{
	return 'http://' . get_server_host()
		. get_url('/subscribe.php?unsubscribe=' . urlencode((string)$token), false, false);
}


function atm_subscription_send_welcome($email, $name, $token)
{
	$email = atm_subscription_normalize_email($email);
	if ($email === '') return false;

	$context = array(
		'greeting'         => !empty($name) ? sprintf(__('Hello, %s!'), $name) : __('Hello!'),
		'intro'            => __('You have subscribed to the general newsletter about new news and articles.'),
		'unsubscribe_text' => __('Unsubscribe from mailing'),
		'unsubscribe_url'  => atm_subscription_unsubscribe_url($token),
	);

	try {
		$mailer = new AtmMail(ROOT . '/sys/settings/email_templates/');
		$mailer->prepare('new_subscription');
		$mailer->sendMail($email, __('Subscription confirmed'), $context);
	} catch (Exception $e) {
		return false;
	}
	return true;
}


/**
 * Send notifications about a newly published material.
 *
 * @return int number of unique recipients
 */
function atm_subscription_notify_material($module, $entity, $categoryIds = null)
{
	if (!in_array((string)$module, array('news', 'stat'), true) || !is_object($entity)) return 0;
	if (is_callable(array($entity, 'getAvailable')) && !$entity->getAvailable()) return 0;
	if (is_callable(array($entity, 'getPremoder')) && $entity->getPremoder() !== 'confirmed') return 0;
	if (is_callable(array($entity, 'getId')) && !$entity->getId()) return 0;

	try {
		if ($categoryIds === null) {
			$categoryIds = atm_subscription_get_entity_category_ids($module, $entity);
		}
		$recipients = atm_subscription_get_recipients($module, $categoryIds);
	} catch (Exception $e) {
		return 0;
	}
	if (empty($recipients)) return 0;

	if (defined('ATM_MAILING_DRY_RUN') && ATM_MAILING_DRY_RUN) {
		return count($recipients);
	}

	$title = is_callable(array($entity, 'getTitle')) ? (string)$entity->getTitle() : '';
	$id = (int)$entity->getId();
	$moduleTitle = ($module === 'news') ? __('News') : __('Article');
	$intro = sprintf(__('A new material has been published in the %s section.'), $moduleTitle);
	$subject = sprintf(__('New publication: %s'), $title);
	$materialUrl = 'http://' . get_server_host() . get_url('/' . $module . '/view/' . $id, false, false);

	$announce = '';
	if (is_callable(array($entity, 'getDescription'))) $announce = (string)$entity->getDescription();
	if (trim($announce) === '' && is_callable(array($entity, 'getMain'))) $announce = (string)$entity->getMain();
	$announce = trim(strip_tags($announce));
	if (function_exists('mb_substr')) {
		if (mb_strlen($announce) > 300) $announce = mb_substr($announce, 0, 300) . '...';
	} elseif (strlen($announce) > 300) {
		$announce = substr($announce, 0, 300) . '...';
	}

	$sent = 0;
	foreach ($recipients as $email => $recipient) {
		$context = array(
			'greeting'         => !empty($recipient['name']) ? sprintf(__('Hello, %s!'), $recipient['name']) : __('Hello!'),
			'intro'            => $intro,
			'material_title'   => h($title),
			'material_url'     => $materialUrl,
			'material_announce'=> h($announce),
			'unsubscribe_text' => __('Unsubscribe from mailing'),
			'unsubscribe_url'  => atm_subscription_unsubscribe_url($recipient['token']),
		);
		try {
			$mailer = new AtmMail(ROOT . '/sys/settings/email_templates/');
			$mailer->prepare('new_material');
			if ($mailer->sendMail($email, $subject, $context)) $sent++;
		} catch (Exception $e) {
			// Do not interrupt content publishing because of one bad address.
		}
	}
	return $sent;
}


function atm_subscription_notice()
{
	if (empty($_GET['mailing'])) return '';
	switch ((string)$_GET['mailing']) {
		case 'subscribed':
			$text = __('You have been subscribed to the newsletter.');
			break;
		case 'unsubscribed':
			$text = __('You have been unsubscribed from the newsletter.');
			break;
		case 'invalid':
			$text = __('The unsubscribe link is invalid.');
			break;
		case 'email':
			$text = __('Please enter a valid e-mail address.');
			break;
		default:
			$text = __('Could not save the subscription. Please try again later.');
			break;
	}
	return '<div class="mailing-notice">' . h($text) . '</div>';
}


function atm_subscription_csrf_token()
{
	if (empty($_SESSION['mailing_csrf'])) {
		$_SESSION['mailing_csrf'] = atm_subscription_token();
	}
	return $_SESSION['mailing_csrf'];
}


function atm_subscription_csrf_check($token)
{
	return !empty($_SESSION['mailing_csrf'])
		&& is_string($token)
		&& hash_equals($_SESSION['mailing_csrf'], $token);
}


function atm_subscription_safe_referer()
{
	if (empty($_SERVER['HTTP_REFERER'])) return '';
	$referer = (string)$_SERVER['HTTP_REFERER'];
	$parts = parse_url($referer);
	if (empty($parts['host'])) return '';

	$host = $parts['host'];
	if (!empty($parts['port'])) $host .= ':' . $parts['port'];
	if (strcasecmp($host, get_server_host()) !== 0) return '';

	return $referer;
}


/**
 * Redirect back with a mailing status in the query string.
 */
function atm_subscription_redirect($status, $fallback = '/')
{
	$to = atm_subscription_safe_referer();
	if ($to === '') {
		$to = 'http://' . get_server_host() . get_url($fallback, false, false);
	}
	$to = preg_replace('/([?&])mailing=[^&]*/', '$1', $to);
	$to = rtrim($to, '?&');
	$separator = (strpos($to, '?') === false) ? '?' : '&';

	header('Location: ' . $to . $separator . 'mailing=' . urlencode($status));
	exit;
}
