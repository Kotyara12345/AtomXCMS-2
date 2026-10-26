<?php
/*-----------------------------------------------\
| 												 |
| @Author:       Andrey Brykin (Drunya)          |
| @Email:        drunyacoder@gmail.com           |
| @Site:         http://atomx.net                |
| @Version:      1.0                             |
| @Project:      CMS                             |
| @package       CMS AtomX                       |
| @subpackege    Admin Panel module  			 |
| @copyright     �Andrey Brykin 2010-2013        |
\-----------------------------------------------*/

/*-----------------------------------------------\
| 												 |
|  any partial or not partial extension          |
|  CMS AtomX,without the consent of the          |
|  author, is illegal                            |
|------------------------------------------------|
|  ����� ���������������                         |
|  CMS Fapos ��� �� ������,                      |
|  ��� �������� ������, �������� �� ��������     |
\-----------------------------------------------*/


include_once '../sys/boot.php';
include_once ROOT . '/admin/inc/adm_boot.php';


if (!empty($_SESSION['user'])) {
	atm_admin_audit(
		'logout',
		'',
		(int)$_SESSION['user']['id'],
		isset($_SESSION['user']['name']) ? (string)$_SESSION['user']['name'] : ''
	);
}

atm_admin_session_revoke_current();
atm_admin_remember_forget();
if (isset($_SESSION['adm_panel_authorize'])) unset($_SESSION['adm_panel_authorize']);
if (isset($_SESSION['user'])) unset($_SESSION['user']);
redirect('/');


