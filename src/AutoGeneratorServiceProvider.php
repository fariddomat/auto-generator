<?php

namespace Fariddomat\AutoGenerator;

use Illuminate\Support\ServiceProvider;

class AutoGeneratorServiceProvider extends ServiceProvider
{
    public function boot()
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                Commands\MakeAuto::class,
            ]);
        }

        $this->publishes([
            __DIR__ . '/../views' => resource_path('views/vendor/auto-generator'),
        ], 'autogenerator-views');
    }

    public function register()
    {
        // No registration needed yet
    }
}