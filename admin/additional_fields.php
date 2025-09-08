<?php
/**
 * ==================================================
 * Additional Fields Admin Module
 * ==================================================
 * 
 * @author    Andrey Brykin (Drunya)
 * @version   1.0
 * @project   CMS AtomX
 * @package   Admin Module
 * @subpackage Additional Fields
 * @copyright © Andrey Brykin 2010-2014
 * 
 * ==================================================
 * Any partial or complete distribution
 * of CMS AtomX without the consent of the author
 * is illegal.
 * ==================================================
 * Любое распространение CMS AtomX или ее частей,
 * без согласия автора, является незаконным.
 * ==================================================
 */

declare(strict_types=1);

include_once '../sys/boot.php';
include_once ROOT . '/admin/inc/adm_boot.php';

// Check permissions
if (!$ACL->turn(['panel', 'restricted_access_additional_fields'], false)) {
    $_SESSION['errors'] = __('Permission denied');
    redirect('/admin/');
}

// Get allowed modules
$ModulesManager = new ModulesManager();
$allow_modules = $ModulesManager->getAllowedModules('addFields');

// Validate module
$module = $_GET['m'] ?? 'news';
if (!in_array($module, $allow_modules)) {
    $module = 'news';
}

$pageTitle = __(ucfirst($module)) . ' - ' . __('Additional fields');

// Validate action
$action = $_GET['ac'] ?? 'index';
$allowed_actions = ['add', 'del', 'index', 'edit'];
if (!in_array($action, $allowed_actions)) {
    $action = 'index';
}

// Process action
switch ($action) {
    case 'del':
        handleDelete($module);
        break;
    case 'add':
        handleAdd($module);
        break;
    case 'edit':
        handleEdit($module);
        break;
    default:
        displayIndex($module);
}

/**
 * Display main index page
 */
