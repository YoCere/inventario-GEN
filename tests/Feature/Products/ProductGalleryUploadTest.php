<?php

namespace Tests\Feature\Products;

use App\Livewire\Products\ProductForm;
use App\Models\Category;
use App\Models\Location;
use App\Models\Product;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Shop\Models\ProductImage;
use App\Shop\Services\ImageProcessor;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Subir fotos del producto desde la web.
 *
 * Lo que pasó en producción: la dueña subía una foto desde el celular, la
 * pantalla tiraba un error 500 y el producto **tampoco se guardaba**: había que
 * cargar todo de nuevo. Dos defectos, y estos tests cubren los dos:
 *
 *  1. Cualquier problema con una imagen tiene que salir como mensaje claro,
 *     nunca como excepción que se escapa (antes el procesador atrapaba el error
 *     al abrir la imagen y seguía con una variable sin definir → fatal → 500).
 *  2. Una foto mala no puede costar el producto entero.
 */
class ProductGalleryUploadTest extends TestCase
{
    use RefreshDatabase;

    private Unit $unidad;
    private Category $categoria;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->unidad = Unit::factory()->create();
        $this->categoria = Category::factory()->create(['name' => 'Cintos', 'slug' => 'cintos']);

        $almacen = Warehouse::create(['name' => 'Almacén Principal', 'is_default' => true]);
        Location::create([
            'warehouse_id' => $almacen->id,
            'name' => 'Estante Principal',
            'is_default' => true,
        ]);
    }

    public function test_una_foto_que_el_servidor_no_puede_abrir_no_cuesta_el_producto(): void
    {
        // Un .jpg que en realidad no es una imagen: es lo que llega cuando la
        // conversión de HEIC en el celular no funciona o el archivo viene cortado.
        $rota = UploadedFile::fake()->create('foto-del-iphone.jpg', 40);

        $componente = $this->formularioCargado()
            ->set('gallery', [$rota])
            ->call('save');

        $producto = Product::where('name', 'Cinto de cuero')->first();

        $this->assertNotNull($producto, 'El producto tenía que guardarse igual que si no hubiera foto.');
        $this->assertSame(0, ProductImage::where('product_id', $producto->id)->count());

        $avisos = $this->toastsDeError($componente->effects['dispatches'] ?? []);
        $this->assertNotEmpty($avisos, 'Tenía que avisar que esa foto no se pudo usar.');
        $this->assertStringContainsString('no pudimos abrir', mb_strtolower(implode(' ', $avisos)));
    }

    public function test_la_foto_buena_se_guarda_aunque_otra_falle(): void
    {
        $buena = UploadedFile::fake()->image('cinto.jpg', 900, 700);
        $rota = UploadedFile::fake()->create('cinto-2.jpg', 40);

        $this->formularioCargado()
            ->set('gallery', [$buena, $rota])
            ->call('save');

        $producto = Product::where('name', 'Cinto de cuero')->sole();
        $imagenes = ProductImage::where('product_id', $producto->id)->get();

        $this->assertCount(1, $imagenes);
        $this->assertTrue($imagenes[0]->is_primary, 'La única foto guardada queda como principal.');

        foreach (['path_full', 'path_card', 'path_thumb'] as $variante) {
            Storage::disk('public')->assertExists($imagenes[0]->{$variante});
        }
    }

    public function test_el_procesador_avisa_en_vez_de_romperse_cuando_no_es_una_imagen(): void
    {
        // Este es el test que se caería si volviera el `catch` vacío: antes de
        // arreglarlo, acá saltaba un Error de PHP (variable sin definir), no una
        // RuntimeException con mensaje para la dueña.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No pudimos abrir ese archivo como imagen');

        app(ImageProcessor::class)->processForProduct(
            UploadedFile::fake()->create('basura.jpg', 10),
            1
        );
    }

    public function test_un_heic_sin_convertir_explica_que_hacer(): void
    {
        try {
            app(ImageProcessor::class)->processForProduct(
                UploadedFile::fake()->create('IMG_0042.heic', 2048),
                1
            );
            $this->fail('Un HEIC que el servidor no puede abrir tenía que dar RuntimeException.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('HEIC', $e->getMessage());
            $this->assertStringContainsString('Telegram', $e->getMessage());
        }
    }

    /**
     * La foto que no entra en memoria se rechaza ANTES de abrirla: un fatal por
     * falta de memoria no se puede atrapar y termina en 500.
     */
    public function test_una_foto_que_no_entra_en_memoria_se_rechaza_antes_de_abrirla(): void
    {
        $procesador = app(ImageProcessor::class);
        $metodo = new \ReflectionMethod($procesador, 'asegurarMemoria');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('demasiado pesada para el servidor');

        // 50.000 x 50.000 píxeles: no hay memory_limit que lo aguante.
        $metodo->invoke($procesador, 50000, 50000, 2500.0, ['file' => 'enorme.jpg']);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function formularioCargado(): \Livewire\Features\SupportTesting\Testable
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->assignRole('emprendedor');

        return Livewire::actingAs($user)
            ->test(ProductForm::class)
            ->call('create')
            ->set('name', 'Cinto de cuero')
            ->set('category_id', $this->categoria->id)
            ->set('unit_id', $this->unidad->id)
            ->set('purchase_price', 12000)
            ->set('selling_price', 25000)
            ->set('quantity', 5)
            ->set('min_stock', 1);
    }

    /**
     * @param  array<int, array<string, mixed>>  $dispatches
     * @return array<int, string>
     */
    private function toastsDeError(array $dispatches): array
    {
        $mensajes = [];

        foreach ($dispatches as $evento) {
            if (($evento['name'] ?? null) !== 'toast') {
                continue;
            }

            $params = $evento['params'] ?? [];
            if (($params['type'] ?? null) === 'error') {
                $mensajes[] = (string) ($params['message'] ?? '');
            }
        }

        return $mensajes;
    }
}
