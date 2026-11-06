<?php

namespace App\Fiscal\Invoicing;

use App\Models\Product;
use App\Models\Setting;
use App\Models\Unit;

/** Qué falta para poder emitir. Solo lectura, sin red. */
class FiscalReadiness
{
    private const REQUIRED_SETTINGS = ['falmus_api_key', 'falmus_point_of_sale_id', 'fiscal_default_activity'];

    /** @return array{ready:bool, missing_settings:list<string>, products_missing_sin_code:list<int>, units_missing_sin_code:list<int>} */
    public function report(): array
    {
        $missingSettings = array_values(array_filter(
            self::REQUIRED_SETTINGS,
            fn ($k) => trim((string) Setting::get($k, '')) === ''
        ));

        $products = Product::query()
            ->where(fn ($q) => $q->whereNull('sin_code')->orWhere('sin_code', ''))
            ->orderBy('id')->pluck('id')->all();

        $units = Unit::query()
            ->whereIn('id', Product::query()->whereNotNull('unit_id')->select('unit_id'))
            ->where(fn ($q) => $q->whereNull('sin_code')->orWhere('sin_code', ''))
            ->orderBy('id')->pluck('id')->all();

        return [
            'ready' => $missingSettings === [] && $products === [] && $units === [],
            'missing_settings' => $missingSettings,
            'products_missing_sin_code' => $products,
            'units_missing_sin_code' => $units,
        ];
    }
}
