<?php

namespace App\Console\Commands;

use App\Fiscal\Invoicing\FiscalReadiness;
use Illuminate\Console\Command;

class FiscalReadinessCommand extends Command
{
    protected $signature = 'fiscal:readiness';

    protected $description = 'Lista qué datos faltan para facturar (settings, códigos SIN de productos y unidades)';

    public function handle(FiscalReadiness $readiness): int
    {
        $r = $readiness->report();

        $this->line($r['ready'] ? '<info>Listo para facturar.</info>' : '<comment>Faltan datos:</comment>');
        foreach ($r['missing_settings'] as $k) {
            $this->line("  - setting: {$k}");
        }
        if ($r['products_missing_sin_code']) {
            $this->line('  - productos sin código SIN: '.count($r['products_missing_sin_code']).' (ids '.implode(',', array_slice($r['products_missing_sin_code'], 0, 20)).')');
        }
        if ($r['units_missing_sin_code']) {
            $this->line('  - unidades sin código SIN: ids '.implode(',', $r['units_missing_sin_code']));
        }

        return self::SUCCESS; // informativo: no falla el deploy
    }
}
