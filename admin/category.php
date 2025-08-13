<?php

declare(strict_types=1);

/**
 * CMS AtomX - Category Management System
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

namespace AtomX\Admin\Category;

use Exception;
use InvalidArgumentException;
use RuntimeException;
use AtomX\Core\Security\InputSanitizer;
use AtomX\Core\Validation\Validator;
use AtomX\Core\Http\{Request, Response, RedirectResponse};
use AtomX\Core\Template\TemplateEngine;
use AtomX\Core\Database\DatabaseManager;
use AtomX\Core\Auth\Authorization;
use AtomX\Core\Cache\CacheManager;

// Подключение основных файлов системы
require_once dirname(__DIR__) . '/sys/boot.php';
require_once ROOT . '/admin/inc/adm_boot.php';

/**
 * Основной контроллер управления категориями
 */
class CategoryController
{
    private const DEFAULT_MODULE = 'news';
    private const ALLOWED_ACTIONS = ['index', 'add', 'edit', 'delete', 'toggle_home'];
    private const MAX_CATEGORIES_PER_MODULE = 1000;
    private const CACHE_TTL = 3600; // 1 час

    public function __construct(
        private readonly Request $request,
        private readonly Response $response,
        private readonly DatabaseManager $db,
        private readonly Authorization $auth,
        private readonly TemplateEngine $template,
        private readonly CacheManager $cache,
        private readonly CategoryService $categoryService,
        private readonly InputSanitizer $sanitizer,
        private readonly Validator $validator
    ) {
        $this->validateAccess();
    }

    /**
     * Главный метод обработки запроса
     */
    public function handle(): Response
    {
        try {
            $action = $this->getAction();
            $module = $this->getCurrentModule();
            
            return match ($action) {
                'index' => $this->indexAction($module),
                'add' => $this->addAction($module),
                'edit' => $this->editAction($module),
                'delete' => $this->deleteAction($module),
                'toggle_home' => $this->toggleHomeAction($module),
                default => $this->indexAction($module)
            };

        } catch (Exception $e) {
            error_log('Category Controller Error: ' . $e->getMessage());
            return $this->errorResponse($e->getMessage());
        }
    }

    /**
     * Отображение списка категорий
     */
    private function indexAction(string $module): Response
    {
        $cacheKey = "categories_tree_{$module}";
        
        $categoriesTree = $this->cache->remember($cacheKey, self::CACHE_TTL, function() use ($module) {
            return $this->categoryService->getCategoriesTree($module);
        });

        $data = [
            'module' => $module,
            'categoriesTree' => $categoriesTree,
            'allowedModules' => $this->categoryService->getAllowedModules(),
            'aclGroups' => $this->auth->getAclGroups(),
            'pageTitle' => __('Categories Management') . ' - ' . __(ucfirst($module)),
        ];

        return $this->template->render('admin/category/index', $data);
    }

    /**
     * Добавление новой категории
     */
    private function addAction(string $module): Response
    {
        if ($this->request->isPost()) {
            return $this->processAddCategory($module);
        }

        // Отображение формы добавления через AJAX или редирект на index
        return new RedirectResponse("/admin/category.php?mod={$module}");
    }

    /**
     * Редактирование категории
     */
    private function editAction(string $module): Response
    {
        if ($this->request->isPost()) {
            return $this->processEditCategory($module);
        }

        return new RedirectResponse("/admin/category.php?mod={$module}");
    }

    /**
     * Удаление категории
     */
    private function deleteAction(string $module): Response
    {
        $categoryId = $this->sanitizer->int($this->request->get('id'));
        
        if ($categoryId <= 0) {
            return $this->redirectWithError($module, __('Invalid category ID'));
        }

        try {
            $result = $this->categoryService->deleteCategory($module, $categoryId);
            
            if ($result->isSuccess()) {
                $this->invalidateCache($module);
                return $this->redirectWithMessage($module, $result->getMessage());
            } else {
                return $this->redirectWithError($module, $result->getMessage());
            }
            
        } catch (Exception $e) {
            return $this->redirectWithError($module, __('Error deleting category'));
        }
    }

