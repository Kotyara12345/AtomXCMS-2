<?php

declare(strict_types=1);

/**
 * CMS AtomX - Advanced Cache Management System
 * 
 * @author    Andrey Brykin (Drunya)
 * @email     drunyacoder@gmail.com
 * @site      http://atomx.net
 * @version   2.0
 * @project   CMS AtomX
 * @package   Admin Panel
 * @copyright ©Andrey Brykin 2010-2024
 * @license   Proprietary
 */

namespace AtomX\Admin\Cache;

use Exception;
use RuntimeException;
use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use FilesystemIterator;

// Подключение основных файлов системы
require_once dirname(__DIR__) . '/sys/boot.php';
require_once ROOT . '/admin/inc/adm_boot.php';

/**
 * Контроллер управления кешем
 */
class CacheController
{
    private const ALLOWED_CACHE_TYPES = [
        'all', 'templates', 'snippets', 'data', 'images', 'assets'
    ];

    public function __construct(
        private readonly CacheManager $cacheManager,
        private readonly SecurityManager $security,
        private readonly AuditLogger $auditLogger
    ) {
        $this->validateAccess();
    }

    /**
     * Главный метод обработки запроса
     */
    public function handle(): CacheCleanResult
    {
        try {
            $cacheType = $this->getCacheType();
            $force = $this->isForceClean();
            
            $this->auditLogger->logCacheCleanStart($cacheType, $force);
            
            $result = $this->cacheManager->cleanCache($cacheType, $force);
            
            $this->auditLogger->logCacheCleanResult($result);
            $this->setSessionMessage($result);
            
            return $result;

        } catch (Exception $e) {
            $errorMessage = 'Cache cleaning error: ' . $e->getMessage();
            error_log($errorMessage);
            
            $this->auditLogger->logCacheCleanError($e);
            
            return new CacheCleanResult(
                success: false,
                message: __('Error occurred while cleaning cache'),
                details: [$e->getMessage()],
                cleanedTypes: [],
                executionTime: 0.0,
                cleanedSize: 0
            );
        }
    }

    /**
     * Проверка доступа
     */
    private function validateAccess(): void
    {
        if (!$this->security->hasPermission('admin.cache.manage')) {
            throw new RuntimeException(__('Access denied'));
        }
    }

    /**
     * Получение типа кеша для очистки
     */
    private function getCacheType(): string
    {
        $type = $_GET['type'] ?? 'all';
        
        if (!in_array($type, self::ALLOWED_CACHE_TYPES, true)) {
            return 'all';
        }
        
        return $type;
    }

    /**
     * Проверка принудительной очистки
     */
    private function isForceClean(): bool
    {
        return isset($_GET['force']) && $_GET['force'] === '1';
    }

    /**
     * Установка сообщения в сессию
     */
    private function setSessionMessage(CacheCleanResult $result): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        if ($result->success) {
            $_SESSION['success_message'] = $result->message;
            if (!empty($result->details)) {
                $_SESSION['cache_details'] = $result->details;
            }
        } else {
            $_SESSION['error_message'] = $result->message;
            if (!empty($result->details)) {
                $_SESSION['cache_errors'] = $result->details;
            }
        }
    }
}

/**
 * Основной менеджер кеша
 */
class CacheManager
{
    private const MAX_EXECUTION_TIME = 300; // 5 минут
    private const CHUNK_SIZE = 100; // Файлов за раз

    public function __construct(
        private readonly array $cacheConfig,
        private readonly FileSystemManager $fileManager
    ) {}

