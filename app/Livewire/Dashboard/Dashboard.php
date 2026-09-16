<?php

namespace App\Livewire\Dashboard;

use Carbon\Carbon;
use Livewire\Component;
use App\Enums\DatePeriod;
use App\Models\AccountingPeriod;
use App\Models\Product;
use App\Models\Sale;
use App\Services\DashboardStatsService;
use App\Support\BusinessTime;

class Dashboard extends Component
{
    /** Períodos que ofrece el selector segmentado de Inicio. */
    public const PERIOD_OPTIONS = [
        DatePeriod::TODAY,
        DatePeriod::THIS_WEEK,
        DatePeriod::THIS_MONTH,
        DatePeriod::CUSTOM,
    ];

    public string $dateFilter = DatePeriod::TODAY->value;
    public ?string $customStartDate = null;
    public ?string $customEndDate = null;

    public array $stats = [];
    public array $lowStockProducts = [];
    public int $lowStockCount = 0;
    public array $recentSales = [];
    public array $weekSales = [];

    public function mount(DashboardStatsService $service)
    {
        $this->loadStats($service);
    }

    public function setPeriod(string $period): void
    {
        $this->dateFilter = DatePeriod::tryFrom($period)?->value ?? DatePeriod::TODAY->value;

        // Personalizado espera a que el usuario elija el rango.
        if ($this->dateFilter !== DatePeriod::CUSTOM->value) {
            $this->loadStats(app(DashboardStatsService::class));
        }
    }

    public function updateCustomRange($startDate, $endDate)
    {
        $this->customStartDate = $startDate;
        $this->customEndDate = $endDate;

        if ($this->dateFilter === DatePeriod::CUSTOM->value) {
            $this->loadStats(app(DashboardStatsService::class));
        }
    }

    public function loadStats(DashboardStatsService $service)
    {
        [$startDate, $endDate] = $this->getDateRange();

        $salesStats = $service->getSalesStats($startDate, $endDate, $this->dateFilter);

        $this->stats = [
            'total_sales' => $salesStats['total_revenue'],
            'sales_count' => $salesStats['count'],
            'gross_profit' => $salesStats['gross_profit'],
        ];

        // Caja solo se consulta si el usuario puede verla.
        if (auth()->user()?->can('finance.view')) {
            $cashFlowStats = $service->getCashFlowStats($startDate, $endDate, $this->dateFilter);
            $this->stats += [
                'income' => $cashFlowStats['income'],
                'expense' => $cashFlowStats['expense'],
                'net_cash_flow' => $cashFlowStats['net_cash_flow'],
            ];
        }

        $this->lowStockProducts = $service->getLowStockProducts(5);
        $this->lowStockCount = $service->getLowStockCount();
        $this->recentSales = $service->getRecentSales(4);

        $today = Carbon::now();
        $this->weekSales = $service->getSalesTrend(
            $today->copy()->subDays(6)->startOfDay(),
            $today->copy()->endOfDay()
        );
    }

    /** Ticket promedio en centavos, null si no hubo ventas. */
    public function getAverageTicketProperty(): ?float
    {
        $count = (int) ($this->stats['sales_count'] ?? 0);

        return $count > 0 ? ((float) $this->stats['total_sales']) / $count : null;
    }

    /** Cuántos Bs quedan de ganancia por cada Bs 100 vendidos. */
    public function getProfitPerHundredProperty(): ?int
    {
        $sales = (float) ($this->stats['total_sales'] ?? 0);

        return $sales > 0 ? (int) round(((float) $this->stats['gross_profit']) / $sales * 100) : null;
    }

    protected function getDateRange(): array
    {
        $now = Carbon::now();

        return match(DatePeriod::tryFrom($this->dateFilter)) {
            DatePeriod::TODAY => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
            DatePeriod::YESTERDAY => [$now->copy()->subDay()->startOfDay(), $now->copy()->subDay()->endOfDay()],
            DatePeriod::THIS_WEEK => [$now->copy()->startOfWeek(), $now->copy()->endOfWeek()],
            DatePeriod::THIS_MONTH => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()],
            DatePeriod::LAST_MONTH => [$now->copy()->subMonth()->startOfMonth(), $now->copy()->subMonth()->endOfMonth()],
            DatePeriod::CUSTOM => $this->customStartDate && $this->customEndDate
                ? [Carbon::parse($this->customStartDate)->startOfDay(), Carbon::parse($this->customEndDate)->endOfDay()]
                : [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
            default => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
        };
    }

    public function render()
    {
        $user = auth()->user();
        $localNow = BusinessTime::now()->locale('es');
        $hour = (int) $localNow->format('G');

        $onboarding = null;
        if ($user?->hasRole('emprendedor')) {
            $hasProducts = Product::query()->exists();
            $hasSales = Sale::query()->exists();
            if (! ($hasProducts && $hasSales)) {
                $onboarding = ['products' => $hasProducts, 'sales' => $hasSales];
            }
        }

        return view('livewire.dashboard.dashboard', [
            'greeting' => $hour < 12 ? 'Buenos días' : ($hour < 19 ? 'Buenas tardes' : 'Buenas noches'),
            'firstName' => strtok((string) $user?->name, ' ') ?: '',
            'todayLabel' => $localNow->isoFormat('dddd D [de] MMMM'),
            'periodAlert' => $user?->isAdmin() ? AccountingPeriod::dashboardAlert() : null,
            'onboarding' => $onboarding,
            'weekBars' => $this->buildWeekBars(),
        ]);
    }

    /** Barras de los últimos 7 días: etiqueta corta, monto y alto relativo. */
    protected function buildWeekBars(): array
    {
        $max = max(array_map('floatval', $this->weekSales) ?: [0]);
        $todayKey = Carbon::now()->format('Y-m-d');

        $bars = [];
        foreach ($this->weekSales as $date => $total) {
            $day = Carbon::parse($date)->locale('es');
            $isToday = $date === $todayKey;
            $bars[] = [
                'label' => $isToday ? 'Hoy' : ucfirst(rtrim($day->isoFormat('ddd'), '.')),
                'title' => ucfirst($day->isoFormat('dddd D [de] MMMM')),
                'total' => (float) $total,
                'height' => $max > 0 ? max(2, (int) round(((float) $total) / $max * 100)) : 2,
                'is_today' => $isToday,
            ];
        }

        return $bars;
    }
}
