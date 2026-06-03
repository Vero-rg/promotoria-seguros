import vue from '@vitejs/plugin-vue';
import autoprefixer from 'autoprefixer';
import laravel from 'laravel-vite-plugin';
import path from 'path';
import tailwindcss from 'tailwindcss';
import { defineConfig } from 'vite';
import ElementPlus from 'unplugin-element-plus/vite';

export default defineConfig({
    plugins: [
        ElementPlus(),
        laravel({
            input: ['resources/js/app.ts'],
            refresh: true,
        }),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
    ],

    resolve: {
        alias: {
            '@': path.resolve(__dirname, './resources/js'),
        },
    },

    css: {
        postcss: {
            plugins: [tailwindcss, autoprefixer],
        },
    },
});