<script setup lang="ts">
defineProps<{ platform: string }>();
</script>
<template>
    <svg v-if="platform === 'YouTube'" viewBox="0 0 24 24" aria-hidden="true" fill="currentColor"><path d="M22 7.2c-.2-1.2-1-2-2.2-2.2C18 4.5 12 4.5 12 4.5S6 4.5 4.2 5C3 5.2 2.2 6 2 7.2a27 27 0 0 0 0 9.6c.2 1.2 1 2 2.2 2.2 1.8.5 7.8.5 7.8.5s6 0 7.8-.5c1.2-.2 2-1 2.2-2.2a27 27 0 0 0 0-9.6ZM10 15.5v-7l6 3.5-6 3.5Z" /></svg>
    <svg v-else-if="platform === 'Instagram'" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="5" /><circle cx="12" cy="12" r="4" /><circle cx="17.5" cy="6.5" r="1" fill="currentColor" stroke="none" /></svg>
    <svg v-else-if="platform === 'Facebook'" viewBox="0 0 24 24" aria-hidden="true" fill="currentColor"><path d="M22 12A10 10 0 1 0 10.4 22v-7H8v-3h2.4V9.7c0-2.6 1.5-4 3.9-4 1.1 0 2.2.2 2.2.2v2.5h-1.2c-1.2 0-1.6.7-1.6 1.5V12h2.7l-.4 3h-2.3v7A10 10 0 0 0 22 12Z" /></svg>
    <span v-else aria-hidden="true">{{ platform.slice(0, 1) }}</span>
</template>
<style scoped>svg { width: 16px; height: 16px; flex: none; }</style>
