<?php
declare(strict_types=1);

/**
 * AtomX CMS Installation - Step 1: Database Configuration
 * Modernized for PHP 8.1+ with security and performance improvements
 */

// Initialize session and configuration
require_once '../sys/boot.php';

// Security headers
header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');

// Initialize errors array
$errors = [];

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send'])) {
    $errors = validateFormData($_POST);
    
    if (empty($errors)) {
        $connection = testDatabaseConnection($_POST);
        
        if ($connection['success']) {
            saveConfiguration($_POST);
            header('Location: step2.php');
            exit;
        } else {
            $errors['database'] = $connection['message'];
        }
    }
}

/**
 * Validate form data
 */
function validateFormData(array $data): array
{
    $errors = [];
    
    // Database validation
    if (empty(trim($data['host'] ?? ''))) {
        $errors['db_host'] = 'Не заполнено поле "Хост базы данных"';
    }
    
    if (empty(trim($data['base'] ?? ''))) {
        $errors['db_name'] = 'Не заполнено поле "Имя базы данных"';
    }
    
    if (empty(trim($data['user'] ?? ''))) {
        $errors['db_user'] = 'Не заполнено поле "Логин"';
    }
    
    // Admin validation
    if (empty(trim($data['adm_login'] ?? ''))) {
        $errors['adm_login'] = 'Не заполнено поле "Логин администратора"';
    }
    
    if (empty(trim($data['adm_pass'] ?? ''))) {
        $errors['adm_pass'] = 'Не заполнено поле "Пароль администратора"';
    }
    
    if (empty(trim($data['adm_email'] ?? '')) || !filter_var($data['adm_email'], FILTER_VALIDATE_EMAIL)) {
        $errors['adm_email'] = 'Неверный формат email адреса';
    }
    
    // Prefix validation
    if (!empty($data['prefix']) && !preg_match('/^[a-z_]*$/i', $data['prefix'])) {
        $errors['db_prefix'] = 'Не допустимые символы в поле "Префикс"';
    }
    
    return $errors;
}

/**
 * Test database connection
 */
function testDatabaseConnection(array $data): array
{
    $host = $data['host'] ?? '';
    $database = $data['base'] ?? '';
    $username = $data['user'] ?? '';
    $password = $data['pass'] ?? '';
    
    try {
        if (!class_exists('PDO')) {
            return [
                'success' => false,
                'message' => 'Требуется расширение PDO для работы с базой данных'
            ];
        }
        
        // Test connection
        $dsn = "mysql:host={$host};charset=utf8mb4";
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 5,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ];
        
        $pdo = new PDO($dsn, $username, $password, $options);
        
        // Test database selection
        $pdo->exec("USE `{$database}`");
        
        return ['success' => true];
        
    } catch (PDOException $e) {
        error_log('Database connection error: ' . $e->getMessage());
        
        return [
            'success' => false,
            'message' => 'Ошибка подключения к базе данных: ' . $e->getMessage()
        ];
    }
}

/**
 * Save configuration to file
 */
function saveConfiguration(array $data): void
{
    $settings = [
        'db' => [
            'host' => $data['host'],
            'name' => $data['base'],
            'user' => $data['user'],
            'pass' => $data['pass'],
            'prefix' => $data['prefix'] ?? ''
        ],
        'admin_email' => $data['adm_email']
    ];
    
    // Store in session for next steps
    $_SESSION['db_config'] = $settings;
    $_SESSION['admin_config'] = [
        'name' => $data['adm_login'],
        'pass' => password_hash($data['adm_pass'], PASSWORD_DEFAULT),
        'email' => $data['adm_email']
    ];
    
    // Also save to config file if needed
    if (class_exists('Config')) {
        $currentConfig = Config::read('all') ?: [];
        $mergedConfig = array_merge($currentConfig, $settings);
        Config::write($mergedConfig);
    }
}

/**
 * Escape HTML output
 */
