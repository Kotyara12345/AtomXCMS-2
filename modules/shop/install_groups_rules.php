<?php
/**
 * Shop ACL rules.
 */

$FpsInstallRules = array(
    'shop.view_catalog' => array(
        'groups' => array(0, 1, 2, 3, 4),
    ),
    'shop.place_orders' => array(
        'groups' => array(0, 1, 2, 3, 4),
    ),
    'shop.manage_products' => array(
        'groups' => array(3, 4),
    ),
    'shop.manage_orders' => array(
        'groups' => array(3, 4),
    ),
    'shop.manage_settings' => array(
        'groups' => array(4),
    ),
);
