<?php

declare(strict_types=1);

/**
 * Forum Module Configuration
 * 
 * @package     CMS AtomX
 * @subpackage  Forum Module
 * @version     1.8.2
 * @copyright   © Andrey Brykin
 */

class ForumModuleInfo
{
    public const KILOBYTE = 1024;
    public const MEGABYTE = 1048576;
    
    public static function getMenuInfo(): array
    {
        return [
            'url' => 'settings.php?m=forum',
            'ankor' => __('Forum'),
            'sub' => [
                'settings.php?m=forum' => __('Settings'),
                'design.php?m=forum' => __('Design'),
                'forum_cat.php' => __('Forums Management'),
                'forum_repair.php' => __('Posts Recounting'),
            ],
        ];
    }

    public static function getSettingsInfo(): array
    {
        return [
            // General settings
            'title' => [
                'type' => 'textarea',
                'title' => __('Title'),
                'description' => sprintf(__('Used in the template as %s'), '{{ meta_title }} | {{ title }}'),
                'category' => 'general',
            ],
            
            'description' => [
                'type' => 'textarea',
                'title' => __('Description'),
                'description' => sprintf(__('Used in the template as %s'), '{{ meta_description }}'),
                'category' => 'general',
            ],
            
            'not_reg_user' => [
                'type' => 'text',
                'title' => __('Alias for Guests'),
                'description' => __('This name will be shown as non-authorized user nickname'),
                'category' => 'general',
            ],

            // Restrictions section
            self::createSectionHeader(__('Restrictions')),
            
            'max_post_length' => [
                'type' => 'number',
                'title' => __('Max Message Length'),
                'description' => __('Maximum number of characters allowed in a single post'),
                'help' => __('Characters'),
                'min' => 1000,
                'max' => 100000,
                'category' => 'restrictions',
            ],
            
            'posts_per_page' => [
                'type' => 'number',
                'title' => __('Posts Per Page'),
                'description' => __('Number of posts displayed on each page'),
                'min' => 5,
                'max' => 100,
                'category' => 'restrictions',
            ],
            
            'themes_per_page' => [
                'type' => 'number',
                'title' => __('Topics Per Page'),
                'description' => __('Number of topics displayed on each page'),
                'min' => 5,
                'max' => 50,
                'category' => 'restrictions',
            ],

            // Images section
            self::createSectionHeader(__('Images')),
            
            'img_size_x' => [
                'type' => 'number',
                'title' => __('Image Width'),
                'description' => __('Maximum width for uploaded images'),
                'help' => 'px',
                'min' => 100,
                'max' => 2000,
                'category' => 'images',
            ],
            
            'img_size_y' => [
                'type' => 'number',
                'title' => __('Image Height'),
                'description' => __('Maximum height for uploaded images'),
                'help' => 'px',
                'min' => 100,
                'max' => 2000,
                'category' => 'images',
            ],
            
            'max_attaches_size' => [
                'type' => 'number',
                'title' => __('Max Attachment Size'),
                'description' => __('Maximum size for a single attached file'),
                'help' => __('KB'),
                'onview' => ['division' => self::KILOBYTE],
                'onsave' => ['multiply' => self::KILOBYTE],
                'category' => 'images',
            ],
            
            'max_attaches' => [
                'type' => 'number',
                'title' => __('Max Attachments'),
                'description' => __('Maximum number of files that can be uploaded at once'),
                'help' => __('Files'),
                'min' => 1,
                'max' => 10,
                'category' => 'images',
            ],
            
            'max_all_attaches_size' => [
                'type' => 'number',
                'title' => __('Max Total User Storage'),
                'description' => __('Maximum total size of all files a user can upload'),
                'help' => __('MB'),
                'onview' => ['division' => self::MEGABYTE],
                'onsave' => ['multiply' => self::MEGABYTE],
                'category' => 'images',
            ],
            
            'max_guest_attaches_size' => [
                'type' => 'number',
                'title' => __('Max Guest Storage'),
                'description' => __('Maximum total size of all files guests can upload'),
                'help' => __('MB'),
                'onview' => ['division' => self::MEGABYTE],
                'onsave' => ['multiply' => self::MEGABYTE],
                'category' => 'images',
            ],
            
            // Common settings section
            self::createSectionHeader(__('Common')),
            
            'active' => [
                'type' => 'checkbox',
                'title' => __('Module Status'),
                'description' => __('Enable or disable the forum module'),
                'value' => '1',
                'checked' => '1',
                'category' => 'common',
            ],
            
            'enable_bbcode' => [
                'type' => 'checkbox',
                'title' => __('Enable BBCode'),
                'description' => __('Allow users to use BBCode in posts'),
                'value' => '1',
                'checked' => '1',
                'category' => 'common',
            ],
            
            'enable_smilies' => [
                'type' => 'checkbox',
                'title' => __('Enable Smilies'),
                'description' => __('Convert text smilies to images'),
                'value' => '1',
                'checked' => '1',
                'category' => 'common',
            ],
            
            'posts_require_approval' => [
                'type' => 'checkbox',
                'title' => __('Posts Require Approval'),
                'description' => __('New posts must be approved by moderator'),
                'value' => '1',
                'category' => 'common',
            ],
        ];
    }

