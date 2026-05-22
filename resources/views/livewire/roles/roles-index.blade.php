<div class="space-y-6">

    {{-- Lista de roles --}}
    <div class="rounded-xl border border-border bg-card">
        <header class="px-5 py-3 border-b border-border flex items-center justify-between">
            <h3 class="font-semibold text-foreground">Roles del sistema</h3>
            <x-primary-button type="button" wire:click="startCreate">+ Nuevo rol</x-primary-button>
        </header>

        <div class="divide-y divide-border">
            @foreach($this->roles as $role)
                <article class="px-5 py-3 flex items-center gap-3">
                    <div class="flex-1">
                        <div class="flex items-center gap-2">
                            <span class="font-mono text-sm text-foreground">{{ $role->name }}</span>
                            @if(in_array($role->name, \App\Livewire\Roles\RolesIndex::BASE_ROLES, true))
                                <span class="text-[10px] px-1.5 py-0.5 rounded-full bg-blue-100 text-blue-700 font-medium uppercase">Base</span>
                            @endif
                        </div>
                        <p class="text-xs text-muted-foreground mt-0.5">
                            {{ $role->permissions_count }} {{ $role->permissions_count === 1 ? 'permiso' : 'permisos' }} ·
                            {{ $role->users_count }} {{ $role->users_count === 1 ? 'usuario' : 'usuarios' }}
                        </p>
                    </div>
                    <div class="flex items-center gap-2">
                        <x-secondary-button type="button" wire:click="startEdit({{ $role->id }})">Editar</x-secondary-button>
                        @if(!in_array($role->name, \App\Livewire\Roles\RolesIndex::BASE_ROLES, true))
                            <button type="button"
                                    wire:click="delete({{ $role->id }})"
                                    wire:confirm="¿Eliminar rol '{{ $role->name }}'? Esta acción no se puede deshacer."
                                    class="px-3 py-1.5 rounded-md text-sm font-medium text-red-600 hover:bg-red-50">
                                Eliminar
                            </button>
                        @endif
                    </div>
                </article>
            @endforeach
        </div>
    </div>

    {{-- Form crear/editar --}}
    @if($editing)
        <div class="rounded-xl border border-border bg-card p-5 space-y-4">
            <header class="flex items-center justify-between border-b border-border pb-3">
                <h3 class="font-semibold text-foreground">
                    {{ $creating ? 'Nuevo rol' : "Editar rol: $roleName" }}
                </h3>
                <button type="button" wire:click="cancelEdit" class="text-sm text-muted-foreground hover:text-foreground">
                    Cancelar
                </button>
            </header>

            <div class="grid md:grid-cols-2 gap-4">
                <div>
                    <x-input-label for="roleName" value="Nombre del rol (slug)" required />
                    <input id="roleName" type="text" wire:model.live.debounce.300ms="roleName"
                           placeholder="ej: cajero, contador, vendedor"
                           @disabled(!$creating && in_array($roleName, \App\Livewire\Roles\RolesIndex::BASE_ROLES, true))
                           class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm font-mono">
                    <p class="text-xs text-muted-foreground mt-1">
                        Solo minúsculas, números, guiones. Identificador interno (no se muestra al usuario final).
                    </p>
                    <x-input-error :messages="$errors->get('roleName')" />
                </div>
            </div>

            {{-- Permisos por grupo --}}
            <div>
                <h4 class="text-sm font-semibold text-foreground mb-2">Permisos asignados</h4>
                <p class="text-xs text-muted-foreground mb-3">
                    Marca lo que este rol podrá hacer. Click en el título del grupo para marcar/desmarcar todo el grupo.
                </p>

                <div class="grid sm:grid-cols-2 gap-3">
                    @foreach($this->allPermissions as $group => $perms)
                        <div class="rounded-lg border border-border bg-background p-3">
                            <button type="button" wire:click="toggleGroup('{{ $group }}')"
                                    class="text-xs font-bold uppercase tracking-wide w-full text-left mb-2 flex items-center gap-2 hover:text-primary">
                                <span @class([
                                    'inline-block w-3 h-3 rounded-sm border',
                                    'bg-primary border-primary' => $this->isGroupChecked($group),
                                    'border-border' => !$this->isGroupChecked($group),
                                ])></span>
                                {{ ucfirst($group) }}
                                <span class="text-muted-foreground font-normal">({{ count($perms) }})</span>
                            </button>
                            <ul class="space-y-1.5">
                                @foreach($perms as $perm)
                                    <li>
                                        <label class="flex items-start gap-2 cursor-pointer text-sm">
                                            <input type="checkbox" value="{{ $perm->name }}"
                                                   wire:model.live="selectedPermissions"
                                                   class="mt-0.5 h-4 w-4 rounded text-primary focus:ring-primary/20">
                                            <span class="flex-1">
                                                <span class="block text-foreground">{{ $this->permissionLabel($perm->name) }}</span>
                                                <span class="block text-[10px] text-muted-foreground font-mono">{{ $perm->name }}</span>
                                            </span>
                                        </label>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="flex justify-end gap-2 pt-3 border-t border-border">
                <x-secondary-button type="button" wire:click="cancelEdit">Cancelar</x-secondary-button>
                <x-primary-button type="button" wire:click="save">Guardar rol</x-primary-button>
            </div>
        </div>
    @endif

    {{-- Nota legal info --}}
    <div class="rounded-lg bg-blue-50 dark:bg-blue-950/30 border border-blue-200 dark:border-blue-900 p-3 text-sm text-blue-900 dark:text-blue-200">
        <p class="font-semibold mb-1">💡 Cómo funciona</p>
        <ul class="list-disc list-inside text-xs space-y-0.5">
            <li><span class="font-mono">developer</span> — super-usuario. Acceso total automático sin importar permisos. No se puede modificar.</li>
            <li><span class="font-mono">admin</span> — gestión de negocio. Hereda permisos asignados (no incluye técnicos ni gestión de roles).</li>
            <li><span class="font-mono">staff</span> — POS básico. Modificable.</li>
            <li>Roles custom (cajero, contador, etc.) — créalos con el botón "+ Nuevo rol" y asigna permisos a mano.</li>
        </ul>
    </div>
</div>
