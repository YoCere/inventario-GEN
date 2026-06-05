<?php

namespace App\Console\Commands;

use App\Services\Accounting\DepreciationService;
use Illuminate\Console\Command;

class RunDepreciation extends Command
{
    protected $signature = 'depreciation:run {--month=}';

    protected $description = 'Postea la depreciación/amortización mensual de los activos fijos.';

    public function handle(DepreciationService $service): int
    {
        $month = $this->option('month') ?: now()->format('Y-m');
        $result = $service->runForMonth($month);
        $this->info("Depreciación {$month}: {$result['processed']} activos, total " . number_format($result['total'] / 100, 2) . " Bs, {$result['skipped']} ya posteados.");
        return self::SUCCESS;
    }
}