    /**
     * Очистка кеша
     */
    public function cleanCache(string $type = 'all', bool $force = false): CacheCleanResult
    {
        $startTime = microtime(true);
        set_time_limit(self::MAX_EXECUTION_TIME);
        
        $cleanedTypes = [];
        $totalSize = 0;
        $errors = [];

        try {
            $cleaners = $this->getCacheCleaners($type);
            
            foreach ($cleaners as $cleanerType => $cleaner) {
                try {
                    $result = $cleaner->clean($force);
                    $cleanedTypes[] = $cleanerType;
                    $totalSize += $result->cleanedSize;
                    
                    if (!empty($result->errors)) {
                        $errors = array_merge($errors, $result->errors);
                    }
                    
                } catch (Exception $e) {
                    $errors[] = sprintf(__('Error cleaning %s cache: %s'), $cleanerType, $e->getMessage());
                }
            }

            $success = !empty($cleanedTypes);
            $executionTime = microtime(true) - $startTime;

            return new CacheCleanResult(
                success: $success,
                message: $this->buildSuccessMessage($cleanedTypes, $totalSize),
                details: $this->buildDetails($cleanedTypes, $totalSize, $executionTime),
                cleanedTypes: $cleanedTypes,
                executionTime: $executionTime,
                cleanedSize: $totalSize,
                errors: $errors
            );

        } catch (Exception $e) {
            return new CacheCleanResult(
                success: false,
                message: __('Critical error during cache cleaning'),
                details: [$e->getMessage()],
                cleanedTypes: $cleanedTypes,
                executionTime: microtime(true) - $startTime,
                cleanedSize: $totalSize,
                errors: array_merge($errors, [$e->getMessage()])
            );
        }
    }

    /**
     * Получение очистителей кеша
     */
    private function getCacheCleaners(string $type): array
    {
        $cleaners = [
            'templates' => new TemplatesCacheCleaner($this->fileManager),
            'snippets' => new SnippetsCacheCleaner(),
            'data' => new DataCacheCleaner(),
            'images' => new ImagesCacheCleaner($this->fileManager),
            'assets' => new AssetsCacheCleaner($this->fileManager)
        ];

        if ($type === 'all') {
            return $cleaners;
        }

        if (!isset($cleaners[$type])) {
            throw new InvalidArgumentException("Unknown cache type: {$type}");
        }

        return [$type => $cleaners[$type]];
    }

    /**
     * Построение сообщения об успехе
     */
    private function buildSuccessMessage(array $cleanedTypes, int $totalSize): string
    {
        if (empty($cleanedTypes)) {
            return __('No cache was cleaned');
        }

        $typesStr = implode(', ', array_map('__', $cleanedTypes));
        $sizeStr = $this->formatFileSize($totalSize);
        
        return sprintf(__('Cache cleaned successfully: %s (%s freed)'), $typesStr, $sizeStr);
    }

    /**
     * Построение детальной информации
     */
    private function buildDetails(array $cleanedTypes, int $totalSize, float $executionTime): array
    {
        return [
            'cleaned_types' => $cleanedTypes,
            'total_size' => $this->formatFileSize($totalSize),
            'execution_time' => sprintf('%.3f sec', $executionTime),
            'memory_usage' => $this->formatFileSize(memory_get_peak_usage(true))
        ];
    }

    /**
     * Форматирование размера файла
     */
    private function formatFileSize(int $size): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $unit = 0;
        
        while ($size >= 1024 && $unit < count($units) - 1) {
            $size /= 1024;
            $unit++;
        }
        
        return sprintf('%.2f %s', $size, $units[$unit]);
    }
}

/**
 * Очиститель кеша шаблонов
 */
class TemplatesCacheCleaner implements CacheCleanerInterface
{
    public function __construct(
        private readonly FileSystemManager $fileManager
    ) {}

    public function clean(bool $force = false): CleanResult
    {
        $templateCachePath = ROOT . '/sys/cache/templates/';
        
        if (!is_dir($templateCachePath)) {
            return new CleanResult(0, []);
        }

        $result = $this->fileManager->cleanDirectory(
            directory: $templateCachePath,
            recursive: true,
            preserveDirectory: true,
            filePatterns: ['*.php', '*.tpl', '*.cache'],
            maxAge: $force ? 0 : 3600 // 1 час для не принудительной очистки
        );

        return new CleanResult($result->deletedSize, $result->errors);
    }
}

