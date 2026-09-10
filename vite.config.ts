import tailwindcss from '@tailwindcss/vite';
import inertia from '@inertiajs/vite';
import vue from '@vitejs/plugin-vue';
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            // 第二个入口是 Shopify App Home 的内嵌页面：它不走 Inertia、不依赖 DecoAdmin
            // 会话，所以必须与后台主包分开打，避免把后台布局和登录态逻辑带进 iframe。
            input: ['resources/js/app.ts', 'resources/js/embedded/instagram-feed.ts'],
            refresh: true,
        }),
        inertia(),
        vue(),
        tailwindcss(),
    ],
    server: {
        host: '0.0.0.0',
        port: 5173,
        strictPort: true,
        hmr: {
            host: 'localhost',
        },
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