function escapeHtml(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <title>AtomX CMS - Настройка базы данных</title>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    
    <link rel="shortcut icon" href="../sys/img/favicon.ico" type="image/x-icon">
    <link rel="stylesheet" href="css/style.css">
    
    <script src="../sys/js/jquery.js" defer></script>
    <script src="js/installer.js" defer></script>
</head>
<body>
    <?php include 'partials/header.php'; ?>
    
    <main class="main-content">
        <div class="container">
            <!-- System Checks -->
            <div class="system-checks">
                <h3>Права на папки (с учетом вложенных файлов)</h3>
                <div class="check-result" id="checkAccess">
                    <div class="loading-spinner"></div>
                </div>
                
                <h3>Настройки сервера и PHP</h3>
                <div class="check-result" id="checkServer">
                    <div class="loading-spinner"></div>
                </div>
            </div>
            
            <!-- Database Configuration Form -->
            <div class="config-section">
                <h3>Настройки подключения к базе данных</h3>
                <p class="form-description">
                    Введите данные доступа к базе данных. Логин и пароль - это учетные данные пользователя базы данных.
                    На локальном сервере обычно используются логин "root" и пустой пароль.
                </p>
                
                <?php if (!empty($errors)): ?>
                <div class="error-message">
                    <h4>Обнаружены ошибки:</h4>
                    <ul>
                        <?php foreach ($errors as $error): ?>
                        <li><?= escapeHtml($error) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>
                
                <form method="post" class="config-form" id="databaseForm">
                    <div class="form-grid">
                        <!-- Database Settings -->
                        <div class="form-group">
                            <label for="host">Хост Сервера SQL *</label>
                            <input type="text" id="host" name="host" value="<?= escapeHtml($_POST['host'] ?? 'localhost') ?>" 
                                   required oninput="validateDatabaseConnection()">
                            <span class="validation-status" id="hostStatus"></span>
                        </div>
                        
                        <div class="form-group">
                            <label for="base">База Данных *</label>
                            <input type="text" id="base" name="base" value="<?= escapeHtml($_POST['base'] ?? 'atomx') ?>" 
                                   required oninput="validateDatabaseConnection()">
                            <span class="validation-status" id="baseStatus"></span>
                        </div>
                        
                        <div class="form-group">
                            <label for="user">Пользователь Базы *</label>
                            <input type="text" id="user" name="user" value="<?= escapeHtml($_POST['user'] ?? 'root') ?>" 
                                   required oninput="validateDatabaseConnection()">
                            <span class="validation-status" id="userStatus"></span>
                        </div>
                        
                        <div class="form-group">
                            <label for="pass">Пароль Пользователя</label>
                            <input type="password" id="pass" name="pass" value="<?= escapeHtml($_POST['pass'] ?? '') ?>" 
                                   oninput="validateDatabaseConnection()">
                            <span class="validation-status" id="passStatus"></span>
                        </div>
                        
                        <div class="form-group">
                            <label for="prefix">Префикс таблиц</label>
                            <input type="text" id="prefix" name="prefix" value="<?= escapeHtml($_POST['prefix'] ?? '') ?>" 
                                   pattern="[a-zA-Z_]*" title="Только латинские буквы и подчеркивания"
                                   oninput="validatePrefix(this.value)">
                            <span class="validation-status" id="prefixStatus"></span>
                        </div>
                        
                        <!-- Admin Settings -->
                        <div class="form-group">
                            <label for="adm_login">Логин Администратора *</label>
                            <input type="text" id="adm_login" name="adm_login" value="<?= escapeHtml($_POST['adm_login'] ?? '') ?>" 
                                   required minlength="3">
                        </div>
                        
                        <div class="form-group">
                            <label for="adm_pass">Пароль Администратора *</label>
                            <input type="password" id="adm_pass" name="adm_pass" value="<?= escapeHtml($_POST['adm_pass'] ?? '') ?>" 
                                   required minlength="6">
                        </div>
                        
                        <div class="form-group">
                            <label for="adm_email">Email Администратора *</label>
                            <input type="email" id="adm_email" name="adm_email" value="<?= escapeHtml($_POST['adm_email'] ?? '') ?>" 
                                   required>
                        </div>
                    </div>
                    
                    <button type="submit" name="send" class="btn-primary">Продолжить</button>
                </form>
            </div>
        </div>
    </main>
    
    <?php include 'partials/footer.php'; ?>
    
    <script>
    // Initialize validation on page load
    document.addEventListener('DOMContentLoaded', function() {
        checkAccess();
        checkServer();
        checkUpdate();
        setupProgressBar();
        
        // Initial validation
        validateDatabaseConnection();
        validatePrefix(document.getElementById('prefix').value);
    });
    </script>
<script type="text/javascript">
class InstallerValidator {
    constructor() {
        this.timeout = null;
    }
    
    validateDatabaseConnection() {
        clearTimeout(this.timeout);
        
        this.timeout = setTimeout(() => {
            const host = document.getElementById('host').value;
            const base = document.getElementById('base').value;
            const user = document.getElementById('user').value;
            const pass = document.getElementById('pass').value;
            
            this.updateValidationStatus('host', 'checking');
            this.updateValidationStatus('base', 'checking');
            this.updateValidationStatus('user', 'checking');
            this.updateValidationStatus('pass', 'checking');
            
            fetch(`check_sql_server.php?host=${encodeURIComponent(host)}&user=${encodeURIComponent(user)}&pass=${encodeURIComponent(pass)}`)
                .then(response => response.text())
                .then(data => {
                    const isValid = !data.includes('Не удалось');
                    this.updateValidationStatus('host', isValid ? 'valid' : 'invalid');
                    this.updateValidationStatus('user', isValid ? 'valid' : 'invalid');
                    this.updateValidationStatus('pass', isValid ? 'valid' : 'invalid');
                    
                    if (isValid && base) {
                        this.validateDatabaseSelection(host, base, user, pass);
                    }
                })
                .catch(() => {
                    this.updateValidationStatus('host', 'error');
                    this.updateValidationStatus('user', 'error');
                    this.updateValidationStatus('pass', 'error');
                });
        }, 500);
    }
    
    validateDatabaseSelection(host, base, user, pass) {
        fetch(`check_sql_server.php?type=base&host=${encodeURIComponent(host)}&base=${encodeURIComponent(base)}&user=${encodeURIComponent(user)}&pass=${encodeURIComponent(pass)}`)
            .then(response => response.text())
            .then(data => {
                const isValid = !data.includes('Не удалось');
                this.updateValidationStatus('base', isValid ? 'valid' : 'invalid');
            })
            .catch(() => {
                this.updateValidationStatus('base', 'error');
            });
    }
    
    validatePrefix(value) {
        const isValid = /^[a-z_]*$/i.test(value);
        this.updateValidationStatus('prefix', isValid ? 'valid' : 'invalid');
    }
    
    updateValidationStatus(field, status) {
        const element = document.getElementById(field + 'Status');
        if (element) {
            element.className = 'validation-status ' + status;
            element.textContent = this.getStatusMessage(status);
        }
    }
    
    getStatusMessage(status) {
        const messages = {
            'checking': 'Проверка...',
            'valid': '✓',
            'invalid': '✗',
            'error': 'Ошибка'
        };
        return messages[status] || '';
    }
}

// Initialize validator
const validator = new InstallerValidator();

// Global functions for HTML onclick attributes
function validateDatabaseConnection() {
    validator.validateDatabaseConnection();
}

function validatePrefix(value) {
    validator.validatePrefix(value);
}

// System check functions
function checkAccess() {
    fetch('check_access.php')
        .then(response => response.text())
        .then(data => {
            document.getElementById('checkAccess').innerHTML = data;
        });
}

function checkServer() {
    fetch('check_server.php')
        .then(response => response.text())
        .then(data => {
            document.getElementById('checkServer').innerHTML = data;
        });
}

function checkUpdate() {
    fetch('ping.php?type=v')
        .then(response => response.text())
        .then(data => {
            document.getElementById('newv').innerHTML = data;
        });
}

function setupProgressBar() {
    fetch('progressbar.php?step=1')
        .then(response => response.text())
        .then(data => {
            document.getElementById('progressbar').innerHTML = data;
        });
}
</script>
</body>
</html>
