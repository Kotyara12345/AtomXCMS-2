<?php

declare(strict_types=1);

/**
 * Additional Fields Admin Panel
 * 
 * @author Andrey Brykin (Drunya) - Original
 * @email drunyacoder@gmail.com
 * @site http://atomx.net
 * @version 2.0 - Refactored for PHP 8.4
 * @package CMS AtomX
 * @subpackage Additional Fields (Admin Part)
 * @copyright ©Andrey Brykin 2010-2013, Refactored 2025
 */

namespace Admin\AdditionalFields;

require_once '../sys/boot.php';
require_once ROOT . '/admin/inc/adm_boot.php';

use InvalidArgumentException;
use RuntimeException;

/**
 * Additional Fields Controller
 */
class AdditionalFieldsController
{
    private const ALLOWED_ACTIONS = ['add', 'del', 'index', 'edit'];
    private const ALLOWED_FIELD_TYPES = ['text', 'checkbox', 'textarea'];
    private const DEFAULT_FIELD_SIZE = 70;
    
    public function __construct(
        private readonly ModulesManager $modulesManager,
        private readonly DatabaseInterface $database,
        private readonly CacheInterface $cache,
        private readonly SessionInterface $session
    ) {}
    
    public function handleRequest(): void
    {
        $module = $this->getValidatedModule();
        $action = $this->getValidatedAction();
        
        $this->executeAction($action, $module);
    }
    
    private function getValidatedModule(): string
    {
        $allowedModules = $this->modulesManager->getAllowedModules('addFields');
        $module = $_GET['m'] ?? '';
        
        if (empty($module) || !in_array($module, $allowedModules, true)) {
            return 'news'; // Default module
        }
        
        return $module;
    }
    
    private function getValidatedAction(): string
    {
        $action = $_GET['ac'] ?? 'index';
        
        if (!in_array($action, self::ALLOWED_ACTIONS, true)) {
            return 'index';
        }
        
        return $action;
    }
    
    private function executeAction(string $action, string $module): void
    {
        match ($action) {
            'add' => $this->handleAdd($module),
            'edit' => $this->handleEdit($module),
            'del' => $this->handleDelete($module),
            'index' => $this->handleIndex($module),
            default => $this->handleIndex($module)
        };
    }
    
    private function handleIndex(string $module): void
    {
        $fields = $this->getFieldsForModule($module);
        $pageTitle = __(ucfirst($module)) . ' - ' . __('Additional fields');
        
        $this->renderIndexPage($fields, $module, $pageTitle);
    }
    
    private function handleAdd(string $module): void
    {
        if (!$this->isPostRequest()) {
            $this->redirect($module);
            return;
        }
        
        try {
            $fieldData = $this->validateFieldData($_POST);
            $this->saveField($module, $fieldData);
            $this->clearModuleCache($module);
            $this->redirect($module);
        } catch (InvalidArgumentException $e) {
            $this->setFormError($e->getMessage());
            $this->redirect($module);
        }
    }
    
    private function handleEdit(string $module): void
    {
        $id = $this->getValidatedId();
        
        if (!$this->isPostRequest()) {
            $this->redirect($module);
            return;
        }
        
        try {
            $fieldData = $this->validateFieldData($_POST);
            $fieldData['id'] = $id;
            $this->updateField($module, $fieldData);
            $this->clearModuleCache($module);
            $this->redirect($module);
        } catch (InvalidArgumentException $e) {
            $this->setFormError($e->getMessage());
            $this->redirect($module);
        }
    }
    
    private function handleDelete(string $module): void
    {
        $id = $this->getValidatedId();
        $this->deleteField($module, $id);
        $this->redirect($module);
    }
    
    private function validateFieldData(array $postData): array
    {
        $errors = [];
        
        // Validate field type
        $type = trim($postData['type'] ?? '');
        if (!in_array($type, self::ALLOWED_FIELD_TYPES, true)) {
            $type = 'text';
        }
        
        // Validate label
        $label = trim($postData['label'] ?? '');
        if (empty($label)) {
            $errors[] = __('Empty field "visible name"');
        }
        
        // Validate size
        $size = $postData['size'] ?? '';
        if ($type !== 'checkbox') {
            if (empty($size)) {
                $errors[] = __('Empty field "max length"');
            } elseif (!is_numeric($size)) {
                $errors[] = __('Wrong chars in "max length"');
            }
        }
        
        if (!empty($errors)) {
            throw new InvalidArgumentException('<li>' . implode('</li><li>', $errors) . '</li>');
        }
        
        return [
            'type' => $type,
            'label' => $label ?: 'Add. field',
            'size' => !empty($size) ? (int) $size : self::DEFAULT_FIELD_SIZE,
            'params' => $this->buildParams($postData, $type)
        ];
    }
    
