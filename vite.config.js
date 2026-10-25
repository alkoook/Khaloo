import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/css/receipt.css', 'resources/css/receipt-preview.css', 'resources/css/quotation-print.css', 'resources/js/app.js', 'resources/js/receipt.js', 'resources/js/receipt-preview.js', 'resources/js/quotation.js'],
            refresh: true,
        }),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
