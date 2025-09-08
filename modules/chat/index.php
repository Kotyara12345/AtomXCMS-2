<?php
declare(strict_types=1);

/**
 * Chat Module for AtomX CMS
 * 
 * Modernized for PHP 8.1+ with security and performance improvements
 */

class ChatModule extends Module
{
    public string $template = 'chat';
    public string $module_title = 'Чат';
    public string $module = 'chat';
    
    private const MAX_MESSAGES = 50;
    private const MESSAGES_FILE = '/sys/tmp/chat/messages.dat';
    
    /**
     * Default action - show chat interface
     */
    public function index(): string
    {
        if (!$this->ACL->turn(['chat', 'add_materials'], false)) {
            return $this->_view(__('Permission denied'));
        }
        
        $content = $this->addForm();
        return $this->_view($content);
    }
    
    /**
     * Display chat messages (AJAX endpoint)
     */
    public function viewMessages(): void
    {
        try {
            $messages = $this->getMessages();
            $content = $this->renderMessagesList($messages);
            
            $this->showAjaxResponse(['result' => $content]);
            
        } catch (Exception $e) {
            error_log('Chat view error: ' . $e->getMessage());
            $this->showAjaxResponse(['error' => __('Failed to load messages')]);
        }
    }
    
    /**
     * Add new chat message (AJAX endpoint)
     */
    public function add(): void
    {
        try {
            $this->validateAddPermission();
            $this->validateCsrfToken();
            
            $messageData = $this->validateAndSanitizeInput();
            $this->saveMessage($messageData);
            
            $this->sendJsonResponse(['status' => 'success', 'message' => 'ok']);
            
        } catch (RuntimeException $e) {
            $this->sendJsonResponse(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }
    
    /**
     * Display add message form
     */
    public static function addForm(): string
    {
        $register = Register::getInstance();
        $acl = $register['ACL'];
        
        if (!$acl->turn(['chat', 'add_materials'], false)) {
            return __('Dont have permission to write post');
        }
        
        $markers = self::prepareFormMarkers();
        $view = $register['Viewer'];
        $view->setLayout('chat');
        
        return $view->view('addform.html', ['data' => $markers]);
    }
    
    /**
     * Get validation rules for actions
     */
    protected function _getValidateRules(): array
    {
        return [
            'add' => [
                'message' => [
                    'required' => true,
                    'max_length' => Config::read('max_lenght', 'chat') ?? 500,
                ],
                'keystring' => [
                    'required' => !$this->ACL->turn(['other', 'no_captcha'], false),
                    'pattern' => V_CAPTCHA,
                    'title' => 'Keystring (captcha)',
                ],
            ],
        ];
    }
    
    /**
     * Retrieve and process chat messages
     */
    private function getMessages(): array
    {
        $filePath = ROOT . self::MESSAGES_FILE;
        
        if (!file_exists($filePath)) {
            return [];
        }
        
        $content = file_get_contents($filePath);
        if ($content === false) {
            throw new RuntimeException('Failed to read messages file');
        }
        
        $data = unserialize($content);
        if (!is_array($data)) {
            return [];
        }
        
        return array_reverse($data);
    }
    
    /**
     * Render messages list with proper sanitization
     */
    private function renderMessagesList(array $messages): string
    {
        foreach ($messages as &$message) {
            $message = $this->processMessage($message);
        }
        
        return $this->render('list.html', ['messages' => $messages]);
    }
    
    /**
     * Process individual message with sanitization
     */
    private function processMessage(array $message): array
    {
        $message['message'] = $this->sanitizeMessage($message['message']);
        
        // Show IP only for admins
        if ($this->ACL->turn(['chat', 'delete_materials'], false)) {
            $message['ip'] = $this->formatIpLink($message['ip'] ?? '');
        } else {
            $message['ip'] = '';
        }
        
        return $message;
    }
    
    /**
     * Sanitize and format message content
     */
    private function sanitizeMessage(string $message): string
    {
        $message = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
        
        $printText = $this->Register['PrintText'];
        $message = $printText->smile($message);
        $message = $printText->parseUrlBb($message);
        $message = $printText->parseBBb($message);
        $message = $printText->parseUBb($message);
        $message = $printText->parseSBb($message);
        
        return $message;
    }
    
    /**
     * Format IP address link for admins
     */
    private function formatIpLink(string $ip): string
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return '';
        }
        
        $escapedIp = htmlspecialchars($ip, ENT_QUOTES, 'UTF-8');
        $url = 'https://apps.db.ripe.net/search/query.html?searchtext=' . urlencode($ip);
        
        return sprintf(
            '<noindex><a rel="nofollow" target="_blank" href="%s" class="fps-ip" title="IP: %s"></a></noindex>',
            $url,
            $escapedIp
        );
    }
    
    /**
     * Validate user permissions for adding messages
     */
    private function validateAddPermission(): void
    {
        if (!$this->ACL->turn(['chat', 'add_materials'], false)) {
            throw new RuntimeException(__('Permission denied'));
        }
    }
    
    /**
     * Validate CSRF token
     */
    private function validateCsrfToken(): void
    {
        $expectedToken = $_SESSION['csrf_token'] ?? '';
        $providedToken = $_POST['csrf_token'] ?? '';
        
        if (empty($expectedToken) || !hash_equals($expectedToken, $providedToken)) {
            throw new RuntimeException(__('Security token validation failed'));
        }
    }
    
