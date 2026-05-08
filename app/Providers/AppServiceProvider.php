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

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(AuditService::class);
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
