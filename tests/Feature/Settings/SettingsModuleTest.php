<?php

namespace Tests\Feature\Settings;

use App\Livewire\Settings\SettingsPage;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class SettingsModuleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function userWithRole(string $role, array $attributes = []): User
    {
        $user = User::factory()->create(['email_verified_at' => now(), ...$attributes]);
        $user->assignRole($role);

        return $user;
    }

    public function test_admin_ve_secciones_de_negocio_y_contabilidad_pero_no_sistema(): void
    {
        $this->actingAs($this->userWithRole('admin'))
            ->get(route('settings.index'))
            ->assertOk()
            ->assertSeeText('Mi negocio')
            ->assertSeeText('Moneda')
            ->assertSeeText('Facturación e impuestos')
            ->assertSeeText('Tienda en línea')
            ->assertSeeText('Sueldos')
            ->assertSeeText('Contabilidad')
            ->assertDontSeeText('Sistema')
            ->assertDontSeeText('Edit Setting')
            // Regresión: un componente Blade mal compilado sale como etiqueta cruda.
            ->assertSee('id="setting-store_name"', false)
            ->assertDontSee('<x-', false);
    }

    public function test_emprendedor_entra_solo_a_lo_basico(): void
    {
        $emprendedor = $this->userWithRole('emprendedor');

        $this->actingAs($emprendedor)
            ->get(route('settings.index'))
            ->assertOk()
            ->assertSeeText('Mi negocio')
            ->assertSeeText('Tienda en línea')
            ->assertDontSeeText('Sueldos')
            ->assertDontSeeText('Contabilidad')
            ->assertDontSeeText('Sistema');

        // En Facturación ve el interruptor pero no las tasas ni cuentas.
        Livewire::actingAs($emprendedor)->test(SettingsPage::class)
            ->call('goTo', 'facturacion')
            ->assertSet('section', 'facturacion')
            ->assertSeeText('Emitir facturas')
            ->assertDontSeeText('Para tu contador')
            ->assertSet('values', ['facturacion_activada' => '0']);

        // No puede abrir una sección fuera de su permiso.
        Livewire::actingAs($emprendedor)->test(SettingsPage::class)
            ->call('goTo', 'sueldos')
            ->assertSet('section', 'negocio');
    }

    public function test_emprendedor_no_puede_forzar_tasas_de_impuestos(): void
    {
        Setting::set('tax_iva_rate', '13');

        Livewire::actingAs($this->userWithRole('emprendedor'))->test(SettingsPage::class)
            ->call('goTo', 'facturacion')
            ->set('values.facturacion_activada', '1')
            ->set('values.tax_iva_rate', '99')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('1', Setting::get('facturacion_activada'));
        $this->assertSame('13', Setting::get('tax_iva_rate'));
    }

    public function test_staff_no_entra_a_ajustes(): void
    {
        $this->actingAs($this->userWithRole('staff'))
            ->get(route('settings.index'))
            ->assertForbidden();
    }

    public function test_guarda_mi_negocio_y_valida_nombre(): void
    {
        $admin = $this->userWithRole('admin');

        Livewire::actingAs($admin)->test(SettingsPage::class)
            ->set('values.store_name', '')
            ->call('save')
            ->assertHasErrors(['values.store_name' => 'required']);

        Livewire::actingAs($admin)->test(SettingsPage::class)
            ->set('values.store_name', '  Tienda Doña Rosa  ')
            ->set('values.store_phone', '70012345')
            ->set('values.business_timezone', 'America/Lima')
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('settings-saved');

        $this->assertSame('Tienda Doña Rosa', Setting::get('store_name'));
        $this->assertSame('70012345', Setting::get('store_phone'));
        $this->assertSame('America/Lima', Setting::get('business_timezone'));
    }

    public function test_moneda_no_permite_separadores_iguales(): void
    {
        Livewire::actingAs($this->userWithRole('admin'))->test(SettingsPage::class)
            ->call('goTo', 'moneda')
            ->set('values.currency_thousand_separator', ',')
            ->set('values.currency_decimal_separator', ',')
            ->call('save')
            ->assertHasErrors('values.currency_decimal_separator');
    }

    public function test_moneda_muestra_vista_previa_y_guarda_espacio_como_separador(): void
    {
        $component = Livewire::actingAs($this->userWithRole('admin'))->test(SettingsPage::class)
            ->call('goTo', 'moneda')
            ->set('values.currency_symbol', 'S/')
            ->set('values.currency_thousand_separator', ' ')
            ->set('values.currency_decimal_separator', '.')
            ->set('values.currency_position', 'left');

        $this->assertSame('S/ 1 234.50', $component->instance()->currencyPreview);

        $component->call('save')->assertHasNoErrors();
        $this->assertSame(' ', Setting::get('currency_thousand_separator'));
    }

    public function test_cuentas_de_iva_se_guardan_en_las_claves_que_lee_la_contabilidad(): void
    {
        Livewire::actingAs($this->userWithRole('admin'))->test(SettingsPage::class)
            ->call('goTo', 'facturacion')
            ->set('values.accounting_cf_iva_code', '1.1.09')
            ->set('values.accounting_df_iva_code', '2.1.20')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('1.1.09', Setting::get('accounting_cf_iva_code'));
        $this->assertSame('2.1.20', Setting::get('accounting_df_iva_code'));
    }

    public function test_cuenta_contable_invalida_se_rechaza(): void
    {
        Livewire::actingAs($this->userWithRole('admin'))->test(SettingsPage::class)
            ->call('goTo', 'facturacion')
            ->set('values.accounting_cf_iva_code', 'caja chica')
            ->call('save')
            ->assertHasErrors('values.accounting_cf_iva_code');
    }

    public function test_saldo_inicial_exige_contrasena(): void
    {
        $admin = $this->userWithRole('admin', ['password' => Hash::make('secreto123')]);
        Setting::set('opening_balance_amount', '0');

        Livewire::actingAs($admin)->test(SettingsPage::class)
            ->call('goTo', 'contabilidad')
            ->set('values.opening_balance_amount', '5000')
            ->call('save')
            ->assertHasErrors('password');
        $this->assertSame('0', Setting::get('opening_balance_amount'));

        Livewire::actingAs($admin)->test(SettingsPage::class)
            ->call('goTo', 'contabilidad')
            ->set('values.opening_balance_amount', '5000')
            ->set('password', 'secreto123')
            ->call('save')
            ->assertHasNoErrors();
        $this->assertSame('5000', Setting::get('opening_balance_amount'));

        // Cambiar otro campo de la sección no pide contraseña.
        Livewire::actingAs($admin)->test(SettingsPage::class)
            ->call('goTo', 'contabilidad')
            ->set('values.company_entity_type', 'srl')
            ->call('save')
            ->assertHasNoErrors();
        $this->assertSame('srl', Setting::get('company_entity_type'));
    }

    public function test_developer_ve_sistema_y_los_secretos_no_viajan_al_navegador(): void
    {
        Setting::set('telegram_bot_token', '123456:ABCDEF-token-real');
        $developer = $this->userWithRole('developer');

        $component = Livewire::actingAs($developer)->test(SettingsPage::class)
            ->call('goTo', 'sistema')
            ->assertSet('values.telegram_bot_token', '')
            ->assertDontSee('ABCDEF-token-real')
            ->assertSee('••••••••real');

        // Vacío = conservar el secreto actual.
        $component->set('values.telegram_admin_chat_id', '999')->call('save')->assertHasNoErrors();
        $this->assertSame('123456:ABCDEF-token-real', Setting::get('telegram_bot_token'));

        // Escribir uno nuevo lo reemplaza.
        $component->set('values.telegram_bot_token', 'nuevo-token')->call('save')->assertHasNoErrors();
        $this->assertSame('nuevo-token', Setting::get('telegram_bot_token'));
    }

    public function test_admin_no_puede_publicar_la_tienda_ni_ver_sistema(): void
    {
        $admin = $this->userWithRole('admin');

        Livewire::actingAs($admin)->test(SettingsPage::class)
            ->call('goTo', 'tienda')
            ->assertSet('section', 'tienda')
            ->set('values.shop_enabled', '1')
            ->call('save');
        $this->assertNotSame('1', Setting::get('shop_enabled'));

        Livewire::actingAs($admin)->test(SettingsPage::class)
            ->call('goTo', 'sistema')
            ->assertSet('section', 'negocio');
    }

    public function test_paleta_se_aplica_al_guardar(): void
    {
        Livewire::actingAs($this->userWithRole('admin'))->test(SettingsPage::class)
            ->call('goTo', 'tienda')
            ->call('applyPalette', 'verde')
            ->assertSet('values.shop_primary_color', '#16A34A')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('#16A34A', Setting::get('shop_primary_color'));
    }

    public function test_seccion_invalida_en_url_cae_en_la_primera(): void
    {
        Livewire::withQueryParams(['seccion' => 'no-existe'])
            ->actingAs($this->userWithRole('admin'))
            ->test(SettingsPage::class)
            ->assertSet('section', 'negocio');
    }
}
