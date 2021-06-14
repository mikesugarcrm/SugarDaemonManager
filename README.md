# The Sugar Daemon Manager
A class for starting and monitoring multiple asynchronous PHP CLI processes.

The SugarDaemonManager allows your PHP script to start any number of asynchronous, concurrent command line calls as separate PHP processes. 

You can pass arguments to the processes, and assign callback functions (with optional arguments) to each child process as well as 
a "final callback" to run when all child processes are complete.


##Requirements
- Sugar 7.9 or later
- PHP 7 or later.
- *nix OS (Windows is not supported).

**NOTE:** You can only call PHP scripts with the SugarDaemonManager. All calls will have 'php' prepended to them 
automatically.


##Installing
**tar/zip**

You can tar/zip up the contents of the custom/ directory and untar/unzip them in your instance. 
You will need to run QRR for the changes to take effect.

**Module Loadable Package**

With the provided manifest file, you can create a module loadable package with this zip command run from this 
repo's base directory: 

`zip -r SugarDaemonManager.zip manifest.php custom/*` 

Then install the package as you would with any other package.

##Configuring
You set the configuration values for the SugarDaemonManager like any other sugar config value, in config_override.php.

Since you may have more than one script that will use the SugarDaemonManager, each instance of the SugarDaemonManager 
is given a unique name, which in turn allows us to namespace the config values, like this:

```php
// for an instance named 'demo':
$sugar_config['sugar_daemon']['demo']['max_simultaneous_processes'] = 50; // maximum number of processes to allow to run at one time.
$sugar_config['sugar_daemon']['demo']['process_check_delay'] = 1; // how often, in seconds, to see if any processes have completed.
$sugar_config['sugar_daemon']['demo']['time_limit'] = 1800; // maximum time, in seconds, allowed to try to run all processes.
//
// for an instance named 'example':
$sugar_config['sugar_daemon']['example']['max_simultaneous_processes'] = 6;
$sugar_config['sugar_daemon']['example']['process_check_delay'] = 5;
$sugar_config['sugar_daemon']['example']['time_limit'] = 3600;
```

##Using
First, create an instance of the SugarDaemonManager with a unique name.

`$sdm = new \Sugarcrm\Sugarcrm\custom\SugarDaemonManager\SugarDaemonManager('demo');`

Next, add some commands for it to run.

```
$sdm->addCommand(
    'sleeper.php', // name of the script to run
    ['-h' => null, '--wait-time' => $wait_time, '--iterations' => $iterations, '--id' => 'Sneezy'], // optional arguments to be passed on the command line
    'callback', // optional name of a callback method, either a function name or an array of ['class' => 'method']
    ['My ID is Sneezy'] // optional arguments to be passed to the callback method.
    );
```
You can add as many of these as you like.

Optionally, add a final callback.
`$sdm->setFinalCallback('finalCallback') // finalCallback is a function name, can also be an array of ['class' => 'method']`

Finally, run the commands.
`$sdm->runAllCommands();`

The runAllCommands() call will start running the commands you passed in via addCommand() until it starts them all or 
hits the max_simultaneous_processes value you set in config. For each process it starts, the SugarDaemonManager will 
store that processes PID. If you meet the max_simultaneous_processes value, the SugarDaemonManager will use ps -p 
with the stored process ID for each process to see if it's still running. If it's not running, its callback (if any)
will be called and the next process (if any) will be started. When all processes are complete, the final callback
method will be called.

**NOTE:** The script that calls runAllCommands() will wait until the last command has completed. So if you're running
five asynchronous commands, and your max_simultaneous_processes setting is >= 5, and each process takes 10 minutes, 
your script will wait for 10 minutes before continuing execution. You script WILL NOT proceed after runAllCommands()
until the last command is complete, or the time limit is exceeded.

**NOTE:** If the time limit is exceeded, running child processes will run to completion but no further processes 
will be started and callbacks will not be run.

###So what actually happens?
Each command and all args will be escaped and then run through exec(). The commands will look something like this 
when executed:

`nohup php sleeper.php -h --wait-time='4' --iterations='5' --id='Sneezy' 1>/dev/null 2>/dev/null & echo $!`

This creates a detached php thread that runs independently of the process that called it and any other PHP processes.


##Runing the demo
If you want to run the demo, just copy demo.php and sleeper.php from this repo into the root directory of 
your sugar instance. Then just run demo.php:

`php -f demo.php`

demo.php will start 7 processes of sleeper.php, and pass each one some random arguments. sleeper.php will
will loop --iterations number of times and call sleep() for --wait-time seconds, and then write the sleeper_log file
which iteration it's on and how long it slept. Tail the sleeper_log to see how and when the various processes work
simultaneously By default, the max_simultaneous_processes is set to 3 but you 
can change that to see how the behavior changes. 

##Questions, Comments, Bugs
Email me - mandersen@sugarcrm.com


