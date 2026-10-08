<?php

namespace Tests\Feature\Ui;

use App\Models\User;
use App\Support\Ui\Navigation;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * El menú es uno solo (App\Support\Ui\Navigation) y cada rol ve exactamente lo
 * que le corresponde. Si alguien agrega un ítem sin gatearlo, acá se cae.
 */
class NavigationMenuTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->assignRole($role);

        return $user;
    }

    /**
     * Menú visible como árbol plano: 'Sección' => ['Grupo' => ['Ítem', ...]].
     *
     * @return array<string, array<string, array<int, string>>>
     */
    private function menuFor(User $user): array
    {
        $sections = Navigation::mainFor($user);
        if ($settings = Navigation::settingsFor($user)) {
            $sections[] = $settings;
        }

        $tree = [];
        foreach ($sections as $section) {
            $groups = [];
            foreach ($section['groups'] as $group) {
                $groups[$group['label'] ?? '-'] = array_column($group['items'], 'label');
            }
            $tree[$section['label']] = $groups;
        }

        return $tree;
    }

    public function test_el_developer_ve_todo_incluido_lo_tecnico(): void
    {
        $this->assertSame([
            'Inicio' => [],
            'Ventas' => ['-' => ['Vender', 'Ventas', 'Clientes']],
            'Compras' => ['-' => ['Compras', 'Proveedores']],
            'Productos' => [
                '-' => ['Productos', 'Informe de productos', 'Kardex valorizado', 'Transferencias', 'Stock por ubicación'],
                'Datos maestros' => ['Categorías', 'Unidades', 'Almacenes', 'Ubicaciones'],
            ],
            'Finanzas' => ['-' => ['Resumen financiero', 'Tesorería', 'Contabilidad', 'Activos y operaciones']],
            'Usuarios' => [
                '-' => ['Usuarios', 'Planilla de sueldos'],
                'Solo desarrollador' => ['Roles y permisos'],
            ],
            'Ajustes' => [
                '-' => ['Ajustes del sistema', 'Página de la tienda'],
                'Solo desarrollador' => ['Copias de seguridad'],
            ],
        ], $this->menuFor($this->userWithRole('developer')));
    }

    public function test_el_admin_ve_todo_el_negocio_pero_nada_tecnico(): void
    {
        $this->assertSame([
            'Inicio' => [],
            'Ventas' => ['-' => ['Vender', 'Ventas', 'Clientes']],
            'Compras' => ['-' => ['Compras', 'Proveedores']],
            'Productos' => [
                '-' => ['Productos', 'Informe de productos', 'Kardex valorizado', 'Transferencias', 'Stock por ubicación'],
                'Datos maestros' => ['Categorías', 'Unidades', 'Almacenes', 'Ubicaciones'],
            ],
            'Finanzas' => ['-' => ['Resumen financiero', 'Tesorería', 'Contabilidad', 'Activos y operaciones']],
            'Usuarios' => ['-' => ['Usuarios', 'Planilla de sueldos']],
            'Ajustes' => ['-' => ['Ajustes del sistema', 'Página de la tienda']],
        ], $this->menuFor($this->userWithRole('admin')));
    }

    public function test_el_emprendedor_no_ve_contabilidad_ni_almacenes_ni_usuarios(): void
    {
        $this->assertSame([
            'Inicio' => [],
            'Ventas' => ['-' => ['Vender', 'Ventas', 'Clientes']],
            'Compras' => ['-' => ['Compras', 'Proveedores']],
            'Productos' => [
                '-' => ['Productos', 'Informe de productos'],
                'Datos maestros' => ['Categorías', 'Unidades'],
            ],
            'Ajustes' => ['-' => ['Ajustes del sistema', 'Página de la tienda']],
        ], $this->menuFor($this->userWithRole('emprendedor')));
    }

    public function test_el_staff_solo_ve_el_pos(): void
    {
        $this->assertSame([
            'Inicio' => [],
            'Ventas' => ['-' => ['Vender', 'Ventas', 'Clientes']],
            'Productos' => ['-' => ['Productos', 'Informe de productos']],
        ], $this->menuFor($this->userWithRole('staff')));
    }

    public function test_el_html_del_menu_respeta_los_permisos(): void
    {
        // Etiquetas que solo existen en el menú, así que identifican la rama.
        $this->actingAs($this->userWithRole('staff'))->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('Datos maestros')
            ->assertDontSee('Planilla de sueldos')
            ->assertDontSee('Roles y permisos')
            ->assertDontSee('Copias de seguridad')
            ->assertDontSee('Ajustes del sistema');
    }

    public function test_el_menu_del_admin_trae_ajustes_fuera_del_avatar(): void
    {
        $this->actingAs($this->userWithRole('admin'))->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Ajustes del sistema')
            ->assertSee('Datos maestros')
            ->assertSee('Planilla de sueldos')
            ->assertDontSee('Roles y permisos')
            ->assertDontSee('Copias de seguridad');
    }

    public function test_solo_el_developer_ve_roles_y_copias_de_seguridad(): void
    {
        $this->actingAs($this->userWithRole('developer'))->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Roles y permisos')
            ->assertSee('Copias de seguridad')
            ->assertSee('Solo desarrollador');
    }

    public function test_el_menu_ya_no_dice_panel_ni_usa_emojis(): void
    {
        $html = $this->actingAs($this->userWithRole('developer'))
            ->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringNotContainsString('>Panel<', $html);
        $this->assertStringNotContainsString('🔧', $html);
        $this->assertStringContainsString('Inicio', $html);
    }

    public function test_escritorio_y_movil_renderizan_los_mismos_items(): void
    {
        $user = $this->userWithRole('admin');
        $html = $this->actingAs($user)->get(route('dashboard'))->assertOk()->getContent();

        // Solo el bloque del menú: el cuerpo de la página también enlaza a
        // Productos o a Vender y falsearía la cuenta.
        $start = strpos($html, 'print:hidden');
        $this->assertNotFalse($start, 'No se encontró el bloque de navegación.');
        $nav = substr($html, $start, strpos($html, '</section>', $start) - $start);

        $sections = Navigation::mainFor($user);
        $sections[] = Navigation::settingsFor($user);

        // Cada ítem visible aparece dos veces (escritorio + cajón móvil): si uno
        // de los dos menús se olvida de un ítem, esto se cae.
        foreach (array_filter($sections) as $section) {
            foreach ($section['groups'] as $group) {
                foreach ($group['items'] as $item) {
                    $this->assertSame(
                        2,
                        substr_count($nav, 'href="' . $item['url'] . '"'),
                        "El ítem '{$item['label']}' no está en los dos menús."
                    );
                }
            }
        }
    }
}
