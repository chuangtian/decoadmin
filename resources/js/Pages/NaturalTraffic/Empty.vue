<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';

defineProps<{ title: string }>();
</script>

<template>
    <Head :title="title" />
    <AppLayout />
</template>