    private function buildParams(array $postData, string $type): string
    {
        $params = [];
        
        if ($type === 'checkbox') {
            $params['values'] = !empty($postData['params']) 
                ? trim($postData['params']) 
                : __('Yes') . '|' . __('No');
        }
        
        if (!empty($postData['required'])) {
            $params['required'] = 1;
        }
        
        return serialize($params);
    }
    
    private function getValidatedId(): int
    {
        $id = (int) ($_GET['id'] ?? 0);
        
        if ($id < 1) {
            throw new InvalidArgumentException('Invalid ID provided');
        }
        
        return $id;
    }
    
    private function getFieldsForModule(string $module): array
    {
        return $this->database->select($module . '_add_fields', DatabaseInterface::DB_ALL);
    }
    
    private function saveField(string $module, array $fieldData): void
    {
        $this->database->save($module . '_add_fields', $fieldData);
    }
    
    private function updateField(string $module, array $fieldData): void
    {
        $this->database->save($module . '_add_fields', $fieldData);
    }
    
    private function deleteField(string $module, int $id): void
    {
        $tableName = $this->database->getFullTableName($module . '_add_fields');
        $query = "DELETE FROM `{$tableName}` WHERE `id` = :id LIMIT 1";
        
        $this->database->prepare($query)->execute(['id' => $id]);
    }
    
    private function clearModuleCache(string $module): void
    {
        $this->cache->clean(CacheInterface::MATCHING_ANY_TAG, ['module_' . $module]);
    }
    
    private function setFormError(string $error): void
    {
        $this->session->set('FpsForm', ['errors' => $error]);
    }
    
    private function redirect(string $module): void
    {
        header("Location: /admin/additional_fields.php?m={$module}");
        exit;
    }
    
    private function isPostRequest(): bool
    {
        return $_SERVER['REQUEST_METHOD'] === 'POST';
    }
    
    private function renderIndexPage(array $fields, string $module, string $pageTitle): void
    {
        $pageNav = $pageTitle;
        $pageNavr = '';
        
        include_once ROOT . '/admin/template/header.php';
        
        $this->renderAddFieldModal($module);
        $this->renderEditFieldModals($fields, $module);
        $this->renderFieldsList($fields, $module);
        $this->renderJavaScriptErrors();
        
        include_once ROOT . '/admin/template/footer.php';
    }
    
    private function renderAddFieldModal(string $module): void
    {
        ?>
        <div class="popup" id="addCat">
            <div class="top">
                <div class="title"><?= __('Adding field') ?></div>
                <div class="close" onclick="closePopup('addCat')"></div>
            </div>
            <div class="items">
                <?= $this->renderFieldForm($module, 'add') ?>
            </div>
        </div>
        <?php
    }
    
    private function renderEditFieldModals(array $fields, string $module): void
    {
        foreach ($fields as $field) {
            $params = !empty($field['params']) ? unserialize($field['params']) : [];
            ?>
            <div class="popup" id="edit_<?= $field['id'] ?>">
                <div class="top">
                    <div class="title"><?= __('Editing field') ?></div>
                    <div class="close" onclick="closePopup('edit_<?= $field['id'] ?>')"></div>
                </div>
                <div class="items">
                    <?= $this->renderFieldForm($module, 'edit', $field, $params) ?>
                </div>
            </div>
            <?php
        }
    }
    
    private function renderFieldForm(string $module, string $action, ?array $field = null, ?array $params = null): string
    {
        $actionUrl = "additional_fields.php?m={$module}&ac={$action}";
        if ($field) {
            $actionUrl .= "&id={$field['id']}";
        }
        
        ob_start();
        ?>
        <form action="<?= htmlspecialchars($actionUrl) ?>" method="POST">
            <div class="item">
                <div class="left"><?= __('Type of field') ?>:</div>
                <div class="right">
                    <select name="type">
                        <?php foreach (self::ALLOWED_FIELD_TYPES as $type): ?>
                            <option value="<?= $type ?>"<?= ($field && $field['type'] === $type) ? ' selected="selected"' : '' ?>>
                                <?= $type ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="clear"></div>
            </div>
            
            <div class="item">
                <div class="left">
                    <?= __('Visible name of field') ?>:
                    <span class="comment"><?= __('Will be displayed in errors') ?></span>
                </div>
                <div class="right">
                    <input type="text" name="label" value="<?= $field ? htmlspecialchars($field['label']) : '' ?>" />
                </div>
                <div class="clear"></div>
            </div>
            
            <div class="item">
                <div class="left">
                    <?= __('Max length') ?>:
                    <span class="comment"><?= __('of saving data') ?></span>
                </div>
                <div class="right">
                    <input type="text" name="size" value="<?= $field && !empty($field['size']) ? htmlspecialchars((string)$field['size']) : '' ?>" />
                </div>
                <div class="clear"></div>
            </div>
            
            <div class="item">
                <div class="left">
                    <?= __('Params') ?>:
                    <span class="comment"><?= __('Read more in the doc') ?></span>
                </div>
                <div class="right">
                    <input type="text" name="params" value="<?= $params && !empty($params['values']) ? htmlspecialchars($params['values']) : '' ?>" />
                </div>
                <div class="clear"></div>
            </div>
            
            <div class="item">
                <div class="left"><?= __('Required field') ?>:</div>
                <div class="right">
                    <input id="required_<?= $field['id'] ?? 'new' ?>" type="checkbox" name="required" value="1"<?= ($params && !empty($params['required'])) ? ' checked="checked"' : '' ?> />
                    <label for="required_<?= $field['id'] ?? 'new' ?>"></label>
                </div>
                <div class="clear"></div>
            </div>
            
            <div class="item submit">
                <div class="left"></div>
                <div class="right" style="float:left;">
                    <input type="submit" value="<?= __('Save') ?>" name="send" class="save-button" />
                </div>
                <div class="clear"></div>
            </div>
        </form>
        <?php
        return ob_get_clean();
    }
    
