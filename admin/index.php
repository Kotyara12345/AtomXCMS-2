<?php
##################################################
##                                              ##
## Author:       Andrey Brykin (Drunya)         ##
## Version:      1.1                            ##
## Project:      CMS                            ##
## package       CMS AtomX                      ##
## subpackege    Admin Panel module             ##
## copyright     ©Andrey Brykin 2010-2014       ##
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

// Проверка безопасности - предотвращение прямого доступа
if (!defined('IN_ADMIN') || !defined('IN_SCRIPT')) {
    die('Access denied');
}

include_once '../sys/boot.php';
include_once ROOT . '/admin/inc/adm_boot.php';

// Проверка прав доступа
if (!$Auth->hasPermission('admin_panel', 'view_dashboard')) {
    die(__('Access denied'));
}

$Register = Register::getInstance();
$FpsDB = $Register['DB'];

$pageTitle = __('Admin Panel');
$pageNav = $pageTitle . __(' - General information');
$pageNavr = '';

// Получение статистики с обработкой ошибок
try {
    $cnt_usrs = $FpsDB->select('users', DB_COUNT);

    $groups_info = array();
    $users_groups = $ACL->get_group_info();
    if (!empty($users_groups)) {
        foreach ($users_groups as $key => $group) {
            if ($key === 0) {
                $groups_info[0] = null;
                continue;
            }
            $groups_info[$group['title']] = $FpsDB->select('users', DB_COUNT, array('cond' => array('status' => $key)));
        }
    }

    // Статистика материалов
    $cnt_for = $FpsDB->select('themes', DB_COUNT);
    $cnt_news = $FpsDB->select('news', DB_COUNT);
    $cnt_premoder_news = $FpsDB->select('news', DB_COUNT, array('cond' => array('premoder' => 'nochecked')));
    $cnt_premoder_news_comments = $FpsDB->select('news_comments', DB_COUNT, array('cond' => array('premoder' => 'nochecked')));
    $cnt_loads = $FpsDB->select('loads', DB_COUNT);
    $cnt_premoder_loads = $FpsDB->select('loads', DB_COUNT, array('cond' => array('premoder' => 'nochecked')));
    $cnt_premoder_loads_comments = $FpsDB->select('loads_comments', DB_COUNT, array('cond' => array('premoder' => 'nochecked')));
    $cnt_stat = $FpsDB->select('stat', DB_COUNT);
    $cnt_premoder_stat = $FpsDB->select('stat', DB_COUNT, array('cond' => array('premoder' => 'nochecked')));
    $cnt_premoder_stat_comments = $FpsDB->select('stat_comments', DB_COUNT, array('cond' => array('premoder' => 'nochecked')));
    $cnt_foto = $FpsDB->select('foto', DB_COUNT);
    $cnt_foto_comments = $FpsDB->select('foto_comments', DB_COUNT, array('cond' => array('premoder' => 'nochecked')));
    $cnt_mat = $cnt_news + $cnt_for + $cnt_loads + $cnt_stat + $cnt_foto;

    // Статистика посещений
    $all_hosts = $FpsDB->query("
        SELECT 
        SUM(`views`) as hits_cnt 
        , SUM(ips) as hosts_cnt
        , (SELECT SUM(`views`) FROM `" . $FpsDB->getFullTableName('statistics') . "` WHERE `date` = '" . date("Y-m-d") . "') as today_hits
        , (SELECT ips FROM `" . $FpsDB->getFullTableName('statistics') . "` WHERE `date` = '" . date("Y-m-d") . "') as today_hosts
        FROM `" . $FpsDB->getFullTableName('statistics') . "`");

    $tmp_datafile = ROOT . '/sys/logs/counter/' . date("Y-m-d") . '.dat';

    if (file_exists($tmp_datafile) && is_readable($tmp_datafile)) {
        $stats = unserialize(file_get_contents($tmp_datafile));
        $today_hits = $stats['views'] ?? 0;
        $today_hosts = $stats['cookie'] ?? 0;
    } else {
        $today_hits = 0;
        $today_hosts = 0;
    }
    
    $all_hosts[0]['hits_cnt'] += $today_hits;
    $all_hosts[0]['hosts_cnt'] += $today_hosts;

} catch (Exception $e) {
    // Логирование ошибок вместо показа пользователю
    error_log('Admin dashboard error: ' . $e->getMessage());
    $error_message = __('Error loading statistics');
}

// Получение списка модулей
$modules = glob(ROOT . '/modules/*', GLOB_ONLYDIR);
$module_list = array();
foreach ($modules as $modul) {
    if (preg_match('#/(\w+)$#i', $modul, $modul_name)) {
        if (is_dir($modul)) {
            $module_list[] = $modul_name[1];
        }
    }
}

include 'template/header.php';

// Отображение ошибок, если есть
if (!empty($error_message)) {
    echo '<div class="warning error">' . htmlspecialchars($error_message) . '</div>';
}
?>

<script type="text/javascript">
    $(document).ready(function() {
        var resizeHandler = function() {
            $('.atm-flex-box .atm-flex-child > .list').each(function(index, element) {
                $(element).css('height', function() {
                    return $(element).parent().height()
                        - ($(element).outerHeight(true) - $(element).innerHeight());
                });
            });

            $('.atm-flex-box .atm-flex-child > .list').css('height', '100%');
        };
        
        $(window).resize(resizeHandler);
        resizeHandler();
        
        // Обновление статистики каждые 5 минут
        setInterval(function() {
            $.get('?ajax=update_stats', function(data) {
                if (data.success) {
                    // Можно обновить отдельные элементы статистики
                    console.log('Stats updated');
                }
            });
        }, 300000);
    });
</script>

<div class="atm-flex-box">
    <div class="atm-flex-child">
        <!--************ GENERAL **********-->                            
        <div class="list">
            <div class="title"><?php echo __('Common settings') ?></div>
            <div class="level1">
                <div class="head">
                    <div class="title settings"><?php echo __('Name'); ?></div>
                    <div class="title-r"><?php echo __('Value'); ?></div>
                    <div class="clear"></div>
                </div>
                <div class="items">
                    <div class="setting-item">
                        <div class="left">
                            <?php echo __('Current domain'); ?>
                            <span class="comment"><?php echo __('Domain is your site address'); ?></span>
                        </div>
                        <div class="right"><?php echo 'http://' . htmlspecialchars($_SERVER['HTTP_HOST']) . '/' ?></div>
                        <div class="clear"></div>
                    </div>
                    <div class="setting-item">
                        <div class="left">
                            <?php echo __('SQL inj state'); ?>
                            <span class="comment"><?php echo __('Is the control of SQL inj'); ?></span>
                        </div>
                        <div class="right"><div class="<?php echo (Config::read('antisql', 'secure') == 1) ? 'yes' : 'no' ?>"></div></div>
                        <div class="clear"></div>
                    </div>
                    <div class="setting-item">
                        <div class="left">
                            <?php echo __('Anti DDOS protection'); ?>
                            <span class="comment"><?php echo __('Is the enable Anti DDOS'); ?></span>
                        </div>
                        <div class="right"><div class="<?php echo (Config::read('anti_ddos', 'secure') == 1) ? 'yes' : 'no' ?>"></div></div>
                        <div class="clear"></div>
                    </div>
                    <div class="setting-item">
                        <div class="left">
                            <?php echo __('Cache'); ?>
                            <span class="comment"><?php echo __('The site will run faster'); ?></span>
                        </div>
                        <div class="right"><div class="<?php echo (Config::read('cache') == 1) ? 'yes' : 'no' ?>"></div></div>
                        <div class="clear"></div>
                    </div>
                    <div class="setting-item">
                        <div class="left">
                            <?php echo __('SQL cache'); ?>
                            <span class="comment"><?php echo __('SQL. Site will be run faster'); ?></span>
                        </div>
                        <div class="right"><div class="<?php echo (Config::read('cache_querys') == 1) ? 'yes' : 'no' ?>"></div></div>
                        <div class="clear"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="atm-flex-child">
        <!--************ MATERIALS **********-->                            
        <div class="list">
            <div class="title"><?php echo __('Materials') ?></div>
            <div class="level1">
                <div class="head">
                    <div class="title settings"><?php echo __('Material') ?></div>
                    <div class="title-r"><?php echo __('Quantity') . ' / ' . __('Pending moderation materials') . ' / ' . __('Comments') ?></div>
                    <div class="clear"></div>
                </div>
                <div class="items">
                    <div class="setting-item">
                        <div class="left">
                            <?php echo __('Total materials') ?>
                        </div>
                        <div class="right"><?php echo htmlspecialchars($cnt_mat) ?></div>
                        <div class="clear"></div>
                    </div>
                    <div class="setting-item">
                        <div class="left">
                            <?php echo __('News') ?>
                        </div>
                        <div class="right">
                            <?php echo htmlspecialchars($cnt_news) ?> / 
                            <span class="red"><?php echo htmlspecialchars($cnt_premoder_news) ?></span> / 
                            <span class="green"><?php echo htmlspecialchars($cnt_premoder_news_comments) ?></span>
                        </div>
                        <div class="clear"></div>
                    </div>
                    <div class="setting-item">
                        <div class="left">
                            <?php echo __('Loads') ?>
                        </div>
                        <div class="right">
                            <?php echo htmlspecialchars($cnt_loads) ?> / 
                            <span class="red"><?php echo htmlspecialchars($cnt_premoder_loads) ?></span> / 
                            <span class="green"><?php echo htmlspecialchars($cnt_premoder_loads_comments) ?></span>
                        </div>
                        <div class="clear"></div>
                    </div>
                    <div class="setting-item">
                        <div class="left">
                            <?php echo __('Stat') ?>
                        </div>
                        <div class="right">
                            <?php echo htmlspecialchars($cnt_stat) ?> / 
                            <span class="red"><?php echo htmlspecialchars($cnt_premoder_stat) ?></span> / 
                            <span class="green"><?php echo htmlspecialchars($cnt_premoder_stat_comments) ?></span>
                        </div>
                        <div class="clear"></div>
                    </div>
                    <div class="setting-item">
                        <div class="left">
                            <?php echo __('Foto') ?>
                        </div>
                        <div class="right">
                            <?php echo htmlspecialchars($cnt_foto) ?> / 
                            <span class="green"><?php echo htmlspecialchars($cnt_foto_comments) ?></span>
                        </div>
                        <div class="clear"></div>
                    </div>
                    <div class="setting-item">
                        <div class="left">
                            <?php echo __('Forum topics') ?>
                        </div>
                        <div class="right"><?php echo htmlspecialchars($cnt_for) ?></div>
                        <div class="clear"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="atm-flex-child">
        <!--************ USERS **********-->                            
        <div class="list">
            <div class="title"><?php echo __('Users') ?></div>
            <div class="level1">
                <div class="head">
                    <div class="title settings"><?php echo __('Group') ?></div>
                    <div class="title-r"><?php echo __('Quantity') ?></div>
                    <div class="clear"></div>
                </div>
                <div class="items">
                    <div class="setting-item">
                        <div class="left">
                            <?php echo __('All users') ?>
                        </div>
                        <div class="right"><?php echo htmlspecialchars($cnt_usrs) ?></div>
                        <div class="clear"></div>
                    </div>
                        
                    <?php if (!empty($groups_info)):
                              foreach ($groups_info as $key => $group_info):
                    ?>
                    <div class="setting-item">
                        <?php if($key === 0): ?>
                        <div class="left">
                            <?php echo __('Guests') ?>
                            <span class="comment">*<?php echo __('Guest') . __(' - abstract group') ?></span>
                        </div>
                        <div class="right">-</div>
                        <?php else: ?>
                        <div class="left">
                            <?php echo htmlspecialchars($key) ?>
                        </div>
                        <div class="right"><?php echo htmlspecialchars($group_info) ?></div>
                        <?php endif; ?>
                        <div class="clear"></div>
                    </div>
                    <?php     endforeach;
                          endif;
                    ?>
                </div>
            </div>
        </div>
    </div>
    
    <div class="atm-flex-child">
        <!--************ STATISTIC **********-->                            
        <div class="list">
            <div class="title"><?php echo __('Statistics') ?></div>
            <div class="level1">
                <div class="head">
                    <div class="title settings"><?php echo __('Name'); ?></div>
                    <div class="title-r"><?php echo __('Value'); ?></div>
                    <div class="clear"></div>
                </div>
                <div class="items">
                    <div class="setting-item">
                        <div class="left">
                            <?php echo __('Total hosts') ?>
                            <span class="comment">*<?php echo __('Host is a unique visitor, essentially a visit to the site from different computers or IP addresses'); ?></span>
                        </div>
                        <div class="right"><?php echo htmlspecialchars($all_hosts[0]['hosts_cnt'] ?? 0) ?></div>
                        <div class="clear"></div>
                    </div>
                    <div class="setting-item">
                        <div class="left">
                            <?php echo __('Total hits') ?>
                            <span class="comment">*<?php echo __('Hits are page views, essentially any page view, even from the same IP. One host can have any number of hits'); ?></span>
                        </div>
                        <div class="right"><?php echo htmlspecialchars($all_hosts[0]['hits_cnt'] ?? 0) ?></div>
                        <div class="clear"></div>
                    </div>
                    <div class="setting-item">
                        <div class="left">
                            <?php echo __('Today hosts') ?>
                        </div>
                        <div class="right"><?php echo htmlspecialchars($today_hosts) ?></div>
                        <div class="clear"></div>
                    </div>
                    <div class="setting-item">
                        <div class="left">
                            <?php echo __('Today hits') ?>
                        </div>
                        <div class="right"><?php echo htmlspecialchars($today_hits) ?></div>
                        <div class="clear"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="atm-flex-child">
        <!--************ MODULES **********-->                          
        <div class="list">
            <div class="title"><?php echo __('Modules') ?></div>
            <div class="level1">
                <div class="head">
                    <div class="title settings"><?php echo __('Module') ?></div>
                    <div class="title-r"><?php echo __('Status') ?></div>
                    <div class="clear"></div>
                </div>
                <div class="items">
                    <div class="setting-item">
                        <div class="left">
                            <?php echo __('Total modules') ?>
                            <span class="comment">*<?php echo __('Modules that are present on your site'); ?></span>
                        </div>
                        <div class="right"><?php echo count($module_list); ?></div>
                        <div class="clear"></div>
                    </div>
                    <?php foreach ($module_list as $module_name): ?>
                    <div class="setting-item">
                        <div class="left">
                            <?php echo htmlspecialchars($module_name) ?>
                        </div>
                        <div class="right">
                            <div class="<?php echo (Config::read('active', $module_name)) ? 'yes' : 'no' ?>"></div>
                        </div>
                        <div class="clear"></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
include_once 'template/footer.php';
?>
