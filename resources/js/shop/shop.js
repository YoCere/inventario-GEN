/**
 * Shop module client bundle.
 *
 * Alpine.js store-based cart con persistencia localStorage. Sin Livewire para
 * que las páginas del catálogo sean cacheables y el carrito tenga cero latencia.
 *
 * Sincronización entre tabs vía StorageEvent.
 */

import Alpine from 'alpinejs';
import collapse from '@alpinejs/collapse';

Alpine.plugin(collapse);

const STORAGE_KEY = 'shop_cart_v1';
const CART_VERSION = 1;

function loadCart() {
    try {
        const raw = localStorage.getItem(STORAGE_KEY);
        if (!raw) return { version: CART_VERSION, items: [] };
        const data = JSON.parse(raw);
        if (data.version !== CART_VERSION) return { version: CART_VERSION, items: [] };
        return data;
    } catch {
        return { version: CART_VERSION, items: [] };
    }
}

function saveCart(state) {
    try {
        localStorage.setItem(STORAGE_KEY, JSON.stringify(state));
    } catch {
        // Quota exceeded, ignorar — el carrito sigue funcionando en memoria.
    }
}

document.addEventListener('alpine:init', () => {
    const initial = loadCart();

    Alpine.store('cart', {
        items: initial.items,
        open: false,
        flash: null,

        init() {
            // Sync entre tabs.
            window.addEventListener('storage', (e) => {
                if (e.key === STORAGE_KEY) {
                    const fresh = loadCart();
                    this.items = fresh.items;
                }
            });
        },

        toggle() {
            this.open = !this.open;
            if (this.open) {
                document.body.style.overflow = 'hidden';
            } else {
                document.body.style.overflow = '';
            }
        },

        close() {
            this.open = false;
            document.body.style.overflow = '';
        },

        add(product) {
            const existing = this.items.find((i) => i.id === product.id);
            if (existing) {
                existing.qty += 1;
            } else {
                this.items.push({
                    id: product.id,
                    name: product.name,
                    slug: product.slug,
                    price: product.price_cents,
                    image: product.image,
                    qty: 1,
                });
            }
            this.persist();
            this.showFlash(`✓ ${product.name} añadido al carrito`);
        },

        remove(id) {
            this.items = this.items.filter((i) => i.id !== id);
            this.persist();
        },

        updateQty(id, qty) {
            const item = this.items.find((i) => i.id === id);
            if (!item) return;
            const q = Math.max(1, Math.min(999, parseInt(qty, 10) || 1));
            item.qty = q;
            this.persist();
        },

        increment(id) {
            const item = this.items.find((i) => i.id === id);
            if (item) {
                item.qty += 1;
                this.persist();
            }
        },

        decrement(id) {
            const item = this.items.find((i) => i.id === id);
            if (!item) return;
            if (item.qty <= 1) {
                this.remove(id);
            } else {
                item.qty -= 1;
                this.persist();
            }
        },

        clear() {
            this.items = [];
            this.persist();
        },

        count() {
            return this.items.reduce((s, i) => s + i.qty, 0);
        },

        total() {
            return this.items.reduce((s, i) => s + i.price * i.qty, 0);
        },

        totalFormatted() {
            return (this.total() / 100).toFixed(2);
        },

        persist() {
            saveCart({ version: CART_VERSION, items: this.items });
        },

        showFlash(msg) {
            this.flash = msg;
            setTimeout(() => {
                this.flash = null;
            }, 2500);
        },
    });

    // Store del buscador inteligente — debounced fetch al endpoint /tienda/api/search.
    Alpine.store('search', {
        query: '',
        results: [],
        loading: false,
        open: false,
        timeout: null,

        onInput(value) {
            this.query = value;
            clearTimeout(this.timeout);
            if (!value || value.length < 2) {
                this.results = [];
                this.open = false;
                return;
            }
            this.timeout = setTimeout(() => this.fetch(), 250);
        },

        async fetch() {
            this.loading = true;
            this.open = true;
            try {
                const url = new URL(window.location.origin + '/tienda/api/search');
                url.searchParams.set('q', this.query);
                const r = await fetch(url, {
                    headers: { Accept: 'application/json' },
                });
                if (!r.ok) throw new Error('Search failed');
                const data = await r.json();
                this.results = data.results || [];
            } catch {
                this.results = [];
            } finally {
                this.loading = false;
            }
        },

        clear() {
            this.query = '';
            this.results = [];
            this.open = false;
        },
    });
});

window.Alpine = Alpine;
Alpine.start();
