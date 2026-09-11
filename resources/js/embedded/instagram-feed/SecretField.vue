<script setup lang="ts">
/**
 * 密钥输入框。
 *
 * 后端从不回显密钥，所以这里的值永远是「本次要写入的新值」：
 * 留空表示保持原值不变。已配置时给一个徽标，让商家知道空白不等于没配。
 */
import { ref } from 'vue';

defineProps<{
    label: string;
    configured: boolean;
    placeholder: string;
    error?: string;
}>();

const model = defineModel<string>({ required: true });

const revealed = ref(false);
</script>

<template>
    <label class="block min-w-0">
        <span class="flex flex-wrap items-center gap-x-2 gap-y-1">
            <span class="text-sm font-semibold text-slate-800">{{ label }}</span>
            <span
                v-if="configured"
                class="inline-flex shrink-0 whitespace-nowrap rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-semibold text-emerald-700 ring-1 ring-emerald-200"
            >
                已配置
            </span>
        </span>

        <span class="mt-2 flex items-center gap-2">
            <input
                v-model="model"
                :type="revealed ? 'text' : 'password'"
                autocomplete="new-password"
                spellcheck="false"
                :placeholder="placeholder"
                class="min-w-0 flex-1 rounded-xl border border-slate-300 px-3.5 py-2.5 font-mono text-sm focus:border-slate-900 focus:outline-none focus:ring-1 focus:ring-slate-900"
            >
            <button
                type="button"
                class="shrink-0 whitespace-nowrap rounded-xl border border-slate-300 px-3 py-2.5 text-xs font-semibold text-slate-700 hover:bg-slate-50"
                :aria-pressed="revealed"
                @click="revealed = !revealed"
            >
                {{ revealed ? '隐藏' : '显示' }}
            </button>
        </span>

        <span v-if="error" class="mt-1 block text-xs text-rose-600">{{ error }}</span>
    </label>
</template>
