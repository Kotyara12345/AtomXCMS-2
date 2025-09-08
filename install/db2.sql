INSERT INTO `table_name` (`name`, `body`) 
VALUES ('menu', '<?php
echo \'<ul class="uMenuRoot">\';
$modules = [];
$activeModules = Config::getActiveModules();

foreach ($activeModules as $module => $config) {
    if (in_array($module, [\'statistics\', \'pages\'])) {
        continue;
    }
    
    $title = htmlspecialchars($config[\'title\'] ?? $module, ENT_QUOTES, \'UTF-8\');
    $url = htmlspecialchars(\'/\' . R . $module . \'/\', ENT_QUOTES, \'UTF-8\');
    
    if ($module === \'chat\') {
        echo \'<li><div class="uMenuItem\"><a href="javascript://" onclick="openChatWindow()">\' . $title . \'</a></div></li>\';
        continue;
    }
    
    echo \'<li><div class="uMenuItem\"><a href="\' . $url . \'">\' . $title . \'</a></div></li>\';
}

echo \'</ul>\';

function openChatWindow() {
    window.open(\'/chat/\', \'chat\', \'resizable=0,location=0,width=210,height=620\');
}
?>');
