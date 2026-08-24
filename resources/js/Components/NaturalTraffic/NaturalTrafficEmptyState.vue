<script setup lang="ts">
defineProps<{ configured: boolean; canSync: boolean }>();
</script>

<template>
    <section class="rounded-[26px] border border-dashed border-slate-300 bg-white px-6 py-16 text-center shadow-sm">
        <div class="mx-auto h-3 w-3 rounded-full" :class="configured ? 'bg-amber-400' : 'bg-slate-300'"></div>
        <h2 class="mt-4 text-xl font-black text-slate-900">{{ configured ? '数据源已配置，等待首次同步' : '当前店铺尚未配置数据源' }}</h2>
        <p class="mx-auto mt-2 max-w-xl text-sm leading-6 text-slate-500">{{ configured ? (canSync ? '点击页面右上角“同步数据”，数据将写入当前项目 MySQL；之后打开页面不会实时请求外部接口。' : '拥有同步权限的成员完成首次同步后，这里会显示数据库内的数据。') : '请先在当前项目的店铺数据来源中完成对应配置。页面不会读取旧项目或旧数据库。' }}</p>
    </section>
</template>
