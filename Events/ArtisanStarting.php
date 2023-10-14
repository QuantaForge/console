<?php

namespace QuantaForge\Console\Events;

class ArtisanStarting
{
    /**
     * The Artisan application instance.
     *
     * @var \QuantaForge\Console\Application
     */
    public $artisan;

    /**
     * Create a new event instance.
     *
     * @param  \QuantaForge\Console\Application  $artisan
     * @return void
     */
    public function __construct($artisan)
    {
        $this->artisan = $artisan;
    }
}
