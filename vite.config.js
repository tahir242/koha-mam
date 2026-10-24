import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            // app.* = staff application (Livewire/Alpine); viewer.* = public viewer (vanilla JS, strict CSP).
            input: ['resources/css/app.css', 'resources/js/app.js', 'resources/css/viewer.css', 'resources/js/viewer.js'],
            refresh: true,
            // Fonts are downloaded at build time and served from this origin.
            fonts: [
                bunny('Inter', { weights: [400, 500, 600, 700] }),
                bunny('Source Serif 4', { weights: [400, 600] }),
            ],
        }),
        tailwindcss(),
    ],
    build: {
        // pdf.js uses top-level await.
        target: 'es2022',
    },
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
