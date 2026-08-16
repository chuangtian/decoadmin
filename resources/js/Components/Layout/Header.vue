<script setup lang="ts">
import OrganizationSwitcher from './OrganizationSwitcher.vue';
import StoreSwitcher from './StoreSwitcher.vue';
import UserDropdown from './UserDropdown.vue';

const emit = defineEmits<{ menu: [] }>();
</script>

<template>
    <header class="sticky top-0 z-30 border-b border-slate-200/80 bg-white/90 backdrop-blur-xl">
        <div class="flex min-h-20 items-center gap-2 px-3 py-3 sm:gap-3 sm:px-6 lg:px-8">
            <button class="grid h-10 w-10 shrink-0 place-items-center rounded-xl border border-slate-200 text-slate-600 hover:bg-slate-50 lg:hidden" aria-label="打开导航" @click="emit('menu')">
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
            </button>
            <OrganizationSwitcher />
            <div class="hidden h-8 w-px bg-slate-200 sm:block" />
            <div class="min-w-0 flex-1 sm:max-w-72"><StoreSwitcher /></div>
            <div class="ml-auto"><UserDropdown /></div>
        </div>
    </header>
</template>
