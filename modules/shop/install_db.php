<?php
/**
 * Shop module database schema.
 *
 * The installer includes this file inside the CMS bootstrap, so Config and
 * the database prefix are already available here.
 */

$shopPrefix = Config::read('prefix', 'db');

$FpsInstallQueries = array();

$FpsInstallQueries[] = "CREATE TABLE IF NOT EXISTS `{$shopPrefix}shop_currencies` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `code` VARCHAR(3) NOT NULL,
    `title` VARCHAR(64) NOT NULL DEFAULT '',
    `symbol` VARCHAR(16) NOT NULL DEFAULT '',
    `position` INT NOT NULL DEFAULT 0,
    `enabled` TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (`id`),
    UNIQUE KEY `code` (`code`),
    KEY `enabled_position` (`enabled`, `position`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8";

$FpsInstallQueries[] = "INSERT IGNORE INTO `{$shopPrefix}shop_currencies`
    (`code`, `title`, `symbol`, `position`, `enabled`) VALUES
    ('RUB', 'Российский рубль', '₽', 10, 1),
    ('USD', 'Доллар США', '$', 20, 1),
    ('EUR', 'Евро', '€', 30, 1),
    ('BYN', 'Белорусский рубль', 'Br', 40, 1)";

$FpsInstallQueries[] = "CREATE TABLE IF NOT EXISTS `{$shopPrefix}shop_categories` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `parent_id` INT UNSIGNED NOT NULL DEFAULT 0,
    `title` VARCHAR(255) NOT NULL DEFAULT '',
    `clean_url_title` VARCHAR(255) NOT NULL DEFAULT '',
    `description` TEXT,
    `image` VARCHAR(255) NOT NULL DEFAULT '',
    `position` INT NOT NULL DEFAULT 0,
    `active` TINYINT(1) NOT NULL DEFAULT 1,
    `meta_title` VARCHAR(255) NOT NULL DEFAULT '',
    `meta_description` TEXT,
    `meta_keywords` TEXT,
    `created_at` DATETIME DEFAULT NULL,
    `updated_at` DATETIME DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `parent_id` (`parent_id`),
    KEY `active_position` (`active`, `position`),
    KEY `clean_url_title` (`clean_url_title`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8";

$FpsInstallQueries[] = "CREATE TABLE IF NOT EXISTS `{$shopPrefix}shop_products` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `category_id` INT UNSIGNED NOT NULL DEFAULT 0,
    `title` VARCHAR(255) NOT NULL DEFAULT '',
    `clean_url_title` VARCHAR(255) NOT NULL DEFAULT '',
    `short_description` TEXT,
    `description` MEDIUMTEXT,
    `image` VARCHAR(255) NOT NULL DEFAULT '',
    `active` TINYINT(1) NOT NULL DEFAULT 1,
    `featured` TINYINT(1) NOT NULL DEFAULT 0,
    `position` INT NOT NULL DEFAULT 0,
    `views` INT UNSIGNED NOT NULL DEFAULT 0,
    `meta_title` VARCHAR(255) NOT NULL DEFAULT '',
    `meta_description` TEXT,
    `meta_keywords` TEXT,
    `created_at` DATETIME DEFAULT NULL,
    `updated_at` DATETIME DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `category_id` (`category_id`),
    KEY `active_position` (`active`, `position`),
    KEY `clean_url_title` (`clean_url_title`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8";

$FpsInstallQueries[] = "CREATE TABLE IF NOT EXISTS `{$shopPrefix}shop_attributes` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `title` VARCHAR(255) NOT NULL DEFAULT '',
    `position` INT NOT NULL DEFAULT 0,
    `active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME DEFAULT NULL,
    `updated_at` DATETIME DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `active_position` (`active`, `position`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8";

$FpsInstallQueries[] = "CREATE TABLE IF NOT EXISTS `{$shopPrefix}shop_attribute_values` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `attribute_id` INT UNSIGNED NOT NULL DEFAULT 0,
    `title` VARCHAR(255) NOT NULL DEFAULT '',
    `position` INT NOT NULL DEFAULT 0,
    `active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME DEFAULT NULL,
    `updated_at` DATETIME DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `attribute_id` (`attribute_id`),
    KEY `active_position` (`active`, `position`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8";

$FpsInstallQueries[] = "CREATE TABLE IF NOT EXISTS `{$shopPrefix}shop_product_attribute_values` (
    `product_id` INT UNSIGNED NOT NULL,
    `attribute_value_id` INT UNSIGNED NOT NULL,
    PRIMARY KEY (`product_id`, `attribute_value_id`),
    KEY `attribute_value_id` (`attribute_value_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8";

$FpsInstallQueries[] = "CREATE TABLE IF NOT EXISTS `{$shopPrefix}shop_variants` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `product_id` INT UNSIGNED NOT NULL DEFAULT 0,
    `title` VARCHAR(255) NOT NULL DEFAULT '',
    `sku` VARCHAR(64) NOT NULL DEFAULT '',
    `stock` INT NOT NULL DEFAULT 0,
    `image` VARCHAR(255) NOT NULL DEFAULT '',
    `active` TINYINT(1) NOT NULL DEFAULT 1,
    `position` INT NOT NULL DEFAULT 0,
    `created_at` DATETIME DEFAULT NULL,
    `updated_at` DATETIME DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `product_active` (`product_id`, `active`),
    KEY `sku` (`sku`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8";

$FpsInstallQueries[] = "CREATE TABLE IF NOT EXISTS `{$shopPrefix}shop_variant_values` (
    `variant_id` INT UNSIGNED NOT NULL,
    `attribute_value_id` INT UNSIGNED NOT NULL,
    PRIMARY KEY (`variant_id`, `attribute_value_id`),
    KEY `attribute_value_id` (`attribute_value_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8";

$FpsInstallQueries[] = "CREATE TABLE IF NOT EXISTS `{$shopPrefix}shop_variant_prices` (
    `variant_id` INT UNSIGNED NOT NULL,
    `currency_code` VARCHAR(3) NOT NULL DEFAULT 'RUB',
    `price` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    PRIMARY KEY (`variant_id`, `currency_code`),
    KEY `currency_price` (`currency_code`, `price`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8";

$FpsInstallQueries[] = "CREATE TABLE IF NOT EXISTS `{$shopPrefix}shop_orders` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL DEFAULT 0,
    `status` VARCHAR(32) NOT NULL DEFAULT 'new',
    `currency_code` VARCHAR(3) NOT NULL DEFAULT 'RUB',
    `subtotal` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `delivery_price` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `total` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `customer_name` VARCHAR(255) NOT NULL DEFAULT '',
    `email` VARCHAR(255) NOT NULL DEFAULT '',
    `phone` VARCHAR(64) NOT NULL DEFAULT '',
    `address` TEXT,
    `comment` TEXT,
    `delivery_method` VARCHAR(32) NOT NULL DEFAULT 'pickup',
    `delivery_title` VARCHAR(255) NOT NULL DEFAULT '',
    `payment_method` VARCHAR(32) NOT NULL DEFAULT 'cash',
    `payment_title` VARCHAR(255) NOT NULL DEFAULT '',
    `access_token` VARCHAR(64) NOT NULL DEFAULT '',
    `stock_released` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` DATETIME DEFAULT NULL,
    `updated_at` DATETIME DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `user_id` (`user_id`),
    KEY `status` (`status`),
    KEY `created_at` (`created_at`),
    KEY `access_token` (`access_token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8";

$FpsInstallQueries[] = "CREATE TABLE IF NOT EXISTS `{$shopPrefix}shop_order_items` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `order_id` INT UNSIGNED NOT NULL DEFAULT 0,
    `product_id` INT UNSIGNED NOT NULL DEFAULT 0,
    `variant_id` INT UNSIGNED NOT NULL DEFAULT 0,
    `product_title` VARCHAR(255) NOT NULL DEFAULT '',
    `variant_title` VARCHAR(255) NOT NULL DEFAULT '',
    `sku` VARCHAR(64) NOT NULL DEFAULT '',
    `price` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `quantity` INT NOT NULL DEFAULT 1,
    `total` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    PRIMARY KEY (`id`),
    KEY `order_id` (`order_id`),
    KEY `product_id` (`product_id`),
    KEY `variant_id` (`variant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8";
