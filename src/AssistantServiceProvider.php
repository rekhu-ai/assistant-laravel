<?php

namespace Rekhu\Assistant;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Rekhu\Assistant\Console\CheckCommand;
use Rekhu\Assistant\Console\InstallCommand;
use Rekhu\Assistant\Console\MakeToolCommand;
use Rekhu\Assistant\Console\PruneCommand;
use Rekhu\Assistant\Console\ScanCommand;
use Rekhu\Assistant\Console\StatusCommand;
use Rekhu\Assistant\Console\TestCommand;
use Rekhu\Assistant\Console\TryCommand;
use Rekhu\Assistant\Runtime\Conversation;
use Rekhu\Assistant\View\Widget;

class AssistantServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/rekhu-assistant.php', 'rekhu-assistant');
    }

    public function boot(): void
    {
        RateLimiter::for('rekhu-assistant', fn (Request $request) => Limit::perMinute(30)->by(Conversation::owner($request->user(), null)));
        // the app may register the routes itself, in its own route file (RekhuAssistant::routes())
        if (! RekhuAssistant::$ignoresRoutes && config('rekhu-assistant.routes', true)) {
            $this->loadRoutesFrom(__DIR__.'/../routes/assistant.php');
        }
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'rekhu-assistant');
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'rekhu-assistant');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        // conversations kept past conversations.keep_days go once a day, with the app's own scheduler
        $this->callAfterResolving(Schedule::class, fn (Schedule $schedule) => $schedule->command(PruneCommand::class)->daily()->withoutOverlapping()->runInBackground());
        Blade::component('rekhu-assistant', Widget::class);

        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/rekhu-assistant.php' => config_path('rekhu-assistant.php')], 'rekhu-assistant-config');
            $this->publishes([__DIR__.'/../resources/lang' => $this->app->langPath('vendor/rekhu-assistant')], 'rekhu-assistant-lang');
            $this->publishes([__DIR__.'/../database/migrations' => database_path('migrations')], 'rekhu-assistant-migrations');
            $this->commands([InstallCommand::class, StatusCommand::class, MakeToolCommand::class, TryCommand::class, CheckCommand::class, ScanCommand::class, TestCommand::class, PruneCommand::class]);
        }
    }
}
