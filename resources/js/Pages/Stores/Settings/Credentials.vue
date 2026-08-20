<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
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

defineProps<{
    store: { id: number; name: string; shopify_domain: string };
    credentialProviders: CredentialProvider[];
    canUpdate: boolean;
}>();
</script>

<template>
    <Head :title="`${store.name} 业务凭证`" />
    <AppLayout :breadcrumbs="[{ label: '店铺设置' }, { label: '业务凭证' }]">
        <div class="mx-auto max-w-6xl space-y-5">
            <div class="rounded-2xl border border-blue-100 bg-blue-50/70 px-5 py-4 text-sm leading-6 text-blue-900">
                以下凭证仅属于当前店铺 <strong>{{ store.name }}</strong>，不会被其他店铺读取。敏感值默认隐藏，需要店铺编辑权限才能查看或修改。
            </div>
            <BusinessCredentialCard v-for="provider in credentialProviders" :key="provider.key" :provider="provider" :can-update="canUpdate" />
        </div>
    </AppLayout>
</template>
