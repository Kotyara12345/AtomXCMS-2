<?php
/*-----------------------------------------------\
| 												 |
|  @Author:       Andrey Brykin (Drunya)         |
|  @Version:      1.2.9                          |
|  @Project:      CMS                            |
|  @package       CMS AtomX                      |
|  @subpackage    Entry dot                      |
|  @copyright     ©Andrey Brykin 2010-2012       |
|  @last mod.     2012/04/29                     |
\-----------------------------------------------*/

/*-----------------------------------------------\
| 												 |
|  Any partial or not partial extension          |
|  CMS AtomX, without the consent of the         |
|  author, is illegal                            |
|------------------------------------------------|
|  Любое распространение                         |
|  CMS AtomX или ее частей,                      |
|  без согласия автора, является незаконным      |
\-----------------------------------------------*/

// Set the content type and charset
header('Content-Type: text/html; charset=utf-8');

// Check if the installation directory exists
if (file_exists('install')) {
    include_once('sys/settings/config.php');
    
    // Validate configuration settings
    if (!empty($set) &&
        !empty($set['db']['name']) &&
        (!empty($set['db']['user']) || !empty($set['db']['pass']))
    ) {
        die('Before using your site, delete the INSTALL directory! <br />Перед использованием удалите папку INSTALL');    
    }
    
    // Redirect to the install page if the installation directory exists
    header('Location: install');
    exit;
}

include_once 'sys/boot.php';

// Intercept before routing
Plugins::intercept('before_pather', []);

/**
 * Parser URL
 * Get params from URL and launch needed module and action
 */
new Pather($Register);

// Intercept after routing
Plugins::intercept('after_pather', []);
//pr($Register);
