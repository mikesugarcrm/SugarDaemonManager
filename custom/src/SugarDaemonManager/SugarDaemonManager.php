<?php

namespace Sugarcrm\Sugarcrm\custom\SugarDaemonManager;
use \Sugarcrm\Sugarcrm\custom\SugarDaemonManager\SugarDaemon as SugarDaemon;

/**
 * Class SugarDaemonManager
 *
 * This class is for managing multiple, concurrent PHP processes executed as cli commands.
 * Its goal is to assemble however many commands the application needs to run, with optional arguments
 * and callbacks, and then run all of those commands.
 *
 * It may not be wise to run them all concurrently, however. You might have dozens, or hundreds, of commands
 * for a large job you're trying to break up. So this class will use limits on the maximum number of
 * concurrent processes it's allowed to run, and won't exceed that limit. It will also stop execution
 * if it exceeds its time limit. These values are set in config.
 *
 * The processes themselves are represented by the SugarDaemon class (@see SugarDaemon).
 *
 * @package Sugarcrm\Sugarcrm\custom\SugarDaemonManager
 */
class SugarDaemonManager
{
    /* int timestamp of when we started running commands. */
    public $startTimestamp = 0;

    /* array of arrays - the commands and arguments we're going to run in parallel. */
    public $commands = array();

    /* array - an array of callbacks (callable objects) keyed by the PID of running processes. */
    public $callbacks = array();

    /* array - an array of arguments to be passed to the callback */
    public $callbackArgs;

    /* int - the maximum number of simultaneous processes to run at one time. */
    public $maxSimultaneousProcesses = 5;

    /* int - max time in seconds we will wait for all commands to complete. */
    public $timeLimitInSeconds = 600;

    /* array - the currently running processes. */
    public $currentProcesses = array();

    /* string - the name we'll use identify this Manager so we can identify config values that are specific to it. */
    public $name = 'default';

    /* int - the time in seconds to wait between checking for completed processes.*/
    public $processCheckDelay = 2;

    /* array - a method to call when all commands are no longer running.*/
    public $finalCallback = null;


    /**
     * SugarDaemonManager constructor.
     *
     * Just sets up the name and reads config values out of config_override.php based on $name.
     *
     * We provide for different applications of this class to have different config settings in the same instance.
     * If you have a 'cleanup_activities' BPM, you may want it to have a very long run time (7200 seconds), but
     * you might also have a 'nightly_case_update' BPM that should only need 600 seconds to run. This way you can
     * configure them differently in the same sugar instance.
     *
     * @param string $name - the name for this instance of the class. This name will correspond to values set in
     *  config_override.php, i.e. $sugar_config['sugar_daemons']['<name>']['time_limit'] = 600;
     */
    public function __construct($name = '')
    {
        if (!empty($name) && is_string($name)) {
            $this->name = $name;
        }

        $this->maxSimultaneousProcesses = $this->config("max_simultaneous_processes", $this->maxSimultaneousProcesses);
        $this->timeLimitInSeconds = $this->config("time_limit", $this->timeLimitInSeconds);
        $this->processCheckDelay = $this->config("process_check_delay", $this->processCheckDelay);
    }


    /**
     * Call this method once for every cli command you want to run. These must be PHP scripts only!
     * They will be executed in the order they were added in.
     *
     * @param string $cmd - the command you want to run. This does NOT need to include 'php'
     * @param array $arguments - an optional array of scalars to pass on the command line to $cmd. They will be escaped for you.
     * @param callable $callback - an optional callback function to run when this class detects $cmd is no longer running.
     * @param array $callbackArgs - an optional array of arguments to pass to the callback.
     */
    public function addCommand($cmd, $arguments = array(), $callback = null, $callbackArgs = array())
    {
        $this->log("Adding Command $cmd");
        $this->commands[] = array(
            'command' => $cmd,
            'arguments' => $arguments,
            'callback' => $callback,
            'callbackArgs' => $callbackArgs,
        );
    }


    /**
     * Function to call after all commands have finished, i.e. they have been started and
     * are no longer running.
     *
     * @param callable $callback
     * @param array $callbackArgs
     */
    public function setFinalCallback(callable $callback, $callbackArgs = array())
    {
        $this->finalCallback = array(
            'callback' => $callback,
            'args' => $callbackArgs,
        );
    }


    /**
     * Calls the final callback, if one has been set.
     *
     * Returns the value of the final callback.
     *
     * @return mixed
     */
    public function executeFinalCallback()
    {
        $retVal = null;
        if (is_array($this->finalCallback)) {
            if (is_array($this->finalCallback['callback'])) {
                $strCallback = get_class($this->finalCallback['callback'][0]) . "->" . $this->finalCallback['callback'][1] . "()";
            } else {
                $strCallback = $this->finalCallback['callback'] . "()";
            }
            $this->log("Calling final callback $strCallback");
            $retVal = call_user_func_array($this->finalCallback['callback'], $this->finalCallback['args']);
            $this->log("final callback $strCallback is complete");
        }
        return $retVal;
    }


    /**
     * Adds a running SugarDaemon object to our list of running processes ($this->currentProcesses).
     * NOTE: this method doesn't check max_simultaneous_processes, it will just add them. That check is performed
     * during runAllCommands().
     *
     * @param \Sugarcrm\Sugarcrm\custom\SugarDaemonManager\SugarDaemon $process - a SugarDaemon (command line process) that has been started.
     * @param callable $callback - an optional callback function to run when $process is no longer running.
     * @param array $callbackArgs - an optional array of agruments to pass to $callback.
     */
    private function addProcess(SugarDaemon $process, $callback = null, $callbackArgs = array())
    {
        $this->log("Adding process {$process->pid} {$process->cmd}.");
        $this->currentProcesses[$process->pid] = $process;
        $this->callbacks[$process->pid] = $callback;
        $this->callbackArgs[$process->pid] = $callbackArgs;
    }


