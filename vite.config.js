import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
            fonts: [
                bunny('Instrument Sans', {
                    weights: [400, 500, 600],
                }),
            ],
        }),
        tailwindcss(),
    ],
    server: {
        host: '0.0.0.0',
        cors: true,
        hmr: process.env.VITE_HMR_HOST
            ? {
                host: process.env.VITE_HMR_HOST,
                protocol: process.env.VITE_HMR_PROTOCOL ?? 'ws',
                clientPort: process.env.VITE_HMR_PORT ? Number(process.env.VITE_HMR_PORT) : 5173,
              }
            : undefined,
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
