<?php

$s = array(
    'use_previous_picks' => true,
    'hide_logo' => false,
    'admin_groups' => 'Administrator',
    'ga_custom_var_index' => 1,
    'ga_custom_var_name' => 'SAB',
    'ga_custom_var_scope' => 2,
);

$settings = array();

foreach ($s as $key => $value) {
    if (is_string($value) || is_int($value)) { $type = 'textfield'; }
    elseif (is_bool($value)) { $type = 'combo-boolean'; }
    else { $type = 'textfield'; }

    $parts = explode('.',$key);
    if (count($parts) == 1) { $area = 'Default'; }
    else { $area = $parts[0]; }
    
    $settings['simpleab.'.$key] = $modx->newObject('modSystemSetting');
    $settings['simpleab.'.$key]->set('key', 'simpleab.'.$key);
    $settings['simpleab.'.$key]->fromArray(array(
        'value' => $value,
        'xtype' => $type,
        'namespace' => 'simpleab',
        'area' => $area,
        'editedon' => time(),
    ));
}

return $settings;


