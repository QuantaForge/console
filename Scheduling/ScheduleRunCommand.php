<?php

namespace QuantaForge\Console\Scheduling;

use QuantaForge\Console\Application;
use QuantaForge\Console\Command;
use QuantaForge\Console\Events\ScheduledTaskFailed;
use QuantaForge\Console\Events\ScheduledTaskFinished;
use QuantaForge\Console\Events\ScheduledTaskSkipped;
use QuantaForge\Console\Events\ScheduledTaskStarting;
use QuantaForge\Contracts\Cache\Repository as Cache;
use QuantaForge\Contracts\Debug\ExceptionHandler;
use QuantaForge\Contracts\Events\Dispatcher;
use QuantaForge\Support\Carbon;
use QuantaForge\Support\Facades\Date;
use QuantaForge\Support\Sleep;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

#[AsCommand(name: 'schedule:run')]
class ScheduleRunCommand extends Command
{
    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'schedule:run';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run the scheduled commands';

    /**
     * The schedule instance.
     *
     * @var \QuantaForge\Console\Scheduling\Schedule
     */
    protected $schedule;

    /**
     * The 24 hour timestamp this scheduler command started running.
     *
     * @var \QuantaForge\Support\Carbon
     */
    protected $startedAt;

    /**
     * Check if any events ran.
     *
     * @var bool
     */
    protected $eventsRan = false;

    /**
     * The event dispatcher.
     *
     * @var \QuantaForge\Contracts\Events\Dispatcher
     */
    protected $dispatcher;

    /**
     * The exception handler.
     *
     * @var \QuantaForge\Contracts\Debug\ExceptionHandler
     */
    protected $handler;

    /**
     * The cache store implementation.
     *
     * @var \QuantaForge\Contracts\Cache\Repository
     */
    protected $cache;

    /**
     * The PHP binary used by the command.
     *
     * @var string
     */
    protected $phpBinary;

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->startedAt = Date::now();

        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @param  \QuantaForge\Console\Scheduling\Schedule  $schedule
     * @param  \QuantaForge\Contracts\Events\Dispatcher  $dispatcher
     * @param  \QuantaForge\Contracts\Cache\Repository  $cache
     * @param  \QuantaForge\Contracts\Debug\ExceptionHandler  $handler
     * @return void
     */
    public function handle(Schedule $schedule, Dispatcher $dispatcher, Cache $cache, ExceptionHandler $handler)
    {
        $this->schedule = $schedule;
        $this->dispatcher = $dispatcher;
        $this->cache = $cache;
        $this->handler = $handler;
        $this->phpBinary = Application::phpBinary();

        $this->clearInterruptSignal();

        $this->newLine();

        $events = $this->schedule->dueEvents($this->quantaforge);

        foreach ($events as $event) {
            if (! $event->filtersPass($this->quantaforge)) {
                $this->dispatcher->dispatch(new ScheduledTaskSkipped($event));

                continue;
            }

            if ($event->onOneServer) {
                $this->runSingleServerEvent($event);
            } else {
                $this->runEvent($event);
            }

            $this->eventsRan = true;
        }

        if ($events->contains->isRepeatable()) {
            $this->repeatEvents($events->filter->isRepeatable());
        }

        if (! $this->eventsRan) {
            $this->components->info('No scheduled commands are ready to run.');
        } else {
            $this->newLine();
        }
    }

    /**
     * Run the given single server event.
     *
     * @param  \QuantaForge\Console\Scheduling\Event  $event
     * @return void
     */
    protected function runSingleServerEvent($event)
    {
        if ($this->schedule->serverShouldRun($event, $this->startedAt)) {
            $this->runEvent($event);
        } else {
            $this->components->info(sprintf(
                'Skipping [%s], as command already run on another server.', $event->getSummaryForDisplay()
            ));
        }
    }

    /**
     * Run the given event.
     *
     * @param  \QuantaForge\Console\Scheduling\Event  $event
     * @return void
     */
    protected function runEvent($event)
    {
        $summary = $event->getSummaryForDisplay();

        $command = $event instanceof CallbackEvent
            ? $summary
            : trim(str_replace($this->phpBinary, '', $event->command));

        $description = sprintf(
            '<fg=gray>%s</> Running [%s]%s',
            Carbon::now()->format('Y-m-d H:i:s'),
            $command,
            $event->runInBackground ? ' in background' : '',
        );

        $this->components->task($description, function () use ($event) {
            $this->dispatcher->dispatch(new ScheduledTaskStarting($event));

            $start = microtime(true);

            try {
                $event->run($this->quantaforge);

                $this->dispatcher->dispatch(new ScheduledTaskFinished(
                    $event,
                    round(microtime(true) - $start, 2)
                ));

                $this->eventsRan = true;
            } catch (Throwable $e) {
                $this->dispatcher->dispatch(new ScheduledTaskFailed($event, $e));

                $this->handler->report($e);
            }

            return $event->exitCode == 0;
        });

        if (! $event instanceof CallbackEvent) {
            $this->components->bulletList([
                $event->getSummaryForDisplay(),
            ]);
        }
    }

    /**
     * Run the given repeating events.
     *
     * @param  \QuantaForge\Support\Collection<\QuantaForge\Console\Scheduling\Event>  $events
     * @return void
     */
    protected function repeatEvents($events)
    {
        $hasEnteredMaintenanceMode = false;

        while (Date::now()->lte($this->startedAt->endOfMinute())) {
            foreach ($events as $event) {
                if ($this->shouldInterrupt()) {
                    return;
                }

                if (! $event->shouldRepeatNow()) {
                    continue;
                }

                $hasEnteredMaintenanceMode = $hasEnteredMaintenanceMode || $this->quantaforge->isDownForMaintenance();

                if ($hasEnteredMaintenanceMode && ! $event->runsInMaintenanceMode()) {
                    continue;
                }

                if (! $event->filtersPass($this->quantaforge)) {
                    $this->dispatcher->dispatch(new ScheduledTaskSkipped($event));

                    continue;
                }

                if ($event->onOneServer) {
                    $this->runSingleServerEvent($event);
                } else {
                    $this->runEvent($event);
                }

                $this->eventsRan = true;
            }

            Sleep::usleep(100000);
        }
    }

    /**
     * Determine if the schedule run should be interrupted.
     *
     * @return bool
     */
    protected function shouldInterrupt()
    {
        return $this->cache->get('quantaforge:schedule:interrupt', false);
    }

    /**
     * Ensure the interrupt signal is cleared.
     *
     * @return bool
     */
    protected function clearInterruptSignal()
    {
        $this->cache->forget('quantaforge:schedule:interrupt');
    }
}