function displayIndex(string $module): void
{
    global $FpsDB, $AddFields;
    
    $fields = $FpsDB->select($module . '_add_fields', DB_ALL);
    $AddFields = new FpsAdditionalFields;
    
    if (!empty($fields)) {
        $inputs = $AddFields->getInputs($fields, false, $module);
    }

    $pageNav = $pageTitle;
    $pageNavr = '';
    
    include_once ROOT . '/admin/template/header.php';
    ?>

    <!-- Add Field Modal -->
    <div class="popup" id="addFieldModal" role="dialog" aria-labelledby="addFieldTitle" aria-hidden="true">
        <div class="top">
            <div class="title" id="addFieldTitle"><?= __('Adding field') ?></div>
            <div class="close" onClick="closePopup('addFieldModal')" aria-label="<?= __('Close') ?>"></div>
        </div>
        <div class="items">
            <form action="additional_fields.php?m=<?= $module ?>&ac=add" method="POST">
                <div class="item">
                    <div class="left">
                        <label for="field_type"><?= __('Type of field') ?>:</label>
                    </div>
                    <div class="right">
                        <select name="type" id="field_type" required>
                            <option value="text">text</option>
                            <option value="checkbox">checkbox</option>
                            <option value="textarea">textarea</option>
                            <option value="select">select</option>
                            <option value="radio">radio</option>
                        </select>
                    </div>
                </div>
                
                <div class="item">
                    <div class="left">
                        <label for="field_label"><?= __('Visible name of field') ?>:</label>
                        <span class="comment"><?= __('Will be displayed in errors') ?></span>
                    </div>
                    <div class="right">
                        <input type="text" name="label" id="field_label" required />
                    </div>
                </div>
                
                <div class="item">
                    <div class="left">
                        <label for="field_size"><?= __('Max length') ?>:</label>
                        <span class="comment"><?= __('of saving data') ?></span>
                    </div>
                    <div class="right">
                        <input type="number" name="size" id="field_size" min="1" max="65535" />
                    </div>
                </div>
                
                <div class="item">
                    <div class="left">
                        <label for="field_params"><?= __('Params') ?>:</label>
                        <span class="comment"><?= __('For select/radio: value1|value2|value3') ?></span>
                    </div>
                    <div class="right">
                        <input type="text" name="params" id="field_params" />
                    </div>
                </div>
                
                <div class="item">
                    <div class="left">
                        <label for="field_required"><?= __('Required field') ?>:</label>
                    </div>
                    <div class="right">
                        <input type="checkbox" name="required" value="1" id="field_required" />
                        <label for="field_required"></label>
                    </div>
                </div>
                
                <div class="item submit">
                    <div class="left"></div>
                    <div class="right">
                        <button type="submit" name="send" class="save-button">
                            <?= __('Save') ?>
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Field Modals -->
    <?php if (!empty($fields)): ?>
        <?php foreach ($fields as $field): ?>
            <?php
            $params = !empty($field['params']) ? unserialize($field['params']) : [];
            $values = $params['values'] ?? '';
            $required = !empty($params['required']);
            ?>
            
            <div class="popup" id="edit_<?= $field['id'] ?>" role="dialog" aria-labelledby="editTitle_<?= $field['id'] ?>" aria-hidden="true">
                <div class="top">
                    <div class="title" id="editTitle_<?= $field['id'] ?>"><?= __('Editing field') ?></div>
                    <div class="close" onClick="closePopup('edit_<?= $field['id'] ?>')" aria-label="<?= __('Close') ?>"></div>
                </div>
                <div class="items">
                    <form action="additional_fields.php?m=<?= $module ?>&ac=edit&id=<?= $field['id'] ?>" method="POST">
                        <div class="item">
                            <div class="left">
                                <label for="type_<?= $field['id'] ?>"><?= __('Type of field') ?>:</label>
                            </div>
                            <div class="right">
                                <select name="type" id="type_<?= $field['id'] ?>" required>
                                    <option value="text"<?= $field['type'] === 'text' ? ' selected' : '' ?>>text</option>
                                    <option value="checkbox"<?= $field['type'] === 'checkbox' ? ' selected' : '' ?>>checkbox</option>
                                    <option value="textarea"<?= $field['type'] === 'textarea' ? ' selected' : '' ?>>textarea</option>
                                    <option value="select"<?= $field['type'] === 'select' ? ' selected' : '' ?>>select</option>
                                    <option value="radio"<?= $field['type'] === 'radio' ? ' selected' : '' ?>>radio</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="item">
                            <div class="left">
                                <label for="label_<?= $field['id'] ?>"><?= __('Visible name of field') ?>:</label>
                                <span class="comment"><?= __('Will be displayed in errors') ?></span>
                            </div>
                            <div class="right">
                                <input type="text" name="label" id="label_<?= $field['id'] ?>" value="<?= htmlspecialchars($field['label']) ?>" required />
                            </div>
                        </div>
                        
                        <div class="item">
                            <div class="left">
                                <label for="size_<?= $field['id'] ?>"><?= __('Max length') ?>:</label>
                                <span class="comment"><?= __('of saving data') ?></span>
                            </div>
                            <div class="right">
                                <input type="number" name="size" id="size_<?= $field['id'] ?>" value="<?= $field['size'] ?>" min="1" max="65535" />
                            </div>
                        </div>
                        
                        <div class="item">
                            <div class="left">
                                <label for="params_<?= $field['id'] ?>"><?= __('Params') ?>:</label>
                                <span class="comment"><?= __('For select/radio: value1|value2|value3') ?></span>
                            </div>
                            <div class="right">
                                <input type="text" name="params" id="params_<?= $field['id'] ?>" value="<?= htmlspecialchars($values) ?>" />
                            </div>
                        </div>
                        
                        <div class="item">
                            <div class="left">
                                <label for="required_<?= $field['id'] ?>"><?= __('Required field') ?>:</label>
                            </div>
                            <div class="right">
                                <input type="checkbox" name="required" value="1" id="required_<?= $field['id'] ?>"<?= $required ? ' checked' : '' ?> />
                                <label for="required_<?= $field['id'] ?>"></label>
                            </div>
                        </div>
                        
                        <div class="item submit">
                            <div class="left"></div>
                            <div class="right">
                                <button type="submit" name="send" class="save-button">
                                    <?= __('Save') ?>
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <!-- Main Content -->
    <?php if (!empty($fields)): ?>
        <div class="list">
            <div class="title"><?= __('Additional fields') ?></div>
            <button onclick="openPopup('addFieldModal');" class="add-cat-butt">
                <div class="add"></div><?= __('Add') ?>
            </button>
            
            <div class="table-responsive">
                <table class="grid" aria-label="<?= __('Additional fields list') ?>">
                    <thead>
                        <tr>
                            <th scope="col"><?= __('Type of field') ?></th>
                            <th scope="col"><?= __('Visible name of field') ?></th>
                            <th scope="col"><?= __('Max length') ?></th>
                            <th scope="col"><?= __('Params') ?></th>
                            <th scope="col"><?= __('Required field') ?></th>
                            <th scope="col"><?= __('Marker of field') ?></th>
                            <th scope="col" style="width:160px;"><?= __('Actions') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($fields as $field): ?>
                            <?php
                            $params = !empty($field['params']) ? unserialize($field['params']) : [];
                            $values = $params['values'] ?? '-';
                            $field_marker = 'add_field_' . $field['id'];
                            $required = !empty($params['required']) 
                                ? '<span class="text-danger">' . __('Yes') . '</span>' 
                                : '<span class="text-muted">' . __('No') . '</span>';
                            ?>
                            <tr>
                                <td><?= htmlspecialchars($field['type']) ?></td>
                                <td><?= htmlspecialchars($field['label']) ?></td>
                                <td><?= $field['size'] ? htmlspecialchars($field['size']) : '-' ?></td>
                                <td><?= $values !== '-' ? htmlspecialchars($values) : '-' ?></td>
                                <td><?= $required ?></td>
                                <td><code><?= htmlspecialchars(strtolower($field_marker)) ?></code></td>
                                <td>
                                    <a class="delete" title="<?= __('Delete') ?>" 
                                       href="additional_fields.php?m=<?= $module ?>&ac=del&id=<?= $field['id'] ?>" 
                                       onclick="return confirm('<?= __('Are you sure?') ?>');">
                                        <span class="sr-only"><?= __('Delete') ?></span>
                                    </a>
                                    <a class="edit" title="<?= __('Edit') ?>" 
                                       href="javascript://" 
                                       onclick="openPopup('edit_<?= $field['id'] ?>')">
                                        <span class="sr-only"><?= __('Edit') ?></span>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php else: ?>
        <div class="warning">
            <h3><?= __('Additional fields not found') ?></h3>
            <p><?= __('No additional fields have been created for this module yet.') ?></p>
        </div>
        <button onclick="openPopup('addFieldModal');" class="save-button">
            <?= __('Add field') ?>
        </button>
    <?php endif; ?>

    <!-- Error notifications -->
    <?php if (!empty($_SESSION['FpsForm']['errors'])): ?>
        <script type="text/javascript">
            showHelpWin(
                '<ul class="error"><?= addslashes($_SESSION['FpsForm']['errors']) ?></ul>', 
                '<?= addslashes(__('Errors')) ?>'
            );
        </script>
        <?php unset($_SESSION['FpsForm']); ?>
    <?php endif; ?>

    <?php
    include_once ROOT . '/admin/template/footer.php';
}

