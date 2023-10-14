<?php

namespace QuantaForge\Console;

use QuantaForge\Console\View\Components\Factory;
use QuantaForge\Contracts\Console\Isolatable;
use QuantaForge\Support\Traits\Macroable;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class Command extends SymfonyCommand
{
    use Concerns\CallsCommands,
        Concerns\ConfiguresPrompts,
        Concerns\HasParameters,
        Concerns\InteractsWithIO,
        Concerns\InteractsWithSignals,
        Concerns\PromptsForMissingInput,
        Macroable;

    /**
     * The QuantaForge application instance.
     *
     * @var \QuantaForge\Contracts\Foundation\Application
     */
    protected $quantaforge;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature;

    /**
     * The console command name.
     *
     * @var string
     */
    protected $name;

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description;

    /**
     * The console command help text.
     *
     * @var string
     */
    protected $help;

    /**
     * Indicates whether the command should be shown in the Artisan command list.
     *
     * @var bool
     */
    protected $hidden = false;

    /**
     * Indicates whether only one instance of the command can run at any given time.
     *
     * @var bool
     */
    protected $isolated = false;

    /**
     * The default exit code for isolated commands.
     *
     * @var int
     */
    protected $isolatedExitCode = self::SUCCESS;

    /**
     * The console command name aliases.
     *
     * @var array
     */
    protected $aliases;

    /**
     * Create a new console command instance.
     *
     * @return void
     */
    public function __construct()
    {
        // We will go ahead and set the name, description, and parameters on console
        // commands just to make things a little easier on the developer. This is
        // so they don't have to all be manually specified in the constructors.
        if (isset($this->signature)) {
            $this->configureUsingFluentDefinition();
        } else {
            parent::__construct($this->name);
        }

        // Once we have constructed the command, we'll set the description and other
        // related properties of the command. If a signature wasn't used to build
        // the command we'll set the arguments and the options on this command.
        if (! isset($this->description)) {
            $this->setDescription((string) static::getDefaultDescription());
        } else {
            $this->setDescription((string) $this->description);
        }

        $this->setHelp((string) $this->help);

        $this->setHidden($this->isHidden());

        if (isset($this->aliases)) {
            $this->setAliases((array) $this->aliases);
        }

        if (! isset($this->signature)) {
            $this->specifyParameters();
        }

        if ($this instanceof Isolatable) {
            $this->configureIsolation();
        }
    }

    /**
     * Configure the console command using a fluent definition.
     *
     * @return void
     */
    protected function configureUsingFluentDefinition()
    {
        [$name, $arguments, $options] = Parser::parse($this->signature);

        parent::__construct($this->name = $name);

        // After parsing the signature we will spin through the arguments and options
        // and set them on this command. These will already be changed into proper
        // instances of these "InputArgument" and "InputOption" Symfony classes.
        $this->getDefinition()->addArguments($arguments);
        $this->getDefinition()->addOptions($options);
    }

    /**
     * Configure the console command for isolation.
     *
     * @return void
     */
    protected function configureIsolation()
    {
        $this->getDefinition()->addOption(new InputOption(
            'isolated',
            null,
            InputOption::VALUE_OPTIONAL,
            'Do not run the command if another instance of the command is already running',
            $this->isolated
        ));
    }

    /**
     * Run the console command.
     *
     * @param  \Symfony\Component\Console\Input\InputInterface  $input
     * @param  \Symfony\Component\Console\Output\OutputInterface  $output
     * @return int
     */
    public function run(InputInterface $input, OutputInterface $output): int
    {
        $this->output = $output instanceof OutputStyle ? $output : $this->quantaforge->make(
            OutputStyle::class, ['input' => $input, 'output' => $output]
        );

        $this->components = $this->quantaforge->make(Factory::class, ['output' => $this->output]);

        $this->configurePrompts($input);

        try {
            return parent::run(
                $this->input = $input, $this->output
            );
        } finally {
            $this->untrap();
        }
    }

    /**
     * Execute the console command.
     *
     * @param  \Symfony\Component\Console\Input\InputInterface  $input
     * @param  \Symfony\Component\Console\Output\OutputInterface  $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if ($this instanceof Isolatable && $this->option('isolated') !== false &&
            ! $this->commandIsolationMutex()->create($this)) {
            $this->comment(sprintf(
                'The [%s] command is already running.', $this->getName()
            ));

            return (int) (is_numeric($this->option('isolated'))
                        ? $this->option('isolated')
                        : $this->isolatedExitCode);
        }

        $method = method_exists($this, 'handle') ? 'handle' : '__invoke';

        try {
            return (int) $this->quantaforge->call([$this, $method]);
        } finally {
            if ($this instanceof Isolatable && $this->option('isolated') !== false) {
                $this->commandIsolationMutex()->forget($this);
            }
        }
    }

    /**
     * Get a command isolation mutex instance for the command.
     *
     * @return \QuantaForge\Console\CommandMutex
     */
    protected function commandIsolationMutex()
    {
        return $this->quantaforge->bound(CommandMutex::class)
            ? $this->quantaforge->make(CommandMutex::class)
            : $this->quantaforge->make(CacheCommandMutex::class);
    }

    /**
     * Resolve the console command instance for the given command.
     *
     * @param  \Symfony\Component\Console\Command\Command|string  $command
     * @return \Symfony\Component\Console\Command\Command
     */
    protected function resolveCommand($command)
    {
        if (! class_exists($command)) {
            return $this->getApplication()->find($command);
        }

        $command = $this->quantaforge->make($command);

        if ($command instanceof SymfonyCommand) {
            $command->setApplication($this->getApplication());
        }

        if ($command instanceof self) {
            $command->setQuantaForge($this->getQuantaForge());
        }

        return $command;
    }

    /**
     * {@inheritdoc}
     *
     * @return bool
     */
    public function isHidden(): bool
    {
        return $this->hidden;
    }

    /**
     * {@inheritdoc}
     */
    public function setHidden(bool $hidden = true): static
    {
        parent::setHidden($this->hidden = $hidden);

        return $this;
    }

    /**
     * Get the QuantaForge application instance.
     *
     * @return \QuantaForge\Contracts\Foundation\Application
     */
    public function getQuantaForge()
    {
        return $this->quantaforge;
    }

    /**
     * Set the QuantaForge application instance.
     *
     * @param  \QuantaForge\Contracts\Container\Container  $quantaforge
     * @return void
     */
    public function setQuantaForge($quantaforge)
    {
        $this->quantaforge = $quantaforge;
    }
}
