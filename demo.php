<?php
if(!defined('sugarEntry'))define('sugarEntry', true);
require_once('include/entryPoint.php');

require("custom/src/SugarDaemonManager/SugarDaemon.php");
require("custom/src/SugarDaemonManager/SugarDaemonManager.php");


function callback($id)
{
    file_put_contents('sleeper_log', "$id is finished sleeping\n", FILE_APPEND);
}

function finalCallback()
{
    file_put_contents('sleeper_log', "All Done\n", FILE_APPEND);
}

$sugar_config['sugar_daemon']['demo']['max_simultaneous_processes'] = '3';
$sugar_config['sugar_daemon']['demo']['time_limit'] = 3600;
$sugar_config['sugar_daemon']['demo']['process_check_delay'] = 1;

$sdm = new \Sugarcrm\Sugarcrm\custom\SugarDaemonManager\SugarDaemonManager('demo');

$dwarves = [
    'Doc',
    'Dopey',
    'Sneezy',
    'Happy',
    'Grumpy',
    'Bashful',
    'Sleepy',
];

foreach ($dwarves as $dwarf) {
    $wait_time = rand(1, 6);
    $iterations = rand(1, 6);
    $sdm->addCommand('sleeper.php', ['-h' => null, '--wait-time' => $wait_time, '--iterations' => $iterations, '--id' => $dwarf], 'callback', [$dwarf]);
}

$sdm->setFinalCallback('finalCallback');
print("Putting the dwarves to sleep\n");
$sdm->runAllCommands();
print("All the dwarves have woken up!\n");