/**
 * Handle field addition
 */
function handleAdd(string $module): void
{
    global $FpsDB;

    if (!isset($_POST['send'])) {
        redirect('/admin/additional_fields.php?m=' . $module);
    }

    $errors = [];
    $allowed_types = ['text', 'checkbox', 'textarea', 'select', 'radio'];

    // Validate type
    $type = $_POST['type'] ?? 'text';
    if (!in_array($type, $allowed_types)) {
        $type = 'text';
    }

    // Validate label
    $label = trim($_POST['label'] ?? '');
    if (empty($label)) {
        $errors[] = __('Empty field "visible name"');
    }

    // Validate size
    $size = null;
    if ($type !== 'checkbox') {
        $size = (int)($_POST['size'] ?? 0);
        if ($size < 1) {
            $errors[] = __('Invalid field "max length"');
        }
    }

    // Prepare params
    $params = [];
    if (in_array($type, ['checkbox', 'select', 'radio'])) {
        $params['values'] = trim($_POST['params'] ?? __('Yes') . '|' . __('No'));
    }
    
    if (!empty($_POST['required'])) {
        $params['required'] = true;
    }

    if (!empty($errors)) {
        $_SESSION['FpsForm'] = ['errors' => implode('', array_map(fn($e) => "<li>$e</li>", $errors))];
        redirect('/admin/additional_fields.php?m=' . $module);
    }

    // Save to database
    $data = [
        'type' => $type,
        'label' => $label,
        'size' => $size,
        'params' => serialize($params),
    ];

    if (!$FpsDB->save($module . '_add_fields', $data)) {
        $_SESSION['errors'] = __('Error saving field');
        redirect('/admin/additional_fields.php?m=' . $module);
    }

    // Clean cache
    $Cache = new Cache();
    $Cache->clean(CACHE_MATCHING_ANY_TAG, ['module_' . $module]);
    
    $_SESSION['message'] = __('Field added successfully');
    redirect('/admin/additional_fields.php?m=' . $module);
}

