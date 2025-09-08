<?php
##################################################
##                                              ##
## Author:       Andrey Brykin (Drunya)         ##
## Version:      1.0                            ##
## Project:      CMS                            ##
## package       CMS AtomX                      ##
## subpackege    Admin Panel module             ##
## copyright     ©Andrey Brykin 2010-2011       ##
##################################################

##################################################
##                                              ##
## any partial or not partial extension         ##
## CMS AtomX,without the consent of the         ##
## author, is illegal                           ##
##################################################
## Любое распространение                        ##
## CMS AtomX или ее частей,                     ##
## без согласия автора, является не законным    ##
##################################################

// Security check - prevent direct access without admin auth
if (!defined('IN_ADMIN') || !defined('IN_SCRIPT')) {
    die('Access denied');
}

include_once '../sys/boot.php';
include_once ROOT . '/admin/inc/adm_boot.php';

// Check permissions
if (!$Auth->hasPermission('admin_panel', 'manage_plugins')) {
    die(__('Access denied'));
}

$Register = Register::getInstance();
$FpsDB = $Register['DB'];
$api_url = 'https://home.atomx.net/'; // Changed to HTTPS for security

// Validate and sanitize inputs
function validateUrl($url) {
    $url = filter_var($url, FILTER_SANITIZE_URL);
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return false;
    }
    
    // Restrict to certain protocols for security
    $allowed_protocols = array('http', 'https');
    $url_parts = parse_url($url);
    if (!in_array($url_parts['scheme'], $allowed_protocols)) {
        return false;
    }
    
    return $url;
}

function validateApiKey($key) {
    // Validate API key format (alphanumeric, hyphens, underscores)
    return preg_match('/^[a-zA-Z0-9_-]+$/', $key) ? $key : false;
}

function showError($message = null) {
    $Register = Register::getInstance();
    
    if ($message === null) {
        $errors = $Register['PluginController']->getErrors();
    } else {
        $errors = is_array($message) ? $message : array($message);
    }
    
    $_SESSION['message'] = '<div class="warning error">' . 
        implode('<br>', array_map('htmlspecialchars', $errors)) . 
        '</div>';
    
    redirect('/admin/get_plugins.php');
}

function showSuccess($message, $files = array()) {
    $html = '<div class="warning ok"><h2>' . htmlspecialchars($message) . '</h2>';
    
    if (!empty($files)) {
        $html .= '<strong>' . __('Files installed:') . '</strong><ul class="wps-list">';
        foreach ($files as $file) {
            $html .= '<li>' . htmlspecialchars($file) . '</li>';
        }
        $html .= '</ul>';
    }
    
    $html .= '</div>';
    
    $_SESSION['message'] = $html;
    redirect('/admin/get_plugins.php');
}

// Process plugin installation
try {
    // Handle local plugin upload
    if (!empty($_FILES['pl_file']['name']) && is_uploaded_file($_FILES['pl_file']['tmp_name'])) {
        // Validate file type
        $file_info = pathinfo($_FILES['pl_file']['name']);
        if (strtolower($file_info['extension'] !== 'zip')) {
            showError(__('File type should be ZIP'));
        }
        
        // Validate file size (max 10MB)
        if ($_FILES['pl_file']['size'] > 10 * 1024 * 1024) {
            showError(__('File size too large. Maximum 10MB allowed.'));
        }
        
        // Download plugin to tmp folder
        $filename = $Register['PluginController']->localUpload('pl_file');
        if (!$filename) {
            showError();
        }
        
        // Install plugin
        $result = $Register['PluginController']->install($filename);
        if (!$result) {
            showError();
        }
        
        $files = $Register['PluginController']->getFiles();
        showSuccess(__('Plugin installed successfully'), $files);
        
    // Handle remote plugin download
    } else if (!empty($_GET['api_key']) || !empty($_POST['pl_url'])) {
        $download_url = '';
        
        if (!empty($_GET['api_key'])) {
            $api_key = validateApiKey($_GET['api_key']);
            if (!$api_key) {
                showError(__('Invalid API key format'));
            }
            $download_url = $api_url . 'plugins/' . $api_key . '.zip';
            
        } else if (!empty($_POST['pl_url'])) {
            $download_url = validateUrl(trim($_POST['pl_url']));
            if (!$download_url) {
                showError(__('Invalid URL format'));
            }
        }
        
        // Validate the final URL
        if (!filter_var($download_url, FILTER_VALIDATE_URL)) {
            showError(__('Invalid download URL'));
        }
        
        // Download plugin to tmp folder
        $filename = $Register['PluginController']->foreignUpload($download_url);
        if (!$filename) {
            showError();
        }
        
        // Install plugin
        $result = $Register['PluginController']->install($filename);
        if (!$result) {
            showError();
        }
        
        $files = $Register['PluginController']->getFiles();
        showSuccess(__('Plugin installed successfully'), $files);
    }
    
} catch (Exception $e) {
    showError(__('An error occurred: ') . $e->getMessage());
}

