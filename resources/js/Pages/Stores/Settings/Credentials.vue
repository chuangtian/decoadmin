<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import BusinessCredentialCard from '../../../Components/Stores/BusinessCredentialCard.vue';
import AppLayout from '../../../Layouts/AppLayout.vue';

type CredentialField = {
    key: string;
    label: string;
    env_key: string;
    secret: boolean;
    placeholder: string;
    configured: boolean;
    masked_value: string;
    current_value: string;
};

type CredentialProvider = {
    key: string;
    title: string;
    description: string;
    setup_hint: string;
    oauth_label: string | null;
    configured: boolean;
    fields: CredentialField[];
};

const props = defineProps<{
    store: { id: number; name: string; shopify_domain: string };
    credentialProviders: CredentialProvider[];
    canUpdate: boolean;
}>();

const activeKey = ref(props.credentialProviders[0]?.key ?? '');
const activeProvider = computed(() => props.credentialProviders.find((provider) => provider.key === activeKey.value) ?? props.credentialProviders[0]);

watch(() => props.credentialProviders, (providers) => {
    if (!providers.some((provider) => provider.key === activeKey.value)) {
        activeKey.value = providers[0]?.key ?? '';
    }
});
</script>

<template>
    <Head :title="`${store.name} 业务凭证`" />
    <AppLayout :breadcrumbs="[{ label: '店铺设置' }, { label: '业务凭证' }]">
        <div class="mx-auto max-w-6xl">
            <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <header class="flex flex-wrap items-start justify-between gap-4 border-b border-slate-100 px-5 py-5 sm:px-7">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-cyan-700">当前店铺独立配置</p>
                        <h1 class="mt-1 text-xl font-semibold text-slate-950">业务凭证</h1>
                        <p class="mt-2 text-sm leading-6 text-slate-500">
                            以下凭证仅属于当前店铺 <strong class="font-semibold text-slate-700">{{ store.name }}</strong>，不会被其他店铺读取。敏感值默认隐藏，需要店铺编辑权限才能查看或修改。
                        </p>
                    </div>
                    <span class="rounded-full bg-slate-100 px-3 py-1.5 text-xs font-semibold text-slate-600">
                        {{ credentialProviders.filter((provider) => provider.configured).length }} / {{ credentialProviders.length }} 已配置
                    </span>
                </header>

                <div v-if="activeProvider" class="grid min-h-[520px] md:grid-cols-[13rem_minmax(0,1fr)]">
                    <nav class="border-b border-slate-100 bg-slate-50/70 p-3 md:border-b-0 md:border-r" aria-label="业务凭证类别">
                        <div class="grid grid-cols-2 gap-1 sm:grid-cols-3 md:grid-cols-1">
                            <button
                                v-for="provider in credentialProviders"
                                :key="provider.key"
                                type="button"
                                class="min-w-0 rounded-xl px-3 py-3 text-left text-sm font-semibold transition"
                                :class="provider.key === activeProvider.key ? 'bg-slate-950 text-white shadow-sm' : 'text-slate-600 hover:bg-white hover:text-slate-950'"
                                @click="activeKey = provider.key"
                            >
                                <span class="block truncate">{{ provider.title }}</span>
                            </button>
                        </div>
                    </nav>

                    <BusinessCredentialCard
                        :key="activeProvider.key"
                        :provider="activeProvider"
                        :can-update="canUpdate"
                        embedded
                    />
                </div>

                <div v-else class="px-7 py-16 text-center text-sm text-slate-500">
                    暂无可配置的业务凭证。
                </div>
            </section>
        </div>
    </AppLayout>
</template>
