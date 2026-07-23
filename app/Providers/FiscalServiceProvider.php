<?php

namespace App\Providers;

use App\Fiscal\Siat\FiscalProvider;
use App\Fiscal\Siat\SimulatorFiscalProvider;
use App\Fiscal\Siat\SiatFiscalProvider;
use App\Models\Setting;
use Illuminate\Support\ServiceProvider;

class FiscalServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(FiscalProvider::class, function () {
            return Setting::get('fiscal_provider', 'simulator') === 'siat'
                ? $this->app->make(SiatFiscalProvider::class)
                : $this->app->make(SimulatorFiscalProvider::class);
        });
    }
}
