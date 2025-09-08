<?php
/**
 * ==================================================
 * Footer template for CMS AtomX Admin Panel
 * ==================================================
 * 
 * @author    Andrey Brykin (Drunya)
 * @version   1.0
 * @project   CMS AtomX
 * @package   Admin Module
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

// Check if we're in development mode
$isDev = ($_ENV['APP_ENV'] ?? 'production') === 'development';

// Get current year
$currentYear = date('Y');
$startYear = 2010;
$yearRange = ($currentYear > $startYear) ? "{$startYear}-{$currentYear}" : $currentYear;
?>

</main>

<footer class="footer" role="contentinfo">
    <div class="footer-content">
        <p class="footer-copyright">
            &copy; AtomX CMS — <?= htmlspecialchars($yearRange) ?>. 
            <span class="footer-rights">All rights reserved</span>
        </p>
        
        <?php if ($isDev): ?>
        <div class="footer-debug">
            <small>
                Page generated in: <?= round(microtime(true) - $_SERVER['REQUEST_TIME_FLOAT'], 4) ?>s | 
                Memory: <?= round(memory_get_peak_usage() / 1024 / 1024, 2) ?>MB
            </small>
        </div>
        <?php endif; ?>
    </div>
</footer>

<div id="overlay" class="overlay" aria-hidden="true" role="presentation"></div>

<?php // Session messages and notifications ?>
<?php if (!empty($_SESSION['message'])): ?>
    <div class="notification notification-success" role="alert" aria-live="polite">
        <button type="button" class="notification-close" aria-label="Close notification">
            &times;
        </button>
        <?= htmlspecialchars($_SESSION['message']) ?>
    </div>
    <?php unset($_SESSION['message']); ?>
<?php endif; ?>

<?php if (!empty($_SESSION['errors'])): ?>
    <div class="notification notification-error" role="alert" aria-live="assertive">
        <button type="button" class="notification-close" aria-label="Close notification">
            &times;
        </button>
        <?= is_array($_SESSION['errors']) ? implode('<br>', array_map('htmlspecialchars', $_SESSION['errors'])) : htmlspecialchars($_SESSION['errors']) ?>
    </div>
    <?php unset($_SESSION['errors']); ?>
<?php endif; ?>

<style>
/* Footer Styles */
.footer {
    width: 94%;
    color: #a1a1a1;
    font-size: 13px;
    font-style: italic;
    text-align: center;
    padding: 30px 0;
    position: relative;
    z-index: 0;
    background: linear-gradient(to top, #1a1a1a, #2d2d2d);
    border-top: 1px solid #333;
    margin-top: auto;
}

.footer-content {
    max-width: 1200px;
    margin: 0 auto;
    display: flex;
    flex-direction: column;
    gap: 10px;
    padding: 0 15px;
}

.footer-copyright {
    margin: 0;
    line-height: 1.4;
    color: #a1a1a1;
}

.footer-rights {
    opacity: 0.8;
}

.footer-debug {
    margin-top: 10px;
    padding: 5px;
    background: rgba(255, 255, 255, 0.1);
    border-radius: 3px;
    font-family: monospace;
    font-size: 11px;
}

/* Notification Styles */
.notification {
    position: fixed;
    bottom: 20px;
    right: 20px;
    padding: 15px;
    border-radius: 4px;
    color: white;
    z-index: 1000;
    max-width: 400px;
    box-shadow: 0 0 15px rgba(0, 0, 0, 0.8);
    animation: notificationSlideIn 0.3s ease;
    font-size: 14px;
    line-height: 1.4;
}

.notification-success {
    background: #96c71e;
    border-left: 4px solid #2d521c;
}

.notification-error {
    background: #f10000;
    border-left: 4px solid #8b0000;
}

.notification-close {
    background: none;
    border: none;
    color: inherit;
    font-size: 18px;
    cursor: pointer;
    float: right;
    margin-left: 10px;
    line-height: 1;
    padding: 0;
    width: 20px;
    height: 20px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.notification-close:hover {
    opacity: 0.8;
}

@keyframes notificationSlideIn {
    from {
        transform: translateY(100px);
        opacity: 0;
    }
    to {
        transform: translateY(0);
        opacity: 1;
    }
}

/* Overlay Styles */
.overlay {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.5);
    z-index: 999;
    display: none;
}

.overlay[aria-hidden="false"] {
    display: block;
}

/* Responsive Styles */
@media screen and (max-width: 768px) {
    .footer {
        padding: 20px 10px;
        font-size: 12px;
    }
    
    .notification {
        left: 15px;
        right: 15px;
        max-width: none;
        bottom: 15px;
    }
}

@media (prefers-reduced-motion: reduce) {
    .notification {
        animation: none;
    }
}
</style>

<script>
// Notification close functionality
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.notification-close').forEach(function(button) {
        button.addEventListener('click', function() {
            this.parentElement.style.display = 'none';
        });
    });
    
    // Auto-hide notifications after 5 seconds
    setTimeout(function() {
        document.querySelectorAll('.notification').forEach(function(notification) {
            notification.style.display = 'none';
        });
    }, 5000);
});

// Overlay control
function showOverlay() {
    document.getElementById('overlay').setAttribute('aria-hidden', 'false');
}

function hideOverlay() {
    document.getElementById('overlay').setAttribute('aria-hidden', 'true');
}
</script>

</body>
</html>
