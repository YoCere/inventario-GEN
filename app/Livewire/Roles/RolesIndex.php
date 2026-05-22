<?php

namespace App\Livewire\Roles;

use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * CRUD de roles + permisos. Solo Developer puede entrar (gate en mount).
 *
 * UX:
 *  - Tabla con todos los roles + cantidad de usuarios + permisos asignados.
 *  - Click "Editar" abre panel con checkboxes de permisos.
 *  - Botón "Nuevo rol" para crear roles custom.
 *  - Borrar deshabilitado para roles base (developer/admin/staff) — son
 *    requeridos por el código del sistema.
 */
class RolesIndex extends Component
{
    /** Roles que no se pueden borrar (los usa código del sistema). */
    public const BASE_ROLES = ['developer', 'admin', 'staff'];

    public bool $editing = false;
    public bool $creating = false;

    public ?int $roleId = null;
    public string $roleName = '';
    public string $roleDisplayName = '';

    /** @var string[] Permisos seleccionados en el form actual */
    public array $selectedPermissions = [];

    public function mount(): void
    {
        abort_if(! auth()->user()?->isDeveloper(), 403);
    }

    public function rules(): array
    {
        return [
            'roleName' => [
                'required', 'string', 'max:50',
                'regex:/^[a-z][a-z0-9\-_]*$/', // slug-style
                Rule::unique('roles', 'name')->ignore($this->roleId),
            ],
            'roleDisplayName' => ['nullable', 'string', 'max:120'],
            'selectedPermissions' => ['array'],
            'selectedPermissions.*' => ['string', Rule::exists('permissions', 'name')],
        ];
    }

    #[Computed]
    public function roles()
    {
        return Role::query()
            ->withCount('permissions', 'users')
            ->orderByRaw("FIELD(name, 'developer', 'admin', 'staff') DESC")
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function allPermissions()
    {
        // Agrupado por prefijo (lo antes del primer punto) para UI más legible.
        return Permission::query()
            ->orderBy('name')
            ->get()
            ->groupBy(fn (Permission $p) => explode('.', $p->name)[0]);
    }

    /** Labels human-friendly para los permisos (del catálogo en el seeder). */
    public function permissionLabel(string $name): string
    {
        return RolesAndPermissionsSeeder::PERMISSIONS[$name] ?? $name;
    }

    public function startCreate(): void
    {
        $this->reset(['roleId', 'roleName', 'roleDisplayName', 'selectedPermissions']);
        $this->creating = true;
        $this->editing = true;
    }

    public function startEdit(int $id): void
    {
        $role = Role::with('permissions')->findOrFail($id);

        $this->roleId = $role->id;
        $this->roleName = $role->name;
        $this->roleDisplayName = ''; // spatie no tiene display name nativo; reservado para futuro
        $this->selectedPermissions = $role->permissions->pluck('name')->all();
        $this->creating = false;
        $this->editing = true;
    }

    public function cancelEdit(): void
    {
        $this->reset(['roleId', 'roleName', 'roleDisplayName', 'selectedPermissions', 'editing', 'creating']);
    }

    public function save(): void
    {
        $this->validate();

        // Bloquear renombrar roles base — el código depende de los slugs.
        if ($this->roleId && in_array($this->originalName(), self::BASE_ROLES, true) && $this->roleName !== $this->originalName()) {
            $this->addError('roleName', 'No se puede renombrar un rol base del sistema.');
            return;
        }

        if ($this->roleId) {
            $role = Role::findOrFail($this->roleId);
            $role->update(['name' => $this->roleName]);
        } else {
            $role = Role::create(['name' => $this->roleName, 'guard_name' => 'web']);
        }

        $role->syncPermissions($this->selectedPermissions);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->cancelEdit();
        $this->dispatch('toast', message: 'Rol guardado correctamente.', type: 'success');
    }

    public function delete(int $id): void
    {
        $role = Role::findOrFail($id);

        if (in_array($role->name, self::BASE_ROLES, true)) {
            $this->dispatch('toast', message: 'No se puede borrar un rol base del sistema.', type: 'error');
            return;
        }

        if ($role->users()->exists()) {
            $this->dispatch('toast', message: "Rol '{$role->name}' tiene usuarios asignados. Reasigna a otro rol antes de borrarlo.", type: 'error');
            return;
        }

        $role->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->dispatch('toast', message: 'Rol eliminado.', type: 'success');
    }

    /** True si todos los permisos del grupo están seleccionados. */
    public function isGroupChecked(string $group): bool
    {
        $names = $this->allPermissions[$group]->pluck('name');
        return $names->every(fn ($n) => in_array($n, $this->selectedPermissions, true));
    }

    /** Marcar / desmarcar grupo completo. */
    public function toggleGroup(string $group): void
    {
        $names = $this->allPermissions[$group]->pluck('name')->all();
        if ($this->isGroupChecked($group)) {
            $this->selectedPermissions = array_values(array_diff($this->selectedPermissions, $names));
        } else {
            $this->selectedPermissions = array_values(array_unique(array_merge($this->selectedPermissions, $names)));
        }
    }

    private function originalName(): string
    {
        return Role::find($this->roleId)?->name ?? '';
    }

    public function render()
    {
        return view('livewire.roles.roles-index');
    }
}
