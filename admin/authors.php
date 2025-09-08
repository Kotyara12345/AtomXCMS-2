<?php
/**
 * ==================================================
 * Development Team Page - Admin Module
 * ==================================================
 * 
 * @author    Andrey Brykin (Drunya)
 * @version   2.0
 * @project   CMS AtomX
 * @package   Admin Module
 * @subpackage Development Team
 * @copyright © Andrey Brykin 2010-2024
 * 
 * ==================================================
 * Any partial or complete distribution
 * of CMS AtomX without the consent of the author
 * is illegal.
 * ==================================================
 * Любое распространение CMS AtomX или ее частей,
 * без согласия автора, является незаконным.
 * ==================================================
 */

declare(strict_types=1);

include_once '../sys/boot.php';
include_once ROOT . '/admin/inc/adm_boot.php';

// Check permissions
if (!$ACL->turn(['panel', 'restricted_access_authors'], false)) {
    $_SESSION['errors'] = __('Permission denied');
    redirect('/admin/');
}

$pageTitle = __('Development Team');
$pageNav = $pageTitle;
$pageNavr = '<span style="float:right;"><a href="javascript://" onClick="showHelpWin(\'Арбайтен! Арбайтен! Арбайтен!\', \'А никто и не мешает\')">' . __('I want to be here') . '</a></span>';

include_once ROOT . '/admin/template/header.php';
?>

<div class="list">
    <div class="title" role="heading" aria-level="2"><?= __('Development Team') ?></div>
    
    <div class="level1">
        <div class="items" role="list">
            
            <div class="setting-item" role="listitem">
                <div class="center">
                    <h3 role="heading" aria-level="3"><?= __('Project Lead') ?></h3>
                    <div class="team-member">
                        <strong>Andrey Brykin (Drunya)</strong>
                        <span class="member-role"><?= __('Founder & Lead Developer') ?></span>
                    </div>
                </div>
            </div>
            
            <div class="setting-item" role="listitem">
                <div class="center">
                    <h3 role="heading" aria-level="3"><?= __('Core Developers') ?></h3>
                    <div class="team-member">
                        <strong>Andrey Brykin (Drunya)</strong>
                        <span class="member-role"><?= __('Architecture & Backend') ?></span>
                    </div>
                    <div class="team-member">
                        <strong>Danilov Alexandr (modos189)</strong>
                        <span class="member-role"><?= __('Backend Development') ?></span>
                    </div>
                </div>
            </div>
            
            <div class="setting-item" role="listitem">
                <div class="center">
                    <h3 role="heading" aria-level="3"><?= __('Quality Assurance') ?></h3>
                    <div class="team-member">
                        <strong>Andrey Konyaev (Ater)</strong>
                        <span class="member-role"><?= __('Testing & Audit') ?></span>
                    </div>
                    <div class="team-member">
                        <strong>Laguta Dmitry (ARMI)</strong>
                        <span class="member-role"><?= __('Testing') ?></span>
                    </div>
                    <div class="team-member">
                        <strong>Roman Maximov (r00t_san)</strong>
                        <span class="member-role"><?= __('Security Testing') ?></span>
                    </div>
                    <div class="team-member">
                        <strong>Alexandr Verenik (Wasja)</strong>
                        <span class="member-role"><?= __('Testing') ?></span>
                    </div>
                    <div class="team-member">
                        <strong>Danilov Alexandr (modos189)</strong>
                        <span class="member-role"><?= __('Testing') ?></span>
                    </div>
                </div>
            </div>
            
            <div class="setting-item" role="listitem">
                <div class="center">
                    <h3 role="heading" aria-level="3"><?= __('Marketing & Community') ?></h3>
                    <div class="team-member">
                        <strong>Andrey Brykin (Drunya)</strong>
                        <span class="member-role"><?= __('Project Management') ?></span>
                    </div>
                    <div class="team-member">
                        <strong>Andrey Konyaev (Ater)</strong>
                        <span class="member-role"><?= __('Community Management') ?></span>
                    </div>
                </div>
            </div>
            
            <div class="setting-item" role="listitem">
                <div class="center">
                    <h3 role="heading" aria-level="3"><?= __('Design & UX') ?></h3>
                    <div class="team-member">
                        <strong>Lapin Boris (MrBoriska)</strong>
                        <span class="member-role"><?= __('Lead Designer') ?></span>
                    </div>
                    <div class="team-member">
                        <strong>Andrey Brykin (Drunya)</strong>
                        <span class="member-role"><?= __('UI Design') ?></span>
                    </div>
                    <div class="team-member">
                        <strong>Alexandr Bognar (Krevedko)</strong>
                        <span class="member-role"><?= __('Graphic Design') ?></span>
                    </div>
                    <div class="team-member">
                        <strong>Roman Maximov (r00t_san)</strong>
                        <span class="member-role"><?= __('UX Design') ?></span>
                    </div>
                    <div class="team-member">
                        <strong>Laguta Dmitry (ARMI)</strong>
                        <span class="member-role"><?= __('Template Design') ?></span>
                    </div>
                </div>
            </div>
            
            <div class="setting-item" role="listitem">
                <div class="center">
                    <h3 role="heading" aria-level="3"><?= __('Security') ?></h3>
                    <div class="team-member">
                        <strong>Roman Maximov (r00t_san)</strong>
                        <span class="member-role"><?= __('Security Lead') ?></span>
                    </div>
                </div>
            </div>
            
            <div class="setting-item" role="listitem">
                <div class="center">
                    <h3 role="heading" aria-level="3"><?= __('Additional Components') ?></h3>
                    <div class="team-member">
                        <strong>Andrey Brykin (Drunya)</strong>
                        <span class="member-role"><?= __('Core Modules') ?></span>
                    </div>
                    <div class="team-member">
                        <strong>Alexandr Verenik (Wasja)</strong>
                        <span class="member-role"><?= __('Third-party Integration') ?></span>
                    </div>
                </div>
            </div>
            
            <div class="setting-item" role="listitem">
                <div class="center">
                    <h3 role="heading" aria-level="3"><?= __('Localization') ?></h3>
                    <div class="team-member">
                        <strong>Victor Sproot (Sproot)</strong>
                        <span class="member-role"><?= __('Translation Lead') ?></span>
                    </div>
                    <div class="team-member">
                        <strong>Andrey Brykin (Drunya)</strong>
                        <span class="member-role"><?= __('Translation') ?></span>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

