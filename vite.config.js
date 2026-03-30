import { defineConfig } from 'vite';
import { resolve } from 'path';

export default defineConfig({
    base: '/dist/',
    build: {
        outDir: 'www/dist',
        manifest: true,
        rollupOptions: {
            input: {
                app: resolve(__dirname, 'resources/js/app.js'),
            },
        },
    },
    server: {
        origin: 'http://localhost:5173',
    },
});
