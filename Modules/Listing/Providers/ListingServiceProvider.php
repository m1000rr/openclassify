<?php

namespace Modules\Listing\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Listing\App\Console\ExpireListingsCommand;

class ListingServiceProvider extends ServiceProvider
{
    protected string $moduleName = 'Listing';

    protected string $moduleNameLower = 'listing';

    public function boot(): void
    {
        $this->loadViewsFrom(module_path($this->moduleName, 'resources/views'), $this->moduleNameLower);
        $this->loadMigrationsFrom(module_path($this->moduleName, 'Database/migrations'));
        $this->loadRoutesFrom(module_path($this->moduleName, 'routes/web.php'));

        if ($this->app->runningInConsole()) {
            $this->commands([
                ExpireListingsCommand::class,
            ]);
        }
    }

    public function register(): void {}
}
