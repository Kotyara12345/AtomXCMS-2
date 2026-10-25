<?php
/**
 * Legacy upgrade script.
 *
 * Performs an ancient one-time migration: renames the old *_sections
 * tables to *_categories, adds some columns and indexes. Kept only for
 * compatibility with very old installations, so it tolerates already
 * performed steps.
 *
 * For safety the script can be executed either from the console (CLI)
 * or by an authorized administrator (user group 4) through a browser.
 */
include_once __DIR__ . '/sys/boot.php';

if (PHP_SAPI !== 'cli') {
	if (empty($_SESSION['user']['status']) || $_SESSION['user']['status'] != 4) {
		header('HTTP/1.1 403 Forbidden');
		exit('Доступ запрещён. Запустите скрипт из консоли или авторизуйтесь как администратор.');
	}
}

include_once ROOT . '/sys/settings/acl_rules.php';

$Register = Register::getInstance();
$FpsDB = $Register['DB'];

$queries = array(
	// convert section tables
	"RENAME TABLE `news_sections` TO `news_categories`",
	"RENAME TABLE `foto_sections` TO `foto_categories`",
	"RENAME TABLE `loads_sections` TO `loads_categories`",
	"RENAME TABLE `stat_sections` TO `stat_categories`",

	// Add cyrillic support
	"ALTER TABLE news ADD clean_url_title VARCHAR(255) DEFAULT '' NOT NULL",
	"ALTER TABLE stat ADD clean_url_title VARCHAR(255) DEFAULT '' NOT NULL",
	"ALTER TABLE loads ADD clean_url_title VARCHAR(255) DEFAULT '' NOT NULL",
	"ALTER TABLE themes ADD clean_url_title VARCHAR(255) DEFAULT '' NOT NULL",
	"ALTER TABLE foto ADD clean_url_title VARCHAR(255) DEFAULT '' NOT NULL",

	// Update users
	"ALTER TABLE `users` ADD `first_name` VARCHAR(32) character set utf8 NOT NULL default ''",
	"ALTER TABLE `users` ADD `last_name` VARCHAR(32) character set utf8 NOT NULL default ''",

	// Hierarchical comments
	"ALTER TABLE `comments` ADD `parent_id` INT(11) NOT NULL DEFAULT '0' AFTER `entity_id`",
	"ALTER TABLE `comments` ADD INDEX (`parent_id`)",

	// Add indexes
	"ALTER TABLE `forums` ADD INDEX (`in_cat`)",
	"ALTER TABLE `loads` ADD INDEX (`category_id`, `author_id`)",
	"ALTER TABLE `foto` ADD INDEX (`category_id`, `author_id`)",
	"ALTER TABLE `news` ADD INDEX (`category_id`, `author_id`)",
	"ALTER TABLE `stat` ADD INDEX (`category_id`, `author_id`)",
	"ALTER TABLE `comments` ADD INDEX (`entity_id`, `user_id`)",
	"ALTER TABLE `messages` ADD INDEX (`to_user`, `from_user`)",
	"ALTER TABLE `posts` ADD INDEX (`id_theme`)",
	"ALTER TABLE `themes` ADD INDEX (`id_forum`)",
	"ALTER TABLE `users_votes` ADD INDEX (`from_user`, `to_user`)",
	"ALTER TABLE `loads_add_content` ADD INDEX (`field_id`, `entity_id`)",
	"ALTER TABLE `news_add_content` ADD INDEX (`field_id`, `entity_id`)",
	"ALTER TABLE `stat_add_content` ADD INDEX (`field_id`, `entity_id`)",
	"ALTER TABLE `users_add_content` ADD INDEX (`field_id`, `entity_id`)",
	"ALTER TABLE `users_warnings` ADD INDEX (`user_id`, `admin_id`)",
	"ALTER TABLE `loads_attaches` ADD INDEX (`user_id`)",
	"ALTER TABLE `news_attaches` ADD INDEX (`user_id`)",
	"ALTER TABLE `stat_attaches` ADD INDEX (`user_id`)",
	"ALTER TABLE `forum_attaches` ADD INDEX (`post_id`, `user_id`)",
);

$errors = array();
foreach ($queries as $query) {
	try {
		$FpsDB->query($query);
	} catch (Exception $e) {
		$errors[] = $e->getMessage();
	}
}

if (!empty($errors)) {
	echo 'Обновление завершено с замечаниями (возможно, оно уже было выполнено ранее):<br /><br />';
	foreach ($errors as $error) {
		echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8') . '<br />';
	}
} else {
	die(__('Operation is successful'));
}
