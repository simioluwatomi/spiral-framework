<?php
/**
 * spiral
 *
 * @author    Wolfy-J
 */

namespace Spiral\Console;

use Spiral\Console\Configs\ConsoleConfig;
use Spiral\Console\Exceptions\ConsoleException;
use Spiral\Console\Logging\DebugHandler;
use Spiral\Core\Component;
use Spiral\Core\Container;
use Spiral\Core\Container\SingletonInterface;
use Spiral\Core\ContainerInterface;
use Spiral\Core\Core;
use Spiral\Core\DispatcherInterface;
use Spiral\Core\MemoryInterface;
use Spiral\Core\NullMemory;
use Spiral\Debug\LogManager;
use Spiral\Debug\SnapshotInterface;
use Symfony\Component\Console\Application as ConsoleApplication;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Used as application dispatcher in console mode. Can execute automatically locate and execute
 * every available Symfony command.
 */
class ConsoleDispatcher extends Component implements SingletonInterface, DispatcherInterface
{
    /**
     * Undefined response code for command (errors). See below.
     */
    const CODE_UNDEFINED = 102;

    /**
     * @var ConsoleApplication
     */
    private $application = null;

    /**
     * Active console output.
     *
     * @var ConsoleOutput
     */
    private $output = null;

    /**
     * @var ConsoleConfig
     */
    protected $config;

    /**
     * @invisible
     * @var ContainerInterface
     */
    protected $container;

    /**
     * @invisible
     * @var MemoryInterface
     */
    protected $memory;

    /**
     * @invisible
     * @var LocatorInterface
     */
    protected $locator;

    /**
     * @param ConsoleConfig           $config
     * @param ContainerInterface|null $container
     * @param MemoryInterface|null    $memory
     * @param LocatorInterface|null   $locator
     */
    public function __construct(
        ConsoleConfig $config,
        ContainerInterface $container = null,
        MemoryInterface $memory = null,
        LocatorInterface $locator = null
    ) {
        $this->config = $config;
        $this->container = $container ?? new Container();
        $this->memory = $memory ?? new NullMemory();
        $this->locator = $locator ?? new NullLocator();
    }

    /**
     * {@inheritdoc}
     *
     * @param InputInterface  $input
     * @param OutputInterface $output
     */
    public function start()
    {
        //Let's keep output reference to render exceptions
        $input = new ArgvInput();
        $this->output = new ConsoleOutput();
        $this->configureIO($input, $this->output);

        $this->runScoped(function () use ($input) {
            $this->consoleApplication()->run($input, $this->output);
        }, $this->output);
    }

    /**
     * Execute console command by it's name. Attention, this method will automatically set debug
     * handler which will display log messages into console when verbosity is ON, hovewer, already
     * existed Logger instances would not be affected.
     *
     * @param string|null          $command Default command when null.
     * @param array|InputInterface $input
     * @param OutputInterface      $output
     *
     * @return CommandOutput
     *
     * @throws ConsoleException
     */
    public function run(
        string $command = null,
        $input = [],
        OutputInterface $output = null
    ): CommandOutput {
        if (is_array($input)) {
            $input = new ArrayInput($input + compact('command'));
        }

        $output = $output ?? new BufferedOutput();

        $code = $this->runScoped(function () use ($input, $output, $command) {
            return $this->consoleApplication()->find($command)->run($input, $output);
        }, $output);

        return new CommandOutput($code ?? self::CODE_UNDEFINED, $output);
    }

    /**
     * Get or create instance of ConsoleApplication.
     *
     * @return ConsoleApplication
     */
    public function consoleApplication()
    {
        if (!empty($this->application)) {
            //Already initiated
            return $this->application;
        }

        $commands = [];
        foreach ($this->getCommands() as $command) {
            $commands[] = $this->container->get($command);
        }

        $this->application = new ConsoleApplication('Spiral Framework', Core::VERSION);
        $this->application->setCatchExceptions(false);
        $this->application->addCommands($commands);

        return $this->application;
    }

    /**
     * Locate every available Symfony command using Tokenizer.
     *
     * @param bool $reset Ignore cache.
     *
     * @return array
     */
    public function getCommands(bool $reset = false): array
    {
        $commands = (array)$this->memory->loadData('commands');
        if (!empty($commands) && !$reset) {
            //Reading from cache
            return $commands + $this->config->userCommands();
        }

        if ($this->config->locateCommands()) {
            //Automatically locate commands
            $commands = $this->locator->locateCommands();
        }

        //Warming up cache
        $this->memory->saveData('commands', $commands);

        return $commands + $this->config->userCommands();
    }

    /**
     * {@inheritdoc}
     *
     * @param OutputInterface $output
     */
    public function handleSnapshot(SnapshotInterface $snapshot)
    {
        if ($this->output == null) {
            // never started
            return;
        }

        $this->output->writeln('', OutputInterface::VERBOSITY_QUIET);
        $this->container->get(ErrorWriter::class)->renderException(
            $this->output->getErrorOutput(),
            $snapshot->getException()
        );
    }

    /**
     * Run method in console IoC scope.
     *
     * @param \Closure                                          $closure
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     *
     * @return mixed
     */
    private function runScoped(\Closure $closure, OutputInterface $output)
    {
        //Each command are executed in a specific environment
        $scope = self::staticContainer($this->container);

        //This handler will allow us to enable verbosity mode
        $debugHandler = $this->container->get(LogManager::class)->debugHandler(
            new DebugHandler($output)
        );

        try {
            return $closure->call($this);
        } finally {
            //Restore default debug handler and container scope
            $this->container->get(LogManager::class)->debugHandler($debugHandler);
            self::staticContainer($scope);
        }
    }

    /**
     * Reduced version of Symfony::configureIO.
     *
     * @source Symfony\Component\Console::configureIO()
     *
     * @param InputInterface  $input
     * @param OutputInterface $output
     */
    private function configureIO(InputInterface $input, OutputInterface $output)
    {
        if (true === $input->hasParameterOption(['--ansi'], true)) {
            $output->setDecorated(true);
        } elseif (true === $input->hasParameterOption(['--no-ansi'], true)) {
            $output->setDecorated(false);
        }

        if (true === $input->hasParameterOption(['--quiet', '-q'], true)) {
            $output->setVerbosity(OutputInterface::VERBOSITY_QUIET);

            return;
        }

        if (
            $input->hasParameterOption('-vvv', true)
            || $input->hasParameterOption('--verbose=3', true)
            || 3 === $input->getParameterOption('--verbose', false, true)
        ) {
            $output->setVerbosity(OutputInterface::VERBOSITY_DEBUG);
        } elseif (
            $input->hasParameterOption('-vv', true)
            || $input->hasParameterOption('--verbose=2', true)
            || 2 === $input->getParameterOption('--verbose', false, true)
        ) {
            $output->setVerbosity(OutputInterface::VERBOSITY_VERY_VERBOSE);
        } elseif (
            $input->hasParameterOption('-v', true)
            || $input->hasParameterOption('--verbose=1', true)
            || $input->hasParameterOption('--verbose', true)
            || $input->getParameterOption('--verbose', false, true)
        ) {
            $output->setVerbosity(OutputInterface::VERBOSITY_VERBOSE);
        }
    }
}