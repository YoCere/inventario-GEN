@props(['disabled' => false])

<div x-data="{
    // model holds cents (integer). display shows decimal Bs (e.g. 1500 cents -> '15.00').
    model: @entangle($attributes->wire('model')),
    display: '',
    timeout: null,
    init() {
        this.display = this.centsToDisplay(this.model);
        this.$watch('model', value => {
            if (this.displayToCents(this.display) != value) {
                this.display = this.centsToDisplay(value);
            }
        });
    },
    centsToDisplay(cents) {
        if (cents === null || cents === '' || cents === undefined) return '';
        const num = parseInt(cents, 10) || 0;
        return (num / 100).toFixed(2);
    },
    displayToCents(value) {
        if (!value) return 0;
        const cleaned = String(value).replace(/[^0-9.]/g, '');
        const num = parseFloat(cleaned) || 0;
        return Math.round(num * 100);
    },
    update(event) {
        let raw = event.target.value;
        clearTimeout(this.timeout);
        this.timeout = setTimeout(() => {
            this.model = this.displayToCents(raw);
        }, 500);
    }
}"
class="w-full"
>
    <input
        {{ $disabled ? 'disabled' : '' }}
        {!! $attributes->whereDoesntStartWith('wire:model')->merge(['class' => 'flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background file:border-0 file:bg-transparent file:text-sm file:font-medium placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50']) !!}
        type="text"
        inputmode="decimal"
        :value="display"
        @input="update($event)"
        @blur="display = centsToDisplay(model)"
        placeholder="0.00"
    />
</div>
