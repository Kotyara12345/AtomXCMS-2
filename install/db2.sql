INSERT INTO `your_table_name` (`name`, `body`) 
VALUES (
    'menu', 
    'echo \'<ul class=\"uMenuRoot\">\';\r\n' .
    '$modules = glob(\'modules/*\');\r\n' .
    'if (!empty($modules)) {\r\n' .
    '    foreach ($modules as $module) {\r\n' .
    '        $module = basename($module);\r\n' .
    '        $unuseable = [\'statistics\', \'pages\'];\r\n' .
    '        if (in_array($module, $unuseable)) continue;\r\n' .
    '        if (Config::read(\'active\', $module) == 1) {\r\n' .
    '            if ($module == \'chat\') {\r\n' .
    '                echo \'<li><div class=\"uMenuItem\"><a href=\"javascript://\" onclick=\"window.open(\\\'/chat/\\\', \\\'chat\\\', \\\'resizable=0, location=0, width=210, height=620\\\')\">\' . Config::read(\'title\', $module) . \'</a></div></li>\';\r\n' .
    '                continue;\r\n' .
    '            }\r\n' .
    '            echo \'<li><div class=\"uMenuItem\"><a href=\"/\' . R . $module . \'/\">\' . Config::read(\'title\', $module) . \'</a></div></li>\';\r\n' .
    '        }\r\n' .
    '    }\r\n' .
    '}\r\n' .
    'echo \'</ul>\';'
);
