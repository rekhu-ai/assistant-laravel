<?php

namespace Rekhu\Assistant;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;
use Rekhu\Assistant\Console\InstallCommand;
use Rekhu\Assistant\Console\StatusCommand;
use Rekhu\Assistant\View\Widget;

class AssistantServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/rekhu-assistant.php', 'rekhu-assistant');
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/assistant.php');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'rekhu-assistant');
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'rekhu-assistant');
        Blade::component('rekhu-assistant', Widget::class);

        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/rekhu-assistant.php' => config_path('rekhu-assistant.php')], 'rekhu-assistant-config');
            $this->publishes([__DIR__.'/../resources/lang' => $this->app->langPath('vendor/rekhu-assistant')], 'rekhu-assistant-lang');
            $this->commands([InstallCommand::class, StatusCommand::class]);
        }
    }
}
