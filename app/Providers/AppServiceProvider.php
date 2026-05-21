<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Console\Scheduling\Schedule;
use App\Events\LowStockDetected;
use App\Listeners\NotifyLowStock;
use App\Console\Commands\SendDailySummaryCommand;
use App\Services\AuditService;
use App\Services\Agent\ToolRegistry;
use App\Services\Agent\Tools\SearchProductsTool;
use App\Services\Agent\Tools\GetStockTool;
use App\Services\Agent\Tools\GetSalesTodayTool;
use App\Services\Agent\Tools\GetTopSellersTool;
use App\Services\Agent\Tools\ListLocationsTool;
use App\Services\Agent\Tools\GetLowStockTool;
use App\Services\Agent\Tools\ListProductsTool;
use App\Services\Agent\Tools\StartSaleTool;
use App\Services\Agent\Tools\StartProductCreationTool;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(AuditService::class);

        $this->app->singleton(ToolRegistry::class, function ($app) {
            $registry = new ToolRegistry();
            $registry->register($app->make(SearchProductsTool::class));
            $registry->register($app->make(GetStockTool::class));
            $registry->register($app->make(GetSalesTodayTool::class));
            $registry->register($app->make(GetTopSellersTool::class));
            $registry->register($app->make(ListLocationsTool::class));
            $registry->register($app->make(StartSaleTool::class));
            $registry->register($app->make(StartProductCreationTool::class));
            $registry->register($app->make(GetLowStockTool::class));
            $registry->register($app->make(ListProductsTool::class));
            return $registry;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Blade::directive('money', function ($expression) {
            return "<?php echo format_money($expression); ?>";
        });
        //if (env('APP_ENV') === 'production') {
          //  \Illuminate\Support\Facades\URL::forceScheme('https');
        //}

        Event::listen(LowStockDetected::class, NotifyLowStock::class);

        $this->app->booted(function () {
            $schedule = $this->app->make(Schedule::class);
            $schedule->command(SendDailySummaryCommand::class)->dailyAt('20:00');
        });
    }
}
