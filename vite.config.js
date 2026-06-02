import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                // Share viewer SPA — built into separate manifest
                // entries so the Blade shell can pull only its bundle.
                'resources/share/main.jsx',
                'resources/share/share.css',
            ],
            refresh: true,
        }),
        react({
            // Restrict React/JSX handling to the share folder so we
            // don't impose JSX semantics on the legacy
            // resources/js/app.js entry.
            include: ['resources/share/**/*.{jsx,tsx}'],
        }),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
