<?php
####################################################
#### Author:       Andrey Brykin (Drunya)         ####
#### Version:      1.0                            ####
#### Project:      CMS                            ####
#### package       CMS AtomX                      ####
#### subpackage    Admin Panel module             ####
#### copyright     ©Andrey Brykin 2010-2011       ####
####################################################
#### any partial or not partial extension         ####
#### CMS AtomX, without the consent of the       ####
#### author, is illegal                           ####
####################################################

include_once '../sys/boot.php';
include_once ROOT . '/admin/inc/adm_boot.php';

$pageTitle = __('Registration rules');
$pageNav = $pageTitle;
$pageNavr = '';

include_once ROOT . '/admin/template/header.php';

if (isset($_POST['send'])) {
    if (!empty($_POST['message'])) {
        $check = $FpsDB->select('users_settings', DB_COUNT, ['cond' => ['type' => 'reg_rules']]);
        
        $data = [
            'values' => $_POST['message']
        ];
        
        if ($check > 0) {
            $FpsDB->save('users_settings', $data, ['type' => 'reg_rules']);
        } else {
            $data['type'] = 'reg_rules';
            $FpsDB->save('users_settings', $data);
        }
    } else {
        echo '<span style="color:red;">' . __('Fill in rules') . '</span>';
    }
}

$query = $FpsDB->select('users_settings', DB_FIRST, ['cond' => ['type' => 'reg_rules']]);
$current_rules = !empty($query) ? $query[0]['values'] : '';

?>

<div class="warning">
    <?php echo __('Fill in registration rules on your site. Users will be duty to read them.'); ?>
</div>

<div class="list">
    <div class="title"><?php echo __('Registration rules'); ?></div>
    <form action="" method="POST">
        <table style="width:100%;" cellspacing="0" class="grid">
            <tr>
                <td>
                    <textarea name="message" style="width:99%; height:400px;"><?php echo htmlspecialchars($current_rules); ?></textarea>
                </td>
            </tr>
            <tr>
                <td align="center">
                    <input class="save-button" type="submit" name="send" value="<?php echo __('Save'); ?>" />
                </td>
            </tr>
        </table>
    </form>
</div>

<?php include_once 'template/footer.php'; ?>
