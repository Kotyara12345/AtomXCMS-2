<?php
declare(strict_types=1);

/**
 * Database Installation Script
 * 
 * Создает таблицы и начальные данные для CMS AtomX
 * Оптимизирован для PHP 8.1+ с использованием PDO
 */

// Заголовки безопасности
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

// Конфигурация ошибок
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// Базовые проверки
session_start();
if (!isset($_SESSION['adm_name'], $_SESSION['adm_pass'], $_SESSION['adm_email'])) {
    http_response_code(403);
    exit('Доступ запрещен: отсутствуют данные установки');
}

define('ROOT', dirname(dirname(__FILE__)));
if (function_exists('set_time_limit')) {
    set_time_limit(300); // 5 минут на установку
}

include_once '../sys/boot.php';

// Функция для безопасного вывода
function escapeHtml(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

// Функция для логирования ошибок
function logError(string $message, string $query = ''): void
{
    error_log('Installation Error: ' . $message . ' Query: ' . $query);
}

try {
    // Инициализация конфигурации
    new Config('../sys/settings/config.php');
    $config = Config::read('all');
    
    if (!isset($config['db']['prefix'])) {
        throw new Exception('Префикс базы данных не настроен');
    }
    
    $prefix = $config['db']['prefix'];
    
    // Проверка подключения к БД
    if (!class_exists('PDO')) {
        throw new Exception('Требуется расширение PDO для работы с базой данных');
    }
    
    include_once '../sys/inc/fpspdo.class.php';
    $db = FpsPDO::get();
    
    if (!$db instanceof PDO) {
        throw new Exception('Не удалось подключиться к базе данных');
    }
    
    // Начинаем транзакцию для атомарности
    $db->beginTransaction();
    
    // Массив SQL запросов
    $queries = [];
    
    // 1. Forum Categories
    $queries[] = "DROP TABLE IF EXISTS `{$prefix}forum_cat`";
    $queries[] = "CREATE TABLE `{$prefix}forum_cat` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `title` VARCHAR(255) DEFAULT NULL,
        `previev_id` INT(11) NOT NULL,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    $queries[] = "INSERT INTO `{$prefix}forum_cat` VALUES (1, 'TEST', 1)";
    
    // 2. Forums
    $queries[] = "DROP TABLE IF EXISTS `{$prefix}forums`";
    $queries[] = "CREATE TABLE `{$prefix}forums` (
        `id` INT(6) NOT NULL AUTO_INCREMENT,
        `title` TEXT CHARACTER SET utf8mb4,
        `description` MEDIUMTEXT CHARACTER SET utf8mb4,
        `pos` SMALLINT(6) NOT NULL DEFAULT '0',
        `in_cat` INT(11) DEFAULT NULL,
        `last_theme_id` INT(11) NOT NULL DEFAULT 0,
        `themes` INT(11) DEFAULT '0',
        `posts` INT(11) NOT NULL DEFAULT '0',
        `parent_forum_id` INT(11),
        `lock_posts` INT(11) DEFAULT '0' NOT NULL,
        `lock_passwd` VARCHAR(100) NOT NULL DEFAULT '',
        PRIMARY KEY (`id`),
        INDEX (`in_cat`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    $queries[] = "INSERT INTO `{$prefix}forums` (`title`, `description`, `pos`, `in_cat`) 
                 VALUES ('TEST', 'тестовый форум', 1, 1)";
    
    // 3. Forum Attaches
    $queries[] = "DROP TABLE IF EXISTS `{$prefix}forum_attaches`";
    $queries[] = "CREATE TABLE `{$prefix}forum_attaches` (
        `id` INT NOT NULL AUTO_INCREMENT,
        `post_id` INT NOT NULL DEFAULT '0',
        `theme_id` INT NOT NULL DEFAULT '0',
        `user_id` INT NOT NULL,
        `attach_number` INT NOT NULL DEFAULT '0',
        `filename` VARCHAR(100) NOT NULL,
        `size` BIGINT NOT NULL,
        `date` DATETIME NOT NULL,
        `is_image` ENUM('0','1') DEFAULT '0' NOT NULL,
        PRIMARY KEY (`id`),
        INDEX (`post_id`, `user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    // 4. Pages
    $queries[] = "DROP TABLE IF EXISTS `{$prefix}pages`";
    $queries[] = "CREATE TABLE `{$prefix}pages` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `name` VARCHAR(255) NOT NULL,
        `title` TEXT NOT NULL,
        `template` VARCHAR(255) DEFAULT '' NOT NULL,
        `content` LONGTEXT NOT NULL,
        `url` VARCHAR(255) DEFAULT '' NOT NULL,
        `meta_title` VARCHAR(255) NOT NULL,
        `meta_keywords` VARCHAR(255) DEFAULT '' NOT NULL,
        `meta_description` TEXT,
        `parent_id` INT(11) DEFAULT 0 NOT NULL,
        `path` VARCHAR(255) DEFAULT '1.' NOT NULL,
        `visible` ENUM('1','0') DEFAULT '1' NOT NULL,
        `publish` ENUM('0','1') NOT NULL DEFAULT '1',
        `position` INT(11) NOT NULL,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    $queries[] = "INSERT INTO `{$prefix}pages` (`id`, `name`, `path`, `content`) 
                 VALUES (1, 'root', '.', '')";
    
    // 5. Loads
    $queries[] = "DROP TABLE IF EXISTS `{$prefix}loads`";
    $queries[] = "CREATE TABLE `{$prefix}loads` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `title` VARCHAR(255) NOT NULL,
        `clean_url_title` VARCHAR(255) DEFAULT '' NOT NULL,
        `main` TEXT NOT NULL,
        `author_id` INT(11) NOT NULL,
        `category_id` INT(11) NOT NULL,
        `views` INT(11) DEFAULT '0',
        `downloads` INT(11) DEFAULT '0',
        `rate` INT(11) DEFAULT '0',
        `download` VARCHAR(255) NOT NULL,
        `download_url` VARCHAR(255) NOT NULL,
        `download_url_size` BIGINT(20) NOT NULL,
        `date` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `comments` INT(11) NOT NULL DEFAULT '0',
        `tags` VARCHAR(255) NOT NULL,
        `description` TEXT NOT NULL,
        `sourse` VARCHAR(255) NOT NULL,
        `sourse_email` VARCHAR(255) NOT NULL,
        `sourse_site` VARCHAR(255) NOT NULL,
        `commented` ENUM('0','1') DEFAULT '1' NOT NULL,
        `available` ENUM('0','1') DEFAULT '1' NOT NULL,
        `view_on_home` ENUM('0','1') DEFAULT '1' NOT NULL,
        `on_home_top` ENUM('0','1') DEFAULT '0' NOT NULL,
        `premoder` ENUM('nochecked','rejected','confirmed') NOT NULL DEFAULT 'confirmed',
        `rating` INT(11) NOT NULL DEFAULT '0',
        PRIMARY KEY (`id`),
        INDEX (`category_id`, `author_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    // 6. Comments
    $queries[] = "DROP TABLE IF EXISTS `{$prefix}comments`";
    $queries[] = "CREATE TABLE `{$prefix}comments` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `entity_id` INT(11) NOT NULL,
        `user_id` INT(11) DEFAULT '0' NOT NULL,
        `name` VARCHAR(100) NOT NULL,
        `message` TEXT NOT NULL,
        `ip` VARCHAR(50) NOT NULL,
        `mail` VARCHAR(150) NOT NULL,
        `date` DATETIME NOT NULL,
        `module` VARCHAR(10) DEFAULT 'news' NOT NULL,
        `premoder` ENUM('nochecked','rejected','confirmed') NOT NULL DEFAULT 'nochecked',
        PRIMARY KEY (`id`),
        INDEX (`entity_id`, `user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    // 7. Loads Categories
    $queries[] = "DROP TABLE IF EXISTS `{$prefix}loads_categories`";
    $queries[] = "CREATE TABLE `{$prefix}loads_categories` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `parent_id` INT(11) DEFAULT '0',
        `path` VARCHAR(255) NOT NULL DEFAULT '',
        `announce` VARCHAR(255) NOT NULL DEFAULT '',
        `title` VARCHAR(255) NOT NULL,
        `view_on_home` ENUM('0','1') DEFAULT '1' NOT NULL,
        `no_access` VARCHAR(255) NOT NULL DEFAULT '',
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    $queries[] = "INSERT INTO `{$prefix}loads_categories` VALUES (1, 0, '', '', 'TEST CAT', '1', '')";
    
    // 8. Messages
    $queries[] = "DROP TABLE IF EXISTS `{$prefix}messages`";
    $queries[] = "CREATE TABLE `{$prefix}messages` (
        `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
        `to_user` INT(10) UNSIGNED NOT NULL DEFAULT '0',
        `from_user` INT(10) UNSIGNED NOT NULL DEFAULT '0',
        `sendtime` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `subject` VARCHAR(255) DEFAULT NULL,
        `message` TEXT,
        `id_rmv` INT(10) UNSIGNED NOT NULL DEFAULT '0',
        `viewed` TINYINT(1) NOT NULL DEFAULT '0',
        PRIMARY KEY (`id`),
        INDEX (`to_user`, `from_user`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    // 9. News
    $queries[] = "DROP TABLE IF EXISTS `{$prefix}news`";
    $queries[] = "CREATE TABLE `{$prefix}news` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `title` VARCHAR(255) NOT NULL,
        `clean_url_title` VARCHAR(255) DEFAULT '' NOT NULL,
        `main` TEXT NOT NULL,
        `views` INT(11) DEFAULT '0',
        `date` DATETIME DEFAULT NULL,
        `category_id` INT(11) NOT NULL,
        `author_id` INT(11) NOT NULL,
        `comments` INT(11) NOT NULL DEFAULT '0',
        `tags` VARCHAR(255) NOT NULL,
        `description` TEXT NOT NULL,
        `sourse` VARCHAR(255) NOT NULL,
        `sourse_email` VARCHAR(255) NOT NULL,
        `sourse_site` VARCHAR(255) NOT NULL,
        `commented` ENUM('0','1') DEFAULT '1' NOT NULL,
        `available` ENUM('0','1') DEFAULT '1' NOT NULL,
        `view_on_home` ENUM('0','1') DEFAULT '1' NOT NULL,
        `on_home_top` ENUM('0','1') DEFAULT '0' NOT NULL,
        `premoder` ENUM('nochecked','rejected','confirmed') NOT NULL DEFAULT 'confirmed',
        `rating` INT(11) NOT NULL DEFAULT '0',
        PRIMARY KEY (`id`),
        INDEX (`category_id`, `author_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    $newsContent = 'Теперь сайт установлен и готов к работе.
    По любым вопросам Вы можете обращаться на сайт [url=http://atomx.net]AtomX[/url].

    Первым делом, Вам может понадобиться что-то из этого:
    [list]
    [*]Большинство настроек можно задать в разделе [b]Общие настройки[/b] в админке.
    [*]Либо в индивидуальных настроек каждого модуля.
    [*]Не забудьте настроить права доступа. Для этого перейдите на страницу [b]Админка - Пользователи - Права групп[/b].
    [*]Вы можете сделать любую страницу сайта главной. Это можно сделать в общих настройках.
    [*]Если Вы хотите сообщить об ошибке, это можно сделать [url=http://atomx.net/forum/view_forum/4/]тут[/url].
    [/list]';
    
    $queries[] = "INSERT INTO `{$prefix}news` 
        (`title`, `clean_url_title`, `main`, `date`, `category_id`, `author_id`, `tags`, `description`, `sourse`, `sourse_email`, `sourse_site`) VALUES
        ('Моя первая новость',
         'моя_первая_новость',
         '" . $db->quote($newsContent) . "',
         NOW(), 1, 1, '', '', '', '', '')";
    
    // 10. News Categories
    $queries[] = "DROP TABLE IF EXISTS `{$prefix}news_categories`";
    $queries[] = "CREATE TABLE `{$prefix}news_categories` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `parent_id` INT(11) DEFAULT '0',
        `path` VARCHAR(255) NOT NULL DEFAULT '',
        `announce` VARCHAR(255) NOT NULL DEFAULT '',
        `title` VARCHAR(255) NOT NULL,
        `view_on_home` ENUM('0','1') DEFAULT '1' NOT NULL,
        `no_access` VARCHAR(255) NOT NULL DEFAULT '',
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    $queries[] = "INSERT INTO `{$prefix}news_categories` VALUES (1, 0, '', '', 'Test category', '1', '')";
    
    // 11. Posts
    $queries[] = "DROP TABLE IF EXISTS `{$prefix}posts`";
    $queries[] = "CREATE TABLE `{$prefix}posts` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `message` TEXT,
        `attaches` ENUM('0','1') DEFAULT '0',
        `id_author` INT(6) UNSIGNED NOT NULL DEFAULT '0',
        `time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `edittime` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `id_editor` INT(6) UNSIGNED NOT NULL DEFAULT '0',
        `id_theme` INT(11) NOT NULL DEFAULT '0',
        `locked` TINYINT(1) UNSIGNED NOT NULL DEFAULT '0',
        PRIMARY KEY (`id`),
        INDEX (`id_theme`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    // 12. Snippets
    $queries[] = "DROP TABLE IF EXISTS `{$prefix}snippets`";
    $queries[] = "CREATE TABLE `{$prefix}snippets` (
        `name` VARCHAR(255) DEFAULT NULL,
        `body` LONGTEXT,
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    // 13. Statistics
    $queries[] = "DROP TABLE IF EXISTS `{$prefix}stat`";
    $queries[] = "CREATE TABLE `{$prefix}stat` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `title` VARCHAR(255) NOT NULL,
        `clean_url_title` VARCHAR(255) DEFAULT '' NOT NULL,
        `main` LONGTEXT NOT NULL,
        `author_id` INT(11) NOT NULL,
        `category_id` INT(11) NOT NULL,
        `views` INT(11) DEFAULT '0',
        `rate` INT(11) DEFAULT '0',
        `date` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `comments` INT(11) NOT NULL DEFAULT '0',
        `tags` VARCHAR(255) NOT NULL,
        `description` TEXT NOT NULL,
        `sourse` VARCHAR(255) NOT NULL,
        `sourse_email` VARCHAR(255) NOT NULL,
        `sourse_site` VARCHAR(255) NOT NULL,
        `commented` ENUM('0','1') DEFAULT '1' NOT NULL,
        `available` ENUM('0','1') DEFAULT '1' NOT NULL,
        `view_on_home` ENUM('0','1') DEFAULT '1' NOT NULL,
        `on_home_top` ENUM('0','1') DEFAULT '0' NOT NULL,
        `premoder` ENUM('nochecked','rejected','confirmed') NOT NULL DEFAULT 'confirmed',
        `rating` INT(11) NOT NULL DEFAULT '0',
        PRIMARY KEY (`id`),
        INDEX (`category_id`, `author_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    // 14. Statistics Categories
    $queries[] = "DROP TABLE IF EXISTS `{$prefix}stat_categories`";
    $queries[] = "CREATE TABLE `{$prefix}stat_categories` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `parent_id` INT(11) DEFAULT '0',
        `path` VARCHAR(255) NOT NULL DEFAULT '',
        `announce` VARCHAR(255) NOT NULL DEFAULT '',
        `title` VARCHAR(255) NOT NULL,
        `view_on_home` ENUM('0','1') DEFAULT '1' NOT NULL,
        `no_access` VARCHAR(255) NOT NULL DEFAULT '',
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    $queries[] = "INSERT INTO `{$prefix}stat_categories` VALUES (1, '0', '', '', 'Test category', '1', '')";
    
    // 15. Statistics Data
    $queries[] = "DROP TABLE IF EXISTS `{$prefix}statistics`";
    $queries[] = "CREATE TABLE `{$prefix}statistics` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `ips` INT(50) DEFAULT '1',
        `cookie` INT(11) DEFAULT '0',
        `referer` VARCHAR(255) NOT NULL DEFAULT '',
        `date` DATE DEFAULT NULL,
        `views` INT(11) NOT NULL,
        `yandex_bot_views` INT(11) NOT NULL DEFAULT '0',
        `google_bot_views` INT(11) DEFAULT '0',
        `other_bot_views` INT(11) NOT NULL DEFAULT '0',
        `other_site_visits` INT(11) DEFAULT '0',
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    // 16. Themes
    $queries[] = "DROP TABLE IF EXISTS `{$prefix}themes`";
    $queries[] = "CREATE TABLE `{$prefix}themes` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `title` VARCHAR(120) DEFAULT NULL,
        `clean_url_title` VARCHAR(255) DEFAULT '' NOT NULL,
        `id_author` INT(6) NOT NULL DEFAULT '0',
        `time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `id_last_author` INT(6) NOT NULL DEFAULT '0',
        `last_post` DATETIME NOT NULL,
        `id_forum` INT(2) NOT NULL DEFAULT '0',
        `locked` TINYINT(1) UNSIGNED NOT NULL DEFAULT '0',
        `posts` INT(11) DEFAULT '0',
        `views` INT(11) DEFAULT '0',
        `important` ENUM('0','1') NOT NULL DEFAULT '0',
        `description` TEXT NOT NULL,
        `group_access` VARCHAR(255) DEFAULT '' NOT NULL,
        `first_top` ENUM('0','1') DEFAULT '0' NOT NULL,
        PRIMARY KEY (`id`),
        INDEX (`id_forum`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    // 17. Users
    $queries[] = "DROP TABLE IF EXISTS `{$prefix}users`";
    $queries[] = "CREATE TABLE `{$prefix}users` (
        `id` INT(6) NOT NULL AUTO_INCREMENT,
        `name` VARCHAR(32) NOT NULL,
        `first_name` VARCHAR(32) NOT NULL DEFAULT '',
        `last_name` VARCHAR(32) NOT NULL DEFAULT '',
        `passw` VARCHAR(255) NOT NULL,
        `email` VARCHAR(64) NOT NULL DEFAULT '',
        `color` VARCHAR(7) NOT NULL DEFAULT '',
        `state` VARCHAR(100) NOT NULL DEFAULT '',
        `rating` INT DEFAULT '0' NOT NULL,
        `timezone` TINYINT(2) NOT NULL DEFAULT '0',
        `url` VARCHAR(64) NOT NULL DEFAULT '',
        `icq` VARCHAR(12) NOT NULL DEFAULT '',
        `pol` ENUM('f','m','') DEFAULT '' NOT NULL,
        `jabber` VARCHAR(100) DEFAULT '' NOT NULL,
        `city` VARCHAR(100) DEFAULT '' NOT NULL,
        `telephone` BIGINT(15) DEFAULT 0 NOT NULL,
        `byear` INT(4) DEFAULT 0 NOT NULL,
        `bmonth` INT(2) DEFAULT 0 NOT NULL,
        `bday` INT(2) DEFAULT 0 NOT NULL,
        `about` TINYTEXT DEFAULT NULL,
        `signature` TINYTEXT DEFAULT NULL,
        `photo` VARCHAR(32) NOT NULL DEFAULT '',
        `puttime` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `last_visit` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `themes` MEDIUMINT(8) UNSIGNED NOT NULL DEFAULT '0',
        `posts` INT(10) UNSIGNED NOT NULL DEFAULT '0',
        `status` INT(2) NOT NULL DEFAULT '1',
        `locked` TINYINT(1) NOT NULL DEFAULT '0',
        `activation` VARCHAR(255) NOT NULL DEFAULT '',
        `warnings` INT DEFAULT '0' NOT NULL,
        `ban_expire` DATETIME DEFAULT NULL,
        `email_notification` ENUM('0','1') NOT NULL DEFAULT '1',
        `summer_time` ENUM('0','1') NOT NULL DEFAULT '1',
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    // 18. Users Votes
    $queries[] = "DROP TABLE IF EXISTS `{$prefix}users_votes`";
    $queries[] = "CREATE TABLE `{$prefix}users_votes` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `from_user` INT(11) NOT NULL,
        `to_user` INT(11) NOT NULL,
        `comment` TEXT NOT NULL,
        `date` DATETIME,
        `points` INT DEFAULT '0' NOT NULL,
        PRIMARY KEY (`id`),
        INDEX (`from_user`, `to_user`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    // 19. Users Settings
    $queries[] = "DROP TABLE IF EXISTS `{$prefix}users_settings`";
    $queries[] = "CREATE TABLE `{$prefix}users_settings` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `type` VARCHAR(255) NOT NULL,
        `values` TEXT NOT NULL,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    // 20. Foto
    $queries[] = "DROP TABLE IF EXISTS `{$prefix}foto`";
    $queries[] = "CREATE TABLE `{$prefix}foto` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `title` VARCHAR(255) NOT NULL,
        `clean_url_title` VARCHAR(255) DEFAULT '' NOT NULL,
        `description` TEXT NOT NULL,
        `filename` VARCHAR(255) NOT NULL,
        `views` INT(11) DEFAULT '0',
        `date` DATETIME DEFAULT NULL,
        `category_id` INT(11) DEFAULT NULL,
        `author_id` INT(11) NOT NULL,
        `comments` INT(11) NOT NULL DEFAULT '0',
        `rating` INT(11) NOT NULL DEFAULT '0',
        PRIMARY KEY (`id`),
        INDEX (`category_id`, `author_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    // 21. Foto Categories
    $queries[] = "DROP TABLE IF EXISTS `{$prefix}foto_categories`";
    $queries[] = "CREATE TABLE `{$prefix}foto_categories` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `parent_id` INT(11) DEFAULT '0',
        `path` VARCHAR(255) NOT NULL DEFAULT '',
        `announce` VARCHAR(255) NOT NULL DEFAULT '',
        `title` VARCHAR(255) NOT NULL,
        `view_on_home` ENUM('0','1') DEFAULT '1' NOT NULL,
        `no_access` VARCHAR(255) NOT NULL DEFAULT '',
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    $queries[] = "INSERT INTO `{$prefix}foto_categories` VALUES (1, 0, '', '', 'section', '1', '')";
    
    // 22. Search Index
    $queries[] = "DROP TABLE IF EXISTS `{$prefix}search_index`";
    $queries[] = "CREATE TABLE `{$prefix}search_index` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `index` TEXT NOT NULL,
        `entity_id` INT(11) NOT NULL,
        `entity_title` VARCHAR(255) NOT NULL DEFAULT '',
        `entity_table` VARCHAR(100) NOT NULL,
        `entity_view` VARCHAR(100) NOT NULL,
        `module` VARCHAR(100) NOT NULL,
        `date` DATETIME NOT NULL,
        PRIMARY KEY (`id`),
        FULLTEXT KEY `index` (`index`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    // 23. Additional Fields (News, Users, Stat, Loads)
    $additionalFieldTables = ['news', 'users', 'stat', 'loads'];
    
    foreach ($additionalFieldTables as $table) {
        $queries[] = "DROP TABLE IF EXISTS `{$prefix}{$table}_add_fields`";
        $queries[] = "CREATE TABLE `{$prefix}{$table}_add_fields` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `type` VARCHAR(10) NOT NULL,
            `name` VARCHAR(100) NOT NULL,
            `label` VARCHAR(255) NOT NULL,
            `size` INT(11) NOT NULL,
            `params` VARCHAR(255) NOT NULL,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        $queries[] = "DROP TABLE IF EXISTS `{$prefix}{$table}_add_content`";
        $queries[] = "CREATE TABLE `{$prefix}{$table}_add_content` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `field_id` INT(11) NOT NULL,
            `entity_id` INT(11) NOT NULL,
            `content` TEXT NOT NULL,
            PRIMARY KEY (`id`),
            INDEX (`field_id`, `entity_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    }
    
    // 24. Users Warnings
    $queries[] = "DROP TABLE IF EXISTS `{$prefix}users_warnings`";
    $queries[] = "CREATE TABLE `{$prefix}users_warnings` (
        `id` INT NOT NULL AUTO_INCREMENT,
        `user_id` INT NOT NULL,
        `admin_id` INT NOT NULL,
        `cause` VARCHAR(255) NOT NULL,
        `date` DATETIME NOT NULL,
        `points` INT DEFAULT '0' NOT NULL,
        PRIMARY KEY (`id`),
        INDEX (`user_id`, `admin_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    // 25. Attaches (Loads, Stat, News)
    $attachTables = ['loads', 'stat', 'news'];
    
    foreach ($attachTables as $table) {
        $queries[] = "DROP TABLE IF EXISTS `{$prefix}{$table}_attaches`";
        $queries[] = "CREATE TABLE `{$prefix}{$table}_attaches` (
            `id` INT NOT NULL AUTO_INCREMENT,
            `entity_id` INT NOT NULL DEFAULT '0',
            `user_id` INT NOT NULL,
            `attach_number` INT NOT NULL DEFAULT '0',
            `filename` VARCHAR(100) NOT NULL,
            `size` BIGINT NOT NULL,
            `date` DATETIME NOT NULL,
            `is_image` ENUM('0','1') DEFAULT '0' NOT NULL,
            PRIMARY KEY (`id`),
            INDEX (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    }
    
    // 26. Polls
    $queries[] = "DROP TABLE IF EXISTS `{$prefix}polls`";
    $queries[] = "CREATE TABLE `{$prefix}polls` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `theme_id` INT(11) NOT NULL,
        `variants` TEXT NOT NULL,
        `voted_users` TEXT NOT NULL,
        `question` TEXT NOT NULL,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    // Выполнение всех запросов
    echo '<div style="height:250px; overflow-y:scroll;">';
    
    foreach ($queries as $index => $query) {
        try {
            $db->exec($query);
            echo '<span style="color:#46B100; font-size:10px; line-height:10px;">' 
                 . $index . '. ' . escapeHtml(mb_substr($query, 0, 70, 'UTF-8')) 
                 . ' ...</span><br />';
            flush();
        } catch (PDOException $e) {
            throw new Exception('Ошибка выполнения запроса: ' . $e->getMessage() . ' Query: ' . $query);
        }
    }
    
    echo '</div>';
    
    // Создание администратора с безопасным хешированием пароля
    $adminName = $_SESSION['adm_name'];
    $adminEmail = $_SESSION['adm_email'];
    $adminPassword = password_hash($_SESSION['adm_pass'], PASSWORD_DEFAULT);
    
    $stmt = $db->prepare("INSERT INTO `{$prefix}users` 
        (`id`, `name`, `passw`, `email`, `status`, `puttime`) 
        VALUES (1, :name, :password, :email, '4', NOW())");
    
    $stmt->execute([
        ':name' => $adminName,
        ':password' => $adminPassword,
        ':email' => $adminEmail
    ]);
    
    // Фиксируем транзакцию
    $db->commit();
    
    // Вывод успешного завершения
    echo '<div style="">';
    echo '<h1 class="fin-h">' . __('All done') . '</h1>';
    echo '<a class="fin-a" href="../">' . __('Go to the site') . '</a>';
    echo '<a style="margin-left:40px;" class="fin-a" href="../admin/">' . __('To admin panel') . '</a><br />';
    echo '<span class="help">' . __('Before using the site, remove or rename INSTALL directory') . '</span>';
    echo '</div>';
    
} catch (Exception $e) {
    // Откатываем транзакцию в случае ошибки
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    
    http_response_code(500);
    echo '<div style="position:absolute;top:300px;left:35%;width:400px;color:#FF0000;">';
    echo 'Ошибка установки: ' . escapeHtml($e->getMessage());
    echo '</div>';
    
    logError($e->getMessage());
}
?>
