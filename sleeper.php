<?php

$opts = getopt('h', [
    'wait-time:',
    'iterations:',
    'id:',
]);


$wait_time = isset($opts['wait-time']) ? $opts['wait-time'] : 1;
$iterations = isset($opts['iterations']) ? $opts['iterations'] : 1;
$id = isset($opts['id']) ? $opts['id'] : 'no id';
$pid = getmypid();


for ($i = 1; $i <= $iterations; $i++) {
    sleep($wait_time);
    file_put_contents('sleeper_log', "$pid - $id slept for $wait_time seconds on iteration $i\n", FILE_APPEND);
}

print("\ndone\n");