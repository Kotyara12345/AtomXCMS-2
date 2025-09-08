<?php
##################################################
##                                              ##
## @Author:       Andrey Brykin (Drunya)        ##
## @Version:      1.2                           ##
## @Project:      CMS                           ##
## @package       CMS AtomX                     ##
## @subpackege    Admin module                  ##
## @copyright     ©Andrey Brykin 2010-2014      ##
## @last mod.     2014/01/13                    ##
##################################################

// Совместимость с PHP 8.1+
declare(strict_types=1);

include_once '../sys/boot.php';
include_once ROOT . '/admin/inc/adm_boot.php';

// Обработка параметров даты
$date = !empty($_GET['date']) ? (int)$_GET['date'] : time();
$currentDate = date("Y-m-d", $date);
$stats = [];

// Загрузка статистики
if ($currentDate === date("Y-m-d")) {
    $statsFile = ROOT . '/sys/logs/counter/' . $currentDate . '.dat';
    if (file_exists($statsFile)) {
        $statsData = file_get_contents($statsFile);
        if ($statsData !== false) {
            $stats[0] = unserialize($statsData);
        }
    }
} else {
    $stats = $FpsDB->select('statistics', DB_ALL, [
        'cond' => ['date' => $currentDate]
    ]);
}

// Обработка параметров графика
$graphFrom = !empty($_POST['grfrom']) 
    ? date("Y-m-d", strtotime($_POST['grfrom'])) 
    : date("Y-m-d", time() - 2592000);
    
$graphTo = !empty($_POST['grto']) 
    ? date("Y-m-d", strtotime($_POST['grto'])) 
    : date("Y-m-d");

// Получение данных для графика
$UsersModel = $Register['ModManager']->getModelInstance('Users');
$Model = $Register['ModManager']->getModelInstance('Statistics');

try {
    $all = $Model->getCollection([
        "date >= '{$graphFrom}'",
        "date <= '{$graphTo}'",
    ]);

    // Автоматическое расширение интервала при недостатке данных
    $interval = 2592000;
    $i = 0;
    while ($i < 6 && count($all) < 2 && empty($_POST['grfrom']) && empty($_POST['grto'])) {
        if ($i < 5) {
            $interval += 2592000;
            $graphFrom = date("Y-m-d", time() - $interval);
        } else {
            $graphFrom = '0000-00-00';
        }
        
        $all = $Model->getCollection([
            "date >= '{$graphFrom}'",
            "date <= '{$graphTo}'",
        ]);
        $i++;
    }

} catch (Exception $e) {
    $all = [];
    error_log("Statistics error: " . $e->getMessage());
}

// Подготовка данных для графика
$jsonDataViews = [];
$jsonDataHosts = [];

if (!empty($all)) {
    foreach ($all as $item) {
        $jsonDataViews[] = [
            $item->getDate(),
            (int)$item->getViews()
        ];
        $jsonDataHosts[] = [
            $item->getDate(),
            (int)$item->getIps()
        ];
    }
}

// Расчет текущей статистики
$tViews = 0;
$tHosts = 0;
$tVisitors = 0;
$viewsOnVisit = 0;
$botViews = 0;

if (!empty($stats[0])) {
    $tHosts = $stats[0]['ips'] ?? 0;
    $tViews = $stats[0]['views'] ?? 0;
    $tVisitors = $stats[0]['cookie'] ?? 0;
    $viewsOnVisit = $tVisitors > 0 ? number_format(($tViews / $tVisitors), 1) : 0;
    $botViews = ($stats[0]['yandex_bot_views'] ?? 0) + 
                ($stats[0]['google_bot_views'] ?? 0) + 
                ($stats[0]['other_bot_views'] ?? 0);
}

// Подготовка JSON данных
$jsonData = json_encode([$jsonDataViews, $jsonDataHosts], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);

$pageTitle = 'Статистика';
$pageNav = $pageTitle;
$pageNavr = '';

include_once ROOT . '/admin/template/header.php';
?>