$pageTitle = __('Plugin Management');
$pageNav = $pageTitle;
$pageNavr = '';

// Get installed plugins
$pl_url = ROOT . '/sys/plugins/*';
$our_plugins = glob($pl_url, GLOB_ONLYDIR);
$installed_plugins = array();

foreach ($our_plugins as $pl_path) {
    if (file_exists($pl_path . '/config.dat')) {
        $pl_conf = json_decode(file_get_contents($pl_path . '/config.dat'), true);
        if (!empty($pl_conf['title'])) {
            $installed_plugins[$pl_conf['title']] = array(
                'title' => $pl_conf['title'],
                'version' => $pl_conf['version'] ?? 'N/A',
                'path' => $pl_path
            );
        }
    }
}

// Get available plugins from API with error handling
$available_plugins = array();
$url = $api_url . 'plugins_api.php';

// Use cURL for better HTTP handling
if (function_exists('curl_init')) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'CMS AtomX Plugin Manager');
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    if ($http_code === 200 && $response) {
        $data = json_decode($response, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {
            $available_plugins = $data;
        }
    }
    curl_close($ch);
} else {
    // Fallback to file_get_contents with context
    $context = stream_context_create(array(
        'http' => array(
            'timeout' => 10,
            'user_agent' => 'CMS AtomX Plugin Manager'
        ),
        'ssl' => array(
            'verify_peer' => true,
            'verify_peer_name' => true
        )
    ));
    
    $response = @file_get_contents($url, false, $context);
    if ($response !== false) {
        $data = json_decode($response, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {
            $available_plugins = $data;
        }
    }
}

include 'template/header.php';

// Display messages from session
if (!empty($_SESSION['message'])) {
    echo $_SESSION['message'];
    unset($_SESSION['message']);
}
?>

<div class="content-box">
    <div class="box-body">
        <div class="box-header">
            <h2><?php echo $pageTitle; ?></h2>
        </div>

        <div class="warning">
            <?php echo __('Plugins instruction') ?>
        </div>

        <!-- Download foreign plugins -->
        <div id="sec" class="popup">
            <div class="top">
                <div class="title"><?php echo __('Download plugin') ?></div>
                <div onClick="closePopup('sec');" class="close"></div>
            </div>
            <form action="get_plugins.php" method="POST" enctype="multipart/form-data" onsubmit="return validatePluginForm(this);">
                <div class="items">
                    <div class="clear">&nbsp;</div>
                    <div class="item">
                        <div class="left">
                            <?php echo __('Plugin URL:'); ?>
                            <span class="comment"><?php echo __('Download plugin from remote server') ?></span>
                        </div>
                        <div class="right">
                            <input type="url" name="pl_url" placeholder="https://site.com/path/to/plugin.zip" 
                                   pattern="https?://.+" title="<?php echo __('Enter a valid HTTP/HTTPS URL'); ?>" />
                        </div>
                        <div class="clear"></div>
                    </div>
                    
                    <div class="item">
                        <div class="left">
                            <?php echo __('OR Upload file:'); ?>
                            <span class="comment"><?php echo __('Upload as local file (ZIP format, max 10MB)') ?></span>
                        </div>
                        <div class="right">
                            <input type="file" accept=".zip,application/zip" name="pl_file" 
                                   onchange="validateZipFile(this);" />
                        </div>
                        <div class="clear"></div>
                    </div>
                    
                    <div class="item submit">
                        <div class="left"></div>
                        <div style="float:left;" class="right">
                            <input type="submit" class="save-button" name="send" value="<?php echo __('Install') ?>">
                        </div>
                        <div class="clear"></div>
                    </div>
                </div>
            </form>
        </div>

        <!-- JavaScript validation -->
        <script type="text/javascript">
        function validateZipFile(input) {
            if (input.files && input.files[0]) {
                var file = input.files[0];
                var extension = file.name.split('.').pop().toLowerCase();
                
                if (extension !== 'zip') {
                    alert('<?php echo __('File type should be ZIP') ?>!');
                    input.value = '';
                    return false;
                }
                
                if (file.size > 10 * 1024 * 1024) {
                    alert('<?php echo __('File size too large. Maximum 10MB allowed.') ?>');
                    input.value = '';
                    return false;
                }
            }
            return true;
        }
        
        function validatePluginForm(form) {
            var url = form.pl_url.value.trim();
            var file = form.pl_file.value;
            
            if (!url && !file) {
                alert('<?php echo __('Please provide either a URL or select a file to upload.') ?>');
                return false;
            }
            
            if (url && file) {
                alert('<?php echo __('Please provide only one method: URL OR file upload.') ?>');
                return false;
            }
            
            return true;
        }
        </script>

        <?php if (!empty($available_plugins)): ?>
        <!-- Available plugins from official server -->                            
        <div class="list">
            <div class="title"><?php echo __('Available Plugins') ?></div>
            <div class="add-cat-butt" onClick="openPopup('sec');">
                <div class="add"></div><?php echo __('Install Plugin') ?>
            </div>
            
            <div class="level1">
                <div class="items" id="plugins">
                <?php foreach ($available_plugins as $row): 
                    $is_installed = isset($installed_plugins[$row['title']]);
                    ?>
                    <div class="setting-item">
                        <div class="left">
                        <?php if (!empty($row['img']) && filter_var($row['img'], FILTER_VALIDATE_URL)): ?>
                            <img class="pl-preview" src="<?php echo htmlspecialchars($row['img']); ?>" 
                                 alt="<?php echo htmlspecialchars($row['title']); ?>" 
                                 onerror="this.style.display='none';" />
                        <?php else: ?>
                            <div class="no-image"><?php echo __('No preview'); ?></div>
                        <?php endif; ?>
                        </div>
                        
                        <div class="right plugin-info">
                            <h3><?php echo htmlspecialchars($row['title']); ?></h3>
                            <div class="plugin-description">
                                <?php echo nl2br(htmlspecialchars($row['description'] ?? '')); ?>
                            </div>
                            
                            <?php if (!empty($row['version'])): ?>
                                <div class="plugin-version">
                                    <strong><?php echo __('Version:'); ?></strong> 
                                    <?php echo htmlspecialchars($row['version']); ?>
                                </div>
                            <?php endif; ?>
                            
                            <div class="r-but-container">
                            <?php if ($is_installed): ?>
                                <span class="status-installed">
                                    <?php echo __('Installed'); ?>
                                    <?php if (!empty($installed_plugins[$row['title']]['version'])): ?>
                                        (v<?php echo htmlspecialchars($installed_plugins[$row['title']]['version']); ?>)
                                    <?php endif; ?>
                                </span>
                            <?php else: ?>
                                <a href="<?php echo WWW_ROOT; ?>/admin/get_plugins.php?api_key=<?php echo urlencode($row['url']); ?>" 
                                   class="install-button">
                                    <?php echo __('Install'); ?>
                                </a>
                            <?php endif; ?>
                            </div>
                        </div>
                        <div class="clear"></div>
                    </div>
                <?php endforeach; ?>    
                </div>
            </div>
        </div>
        
        <?php else: ?>
        <div class="warning error">
            <?php echo __('Unable to fetch plugins list from the server. Please check your internet connection or try again later.'); ?>
        </div>
        <?php endif; ?>
        
        <!-- Installed plugins section -->
        <?php if (!empty($installed_plugins)): ?>
        <div class="list">
            <div class="title"><?php echo __('Installed Plugins'); ?></div>
            <div class="level1">
                <div class="items">
                <?php foreach ($installed_plugins as $plugin): ?>
                    <div class="setting-item">
                        <div class="left">
                            <div class="plugin-icon">📦</div>
                        </div>
                        <div class="right">
                            <h3><?php echo htmlspecialchars($plugin['title']); ?></h3>
                            <div class="plugin-version">
                                <strong><?php echo __('Version:'); ?></strong> 
                                <?php echo htmlspecialchars($plugin['version']); ?>
                            </div>
                            <div class="plugin-path">
                                <strong><?php echo __('Path:'); ?></strong> 
                                <?php echo htmlspecialchars($plugin['path']); ?>
                            </div>
                        </div>
                        <div class="clear"></div>
                    </div>
                <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php
include_once 'template/footer.php';
