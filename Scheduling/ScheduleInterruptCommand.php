<?php

namespace QuantaForge\Console\Scheduling;

use QuantaForge\Console\Command;
use QuantaForge\Contracts\Cache\Repository as Cache;
use QuantaForge\Support\Facades\Date;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'schedule:interrupt')]
class ScheduleInterruptCommand extends Command
{
    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'schedule:interrupt';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Interrupt the current schedule run';

    /**
     * The cache store implementation.
     *
     * @var \QuantaForge\Contracts\Cache\Repository
     */
    protected $cache;

    /**
     * Create a new schedule interrupt command.
     *
     * @param  \QuantaForge\Contracts\Cache\Repository  $cache
     * @return void
     */
    public function __construct(Cache $cache)
    {
        parent::__construct();

        $this->cache = $cache;
    }

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle()
    {
        $this->cache->put('quantaforge:schedule:interrupt', true, Date::now()->endOfMinute());

        $this->components->info('Broadcasting schedule interrupt signal.');
    }
}