    /**
     * Removes a running process from the currentProcesses array via the process's PID.
     *
     * @param string - $pid The process id of the process we're removing.
     */
    private function removeProcess($pid)
    {
        unset($this->currentProcesses[$pid]);
        $this->log("Process $pid has been removed.");
    }


    /**
     * Runs the callback, if any, for the given process id.
     *
     * @param string $pid - the process id of the process we're running the callback for.
     */
    private function executeCallback($pid)
    {
        if (isset($this->callbacks[$pid]) && !is_null($this->callbacks[$pid])) {
            if (is_callable($this->callbacks[$pid])) {
                $this->log("Calling callback for $pid");
                call_user_func_array($this->callbacks[$pid], $this->callbackArgs[$pid]);
            }
        }
    }


    /**
     * Gets the number of currently running processes.
     *
     * @return int
     */
    public function getCountOfCurrentRunningProcesses()
    {
        return count($this->currentProcesses);
    }


    /**
     * Returns true if we've been running processes longer than the time limit we set in config.
     *
     * @return bool
     */
    public function timeLimitExceeded()
    {
        return $this->startTimestamp + $this->timeLimitInSeconds <= time();
    }


    /**
     * Returns true if the number of currently running processes exceeds the max_simultaneous_processes we
     * set in config, false otherwise.
     *
     * @return bool
     */
    public function maxAllowedProcessesAreRunning()
    {
        return $this->getCountOfCurrentRunningProcesses() >= $this->maxSimultaneousProcesses;
    }


    /**
     * Iterates through every command added via $this->addCommand() and runs each one.
     *
     * Once this method has started, it will start however many commands we set in max_simultaneous_processes.
     * After it reaches that limit, this method will start a while() loop and check each running process to see
     * if it's still running.
     *
     * If the process has stopped (i.e. it doesn't come up in ps -p) that process is removed from $this->currentProcesses.
     * If that removed process has a callback, it's called at this point.
     *
     * Once the stopped processes are removed from currentProcesses, any remaining commands will be run until we
     * get back to the max allowed. This loop will repeat until all commands are run or we exceed our time limit.
     *
     * NOTE: all commands are run as background processes, so they are completely detached from the PHP process
     * executing this class and no communication between them is possible.
     */
    public function runAllCommands()
    {
        $this->log("Running all commands");
        $this->startTimestamp = time();
        for ($i = 0; $i < count($this->commands);)
        {
            // if we've reached the max number of processes allowed, start monitoring them
            // to see if/when one completes. Then we can remove that process from the list
            // of currently running processes and start a new one.
            if ($this->maxAllowedProcessesAreRunning()) {
                $this->log("we have reached {$this->maxSimultaneousProcesses} simultaneous processes. Waiting for one to finish");
                while (true) {
                    $completedProcessPIDs = array();
                    foreach ($this->currentProcesses as $pid => $process) {
                        if (!$process->processIsRunning()) {
                            $this->log("Process $pid is complete.");
                            $completedProcessPIDs[] = $pid;
                        }
                    }

                    foreach ($completedProcessPIDs as $pid) {
                        $this->removeProcess($pid);
                        $this->executeCallback($pid);
                    }

                    if (!empty($completedProcessPIDs) || $this->timeLimitExceeded()) {
                        break;
                    }

                    // don't bombard the OS with constant requests to see if processes are still
                    // running. Take a break and try again later.
                    $this->log(" -- sleeping - will check again in {$this->processCheckDelay} seconds.");
                    sleep($this->processCheckDelay);
                }
            }

            // if time limit has been exceeded, stop working.
            if ($this->timeLimitExceeded()) {
                $this->log("Time limit has been exceeded.");
                break;
            }

            $commandAndArgs = $this->commands[$i];

            $sugarDaemon = new SugarDaemon($commandAndArgs['command'], $commandAndArgs['arguments']);
            if ($sugarDaemon->execute()) {
                $this->addProcess($sugarDaemon, $commandAndArgs['callback'], $commandAndArgs['callbackArgs']);
            }
            $i++;
        }

        while (count($this->currentProcesses) > 0) {
            foreach ($this->currentProcesses as $pid => $process) {
                if (!$process->processIsRunning()) {
                    $this->log("Process $pid is complete.");
                    $this->removeProcess($pid);
                    $this->executeCallback($pid);
                }
            }

            if ($this->timeLimitExceeded()) {
                $this->log("Time limit has been exceeded.");
                break;
            }
        }

        $this->log("Finished running commands - we ran $i out of " . count($this->commands) . " commands.");

        if (!$this->timeLimitExceeded()) {
            $this->log("Running final callback, if any");
            $this->executeFinalCallback();
        } else {
            $this->log("Time limit exceeded, so not running final callback.");
        }
    }


    /**
     * Gets config values specific to this class. It prepends the correct namespaces to the config name
     * you need.
     *
     * @param string $name - the name of the config value, i.e. time_limit
     * @param string $default - a default value in case the config value isn't set.
     * @return mixed - whatever is in config.
     */
    protected function config($name, $default = 'default')
    {
        $configKey = "sugar_daemon.{$this->name}.$name";
        return \SugarConfig::getInstance()->get($configKey, $default);
    }


    /**
     * Writes log messages.
     *
     * @param $msg
     */
    protected function log($msg)
    {
        $GLOBALS['log']->fatal("SugarDaemonManager:{$this->name}: $msg");
    }
}