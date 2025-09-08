<?php
##################################################
##                                              ##
## Author:       Andrey Brykin (Drunya)         ##
## Version:      1.4                            ##
## Project:      CMS                            ##
## package       CMS AtomX                      ##
## subpackege    Admin Panel module             ##
## copyright     ©Andrey Brykin 2010-2017       ##
##################################################

// Совместимость с PHP 8.1+
declare(strict_types=1);

include_once '../sys/boot.php';
include_once ROOT . '/admin/inc/adm_boot.php';

function prepareConfToSave(array $conf): array {
    $result = [];
    
    foreach ($conf as $mod => $rules) {
        foreach ($rules as $rule => $params) {
            $result[$mod . '.' . $rule] = $params;
        }
    }

    return $result;
}

function saveRules(array $rules): void {
    $Register = Register::getInstance();
    $Register['ACL']->save_rules(prepareConfToSave($rules));
    
    $_SESSION['message'] = __('Saved');
    redirect('/admin/users_rules.php');
}

$pageTitle = __('Users');

$ACL = $Register['ACL'];
$acl_groups = $ACL->get_group_info() ?? [];
$acl_rules = $ACL->getRules() ?? [];
$group = isset($_GET['group']) ? (int)$_GET['group'] : 1;

// Convert to nice format (simple format to view)
$bufer = [];
$special_rules_bufer = [];

foreach ($acl_rules as $key => $_rules) {
    $access_params = explode('.', $key);
    
    if (count($access_params) === 2) {
        if (empty($bufer[$access_params[0]])) {
            $bufer[$access_params[0]] = [];
        }
        $bufer[$access_params[0]][$access_params[1]] = $_rules;
    } elseif (count($access_params) === 3) {
        $compoundKey = $access_params[0] . '.' . $access_params[1];
        if (empty($bufer[$compoundKey])) {
            $bufer[$compoundKey] = [];
        }
        $bufer[$compoundKey][$access_params[2]] = $_rules;
    }

    // For special rules
    if (!empty($_rules['users'])) {
        foreach ($_rules['users'] as $row) {
            $userKey = 'user_' . $row;
            if (empty($special_rules_bufer[$userKey])) {
                $special_rules_bufer[$userKey] = [];
            }
            
            switch (count($access_params)) {
                case 2:
                    if (empty($special_rules_bufer[$userKey][$access_params[0]])) {
                        $special_rules_bufer[$userKey][$access_params[0]] = [];
                    }
                    $special_rules_bufer[$userKey][$access_params[0]][] = $access_params[1];
                    break;
                
                case 3:
                    $forumKey = $access_params[0] . '_' . $access_params[1];
                    if (empty($special_rules_bufer[$userKey][$forumKey])) {
                        $special_rules_bufer[$userKey][$forumKey] = [];
                    }
                    $special_rules_bufer[$userKey][$forumKey][] = $access_params[2];
                    break;
            }
        }
    }
}

$acl_rules = $bufer;
$specialRules = $special_rules_bufer;

// Get special rules and additional information
$forumModel = $Register['ModManager']->getModelInstance('forum');
$allForums = $forumModel->getCollection() ?? [];

// Getting users names & forums titles for view
$usersNames = [];
$forumsTitles = [];

if (!empty($specialRules)) {
    $uIds = [];
    $fIds = [];
    
    foreach ($specialRules as $k => $uRules) {
        $uIds[] = (int)str_replace('user_', '', $k);
        
        foreach ($uRules as $rk => $rv) {
            if (str_contains($rk, 'forum_')) {
                $fIds[] = (int)str_replace('forum_', '', $rk);
            }
        }
    }
    
    // Get users names
    $usersModel = $Register['ModManager']->getModelInstance('users');
    $uNames = $usersModel->getCollection(
        ['id IN (' . implode(',', array_unique($uIds)) . ')'], 
        ['fields' => 'id, name']
    );
    
    if ($uNames) {
        foreach ($uNames as $user) {
            $usersNames[$user->getId()] = $user->getName();
        }
    }
    
    // Clean up non-existent users
    foreach ($specialRules as $k => $v) {
        $userId = (int)str_replace('user_', '', $k);
        if (!array_key_exists($userId, $usersNames)) {
            unset($specialRules[$k]);
        }
    }
    
    // Get forum titles
    if (!empty($fIds)) {
        $forums = $forumModel->getCollection(['id IN (' . implode(',', array_unique($fIds)) . ')']);
        
        if ($forums) {
            foreach ($forums as $forum) {
                $forumsTitles[$forum->getId()] = $forum->getTitle();
            }
        }
    }
}