/**
 * Очиститель кеша сниппетов
 */
class SnippetsCacheCleaner implements CacheCleanerInterface
{
    public function clean(bool $force = false): CleanResult
    {
        $errors = [];
        $cleanedSize = 0;

        try {
            // Используем существующий класс если доступен
            if (class_exists('AtmSnippets')) {
                $snippets = new \AtmSnippets();
                $snippets->cleanCache();
            }

            // Дополнительно очищаем директорию сниппетов если есть
            $snippetsCachePath = ROOT . '/sys/cache/snippets/';
            if (is_dir($snippetsCachePath)) {
                $fileManager = new FileSystemManager();
                $result = $fileManager->cleanDirectory($snippetsCachePath, true, true);
                $cleanedSize += $result->deletedSize;
                $errors = array_merge($errors, $result->errors);
            }

        } catch (Exception $e) {
            $errors[] = 'Snippets cache cleaning error: ' . $e->getMessage();
        }

        return new CleanResult($cleanedSize, $errors);
    }
}

/**
 * Очиститель кеша данных
 */
class DataCacheCleaner implements CacheCleanerInterface
{
    public function clean(bool $force = false): CleanResult
    {
        $errors = [];
        $cleanedSize = 0;

        try {
            // Очищаем системный кеш через Register
            $register = \Register::getInstance();
            if (isset($register['Cache'])) {
                $register['Cache']->clean();
            }

            // Очищаем файловый кеш данных
            $dataCachePath = ROOT . '/sys/cache/data/';
            if (is_dir($dataCachePath)) {
                $fileManager = new FileSystemManager();
                $result = $fileManager->cleanDirectory($dataCachePath, true, true);
                $cleanedSize += $result->deletedSize;
                $errors = array_merge($errors, $result->errors);
            }

        } catch (Exception $e) {
            $errors[] = 'Data cache cleaning error: ' . $e->getMessage();
        }

        return new CleanResult($cleanedSize, $errors);
    }
}

/**
 * Очиститель кеша изображений
 */
class ImagesCacheCleaner implements CacheCleanerInterface
{
    public function __construct(
        private readonly FileSystemManager $fileManager
    ) {}

    public function clean(bool $force = false): CleanResult
    {
        $imagesCachePath = ROOT . '/sys/cache/images/';
        
        if (!is_dir($imagesCachePath)) {
            return new CleanResult(0, []);
        }

        $result = $this->fileManager->cleanDirectory(
            directory: $imagesCachePath,
            recursive: true,
            preserveDirectory: true,
            filePatterns: ['*.jpg', '*.jpeg', '*.png', '*.gif', '*.webp'],
            maxAge: $force ? 0 : 86400 // 24 часа для не принудительной очистки
        );

        return new CleanResult($result->deletedSize, $result->errors);
    }
}

/**
 * Очиститель кеша ресурсов
 */
class AssetsCacheCleaner implements CacheCleanerInterface
{
    public function __construct(
        private readonly FileSystemManager $fileManager
    ) {}

    public function clean(bool $force = false): CleanResult
    {
        $assetsCachePath = ROOT . '/sys/cache/assets/';
        
        if (!is_dir($assetsCachePath)) {
            return new CleanResult(0, []);
        }

        $result = $this->fileManager->cleanDirectory(
            directory: $assetsCachePath,
            recursive: true,
            preserveDirectory: true,
            filePatterns: ['*.css', '*.js', '*.min.css', '*.min.js'],
            maxAge: $force ? 0 : 7200 // 2 часа для не принудительной очистки
        );

        return new CleanResult($result->deletedSize, $result->errors);
    }
}

/**
 * Менеджер файловой системы
 */
class FileSystemManager
{
    private const MAX_FILES_PER_BATCH = 100;
    