<div id="chart2"></div>
<div class="list">
    <div class="title">
        <table cellspacing="0" width="100%">
            <tr>
                <td>
                    <a style="color:#8BB35B;" href="statistic.php?date=<?= $date - 86400 ?>">
                        <?= '&laquo; ' . date("Y-m-d", $date - 86400) ?>
                    </a>
                </td>
                <td align="center">
                    <a href="statistic.php?date=<?= $date ?>">
                        <span style="color:#8BB35B;"><?= date("Y-m-d", $date) ?></span>
                    </a>
                </td>
                <td align="right" width="20%">
                    <?php if (($date + 86400) <= time()): ?>
                    <a style="color:#8BB35B;" href="statistic.php?date=<?= $date + 86400 ?>">
                        <?= date("Y-m-d", $date + 86400) . ' &raquo;' ?>
                    </a>
                    <?php endif; ?>
                </td>
            </tr>
        </table>
    </div>
    
    <table class="grid" style="width:100%;" cellspacing="0px">
        <?php if (!empty($stats[0])): ?>
        <tr>
            <td>Просмотров</td>
            <td><?= h($tViews) ?></td>
        </tr>
        <tr>
            <td>Хостов</td>
            <td><?= h($tHosts) ?></td>
        </tr>
        <tr>
            <td>Посетителей</td>
            <td><?= h($tVisitors) ?></td>
        </tr>
        <tr>
            <td>Просмотров на посетителя</td>
            <td><?= h($viewsOnVisit) ?></td>
        </tr>
        <tr>
            <td>Просмотров роботами</td>
            <td><?= h($botViews) ?></td>
        </tr>
        <tr>
            <td>Робот ПС google</td>
            <td><?= h($stats[0]['google_bot_views'] ?? 0) ?></td>
        </tr>
        <tr>
            <td>Робот ПС yandex</td>
            <td><?= h($stats[0]['yandex_bot_views'] ?? 0) ?></td>
        </tr>
        <tr>
            <td>Переходы с других сайтов</td>
            <td><?= h($stats[0]['other_site_visits'] ?? 0) ?></td>
        </tr>
        <?php else: ?>
        <tr>
            <td align="center" colspan="2">Записей нет</td>
        </tr>
        <?php endif; ?>

        <?php if(!empty($jsonDataViews) && !empty($jsonDataHosts)): ?>
        <tr>
            <td colspan="2">
                <script type="text/javascript" src="<?= h(WWW_ROOT) ?>/sys/js/jqplot/graphlib.js"></script>
                <script type="text/javascript" src="<?= h(WWW_ROOT) ?>/sys/js/jqplot/plugins/jqplot.canvasTextRenderer.min.js"></script>
                <script type="text/javascript" src="<?= h(WWW_ROOT) ?>/sys/js/jqplot/plugins/jqplot.canvasAxisLabelRenderer.min.js"></script>
                <script type="text/javascript" src="<?= h(WWW_ROOT) ?>/sys/js/jqplot/plugins/jqplot.dateAxisRenderer.min.js"></script>
                <script type="text/javascript" src="<?= h(WWW_ROOT) ?>/sys/js/jqplot/plugins/jqplot.highlighter.min.js"></script>
                <link href="<?= h(WWW_ROOT) ?>/sys/js/jqplot/style.css" type="text/css" rel="stylesheet">
                <script type="text/javascript" src="<?= h(WWW_ROOT) ?>/sys/js/datepicker/datepicker.js"></script>
                <link type="text/css" rel="StyleSheet" href="<?= h(WWW_ROOT) ?>/sys/js/datepicker/datepicker.css" />
                
                <script type="text/javascript">
                $(document).ready(function(){
                    $('.tcal').datetimepicker({
                        timepicker:false,
                        format:'Y/m/d',
                        closeOnDateSelect: true
                    });
                    
                    var data = <?= $jsonData ?>;
                    
                    if (!data[0].length || !data[1].length) {
                        $('#graph').hide();
                        return false;
                    }
                    
                    var plot2 = $.jqplot('graph', data, {
                        title: 'Views and hosts',
                        axesDefaults: {
                            labelRenderer: $.jqplot.CanvasAxisLabelRenderer,
                            gridLineColor: "#ff0000",
                            border: '#ff0000'
                        },
                        axes: {
                            xaxis: {
                                renderer: $.jqplot.DateAxisRenderer,
                                tickOptions: {
                                    formatString: '%b-%d'
                                }
                            },
                            yaxis: {
                                label: "Quantity",
                                labelRenderer: $.jqplot.CanvasAxisLabelRenderer
                            }
                        },
                        highlighter: {
                            show: true,
                            sizeAdjust: 7.5
                        },
                        cursor: {
                            show: false
                        },
                        series: [
                            {
                                lineWidth: 1,
                                fill: false,
                                color: '#777',
                                label: 'Views',
                                markerOptions: { style: 'diamond' }
                            },
                            {
                                lineWidth: 5,
                                label: 'Hosts',
                                color: '#96c703',
                                markerOptions: { style: "filledSquare", size: 10 }
                            }
                        ],
                        grid: {
                            background: '#f4f2f2'
                        }
                    });
                });
                </script>
                
                <div style="graph-wrapper">
                    <div style="graph-container" id="graph"></div>
                </div>
            </td>
        </tr>
        <?php endif; ?>

        <tr>
            <td colspan="2">
                <form method="POST" action="">
                    <br />
                    <table class="lines" cellspacing="0px">
                        <tr>
                            <td>
                                &nbsp;<?= h(__('From')) ?>&nbsp;:&nbsp;&nbsp;
                                <input class="tcal" id="ffrom" type="text" name="grfrom" 
                                       value="<?= h(!empty($_POST['grfrom']) ? date("Y/m/d", strtotime($_POST['grfrom'])) : date("Y/m/d", time() - 2592000)) ?>"/>
                                &nbsp;<?= h(__('To')) ?>&nbsp;:&nbsp;&nbsp;
                                <input class="tcal" id="fto" type="text" name="grto" 
                                       value="<?= h(!empty($_POST['grto']) ? date("Y/m/d", strtotime($_POST['grto'])) : date("Y/m/d")) ?>"/>
                            </td>
                            <td>
                                <input type="submit" name="send" class="save-button" value="<?= h(__('Apply')) ?>" />
                            </td>
                        </tr>
                    </table>
                </form>
            </td>
        </tr>
    </table>
</div>

<?php 
include_once 'template/footer.php';