/**
 * Handle field editing
 */
function handleEdit(string $module): void
{
    global $FpsDB;

    $id = (int)($_GET['id'] ?? 0);
    if ($id < 1) {
        redirect('/admin/additional_fields.php?m=' . $module);
    }

    if (!isset($_POST['send'])) {
        redirect('/admin/additional_fields.php?m=' . $module);
    }

    $errors = [];
    $allowed_types = ['text', 'checkbox', 'textarea', 'select', 'radio'];

    // Validate type
    $type = $_POST['type'] ?? 'text';
    if (!in_array($type, $allowed_types)) {
        $type = 'text';
    }

    // Validate label
    $label = trim($_POST['label'] ?? '');
    if (empty($label)) {
        $errors[] = __('Empty field "visible name"');
    }

    // Validate size
    $size = null;
    if ($type !== 'checkbox') {
        $size = (int)($_POST['size'] ?? 0);
        if ($size < 1) {
            $errors[] = __('Invalid field "max length"');
        }
    }

    // Prepare params
    $params = [];
    if (in_array($type, ['checkbox', 'select', 'radio'])) {
        $params['values'] = trim($_POST['params'] ?? __('Yes') . '|' . __('No'));
    }
    
    if (!empty($_POST['required'])) {
        $params['required'] = true;
    }

    if (!empty($errors)) {
        $_SESSION['FpsForm'] = ['errors' => implode('', array_map(fn($e) => "<li>$e</li>", $errors))];
        redirect('/admin/additional_fields.php?m=' . $module);
    }

    // Update database
    $data = [
        'type' => $type,
        'label' => $label,
        'size' => $size,
        'params' => serialize($params),
        'id' => $id,
    ];

    if (!$FpsDB->save($module . '_add_fields', $data)) {
        $_SESSION['errors'] = __('Error updating field');
        redirect('/admin/additional_fields.php?m=' . $module);
    }

    // Clean cache
    $Cache = new Cache();
    $Cache->clean(CACHE_MATCHING_ANY_TAG, ['module_' . $module]);
    
    $_SESSION['message'] = __('Field updated successfully');
    redirect('/admin/additional_fields.php?m=' . $module);
}

/**
 * Handle field deletion
 */
function handleDelete(string $module): void
{
    global $FpsDB;

    $id = (int)($_GET['id'] ?? 0);
    if ($id < 1) {
        redirect('/admin/additional_fields.php?m=' . $module);
    }

    // Delete from database
    $result = $FpsDB->delete($module . '_add_fields', ['id' => $id]);
    
    if (!$result) {
        $_SESSION['errors'] = __('Error deleting field');
        redirect('/admin/additional_fields.php?m=' . $module);
    }

    // Clean cache
    $Cache = new Cache();
    $Cache->clean(CACHE_MATCHING_ANY_TAG, ['module_' . $module]);
    
    $_SESSION['message'] = __('Field deleted successfully');
    redirect('/admin/additional_fields.php?m=' . $module);
}
