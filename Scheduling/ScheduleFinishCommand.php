<?php

namespace QuantaForge\Console\Scheduling;

use QuantaForge\Console\Command;
use QuantaForge\Console\Events\ScheduledBackgroundTaskFinished;
use QuantaForge\Contracts\Events\Dispatcher;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'schedule:finish')]
class ScheduleFinishCommand extends Command
{
    /**
     * The console command name.
     *
     * @var string
     */
    protected $signature = 'schedule:finish {id} {code=0}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Handle the completion of a scheduled command';

    /**
     * Indicates whether the command should be shown in the Artisan command list.
     *
     * @var bool
     */
    protected $hidden = true;

    /**
     * Execute the console command.
     *
     * @param  \QuantaForge\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    public function handle(Schedule $schedule)
    {
        collect($schedule->events())->filter(function ($value) {
            return $value->mutexName() == $this->argument('id');
        })->each(function ($event) {
            $event->finish($this->quantaforge, $this->argument('code'));

            $this->quantaforge->make(Dispatcher::class)->dispatch(new ScheduledBackgroundTaskFinished($event));
        });
    }
}
