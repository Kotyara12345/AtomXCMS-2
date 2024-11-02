<?php
##################################################
##                                                ##
## Author:       Andrey Brykin (Drunya)         ##
## Version:      0.7                            ##
## Project:      CMS                            ##
## package       CMS AtomX                      ##
## subpackage    Admin Panel module             ##
## copyright     ©Andrey Brykin 2010-2011       ##
##################################################

##################################################
##                                                ##
## Любое частичное или полное расширение       ##
## CMS AtomX без согласия автора является        ##
## незаконным                                   ##
##################################################
## Any distribution of CMS AtomX or its parts   ##
## without the author's consent is illegal       ##
##################################################

include_once '../sys/boot.php';
include_once ROOT . '/admin/inc/adm_boot.php';

$templateDir = ROOT . '/template/' . Config::read('template');
$templateStandard = array_merge(
    glob($templateDir . '/html/*/*.html'),
    glob($templateDir . '/css/*.css')
);

if (is_array($templateStandard)) {
    foreach ($templateStandard as $file) {
        $standFile = $file . '.stand';
        if (file_exists($standFile)) {
            if (copy($standFile, $file)) {
                unlink($standFile);
            }
        }
    }
}

$_SESSION['message'] = __('Шаблон восстановлен');
redirect('/admin/default_dis.php');
