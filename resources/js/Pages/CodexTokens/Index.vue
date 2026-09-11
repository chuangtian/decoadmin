<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import { useToast } from '../../composables/useToast';
import type { PaginatedResource } from '../../types';

type TokenUser = { id: number; name: string; email: string };
type Ability = { slug: string; label: string; group: 'read' | 'write'; description: string };
type Token = {
    id: number;
    uuid: string;
    name: string;
    source: 'manual' | 'oauth';
    status: 'active' | 'expired' | 'revoked';
    abilities: string[];
    user: TokenUser;
    issuer: { id: number; name: string } | null;
    last_used_at: string | null;
    expires_at: string;
    revoked_at: string | null;
    created_at: string;
};

const props = defineProps<{
    tokens: PaginatedResource<Token>;
    users: TokenUser[];
    abilities: Ability[];
    canManage: boolean;
}>();

const toast = useToast();
const issueForm = reactive({
    user_id: props.users[0]?.id ?? 0,
    name: 'Codex 私有插件',
    abilities: props.abilities.filter((ability) => ability.group === 'read').map((ability) => ability.slug),
    expires_in_days: 30,
});
const issueErrors = reactive<Record<string, string>>({});
const confirmingIssue = ref(false);
const issuing = ref(false);
const issueIdempotencyKey = ref('');
const issuedToken = ref('');
const issuedFor = ref('');
const revokeTarget = ref<Token | null>(null);
const revoking = ref(false);
const revokeIdempotencyKey = ref('');

const readAbilities = computed(() => props.abilities.filter((ability) => ability.group === 'read'));
const writeAbilities = computed(() => props.abilities.filter((ability) => ability.group === 'write'));
const selectedUser = computed(() => props.users.find((user) => user.id === issueForm.user_id));
const selectedAbilities = computed(() => props.abilities.filter((ability) => issueForm.abilities.includes(ability.slug)));
const abilityMap = computed(() => new Map(props.abilities.map((ability) => [ability.slug, ability])));
const previousUrl = computed(() => props.tokens.links[0]?.url ?? null);
const nextUrl = computed(() => props.tokens.links[props.tokens.links.length - 1]?.url ?? null);

const csrf = () => document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
const newIdempotencyKey = () => crypto.randomUUID();

