<script setup lang="ts">
defineProps<{ name: string; size?: number }>();
</script>

<template>
    <svg :width="size ?? 20" :height="size ?? 20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <template v-if="name === 'dashboard'"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></template>
        <template v-else-if="name === 'business'"><path d="M4 7h16v13H4z"/><path d="M8 7V4h8v3M4 12h16M10 12v2h4v-2"/></template>
        <template v-else-if="name === 'shopify'"><path d="M6 8h12l1 13H5L6 8Z"/><path d="M9 9V7a3 3 0 0 1 6 0v2"/><path d="M10 13c1-1 4-1 4 1s-4 1-4 3 3 2 4 1"/></template>
        <template v-else-if="name === 'app-center'"><rect x="3" y="3" width="7" height="7" rx="2"/><rect x="14" y="3" width="7" height="7" rx="2"/><rect x="3" y="14" width="7" height="7" rx="2"/><rect x="14" y="14" width="7" height="7" rx="2"/></template>
        <template v-else-if="name === 'analytics'"><path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/></template>
        <template v-else-if="name === 'collaboration'"><circle cx="8" cy="8" r="3"/><circle cx="17" cy="9" r="2.5"/><path d="M2 20a6 6 0 0 1 12 0M13 20a5 5 0 0 1 9 0"/></template>
        <template v-else-if="name === 'system'"><path d="M4 5h16M4 12h16M4 19h16"/><circle cx="8" cy="5" r="2"/><circle cx="16" cy="12" r="2"/><circle cx="10" cy="19" r="2"/></template>
        <template v-else-if="name === 'orders'"><path d="M6 3h12l1 18H5L6 3Z"/><path d="M9 7a3 3 0 0 0 6 0"/></template>
        <template v-else-if="name === 'products'"><path d="m4 7 8-4 8 4-8 4-8-4Z"/><path d="m4 7 8 4 8-4v10l-8 4-8-4V7Z"/><path d="M12 11v10"/></template>
        <template v-else-if="name === 'customers' || name === 'users'"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></template>
        <template v-else-if="name === 'stores'"><path d="M3 9 5 3h14l2 6"/><path d="M5 13v8h14v-8M9 21v-6h6v6"/><path d="M3 9a3 3 0 0 0 6 0 3 3 0 0 0 6 0 3 3 0 0 0 6 0"/></template>
        <template v-else-if="name === 'apps'"><rect x="3" y="3" width="7" height="7" rx="2"/><rect x="14" y="3" width="7" height="7" rx="2"/><rect x="3" y="14" width="7" height="7" rx="2"/><path d="M17.5 14v7M14 17.5h7"/></template>
        <template v-else-if="name === 'sync'"><path d="M20 7h-6V1"/><path d="m20 1-7 7a7 7 0 1 0 2 7"/></template>
        <template v-else-if="name === 'webhooks'"><circle cx="6" cy="12" r="3"/><circle cx="18" cy="6" r="3"/><circle cx="18" cy="18" r="3"/><path d="m8.6 10.5 6.8-3M8.6 13.5l6.8 3"/></template>
        <template v-else-if="name === 'roles'"><circle cx="8" cy="8" r="4"/><path d="M2 21a6 6 0 0 1 12 0M16 11l2 2 4-4"/></template>
        <template v-else-if="name === 'permissions'"><circle cx="8" cy="15" r="4"/><path d="m11 12 9-9M17 3h3v3M10.8 12.2l2 2"/></template>
        <template v-else-if="name === 'audit'"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><path d="M14 2v6h6M8 13h8M8 17h5"/></template>
        <template v-else-if="name === 'settings'"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .34 1.88l.06.06-2.83 2.83-.06-.06a1.7 1.7 0 0 0-1.88-.34 1.7 1.7 0 0 0-1.03 1.56V21h-4v-.08A1.7 1.7 0 0 0 9 19.37a1.7 1.7 0 0 0-1.88.34l-.06.06-2.83-2.83.06-.06A1.7 1.7 0 0 0 4.63 15 1.7 1.7 0 0 0 3.08 14H3v-4h.08A1.7 1.7 0 0 0 4.63 9a1.7 1.7 0 0 0-.34-1.88l-.06-.06 2.83-2.83.06.06A1.7 1.7 0 0 0 9 4.63 1.7 1.7 0 0 0 10 3.08V3h4v.08A1.7 1.7 0 0 0 15 4.63a1.7 1.7 0 0 0 1.88-.34l.06-.06 2.83 2.83-.06.06A1.7 1.7 0 0 0 19.37 9 1.7 1.7 0 0 0 20.92 10H21v4h-.08A1.7 1.7 0 0 0 19.4 15Z"/></template>
        <template v-else><circle cx="12" cy="12" r="9"/><path d="M12 8v4l2 2"/></template>
    </svg>
</template>
