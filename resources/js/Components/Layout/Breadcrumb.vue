<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { BreadcrumbItem } from '../../types';

const props = defineProps<{ items: BreadcrumbItem[] }>();
const normalizedItems = computed(() => props.items[0]?.label === '工作台' ? props.items.slice(1) : props.items);
</script>

<template>
    <nav aria-label="面包屑导航">
        <ol class="flex min-w-0 flex-wrap items-center gap-1.5 text-xs font-medium text-slate-500 sm:text-sm">
            <li>
                <Link href="/dashboard" class="transition hover:text-emerald-700">工作台</Link>
            </li>
            <template v-for="(item, index) in normalizedItems" :key="`${item.label}-${index}`">
                <li aria-hidden="true" class="text-slate-300">/</li>
                <li class="min-w-0">
                    <Link v-if="item.href && index < normalizedItems.length - 1" :href="item.href" class="block max-w-52 truncate transition hover:text-emerald-700">
                        {{ item.label }}
                    </Link>
                    <span v-else class="block max-w-52 truncate text-slate-800" :aria-current="index === normalizedItems.length - 1 ? 'page' : undefined">
                        {{ item.label }}
                    </span>
                </li>
            </template>
        </ol>
    </nav>
</template>
