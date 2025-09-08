<?php
declare(strict_types=1);

/**
 * Chat Module Configuration
 * 
 * Modernized for PHP 8.1+ with type safety and security improvements
 */

namespace AtomX\Modules\Chat;

class ChatModuleInfo
{
    public const MODULE_NAME = 'chat';
    public const MODULE_TITLE = 'Chat';
    
    /**
     * Get module menu configuration
     */
    public static function getMenuInfo(): array
    {
        return [
            'url' => 'settings.php?m=' . self::MODULE_NAME,
            'ankor' => self::translate('Chat'),
            'sub' => [
                'settings.php?m=' . self::MODULE_NAME => self::translate('Settings'),
                'design.php?m=' . self::MODULE_NAME => self::translate('Design'),
            ],
        ];
    }
    
    /**
     * Get module settings configuration
     */
    public static function getSettingsInfo(): array
    {
        return [
            // General settings
            'title' => [
                'type' => 'text',
                'title' => self::translate('Title'),
                'description' => self::translate('Used in the template as {{ meta_title }} | {{ title }}'),
                'validation' => [
                    'required' => true,
                    'max_length' => 255
                ]
            ],
            
            'description' => [
                'type' => 'text',
                'title' => self::translate('Description'),
                'description' => self::translate('Used in the template as {{ meta_description }}'),
                'validation' => [
                    'max_length' => 500
                ]
            ],
            
            // Restrictions section
            self::createSectionHeader(self::translate('Restrictions')),
            
            'max_lenght' => [
                'type' => 'number',
                'title' => self::translate('Max message length'),
                'description' => self::translate('Maximum allowed characters per message'),
                'help' => self::translate('Symbols'),
                'default' => 500,
                'validation' => [
                    'required' => true,
                    'min' => 1,
                    'max' => 5000
                ],
                'attributes' => [
                    'min' => 1,
                    'max' => 5000,
                    'step' => 1
                ]
            ],
            
            'flood_control' => [
                'type' => 'number',
                'title' => self::translate('Flood control'),
                'description' => self::translate('Minimum seconds between messages from same user'),
                'help' => self::translate('Seconds'),
                'default' => 10,
                'validation' => [
                    'min' => 0,
                    'max' => 3600
                ]
            ],
            
            'max_messages' => [
                'type' => 'number',
                'title' => self::translate('Max messages stored'),
                'description' => self::translate('Maximum number of messages to keep in history'),
                'default' => 100,
                'validation' => [
                    'min' => 10,
                    'max' => 1000
                ]
            ],
            
            // Common settings section
            self::createSectionHeader(self::translate('Common')),
            
            'active' => [
                'type' => 'checkbox',
                'title' => self::translate('Status'),
                'description' => self::translate('Enable/Disable module'),
                'value' => '1',
                'default' => '1',
                'checked' => true
            ],
            
            'enable_guests' => [
                'type' => 'checkbox',
                'title' => self::translate('Allow guest posts'),
                'description' => self::translate('Allow unregistered users to post messages'),
                'value' => '1',
                'default' => '0',
                'checked' => false
            ],
            
            'enable_bbcode' => [
                'type' => 'checkbox',
                'title' => self::translate('Enable BBCode'),
                'description' => self::translate('Allow BBCode formatting in messages'),
                'value' => '1',
                'default' => '1',
                'checked' => true
            ],
            
            'enable_smilies' => [
                'type' => 'checkbox',
                'title' => self::translate('Enable smilies'),
                'description' => self::translate('Convert smiley codes to images'),
                'value' => '1',
                'default' => '1',
                'checked' => true
            ],
            
            'enable_links' => [
                'type' => 'checkbox',
                'title' => self::translate('Enable links'),
                'description' => self::translate('Automatically convert URLs to clickable links'),
                'value' => '1',
                'default' => '1',
                'checked' => true
            ],
            
            // Moderation settings section
            self::createSectionHeader(self::translate('Moderation')),
            
            'premoderate' => [
                'type' => 'checkbox',
                'title' => self::translate('Pre-moderate messages'),
                'description' => self::translate('Require admin approval before messages appear'),
                'value' => '1',
                'default' => '0',
                'checked' => false
            ],
            
            'enable_captcha_guests' => [
                'type' => 'checkbox',
                'title' => self::translate('CAPTCHA for guests'),
                'description' => self::translate('Require CAPTCHA for guest messages'),
                'value' => '1',
                'default' => '1',
                'checked' => true
            ],
            
            'enable_captcha_users' => [
                'type' => 'checkbox',
                'title' => self::translate('CAPTCHA for users'),
                'description' => self::translate('Require CAPTCHA for registered users'),
                'value' => '1',
                'default' => '0',
                'checked' => false
            ],
            
            // Display settings section
            self::createSectionHeader(self::translate('Display')),
            
            'messages_per_page' => [
                'type' => 'number',
                'title' => self::translate('Messages per page'),
                'description' => self::translate('Number of messages to show per page'),
                'default' => 20,
                'validation' => [
                    'min' => 5,
                    'max' => 100
                ]
            ],
            
            'update_interval' => [
                'type' => 'number',
                'title' => self::translate('Update interval'),
                'description' => self::translate('How often to check for new messages (seconds)'),
                'help' => self::translate('Seconds'),
                'default' => 10,
                'validation' => [
                    'min' => 2,
                    'max' => 60
                ]
            ],
            
            'show_timestamps' => [
                'type' => 'checkbox',
                'title' => self::translate('Show timestamps'),
                'description' => self::translate('Display message timestamps'),
                'value' => '1',
                'default' => '1',
                'checked' => true
            ],
            
            'show_user_avatars' => [
                'type' => 'checkbox',
                'title' => self::translate('Show avatars'),
                'description' => self::translate('Display user avatars in messages'),
                'value' => '1',
                'default' => '1',
                'checked' => true
            ]
        ];
    }
    
