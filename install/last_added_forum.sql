INSERT INTO `snippets` (`name`, `body`) 
VALUES ('last_added', '<?php
use Core\\Database\\DatabaseService;

/**
 * Last Added Forum Posts Snippet
 * 
 * Displays the latest forum themes with caching and security
 */
 
class LastAddedForumThemes 
{
    private const CACHE_KEY = \'last_forum_themes\';
    private const CACHE_TTL = 300; // 5 minutes
    
    public static function render(): string 
    {
        try {
            $db = DatabaseService::getInstance();
            $themes = self::getLatestThemes($db);
            
            if (empty($themes)) {
                return \'\';
            }
            
            return self::generateHtml($themes);
            
        } catch (Exception $e) {
            error_log(\'LastAddedForumThemes error: \' . $e->getMessage());
            return \'\';
        }
    }
    
    private static function getLatestThemes(PDO $db): array 
    {
        // Try to get from cache first
        $cache = CacheService::getInstance();
        $cachedThemes = $cache->get(self::CACHE_KEY);
        
        if ($cachedThemes !== null) {
            return $cachedThemes;
        }
        
        $query = "SELECT id, title, last_post 
                 FROM themes 
                 WHERE publish = \'1\' 
                 ORDER BY last_post DESC 
                 LIMIT 10";
        
        $stmt = $db->prepare($query);
        $stmt->execute();
        
        $themes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Cache the results
        $cache->set(self::CACHE_KEY, $themes, self::CACHE_TTL);
        
        return $themes;
    }
    
    private static function generateHtml(array $themes): string 
    {
        $output = \'<ul class="last-added-themes">\';
        
        foreach ($themes as $theme) {
            $title = htmlspecialchars($theme[\'title\'] ?? \'\', ENT_QUOTES, \'UTF-8\');
            $url = htmlspecialchars(\'/\' . R . \'forum/view_theme/\' . $theme[\'id\'], ENT_QUOTES, \'UTF-8\');
            $date = self::formatDate($theme[\'last_post\'] ?? \'\');
            
            $output .= sprintf(
                \'<li class="last-added-themes__item">\',
                \'<a href="%s" class="last-added-themes__link">%s</a>\',
                \' <span class="last-added-themes__date">%s</span>\',
                \'</li>\',
                $url,
                $title,
                $date
            );
        }
        
        $output .= \'</ul>\';
        return $output;
    }
    
    private static function formatDate(string $dateString): string 
    {
        if (empty($dateString)) {
            return \'\';
        }
        
        try {
            $date = new DateTime($dateString);
            $now = new DateTime();
            $diff = $now->diff($date);
            
            if ($diff->days === 0) {
                return \'сегодня в \' . $date->format(\'H:i\');
            } elseif ($diff->days === 1) {
                return \'вчера в \' . $date->format(\'H:i\');
            } elseif ($diff->days < 7) {
                return $diff->days . \' д. назад\';
            } else {
                return $date->format(\'d.m.Y H:i\');
            }
        } catch (Exception $e) {
            return $dateString;
        }
    }
}

echo LastAddedForumThemes::render();
?>');
