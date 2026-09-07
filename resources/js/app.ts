import '../css/app.css';
import { createInertiaApp } from '@inertiajs/vue3';

createInertiaApp({
    resolve: async (name: string) => {
        const pages = import.meta.glob('./Pages/**/*.vue');
        const communityReviews = import.meta.glob('../../shopify-apps/community-reviews/frontend/Pages/**/*.vue');
        const loader = pages[`./Pages/${name}.vue`]
            ?? communityReviews[`../../shopify-apps/community-reviews/frontend/Pages/${name}.vue`];
        if (!loader) throw new Error(`Page not found: ${name}`);
        const module = await loader() as { default: unknown };
        return module.default;
    },
});
