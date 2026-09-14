<script setup lang="ts">
import { Head, router, useForm } from "@inertiajs/vue3";
import EmptyState from "../../Components/Feedback/EmptyState.vue";
import Pagination from "../../Components/Navigation/Pagination.vue";
import AppLayout from "../../Layouts/AppLayout.vue";
import { useStoreDateTime } from "../../composables/useStoreDateTime";

interface AlertRow {
    context?: { changes?: string[] };
    id: number;
    uuid: string;
    type: string;
    severity: string;
    code: string;
    title: string;
    message: string;
    status: string;
    delivery_status: string | null;
    delivery_error: string | null;
    occurred_at: string | null;
    acknowledged_at: string | null;
    resolved_at: string | null;
}
interface Page<T> {
    data: T[];
    links: Array<{ url: string | null; label: string; active: boolean }>;
}
const props = defineProps<{
    alerts: Page<AlertRow>;
    filters: { status?: string; type?: string };
}>();
const form = useForm({
    status: props.filters.status ?? "",
    type: props.filters.type ?? "",
});
const submit = () =>
    form.get("/alerts", { preserveState: true, replace: true });
const scan = () => router.post("/alerts/scan", {}, { preserveScroll: true });
const act = (id: number, action: "acknowledge" | "resolve") =>
    router.post(`/alerts/${id}/${action}`, {}, { preserveScroll: true });
const { formatDateTime: date } = useStoreDateTime();
const typeLabel = (value: string) =>
    ({
        connection: "连接",
        sync: "数据同步",
        webhook: "Webhook",
        discount: "重点折扣",
        product: "重点产品",
    })[value] || value;
const statusLabel = (value: string) =>
    ({ open: "待处理", acknowledged: "已确认", resolved: "已解决" })[value] ||
    value;
const severityClass = (value: string) =>
    value === "critical" || value === "error"
        ? "bg-rose-50 text-rose-700"
        : value === "warning"
          ? "bg-amber-50 text-amber-700"
          : "bg-sky-50 text-sky-700";
</script>
<template>
    <Head title="异常告警" /><AppLayout
        :breadcrumbs="[
            { label: '工作台', href: '/dashboard' },
            { label: 'Shopify' },
            { label: '异常告警' },
        ]"
        ><div class="mx-auto max-w-7xl space-y-6">
            <header
                class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between"
            >
                <div>
                    <p class="text-sm font-semibold text-emerald-700">
                        运营监控
                    </p>
                    <h1 class="mt-1 text-3xl font-semibold text-slate-950">
                        异常告警
                    </h1>
                    <p class="mt-2 text-sm text-slate-500">
                        集中查看连接、同步、Webhook
                        以及重点折扣和重点产品告警。立即检查按钮仅检查连接、同步与
                        Webhook。
                    </p>
                </div>
                <button
                    class="self-start rounded-xl bg-slate-950 px-5 py-3 text-sm font-semibold text-white shadow-sm"
                    @click="scan"
                >
                    立即检查
                </button>
            </header>
            <form
                class="grid gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:grid-cols-[220px_220px_auto]"
                @submit.prevent="submit"
            >
                <select
                    v-model="form.status"
                    class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-2.5 text-sm"
                >
                    <option value="">全部状态</option>
                    <option value="open">待处理</option>
                    <option value="acknowledged">已确认</option>
                    <option value="resolved">已解决</option></select
                ><select
                    v-model="form.type"
                    class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-2.5 text-sm"
                >
                    <option value="">全部类型</option>
                    <option value="connection">连接异常</option>
                    <option value="sync">同步异常</option>
                    <option value="webhook">Webhook 异常</option>
                    <option value="discount">重点折扣</option>
                    <option value="product">重点产品</option></select
                ><button
                    class="rounded-xl bg-slate-950 px-5 py-2.5 text-sm font-semibold text-white sm:justify-self-start"
                >
                    筛选
                </button>
            </form>
            <section v-if="alerts.data.length" class="space-y-3">
                <article
                    v-for="alert in alerts.data"
                    :key="alert.id"
                    class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"
                >
                    <div
                        class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between"
                    >
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <span
                                    class="rounded-full px-2.5 py-1 text-xs font-semibold"
                                    :class="severityClass(alert.severity)"
                                    >{{ typeLabel(alert.type) }}</span
                                ><span
                                    class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-600"
                                    >{{ statusLabel(alert.status) }}</span
                                ><span
                                    v-if="alert.delivery_status"
                                    class="text-xs text-slate-400"
                                    >通知：{{ alert.delivery_status }}</span
                                >
                            </div>
                            <h2 class="mt-3 font-semibold text-slate-950">
                                {{ alert.title }}
                            </h2>
                            <p
                                class="mt-1 whitespace-pre-line text-sm leading-6 text-slate-600"
                            >
                                {{ alert.message }}
                            </p>
                            <details
                                v-if="
                                    alert.type === 'product' &&
                                    alert.context?.changes?.length
                                "
                                class="mt-2 text-sm text-slate-600"
                            >
                                <summary class="cursor-pointer">
                                    查看全部变更（{{
                                        alert.context.changes.length
                                    }}）
                                </summary>
                                <ul class="mt-2 space-y-1">
                                    <li
                                        v-for="(change, index) in alert.context
                                            .changes"
                                        :key="index"
                                    >
                                        {{ change }}
                                    </li>
                                </ul>
                            </details>
                            <p class="mt-3 text-xs text-slate-400">
                                {{ date(alert.occurred_at) }} · {{ alert.code }}
                            </p>
                            <p
                                v-if="alert.delivery_error"
                                class="mt-2 text-xs text-rose-500"
                            >
                                通知发送失败：{{ alert.delivery_error }}
                            </p>
                        </div>
                        <div
                            v-if="alert.status !== 'resolved'"
                            class="flex shrink-0 gap-2"
                        >
                            <button
                                v-if="alert.status === 'open'"
                                class="rounded-xl border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-700"
                                @click="act(alert.id, 'acknowledge')"
                            >
                                确认</button
                            ><button
                                class="rounded-xl bg-emerald-600 px-4 py-2 text-sm font-semibold text-white"
                                @click="act(alert.id, 'resolve')"
                            >
                                标记已解决
                            </button>
                        </div>
                    </div>
                </article>
            </section>
            <EmptyState
                v-else
                title="当前没有异常告警"
                description="Shopify 连接、同步和 Webhook 运行正常，或当前筛选条件下没有记录。"
                icon="status"
            /><Pagination :links="alerts.links" /></div
    ></AppLayout>
</template>
