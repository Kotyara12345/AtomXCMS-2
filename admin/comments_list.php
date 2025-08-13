<?php

declare(strict_types=1);

/**
 * CMS AtomX - Advanced Comments Management System
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

namespace AtomX\Admin\Comments;

use Exception;
use InvalidArgumentException;
use RuntimeException;
use AtomX\Core\Http\{Request, Response, RedirectResponse};
use AtomX\Core\Security\{InputSanitizer, Authorization};
use AtomX\Core\Database\DatabaseManager;
use AtomX\Core\Template\TemplateEngine;
use AtomX\Core\Cache\CacheManager;
use AtomX\Core\Pagination\Paginator;
use AtomX\Core\Validation\Validator;

// Подключение основных файлов системы
require_once dirname(__DIR__) . '/sys/boot.php';
require_once ROOT . '/admin/inc/adm_boot.php';

/**
 * Основной контроллер управления комментариями
 */
class CommentsController
{
    private const ALLOWED_ACTIONS = ['index', 'edit', 'delete', 'moderate'];
    private const ALLOWED_STATUSES = ['confirmed', 'rejected', 'pending'];
    private const ITEMS_PER_PAGE = 20;
    private const MAX_MESSAGE_LENGTH = 5000;

    public function __construct(
        private readonly Request $request,
        private readonly Response $response,
        private readonly TemplateEngine $template,
        private readonly CommentsService $commentsService,
        private readonly Authorization $auth,
        private readonly InputSanitizer $sanitizer,
        private readonly Validator $validator,
        private readonly CacheManager $cache
    ) {
        $this->validateAccess();
    }

    /**
     * Главный метод обработки запроса
     */
    public function handle(): Response
    {
        try {
            $module = $this->getCurrentModule();
            $action = $this->getAction();

            return match ($action) {
                'index' => $this->indexAction($module),
                'edit' => $this->editAction($module),
                'delete' => $this->deleteAction($module),
                'moderate' => $this->moderateAction($module),
                default => $this->indexAction($module)
            };

        } catch (Exception $e) {
            error_log('Comments Controller Error: ' . $e->getMessage());
            return $this->errorResponse($e->getMessage());
        }
    }

    /**
     * Отображение списка комментариев
     */
    private function indexAction(string $module): Response
    {
        $filters = $this->buildFilters($module);
        $sorting = $this->buildSorting();
        $page = $this->sanitizer->int($this->request->get('page', 1));

        $result = $this->commentsService->getCommentsList(
            module: $module,
            filters: $filters,
            sorting: $sorting,
            page: $page,
            limit: self::ITEMS_PER_PAGE
        );

        $pagination = new Paginator(
            totalItems: $result->total,
            itemsPerPage: self::ITEMS_PER_PAGE,
            currentPage: $page,
            baseUrl: $this->buildPaginationUrl($module, $filters, $sorting)
        );

        $data = [
            'comments' => $result->comments,
            'module' => $module,
            'filters' => $filters,
            'sorting' => $sorting,
            'pagination' => $pagination,
            'availableModules' => $this->commentsService->getAvailableModules(),
            'pageTitle' => $this->buildPageTitle($module, $filters),
            'stats' => $this->commentsService->getCommentStats($module)
        ];

        return $this->template->render('admin/comments/index', $data);
    }

    /**
     * Редактирование комментария
     */
    private function editAction(string $module): Response
    {
        $commentId = $this->sanitizer->int($this->request->get('id', 0));
        
        if ($commentId <= 0) {
            return $this->redirectWithError($module, __('Invalid comment ID'));
        }

        if ($this->request->isPost()) {
            return $this->processEditComment($module, $commentId);
        }

        $comment = $this->commentsService->getCommentById($commentId);
        
        if (!$comment || $comment->module !== $module) {
            return $this->redirectWithError($module, __('Comment not found'));
        }

        $data = [
            'comment' => $comment,
            'module' => $module,
            'pageTitle' => __('Edit Comment'),
            'availableStatuses' => self::ALLOWED_STATUSES
        ];

        return $this->template->render('admin/comments/edit', $data);
    }

    /**
     * Удаление комментария
     */
    private function deleteAction(string $module): Response
    {
        $commentId = $this->sanitizer->int($this->request->get('id', 0));
        
        if ($commentId <= 0) {
            return $this->redirectWithError($module, __('Invalid comment ID'));
        }

        try {
            $result = $this->commentsService->deleteComment($commentId, $module);
            
            if ($result->isSuccess()) {
                $this->invalidateCache($module, $commentId);
                return $this->redirectWithMessage($module, $result->getMessage());
            } else {
                return $this->redirectWithError($module, $result->getMessage());
            }
            
        } catch (Exception $e) {
            return $this->redirectWithError($module, __('Error deleting comment'));
        }
    }

