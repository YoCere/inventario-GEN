import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                // Shop module: entry separado para que admin no descargue assets de tienda.
                'resources/css/shop/shop.css',
                'resources/js/shop/shop.js',
            ],
            refresh: true,
        }),
    ],
});