    /**
     * Переключение отображения на главной
     */
    private function toggleHomeAction(string $module): Response
    {
        if ($module === 'foto') {
            return $this->redirectWithError($module, __('Not available for this module'));
        }

        $categoryId = $this->sanitizer->int($this->request->get('id'));
        $enable = $this->request->get('enable', 'true') === 'true';

        if ($categoryId <= 0) {
            return $this->redirectWithError($module, __('Invalid category ID'));
        }

        try {
            $this->categoryService->toggleHomeVisibility($module, $categoryId, $enable);
            $this->invalidateCache($module);
            
            $message = $enable ? __('Category enabled on home') : __('Category disabled on home');
            return $this->redirectWithMessage($module, $message);
            
        } catch (Exception $e) {
            return $this->redirectWithError($module, __('Error updating category'));
        }
    }

    /**
     * Обработка добавления категории
     */
    private function processAddCategory(string $module): Response
    {
        $data = $this->validateCategoryData();
        
        if (!empty($data['errors'])) {
            return $this->redirectWithError($module, implode('<br>', $data['errors']));
        }

        try {
            $categoryData = new CategoryData(
                title: $data['title'],
                parentId: $data['parent_id'],
                accessGroups: $data['access_groups']
            );

            $result = $this->categoryService->createCategory($module, $categoryData);
            
            if ($result->isSuccess()) {
                $this->invalidateCache($module);
                return $this->redirectWithMessage($module, __('Category created successfully'));
            } else {
                return $this->redirectWithError($module, $result->getMessage());
            }
            
        } catch (Exception $e) {
            return $this->redirectWithError($module, __('Error creating category'));
        }
    }

    /**
     * Обработка редактирования категории
     */
    private function processEditCategory(string $module): Response
    {
        $categoryId = $this->sanitizer->int($this->request->get('id'));
        
        if ($categoryId <= 0) {
            return $this->redirectWithError($module, __('Invalid category ID'));
        }

        $data = $this->validateCategoryData();
        
        if (!empty($data['errors'])) {
            return $this->redirectWithError($module, implode('<br>', $data['errors']));
        }

        try {
            $categoryData = new CategoryData(
                title: $data['title'],
                parentId: $data['parent_id'],
                accessGroups: $data['access_groups']
            );

            $result = $this->categoryService->updateCategory($module, $categoryId, $categoryData);
            
            if ($result->isSuccess()) {
                $this->invalidateCache($module);
                return $this->redirectWithMessage($module, __('Category updated successfully'));
            } else {
                return $this->redirectWithError($module, $result->getMessage());
            }
            
        } catch (Exception $e) {
            return $this->redirectWithError($module, __('Error updating category'));
        }
    }

    /**
     * Валидация данных категории
     */
    private function validateCategoryData(): array
    {
        $title = $this->sanitizer->string($this->request->post('title', ''));
        $parentId = $this->sanitizer->int($this->request->post('id_sec', 0));
        $accessGroups = array_map('intval', $this->request->post('access', []));

        $errors = [];

        // Валидация заголовка
        if (empty($title)) {
            $errors[] = __('Title is required');
        } elseif (mb_strlen($title) > 100) {
            $errors[] = __('Title is too long (max 100 characters)');
        }

        // Валидация родительской категории
        if ($parentId < 0) {
            $errors[] = __('Invalid parent category ID');
        }

        return [
            'title' => $title,
            'parent_id' => $parentId,
            'access_groups' => $accessGroups,
            'errors' => $errors
        ];
    }

