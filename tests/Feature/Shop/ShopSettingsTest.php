<?php

namespace Tests\Feature\Shop;

use App\Models\Setting;
use App\Shop\ShopSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShopSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_nombre_de_tienda_usa_el_del_negocio_si_esta_vacio(): void
    {
        Setting::set('store_name', 'Importadora El Cóndor');
        Setting::set('shop_business_name', '');

        $this->assertSame('Importadora El Cóndor', ShopSettings::businessName());

        Setting::set('shop_business_name', 'El Cóndor Online');
        $this->assertSame('El Cóndor Online', ShopSettings::businessName());
    }

    public function test_moneda_de_tienda_es_la_del_sistema(): void
    {
        Setting::set('currency_symbol', 'S/');

        $this->assertSame('S/', ShopSettings::currencySymbol());
    }
}
