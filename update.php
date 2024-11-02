<?php
include_once '/sys/boot.php';
include '/sys/settings/acl_rules.php';

// Массив с запросами на переименование таблиц
$renameTables = [
    'news_sections' => 'news_categories',
    'foto_sections' => 'foto_categories',
    'loads_sections' => 'loads_categories',
    'stat_sections' => 'stat_categories'
];

// Переименование таблиц
foreach ($renameTables as $oldName => $newName) {
    $Register['DB']->query("RENAME TABLE `$oldName` TO `$newName`");
}

// Добавление поддержки кириллицы
$tablesToAlter = ['news', 'stat', 'loads', 'themes', 'foto'];
foreach ($tablesToAlter as $table) {
    $Register['DB']->query("ALTER TABLE `$table` ADD `clean_url_title` VARCHAR(255) DEFAULT '' NOT NULL");
}

// Обновление пользователей
$Register['DB']->query("ALTER TABLE `users` ADD `first_name` VARCHAR(32) CHARACTER SET utf8 NOT NULL DEFAULT ''");
$Register['DB']->query("ALTER TABLE `users` ADD `last_name` VARCHAR(32) CHARACTER SET utf8 NOT NULL DEFAULT ''");

// Добавление индексов
$indexesToAdd = [
    'forums' => ['in_cat'],
    'loads' => ['category_id', 'author_id'],
    'foto' => ['category_id', 'author_id'],
    'news' => ['category_id', 'author_id'],
    'stat' => ['category_id', 'author_id'],
    'comments' => ['entity_id', 'user_id'],
    'messages' => ['to_user', 'from_user'],
    'posts' => ['id_theme'],
    'themes' => ['id_forum'],
    'users_votes' => ['from_user', 'to_user'],
    'loads_add_content' => ['field_id', 'entity_id'],
    'news_add_content' => ['field_id', 'entity_id'],
    'stat_add_content' => ['field_id', 'entity_id'],
    'users_add_content' => ['field_id', 'entity_id'],
    'users_warnings' => ['user_id', 'admin_id'],
    'loads_attaches' => ['user_id'],
    'news_attaches' => ['user_id'],
    'stat_attaches' => ['user_id'],
    'forum_attaches' => ['post_id', 'user_id']
];

// Добавление индексов в таблицы
foreach ($indexesToAdd as $table => $indexes) {
    foreach ($indexes as $index) {
        $Register['DB']->query("ALTER TABLE `$table` ADD INDEX (`$index`)");
    }
}

// Завершение операции
die(__('Operation is successful'));
