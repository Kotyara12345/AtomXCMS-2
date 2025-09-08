<?php
declare(strict_types=1);

/**
 * AtomX CMS Installation - Step 2: Database Creation
 * Modernized for PHP 8.1+ with security and performance improvements
 */

// Initialize session and configuration
require_once '../sys/boot.php';

// Security check - ensure we have valid session data from step 1
session_start();
if (!isset($_SESSION['db_config']) || !isset($_SESSION['admin_config'])) {
    header('Location: step1.php');
    exit;
}

// Security headers
header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');

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
    <title>AtomX CMS - Создание базы данных</title>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    
    <link rel="shortcut icon" href="../sys/img/favicon.ico" type="image/x-icon">
    <link rel="stylesheet" href="css/style.css">
    
    <script src="../sys/js/jquery.js" defer></script>
    <script src="js/installation.js" defer></script>
</head>
<body>
    <?php include 'partials/header.php'; ?>
    
    <main class="main-content">
        <div class="container">
            <div class="installation-section">
                <h2>Создание базы данных</h2>
                <p class="installation-description">
                    Происходит создание таблиц базы данных и начальная настройка системы.
                    Это может занять несколько минут.
                </p>
                
                <!-- Progress indicator -->
                <div class="progress-container">
                    <div class="progress-bar" role="progressbar" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100">
                        <div class="progress-bar-fill" style="width: 0%"></div>
                    </div>
                    <div class="progress-text">
                        <span class="progress-percentage">0%</span>
                        <span class="progress-status">Подготовка...</span>
                    </div>
                </div>
                
                <!-- Real-time log output -->
                <div class="log-container">
                    <h4>Журнал установки:</h4>
                    <div class="log-output" id="installationLog">
                        <div class="log-entry log-entry-info">Начало процесса установки...</div>
                    </div>
                </div>
                
                <!-- Results display -->
                <div class="results-container" id="installationResults" style="display: none;">
                    <div class="result-message" id="resultMessage"></div>
                    <div class="action-buttons" id="actionButtons" style="display: none;">
                        <a href="step3.php" class="btn btn-success" id="nextButton">Продолжить</a>
                        <button class="btn btn-secondary" onclick="location.reload()">Повторить</button>
                    </div>
                </div>
            </div>
        </div>
    </main>
    
    <?php include 'partials/footer.php'; ?>
    
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Initialize progress bar
        updateProgressBar(2);
        
        // Start installation process
        startInstallation();
    });
    </script>
</body>
</html>
