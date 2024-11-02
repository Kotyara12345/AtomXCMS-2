<?php
@ini_set('display_errors', 0);
sleep(1);

$step = (isset($_GET['step'])) ? $_GET['step'] : '1';
if (!in_array($step, array(1, 2, 3))) $step = 1;

// Количество элементов для каждого шага
$stepsCount = 25;

// Генерация вывода
$output = '';
for ($i = 1; $i <= $stepsCount; $i++) {
    $class = ($i <= $step * 6) ? 'act' : '';
    $output .= "<li class=\"$class\"></li>\n";
}

echo $output;
?>
