<x-modal name="chart-of-account-detail-modal" focusable>
    @if($account)
        <div class="p-6">
            <div class="mb-6 space-y-1.5 text-center sm:text-left border-b border-gray-200 pb-4">
                <h3 class="text-lg font-semibold leading-none tracking-tight text-foreground">
                    {{ __('Detalle de Cuenta Contable') }}
                </h3>
                <p class="text-sm text-muted-foreground">
                    {{ $account->code }} - {{ $account->name }}
                </p>
            </div>

            <div class="space-y-6">
                <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                    <div class="space-y-1">
                        <label class="text-sm font-medium leading-none text-muted-foreground">Código</label>
                        <p class="text-sm text-foreground font-medium font-mono">{{ $account->code }}</p>
                    </div>
                    <div class="space-y-1">
                        <label class="text-sm font-medium leading-none text-muted-foreground">Nivel</label>
                        <p class="text-sm text-foreground font-medium">{{ $account->level }}</p>
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                    <div class="space-y-1">
                        <label class="text-sm font-medium leading-none text-muted-foreground">Tipo</label>
                        <p class="text-sm text-foreground font-medium">{{ $account->account_type->label() }}</p>
                    </div>
                    <div class="space-y-1">
                        <label class="text-sm font-medium leading-none text-muted-foreground">Naturaleza</label>
                        <p class="text-sm text-foreground font-medium">{{ $account->normal_balance->label() }}</p>
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                    <div class="space-y-1">
                        <label class="text-sm font-medium leading-none text-muted-foreground">Cuenta padre</label>
                        <p class="text-sm text-foreground font-medium">
                            @if($account->parent)
                                {{ $account->parent->code }} - {{ $account->parent->name }}
                            @else
                                -
                            @endif
                        </p>
                    </div>
                    <div class="space-y-1">
                        <label class="text-sm font-medium leading-none text-muted-foreground">Imputable</label>
                        <p class="text-sm text-foreground font-medium">{{ $account->allows_posting ? 'Sí' : 'No' }}</p>
                    </div>
                </div>

                <div class="space-y-1">
                    <label class="text-sm font-medium leading-none text-muted-foreground">Estado</label>
                    <p class="text-sm text-foreground font-medium">{{ $account->is_active ? 'Activo' : 'Inactivo' }}</p>
                </div>

                @if($account->description)
                    <div class="space-y-1">
                        <label class="text-sm font-medium leading-none text-muted-foreground">Descripción</label>
                        <p class="text-sm text-foreground font-medium">{{ $account->description }}</p>
                    </div>
                @endif
            </div>

            <div class="mt-6 flex items-center justify-end gap-x-2 pt-4 border-t border-border">
                <x-secondary-button type="button" x-on:click="$dispatch('close-modal', { name: 'chart-of-account-detail-modal' })">
                    Cerrar
                </x-secondary-button>
            </div>
        </div>
    @else
        <div class="p-8 text-center flex flex-col items-center justify-center space-y-3">
            <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-primary"></div>
            <span class="text-sm text-muted-foreground">Cargando detalles...</span>
        </div>
    @endif
</x-modal>
