<?php
/*-----------------------------------------------\
|                                                 |
| @Author:       Andrey Brykin (Drunya)          |
| @Email:        drunyacoder@gmail.com           |
| @Site:         http://atomx.net                |
| @Version:      1.1                             |
| @Project:      CMS                             |
| @package       CMS AtomX                       |
| @subpackege    Admin Panel module              |
| @copyright     ©Andrey Brykin 2010-2013        |
\-----------------------------------------------*/

/*-----------------------------------------------\
|                                                 |
|  any partial or not partial extension          |
|  CMS AtomX,without the consent of the          |
|  author, is illegal                            |
|------------------------------------------------|
|  Любое распространение                         |
|  CMS AtomX или ее частей,                      |
|  без согласия автора, является не законным     |
\-----------------------------------------------*/

/**
* Repair forums, themes, messages count 
* Enhanced version with better error handling and performance
*/

// Security check - prevent direct access without admin auth
if (!defined('IN_ADMIN') || !defined('IN_SCRIPT')) {
    die('Access denied');
}

include_once '../sys/boot.php';
include_once ROOT . '/admin/inc/adm_boot.php';

// Check permissions
if (!$Auth->hasPermission('admin_panel', 'manage_forums')) {
    die(__('Access denied'));
}

// Set longer execution time and memory limit for large forums
@set_time_limit(600); // 10 minutes
@ini_set('memory_limit', '256M');

$errors = array();
$success = false;

try {
    // Start transaction for data consistency
    $FpsDB->query("START TRANSACTION");
    
    // Get all forums
    $forums = $FpsDB->select('forums', DB_ALL, array('order' => 'id ASC'));
    
    if (!empty($forums)) {
        $totalForums = count($forums);
        $processedForums = 0;
        $processedThemes = 0;
        
        foreach ($forums as $forum) {
            // Update themes count and posts count for each theme in this forum
            $themes = $FpsDB->select('themes', DB_ALL, array(
                'cond' => array('id_forum' => $forum['id']),
                'order' => 'id ASC'
            ));
            
            if (!empty($themes)) {
                foreach ($themes as $theme) {
                    // Count posts for this theme
                    $postsCount = $FpsDB->select('posts', DB_COUNT, array(
                        'cond' => array('id_theme' => $theme['id'])
                    ));
                    
                    // Update theme with correct post count
                    $updateResult = $FpsDB->update('themes', array(
                        'posts' => $postsCount
                    ), array('id' => $theme['id']));
                    
                    if (!$updateResult) {
                        $errors[] = sprintf(__('Failed to update theme %d'), $theme['id']);
                    }
                    
                    $processedThemes++;
                }
            }
            
            // Count themes for this forum
            $themesCount = $FpsDB->select('themes', DB_COUNT, array(
                'cond' => array('id_forum' => $forum['id'])
            ));
            
            // Sum posts for all themes in this forum
            $postsSum = $FpsDB->select('themes', DB_SUM, array(
                'field' => 'posts',
                'cond' => array('id_forum' => $forum['id'])
            ));
            
            // Update forum with correct counts
            $updateResult = $FpsDB->update('forums', array(
                'themes' => $themesCount,
                'posts' => $postsSum ? $postsSum : 0
            ), array('id' => $forum['id']));
            
            if (!$updateResult) {
                $errors[] = sprintf(__('Failed to update forum %d'), $forum['id']);
            }
            
            $processedForums++;
        }
        
        // Commit transaction if no errors
        if (empty($errors)) {
            $FpsDB->query("COMMIT");
            $success = true;
        } else {
            $FpsDB->query("ROLLBACK");
        }
    } else {
        // No forums to process
        $success = true;
    }
} catch (Exception $e) {
    // Rollback on any error
    $FpsDB->query("ROLLBACK");
    $errors[] = __('Database error: ') . $e->getMessage();
}

$pageTitle = __('Recalculation forum');
$pageNav = $pageTitle;
$pageNavr = '';

include_once ROOT . '/admin/template/header.php';
?>

<div class="content-box">
    <div class="box-body">
        <div class="box-header">
            <h2><?php echo $pageTitle; ?></h2>
        </div>
        
        <?php if ($success): ?>
            <div class="warning ok">
                <?php echo __('All done successfully!'); ?>
                <?php if (isset($totalForums)): ?>
                    <p><?php echo sprintf(__('Processed %d forums and %d themes'), $processedForums, $processedThemes); ?></p>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="warning error">
                <?php echo __('Errors occurred during the process:'); ?>
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo htmlspecialchars($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
        
        <div class="box-footer">
            <a href="index.php?module=forums" class="btn btn-primary"><?php echo __('Back to forums management'); ?></a>
        </div>
    </div>
</div>

<?php
include_once 'template/footer.php';