    /**
     * Очистка директории
     */
    public function cleanDirectory(
        string $directory,
        bool $recursive = false,
        bool $preserveDirectory = false,
        array $filePatterns = ['*'],
        int $maxAge = 0
    ): DirectoryCleanResult {
        $errors = [];
        $deletedSize = 0;
        $deletedCount = 0;

        if (!is_dir($directory)) {
            return new DirectoryCleanResult($deletedSize, $deletedCount, $errors);
        }

        try {
            $iterator = $recursive 
                ? new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::CHILD_FIRST
                  )
                : new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS);

            $files = [];
            foreach ($iterator as $fileinfo) {
                if ($fileinfo->isFile()) {
                    // Проверяем возраст файла
                    if ($maxAge > 0 && (time() - $fileinfo->getMTime()) < $maxAge) {
                        continue;
                    }

                    // Проверяем паттерн
                    if (!$this->matchesPattern($fileinfo->getFilename(), $filePatterns)) {
                        continue;
                    }

                    $files[] = $fileinfo;
                    
                    // Обрабатываем файлы порциями
                    if (count($files) >= self::MAX_FILES_PER_BATCH) {
                        $result = $this->deleteFiles($files);
                        $deletedSize += $result->deletedSize;
                        $deletedCount += $result->deletedCount;
                        $errors = array_merge($errors, $result->errors);
                        $files = [];
                    }
                }
            }

            // Обрабатываем оставшиеся файлы
            if (!empty($files)) {
                $result = $this->deleteFiles($files);
                $deletedSize += $result->deletedSize;
                $deletedCount += $result->deletedCount;
                $errors = array_merge($errors, $result->errors);
            }

            // Удаляем пустые директории если не нужно сохранять структуру
            if ($recursive && !$preserveDirectory) {
                $this->removeEmptyDirectories($directory, $errors);
            }

        } catch (Exception $e) {
            $errors[] = "Directory cleaning error: {$e->getMessage()}";
        }

        return new DirectoryCleanResult($deletedSize, $deletedCount, $errors);
    }

    /**
     * Удаление файлов
     */
    private function deleteFiles(array $files): DirectoryCleanResult
    {
        $errors = [];
        $deletedSize = 0;
        $deletedCount = 0;

        foreach ($files as $fileinfo) {
            try {
                $size = $fileinfo->getSize();
                
                if (unlink($fileinfo->getPathname())) {
                    $deletedSize += $size;
                    $deletedCount++;
                } else {
                    $errors[] = "Failed to delete file: {$fileinfo->getPathname()}";
                }
                
            } catch (Exception $e) {
                $errors[] = "Error deleting file {$fileinfo->getPathname()}: {$e->getMessage()}";
            }
        }

        return new DirectoryCleanResult($deletedSize, $deletedCount, $errors);
    }

    /**
     * Проверка соответствия паттерну
     */
    private function matchesPattern(string $filename, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (fnmatch($pattern, $filename)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Удаление пустых директорий
     */
    private function removeEmptyDirectories(string $directory, array &$errors): void
    {
        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ($iterator as $fileinfo) {
                if ($fileinfo->isDir()) {
                    try {
                        if ($this->isEmptyDirectory($fileinfo->getPathname())) {
                            rmdir($fileinfo->getPathname());
                        }
                    } catch (Exception $e) {
                        $errors[] = "Failed to remove empty directory {$fileinfo->getPathname()}: {$e->getMessage()}";
                    }
                }
            }
        } catch (Exception $e) {
            $errors[] = "Error removing empty directories: {$e->getMessage()}";
        }
    }

    /**
     * Проверка, пуста ли директория
     */
    private function isEmptyDirectory(string $directory): bool
    {
        $iterator = new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS);
        return !$iterator->valid();
    }
}

/**
 * Менеджер безопасности
 */
class SecurityManager
{
    public function hasPermission(string $permission): bool
    {
        // Проверяем базовые права администратора
        if (!isset($_SESSION['admin']) || !$_SESSION['admin']) {
            return false;
        }

        // Можно добавить более детальную проверку прав
        $register = \Register::getInstance();
        if (isset($register['ACL'])) {
            return $register['ACL']->turn($permission);
        }

        return true; // Для обратной совместимости
    }
}

