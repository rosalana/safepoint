<?php

namespace Rosalana\Safepoint\Providers;

use Illuminate\Support\ServiceProvider;
use Rosalana\Safepoint\Commands\GenerateCommand;

class RosalanaSafepointServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     *
     * @return void
     */
    public function register()
    {
        //
    }

    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot()
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                GenerateCommand::class,
            ]);
        }
    }
}
