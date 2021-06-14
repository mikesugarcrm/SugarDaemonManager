<?php
$manifest = array(
    'key' => 'SugarDaemonManager_v1.0',
    'name' => 'Sugar Daemon Manager',
    'description' => 'The Sugar Daemon Manager can start and monitor multiple asynchronous, concurrent PHP command line processes',
    'built_in_version' => '7.9',
    'version' => '1.0',
    'acceptable_sugar_versions' => array(
        'regex_matches' => array(
            '7.9.*',
            '8.*',
            '9.*',
            '10.*',
            '11.*',
            '12.*',
        ),
    ),
    'acceptable_sugar_flavors' => array(
        'ENT',
        'PRO',
        'ULT',
    ),
    'author' => 'sugarcrm',
    'is_uninstallable' => true,
    'type' => 'module',
);

$installdefs = array(
    'id' => 'SugarDaemonManager',
    'copy' => array (
        array (
            'from' => '<basepath>/custom/src/SugarDaemonManager/SugarDaemon.php',
            'to' => 'custom/src/SugarDaemonManager/SugarDaemon.php',
        ),
        array (
            'from' => '<basepath>/custom/src/SugarDaemonManager/SugarDaemonManager.php',
            'to' => 'custom/src/SugarDaemonManager/SugarDaemonManager.php',
        ),
    ),
);