<div class="space-y-4">
    <div>
        <label class="block text-sm font-medium text-foreground mb-1">Título</label>
        <input type="text" wire:model="form.heading" class="w-full rounded-md border-input bg-background text-sm">
        @error('form.heading') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
    </div>
    <div>
        <label class="block text-sm font-medium text-foreground mb-1">WhatsApp</label>
        <input type="text" wire:model="form.whatsapp" placeholder="+591 700 12345"
               class="w-full rounded-md border-input bg-background text-sm">
        @error('form.whatsapp') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
    </div>
    <div>
        <label class="block text-sm font-medium text-foreground mb-1">Dirección</label>
        <input type="text" wire:model="form.address" class="w-full rounded-md border-input bg-background text-sm">
        @error('form.address') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
    </div>
    <div>
        <label class="block text-sm font-medium text-foreground mb-1">Correo</label>
        <input type="email" wire:model="form.email" class="w-full rounded-md border-input bg-background text-sm">
        @error('form.email') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
    </div>
</div>