const formatDate = (value: string | null) => value
    ? new Intl.DateTimeFormat('zh-CN', { year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit' }).format(new Date(value))
    : '尚未使用';

const statusLabel = (status: Token['status']) => ({ active: '有效', expired: '已过期', revoked: '已撤销' }[status]);
const statusClass = (status: Token['status']) => ({
    active: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
    expired: 'bg-amber-50 text-amber-700 ring-amber-200',
    revoked: 'bg-slate-100 text-slate-500 ring-slate-200',
}[status]);

const useReadOnlyPreset = () => {
    issueForm.abilities = readAbilities.value.map((ability) => ability.slug);
};

const useReadWritePreset = () => {
    issueForm.abilities = props.abilities.map((ability) => ability.slug);
};

const toggleAbility = (slug: string) => {
    issueForm.abilities = issueForm.abilities.includes(slug)
        ? issueForm.abilities.filter((ability) => ability !== slug)
        : [...issueForm.abilities, slug];
};

const openIssueConfirmation = () => {
    Object.keys(issueErrors).forEach((key) => delete issueErrors[key]);
    if (!issueForm.user_id) issueErrors.user_id = '请选择授权用户。';
    if (!issueForm.name.trim()) issueErrors.name = '请输入授权名称。';
    if (!issueForm.abilities.length) issueErrors.abilities = '至少选择一项能力。';
    if (Object.keys(issueErrors).length) return;

    issueIdempotencyKey.value = newIdempotencyKey();
    confirmingIssue.value = true;
};

const closeIssueConfirmation = () => {
    if (!issuing.value) confirmingIssue.value = false;
};

const confirmIssue = async () => {
    if (issuing.value) return;
    issuing.value = true;
    Object.keys(issueErrors).forEach((key) => delete issueErrors[key]);
    try {
        const response = await fetch('/codex-tokens', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf(),
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({
                ...issueForm,
                name: issueForm.name.trim(),
                idempotency_key: issueIdempotencyKey.value,
                confirmation_text: '确认签发',
            }),
        });
        const payload = await response.json() as {
            plain_text_token?: string | null;
            idempotent_replay?: boolean;
            message?: string;
            errors?: Record<string, string[]>;
        };
        if (!response.ok) {
            Object.entries(payload.errors ?? {}).forEach(([key, messages]) => { issueErrors[key] = messages[0] ?? '输入有误。'; });
            throw new Error(payload.message || '授权签发失败。');
        }

        confirmingIssue.value = false;
        if (payload.plain_text_token) {
            issuedToken.value = payload.plain_text_token;
            issuedFor.value = selectedUser.value?.name ?? '当前用户';
            toast.success('授权已签发，请立即复制令牌。');
        } else if (payload.idempotent_replay) {
            toast.warning('该请求已处理，系统没有重复签发令牌。');
        }
        router.visit(window.location.href, { only: ['tokens'], preserveScroll: true, preserveState: true });
    } catch (error) {
        toast.error(error instanceof Error ? error.message : '授权签发失败。');
    } finally {
        issuing.value = false;
    }
};

const copyIssuedToken = async () => {
    if (!issuedToken.value) return;
    try {
        await navigator.clipboard.writeText(issuedToken.value);
        toast.success('令牌已复制。');
    } catch {
        toast.error('无法自动复制，请手动选中令牌。');
    }
};

const dismissIssuedToken = () => {
    issuedToken.value = '';
    issuedFor.value = '';
};

const openRevokeConfirmation = (token: Token) => {
    revokeTarget.value = token;
    revokeIdempotencyKey.value = newIdempotencyKey();
};

const closeRevokeConfirmation = () => {
    if (!revoking.value) revokeTarget.value = null;
};

const confirmRevoke = async () => {
    if (!revokeTarget.value || revoking.value) return;
    revoking.value = true;
    try {
        const response = await fetch(`/codex-tokens/${revokeTarget.value.id}`, {
            method: 'DELETE',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf(),
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({ idempotency_key: revokeIdempotencyKey.value, confirmation_text: '确认撤销' }),
        });
        const payload = await response.json() as { message?: string };
        if (!response.ok) throw new Error(payload.message || '撤销授权失败。');

        revokeTarget.value = null;
        toast.success('授权已撤销，对应插件令牌立即失效。');
        router.visit(window.location.href, { only: ['tokens'], preserveScroll: true, preserveState: true });
    } catch (error) {
        toast.error(error instanceof Error ? error.message : '撤销授权失败。');
    } finally {
        revoking.value = false;
    }
};
</script>

<template>
    <Head title="Codex 插件授权" />
    <AppLayout :breadcrumbs="[{ label: '系统管理' }, { label: 'Codex 插件授权' }]">
        <div class="mx-auto max-w-7xl space-y-6">
            <header class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="text-sm font-semibold text-emerald-700">访问控制</p>
                    <h1 class="mt-1 text-3xl font-semibold tracking-tight text-slate-950">Codex 插件授权</h1>
                    <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-500">员工可在插件安装时登录 DecoAdmin 自动授权；此页同时保留管理员手动令牌作为兼容和应急方式。后台角色变更会立即生效。</p>
                </div>
                <Link href="/roles" class="rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50">管理角色权限</Link>
            </header>

            <section class="grid gap-4 lg:grid-cols-3">
                <div class="rounded-2xl border border-emerald-200 bg-emerald-50/70 p-5">
                    <span class="inline-flex h-9 w-9 items-center justify-center rounded-xl bg-emerald-600 text-sm font-bold text-white">1</span>
                    <h2 class="mt-4 font-semibold text-slate-900">后台角色权限</h2>
                    <p class="mt-2 text-sm leading-6 text-slate-600">用户必须先加入组织和店铺，并拥有对应 RBAC 权限。</p>
                </div>
                <div class="rounded-2xl border border-blue-200 bg-blue-50/70 p-5">
                    <span class="inline-flex h-9 w-9 items-center justify-center rounded-xl bg-blue-600 text-sm font-bold text-white">2</span>
                    <h2 class="mt-4 font-semibold text-slate-900">插件令牌能力</h2>
                    <p class="mt-2 text-sm leading-6 text-slate-600">每个用户使用自己的令牌，只能调用签发时选中的能力。</p>
                </div>
                <div class="rounded-2xl border border-violet-200 bg-violet-50/70 p-5">
                    <span class="inline-flex h-9 w-9 items-center justify-center rounded-xl bg-violet-600 text-sm font-bold text-white">3</span>
                    <h2 class="mt-4 font-semibold text-slate-900">每次写入二次确认</h2>
                    <p class="mt-2 text-sm leading-6 text-slate-600">刷新、同步和修改配置仍要求用户在 Codex 中单独回复“确认执行”。</p>
                </div>
            </section>

            <section v-if="canManage" class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <header class="border-b border-slate-100 px-6 py-5">
                    <h2 class="text-xl font-semibold text-slate-950">签发新授权</h2>
                    <p class="mt-1 text-sm text-slate-500">默认为只读。如果选择写入能力，用户的后台角色仍会限制其实际可执行范围。</p>
                </header>
                <form class="space-y-6 p-6" @submit.prevent="openIssueConfirmation">
                    <div class="grid gap-5 lg:grid-cols-3">
                        <label class="text-sm font-semibold text-slate-700">
                            授权用户
                            <select v-model="issueForm.user_id" class="mt-2 w-full rounded-xl border border-slate-200 bg-white px-3.5 py-3 font-normal text-slate-900 outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-100">
                                <option v-for="user in users" :key="user.id" :value="user.id">{{ user.name }}（{{ user.email }}）</option>
                            </select>
                            <span v-if="issueErrors.user_id" class="mt-1 block text-xs font-normal text-rose-600">{{ issueErrors.user_id }}</span>
                        </label>
                        <label class="text-sm font-semibold text-slate-700">
                            授权名称
                            <input v-model="issueForm.name" maxlength="120" autocomplete="off" class="mt-2 w-full rounded-xl border border-slate-200 px-3.5 py-3 font-normal text-slate-900 outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-100" placeholder="例如：Pember 的 MacBook" />
                            <span v-if="issueErrors.name" class="mt-1 block text-xs font-normal text-rose-600">{{ issueErrors.name }}</span>
                        </label>
                        <label class="text-sm font-semibold text-slate-700">
                            有效期
                            <select v-model="issueForm.expires_in_days" class="mt-2 w-full rounded-xl border border-slate-200 bg-white px-3.5 py-3 font-normal text-slate-900 outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-100">
                                <option :value="7">7 天</option>
                                <option :value="30">30 天</option>
                                <option :value="90">90 天</option>
                                <option :value="180">180 天</option>
                                <option :value="365">365 天</option>
                            </select>
                        </label>
                    </div>

                    <div>
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div><h3 class="text-sm font-semibold text-slate-800">插件能力</h3><p class="mt-1 text-xs text-slate-500">令牌不包含的能力将始终被拒绝。</p></div>
                            <div class="flex gap-2">
                                <button type="button" class="rounded-lg border border-slate-200 px-3 py-2 text-xs font-semibold text-slate-600 hover:bg-slate-50" @click="useReadOnlyPreset">只读授权</button>
                                <button type="button" class="rounded-lg border border-violet-200 bg-violet-50 px-3 py-2 text-xs font-semibold text-violet-700 hover:bg-violet-100" @click="useReadWritePreset">读写授权</button>
                            </div>
                        </div>
                        <div class="mt-4 grid gap-4 lg:grid-cols-2">
                            <section class="rounded-xl border border-slate-200 p-4">
                                <div class="flex items-center gap-2"><span class="h-2 w-2 rounded-full bg-blue-500"></span><h4 class="text-sm font-semibold text-slate-800">只读能力</h4></div>
                                <div class="mt-3 space-y-2">
                                    <label v-for="ability in readAbilities" :key="ability.slug" class="flex cursor-pointer gap-3 rounded-lg p-2.5 hover:bg-slate-50">
                                        <input :checked="issueForm.abilities.includes(ability.slug)" type="checkbox" class="mt-1" @change="toggleAbility(ability.slug)" />
                                        <span><span class="block text-sm font-medium text-slate-800">{{ ability.label }}</span><span class="mt-0.5 block text-xs leading-5 text-slate-500">{{ ability.description }}</span></span>
                                    </label>
                                </div>
                            </section>
                            <section class="rounded-xl border border-violet-200 bg-violet-50/30 p-4">
                                <div class="flex items-center gap-2"><span class="h-2 w-2 rounded-full bg-violet-500"></span><h4 class="text-sm font-semibold text-slate-800">写入能力</h4><span class="rounded bg-violet-100 px-2 py-0.5 text-xs font-semibold text-violet-700">需二次确认</span></div>
                                <div class="mt-3 space-y-2">
                                    <label v-for="ability in writeAbilities" :key="ability.slug" class="flex cursor-pointer gap-3 rounded-lg p-2.5 hover:bg-white/70">
                                        <input :checked="issueForm.abilities.includes(ability.slug)" type="checkbox" class="mt-1" @change="toggleAbility(ability.slug)" />
                                        <span><span class="block text-sm font-medium text-slate-800">{{ ability.label }}</span><span class="mt-0.5 block text-xs leading-5 text-slate-500">{{ ability.description }}</span></span>
                                    </label>
                                </div>
                            </section>
                        </div>
                        <p v-if="issueErrors.abilities" class="mt-2 text-xs text-rose-600">{{ issueErrors.abilities }}</p>
                    </div>

                    <div class="flex justify-end">
                        <button :disabled="!users.length" class="rounded-xl bg-slate-950 px-5 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-40">检查并确认授权</button>
                    </div>
                </form>
            </section>

            <section v-else class="rounded-2xl border border-amber-200 bg-amber-50 p-5 text-sm text-amber-800">当前账号可以查看授权，但不能签发或撤销令牌。</section>

            <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <header class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-6 py-5">
                    <div><h2 class="text-xl font-semibold text-slate-950">已签发授权</h2><p class="mt-1 text-sm text-slate-500">共 {{ tokens.meta.total }} 个令牌，列表不会显示令牌明文或密钥。</p></div>
                </header>
                <div v-if="tokens.data.length" class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-100 text-left">
                        <thead class="bg-slate-50/80 text-xs font-semibold uppercase tracking-wide text-slate-500"><tr><th class="px-6 py-3.5">用户 / 授权</th><th class="px-6 py-3.5">能力</th><th class="px-6 py-3.5">状态</th><th class="px-6 py-3.5">最近使用</th><th class="px-6 py-3.5">到期时间</th><th class="px-6 py-3.5 text-right">操作</th></tr></thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="token in tokens.data" :key="token.id" class="align-top">
                                <td class="px-6 py-4"><p class="font-semibold text-slate-900">{{ token.user.name }}</p><p class="mt-0.5 text-xs text-slate-500">{{ token.user.email }}</p><div class="mt-2 flex flex-wrap items-center gap-2"><p class="text-sm text-slate-700">{{ token.name }}</p><span class="rounded-full px-2 py-0.5 text-xs font-semibold" :class="token.source === 'oauth' ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600'">{{ token.source === 'oauth' ? '网页登录' : '手动令牌' }}</span></div><code class="mt-1 block text-xs text-slate-400">{{ token.uuid }}</code></td>
                                <td class="max-w-sm px-6 py-4"><div class="flex flex-wrap gap-1.5"><span v-for="slug in token.abilities" :key="slug" class="rounded-md px-2 py-1 text-xs font-semibold" :class="abilityMap.get(slug)?.group === 'write' ? 'bg-violet-50 text-violet-700' : 'bg-blue-50 text-blue-700'">{{ abilityMap.get(slug)?.label ?? slug }}</span></div></td>
                                <td class="px-6 py-4"><span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold ring-1 ring-inset" :class="statusClass(token.status)">{{ statusLabel(token.status) }}</span></td>
                                <td class="whitespace-nowrap px-6 py-4 text-sm text-slate-600">{{ formatDate(token.last_used_at) }}</td>
                                <td class="whitespace-nowrap px-6 py-4 text-sm text-slate-600">{{ formatDate(token.expires_at) }}</td>
                                <td class="px-6 py-4 text-right"><button v-if="canManage && token.status === 'active'" type="button" class="rounded-lg border border-rose-200 px-3 py-2 text-xs font-semibold text-rose-600 hover:bg-rose-50" @click="openRevokeConfirmation(token)">撤销</button><span v-else class="text-xs text-slate-400">—</span></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div v-else class="px-6 py-16 text-center"><p class="font-medium text-slate-700">尚未签发 Codex 插件授权</p><p class="mt-2 text-sm text-slate-400">签发后会在这里显示使用状态和到期时间。</p></div>
                <footer v-if="tokens.meta.last_page > 1" class="flex items-center justify-between border-t border-slate-100 px-6 py-4 text-sm"><button :disabled="!previousUrl" class="rounded-lg border border-slate-200 px-3 py-2 font-semibold text-slate-600 disabled:opacity-40" @click="previousUrl && router.visit(previousUrl)">上一页</button><span class="text-slate-500">第 {{ tokens.meta.current_page }} / {{ tokens.meta.last_page }} 页</span><button :disabled="!nextUrl" class="rounded-lg border border-slate-200 px-3 py-2 font-semibold text-slate-600 disabled:opacity-40" @click="nextUrl && router.visit(nextUrl)">下一页</button></footer>
            </section>
        </div>

        <div v-if="confirmingIssue" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/50 p-4" role="dialog" aria-modal="true" aria-labelledby="issue-title" @click.self="closeIssueConfirmation">
            <div class="w-full max-w-lg rounded-2xl bg-white p-6 shadow-2xl">
                <div class="flex items-start gap-4"><span class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-violet-100 text-xl text-violet-700">!</span><div><h2 id="issue-title" class="text-xl font-semibold text-slate-950">确认签发插件授权</h2><p class="mt-1 text-sm leading-6 text-slate-500">签发后该用户可在令牌能力和后台角色权限交集范围内使用 Codex。</p></div></div>
                <dl class="mt-5 space-y-3 rounded-xl bg-slate-50 p-4 text-sm"><div class="flex justify-between gap-4"><dt class="text-slate-500">用户</dt><dd class="text-right font-semibold text-slate-900">{{ selectedUser?.name }}（{{ selectedUser?.email }}）</dd></div><div class="flex justify-between gap-4"><dt class="text-slate-500">名称</dt><dd class="text-right font-semibold text-slate-900">{{ issueForm.name }}</dd></div><div class="flex justify-between gap-4"><dt class="text-slate-500">有效期</dt><dd class="font-semibold text-slate-900">{{ issueForm.expires_in_days }} 天</dd></div><div><dt class="text-slate-500">能力</dt><dd class="mt-2 flex flex-wrap gap-1.5"><span v-for="ability in selectedAbilities" :key="ability.slug" class="rounded-md bg-white px-2 py-1 text-xs font-semibold text-slate-700 ring-1 ring-slate-200">{{ ability.label }}</span></dd></div></dl>
                <p class="mt-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm leading-6 text-amber-800">令牌明文只显示一次，请签发后立即复制并安全保存。</p>
                <div class="mt-6 flex justify-end gap-3"><button type="button" :disabled="issuing" class="rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-semibold text-slate-600" @click="closeIssueConfirmation">取消</button><button type="button" :disabled="issuing" class="rounded-xl bg-violet-600 px-5 py-2.5 text-sm font-semibold text-white disabled:opacity-50" @click="confirmIssue">{{ issuing ? '签发中…' : '确认签发' }}</button></div>
            </div>
        </div>

        <div v-if="issuedToken" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/60 p-4" role="dialog" aria-modal="true" aria-labelledby="token-title">
            <div class="w-full max-w-2xl rounded-2xl bg-white p-6 shadow-2xl">
                <div class="flex items-start gap-4"><span class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-emerald-100 text-xl text-emerald-700">✓</span><div><h2 id="token-title" class="text-xl font-semibold text-slate-950">授权已签发</h2><p class="mt-1 text-sm text-slate-500">{{ issuedFor }} 的令牌只在本次显示。关闭后无法再次查看。</p></div></div>
                <div class="mt-5 rounded-xl border border-slate-200 bg-slate-950 p-4"><code class="block select-all break-all text-sm leading-6 text-emerald-300">{{ issuedToken }}</code></div>
                <p class="mt-3 text-xs leading-5 text-slate-500">不要将令牌发到聊天、邮件、截图或提交到 Git。每个用户应使用自己的令牌。</p>
                <div class="mt-6 flex justify-end gap-3"><button type="button" class="rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-semibold text-slate-700" @click="dismissIssuedToken">我已安全保存</button><button type="button" class="rounded-xl bg-emerald-600 px-5 py-2.5 text-sm font-semibold text-white" @click="copyIssuedToken">复制令牌</button></div>
            </div>
        </div>

        <div v-if="revokeTarget" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/50 p-4" role="dialog" aria-modal="true" aria-labelledby="revoke-title" @click.self="closeRevokeConfirmation">
            <div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-2xl"><h2 id="revoke-title" class="text-xl font-semibold text-slate-950">确认撤销授权</h2><p class="mt-2 text-sm leading-6 text-slate-500">将撤销 <strong class="text-slate-800">{{ revokeTarget.name }}</strong>（{{ revokeTarget.user.name }}）。对应插件会立即失去访问权限，且无法恢复该令牌。</p><div class="mt-6 flex justify-end gap-3"><button type="button" :disabled="revoking" class="rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-semibold text-slate-600" @click="closeRevokeConfirmation">取消</button><button type="button" :disabled="revoking" class="rounded-xl bg-rose-600 px-5 py-2.5 text-sm font-semibold text-white disabled:opacity-50" @click="confirmRevoke">{{ revoking ? '撤销中…' : '确认撤销' }}</button></div></div>
        </div>
    </AppLayout>
</template>
