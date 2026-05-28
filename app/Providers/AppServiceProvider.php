<?php

namespace App\Providers;

use App\Application\GatewayLog\Services\GatewayLogParser;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(GatewayLogParser::class, function (): GatewayLogParser {
            return new GatewayLogParser(
                timezone: config('app.timezone', 'UTC'),
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
