<script setup lang="ts">
import { Head, router, useForm } from "@inertiajs/vue3";
import { computed, ref, watch } from "vue";
import AppLayout from "../../Layouts/AppLayout.vue";

type Log = {
    uuid: string;
    action: string;
    content: string | null;
    user: string;
    created_at: string;
};
type Row = {
    uuid: string;
    reference_no: string;
    title: string;
    description: string;
    category: string;
    priority: string;
    desired_date: string;
    status: string;
    submitter: string;
    assignee: string | null;
    created_at: string;
    can_accept: boolean;
    can_process: boolean;
    attachments: Array<{ uuid: string; name: string; url: string }>;
    progress_logs: Log[];
};
type Page = {
    data: Row[];
    current_page: number;
    last_page: number;
    total: number;
    prev_page_url: string | null;
    next_page_url: string | null;
};
type Summary = {
    total: number;
    pending_approval: number;
    unassigned: number;
    in_progress: number;
    completed: number;
    overdue: number;
    on_time_rate: number | null;
    average_turnaround_days: number | null;
    status_counts: Record<string, number>;
    developer_performance: Array<{
        developer_id: number;
        developer: string;
        total: number;
        in_progress: number;
        completed: number;
        overdue: number;
        on_time_rate: number | null;
        average_turnaround_days: number | null;
    }>;
};

const props = defineProps<{
    scope: "mine" | "all";
    canCreate: boolean;
    canManage: boolean;
    canViewOverview: boolean;
    today: string;
    filters: { tab: "overview" | "list"; status: string; search: string };
    options: { categories: string[]; priorities: string[] };
    summary: Summary;
    requests: Page;
}>();

const statusOrder = [
    "pending_approval",
    "approved",
    "assigned",
    "in_progress",
    "completed",
    "rejected",
] as const;
const statusColors = {
    pending_approval: "#f59e0b",
    approved: "#8b5cf6",
    assigned: "#6366f1",
    in_progress: "#2563eb",
    completed: "#10b981",
    rejected: "#f97316",
    unassigned: "#f97316",
} as const;

const categoryLabels: Record<string, string> = {
    bug: "故障修复",
    feature: "功能开发",
    data: "数据支持",
    automation: "流程自动化",
    access: "权限申请",
    other: "其他",
};
const priorityLabels: Record<string, string> = {
    urgent: "紧急",
    high: "高",
    medium: "中",
    low: "低",
};
const statusLabels: Record<string, string> = {
    pending_approval: "待审批",
    approved: "待接受",
    rejected: "已驳回",
    assigned: "已接受",
    in_progress: "处理中",
    completed: "已完成",
};
const logActionLabels: Record<string, string> = {
    approved: "审批通过",
    rejected: "审批驳回",
    accepted: "接受需求",
    progress: "提交进展",
    completed: "完成需求",
};

const activeTab = ref<"overview" | "list">(
    props.canViewOverview ? props.filters.tab : "list",
);
const scopeLabel = computed(() =>
    props.scope === "all" ? "全部技术需求" : "我的技术需求",
);
const maxStatusCount = computed(() => {
    const values = Object.values(props.summary.status_counts ?? {});
    return Math.max(1, ...values, 0);
});
const statusCount = (status: string): number =>
    props.summary.status_counts?.[status] ?? 0;
const percentRate = (value: number | null): string =>
    value === null ? "—" : `${value}%`;
const formatDays = (value: number | null): string =>
    value === null ? "—" : `${value} 天`;

const createOpen = ref(false);
const processOpen = ref(false);
const selected = ref<Row | null>(null);
const images = ref<File[]>([]);
const accepting = ref<string | null>(null);
const filters = ref({
    status: props.filters.status,
    search: props.filters.search,
});
const form = useForm({
    title: "",
    category: "feature",
    priority: "medium",
    desired_date: "",
    description: "",
    images: [] as File[],
});
const progressForm = useForm({
    action: "progress" as "progress" | "complete",
    note: "",
});

