<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\ProductionSeeder;
use Database\Seeders\StarterCatalogSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Alta de un cliente nuevo: deja la instancia lista para usar en un comando.
 *
 * Pensado para correr UNA vez, recién migrada la base. Siembra solo lo
 * estructural (ProductionSeeder), guarda los datos del negocio y crea el usuario
 * dueño con su contraseña. No carga productos ni clientes de ejemplo.
 *
 * Sirve interactivo (pregunta todo) o con opciones, para poder automatizarlo
 * desde el script de provisioning sin que nadie tipee nada.
 */
class InstallClientCommand extends Command
{
    protected $signature = 'instalar:cliente
        {--negocio= : Nombre del negocio}
        {--nit= : NIT (opcional)}
        {--telefono= : Teléfono de contacto}
        {--direccion= : Dirección}
        {--zona=America/La_Paz : Zona horaria}
        {--moneda=Bs : Símbolo de moneda}
        {--facturacion : Arranca con la facturación activada}
        {--duenio= : Nombre de la persona dueña}
        {--email= : Correo con el que entra al sistema}
        {--password= : Contraseña; si se omite se genera una segura}
        {--rol=admin : Rol del usuario dueño: admin o emprendedor}
        {--rubro=ninguno : Categorias sugeridas: ninguno, talabarteria o tienda}
        {--force : Seguir aunque la base ya tenga datos}';

    protected $description = 'Prepara esta instancia para un cliente nuevo: datos del negocio, usuario dueño y catálogo base.';

    public function handle(): int
    {
        if (! Schema::hasTable('settings') || ! Schema::hasTable('users')) {
            $this->error('La base todavía no tiene las tablas. Corré primero: php artisan migrate');

            return self::FAILURE;
        }

        if (! $this->baseVacia() && ! $this->option('force')) {
            $this->error('Esta base ya tiene usuarios o productos: no parece una instancia nueva.');
            $this->line('Si igual querés seguir (y pisar los ajustes del negocio), repetí con --force.');

            return self::FAILURE;
        }

        $negocio = $this->valor('negocio', 'Nombre del negocio', 'Mi negocio');
        $duenio = $this->valor('duenio', 'Nombre de la persona dueña', 'Dueño');
        $email = $this->valor('email', 'Correo con el que va a entrar al sistema', '');

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error("El correo '{$email}' no es válido.");

            return self::FAILURE;
        }

        if (User::where('email', $email)->exists()) {
            $this->error("Ya existe un usuario con el correo {$email}.");

            return self::FAILURE;
        }

        $zona = (string) $this->option('zona');
        if (! in_array($zona, \DateTimeZone::listIdentifiers(), true)) {
            $this->error("La zona horaria '{$zona}' no existe.");

            return self::FAILURE;
        }

        $rol = $this->option('rol');
        if (! in_array($rol, ['admin', 'emprendedor'], true)) {
            $this->error("El rol '{$rol}' no existe. Usá admin o emprendedor.");

            return self::FAILURE;
        }

        $rubro = $this->option('rubro');
        if ($rubro !== 'ninguno' && ! isset(StarterCatalogSeeder::RUBROS[$rubro])) {
            $this->error("El rubro '{$rubro}' no existe. Opciones: ninguno, " . implode(', ', array_keys(StarterCatalogSeeder::RUBROS)) . '.');

            return self::FAILURE;
        }

        $password = $this->option('password') ?: Str::password(14, true, true, false);
        $generada = ! $this->option('password');

        $this->info("Preparando la instancia de «{$negocio}»…");

        // 1. Estructura: roles, plan de cuentas, período, categorías de caja, unidades.
        $this->callSilent('db:seed', ['--class' => ProductionSeeder::class, '--force' => true]);
        $this->line('  ✓ Plan de cuentas, roles, período contable y categorías de caja');

        // 2. Datos del negocio.
        Setting::set('store_name', $negocio);
        Setting::set('store_nit', (string) $this->option('nit'));
        Setting::set('store_phone', (string) $this->option('telefono'));
        Setting::set('store_address', (string) $this->option('direccion'));
        Setting::set('business_timezone', $zona);
        Setting::set('currency_symbol', $this->option('moneda') ?: 'Bs');
        Setting::set('facturacion_activada', $this->option('facturacion') ? '1' : '0');
        $this->line('  ✓ Datos del negocio');

        // 3. Catálogo sugerido del rubro (sin productos: esos los carga el cliente).
        if ($rubro !== 'ninguno') {
            $seeder = new StarterCatalogSeeder();
            $seeder->rubro = $rubro;
            $seeder->run();
            $this->line('  ✓ Categorías y unidades sugeridas: ' . StarterCatalogSeeder::RUBROS[$rubro]['label']);
        }

        // 4. Usuario dueño.
        $user = User::create([
            'name' => $duenio,
            'username' => Str::slug($duenio, '') ?: 'duenio',
            'email' => $email,
            'password' => Hash::make($password),
        ]);
        // email_verified_at no es asignable en masa; sin esto el sistema le pediría
        // verificar un correo que nadie le mandó y no podría entrar.
        $user->forceFill(['email_verified_at' => now()])->save();
        $user->assignRole($rol);
        $this->line("  ✓ Usuario «{$duenio}» con rol {$rol}");

        $this->newLine();
        $this->info('Listo. Entrá con:');
        $this->table(['Dato', 'Valor'], [
            ['Negocio', $negocio],
            ['Correo', $email],
            ['Contraseña', $generada ? $password . '  (generada: anotala ahora)' : '(la que indicaste)'],
            ['Rol', $rol],
            ['Zona horaria', $zona],
            ['Facturación', $this->option('facturacion') ? 'activada' : 'apagada'],
        ]);

        $this->newLine();
        $this->line('Siguientes pasos sugeridos:');
        $this->line('  1. Entrar y cambiar la contraseña.');
        $this->line('  2. Ajustes → Mi negocio: subir el logo.');
        $this->line('  3. Cargar los productos con su precio y su stock.');
        $this->line('  4. Si va a vender por catálogo: Ajustes → Tienda en línea.');

        return self::SUCCESS;
    }

    /** ¿Es una instancia nueva? Usuarios y productos son la señal más clara. */
    private function baseVacia(): bool
    {
        return User::count() === 0 && Product::count() === 0;
    }

    /**
     * Valor de una opción; si falta y hay alguien del otro lado, lo pregunta.
     * Sin terminal interactiva cae al valor por defecto, para poder automatizar.
     */
    private function valor(string $opcion, string $pregunta, string $defecto): string
    {
        $valor = (string) $this->option($opcion);

        if ($valor === '' && $this->input->isInteractive()) {
            $valor = (string) $this->ask($pregunta, $defecto !== '' ? $defecto : null);
        }

        return trim($valor !== '' ? $valor : $defecto);
    }
}
