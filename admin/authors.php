<?php

declare(strict_types=1);

/**
 * Authors Page - Development Team
 * 
 * @author Andrey Brykin (Drunya) - Original
 * @email drunyacoder@gmail.com
 * @site http://atomx.net
 * @version 2.0 - Refactored for PHP 8.4
 * @package CMS AtomX
 * @subpackage Authors List (Admin Part)
 * @copyright ©Andrey Brykin 2010-2014, Refactored 2025
 */

namespace Admin\Authors;

require_once '../sys/boot.php';
require_once ROOT . '/admin/inc/adm_boot.php';

use InvalidArgumentException;

/**
 * Development Team Data Transfer Object
 */
readonly class TeamMember
{
    public function __construct(
        public string $name,
        public string $nickname,
        public string $role = '',
        public string $email = '',
        public string $website = ''
    ) {}
    
    public function getDisplayName(): string
    {
        return $this->nickname ? "{$this->name} ({$this->nickname})" : $this->name;
    }
}

/**
 * Development Team Section
 */
readonly class TeamSection
{
    /**
     * @param TeamMember[] $members
     */
    public function __construct(
        public string $title,
        public array $members
    ) {
        $this->validateMembers($members);
    }
    
    private function validateMembers(array $members): void
    {
        foreach ($members as $member) {
            if (!$member instanceof TeamMember) {
                throw new InvalidArgumentException('All members must be TeamMember instances');
            }
        }
    }
    
    public function getMembersCount(): int
    {
        return count($this->members);
    }
}

/**
 * Authors Page Controller
 */
class AuthorsPageController
{
    private readonly array $teamSections;
    
    public function __construct()
    {
        $this->teamSections = $this->initializeTeamData();
    }
    
    public function render(): void
    {
        $this->renderHeader();
        $this->renderTeamSections();
        $this->renderFooter();
    }
    
    private function initializeTeamData(): array
    {
        return [
            new TeamSection(
                title: __('Idea by'),
                members: [
                    new TeamMember('Andrey Brykin', 'Drunya', email: 'drunyacoder@gmail.com')
                ]
            ),
            new TeamSection(
                title: __('Programmers'),
                members: [
                    new TeamMember('Andrey Brykin', 'Drunya', email: 'drunyacoder@gmail.com'),
                    new TeamMember('Danilov Alexandr', 'modos189')
                ]
            ),
            new TeamSection(
                title: __('Testers and audit'),
                members: [
                    new TeamMember('Andrey Konyaev', 'Ater'),
                    new TeamMember('Laguta Dmitry', 'ARMI'),
                    new TeamMember('Roman Maximov', 'r00t_san'),
                    new TeamMember('Alexandr Verenik', 'Wasja'),
                    new TeamMember('Danilov Alexandr', 'modos189')
                ]
            ),
            new TeamSection(
                title: __('Marketing'),
                members: [
                    new TeamMember('Andrey Brykin', 'Drunya'),
                    new TeamMember('Andrey Konyaev', 'Ater')
                ]
            ),
            new TeamSection(
                title: __('Design and Templates'),
                members: [
                    new TeamMember('Lapin Boris', 'MrBoriska'),
                    new TeamMember('Andrey Brykin', 'Drunya'),
                    new TeamMember('Alexandr Bognar', 'Krevedko'),
                    new TeamMember('Roman Maximov', 'r00t_san'),
                    new TeamMember('Laguta Dmitry', 'ARMI')
                ]
            ),
            new TeamSection(
                title: __('Specialists by Security'),
                members: [
                    new TeamMember('Roman Maximov', 'r00t_san')
                ]
            ),
            new TeamSection(
                title: __('Additional Software'),
                members: [
                    new TeamMember('Andrey Brykin', 'Drunya'),
                    new TeamMember('Alexandr Verenik', 'Wasja')
                ]
            ),
            new TeamSection(
                title: __('Translation'),
                members: [
                    new TeamMember('Victor Sproot', 'Sproot'),
                    new TeamMember('Andrey Brykin', 'Drunya')
                ]
            )
        ];
    }
    
    private function renderHeader(): void
    {
        $pageTitle = $page_title = __('Dev. Team');
        $pageNav = $page_title;
        $pageNavr = $this->buildNavigationRight();
        
        include_once ROOT . '/admin/template/header.php';
    }
    
    private function buildNavigationRight(): string
    {
        $helpText = htmlspecialchars(__('Work! Work! Work!'), ENT_QUOTES, 'UTF-8');
        $helpTitle = htmlspecialchars(__('Nobody prevents it'), ENT_QUOTES, 'UTF-8');
        $linkText = htmlspecialchars(__('I want to be here'), ENT_QUOTES, 'UTF-8');
        
        return sprintf(
            '<span style="float:right;"><a href="javascript://" onclick="showHelpWin(\'%s\', \'%s\')">%s</a></span>',
            $helpText,
            $helpTitle,
            $linkText
        );
    }
    
    private function renderTeamSections(): void
    {
        ?>
        <div class="list">
            <div class="title"><?= htmlspecialchars(__('Authors'), ENT_QUOTES, 'UTF-8') ?></div>
            <div class="level1">
                <div class="items">
                    <?php foreach ($this->teamSections as $section): ?>
                        <?= $this->renderTeamSection($section) ?>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php
    }
    