<style>
.team-member {
    margin: 10px 0;
    padding: 8px;
    background: #f8f8f8;
    border-radius: 4px;
    border-left: 3px solid var(--primary-color, #96c703);
}

.team-member strong {
    display: block;
    color: #333;
    font-size: 14px;
}

.member-role {
    display: block;
    color: #666;
    font-size: 12px;
    font-style: italic;
    margin-top: 2px;
}

.setting-item .center {
    text-align: center;
    padding: 20px;
}

.setting-item .center h3 {
    color: var(--primary-color, #96c703);
    margin-bottom: 15px;
    font-size: 16px;
}

/* Responsive design */
@media screen and (max-width: 768px) {
    .team-member {
        margin: 8px 0;
        padding: 6px;
    }
    
    .team-member strong {
        font-size: 13px;
    }
    
    .member-role {
        font-size: 11px;
    }
    
    .setting-item .center {
        padding: 15px;
    }
    
    .setting-item .center h3 {
        font-size: 15px;
    }
}

/* High contrast mode support */
@media (prefers-contrast: high) {
    .team-member {
        border: 1px solid currentColor;
    }
    
    .team-member strong {
        font-weight: bold;
    }
}

/* Reduced motion support */
@media (prefers-reduced-motion: reduce) {
    .team-member {
        transition: none;
    }
}
</style>

<?php
include_once ROOT . '/admin/template/footer.php';
