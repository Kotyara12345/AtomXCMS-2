<?php
declare(strict_types=1);

/**
 * @author    Andrey Brykin (Drunya)
 * @email     drunyacoder@gmail.com
 * @site      http://atomx.net
 * @version   0.5
 * @project   CMS AtomX
 * @package   Additional Fields (Admin Part)
 * @copyright ©Andrey Brykin 2010-2013
 *
 * Любое распространение CMS AtomX или ее частей
 * без согласия автора является незаконным.
 */

require_once '../sys/boot.php';
require_once ROOT . '/admin/inc/adm_boot.php';

use Fps\Cache;
use Fps\Database\FpsDB;
use Fps\Modules\ModulesManager;
use Fps\AdditionalFields\FpsAdditionalFields;

// Проверка разрешенных модулей
$ModulesManager = new ModulesManager();
$allowModules = $ModulesManager->getAllowedModules('addFields');

// Проверка модуля
$module = $_GET['m'] ?? 'news';
if (!in_array($module, $allowModules)) {
    $module = 'news';
    $_GET['ac'] = 'index';
}

// Проверка действия
$action = $_GET['ac'] ?? 'index';
$allowedActions = ['add', 'del', 'index', 'edit'];
if (!in_array($action, $allowedActions)) {
    $action = 'index';
}

// Установка заголовка страницы
$pageTitle = __(ucfirst($module)) . ' - ' . __('Additional fields');

// Обработка действий
switch ($action) {
    case 'del':
        handleDelete();
        break;
    case 'add':
        handleAdd();
        break;
    case 'edit':
        handleEdit();
        break;
    default:
        handleIndex();
}

/**
 * Отображение списка дополнительных полей
 */
function handleIndex(): void
{
    global $FpsDB, $module;

    $fields = $FpsDB->select($module . '_add_fields', DB_ALL);
    $AddFields = new FpsAdditionalFields();
    $inputs = [];

    if (count($fields) {
        $inputs = $AddFields->getInputs($fields, false, $module);
    }

    include_once ROOT . '/admin/template/header.php';

    if (!empty($fields)) {
        include 'views/additional_fields_list.php';
    } else {
        include 'views/additional_fields_empty.php';
    }

    include_once 'template/footer.php';
}

/**
 * Обработка добавления поля
 */
function handleAdd(): void
{
    global $FpsDB, $module;

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $errors = validateFieldData($_POST);
        if (!empty($errors)) {
            $_SESSION['FpsForm'] = ['errors' => $errors];
            redirect("/admin/additional_fields.php?m=$module");
        }

        $data = prepareFieldData($_POST);
        $FpsDB->save($module . '_add_fields', $data);

        // Очистка кэша
        $Cache = new Cache();
        $Cache->clean(CACHE_MATCHING_ANY_TAG, ["module_$module"]);
        redirect("/admin/additional_fields.php?m=$module");
    }
}

/**
 * Обработка редактирования поля
 */
function handleEdit(): void
{
    global $FpsDB, $module;

    $id = intval($_GET['id'] ?? 0);
    if ($id < 1) {
        redirect("/admin/additional_fields.php?m=$module");
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $errors = validateFieldData($_POST);
        if (!empty($errors)) {
            $_SESSION['FpsForm'] = ['errors' => $errors];
            redirect("/admin/additional_fields.php?m=$module");
        }

        $data = prepareFieldData($_POST);
        $data['id'] = $id;
        $FpsDB->save($module . '_add_fields', $data);

        // Очистка кэша
        $Cache = new Cache();
        $Cache->clean(CACHE_MATCHING_ANY_TAG, ["module_$module"]);
        redirect("/admin/additional_fields.php?m=$module");
    }
}

/**
 * Обработка удаления поля
 */
function handleDelete(): void
{
    global $FpsDB, $module;

    $id = intval($_GET['id'] ?? 0);
    if ($id < 1) {
        redirect("/admin/additional_fields.php?m=$module");
    }

    $FpsDB->query("DELETE FROM `" . $FpsDB->getFullTableName($module . '_add_fields') . "` WHERE `id` = ? LIMIT 1", [$id]);
    redirect("/admin/additional_fields.php?m=$module");
}

/**
 * Валидация данных поля
 */
function validateFieldData(array $data): array
{
    $errors = [];
    $allowTypes = ['text', 'checkbox', 'textarea'];

    if (empty($data['label'])) {
        $errors[] = __('Empty field "visible name"');
    }

    if (empty($data['size']) && ($data['type'] ?? '') !== 'checkbox') {
        $errors[] = __('Empty field "max length"');
    }

    if (!empty($data['size']) && !is_numeric($data['size'])) {
        $errors[] = __('Wrong chars in "max length"');
    }

    if (!in_array($data['type'] ?? '', $allowTypes)) {
        $errors[] = __('Invalid field type');
    }

    return $errors;
}

/**
 * Подготовка данных поля для сохранения
 */
function prepareFieldData(array $data): array
{
    $params = [
        'values' => $data['params'] ?? __('Yes') . '|' . __('No'),
    ];

    if (!empty($data['required'])) {
        $params['required'] = 1;
    }

    if (($data['type'] ?? '') !== 'checkbox') {
        unset($params['values']);
    }

    return [
        'type' => $data['type'] ?? 'text',
        'label' => $data['label'] ?? 'Add. field',
        'size' => intval($data['size'] ?? 70),
        'params' => serialize($params),
    ];
}
