<?php

declare(strict_types=1);

/**
 * Admin Boot Module for CMS AtomX
 * 
 * @author Andrey Brykin (Drunya)
 * @version 2.0 (Modernized for PHP 8.4)
 * @package CMS AtomX
 * @subpackage Admin Module
 * @copyright ©Andrey Brykin 2010-2024
 * @license Proprietary
 */

namespace AtomX\Admin;

use AtomX\Core\{Config, Security, Session, Database, ACL, ModuleInstaller};
use AtomX\Exceptions\{AuthenticationException, AuthorizationException, ModuleInstallException};

/**
 * Admin Authentication and Authorization Handler
 */
readonly class AdminBootstrap
{
    private const SESSION_TIMEOUT_KEY = 'adm_panel_authorize';
    private const MAX_LOGIN_ATTEMPTS = 5;
    private const LOCKOUT_TIME = 900; // 15 minutes
    
    public function __construct(
        private Database $db,
        private ACL $acl,
        private Session $session,
        private Security $security,
        private Config $config
    ) {}
    
    /**
     * Initialize admin panel
     */
    public function boot(): void
    {
        $this->setSecurityHeaders();
        $this->checkInstallation();
        $this->checkReferrerProtection();
        $this->handleAuthentication();
        $this->handleModuleInstallation();
    }
    
    private function setSecurityHeaders(): void
    {
        if (!headers_sent()) {
            header('Content-Type: text/html; charset=utf-8');
            header('X-Frame-Options: DENY');
            header('X-Content-Type-Options: nosniff');
            header('X-XSS-Protection: 1; mode=block');
            header('Referrer-Policy: strict-origin-when-cross-origin');
            
            if ($this->config->get('force_https', false)) {
                header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
            }
        }
    }
    
    private function checkInstallation(): void
    {
        if (!$this->isInstalled()) {
            $this->redirect('/install');
        }
    }
    
    private function checkReferrerProtection(): void
    {
        if (!$this->config->get('adm_refer_protected', false)) {
            return;
        }
        
        $scriptName = $_SERVER['REQUEST_URI'] ?? '';
        $scriptName = basename(parse_url($scriptName, PHP_URL_PATH) ?: '');
        
        if ($scriptName !== 'index.php' && $scriptName !== '') {
            $referer = $_SERVER['HTTP_REFERER'] ?? '';
            $refererHost = parse_url($referer, PHP_URL_HOST);
            $serverName = $_SERVER['SERVER_NAME'] ?? '';
            
            if (empty($refererHost) || $refererHost !== $serverName) {
                $this->redirect('/admin/index.php');
            }
        }
    }
    
    private function handleAuthentication(): void
    {
        $currentTime = time();
        $sessionTimeout = $this->session->get(self::SESSION_TIMEOUT_KEY, 0);
        $currentUser = $this->session->get('user');
        
        // Check if user is authenticated and session is valid
        if ($sessionTimeout > $currentTime && !empty($currentUser)) {
            $this->refreshSession();
            $this->checkPermissions();
            return;
        }
        
        // Handle login attempt
        if ($this->isLoginAttempt()) {
            $this->processLogin();
        }
        
        $this->showLoginForm();
    }
    
    private function isLoginAttempt(): bool
    {
        return isset($_POST['send'], $_POST['login'], $_POST['passwd']);
    }
    
    private function processLogin(): void
    {
        try {
            $this->checkBruteForce();
            
            $credentials = $this->validateCredentials();
            $user = $this->authenticateUser($credentials);
            
            $this->createUserSession($user);
            $this->clearLoginAttempts();
            
            $this->redirect('/admin/');
            
        } catch (AuthenticationException $e) {
            $this->recordFailedAttempt();
            $this->showLoginForm($e->getMessage());
        }
    }
    
    private function checkBruteForce(): void
    {
        $clientIp = $this->security->getClientIP();
        $attempts = $this->session->get("login_attempts_{$clientIp}", 0);
        $lastAttempt = $this->session->get("last_attempt_{$clientIp}", 0);
        
        if ($attempts >= self::MAX_LOGIN_ATTEMPTS) {
            $timeSinceLastAttempt = time() - $lastAttempt;
            if ($timeSinceLastAttempt < self::LOCKOUT_TIME) {
                $remainingTime = self::LOCKOUT_TIME - $timeSinceLastAttempt;
                throw new AuthenticationException(
                    "Слишком много неудачных попыток входа. Попробуйте через {$remainingTime} секунд."
                );
            }
            // Reset attempts after lockout period
            $this->clearLoginAttempts();
        }
    }
    
    private function validateCredentials(): array
    {
        $login = trim($_POST['login'] ?? '');
        $password = trim($_POST['passwd'] ?? '');
        
        if (empty($login)) {
            throw new AuthenticationException('Заполните поле "Логин"');
        }
        
        if (empty($password)) {
            throw new AuthenticationException('Заполните поле "Пароль"');
        }
        
        if (!$this->security->validateInput($login)) {
            throw new AuthenticationException('Недопустимые символы в логине');
        }
        
        return ['login' => strtolower($login), 'password' => $password];
    }
    
    private function authenticateUser(array $credentials): array
    {
        // Use password_verify instead of md5 for new installations
        // Keep md5 compatibility for legacy users
        $user = $this->db->selectFirst('users', [
            'name' => $credentials['login']
        ]);
        
        if (empty($user)) {
            throw new AuthenticationException('Неверный логин или пароль');
        }
        
        // Check if user has new password hash
        if (!empty($user['password_hash'])) {
            if (!password_verify($credentials['password'], $user['password_hash'])) {
                throw new AuthenticationException('Неверный логин или пароль');
            }
        } else {
            // Legacy MD5 check
            if ($user['passw'] !== md5($credentials['password'])) {
                throw new AuthenticationException('Неверный логин или пароль');
            }
            
            // Upgrade to modern password hash
            $this->upgradeUserPassword($user['id'], $credentials['password']);
        }
        
        return $user;
    }
    
    private function upgradeUserPassword(int $userId, string $password): void
    {
        $hash = password_hash($password, PASSWORD_ARGON2ID);
        $this->db->update('users', ['password_hash' => $hash], ['id' => $userId]);
    }
    
    private function createUserSession(array $user): void
    {
        $this->session->set('user', $user);
        $this->session->set(
            self::SESSION_TIMEOUT_KEY,
            time() + $this->config->get('session_time', 3600)
        );
        
        $this->acl->setUserPermissions($user['status']);
    }
    
    private function refreshSession(): void
    {
        $this->session->set(
            self::SESSION_TIMEOUT_KEY,
            time() + $this->config->get('session_time', 3600)
        );
    }
    
    private function checkPermissions(): void
    {
        if (!$this->acl->hasPermission(['panel', 'restricted_access'])) {
            return;
        }
        
        $scriptName = basename($_SERVER['SCRIPT_NAME'] ?? '', '.php');
        
        if (!empty($scriptName) && !in_array($scriptName, ['index', 'exit'], true)) {
            if (!$this->acl->hasPermission(['panel', "restricted_access_{$scriptName}"])) {
                $this->session->setFlash('error', 'Доступ запрещен');
                $this->redirect('/admin/');
            }
        }
    }
    
    private function recordFailedAttempt(): void
    {
        $clientIp = $this->security->getClientIP();
        $attempts = $this->session->get("login_attempts_{$clientIp}", 0) + 1;
        
        $this->session->set("login_attempts_{$clientIp}", $attempts);
        $this->session->set("last_attempt_{$clientIp}", time());
    }
    
    private function clearLoginAttempts(): void
    {
        $clientIp = $this->security->getClientIP();
        $this->session->remove("login_attempts_{$clientIp}");
        $this->session->remove("last_attempt_{$clientIp}");
    }
    
    private function handleModuleInstallation(): void
    {
        $moduleToInstall = $_GET['install'] ?? '';
        
        if (empty($moduleToInstall)) {
            return;
        }
        
        if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_]*$/', $moduleToInstall)) {
            $this->session->setFlash('error', 'Недопустимое имя модуля');
            $this->redirect('/admin/');
        }
        
        try {
            $installer = new ModuleInstaller($this->db, $this->config);
            $installer->installModule($moduleToInstall);
            
            $this->session->setFlash(
                'success',
                sprintf('Модуль "%s" успешно установлен', htmlspecialchars($moduleToInstall))
            );
            
        } catch (ModuleInstallException $e) {
            $this->session->setFlash(
                'error',
                sprintf('Ошибка установки модуля "%s": %s', 
                    htmlspecialchars($moduleToInstall), 
                    htmlspecialchars($e->getMessage())
                )
            );
        }
        
        $this->redirect('/admin/');
    }
    
    private function showLoginForm(?string $error = null): void
    {
        $csrfToken = $this->security->generateCSRFToken();
        $this->session->set('csrf_token', $csrfToken);
        
        $pageTitle = 'Авторизация в панели Администрирования';
        
        include $this->getLoginTemplate($pageTitle, $error, $csrfToken);
        exit;
    }
    
    private function getLoginTemplate(string $pageTitle, ?string $error, string $csrfToken): string
    {
        return __DIR__ . '/../template/login.php';
    }
    
    public function getAdminMenuModules(): array
    {
        $modules = [];
        $modulePaths = glob(ROOT . '/modules/*', GLOB_ONLYDIR) ?: [];
        
        foreach ($modulePaths as $modulePath) {
            $infoFile = $modulePath . '/info.php';
            
            if (!file_exists($infoFile)) {
                continue;
            }
            
            $moduleInfo = $this->loadModuleInfo($infoFile);
            
            if (!empty($moduleInfo)) {
                $moduleName = basename($modulePath);
                $modules[$moduleName] = $moduleInfo;
            }
        }
        
        return $modules;
    }
    
    private function loadModuleInfo(string $infoFile): ?array
    {
        // Safely load module info
        $menuInfo = null;
        
        // Use output buffering to capture any output from included file
        ob_start();
        $result = include $infoFile;
        ob_end_clean();
        
        return is_array($menuInfo) ? $menuInfo : null;
    }
    
    private function isInstalled(): bool
    {
        return file_exists(ROOT . '/config/installed.lock');
    }
    
    private function redirect(string $url): never
    {
        if (!headers_sent()) {
            header("Location: {$url}", true, 302);
        } else {
            echo "<script>window.location.href='{$url}';</script>";
        }
        exit;
    }
}

// Initialize and run admin bootstrap
try {
    $adminBootstrap = new AdminBootstrap(
        $Register['DB'],
        $Register['ACL'], 
        new Session(),
        new Security(),
        new Config()
    );
    
    $adminBootstrap->boot();
    
} catch (Throwable $e) {
    // Log error and show user-friendly message
    error_log("Admin bootstrap error: " . $e->getMessage());
    
    if ($e instanceof AuthenticationException) {
        // Show login form with error
        $adminBootstrap->showLoginForm($e->getMessage());
    } else {
        // Show generic error page
        http_response_code(500);
        include __DIR__ . '/../template/error.php';
    }
    exit;
}

/**
 * Helper function for backward compatibility
 * @deprecated Use AdminBootstrap::getAdminMenuModules() instead
 */
function getAdmFrontMenuParams(): array
{
    global $adminBootstrap;
    return $adminBootstrap?->getAdminMenuModules() ?? [];
}