    /**
     * Модерация комментария
     */
    private function moderateAction(string $module): Response
    {
        $commentId = $this->sanitizer->int($this->request->get('id', 0));
        $status = $this->sanitizer->string($this->request->get('status', ''));
        
        if ($commentId <= 0) {
            return $this->redirectWithError($module, __('Invalid comment ID'));
        }

        if (!in_array($status, self::ALLOWED_STATUSES, true)) {
            return $this->redirectWithError($module, __('Invalid status'));
        }

        try {
            $result = $this->commentsService->moderateComment($commentId, $status, $module);
            
            if ($result->isSuccess()) {
                $this->invalidateCache($module, $commentId);
                return $this->redirectWithMessage($module, $result->getMessage());
            } else {
                return $this->redirectWithError($module, $result->getMessage());
            }
            
        } catch (Exception $e) {
            return $this->redirectWithError($module, __('Error moderating comment'));
        }
    }

    /**
     * Обработка редактирования комментария
     */
    private function processEditComment(string $module, int $commentId): Response
    {
        $message = $this->sanitizer->string($this->request->post('message', ''));
        $status = $this->sanitizer->string($this->request->post('status', 'pending'));
        
        $errors = $this->validateCommentData($message, $status);
        
        if (!empty($errors)) {
            return $this->redirectWithError($module, implode('<br>', $errors));
        }

        try {
            $updateData = new CommentUpdateData(
                message: $message,
                status: $status
            );

            $result = $this->commentsService->updateComment($commentId, $updateData, $module);
            
            if ($result->isSuccess()) {
                $this->invalidateCache($module, $commentId);
                return $this->redirectWithMessage($module, __('Comment updated successfully'));
            } else {
                return $this->redirectWithError($module, $result->getMessage());
            }
            
        } catch (Exception $e) {
            return $this->redirectWithError($module, __('Error updating comment'));
        }
    }

    /**
     * Валидация данных комментария
     */
    private function validateCommentData(string $message, string $status): array
    {
        $errors = [];

        if (empty($message)) {
            $errors[] = __('Message is required');
        } elseif (mb_strlen($message) > self::MAX_MESSAGE_LENGTH) {
            $errors[] = __('Message is too long');
        }

        if (!in_array($status, self::ALLOWED_STATUSES, true)) {
            $errors[] = __('Invalid status');
        }

        return $errors;
    }

    /**
     * Построение фильтров
     */
    private function buildFilters(string $module): CommentFilters
    {
        return new CommentFilters(
            module: $module,
            status: $this->sanitizer->string($this->request->get('status', '')),
            authorId: $this->sanitizer->int($this->request->get('author_id', 0)),
            materialId: $this->sanitizer->int($this->request->get('material_id', 0)),
            dateFrom: $this->sanitizer->string($this->request->get('date_from', '')),
            dateTo: $this->sanitizer->string($this->request->get('date_to', '')),
            search: $this->sanitizer->string($this->request->get('search', '')),
            premoderation: $this->request->get('premoder') === '1'
        );
    }

    /**
     * Построение параметров сортировки
     */
    private function buildSorting(): CommentSorting
    {
        $order = $this->sanitizer->string($this->request->get('order', 'date'));
        $direction = $this->request->get('asc') === '1' ? 'ASC' : 'DESC';

        return new CommentSorting($order, $direction);
    }

    /**
     * Получение текущего модуля
     */
    private function getCurrentModule(): string
    {
        $module = $this->sanitizer->string($this->request->get('m', ''));
        
        if (empty($module)) {
            throw new InvalidArgumentException(__('Module is required'));
        }

        $availableModules = $this->commentsService->getAvailableModules();
        
        if (!in_array($module, $availableModules, true)) {
            throw new InvalidArgumentException(__('Invalid module'));
        }

        return $module;
    }

    /**
     * Получение действия
     */
    private function getAction(): string
    {
        $action = $this->sanitizer->string($this->request->get('ac', 'index'));
        
        return in_array($action, self::ALLOWED_ACTIONS, true) ? $action : 'index';
    }

    /**
     * Проверка доступа
     */
    private function validateAccess(): void
    {
        if (!$this->auth->hasPermission('admin.comments.manage')) {
            throw new RuntimeException(__('Access denied'));
        }
    }

    /**
     * Построение заголовка страницы
     */
    private function buildPageTitle(string $module, CommentFilters $filters): string
    {
        $title = __('Comments Management') . ' - ' . __(ucfirst($module));
        
        if ($filters->premoderation) {
            $title .= ' (' . __('Premoderation') . ')';
        }
        
        return $title;
    }

