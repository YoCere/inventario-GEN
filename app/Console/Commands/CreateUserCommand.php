<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\Models\Role;

/**
 * Crea un usuario desde la consola.
 *
 * Existe sobre todo para el acceso del proveedor (rol developer) en la instancia
 * de un cliente: `instalar:cliente` crea a la persona dueña y nada más, a
 * propósito, para no dejar usuarios de más en una instancia nueva.
 */
class CreateUserCommand extends Command
{
    protected $signature = 'usuario:crear
        {--nombre= : Nombre de la persona}
        {--email= : Correo con el que entra}
        {--rol=developer : Rol: developer, admin, emprendedor o staff}
        {--password= : Contraseña; si se omite se genera una segura}';

    protected $description = 'Crea un usuario con su rol (por defecto developer, para el acceso del proveedor).';

    public function handle(): int
    {
        $nombre = $this->valor('nombre', 'Nombre de la persona');
        $email = $this->valor('email', 'Correo con el que entra');
        $rol = (string) $this->option('rol');

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error("El correo '{$email}' no es válido.");

            return self::FAILURE;
        }

        if (User::where('email', $email)->exists()) {
            $this->error("Ya existe un usuario con el correo {$email}.");

            return self::FAILURE;
        }

        if (! Role::where('name', $rol)->exists()) {
            $roles = Role::pluck('name')->implode(', ');
            $this->error("El rol '{$rol}' no existe. Roles disponibles: " . ($roles !== '' ? $roles : 'ninguno — ¿corriste instalar:cliente?') . '.');

            return self::FAILURE;
        }

        $password = (string) $this->option('password');
        $generada = $password === '';
        if ($generada) {
            $password = Str::password(14, true, true, false);
        } else {
            $validator = validator(
                ['password' => $password],
                ['password' => ['required', Password::min(8)]],
                ['password' => 'La contraseña debe tener al menos 8 caracteres.']
            );

            if ($validator->fails()) {
                $this->error($validator->errors()->first('password'));

                return self::FAILURE;
            }
        }

        $user = User::create([
            'name' => $nombre,
            'username' => $this->usernameLibre($nombre),
            'email' => $email,
            'password' => Hash::make($password),
        ]);
        // email_verified_at no es asignable en masa; sin esto no podría entrar.
        $user->forceFill(['email_verified_at' => now()])->save();
        $user->assignRole($rol);

        $this->info("Usuario creado: {$nombre} <{$email}> con rol {$rol}.");
        if ($generada) {
            $this->warn("Contraseña generada: {$password}");
            $this->line('Anotala ahora: no se vuelve a mostrar.');
        }

        return self::SUCCESS;
    }

    /** El username es único en la base: si choca, le agrega un número. */
    private function usernameLibre(string $nombre): string
    {
        $base = Str::slug($nombre, '') ?: 'usuario';
        $username = $base;
        $i = 2;

        while (User::where('username', $username)->exists()) {
            $username = $base . $i++;
        }

        return $username;
    }

    private function valor(string $opcion, string $pregunta): string
    {
        $valor = trim((string) $this->option($opcion));

        if ($valor === '' && $this->input->isInteractive()) {
            $valor = trim((string) $this->ask($pregunta));
        }

        return $valor;
    }
}