    private function renderTeamSection(TeamSection $section): string
    {
        ob_start();
        ?>
        <div class="setting-item">
            <div class="center">
                <h3><?= htmlspecialchars($section->title, ENT_QUOTES, 'UTF-8') ?></h3>
                <?php foreach ($section->members as $index => $member): ?>
                    <?= htmlspecialchars($member->getDisplayName(), ENT_QUOTES, 'UTF-8') ?>
                    <?php if ($index < $section->getMembersCount() - 1): ?>
                        <br />
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
    
    private function renderFooter(): void
    {
        include_once ROOT . '/admin/template/footer.php';
    }
    
    /**
     * Get team statistics
     */
    public function getTeamStats(): array
    {
        $totalMembers = 0;
        $uniqueMembers = [];
        $sectionStats = [];
        
        foreach ($this->teamSections as $section) {
            $memberCount = $section->getMembersCount();
            $totalMembers += $memberCount;
            
            $sectionStats[] = [
                'section' => $section->title,
                'count' => $memberCount
            ];
            
            // Count unique members
            foreach ($section->members as $member) {
                $uniqueMembers[$member->getDisplayName()] = true;
            }
        }
        
        return [
            'total_positions' => $totalMembers,
            'unique_members' => count($uniqueMembers),
            'sections' => count($this->teamSections),
            'section_stats' => $sectionStats
        ];
    }
}

/**
 * Enhanced Authors Page with Additional Features
 */
class EnhancedAuthorsPageController extends AuthorsPageController
{
    private const CACHE_KEY = 'authors_page_data';
    private const CACHE_TTL = 3600; // 1 hour
    
    public function __construct(
        private readonly ?CacheInterface $cache = null,
        private readonly ?LoggerInterface $logger = null
    ) {
        parent::__construct();
    }
    
    public function render(): void
    {
        try {
            $this->logPageView();
            parent::render();
        } catch (\Throwable $e) {
            $this->logger?->error('Error rendering authors page: ' . $e->getMessage(), [
                'exception' => $e,
                'user_ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
            ]);
            
            // Fallback to basic rendering
            parent::render();
        }
    }
    
    public function renderWithCache(): void
    {
        if ($this->cache) {
            $cachedContent = $this->cache->get(self::CACHE_KEY);
            if ($cachedContent !== null) {
                echo $cachedContent;
                return;
            }
        }
        
        ob_start();
        $this->render();
        $content = ob_get_clean();
        
        $this->cache?->set(self::CACHE_KEY, $content, self::CACHE_TTL);
        
        echo $content;
    }
    
    private function logPageView(): void
    {
        $this->logger?->info('Authors page viewed', [
            'timestamp' => time(),
            'user_ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
        ]);
    }
    
    public function exportTeamData(string $format = 'json'): string
    {
        $data = [
            'export_date' => date('Y-m-d H:i:s'),
            'cms_version' => '2.0',
            'team_sections' => []
        ];
        
        foreach ($this->teamSections as $section) {
            $sectionData = [
                'title' => $section->title,
                'members' => []
            ];
            
            foreach ($section->members as $member) {
                $sectionData['members'][] = [
                    'name' => $member->name,
                    'nickname' => $member->nickname,
                    'display_name' => $member->getDisplayName(),
                    'role' => $member->role,
                    'email' => $member->email,
                    'website' => $member->website
                ];
            }
            
            $data['team_sections'][] = $sectionData;
        }
        
        return match ($format) {
            'json' => json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            'xml' => $this->arrayToXml($data),
            default => throw new InvalidArgumentException("Unsupported format: {$format}")
        };
    }
    
    private function arrayToXml(array $data): string
    {
        $xml = new \SimpleXMLElement('<team_data/>');
        $this->arrayToXmlRecursive($data, $xml);
        return $xml->asXML();
    }
    
    private function arrayToXmlRecursive(array $data, \SimpleXMLElement $xml): void
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $subnode = $xml->addChild($key);
                $this->arrayToXmlRecursive($value, $subnode);
            } else {
                $xml->addChild($key, htmlspecialchars((string)$value));
            }
        }
    }
}

// Interfaces for dependency injection
interface CacheInterface
{
    public function get(string $key): mixed;
    public function set(string $key, mixed $value, int $ttl = 0): bool;
    public function delete(string $key): bool;
}

interface LoggerInterface
{
    public function info(string $message, array $context = []): void;
    public function error(string $message, array $context = []): void;
}

// Usage
try {
    // Basic usage
    $authorsPage = new AuthorsPageController();
    $authorsPage->render();
    
    // Or with enhanced features
    // $enhancedPage = new EnhancedAuthorsPageController($cache, $logger);
    // $enhancedPage->renderWithCache();
    
    // Export team data if requested
    if (isset($_GET['export']) && in_array($_GET['export'], ['json', 'xml'])) {
        $format = $_GET['export'];
        $filename = "team_data_{$format}_" . date('Y-m-d') . ".{$format}";
        
        header("Content-Type: application/{$format}");
        header("Content-Disposition: attachment; filename=\"{$filename}\"");
        
        echo $authorsPage->exportTeamData($format);
        exit;
    }
    
} catch (\Throwable $e) {
    error_log("Authors Page Error: " . $e->getMessage());
    
    // Fallback content
    echo '<div class="error">Unable to load authors page. Please try again later.</div>';
}

?>