    private function renderFieldsList(array $fields, string $module): void
    {
        if (empty($fields)) {
            $this->renderEmptyFieldsMessage();
            return;
        }
        
        ?>
        <div class="list">
            <div class="title"><?= __('Additional fields') ?></div>
            <div onclick="openPopup('addCat');" class="add-cat-butt">
                <div class="add"></div><?= __('Add') ?>
            </div>
            
            <table class="grid" cellspacing="0" style="width:100%;">
                <tr>
                    <th><?= __('Type of field') ?></th>
                    <th><?= __('Visible name of field') ?></th>
                    <th><?= __('Max length') ?></th>
                    <th><?= __('Params') ?></th>
                    <th><?= __('Required field') ?></th>
                    <th><?= __('Marker of field') ?></th>
                    <th style="width:160px;"><?= __('Actions') ?></th>
                </tr>
                
                <?php foreach ($fields as $field): ?>
                    <?php
                    $params = !empty($field['params']) ? unserialize($field['params']) : [];
                    $values = !empty($params['values']) ? $params['values'] : '-';
                    $fieldMarker = 'add_field_' . $field['id'];
                    $required = !empty($params['required']) 
                        ? '<span style="color:red;">' . __('Yes') . '</span>' 
                        : '<span style="color:blue;">' . __('No') . '</span>';
                    ?>
                    
                    <tr>
                        <td><?= htmlspecialchars($field['type']) ?></td>
                        <td><?= htmlspecialchars($field['label']) ?></td>
                        <td><?= !empty($field['size']) ? htmlspecialchars((string)$field['size']) : '-' ?></td>
                        <td><?= htmlspecialchars($values) ?></td>
                        <td><?= $required ?></td>
                        <td><?= htmlspecialchars(strtolower($fieldMarker)) ?></td>
                        <td>
                            <a class="delete" title="Delete" 
                               href="additional_fields.php?m=<?= $module ?>&ac=del&id=<?= $field['id'] ?>" 
                               onclick="return confirm('Are you sure?');"></a>
                            <a class="edit" title="Edit" 
                               href="javascript://" 
                               onclick="openPopup('edit_<?= $field['id'] ?>')"></a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>
        </div>
        <?php
    }
    
    private function renderEmptyFieldsMessage(): void
    {
        ?>
        <div class="warning">
            <div class="h3"><?= __('Additional fields not found') ?></div>
        </div>
        <input type="button" value="<?= __('Add') ?>" onclick="openPopup('addCat');" class="save-button" />
        <?php
    }
    
    private function renderJavaScriptErrors(): void
    {
        $formSession = $this->session->get('FpsForm');
        if (!empty($formSession['errors'])) {
            ?>
            <script type="text/javascript">
                showHelpWin('<?= '<ul class="error">' . $formSession['errors'] . '</ul>' ?>', '<?= __('Errors') ?>');
            </script>
            <?php
            $this->session->unset('FpsForm');
        }
    }
}

// Interfaces for dependency injection
interface DatabaseInterface
{
    public const DB_ALL = 'all';
    
    public function select(string $table, string $type): array;
    public function save(string $table, array $data): void;
    public function getFullTableName(string $table): string;
    public function prepare(string $query): \PDOStatement;
}

interface CacheInterface
{
    public const MATCHING_ANY_TAG = 'matching_any_tag';
    
    public function clean(string $mode, array $tags): void;
}

interface SessionInterface
{
    public function get(string $key): mixed;
    public function set(string $key, mixed $value): void;
    public function unset(string $key): void;
}

// Usage
try {
    $controller = new AdditionalFieldsController(
        new ModulesManager(),
        new Database(), // Assuming these classes implement the interfaces
        new Cache(),
        new Session()
    );
    
    $controller->handleRequest();
} catch (Throwable $e) {
    error_log("Additional Fields Error: " . $e->getMessage());
    // Handle error appropriately
}

?>