    /**
     * Построение URL для пагинации
     */
    private function buildPaginationUrl(string $module, CommentFilters $filters, CommentSorting $sorting): string
    {
        $params = ['m' => $module];
        
        if (!empty($filters->status)) {
            $params['status'] = $filters->status;
        }
        
        if ($filters->premoderation) {
            $params['premoder'] = '1';
        }
        
        if ($sorting->field !== 'date') {
            $params['order'] = $sorting->field;
        }
        
        if ($sorting->direction === 'ASC') {
            $params['asc'] = '1';
        }

        return '/admin/comments_list.php?' . http_build_query($params);
    }

    /**
     * Инвалидация кеша
     */
    private function invalidateCache(string $module, int $commentId): void
    {
        $this->cache->invalidateTags([
            "comments_module_{$module}",
            "comment_{$commentId}",
            "comments_list"
        ]);
    }

    /**
     * Редирект с сообщением об успехе
     */
    private function redirectWithMessage(string $module, string $message): RedirectResponse
    {
        session_start();
        $_SESSION['success_message'] = $message;
        return new RedirectResponse("/admin/comments_list.php?m={$module}");
    }

    /**
     * Редирект с сообщением об ошибке
     */
    private function redirectWithError(string $module, string $error): RedirectResponse
    {
        session_start();
        $_SESSION['error_message'] = $error;
        return new RedirectResponse("/admin/comments_list.php?m={$module}");
    }

    /**
     * Ответ с ошибкой
     */
    private function errorResponse(string $message): Response
    {
        return $this->template->render('admin/error', [
            'message' => $message,
            'pageTitle' => __('Error')
        ]);
    }
}

/**
 * Сервис для работы с комментариями
 */
