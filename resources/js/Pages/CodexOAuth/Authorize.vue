<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';

type Ability = { slug: string; label: string; description: string; group: 'read' | 'write' };
type Organization = { id: number; name: string };

const props = defineProps<{
    client: { name: string; id: string };
    abilities: Ability[];
    organizations: Organization[];
    authorizationRequest: Record<string, string>;
}>();

const csrf = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
const hasWrites = computed(() => props.abilities.some((ability) => ability.group === 'write'));
</script>

<template>
    <Head title="连接 DecoAdmin" />
    <main class="min-h-screen bg-slate-50 px-4 py-10 sm:px-6">
        <div class="mx-auto max-w-2xl overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-xl shadow-slate-200/60">
            <header class="bg-slate-950 px-7 py-8 text-white sm:px-10">
                <div class="flex items-center gap-4">
                    <span class="inline-flex h-12 w-12 items-center justify-center rounded-2xl bg-emerald-400 text-2xl font-bold text-slate-950">D</span>
                    <div>
                        <p class="text-sm font-semibold text-emerald-300">DecoAdmin 安全连接</p>
                        <h1 class="mt-1 text-2xl font-semibold">授权 {{ client.name }}</h1>
                    </div>
                </div>
                <p class="mt-5 text-sm leading-6 text-slate-300">插件只能访问当前账号和所选组织允许的数据。后台角色、店铺范围和插件能力会同时生效。</p>
            </header>

            <form method="post" action="/oauth/authorize" class="space-y-6 px-7 py-8 sm:px-10">
                <input type="hidden" name="_token" :value="csrf" />
                <input v-for="(value, key) in authorizationRequest" :key="key" type="hidden" :name="key" :value="value" />

                <section>
                    <label for="organization_id" class="text-sm font-semibold text-slate-800">授权组织</label>
                    <select id="organization_id" name="organization_id" required class="mt-2 w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm text-slate-900 outline-none ring-emerald-500 focus:ring-2">
                        <option v-for="organization in organizations" :key="organization.id" :value="organization.id">{{ organization.name }}</option>
                    </select>
                    <p class="mt-2 text-xs leading-5 text-slate-500">授权不会扩大你在该组织中的角色或店铺权限。</p>
                </section>

                <section class="rounded-2xl border border-slate-200 bg-slate-50 p-5">
                    <div class="flex items-center justify-between gap-3">
                        <h2 class="font-semibold text-slate-900">请求的能力</h2>
                        <span v-if="hasWrites" class="rounded-full bg-amber-100 px-3 py-1 text-xs font-semibold text-amber-800">包含受控写入</span>
                        <span v-else class="rounded-full bg-blue-100 px-3 py-1 text-xs font-semibold text-blue-700">只读</span>
                    </div>
                    <ul class="mt-4 space-y-3">
                        <li v-for="ability in abilities" :key="ability.slug" class="flex gap-3">
                            <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full" :class="ability.group === 'write' ? 'bg-amber-500' : 'bg-blue-500'"></span>
                            <span><span class="block text-sm font-medium text-slate-800">{{ ability.label }}</span><span class="mt-0.5 block text-xs leading-5 text-slate-500">{{ ability.description }}</span></span>
                        </li>
                    </ul>
                </section>

                <p v-if="hasWrites" class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm leading-6 text-amber-800">刷新、同步或修改设置仍需你在 Codex 的后续消息中单独回复“确认执行”，系统才会执行并写入审计日志。</p>

                <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                    <button type="submit" name="decision" value="deny" formnovalidate class="rounded-xl border border-slate-300 px-5 py-3 text-sm font-semibold text-slate-600 hover:bg-slate-50">取消</button>
                    <button type="submit" name="decision" value="approve" class="rounded-xl bg-emerald-600 px-6 py-3 text-sm font-semibold text-white shadow-sm hover:bg-emerald-700">同意并连接</button>
                </div>
            </form>
        </div>
    </main>
</template>
