import '../../css/app.css';
import { createApp } from 'vue';
import App from './instagram-feed/App.vue';

// Shopify App Home 的内嵌页面入口。刻意不走 Inertia：这个页面没有 DecoAdmin 会话，
// 也不共享后台布局，所有数据都由 App.vue 带着 session token 自己拉。
const mount = document.getElementById('instagram-feed-embedded');

if (mount) {
    createApp(App, {
        apiBase: mount.dataset.apiBase ?? '/api/shopify-app/instagram-feed',
        environment: mount.dataset.environment ?? '',
    }).mount(mount);
}
