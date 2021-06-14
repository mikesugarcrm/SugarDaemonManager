<?php
namespace Sugarcrm\Sugarcrm\custom\SugarDaemonManager;


/**
 * Class SugarDaemon
 * @package Sugarcrm\Sugarcrm\custom\SugarDaemonManager
 *
 * The SugarDaemon class represents a running process started by the SugarDaemonManager.
 */
class SugarDaemon
{
    /* string $pathToPHP - the path to the PHP binary - defaults to just 'php'*/
    public $pathToPHP = "php";

    /* string $cmd - the command that will be run in the background */
    public $cmd = '';

    /* string $cmdWrapper - we insert the passed in command and args into this
       wrapper with nohup and routing output to /dev/null to detach the process
       from php.

       Order of args for sprintf:
        - path to php binary
        - command
        - arguments as string.

      The & echo $! at the end gives us the PID of the command when its run.
    */
    public $cmdWrapper = "nohup %s %s %s 1>/dev/null 2>/dev/null & echo $!";

    /* string $pid  - the process ID of the command */
    public $pid = '';

    /* bool $started - indicates whether the $cmd has been run or not */
    public $started;

    /* array $arguments - arguments to be passed to cmd */
    public $arguments = array();


    /**
     * SugarDaemon constructor.
     * @param string $cmd
     * @param array $arguments
     */
    public function __construct($cmd = '', $arguments = array())
    {
        $this->pathToPHP = \SugarConfig::getInstance()->get('sugar_daemon.php_binary', \SugarConfig::getInstance()->get('cron.php_binary', $this->pathToPHP));
        if (!empty($cmd)) {
            $this->setCommand($cmd);
        }

        $this->setArguments($arguments);
    }


    /**
     * Executes this process's command, with its arguments (if any). It uses PHP's exec() function.
     *
     * By the time this method is called, $this->cmd should have been set via $this->setCommand() which will
     * escape the command and its arguments.
     *
     * Returns a process ID if successful, false if not.
     *
     * @return bool|string
     */
    public function execute()
    {
        if (empty($this->cmd)) {
            $this->log("Cannot execute command - no command given");
            return false;
        }

        if (empty($this->pathToPHP)) {
            $this->log("Cannot execute command - no path to php binary is set in config. You need to set either sugar_daemon.php_binary or cron.php_binary");
            return false;
        }

        $command = sprintf($this->cmdWrapper, $this->pathToPHP, $this->cmd, $this->getArgsAsString());
        $this->log("Running command: $command");
        exec($command, $output);
        $this->setPID($output[0]);

        return $this->pid;
    }


    /**
     * Sets the process id for this process after it's run.
     *
     * @param string $pid
     */
    private function setPID($pid)
    {
        $this->log("process id is $pid");
        $this->pid = (int)$pid;
    }


    /**
     * Sets the cmd property to the escaped version of the passed in command.
     *
     * @param string $cmd
     */
    public function setCommand($cmd)
    {
        $this->cmd = escapeshellcmd($cmd);
    }


    /**
     * Accepts either a sequential array or an associative array of arguments to pass to the command
     * specified in $this->cmd.
     *
     * Loops through every argument passed in and escapes it with escapeshellarg(), which just surrounds
     * the argument in single quotes.
     *
     * If we're passed an associative array, the values are escaped but the keys are not.
     *
     * If the argument names require a '-' or two '--' in front of them, you must include those with the key.
     * Argument names that don't include a value are just left blank.
     *
     * Examples: ['--id' => '123xyz', -h => '']
     *
     * @param $args
     */
    public function setArguments($args)
    {
        if (!is_array($args)) {
            $args = array($args);
        }

        if (array_keys($args) === range(0, count($args) - 1)) {
            // sequential array means pass bare arguments, not option=value pairs.
            foreach ($args as $arg) {
                $this->arguments[] = escapeshellarg($arg);
            }
        } else {
            // associative array means pass option=value pairs.
            foreach ($args as $option => $value) {
                if (empty($value)) {
                    $this->arguments[$option] = '';
                } else {
                    $this->arguments[$option] = escapeshellarg($value);
                }
            }
        }
    }


    /**
     * Formats the arguments for outputting them to the command line.
     *
     * For sequential arrays, it will just separate them with a space. All args will be surrounded by single quotes.
     *
     * For associative arrays, the keys and values will be output in pairs, i.e. arg1='value1', arg2='value2', etc.
     *
     * Please note that different OS's have different limits for how many characters you can pass on the command line.
     *
     * @return string
     */
    public function getArgsAsString()
    {
        if (array_keys($this->arguments) === range(0, count($this->arguments) - 1)) {
            // sequential array means pass bare arguments, not option=value pairs.
            return implode(' ', $this->arguments);
        } else {
            // associative array means pass option=value pairs.
            $pairs = array();
            foreach ($this->arguments as $option => $value) {
                if ($value !== '') {
                    $pairs[] = "{$option}={$value}";
                } else {
                    $pairs[] = "{$option}";
                }
            }
            return implode(' ', $pairs);
        }
    }


    /**
     * Runs ps -p for this object's pid. Returns true if the pid is found, false if not.
     *
     * @return bool
     */
    public function processIsRunning()
    {
        $command = "ps -p {$this->pid}";
        exec($command, $output);
        if (isset($output[1])) {
            //$this->log("Process {$this->pid} is running");
            return true;
        }
        //$this->log("Process {$this->pid} has stopped");
        return false;
    }


    /**
     * Runs kill against this object's pid.
     *
     * If the process is no longer running after running kill, this method returns true. It returns false if
     * the process is still running.
     *
     * @return bool
     */
    public function kill()
    {
        $this->log("Killing process {$this->pid}");
        $command = "kill {$this->pid}";
        exec($command, $output);

        if ($this->processIsRunning()) {
            $this->log("Process {$this->pid} cannot die!");
            return false;
        }

        $this->log("Process {$this->pid} is dead.");
        return true;
    }


    /**
     * Writes log messages.
     *
     * @param $msg
     */
    protected function log($msg)
    {
        $GLOBALS['log']->fatal("SugarDaemon:{$this->pid}: $msg");
    }
}