class CommentsService
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly CacheManager $cache,
        private readonly Authorization $auth
    ) {}

    /**
     * Получение списка комментариев
     */
    public function getCommentsList(
        string $module,
        CommentFilters $filters,
        CommentSorting $sorting,
        int $page,
        int $limit
    ): CommentsListResult {
        $cacheKey = $this->buildCacheKey('comments_list', $module, $filters, $sorting, $page, $limit);
        
        return $this->cache->remember($cacheKey, 300, function() use ($module, $filters, $sorting, $page, $limit) {
            $whereConditions = $this->buildWhereConditions($filters);
            $params = $this->buildQueryParams($filters);
            
            // Подсчёт общего количества
            $countQuery = "
                SELECT COUNT(DISTINCT c.id) as total
                FROM comments c
                LEFT JOIN users u ON c.user_id = u.id
                LEFT JOIN {$module} m ON c.entity_id = m.id
                WHERE {$whereConditions}
            ";
            
            $total = (int)$this->db->fetchOne($countQuery, $params);
            
            // Получение комментариев
            $offset = ($page - 1) * $limit;
            $orderClause = $this->buildOrderClause($sorting);
            
            $query = "
                SELECT 
                    c.*,
                    u.name as author_name,
                    u.id as author_id,
                    m.title as material_title,
                    m.id as material_id
                FROM comments c
                LEFT JOIN users u ON c.user_id = u.id
                LEFT JOIN {$module} m ON c.entity_id = m.id
                WHERE {$whereConditions}
                ORDER BY {$orderClause}
                LIMIT {$limit} OFFSET {$offset}
            ";
            
            $commentsData = $this->db->fetchAll($query, $params);
            $comments = array_map([$this, 'mapCommentFromArray'], $commentsData);
            
            return new CommentsListResult($comments, $total);
        });
    }

    /**
     * Получение комментария по ID
     */
    public function getCommentById(int $commentId): ?Comment
    {
        $cacheKey = "comment_{$commentId}";
        
        return $this->cache->remember($cacheKey, 1800, function() use ($commentId) {
            $query = "
                SELECT 
                    c.*,
                    u.name as author_name,
                    u.id as author_id,
                    CASE 
                        WHEN c.module = 'news' THEN n.title
                        WHEN c.module = 'blog' THEN b.title
                        ELSE 'Unknown'
                    END as material_title
                FROM comments c
                LEFT JOIN users u ON c.user_id = u.id
                LEFT JOIN news n ON c.module = 'news' AND c.entity_id = n.id
                LEFT JOIN blog b ON c.module = 'blog' AND c.entity_id = b.id
                WHERE c.id = ?
            ";
            
            $data = $this->db->fetchRow($query, [$commentId]);
            
            return $data ? $this->mapCommentFromArray($data) : null;
        });
    }

    /**
     * Обновление комментария
     */
    public function updateComment(int $commentId, CommentUpdateData $updateData, string $module): OperationResult
    {
        try {
            $this->db->beginTransaction();
            
            $comment = $this->getCommentById($commentId);
            if (!$comment || $comment->module !== $module) {
                throw new RuntimeException(__('Comment not found'));
            }

            $updateFields = [
                'message' => $updateData->message,
                'premoder' => $updateData->status,
                'updated_at' => date('Y-m-d H:i:s')
            ];

            $this->db->update('comments', $updateFields, ['id' => $commentId]);
            
            // Очистка кеша
            $this->cache->forget("comment_{$commentId}");
            $this->cache->invalidateTags(["comments_module_{$module}"]);
            
            $this->db->commit();

            return new OperationResult(
                success: true,
                message: __('Comment updated successfully')
            );

        } catch (Exception $e) {
            $this->db->rollback();
            return new OperationResult(
                success: false,
                message: $e->getMessage()
            );
        }
    }

    /**
     * Модерация комментария
     */
    public function moderateComment(int $commentId, string $status, string $module): OperationResult
    {
        try {
            $this->db->beginTransaction();
            
            $comment = $this->getCommentById($commentId);
            if (!$comment || $comment->module !== $module) {
                throw new RuntimeException(__('Comment not found'));
            }

            $this->db->update('comments', 
                ['premoder' => $status, 'updated_at' => date('Y-m-d H:i:s')], 
                ['id' => $commentId]
            );
            
            // Очистка кеша материала
            $this->cache->invalidateTags([
                "comments_module_{$module}",
                "material_{$comment->materialId}",
                "comment_{$commentId}"
            ]);
            
            $this->db->commit();

            $statusMessages = [
                'confirmed' => __('Comment approved'),
                'rejected' => __('Comment rejected'),
                'pending' => __('Comment set to pending')
            ];

            return new OperationResult(
                success: true,
                message: $statusMessages[$status] ?? __('Comment status updated')
            );

        } catch (Exception $e) {
            $this->db->rollback();
            return new OperationResult(
                success: false,
                message: $e->getMessage()
            );
        }
    }

    /**
     * Удаление комментария
     */
    public function deleteComment(int $commentId, string $module): OperationResult
    {
        try {
            $this->db->beginTransaction();
            
            $comment = $this->getCommentById($commentId);
            if (!$comment || $comment->module !== $module) {
                throw new RuntimeException(__('Comment not found'));
            }

            // Удаляем дочерние комментарии
            $this->deleteChildComments($commentId);
            
            // Удаляем сам комментарий
            $this->db->delete('comments', ['id' => $commentId]);
            
            // Очистка кеша
            $this->cache->invalidateTags([
                "comments_module_{$module}",
                "material_{$comment->materialId}",
                "comment_{$commentId}"
            ]);
            
            $this->db->commit();

            return new OperationResult(
                success: true,
                message: __('Comment deleted successfully')
            );

        } catch (Exception $e) {
            $this->db->rollback();
            return new OperationResult(
                success: false,
                message: $e->getMessage()
            );
        }
    }

    /**
     * Получение статистики комментариев
     */
    public function getCommentStats(string $module): CommentStats
    {
        $cacheKey = "comment_stats_{$module}";
        
        return $this->cache->remember($cacheKey, 600, function() use ($module) {
            $query = "
                SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN premoder = 'pending' THEN 1 ELSE 0 END) as pending,
                    SUM(CASE WHEN premoder = 'confirmed' THEN 1 ELSE 0 END) as confirmed,
                    SUM(CASE WHEN premoder = 'rejected' THEN 1 ELSE 0 END) as rejected,
                    SUM(CASE WHEN DATE(date) = CURDATE() THEN 1 ELSE 0 END) as today
                FROM comments 
                WHERE module = ?
            ";
            
            $data = $this->db->fetchRow($query, [$module]);
            
            return new CommentStats(
                total: (int)$data['total'],
                pending: (int)$data['pending'],
                confirmed: (int)$data['confirmed'],
                rejected: (int)$data['rejected'],
                today: (int)$data['today']
            );
        });
    }

    /**
     * Получение доступных модулей
     */
    public function getAvailableModules(): array
    {
        $register = \Register::getInstance();
        $moduleManager = $register['ModManager'];
        
        return $moduleManager->getAllowedModules('commentsList');
    }

    /**
     * Построение условий WHERE
     */
    private function buildWhereConditions(CommentFilters $filters): string
    {
        $conditions = ["c.module = :module"];

        if (!empty($filters->status)) {
            $conditions[] = "c.premoder = :status";
        }

        if ($filters->premoderation) {
            $conditions[] = "c.premoder = 'pending'";
        }

        if ($filters->authorId > 0) {
            $conditions[] = "c.user_id = :author_id";
        }

        if ($filters->materialId > 0) {
            $conditions[] = "c.entity_id = :material_id";
        }

        if (!empty($filters->search)) {
            $conditions[] = "(c.message LIKE :search OR u.name LIKE :search)";
        }

        if (!empty($filters->dateFrom)) {
            $conditions[] = "DATE(c.date) >= :date_from";
        }

        if (!empty($filters->dateTo)) {
            $conditions[] = "DATE(c.date) <= :date_to";
        }

        return implode(' AND ', $conditions);
    }

    /**
     * Построение параметров запроса
     */
    private function buildQueryParams(CommentFilters $filters): array
    {
        $params = ['module' => $filters->module];

        if (!empty($filters->status)) {
            $params['status'] = $filters->status;
        }

        if ($filters->authorId > 0) {
            $params['author_id'] = $filters->authorId;
        }

        if ($filters->materialId > 0) {
            $params['material_id'] = $filters->materialId;
        }

        if (!empty($filters->search)) {
            $params['search'] = '%' . $filters->search . '%';
        }

        if (!empty($filters->dateFrom)) {
            $params['date_from'] = $filters->dateFrom;
        }

        if (!empty($filters->dateTo)) {
            $params['date_to'] = $filters->dateTo;
        }

        return $params;
    }

    /**
     * Построение ORDER BY клаузулы
     */
    private function buildOrderClause(CommentSorting $sorting): string
    {
        $allowedFields = [
            'date' => 'c.date',
            'author' => 'u.name',
            'premoder' => 'c.premoder',
            'material' => 'm.title'
        ];

        $field = $allowedFields[$sorting->field] ?? 'c.date';
        
        return "{$field} {$sorting->direction}";
    }

    /**
     * Построение ключа кеша
     */
    private function buildCacheKey(string $prefix, string $module, CommentFilters $filters, CommentSorting $sorting, int $page, int $limit): string
    {
        $parts = [
            $prefix,
            $module,
            md5(serialize($filters)),
            md5(serialize($sorting)),
            $page,
            $limit
        ];

        return implode('_', $parts);
    }

    /**
     * Удаление дочерних комментариев
     */
    private function deleteChildComments(int $parentId): void
    {
        $children = $this->db->fetchAll("SELECT id FROM comments WHERE parent_id = ?", [$parentId]);
        
        foreach ($children as $child) {
            $this->deleteChildComments((int)$child['id']);
            $this->db->delete('comments', ['id' => $child['id']]);
        }
    }

    /**
     * Маппинг комментария из массива
     */
    private function mapCommentFromArray(array $data): Comment
    {
        return new Comment(
            id: (int)$data['id'],
            message: $data['message'],
            authorName: $data['author_name'] ?? __('Guest'),
            authorId: $data['author_id'] ? (int)$data['author_id'] : null,
            materialTitle: $data['material_title'] ?? '',
            materialId: (int)$data['material_id'],
            module: $data['module'],
            status: $data['premoder'] ?? 'pending',
            date: $data['date'],
            ip: $data['ip'] ?? '',
            userAgent: $data['user_agent'] ?? ''
        );
    }
}