watch(
    () => props.filters,
    (value) => {
        filters.value = { status: value.status, search: value.search };
        activeTab.value = props.canViewOverview ? value.tab : "list";
    },
);

function queryParams(
    overrides: Record<string, string | number> = {},
): Record<string, string | number> {
    const values: Record<string, string | number> = {
        tab: activeTab.value,
        status: filters.value.status,
        search: filters.value.search,
        ...overrides,
    };
    Object.keys(values).forEach((key) => {
        const value = values[key];
        if (typeof value === "string" && value === "") {
            delete values[key];
        }
    });
    return values;
}

function selectTab(tab: "overview" | "list"): void {
    activeTab.value = tab;
    router.get("/technical-requests", queryParams({ tab }), {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

function applyFilters(): void {
    activeTab.value = "list";
    router.get(
        "/technical-requests",
        queryParams({ tab: "list", page: 1 }),
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

function selectFiles(event: Event): void {
    const input = event.target as HTMLInputElement;
    images.value = Array.from(input.files ?? []).slice(0, 10);
    form.images = images.value;
}

function pasteImages(event: ClipboardEvent): void {
    const files = Array.from(event.clipboardData?.items ?? [])
        .filter(
            (item) => item.kind === "file" && item.type.startsWith("image/"),
        )
        .map((item) => item.getAsFile())
        .filter((file): file is File => Boolean(file));
    if (!files.length) return;
    event.preventDefault();
    images.value = [...images.value, ...files].slice(0, 10);
    form.images = images.value;
}

function submit(): void {
    form.post("/technical-requests", {
        forceFormData: true,
        onSuccess: () => {
            createOpen.value = false;
            images.value = [];
            form.reset();
            form.category = "feature";
            form.priority = "medium";
        },
    });
}

function accept(row: Row): void {
    accepting.value = row.uuid;
    router.post(
        `/technical-requests/${row.uuid}/accept`,
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                accepting.value = null;
            },
        },
    );
}

function openProcess(row: Row): void {
    selected.value = row;
    progressForm.reset();
    progressForm.clearErrors();
    progressForm.action = "progress";
    progressForm.note = "";
    processOpen.value = true;
}

function closeProcess(): void {
    processOpen.value = false;
    selected.value = null;
    progressForm.reset();
    progressForm.clearErrors();
    progressForm.action = "progress";
    progressForm.note = "";
}

function saveProgress(action: "progress" | "complete"): void {
    if (!selected.value) return;
    progressForm.action = action;
    progressForm.put(`/technical-requests/${selected.value.uuid}/progress`, {
        preserveScroll: true,
        onSuccess: () => {
            closeProcess();
        },
    });
}
</script>

<template>
    <Head title="技术需求" />
    <AppLayout
        :breadcrumbs="[
            { label: '个人' },
            { label: '需求和报销' },
            { label: '技术需求' },
        ]"
    >
        <main class="mx-auto w-full max-w-[1500px] space-y-5">
            <header
                class="flex flex-col gap-4 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:flex-row sm:items-center sm:justify-between"
            >
                <div>
                    <span
                        class="rounded-full bg-violet-50 px-3 py-1 text-xs font-semibold text-violet-700"
                        >{{ scopeLabel }}</span
                    >
                    <h1 class="mt-3 text-2xl font-bold text-slate-950">
                        技术需求
                    </h1>
                    <p class="mt-1.5 text-sm text-slate-500">
                        提交系统问题、功能开发、数据和自动化需求，审批通过后由开发人员处理。数据截至：
                        {{ today }}
                    </p>
                </div>
                <button
                    v-if="canCreate"
                    class="h-11 rounded-xl bg-violet-600 px-5 text-sm font-semibold text-white shadow-sm hover:bg-violet-700"
                    @click="createOpen = true"
                >
                    + 新建技术需求
                </button>
            </header>

            <nav class="flex gap-1 border-b border-slate-200" aria-label="技术需求视图">
                <button
                    v-if="canViewOverview"
                    class="border-b-2 px-4 py-3 text-sm font-semibold"
                    :class="
                        activeTab === 'overview'
                            ? 'border-violet-600 text-violet-700'
                            : 'border-transparent text-slate-500'
                    "
                    type="button"
                    @click="selectTab('overview')"
                >
                    效率总览
                </button>
                <button
                    class="border-b-2 px-4 py-3 text-sm font-semibold"
                    :class="
                        activeTab === 'list'
                            ? 'border-violet-600 text-violet-700'
                            : 'border-transparent text-slate-500'
                    "
                    type="button"
                    @click="selectTab('list')"
                >
                    需求列表 <span class="ml-1 text-xs">{{ summary.total }}</span>
                </button>
            </nav>

            <template v-if="activeTab === 'overview' && canViewOverview">
                <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <article
                        class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"
                    >
                        <p class="text-sm text-slate-500">需求总数</p>
                        <strong
                            class="mt-2 block text-3xl font-bold text-slate-950"
                            >{{ summary.total }}</strong
                        >
                        <p class="mt-2 text-xs text-slate-400">{{ scopeLabel }}</p>
                    </article>
                    <article
                        class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"
                    >
                        <p class="text-sm text-slate-500">处理中</p>
                        <strong
                            class="mt-2 block text-3xl font-bold text-blue-600"
                            >{{ summary.in_progress }}</strong
                        >
                        <p class="mt-2 text-xs text-slate-400">已接单且处理中</p>
                    </article>
                    <article
                        class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"
                    >
                        <p class="text-sm text-slate-500">已完成</p>
                        <strong
                            class="mt-2 block text-3xl font-bold text-emerald-600"
                            >{{ summary.completed }}</strong
                        >
                        <p class="mt-2 text-xs text-slate-400">
                            按时率 {{ percentRate(summary.on_time_rate) }}
                        </p>
                    </article>
                    <article
                        class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"
                    >
                        <p class="text-sm text-slate-500">已逾期</p>
                        <strong
                            class="mt-2 block text-3xl font-bold text-rose-600"
                            >{{ summary.overdue }}</strong
                        >
                        <p class="mt-2 text-xs text-slate-400">含当前逾期任务</p>
                    </article>
                </section>

                <section class="grid gap-5 xl:grid-cols-[1.35fr_.65fr]">
                    <article
                        class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm"
                    >
                        <h2 class="text-base font-semibold text-slate-900">
                            状态分布
                        </h2>
                        <div class="mt-5 space-y-4">
                            <div
                                v-for="status in statusOrder"
                                :key="status"
                                class="grid grid-cols-[92px_minmax(0,1fr)_48px] items-center gap-3 text-sm"
                            >
                                <span class="text-xs text-slate-600">{{ statusLabels[status] }}</span>
                                <div class="h-2.5 overflow-hidden rounded-full bg-slate-100">
                                    <div
                                        class="h-full rounded-full transition-all"
                                        :style="{
                                            width: `${(statusCount(status) / maxStatusCount) * 100}%`,
                                            background: statusColors[status],
                                        }"
                                    ></div>
                                </div>
                                <strong class="text-right tabular-nums text-slate-800 text-xs"
                                    >{{ statusCount(status) }}</strong
                                >
                            </div>
                        </div>
                    </article>
                    <article
                        class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm"
                    >
                        <h2 class="text-base font-semibold text-slate-900">
                            交付表现
                        </h2>
                        <dl class="mt-5 divide-y divide-slate-100">
                            <div class="flex items-center justify-between py-4">
                                <dt class="text-sm text-slate-500">按时率</dt>
                                <dd
                                    class="text-xl font-bold tabular-nums text-slate-900"
                                >
                                    {{ percentRate(summary.on_time_rate) }}
                                </dd>
                            </div>
                            <div class="flex items-center justify-between py-4">
                                <dt class="text-sm text-slate-500">
                                    平均处理周期
                                </dt>
                                <dd
                                    class="text-xl font-bold tabular-nums text-slate-900"
                                >
                                    {{ formatDays(summary.average_turnaround_days) }}
                                </dd>
                            </div>
                            <div class="flex items-center justify-between py-4">
                                <dt class="text-sm text-slate-500">待接受</dt>
                                <dd
                                    class="text-xl font-bold tabular-nums text-amber-600"
                                >
                                    {{ summary.unassigned }}
                                </dd>
                            </div>
                        </dl>
                    </article>
                </section>

                <section
                    class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm"
                >
                    <header class="border-b border-slate-100 px-6 py-5">
                        <h2 class="text-base font-semibold text-slate-900">
                            开发人员交付概览
                        </h2>
                        <p class="mt-1 text-sm text-slate-500">
                            说明：任务按实际接单开发人员归属统计
                        </p>
                    </header>
                    <div class="overflow-x-auto">
                        <table
                            class="w-full min-w-[960px] text-left text-sm"
                        >
                            <thead
                                class="bg-slate-50/80 text-slate-500"
                            >
                                <tr>
                                    <th class="px-6 py-3.5 font-medium">开发人员</th>
                                    <th class="px-4 py-3.5 text-right font-medium">
                                        接单总数
                                    </th>
                                    <th class="px-4 py-3.5 text-right font-medium">
                                        处理中
                                    </th>
                                    <th class="px-4 py-3.5 text-right font-medium">
                                        已完成
                                    </th>
                                    <th class="px-4 py-3.5 text-right font-medium">
                                        逾期
                                    </th>
                                    <th class="px-4 py-3.5 text-right font-medium">
                                        按时率
                                    </th>
                                    <th class="px-4 py-3.5 text-right font-medium">
                                        平均周期
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <tr
                                    v-for="item in summary.developer_performance"
                                    :key="item.developer_id"
                                >
                                    <td
                                        class="px-6 py-4 font-semibold text-slate-800"
                                    >
                                        {{ item.developer }}
                                    </td>
                                    <td
                                        class="px-4 py-4 text-right tabular-nums text-slate-700"
                                    >
                                        {{ item.total }}
                                    </td>
                                    <td
                                        class="px-4 py-4 text-right tabular-nums text-blue-600"
                                    >
                                        {{ item.in_progress }}
                                    </td>
                                    <td
                                        class="px-4 py-4 text-right tabular-nums text-emerald-600"
                                    >
                                        {{ item.completed }}
                                    </td>
                                    <td
                                        class="px-4 py-4 text-right tabular-nums text-rose-600"
                                    >
                                        {{ item.overdue }}
                                    </td>
                                    <td
                                        class="px-4 py-4 text-right tabular-nums text-slate-700"
                                    >
                                        {{ percentRate(item.on_time_rate) }}
                                    </td>
                                    <td
                                        class="px-4 py-4 text-right tabular-nums text-slate-700"
                                    >
                                        {{ formatDays(item.average_turnaround_days) }}
                                    </td>
                                </tr>
                                <tr v-if="!summary.developer_performance.length">
                                    <td
                                        colspan="7"
                                        class="px-6 py-10 text-center text-slate-400"
                                    >
                                        暂无交付数据
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>
            </template>

            <section v-else class="space-y-3">
                <form
                    class="grid gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:grid-cols-[minmax(220px,1fr)_180px_auto]"
                    @submit.prevent="applyFilters"
                >
                    <input
                        v-model="filters.search"
                        placeholder="搜索编号、标题或描述"
                        class="h-11 rounded-xl border-slate-200 text-sm"
                    /><select
                        v-model="filters.status"
                        class="h-11 rounded-xl border-slate-200 text-sm"
                    >
                        <option value="">全部状态</option>
                        <option
                            v-for="(label, value) in statusLabels"
                            :key="value"
                            :value="value"
                        >
                            {{ label }}
                        </option></select
                    ><button
                        class="h-11 rounded-xl bg-slate-900 px-5 text-sm font-semibold text-white"
                    >
                        筛选
                    </button>
                </form>
                <article
                    v-for="row in requests.data"
                    :key="row.uuid"
                    class="grid gap-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm lg:grid-cols-[minmax(280px,1.4fr)_150px_160px_130px_auto] lg:items-center"
                >
                    <div>
                        <div class="flex flex-wrap items-center gap-2">
                            <span
                                class="font-mono text-xs font-semibold text-slate-400"
                                >{{ row.reference_no }}</span
                            ><span
                                class="rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-600"
                                >{{ categoryLabels[row.category] }}</span
                            >
                        </div>
                        <h2 class="mt-2 font-semibold text-slate-900">
                            {{ row.title }}
                        </h2>
                        <p
                            class="mt-1 line-clamp-2 text-sm leading-5 text-slate-500"
                        >
                            {{ row.description }}
                        </p>
                        <div
                            v-if="row.attachments.length"
                            class="mt-3 flex gap-2"
                        >
                            <a
                                v-for="file in row.attachments.slice(0, 4)"
                                :key="file.uuid"
                                :href="file.url"
                                target="_blank"
                                ><img
                                    :src="file.url"
                                    :alt="file.name"
                                    class="h-11 w-14 rounded-lg border border-slate-200 object-cover"
                            /></a>
                        </div>
                    </div>
                    <div class="text-sm">
                        <p class="text-xs text-slate-400">提交人</p>
                        <p class="mt-1 font-medium text-slate-700">
                            {{ row.submitter }}
                        </p>
                        <p class="mt-3 text-xs text-slate-400">期望日期</p>
                        <p class="mt-1 text-slate-700">
                            {{ row.desired_date }}
                        </p>
                    </div>
                    <div>
                        <span
                            class="rounded-full bg-violet-50 px-2.5 py-1 text-xs font-semibold text-violet-700"
                            >{{ priorityLabels[row.priority] }}优先级</span
                        >
                        <p class="mt-3 text-xs text-slate-400">处理人</p>
                        <p
                            class="mt-1 text-sm font-medium"
                            :class="
                                row.assignee
                                    ? 'text-slate-700'
                                    : 'text-amber-600'
                            "
                        >
                            {{ row.assignee || "待接受" }}
                        </p>
                    </div>
                    <div>
                        <span
                            class="rounded-full px-3 py-1.5 text-xs font-semibold"
                            :class="
                                row.status === 'completed'
                                    ? 'bg-emerald-50 text-emerald-700'
                                    : row.status === 'rejected'
                                      ? 'bg-rose-50 text-rose-700'
                                      : row.status === 'pending_approval'
                                        ? 'bg-amber-50 text-amber-700'
                                        : 'bg-blue-50 text-blue-700'
                            "
                            >{{ statusLabels[row.status] }}</span
                        >
                        <p class="mt-2 text-xs text-slate-400">
                            {{ row.created_at }}
                        </p>
                    </div>
                    <div v-if="canManage" class="text-right">
                        <button
                            v-if="row.can_accept"
                            class="rounded-xl border border-violet-200 bg-violet-50 px-4 py-2 text-sm font-semibold text-violet-700"
                            :disabled="accepting !== null"
                            @click="accept(row)"
                        >
                            {{ accepting === row.uuid ? "接受中…" : "接受" }}
                        </button
                        ><button
                            v-else-if="row.can_process"
                            class="rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white"
                            @click="openProcess(row)"
                        >
                            处理
                        </button>
                    </div>
                </article>
                <div
                    v-if="!requests.data.length"
                    class="rounded-2xl border border-dashed border-slate-300 bg-white py-16 text-center text-sm text-slate-400"
                >
                    暂无技术需求
                </div>
            </section>

            <footer
                v-if="requests.last_page > 1"
                class="flex justify-center gap-2"
            >
                <button
                    :disabled="!requests.prev_page_url"
                    class="rounded-lg border px-4 py-2 text-sm disabled:opacity-40"
                    @click="
                        requests.prev_page_url &&
                        router.get(requests.prev_page_url)
                    "
                >
                    上一页</button
                ><span class="px-3 py-2 text-sm text-slate-500"
                    >{{ requests.current_page }} /
                    {{ requests.last_page }}</span
                ><button
                    :disabled="!requests.next_page_url"
                    class="rounded-lg border px-4 py-2 text-sm disabled:opacity-40"
                    @click="
                        requests.next_page_url &&
                        router.get(requests.next_page_url)
                    "
                >
                    下一页
                </button>
            </footer>
        </main>
        <Teleport to="body"
            ><dialog
                :open="createOpen"
                class="fixed inset-0 z-50 m-0 h-full w-full max-w-none bg-slate-950/45 p-4"
                @click.self="createOpen = false"
            >
                <form
                    class="mx-auto mt-[5vh] flex h-[90vh] w-full max-w-2xl flex-col overflow-hidden rounded-3xl bg-white shadow-2xl"
                    @submit.prevent="submit"
                >
                    <div class="flex shrink-0 justify-between p-7 pb-0">
                        <div>
                            <h2 class="text-xl font-bold">新建技术需求</h2>
                            <p class="mt-1 text-sm text-slate-500">
                                描述问题、目标和期望完成时间。
                            </p>
                        </div>
                        <button
                            type="button"
                            class="text-2xl text-slate-400"
                            @click="createOpen = false"
                        >
                            ×
                        </button>
                    </div>
                    <div class="min-h-0 flex-1 overflow-y-auto px-7 pb-6">
                        <div class="mt-6 grid gap-4 sm:grid-cols-2">
                            <label
                                class="text-sm font-semibold text-slate-700 sm:col-span-2"
                                >需求标题<input
                                    v-model="form.title"
                                    required
                                    maxlength="180"
                                    class="mt-2 h-11 w-full rounded-xl border-slate-200"
                                    placeholder="一句话说明需要解决的问题" /></label
                            ><label
                                class="text-sm font-semibold text-slate-700"
                                >需求类型<select
                                    v-model="form.category"
                                    class="mt-2 h-11 w-full rounded-xl border-slate-200"
                                >
                                    <option
                                        v-for="item in options.categories"
                                        :key="item"
                                        :value="item"
                                    >
                                        {{ categoryLabels[item] }}
                                    </option>
                                </select></label
                            ><label
                                class="text-sm font-semibold text-slate-700"
                                >优先级<select
                                    v-model="form.priority"
                                    class="mt-2 h-11 w-full rounded-xl border-slate-200"
                                >
                                    <option
                                        v-for="item in options.priorities"
                                        :key="item"
                                        :value="item"
                                    >
                                        {{ priorityLabels[item] }}
                                    </option>
                                </select></label
                            ><label
                                class="text-sm font-semibold text-slate-700 sm:col-span-2"
                                >期望完成日期<input
                                    v-model="form.desired_date"
                                    required
                                    type="date"
                                    class="mt-2 h-11 w-full rounded-xl border-slate-200" /></label
                            ><label
                                class="text-sm font-semibold text-slate-700 sm:col-span-2"
                                >需求描述<textarea
                                    v-model="form.description"
                                    required
                                    rows="7"
                                    maxlength="5000"
                                    class="mt-2 w-full rounded-xl border-slate-200"
                                    placeholder="请说明现状、期望结果、复现步骤或验收标准"
                                    @paste="pasteImages"
                                ></textarea></label
                            ><label
                                class="text-sm font-semibold text-slate-700 sm:col-span-2"
                                >截图或附件<input
                                    type="file"
                                    multiple
                                    accept="image/*"
                                    class="mt-2 block w-full text-sm"
                                    @change="selectFiles"
                                /><span
                                    class="mt-1 block text-xs font-normal text-slate-400"
                                    >可直接在描述框粘贴截图，最多 10 张。</span
                                ></label
                            >
                        </div>
                        <p
                            v-if="form.hasErrors"
                            class="mt-4 rounded-xl bg-rose-50 p-3 text-sm text-rose-700"
                        >
                            {{ Object.values(form.errors).join("；") }}
                        </p>
                    </div>
                    <div class="flex shrink-0 justify-end gap-3 border-t border-slate-100 bg-white px-7 py-4">
                        <button
                            type="button"
                            class="h-11 rounded-xl border px-5 text-sm font-semibold"
                            @click="createOpen = false"
                        >
                            取消
                        </button
                        ><button
                            class="h-11 rounded-xl bg-violet-600 px-5 text-sm font-semibold text-white"
                        >
                            提交
                        </button>
                    </div>
                </form>
            </dialog>
            <dialog
                :open="processOpen"
                class="fixed inset-0 z-50 m-0 h-full w-full max-w-none bg-slate-950/45 p-4"
                @click.self="closeProcess"
            >
                <form
                    v-if="selected"
                    class="mx-auto mt-[7vh] flex h-[86vh] w-full max-w-xl flex-col overflow-hidden rounded-3xl bg-white shadow-2xl"
                    @submit.prevent="saveProgress('progress')"
                >
                    <div class="flex shrink-0 justify-between p-7 pb-0">
                        <div>
                            <p class="font-mono text-xs text-violet-600">
                                {{ selected.reference_no }}
                            </p>
                            <h2 class="mt-1 text-xl font-bold">
                                {{ selected.title }}
                            </h2>
                        </div>
                        <button
                            type="button"
                            class="text-2xl text-slate-400"
                            aria-label="关闭处理"
                            @click="closeProcess"
                        >
                            ×
                        </button>
                    </div>
                    <div class="min-h-0 flex-1 overflow-y-auto px-7 pb-6">
                        <div class="mt-6 space-y-3">
                            <div
                                v-for="log in selected.progress_logs"
                                :key="log.uuid"
                                class="rounded-xl bg-slate-50 p-3"
                            >
                                <div
                                    class="flex justify-between text-xs text-slate-400"
                                >
                                    <span>{{ log.user }} · {{ logActionLabels[log.action] ?? log.action }}</span
                                    ><span>{{ log.created_at }}</span>
                                </div>
                                <p
                                    v-if="log.content"
                                    class="mt-2 text-sm text-slate-700"
                                >
                                    {{ log.content }}
                                </p>
                            </div>
                        </div>
                        <label
                            class="mt-5 block text-sm font-semibold text-slate-700"
                            >进展记录<textarea
                                v-model="progressForm.note"
                                rows="5"
                                maxlength="3000"
                                class="mt-2 w-full rounded-xl border-slate-200"
                                placeholder="填写已完成内容、链接或遇到的问题"
                            ></textarea>
                        </label>
                        <p
                            v-if="progressForm.hasErrors"
                            class="mt-3 rounded-xl bg-rose-50 p-3 text-sm text-rose-700"
                        >
                            {{ Object.values(progressForm.errors).join("；") }}
                        </p>
                    </div>
                    <div class="flex shrink-0 justify-end gap-3 border-t border-slate-100 bg-white px-7 py-4">
                        <button
                            type="submit"
                            class="h-11 rounded-xl border border-violet-200 bg-violet-50 px-5 text-sm font-semibold text-violet-700 disabled:opacity-50"
                            :disabled="progressForm.processing"
                        >
                            {{ progressForm.processing ? "提交中…" : "提交进展" }}</button
                        ><button
                            type="button"
                            class="h-11 rounded-xl bg-emerald-600 px-5 text-sm font-semibold text-white disabled:opacity-50"
                            :disabled="progressForm.processing"
                            @click="saveProgress('complete')"
                        >
                            完成需求
                        </button>
                    </div>
                </form>
            </dialog></Teleport
        >
    </AppLayout>
</template>
