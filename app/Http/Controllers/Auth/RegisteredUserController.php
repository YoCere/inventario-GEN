<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Illuminate\View\View;

class RegisteredUserController extends Controller
{
    /**
     * Display the registration view.
     */
    public function create(): View
    {
        return view('auth.register');
    }

    /**
     * Handle an incoming registration request.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        // Bloquear registro si ya hay usuarios y el solicitante no es admin
        if (User::exists() && (!auth()->check() || !auth()->user()->isAdmin())) {
            abort(403, 'El registro de nuevos usuarios solo está disponible para administradores.');
        }

        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:255', 'unique:'.User::class],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:'.User::class],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        // Determinar rol: developer si es el primero (instalador del sistema),
        // staff para los siguientes (admin promueve manualmente desde users).
        // El developer hereda todos los permisos via Gate::before, así que
        // el setup inicial queda con acceso total + capacidad de gestionar roles.
        $isFirstUser = !User::exists();
        $roleName = $isFirstUser ? 'developer' : 'staff';

        $user = User::create([
            'name' => $request->name,
            'username' => $request->username,
            'email' => $request->email,
            'password' => Hash::make($request->password),
        ]);

        // assignRole requiere que el rol exista — RolesAndPermissionsSeeder
        // los crea en la migración migrate_user_roles_to_spatie, pero defendemos
        // contra setups sin esa migración con findOrCreate.
        \Spatie\Permission\Models\Role::findOrCreate($roleName, 'web');
        $user->assignRole($roleName);

        event(new Registered($user));

        // Solo hacer login automático si es el primer usuario (setup inicial)
        if ($isFirstUser) {
            Auth::login($user);
            return redirect(route('dashboard', absolute: false));
        }

        return redirect(route('users.index'))
            ->with('success', 'Usuario creado correctamente.');
    }
}