/**
 * DTO для фильтров комментариев
 */
readonly class CommentFilters
{
    public function __construct(
        public string $module,
        public string $status = '',
        public int $authorId = 0,
        public int $materialId = 0,
        public string $dateFrom = '',
        public string $dateTo = '',
        public string $search = '',
        public bool $premoderation = false
    ) {}
}

/**
 * DTO для сортировки комментариев
 */
readonly class CommentSorting
{
    public function __construct(
        public string $field = 'date',
        public string $direction = 'DESC'
    ) {}
}

/**
 * DTO для данных обновления комментария
 */
readonly class CommentUpdateData
{
    public function __construct(
        public string $message,
        public string $status = 'pending'
    ) {}
}

/**
 * Модель комментария
 */
readonly class Comment
{
    public function __construct(
        public int $id,
        public string $message,
        public string $authorName,
        public ?int $authorId,
        public string $materialTitle,
        public int $materialId,
        public string $module,
        public string $status,
        public string $date,
        public string $ip = '',
        public string $userAgent = ''
    ) {}

    public function getShortMessage(int $length = 120): string
    {
        return mb_strlen($this->message) > $length 
            ? mb_substr($this->message, 0, $length) . '...'
            : $this->message;
    }

    public function getFormattedDate(): string
    {
        return date('d.m.Y H:i', strtotime($this->date));
    }

    public function isGuest(): bool
    {
        return $this->authorId === null;
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isConfirmed(): bool
    {
        return $this->status === 'confirmed';
    }

    public function isRejected(): bool
    {
        return $this->status === 'rejected';
    }
}

/**
 * Результат получения списка комментариев
 */
readonly class CommentsListResult
{
    public function __construct(
        public array $comments,
        public int $total
    ) {}
}

/**
 * Статистика комментариев
 */
readonly class CommentStats
{
    public function __construct(
        public int $total,
        public int $pending,
        public int $confirmed,
        public int $rejected,
        public int $today
    ) {}
}

/**
 * Результат операции
 */
readonly class OperationResult
{
    public function __construct(
        public bool $success,
        public string $message,
        public array $data = []
    ) {}

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getData(): array
    {
        return $this->data;
    }
}

// Инициализация и запуск приложения
try {
    $container = \AtomX\Core\Container\Container::getInstance();
    
    $controller = new CommentsController(
        request: $container->get(Request::class),
        response: $container->get(Response::class),
        template: $container->get(TemplateEngine::class),
        commentsService: $container->get(CommentsService::class),
        auth: $container->get(Authorization::class),
        sanitizer: $container->get(InputSanitizer::class),
        validator: $container->get(Validator::class),
        cache: $container->get(CacheManager::class)
    );

    $response = $controller->handle();
    $response->send();

} catch (Exception $e) {
    error_log('Comments Application Error: ' . $e->getMessage());
    
    // Простая обработка ошибок для обратной совместимости
    session_start();
    $_SESSION['error_message'] = __('System error occurred');
    
    header('Location: /admin/', true, 302);
    exit;
}

/**
 * Шаблон для отображения списка комментариев (legacy совместимость)
 */
function renderCommentsListLegacy(array $comments, string $module, array $pagination): string
{
    ob_start();
    ?>
    <div class="list">
        <div class="title"><?= __('Comments Management') ?></div>
        
        <!-- Фильтры и сортировка -->
        <div class="add-cat-butt">
            <select onChange="window.location.href='/admin/comments_list.php?m=<?= $module ?>&order='+this.value;">
                <option><?= __('Ordering') ?></option>
                <option value="author"><?= __('Author') ?></option>
                <option value="date"><?= __('Date') ?> (↓)</option>
                <option value="date&asc=1"><?= __('Date') ?> (↑)</option>
                <option value="premoder"><?= __('Status') ?> (↓)</option>
                <option value="premoder&asc=1"><?= __('Status') ?> (↑)</option>
            </select>
            
            <a href="?m=<?= $module ?>&premoder=1" class="filter-btn <?= isset($_GET['premoder']) ? 'active' : '' ?>">
                <?= __('Premoderation') ?>
            </a>
        </div>

        <div class="level1">
            <div class="head">
                <div class="title settings"><?= __('Author / Material') ?></div>
                <div class="title-r"><?= __('Message') ?></div>
                <div class="clear"></div>
            </div>
            
            <div class="items">
                <?php if (empty($comments)): ?>
                    <div class="setting-item">
                        <div class="left">
                            <b><?= __('Comments not found') ?></b>
                        </div>
                        <div class="clear"></div>
                    </div>
                <?php else: ?>
                    <?php foreach ($comments as $comment): ?>
                        <div class="setting-item" data-comment-id="<?= $comment->id ?>">
                            <div class="left">
                                <!-- Ссылка на материал -->
                                <a style="font-weight:bold; margin-bottom:5px;" 
                                   href="/admin/materials_list.php?m=<?= $module ?>&ac=edit&id=<?= $comment->materialId ?>">
                                    <?= htmlspecialchars($comment->materialTitle) ?>
                                </a><br>
                                
                                <!-- Автор -->
                                <?= __('Author') ?>: 
                                <?php if (!$comment->isGuest()): ?>
                                    <a style="font-weight:bold; margin-bottom:5px;" 
                                       href="/admin/users_list.php?ac=ank&id=<?= $comment->authorId ?>">
                                        <?= htmlspecialchars($comment->authorName) ?>
                                    </a>
                                <?php else: ?>
                                    <?= __('Guest') ?>
                                <?php endif; ?>
                                
                                <!-- Статус -->
                                <br><span class="comment-status status-<?= $comment->status ?>">
                                    <?php
                                    echo match($comment->status) {
                                        'confirmed' => __('Approved'),
                                        'rejected' => __('Rejected'),
                                        'pending' => __('Pending'),
                                        default => __('Unknown')
                                    };
                                    ?>
                                </span>
                            </div>
                            
                            <div style="width:60%;" class="right">
                                <!-- Сообщение -->
                                <?= htmlspecialchars($comment->getShortMessage()) ?>
                                <br>
                                <span class="comment"><?= $comment->getFormattedDate() ?></span>
                                
                                <!-- IP адрес для админов -->
                                <?php if (!empty($comment->ip)): ?>
                                    <br><small class="ip-info">IP: <?= htmlspecialchars($comment->ip) ?></small>
                                <?php endif; ?>
                            </div>
                            
                            <div class="unbordered-buttons">
                                <?php if (isset($_GET['premoder'])): ?>
                                    <!-- Кнопки модерации -->
                                    <a href="/admin/comments_list.php?m=<?= $module ?>&ac=moderate&status=rejected&id=<?= $comment->id ?>" 
                                       class="off" title="<?= __('Reject') ?>" 
                                       onclick="return confirm('<?= __('Are you sure?') ?>');"></a>
                                    <a href="/admin/comments_list.php?m=<?= $module ?>&ac=moderate&status=confirmed&id=<?= $comment->id ?>" 
                                       class="on" title="<?= __('Approve') ?>" 
                                       onclick="return confirm('<?= __('Are you sure?') ?>');"></a>
                                <?php else: ?>
                                    <!-- Обычные кнопки управления -->
                                    <a href="/admin/comments_list.php?m=<?= $module ?>&ac=delete&id=<?= $comment->id ?>" 
                                       class="delete" title="<?= __('Delete') ?>" 
                                       onclick="return confirm('<?= __('Are you sure you want to delete this comment?') ?>');"></a>
                                    <a href="/admin/comments_list.php?m=<?= $module ?>&ac=edit&id=<?= $comment->id ?>" 
                                       class="edit" title="<?= __('Edit') ?>"></a>
                                    
                                    <!-- Дополнительные кнопки модерации -->
                                    <?php if ($comment->isPending()): ?>
                                        <a href="/admin/comments_list.php?m=<?= $module ?>&ac=moderate&status=confirmed&id=<?= $comment->id ?>" 
                                           class="approve" title="<?= __('Approve') ?>" 
                                           onclick="return confirm('<?= __('Approve this comment?') ?>');"></a>
                                    <?php elseif ($comment->isConfirmed()): ?>
                                        <a href="/admin/comments_list.php?m=<?= $module ?>&ac=moderate&status=pending&id=<?= $comment->id ?>" 
                                           class="moderate" title="<?= __('Set to pending') ?>"></a>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                            
                            <div class="clear"></div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Пагинация -->
    <?php if (!empty($pagination['html'])): ?>
        <div class="pagination"><?= $pagination['html'] ?></div>
    <?php endif; ?>
    
    <!-- JavaScript для улучшения UX -->
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // AJAX модерация
        document.querySelectorAll('.on, .off, .approve').forEach(function(button) {
            button.addEventListener('click', function(e) {
                e.preventDefault();
                
                const url = this.href;
                const action = this.classList.contains('on') || this.classList.contains('approve') ? 'approve' : 'reject';
                
                if (!confirm('<?= __("Are you sure?") ?>')) {
                    return;
                }
                
                // Отправляем AJAX запрос
                fetch(url, {
                    method: 'GET',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Обновляем статус комментария на странице
                        const commentItem = this.closest('.setting-item');
                        const statusElement = commentItem.querySelector('.comment-status');
                        
                        if (action === 'approve') {
                            statusElement.textContent = '<?= __("Approved") ?>';
                            statusElement.className = 'comment-status status-confirmed';
                        } else {
                            statusElement.textContent = '<?= __("Rejected") ?>';
                            statusElement.className = 'comment-status status-rejected';
                        }
                        
                        // Показываем уведомление
                        showNotification(data.message, 'success');
                    } else {
                        showNotification(data.message, 'error');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showNotification('<?= __("Error occurred") ?>', 'error');
                });
            });
        });
        
        // Функция показа уведомлений
        function showNotification(message, type) {
            const notification = document.createElement('div');
            notification.className = `notification ${type}`;
            notification.textContent = message;
            notification.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                padding: 10px 20px;
                background: ${type === 'success' ? '#4CAF50' : '#f44336'};
                color: white;
                border-radius: 4px;
                z-index: 10000;
                animation: slideIn 0.3s ease;
            `;
            
            document.body.appendChild(notification);
            
            setTimeout(() => {
                notification.style.animation = 'slideOut 0.3s ease';
                setTimeout(() => {
                    document.body.removeChild(notification);
                }, 300);
            }, 3000);
        }
        
        // Массовые операции
        const checkboxes = document.querySelectorAll('.comment-checkbox');
        const massActionSelect = document.querySelector('.mass-action-select');
        const applyMassAction = document.querySelector('.apply-mass-action');
        
        if (massActionSelect && applyMassAction) {
            applyMassAction.addEventListener('click', function() {
                const selectedComments = Array.from(checkboxes)
                    .filter(cb => cb.checked)
                    .map(cb => cb.value);
                    
                const action = massActionSelect.value;
                
                if (selectedComments.length === 0) {
                    alert('<?= __("Please select comments") ?>');
                    return;
                }
                
                if (!action) {
                    alert('<?= __("Please select action") ?>');
                    return;
                }
                
                if (!confirm(`<?= __("Apply action to") ?> ${selectedComments.length} <?= __("comments?") ?>`)) {
                    return;
                }
                
                // Отправляем массовый запрос
                fetch('/admin/comments_list.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify({
                        action: 'mass_action',
                        mass_action: action,
                        comment_ids: selectedComments,
                        module: '<?= $module ?>'
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        location.reload();
                    } else {
                        showNotification(data.message, 'error');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showNotification('<?= __("Error occurred") ?>', 'error');
                });
            });
        }
    });
    
    // CSS анимации
    const style = document.createElement('style');
    style.textContent = `
        @keyframes slideIn {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        
        @keyframes slideOut {
            from { transform: translateX(0); opacity: 1; }
            to { transform: translateX(100%); opacity: 0; }
        }
        
        .comment-status {
            font-size: 11px;
            padding: 2px 6px;
            border-radius: 3px;
            font-weight: bold;
        }
        
        .status-confirmed { background: #4CAF50; color: white; }
        .status-rejected { background: #f44336; color: white; }
        .status-pending { background: #FF9800; color: white; }
        
        .ip-info {
            color: #666;
            font-size: 10px;
        }
        
        .filter-btn {
            padding: 5px 10px;
            margin-left: 10px;
            background: #f0f0f0;
            border-radius: 3px;
            text-decoration: none;
        }
        
        .filter-btn.active {
            background: #007cba;
            color: white;
        }
    `;
    document.head.appendChild(style);
    </script>
    <?php
    
    return ob_get_clean();
}

/**
 * Шаблон для редактирования комментария (legacy совместимость)
 */
function renderCommentEditLegacy(Comment $comment, string $module): string
{
    ob_start();
    ?>
    <form method="POST" action="/admin/comments_list.php?m=<?= $module ?>&ac=edit&id=<?= $comment->id ?>">
        <div class="list">
            <div class="title"><?= __('Edit Comment') ?></div>
            
            <div class="level1">
                <div class="items">
                    <!-- Информация о комментарии -->
                    <div class="setting-item">
                        <div class="left"><?= __('Author') ?>:</div>
                        <div class="right">
                            <?php if (!$comment->isGuest()): ?>
                                <a href="/admin/users_list.php?ac=ank&id=<?= $comment->authorId ?>">
                                    <?= htmlspecialchars($comment->authorName) ?>
                                </a>
                            <?php else: ?>
                                <?= __('Guest') ?>
                            <?php endif; ?>
                        </div>
                        <div class="clear"></div>
                    </div>
                    
                    <div class="setting-item">
                        <div class="left"><?= __('Material') ?>:</div>
                        <div class="right">
                            <a href="/admin/materials_list.php?m=<?= $module ?>&ac=edit&id=<?= $comment->materialId ?>">
                                <?= htmlspecialchars($comment->materialTitle) ?>
                            </a>
                        </div>
                        <div class="clear"></div>
                    </div>
                    
                    <div class="setting-item">
                        <div class="left"><?= __('Date') ?>:</div>
                        <div class="right"><?= $comment->getFormattedDate() ?></div>
                        <div class="clear"></div>
                    </div>
                    
                    <?php if (!empty($comment->ip)): ?>
                    <div class="setting-item">
                        <div class="left"><?= __('IP Address') ?>:</div>
                        <div class="right"><?= htmlspecialchars($comment->ip) ?></div>
                        <div class="clear"></div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Status -->
                    <div class="setting-item">
                        <div class="left"><?= __('Status') ?>:</div>
                        <div class="right">
                            <select name="status">
                                <option value="pending" <?= $comment->status === 'pending' ? 'selected' : '' ?>>
                                    <?= __('Pending') ?>
                                </option>
                                <option value="confirmed" <?= $comment->status === 'confirmed' ? 'selected' : '' ?>>
                                    <?= __('Approved') ?>
                                </option>
                                <option value="rejected" <?= $comment->status === 'rejected' ? 'selected' : '' ?>>
                                    <?= __('Rejected') ?>
                                </option>
                            </select>
                        </div>
                        <div class="clear"></div>
                    </div>
                    
                    <!-- Message -->
                    <div class="setting-item">
                        <div class="left"><?= __('Message') ?>:</div>
                        <div class="right">
                            <textarea name="message" style="width: 100%; height: 200px; resize: vertical;"><?= htmlspecialchars($comment->message) ?></textarea>
                        </div>
                        <div class="clear"></div>
                    </div>
                    
                    <!-- Buttons -->
                    <div class="setting-item">
                        <div class="left"></div>
                        <div class="right">
                            <input class="save-button" type="submit" name="send" value="<?= __('Save') ?>" />
                            <a href="/admin/comments_list.php?m=<?= $module ?>" class="cancel-button">
                                <?= __('Cancel') ?>
                            </a>
                        </div>
                        <div class="clear"></div>
                    </div>
                </div>
            </div>
        </div>
    </form>
    
    <style>
        .cancel-button {
            margin-left: 10px;
            padding: 8px 16px;
            background: #f0f0f0;
            border: 1px solid #ccc;
            border-radius: 4px;
            text-decoration: none;
            display: inline-block;
        }
        
        .cancel-button:hover {
            background: #e0e0e0;
        }
    </style>
    <?php
    
    return ob_get_clean();
}