/**
 * Логгер аудита
 */
class AuditLogger
{
    public function logCacheCleanStart(string $cacheType, bool $force): void
    {
        $this->log('cache_clean_start', [
            'type' => $cacheType,
            'force' => $force,
            'user_id' => $_SESSION['user_id'] ?? 'unknown',
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ]);
    }

    public function logCacheCleanResult(CacheCleanResult $result): void
    {
        $this->log('cache_clean_result', [
            'success' => $result->success,
            'cleaned_types' => $result->cleanedTypes,
            'cleaned_size' => $result->cleanedSize,
            'execution_time' => $result->executionTime,
            'error_count' => count($result->errors)
        ]);
    }

    public function logCacheCleanError(Exception $e): void
    {
        $this->log('cache_clean_error', [
            'error' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ]);
    }

    private function log(string $event, array $data): void
    {
        $logEntry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'event' => $event,
            'data' => $data
        ];

        error_log('[CACHE_AUDIT] ' . json_encode($logEntry, JSON_UNESCAPED_UNICODE));
    }
}

/**
 * Интерфейс очистителя кеша
 */
interface CacheCleanerInterface
{
    public function clean(bool $force = false): CleanResult;
}

/**
 * Результат очистки кеша
 */
readonly class CacheCleanResult
{
    public function __construct(
        public bool $success,
        public string $message,
        public array $details,
        public array $cleanedTypes,
        public float $executionTime,
        public int $cleanedSize,
        public array $errors = []
    ) {}
}

/**
 * Результат очистки отдельного типа кеша
 */
readonly class CleanResult
{
    public function __construct(
        public int $cleanedSize,
        public array $errors
    ) {}
}

/**
 * Результат очистки директории
 */
readonly class DirectoryCleanResult
{
    public function __construct(
        public int $deletedSize,
        public int $deletedCount,
        public array $errors
    ) {}
}

/**
 * Функция безопасного редиректа
 */
function safeRedirect(string $url = '/admin'): never
{
    // Валидация URL
    if (!filter_var($url, FILTER_VALIDATE_URL) && !str_starts_with($url, '/')) {
        $url = '/admin';
    }

    // Очистка буферов вывода
    while (ob_get_level()) {
        ob_end_clean();
    }

    // Установка заголовков безопасности
    header('Location: ' . $url, true, 302);
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    exit;
}

// Основная логика выполнения
try {
    // Создаем необходимые объекты
    $fileManager = new FileSystemManager();
    $security = new SecurityManager();
    $auditLogger = new AuditLogger();
    
    $cacheConfig = [
        'templates_path' => ROOT . '/sys/cache/templates/',
        'data_path' => ROOT . '/sys/cache/data/',
        'images_path' => ROOT . '/sys/cache/images/',
        'assets_path' => ROOT . '/sys/cache/assets/',
    ];
    
    $cacheManager = new CacheManager($cacheConfig, $fileManager);
    $controller = new CacheController($cacheManager, $security, $auditLogger);

    // Выполняем очистку кеша
    $result = $controller->handle();

    // Логируем результат для мониторинга
    error_log(sprintf(
        'Cache cleaning completed: success=%s, types=%s, size=%d bytes, time=%.3fs',
        $result->success ? 'true' : 'false',
        implode(',', $result->cleanedTypes),
        $result->cleanedSize,
        $result->executionTime
    ));

    // Перенаправляем на главную страницу админки
    safeRedirect('/admin');

} catch (Exception $e) {
    // Логирование критической ошибки
    error_log('Critical cache cleaning error: ' . $e->getMessage());
    
    // Установка сообщения об ошибке в сессию
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $_SESSION['error_message'] = __('Cache cleaning failed: ') . $e->getMessage();
    
    // Перенаправление с ошибкой
    safeRedirect('/admin?error=cache_clean_failed');
}