    /**
     * Validate and sanitize input data
     */
    private function validateAndSanitizeInput(): array
    {
        $name = $this->getUserName();
        $message = trim($_POST['message'] ?? '');
        $keystring = trim($_POST['keystring'] ?? '');
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        
        // Validate input
        $errors = $this->validateInput($name, $message, $keystring);
        
        if (!empty($errors)) {
            $this->storeFormData($name, $message);
            throw new RuntimeException(implode("\n", $errors));
        }
        
        return [
            'name' => $name,
            'message' => $message,
            'ip' => $ip,
            'date' => date('Y-m-d H:i')
        ];
    }
    
    /**
     * Get user name from session
     */
    private function getUserName(): string
    {
        if (!empty($_SESSION['user']['name'])) {
            return htmlspecialchars(trim($_SESSION['user']['name']), ENT_QUOTES, 'UTF-8');
        }
        
        return __('Guest');
    }
    
    /**
     * Validate input data
     */
    private function validateInput(string $name, string $message, string $keystring): array
    {
        $errors = [];
        $validator = $this->Register['Validate'];
        
        // Validate name
        if (!empty($name) && !$validator->cha_val($name, V_TITLE)) {
            $errors[] = __('Wrong chars in field "login"');
        }
        
        // Validate captcha if required
        if (!$this->ACL->turn(['other', 'no_captcha'], false)) {
            if (!$this->Register['Protector']->checkCaptcha('chatsend', $keystring)) {
                $errors[] = __('Wrong protection code');
            }
            $this->Register['Protector']->cleanCaptcha('chatsend');
        }
        
        // Validate message using framework rules
        $validationErrors = $this->Register['Validate']->check($this->Register['action']);
        $errors = array_merge($errors, $validationErrors);
        
        return $errors;
    }
    
    /**
     * Store form data in session for error display
     */
    private function storeFormData(string $name, string $message): void
    {
        $_SESSION['addForm'] = [
            'name' => $name,
            'message' => $message
        ];
    }
    
    /**
     * Save message to storage
     */
    private function saveMessage(array $messageData): void
    {
        $messages = $this->getMessages();
        $messages = array_reverse($messages); // Convert back to chronological order
        
        // Add new message
        $messages[] = $messageData;
        
        // Limit messages count
        if (count($messages) > self::MAX_MESSAGES) {
            $messages = array_slice($messages, -self::MAX_MESSAGES);
        }
        
        $this->persistMessages($messages);
        $_SESSION['chat_name'] = $messageData['name'];
    }
    
    /**
     * Persist messages to file
     */
    private function persistMessages(array $messages): void
    {
        $dirPath = dirname(ROOT . self::MESSAGES_FILE);
        
        // Create directory if not exists
        if (!file_exists($dirPath)) {
            if (!mkdir($dirPath, 0755, true) && !is_dir($dirPath)) {
                throw new RuntimeException('Failed to create chat directory');
            }
        }
        
        // Write messages to file
        $result = file_put_contents(
            ROOT . self::MESSAGES_FILE,
            serialize($messages),
            LOCK_EX
        );
        
        if ($result === false) {
            throw new RuntimeException('Failed to save message');
        }
    }
    
    /**
     * Prepare form markers for rendering
     */
    private static function prepareFormMarkers(): array
    {
        $register = Register::getInstance();
        $markers = [
            'action' => get_url('/chat/add/'),
            'login' => self::getStoredUserName(),
            'message' => self::getStoredMessage(),
            'csrf_token' => self::generateCsrfToken()
        ];
        
        // Add captcha if required
        if (!$register['ACL']->turn(['other', 'no_captcha'], false)) {
            list($captcha, $captchaText) = $register['Protector']->getCaptcha('chatsend');
            $markers['captcha'] = $captcha;
            $markers['captcha_text'] = $captchaText;
        }
        
        return $markers;
    }
    
    /**
     * Get stored user name from session
     */
    private static function getStoredUserName(): string
    {
        if (isset($_SESSION['addForm']['name'])) {
            return htmlspecialchars($_SESSION['addForm']['name'], ENT_QUOTES, 'UTF-8');
        }
        
        if (isset($_SESSION['chat_name'])) {
            return htmlspecialchars($_SESSION['chat_name'], ENT_QUOTES, 'UTF-8');
        }
        
        if (!empty($_SESSION['user']['name'])) {
            return htmlspecialchars($_SESSION['user']['name'], ENT_QUOTES, 'UTF-8');
        }
        
        return '';
    }
    
    /**
     * Get stored message from session
     */
    private static function getStoredMessage(): string
    {
        if (isset($_SESSION['addForm']['message'])) {
            return htmlspecialchars($_SESSION['addForm']['message'], ENT_QUOTES, 'UTF-8');
        }
        
        return '';
    }
    
    /**
     * Generate CSRF token
     */
    private static function generateCsrfToken(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        
        return $_SESSION['csrf_token'];
    }
    
    /**
     * Send JSON response
     */
    private function sendJsonResponse(array $data): void
    {
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }
}
