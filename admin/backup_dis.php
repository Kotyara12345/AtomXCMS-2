<?php
/**
 * ==================================================
 * Backup Restoration Module - Admin Panel
 * ==================================================
 * 
 * @author    Andrey Brykin (Drunya)
 * @version   2.0
 * @project   CMS AtomX
 * @package   Admin Module
 * @subpackage Backup Management
 * @copyright © Andrey Brykin 2010-2024
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
if (!$ACL->turn(['panel', 'restricted_access_backup'], false)) {
    $_SESSION['errors'] = __('Permission denied');
    redirect('/admin/');
}

// Validate CSRF token
if (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
    $_SESSION['errors'] = __('Security token validation failed');
    redirect('/admin/backup.php');
}

$pageTitle = __('Backup Restoration');
$pageNav = __('Backup Management');
$pageNavr = '';

include_once ROOT . '/admin/template/header.php';

try {
    // Get template directory
    $template = Config::read('template');
    if (empty($template)) {
        throw new Exception(__('Template not configured'));
    }

    $templateDir = ROOT . '/template/' . $template;
    
    // Validate template directory
    if (!is_dir($templateDir) || !is_readable($templateDir)) {
        throw new Exception(__('Template directory not accessible'));
    }

    // Find backup files
    $backupFiles = [];
    $cssBackups = glob($templateDir . '/css/*.stand');
    $htmlBackups = glob($templateDir . '/html/*/*.stand');
    
    if ($cssBackups === false || $htmlBackups === false) {
        throw new Exception(__('Error scanning template directory'));
    }

    $backupFiles = array_merge($cssBackups, $htmlBackups);
    
    if (empty($backupFiles)) {
        $_SESSION['message'] = __('No backup files found');
        redirect('/admin/backup.php');
    }

    // Delete backup files with validation
    $deletedCount = 0;
    $errors = [];
    
    foreach ($backupFiles as $file) {
        // Validate file path to prevent directory traversal
        if (!is_file($file) || strpos(realpath($file), realpath($templateDir)) !== 0) {
            $errors[] = sprintf(__('Invalid file path: %s'), htmlspecialchars($file));
            continue;
        }
        
        // Check if file is readable and writable
        if (!is_readable($file) || !is_writable($file)) {
            $errors[] = sprintf(__('File permissions error: %s'), htmlspecialchars(basename($file)));
            continue;
        }
        
        // Delete the file
        if (@unlink($file)) {
            $deletedCount++;
        } else {
            $errors[] = sprintf(__('Failed to delete: %s'), htmlspecialchars(basename($file)));
        }
    }

    // Prepare result message
    if ($deletedCount > 0) {
        $_SESSION['message'] = sprintf(
            __('Backup restoration complete. Deleted %d backup files.'),
            $deletedCount
        );
    }
    
    if (!empty($errors)) {
        $_SESSION['errors'] = implode('<br>', $errors);
        
        // Log errors for debugging
        error_log('Backup restoration errors: ' . implode('; ', $errors));
    }

    // Clear template cache
    $Cache = new Cache();
    $cacheTags = ['template_' . $template, 'template_assets'];
    $Cache->clean(CACHE_MATCHING_ANY_TAG, $cacheTags);

} catch (Exception $e) {
    $_SESSION['errors'] = $e->getMessage();
    error_log('Backup restoration error: ' . $e->getMessage());
}

redirect('/admin/backup.php');

include_once ROOT . '/admin/template/footer.php';