    /**
     * Get default module settings
     */
    public static function getDefaultSettings(): array
    {
        return [
            'title' => self::translate('Chat'),
            'description' => self::translate('Real-time communication'),
            'max_lenght' => 500,
            'flood_control' => 10,
            'max_messages' => 100,
            'active' => '1',
            'enable_guests' => '0',
            'enable_bbcode' => '1',
            'enable_smilies' => '1',
            'enable_links' => '1',
            'premoderate' => '0',
            'enable_captcha_guests' => '1',
            'enable_captcha_users' => '0',
            'messages_per_page' => 20,
            'update_interval' => 10,
            'show_timestamps' => '1',
            'show_user_avatars' => '1'
        ];
    }
    
    /**
     * Get module permissions configuration
     */
    public static function getPermissionsInfo(): array
    {
        return [
            'chat' => [
                'title' => self::translate('Chat'),
                'permissions' => [
                    'view_messages' => [
                        'title' => self::translate('View messages'),
                        'description' => self::translate('Can view chat messages')
                    ],
                    'add_materials' => [
                        'title' => self::translate('Post messages'),
                        'description' => self::translate('Can post new messages')
                    ],
                    'delete_materials' => [
                        'title' => self::translate('Delete messages'),
                        'description' => self::translate('Can delete messages (moderation)')
                    ],
                    'edit_materials' => [
                        'title' => self::translate('Edit messages'),
                        'description' => self::translate('Can edit existing messages')
                    ],
                    'bypass_flood_control' => [
                        'title' => self::translate('Bypass flood control'),
                        'description' => self::translate('Ignore flood control restrictions')
                    ],
                    'bypass_moderation' => [
                        'title' => self::translate('Bypass moderation'),
                        'description' => self::translate('Messages appear without moderation')
                    ],
                    'view_ip_addresses' => [
                        'title' => self::translate('View IP addresses'),
                        'description' => self::translate('Can see user IP addresses')
                    ]
                ]
            ]
        ];
    }
    
    /**
     * Get module dependencies
     */
    public static function getDependencies(): array
    {
        return [
            'php' => [
                'version' => '8.1.0',
                'extensions' => ['json', 'session']
            ],
            'modules' => [
                'users' => '1.0.0'
            ]
        ];
    }
    
    /**
     * Create a section header for settings
     */
    private static function createSectionHeader(string $title): array
    {
        return [
            'type' => 'section',
            'title' => $title,
            'is_header' => true
        ];
    }
    
