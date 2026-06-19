<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Illuminate\Console\Scheduling\Schedule;
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
use App\Services\Agent\Tools\GetFinancialStatusTool;
use App\Services\Agent\Tools\GetIncomeAndExpensesTool;
use App\Services\Agent\Tools\GetBalanceSheetTool;
use App\Services\Agent\Tools\CreateReminderTool;
use App\Services\Agent\Tools\ListRemindersTool;
use App\Services\Agent\Tools\CancelReminderTool;

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
            $registry->register($app->make(GetFinancialStatusTool::class));
            $registry->register($app->make(GetIncomeAndExpensesTool::class));
            $registry->register($app->make(GetBalanceSheetTool::class));
            $registry->register($app->make(CreateReminderTool::class));
            $registry->register($app->make(ListRemindersTool::class));
            $registry->register($app->make(CancelReminderTool::class));
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

        // NotifyLowStock se registra por auto-discovery de Laravel 11
        // (handle(LowStockDetected)). No agregar Event::listen manual aquí:
        // duplicaría el listener y enviaría la alerta de Telegram dos veces.

        // Developer = super-usuario absoluto. Gate::before corre antes que
        // cualquier policy / permission y, si retorna true, autoriza la
        // acción independientemente de los permisos asignados.
        //
        // Si retorna null sigue el flujo normal de permisos (spatie/policy).
        Gate::before(function ($user, string $ability) {
            return $user->hasRole('developer') ? true : null;
        });

        $this->app->booted(function () {
            $schedule = $this->app->make(Schedule::class);
            $schedule->command(SendDailySummaryCommand::class)->dailyAt('20:00');
        });
    }
}
