<?php
/*-----------------------------------------------\
| 												 |
| @Author:       Andrey Brykin (Drunya)          |
| @Email:        drunyacoder@gmail.com           |
| @Site:         http://atomx.net                |
| @Version:      1.0                             |
| @Project:      CMS                             |
| @package       CMS AtomX                       |
| @subpackege    Admin Panel module  			 |
| @copyright     ©Andrey Brykin 2010-2013        |
\-----------------------------------------------*/

/*-----------------------------------------------\
| 												 |
|  any partial or not partial extension          |
|  CMS AtomX,without the consent of the          |
|  author, is illegal                            |
|------------------------------------------------|
|  Любое распространение                         |
|  CMS AtomX или ее частей,                      |
|  без согласия автора, является не законным     |
\-----------------------------------------------*/

// Безопасные заголовки
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('X-Content-Type-Options: nosniff');

include_once '../sys/boot.php';
include_once ROOT . '/admin/inc/adm_boot.php';

// Проверка, что запрос выполнен методом POST для предотвращения CSRF
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    // Для GET запросов показываем форму подтверждения
    showLogoutConfirmation();
    exit;
}

// Проверка CSRF-токена
if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    die(__('CSRF token validation failed'));
}

// Полный выход из административной панели
logoutFromAdminPanel();

/**
 * Показывает форму подтверждения выхода
 */
function showLogoutConfirmation() {
    include_once ROOT . '/admin/template/header.php';
    ?>
    <div class="content">
        <div class="warning">
            <?php echo __('Are you sure you want to logout from admin panel?') ?>
        </div>
        <form action="exit.php" method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>" />
            <div class="form-actions">
                <input type="submit" value="<?php echo __('Yes, logout'); ?>" class="button" />
                <a href="/admin/" class="button"><?php echo __('Cancel'); ?></a>
            </div>
        </form>
    </div>
    <?php
    include_once ROOT . '/admin/template/footer.php';
}

/**
 * Выполняет выход из административной панели
 */
function logoutFromAdminPanel() {
    // Регистрируем событие выхода в лог
    if (isset($_SESSION['user_id'])) {
        error_log('Admin logout: User ID ' . $_SESSION['user_id'] . ' from IP ' . $_SERVER['REMOTE_ADDR']);
    }
    
    // Удаляем все данные сессии, связанные с админкой
    $adminSessionKeys = [
        'adm_panel_authorize',
        'admin_last_action',
        'admin_login_time'
    ];
    
    foreach ($adminSessionKeys as $key) {
        if (isset($_SESSION[$key])) {
            unset($_SESSION[$key]);
        }
    }
    
    // Также можно очистить другие данные сессии, если необходимо
    // но оставить основные данные пользователя, если он остается авторизованным на сайте
    
    // Редирект на главную страницу
    redirect('/');
}

// Если скрипт вызван напрямую без формы, выполняем выход
// (для обратной совместимости, но с проверкой метода)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && basename($_SERVER['SCRIPT_NAME']) === 'exit.php') {
    // Для старых ссылок показываем форму подтверждения
    showLogoutConfirmation();
    exit;
}
