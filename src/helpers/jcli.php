<?php
foreach (glob(getcwd() . '/applications/cli/*') as $files) {
    require_once $files;
}

use splitbrain\phpcli\CLI;
use splitbrain\phpcli\Options;

abstract class jCli
{
    /**
     * constant prompt type warning
     */
    protected const PROMPT_TYPE_WARNING = 'warning';

    /**
     * constant prompt type success
     */
    protected const PROMPT_TYPE_SUCCESS = 'success';

    /**
     * constant prompt type error
     */
    protected const PROMPT_TYPE_ERROR = 'error';

    /**
     * constant prompt type info
     */
    protected const PROMPT_TYPE_INFO = 'info';

    /**
     * subcommand for the cli
     *
     * @var string
     */
    private $_command;

    /**
     * options of the cli object
     *
     * @var Options
     */
    private $_options;

    /**
     * the cli object which runs all the custom cli classes
     *
     * @var CLI
     */
    private $_cliObject;


    /**
     * creates an object of the given class name and returns it.
     *
     * @param string $className
     * 
     * @return jCli
     */
    public static function factory(string $className): jCli
    {
        return new $className();
    }

    /**
     * set options of cli to add command, options and arguments
     *
     * @param Options $options
     * 
     * @return jCli
     */
    public function setCliOption(Options &$options): jCli
    {
        $this->_options = $options;
        return $this;
    }

    /**
     * set options of cli to add command, options and arguments
     *
     * @param Options $options
     * 
     * @return jCli
     */
    public function setCliObject(CLI &$cliObject): jCli
    {
        $this->_cliObject = $cliObject;
        return $this;
    }

    /**
     * registers a command to the option
     *
     * @param string $command
     * @param string $description
     * 
     * @return void
     */
    protected function addCommand(string $command, string $description)
    {
        $this->_command = $command;
        $this->_options->registerCommand($command, $description);
    }

    /**
     * register options to the cli options
     *
     * @param string $longFlag
     * @param string $description
     * @param string|null $shortFlag
     * @param boolean|string $needsarg  - if a string is given, it will take it as a required argument
     * 
     * @throws Exception
     * 
     * @return void
     */
    protected function addOption(string $longFlag, string $description, string|null $shortFlag = null, bool|string $needsarg = false)
    {
        if (!isset($this->_command)) {
            throw new Exception('Add a command first');
        }

        $this->_options->registerOption($longFlag, $description, $shortFlag, $needsarg, $this->_command);
    }

    /**
     * add a general argument
     *
     * @param string $arg
     * @param string $description
     * @param boolean $required
     * 
     * @throws Exception
     * 
     * @return void
     */
    protected function addArgument($arg, $description, $required = true)
    {
        if (!isset($this->_command)) {
            throw new Exception('Add a command first');
        }

        $this->_options->registerArgument($arg, $description, $required, $this->_command);
    }

    /**
     * set prompt for the cli output
     *
     * @param string $text - the text to show
     * @param string $type - type of prompt. it can be either 4 of the constants, 
     * PROMPT_TYPE_WARNING | PROMPT_TYPE_SUCCESS | PROMPT_TYPE_ERROR | PROMPT_TYPE_INFO
     * 
     * @return void
     */
    protected function prompt(string $text, string $type = self::PROMPT_TYPE_INFO)
    {
        $this->_cliObject->$type($text);
    }

    /**
     * all kind of setups will be here, eg. addCommand, addOptions etc.
     *
     * @return void
     */
    abstract public function setup();

    /**
     * all the logics which will be performed by this command
     *
     * @return bool - if successful return true else false.
     */
    abstract public function perform(array $options, array $args = []): bool;
}
