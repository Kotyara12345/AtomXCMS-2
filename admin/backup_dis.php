<?php

declare(strict_types=1);

/**
 * CMS AtomX - Backup Template Files Manager
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

namespace AtomX\Admin\Backup;

use Exception;
use InvalidArgumentException;
use RuntimeException;

// Подключение основных файлов системы
require_once dirname(__DIR__) . '/sys/boot.php';
require_once ROOT . '/admin/inc/adm_boot.php';

/**
 * Класс для управления резервным копированием шаблонов
 */
class TemplateBackupManager
{
    private const ALLOWED_EXTENSIONS = ['.stand'];
    private const MAX_FILES_TO_DELETE = 1000; // Защита от случайного удаления большого количества файлов

    private string $templatePath;
    private array $deletedFiles = [];
    private array $errors = [];

    public function __construct()
    {
        $this->validateEnvironment();
        $this->templatePath = $this->getTemplatePath();
    }

    /**
     * Проверка окружения
     */
    private function validateEnvironment(): void
    {
        if (!defined('ROOT')) {
            throw new RuntimeException('ROOT constant is not defined');
        }

        if (!class_exists('Config')) {
            throw new RuntimeException('Config class is not available');
        }

        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
    }

    /**
     * Получение пути к шаблону
     */
    private function getTemplatePath(): string
    {
        $template = Config::read('template');
        
        if (empty($template)) {
            throw new InvalidArgumentException('Template name is empty');
        }

        $templatePath = ROOT . '/template/' . $template;
        
        if (!is_dir($templatePath)) {
            throw new InvalidArgumentException("Template directory does not exist: {$templatePath}");
        }

        return $templatePath;
    }

    /**
     * Поиск файлов для удаления
     */
    private function findBackupFiles(): array
    {
        $patterns = [
            $this->templatePath . '/css/*.stand',
            $this->templatePath . '/html/*/*.stand'
        ];

        $files = [];
        foreach ($patterns as $pattern) {
            $foundFiles = glob($pattern, GLOB_NOSORT);
            if ($foundFiles !== false) {
                $files = array_merge($files, $foundFiles);
            }
        }

        return array_unique($files);
    }

    /**
     * Безопасное удаление файла
     */
    private function safeUnlink(string $filePath): bool
    {
        try {
            // Проверяем, что файл находится в разрешённой директории
            $realPath = realpath($filePath);
            $allowedPath = realpath($this->templatePath);
            
            if (!$realPath || !$allowedPath || !str_starts_with($realPath, $allowedPath)) {
                $this->errors[] = "File outside allowed directory: {$filePath}";
                return false;
            }

            // Проверяем расширение
            $hasAllowedExtension = false;
            foreach (self::ALLOWED_EXTENSIONS as $extension) {
                if (str_ends_with($filePath, $extension)) {
                    $hasAllowedExtension = true;
                    break;
                }
            }

            if (!$hasAllowedExtension) {
                $this->errors[] = "File has disallowed extension: {$filePath}";
                return false;
            }

            // Проверяем, что это действительно файл
            if (!is_file($realPath)) {
                $this->errors[] = "Not a file: {$filePath}";
                return false;
            }

            // Удаляем файл
            if (unlink($realPath)) {
                $this->deletedFiles[] = $realPath;
                return true;
            } else {
                $this->errors[] = "Failed to delete file: {$filePath}";
                return false;
            }

        } catch (Exception $e) {
            $this->errors[] = "Exception while deleting {$filePath}: " . $e->getMessage();
            return false;
        }
    }

    /**
     * Выполнение резервного копирования (удаления временных файлов)
     */
    public function performBackup(): BackupResult
    {
        $startTime = microtime(true);
        $files = $this->findBackupFiles();

        if (empty($files)) {
            return new BackupResult(
                success: true,
                message: __('No backup files found'),
                deletedCount: 0,
                errors: [],
                executionTime: microtime(true) - $startTime
            );
        }

        if (count($files) > self::MAX_FILES_TO_DELETE) {
            return new BackupResult(
                success: false,
                message: sprintf(__('Too many files to delete: %d (max: %d)'), count($files), self::MAX_FILES_TO_DELETE),
                deletedCount: 0,
                errors: ['File count exceeds safety limit'],
                executionTime: microtime(true) - $startTime
            );
        }

        // Удаляем файлы
        $successCount = 0;
        foreach ($files as $file) {
            if ($this->safeUnlink($file)) {
                $successCount++;
            }
        }

        $isSuccess = $successCount > 0 && empty($this->errors);
        $message = $isSuccess 
            ? sprintf(__('Backup complete. Deleted %d files'), $successCount)
            : sprintf(__('Backup completed with errors. Deleted %d of %d files'), $successCount, count($files));

        return new BackupResult(
            success: $isSuccess,
            message: $message,
            deletedCount: $successCount,
            errors: $this->errors,
            executionTime: microtime(true) - $startTime
        );
    }
}

/**
 * Результат операции резервного копирования
 */
readonly class BackupResult
{
    public function __construct(
        public bool $success,
        public string $message,
        public int $deletedCount,
        public array $errors,
        public float $executionTime
    ) {}
}

/**
 * Безопасная функция перенаправления
 */
function safeRedirect(string $url): never
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
    // Инициализация переменных для шаблона
    $pageTitle = __('Template Backup');
    $pageNav = '';
    $pageNavr = '';

    // Подключение заголовка админки
    include_once ROOT . '/admin/template/header.php';

    // Создание менеджера резервного копирования
    $backupManager = new TemplateBackupManager();
    
    // Выполнение резервного копирования
    $result = $backupManager->performBackup();

    // Логирование результата
    error_log(sprintf(
        'Template backup completed: success=%s, deleted=%d, errors=%d, time=%.3fs',
        $result->success ? 'true' : 'false',
        $result->deletedCount,
        count($result->errors),
        $result->executionTime
    ));

    // Установка сообщения в сессию
    $_SESSION['message'] = $result->message;
    
    if (!empty($result->errors)) {
        $_SESSION['errors'] = $result->errors;
    }

    // Перенаправление
    safeRedirect('/admin');

} catch (Exception $e) {
    // Логирование ошибки
    error_log('Template backup error: ' . $e->getMessage());
    
    // Установка сообщения об ошибке
    $_SESSION['error'] = __('Backup failed: ') . $e->getMessage();
    
    // Перенаправление на страницу ошибки или админку
    safeRedirect('/admin?error=backup_failed');

} finally {
    // Подключение футера (если заголовок был подключён)
    if (isset($pageTitle)) {
        include_once ROOT . '/admin/template/footer.php';
    }
}