    /**
     * Получение текущего модуля
     */
    private function getCurrentModule(): string
    {
        $module = $this->sanitizer->string($this->request->get('mod', ''));
        
        if (empty($module)) {
            return self::DEFAULT_MODULE;
        }

        $allowedModules = $this->categoryService->getAllowedModules();
        
        if (!in_array($module, $allowedModules, true)) {
            return self::DEFAULT_MODULE;
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
        if (!$this->auth->hasPermission('admin.categories.manage')) {
            throw new RuntimeException(__('Access denied'));
        }
    }

    /**
     * Инвалидация кеша
     */
    private function invalidateCache(string $module): void
    {
        $this->cache->forget("categories_tree_{$module}");
        $this->cache->forget("categories_flat_{$module}");
    }

    /**
     * Редирект с сообщением об успехе
     */
    private function redirectWithMessage(string $module, string $message): RedirectResponse
    {
        session_start();
        $_SESSION['success_message'] = $message;
        return new RedirectResponse("/admin/category.php?mod={$module}");
    }

    /**
     * Редирект с сообщением об ошибке
     */
    private function redirectWithError(string $module, string $error): RedirectResponse
    {
        session_start();
        $_SESSION['error_message'] = $error;
        return new RedirectResponse("/admin/category.php?mod={$module}");
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
 * Сервис для работы с категориями
 */
class CategoryService
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Authorization $auth,
        private readonly CacheManager $cache
    ) {}

    /**
     * Получение разрешённых модулей
     */
    public function getAllowedModules(): array
    {
        $modulesManager = new \ModulesManager();
        return $modulesManager->getAllowedModules('categories');
    }

    /**
     * Получение дерева категорий
     */
    public function getCategoriesTree(string $module): array
    {
        $categories = $this->getFlatCategories($module);
        return $this->buildTree($categories);
    }

    /**
     * Получение плоского списка категорий
     */
    public function getFlatCategories(string $module): array
    {
        $tableName = $module . '_categories';
        
        $query = "
            SELECT 
                a.*,
                COUNT(b.id) as materials_count
            FROM {$tableName} a
            LEFT JOIN {$module} b ON a.id = b.category_id
            GROUP BY a.id
            ORDER BY a.parent_id, a.title
        ";

        return $this->db->fetchAll($query);
    }

    /**
     * Создание категории
     */
    public function createCategory(string $module, CategoryData $data): OperationResult
    {
        try {
            $this->db->beginTransaction();

            $tableName = $module . '_categories';
            
            // Проверяем лимит категорий
            $count = $this->db->fetchOne("SELECT COUNT(*) FROM {$tableName}");
            if ($count >= CategoryController::MAX_CATEGORIES_PER_MODULE) {
                throw new RuntimeException(__('Maximum categories limit reached'));
            }

            // Получаем путь родительской категории
            $path = $this->buildCategoryPath($module, $data->parentId);

            $insertData = [
                'title' => $data->title,
                'parent_id' => $data->parentId,
                'path' => $path,
                'no_access' => $this->buildAccessString($data->accessGroups),
                'created_at' => date('Y-m-d H:i:s'),
                'view_on_home' => 0
            ];

            $categoryId = $this->db->insert($tableName, $insertData);
            
            $this->db->commit();

            return new OperationResult(
                success: true,
                message: __('Category created successfully'),
                data: ['id' => $categoryId]
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
     * Обновление категории
     */
    public function updateCategory(string $module, int $categoryId, CategoryData $data): OperationResult
    {
        try {
            $this->db->beginTransaction();

            $tableName = $module . '_categories';
            
            // Проверяем существование категории
            $category = $this->db->fetchRow(
                "SELECT * FROM {$tableName} WHERE id = ?", 
                [$categoryId]
            );

            if (!$category) {
                throw new RuntimeException(__('Category not found'));
            }

            // Проверяем циклические ссылки
            if ($data->parentId > 0 && $this->wouldCreateCycle($module, $categoryId, $data->parentId)) {
                throw new RuntimeException(__('Cannot set parent category - would create cycle'));
            }

            // Получаем новый путь
            $path = $this->buildCategoryPath($module, $data->parentId);

            $updateData = [
                'title' => $data->title,
                'parent_id' => $data->parentId,
                'path' => $path,
                'no_access' => $this->buildAccessString($data->accessGroups),
                'updated_at' => date('Y-m-d H:i:s')
            ];

            $this->db->update($tableName, $updateData, ['id' => $categoryId]);

            // Обновляем пути дочерних категорий если изменился родитель
            if ($category['parent_id'] != $data->parentId) {
                $this->updateChildrenPaths($module, $categoryId);
            }
            
            $this->db->commit();

            return new OperationResult(
                success: true,
                message: __('Category updated successfully')
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
     * Удаление категории
     */
    public function deleteCategory(string $module, int $categoryId): OperationResult
    {
        try {
            $this->db->beginTransaction();

            $tableName = $module . '_categories';
            
            // Проверяем, что это не последняя категория
            $totalCategories = $this->db->fetchOne("SELECT COUNT(*) FROM {$tableName}");
            if ($totalCategories <= 1) {
                throw new RuntimeException(__('Cannot delete the last category'));
            }

            // Получаем категорию
            $category = $this->db->fetchRow(
                "SELECT * FROM {$tableName} WHERE id = ?", 
                [$categoryId]
            );

            if (!$category) {
                throw new RuntimeException(__('Category not found'));
            }

            // Удаляем дочерние категории рекурсивно
            $this->deleteChildCategories($module, $categoryId);
            
            // Удаляем материалы в категории
            $this->deleteCategoryMaterials($module, $categoryId);
            
            // Удаляем саму категорию
            $this->db->delete($tableName, ['id' => $categoryId]);
            
            $this->db->commit();

            return new OperationResult(
                success: true,
                message: __('Category deleted successfully')
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
     * Переключение видимости на главной
     */
    public function toggleHomeVisibility(string $module, int $categoryId, bool $visible): void
    {
        $tableName = $module . '_categories';
        
        $this->db->beginTransaction();
        
        try {
            // Обновляем категорию
            $this->db->update(
                $tableName, 
                ['view_on_home' => $visible ? 1 : 0], 
                ['id' => $categoryId]
            );

            // Обновляем материалы в категории
            $this->db->update(
                $module, 
                ['view_on_home' => $visible ? 1 : 0], 
                ['category_id' => $categoryId]
            );

            // Рекурсивно обновляем дочерние категории
            $this->updateChildrenHomeVisibility($module, $categoryId, $visible);
            
            $this->db->commit();
            
        } catch (Exception $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    /**
     * Построение дерева категорий из плоского массива
     */
    private function buildTree(array $categories, int $parentId = 0): array
    {
        $tree = [];
        
        foreach ($categories as $category) {
            if ((int)$category['parent_id'] === $parentId) {
                $category['children'] = $this->buildTree($categories, (int)$category['id']);
                $tree[] = $category;
            }
        }
        
        return $tree;
    }

    /**
     * Построение пути категории
     */
    private function buildCategoryPath(string $module, int $parentId): string
    {
        if ($parentId <= 0) {
            return '';
        }

        $tableName = $module . '_categories';
        $parent = $this->db->fetchRow(
            "SELECT path FROM {$tableName} WHERE id = ?", 
            [$parentId]
        );

        if (!$parent) {
            return '';
        }

        return ($parent['path'] ?? '') . $parentId . '.';
    }

    /**
     * Построение строки доступа
     */
    private function buildAccessString(array $accessGroups): string
    {
        $allGroups = $this->auth->getAclGroups();
        $noAccess = [];

        foreach ($allGroups as $groupId => $group) {
            if (!in_array($groupId, $accessGroups, true)) {
                $noAccess[] = $groupId;
            }
        }

        return implode(',', $noAccess);
    }

    /**
     * Проверка на циклические ссылки
     */
    private function wouldCreateCycle(string $module, int $categoryId, int $parentId): bool
    {
        if ($categoryId === $parentId) {
            return true;
        }

        $tableName = $module . '_categories';
        $current = $parentId;

        while ($current > 0) {
            if ($current === $categoryId) {
                return true;
            }

            $parent = $this->db->fetchRow(
                "SELECT parent_id FROM {$tableName} WHERE id = ?", 
                [$current]
            );

            if (!$parent) {
                break;
            }

            $current = (int)$parent['parent_id'];
        }

        return false;
    }

    /**
     * Обновление путей дочерних категорий
     */
    private function updateChildrenPaths(string $module, int $categoryId): void
    {
        $tableName = $module . '_categories';
        
        $children = $this->db->fetchAll(
            "SELECT id, parent_id FROM {$tableName} WHERE parent_id = ?", 
            [$categoryId]
        );

        foreach ($children as $child) {
            $path = $this->buildCategoryPath($module, (int)$child['parent_id']);
            $this->db->update(
                $tableName, 
                ['path' => $path], 
                ['id' => $child['id']]
            );
            
            // Рекурсивно обновляем потомков
            $this->updateChildrenPaths($module, (int)$child['id']);
        }
    }

    /**
     * Удаление дочерних категорий
     */
    private function deleteChildCategories(string $module, int $parentId): void
    {
        $tableName = $module . '_categories';
        
        $children = $this->db->fetchAll(
            "SELECT id FROM {$tableName} WHERE parent_id = ?", 
            [$parentId]
        );

        foreach ($children as $child) {
            $this->deleteChildCategories($module, (int)$child['id']);
            $this->deleteCategoryMaterials($module, (int)$child['id']);
            $this->db->delete($tableName, ['id' => $child['id']]);
        }
    }

    /**
     * Удаление материалов категории
     */
    private function deleteCategoryMaterials(string $module, int $categoryId): void
    {
        // Получаем все материалы в категории
        $materials = $this->db->fetchAll(
            "SELECT id FROM {$module} WHERE category_id = ?", 
            [$categoryId]
        );

        foreach ($materials as $material) {
            // Удаляем вложения
            $this->db->delete($module . '_attaches', ['material_id' => $material['id']]);
        }

        // Удаляем сами материалы
        $this->db->delete($module, ['category_id' => $categoryId]);
    }

    /**
     * Обновление видимости дочерних категорий на главной
     */
    private function updateChildrenHomeVisibility(string $module, int $parentId, bool $visible): void
    {
        $tableName = $module . '_categories';
        
        $children = $this->db->fetchAll(
            "SELECT id FROM {$tableName} WHERE parent_id = ?", 
            [$parentId]
        );

        foreach ($children as $child) {
            $childId = (int)$child['id'];
            
            // Обновляем категорию
            $this->db->update(
                $tableName, 
                ['view_on_home' => $visible ? 1 : 0], 
                ['id' => $childId]
            );

            // Обновляем материалы
            $this->db->update(
                $module, 
                ['view_on_home' => $visible ? 1 : 0], 
                ['category_id' => $childId]
            );

            // Рекурсивно обновляем потомков
            $this->updateChildrenHomeVisibility($module, $childId, $visible);
        }
    }
}

/**
 * DTO для данных категории
 */
readonly class CategoryData
{
    public function __construct(
        public string $title,
        public int $parentId = 0,
        public array $accessGroups = []
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
    
    $controller = new CategoryController(
        request: $container->get(Request::class),
        response: $container->get(Response::class),
        db: $container->get(DatabaseManager::class),
        auth: $container->get(Authorization::class),
        template: $container->get(TemplateEngine::class),
        cache: $container->get(CacheManager::class),
        categoryService: $container->get(CategoryService::class),
        sanitizer: $container->get(InputSanitizer::class),
        validator: $container->get(Validator::class)
    );

    $response = $controller->handle();
    $response->send();

} catch (Exception $e) {
    error_log('Category Application Error: ' . $e->getMessage());
    
    // Простая обработка ошибок для обратной совместимости
    session_start();
    $_SESSION['error_message'] = __('System error occurred');
    
    header('Location: /admin/category.php?mod=news', true, 302);
    exit;
}