    public static function getDefaultValues(): array
    {
        return [
            'title' => __('Forum'),
            'description' => __('Community Discussion Forum'),
            'not_reg_user' => __('Guest'),
            'max_post_length' => 10000,
            'posts_per_page' => 20,
            'themes_per_page' => 15,
            'img_size_x' => 800,
            'img_size_y' => 600,
            'max_attaches_size' => 2 * self::KILOBYTE, // 2MB in bytes
            'max_attaches' => 5,
            'max_all_attaches_size' => 50 * self::MEGABYTE, // 50MB in bytes
            'max_guest_attaches_size' => 10 * self::MEGABYTE, // 10MB in bytes
            'active' => '1',
            'enable_bbcode' => '1',
            'enable_smilies' => '1',
            'posts_require_approval' => '0',
        ];
    }

    public static function getSettingsByCategory(): array
    {
        $settings = self::getSettingsInfo();
        $categorized = [];
        
        foreach ($settings as $key => $setting) {
            if (isset($setting['category'])) {
                $category = $setting['category'];
                if (!isset($categorized[$category])) {
                    $categorized[$category] = [];
                }
                $categorized[$category][$key] = $setting;
            }
        }
        
        return $categorized;
    }

    public static function validateSetting(string $key, mixed $value): bool
    {
        $settings = self::getSettingsInfo();
        
        if (!isset($settings[$key])) {
            return false;
        }
        
        $setting = $settings[$key];
        
        switch ($setting['type']) {
            case 'number':
                return is_numeric($value) && 
                       (!isset($setting['min']) || $value >= $setting['min']) &&
                       (!isset($setting['max']) || $value <= $setting['max']);
                
            case 'checkbox':
                return in_array($value, ['0', '1'], true);
                
            case 'text':
            case 'textarea':
                return is_string($value) && mb_strlen($value) <= 255;
                
            default:
                return true;
        }
    }

    public static function processSettingValue(string $key, mixed $value, string $direction = 'save'): mixed
    {
        $settings = self::getSettingsInfo();
        
        if (!isset($settings[$key]) || !isset($settings[$key][$direction])) {
            return $value;
        }
        
        $operation = $settings[$key][$direction];
        
        if (isset($operation['multiply']) && is_numeric($value)) {
            return $value * $operation['multiply'];
        }
        
        if (isset($operation['division']) && is_numeric($value)) {
            return $value / $operation['division'];
        }
        
        return $value;
    }

    private static function createSectionHeader(string $title): array
    {
        return [
            'type' => 'section_header',
            'title' => $title,
            'is_header' => true,
        ];
    }

    public static function getFileSizeHumanReadable(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $index = 0;
        
        while ($bytes >= 1024 && $index < count($units) - 1) {
            $bytes /= 1024;
            $index++;
        }
        
        return round($bytes, 2) . ' ' . $units[$index];
    }
}

// Backward compatibility functions
function getForumMenuInfo(): array
{
    return ForumModuleInfo::getMenuInfo();
}

function getForumSettingsInfo(): array
{
    return ForumModuleInfo::getSettingsInfo();
}

// Example usage in modern PHP 8.1 style:
$menuInfo = ForumModuleInfo::getMenuInfo();
$settingsInfo = ForumModuleInfo::getSettingsInfo();
$defaultValues = ForumModuleInfo::getDefaultValues();