// Save special rules
if (isset($_POST['send']) && !empty($_GET['ac']) && $_GET['ac'] === 'special') {
    if (!empty($acl_rules)) {
        $acl_rules_ = $acl_rules;
        
        $user_id = isset($_GET['uid']) ? (int)$_GET['uid'] : 0;
        if ($user_id <= 0) {
            throw new Exception('Incorrect user ID');
        }
        
        foreach ($acl_rules as $mod => $rules) {
            foreach ($rules as $rule => $roles) {
                $post_mod = str_replace('.', '_', $mod);
                
                if (!empty($_POST[$post_mod][$rule])) {
                    if (!in_array($user_id, $acl_rules_[$mod][$rule]['users'] ?? [])) {
                        $acl_rules_[$mod][$rule]['users'][] = $user_id;
                    }
                } else {
                    // Remove user from permissions if unchecked
                    foreach (($roles['users'] ?? []) as $key => $uid) {
                        if ($uid == $user_id) {
                            unset($acl_rules_[$mod][$rule]['users'][$key]);
                        }
                    }
                }
            }
        }

        // Process forum permissions
        foreach ($_POST as $mod => $rules) {
            if (!str_contains($mod, 'forum_')) continue;
            
            $modKey = str_replace('_', '.', $mod);
            foreach ($rules as $title => $value) {
                if (empty($acl_rules_[$modKey])) {
                    $acl_rules_[$modKey] = [];
                }
                if (empty($acl_rules_[$modKey][$title])) {
                    $acl_rules_[$modKey][$title] = ['users' => []];
                }
                
                if (!in_array($user_id, $acl_rules_[$modKey][$title]['users'])) {
                    $acl_rules_[$modKey][$title]['users'][] = $user_id;
                }
            }
        }
        
        saveRules($acl_rules_);
    }
} elseif (isset($_POST['send'])) {
    // Save group rules
    if (!empty($acl_rules)) {
        $acl_rules_ = $acl_rules;
        
        foreach ($acl_rules as $mod => $rules) {
            foreach ($rules as $rule => $roles) {
                foreach ($acl_groups as $id => $params) {
                    $fieldName = $mod . '[' . $rule . '_' . $id . ']';
                    
                    if (!empty($_POST[$mod][$rule . '_' . $id])) {
                        if (!in_array($id, $acl_rules_[$mod][$rule]['groups'] ?? [])) {
                            $acl_rules_[$mod][$rule]['groups'][] = $id;
                        }
                    } else {
                        $groups = $acl_rules_[$mod][$rule]['groups'] ?? [];
                        if (($key = array_search($id, $groups)) !== false) {
                            unset($acl_rules_[$mod][$rule]['groups'][$key]);
                        }
                    }
                }
            }
        }
        
        saveRules($acl_rules_);
    }
}

// Remove forum rules from main display
foreach ($acl_rules as $k => $rule) {
    if (str_contains($k, 'forum.')) {
        unset($acl_rules[$k]);
    }
}

$pageNav = $pageTitle;
$pageNavr = '<a href="users_groups.php">' . h(__('Users groups')) . '</a>';

$dp = $Register['DocParser'] ?? null;

include_once ROOT . '/admin/template/header.php';
?>

<!-- Find users for add new special rules -->
<div id="sp_rules_find_users" class="popup">
    <div class="top">
        <div class="title"><?= h(__('Find users')) ?></div>
        <div onClick="closePopup('sp_rules_find_users');" class="close"></div>
    </div>
    <div class="items">
        <div class="item">
            <div class="left">
                <?= h(__('Name')) ?>
                <span class="comment"><?= h(__('Begin to write that see similar users')) ?></span>
            </div>
            <div class="right">
                <input id="autocomplete_inp" type="text" name="user_name" placeholder="<?= h(__('User Name')) ?>" />
            </div>
            <div class="clear"></div>
        </div>
        <div id="add_users_list"></div>
    </div>
</div>

<script>
$('#autocomplete_inp').on('input', function(){
    var inp = $(this);
    if (inp.val().length < 2) return;
    
    setTimeout(function(){
        AtomX.findUsers('/admin/find_users.php?name=' + encodeURIComponent(inp.val()), 'add_users_list', false);
    }, 500);
});
</script>

<!-- [Остальная часть HTML кода с экранированием вывода через h()] -->

<?php
include_once ROOT . '/admin/template/footer.php';