    /**
     * Translation helper with fallback
     */
    private static function translate(string $text): string
    {
        // Use framework translation function if available
        if (function_exists('__')) {
            return __($text);
        }
        
        // Fallback to English
        $translations = [
            'Chat' => 'Чат',
            'Settings' => 'Настройки',
            'Design' => 'Дизайн',
            'Title' => 'Заголовок',
            'Description' => 'Описание',
            'Used in the template as {{ meta_title }} | {{ title }}' => 'Используется в шаблоне как {{ meta_title }} | {{ title }}',
            'Used in the template as {{ meta_description }}' => 'Используется в шаблоне как {{ meta_description }}',
            'Restrictions' => 'Ограничения',
            'Max message length' => 'Максимальная длина сообщения',
            'Symbols' => 'Символов',
            'Common' => 'Общие',
            'Status' => 'Статус',
            'Enable/Disable' => 'Включить/Выключить',
            'Maximum allowed characters per message' => 'Максимальное количество символов в сообщении',
            'Flood control' => 'Защита от флуда',
            'Minimum seconds between messages from same user' => 'Минимальная пауза между сообщениями от одного пользователя',
            'Seconds' => 'Секунд',
            'Max messages stored' => 'Максимум сообщений в истории',
            'Maximum number of messages to keep in history' => 'Максимальное количество хранимых сообщений',
            'Allow guest posts' => 'Разрешить гостевые сообщения',
            'Allow unregistered users to post messages' => 'Разрешить незарегистрированным пользователям писать сообщения',
            'Enable BBCode' => 'Включить BBCode',
            'Allow BBCode formatting in messages' => 'Разрешить форматирование BBCode в сообщениях',
            'Enable smilies' => 'Включить смайлики',
            'Convert smiley codes to images' => 'Преобразовать коды смайликов в изображения',
            'Enable links' => 'Включить ссылки',
            'Automatically convert URLs to clickable links' => 'Автоматически преобразовывать URL в кликабельные ссылки',
            'Moderation' => 'Модерация',
            'Pre-moderate messages' => 'Премодерация сообщений',
            'Require admin approval before messages appear' => 'Требовать одобрения администратора перед показом сообщений',
            'CAPTCHA for guests' => 'CAPTCHA для гостей',
            'Require CAPTCHA for guest messages' => 'Требовать CAPTCHA для гостевых сообщений',
            'CAPTCHA for users' => 'CAPTCHA для пользователей',
            'Require CAPTCHA for registered users' => 'Требовать CAPTCHA для зарегистрированных пользователей',
            'Display' => 'Отображение',
            'Messages per page' => 'Сообщений на странице',
            'Number of messages to show per page' => 'Количество сообщений для отображения на странице',
            'Update interval' => 'Интервал обновления',
            'How often to check for new messages' => 'Как часто проверять новые сообщения',
            'Show timestamps' => 'Показывать время',
            'Display message timestamps' => 'Отображать временные метки сообщений',
            'Show avatars' => 'Показывать аватары',
            'Display user avatars in messages' => 'Отображать аватары пользователей в сообщениях',
            'View messages' => 'Просмотр сообщений',
            'Can view chat messages' => 'Может просматривать сообщения чата',
            'Post messages' => 'Отправка сообщений',
            'Can post new messages' => 'Может отправлять новые сообщения',
            'Delete messages' => 'Удаление сообщений',
            'Can delete messages' => 'Может удалять сообщения',
            'Edit messages' => 'Редактирование сообщений',
            'Can edit existing messages' => 'Может редактировать существующие сообщения',
            'Bypass flood control' => 'Обход защиты от флуда',
            'Ignore flood control restrictions' => 'Игнорировать ограничения защиты от флуда',
            'Bypass moderation' => 'Обход модерации',
            'Messages appear without moderation' => 'Сообщения появляются без модерации',
            'View IP addresses' => 'Просмотр IP адресов',
            'Can see user IP addresses' => 'Может видеть IP адреса пользователей'
        ];
        
        return $translations[$text] ?? $text;
    }
}

// Backward compatibility for legacy code
$menuInfo = ChatModuleInfo::getMenuInfo();
$settingsInfo = ChatModuleInfo::getSettingsInfo();

?>
