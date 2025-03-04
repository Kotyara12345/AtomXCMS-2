<?php
declare(strict_types=1);

/**
 * AtomX CMS Admin Module
 *
 * @author    Andrey Brykin (Drunya)
 * @version   0.7
 * @project   CMS AtomX
 * @package   Admin Module
 * @copyright ©Andrey Brykin 2010-2011
 *
 * Любое распространение CMS AtomX или ее частей
 * без согласия автора является незаконным.
 */
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Админ-панель CMS AtomX">
    <meta name="keywords" content="CMS, AtomX, Admin">
    <title>Админ-панель AtomX</title>
    <link rel="stylesheet" href="/assets/css/admin.css">
</head>
<body>
    <div class="admin-container">
        <header class="admin-header">
            <h1>Админ-панель AtomX</h1>
        </header>

        <main class="admin-content">
            <div class="content-wrapper">
                <!-- Основное содержимое страницы -->
                <div class="clearfix"></div>
            </div>
        </main>

        <footer class="admin-footer">
            <p>&copy; AtomX — <?= htmlspecialchars(date("Y"), ENT_QUOTES, 'UTF-8') ?>. Все права защищены.</p>
        </footer>
    </div>

    <div id="overlay" class="overlay" aria-hidden="true"></div>

    <script src="/assets/js/admin.js"></script>
</body>
</html>
