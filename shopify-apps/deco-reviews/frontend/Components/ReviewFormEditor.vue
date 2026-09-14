<script setup lang="ts">
import { router, useForm, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';

type QuestionType = 'single' | 'multiple' | 'scale';
type QuestionKind = 'all' | 'product' | 'store';
type Question = {
    id: string; label: string; type: QuestionType; required: boolean; public: boolean;
    kind: QuestionKind; product_ids: number[]; options: string[]; min: number; max: number;
};
type FormConfig = {
    version: string;
    heading: string; description: string; name_label: string; title_label: string; body_label: string;
    submit_label: string; thank_you: string; allow_photos: boolean; allow_video: boolean; questions: Question[];
};

const props = defineProps<{
    baseUrl: string; canManage: boolean; products: Array<{ id: number; title: string }>; formConfig: FormConfig;
}>();
const emit = defineEmits<{ dirty: [value: boolean]; saved: [] }>();
const page = usePage<{ formConfig: FormConfig }>();

const clone = (value: FormConfig): FormConfig => JSON.parse(JSON.stringify(value));
const initial = ref(JSON.stringify(props.formConfig));
const form = useForm<FormConfig>(clone(props.formConfig));
const message = ref('');
const isDirty = computed(() => JSON.stringify(form.data()) !== initial.value);
watch(isDirty, value => emit('dirty', value), { immediate: true });
const confirmLeave = (event: BeforeUnloadEvent) => {
    if (!isDirty.value) return;
    event.preventDefault(); event.returnValue = '';
};
let stopNavigationGuard: (() => void) | undefined;
onMounted(() => {
    window.addEventListener('beforeunload', confirmLeave);
    stopNavigationGuard = router.on('before', event => {
        if (isDirty.value && event.detail.visit.method === 'get' && !window.confirm('评价表单有未保存的更改，确定离开吗？')) event.preventDefault();
    });
});
onBeforeUnmount(() => { window.removeEventListener('beforeunload', confirmLeave); stopNavigationGuard?.(); });

const addQuestion = () => {
    if (!props.canManage || form.questions.length >= 10) return;
    if (!globalThis.crypto?.randomUUID) { message.value = '请使用支持安全连接的现代浏览器创建问题。'; return; }
    form.questions.push({ id: globalThis.crypto.randomUUID(), label: '', type: 'single', required: false, public: false, kind: 'all', product_ids: [], options: [], min: 1, max: 5 });
};
const removeQuestion = (index: number) => { if (props.canManage) form.questions.splice(index, 1); };
const moveQuestion = (index: number, step: number) => {
    if (!props.canManage) return;
    const next = index + step;
    if (next < 0 || next >= form.questions.length) return;
    const [question] = form.questions.splice(index, 1);
    form.questions.splice(next, 0, question);
};
const optionsText = (question: Question) => question.options.join('\n');
const updateOptions = (question: Question, value: string) => {
    question.options = value.split(/\r?\n/);
};
const save = () => {
    message.value = '';
    form.transform(data => ({ ...data, questions: data.questions.map(question => ({ ...question,
        product_ids: question.kind === 'product' ? question.product_ids : [],
        options: question.type === 'scale' ? [] : question.options.map(option => option.trim()).filter(Boolean),
    })) })).put(`${props.baseUrl}/form`, {
        preserveScroll: true,
        onSuccess: () => {
            const fresh = clone(page.props.formConfig);
            form.defaults(fresh); form.reset(); initial.value = JSON.stringify(fresh);
            message.value = '评价表单已保存。';
            emit('dirty', false);
            emit('saved');
        },
    });
};
watch(() => props.formConfig, value => {
    if (isDirty.value) return;
    form.defaults(clone(value)); form.reset(); initial.value = JSON.stringify(value);
}, { deep: true });
</script>

<template>
    <form class="space-y-5 text-[14px]" @submit.prevent="save">
        <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div><h2 class="text-lg font-semibold text-slate-950">评价表单内容</h2><p class="mt-2 text-slate-500">自定义顾客看到的标题、说明和基础字段标签。</p></div>
                <a :href="`${baseUrl}/form-preview`" target="_blank" rel="noopener" class="rounded-xl border border-slate-200 px-4 py-2.5 font-semibold text-violet-700">预览已保存的表单（不可提交）</a>
            </div>
            <div class="mt-6 grid gap-5 md:grid-cols-2">
                <label class="font-medium">表单标题<input v-model="form.heading" :disabled="!canManage" required maxlength="160" class="mt-2 w-full rounded-xl border border-slate-200 px-3 py-2.5" /></label>
                <label class="font-medium">姓名字段<input id="review-form-name-label" v-model="form.name_label" :disabled="!canManage" required maxlength="160" class="mt-2 w-full rounded-xl border border-slate-200 px-3 py-2.5" /></label>
                <label class="font-medium md:col-span-2">表单说明<textarea v-model="form.description" :disabled="!canManage" maxlength="300" rows="3" class="mt-2 w-full rounded-xl border border-slate-200 px-3 py-2.5"></textarea></label>
                <label class="font-medium">评价标题字段<input v-model="form.title_label" :disabled="!canManage" required maxlength="160" class="mt-2 w-full rounded-xl border border-slate-200 px-3 py-2.5" /></label>
                <label class="font-medium">评价正文字段<input v-model="form.body_label" :disabled="!canManage" required maxlength="160" class="mt-2 w-full rounded-xl border border-slate-200 px-3 py-2.5" /></label>
                <label class="font-medium">提交按钮文字<input v-model="form.submit_label" :disabled="!canManage" required maxlength="160" class="mt-2 w-full rounded-xl border border-slate-200 px-3 py-2.5" /></label>
                <label class="font-medium">提交后提示<input v-model="form.thank_you" :disabled="!canManage" required maxlength="300" class="mt-2 w-full rounded-xl border border-slate-200 px-3 py-2.5" /></label>
            </div>
            <div class="mt-5 flex flex-wrap gap-6"><label class="flex items-center gap-2 font-medium"><input v-model="form.allow_photos" :disabled="!canManage" type="checkbox" />允许上传图片</label><label class="flex items-center gap-2 font-medium"><input v-model="form.allow_video" :disabled="!canManage" type="checkbox" />允许上传视频</label></div>
        </section>

        <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <div class="flex flex-wrap items-center justify-between gap-3"><div><h2 class="text-lg font-semibold text-slate-950">附加问题</h2><p class="mt-2 text-slate-500">最多 10 个。问题默认仅供内部查看，需明确开启才会公开。</p></div><button type="button" :disabled="!canManage || form.questions.length >= 10" class="rounded-xl bg-slate-950 px-4 py-2.5 font-semibold text-white disabled:opacity-40" @click="addQuestion">添加问题</button></div>
            <p v-if="!form.questions.length" class="mt-8 rounded-xl bg-slate-50 px-5 py-8 text-center text-slate-500">尚未添加附加问题。</p>
            <article v-for="(question, index) in form.questions" :key="question.id" class="mt-5 rounded-2xl border border-slate-200 p-5">
                <div class="flex flex-wrap items-center justify-between gap-3"><strong>问题 {{ index + 1 }}</strong><div class="flex gap-2"><button type="button" :disabled="!canManage || index === 0" class="rounded-lg border px-3 py-1.5 disabled:opacity-40" :aria-label="`上移问题 ${index + 1}`" @click="moveQuestion(index, -1)">上移</button><button type="button" :disabled="!canManage || index === form.questions.length - 1" class="rounded-lg border px-3 py-1.5 disabled:opacity-40" :aria-label="`下移问题 ${index + 1}`" @click="moveQuestion(index, 1)">下移</button><button type="button" :disabled="!canManage" class="rounded-lg border border-red-200 px-3 py-1.5 font-semibold text-red-700 disabled:opacity-40" @click="removeQuestion(index)">移除</button></div></div>
                <div class="mt-4 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                    <label class="font-medium md:col-span-2">问题文字<input v-model="question.label" :disabled="!canManage" required maxlength="160" class="mt-2 w-full rounded-xl border border-slate-200 px-3 py-2.5" /></label>
                    <label class="font-medium">回答方式<select v-model="question.type" :disabled="!canManage" class="mt-2 w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5"><option value="single">单选</option><option value="multiple">多选</option><option value="scale">评分刻度</option></select></label>
                    <label class="font-medium">适用评价<select v-model="question.kind" :disabled="!canManage" class="mt-2 w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5"><option value="all">全部评价</option><option value="product">商品评价</option><option value="store">店铺评价</option></select></label>
                    <label v-if="question.kind === 'product'" class="font-medium md:col-span-2">限定商品（可多选）<select v-model="question.product_ids" :disabled="!canManage" multiple class="mt-2 h-32 w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5"><option v-for="product in products" :key="product.id" :value="product.id">{{ product.title }}</option></select><span class="mt-1 block text-[12px] text-slate-500">不选择商品时适用于所有商品评价。</span></label>
                    <label v-if="question.type === 'single' || question.type === 'multiple'" class="font-medium md:col-span-2 xl:col-span-3">选项（每行一个）<textarea :value="optionsText(question)" :disabled="!canManage" required rows="4" class="mt-2 w-full rounded-xl border border-slate-200 px-3 py-2.5" @input="updateOptions(question, ($event.target as HTMLTextAreaElement).value)"></textarea></label>
                    <template v-else><label class="font-medium">最小值<input v-model.number="question.min" :disabled="!canManage" type="number" min="1" max="10" required class="mt-2 w-full rounded-xl border border-slate-200 px-3 py-2.5" /></label><label class="font-medium">最大值<input v-model.number="question.max" :disabled="!canManage" type="number" min="1" max="10" required class="mt-2 w-full rounded-xl border border-slate-200 px-3 py-2.5" /></label></template>
                </div>
                <div class="mt-4 flex flex-wrap gap-6"><label class="flex items-center gap-2"><input v-model="question.required" :disabled="!canManage" type="checkbox" />必填</label><label class="flex items-center gap-2"><input v-model="question.public" :disabled="!canManage" type="checkbox" />允许公开展示此回答</label><span v-if="!question.public" class="rounded-full bg-slate-100 px-2.5 py-1 text-[12px] font-semibold text-slate-600">仅内部可见</span></div>
            </article>
        </section>

        <div v-if="Object.keys(form.errors).length" role="alert" class="rounded-xl border border-red-200 bg-red-50 p-4 text-red-800"><p v-for="(error, field) in form.errors" :key="field">{{ error }}</p></div>
        <p v-if="message" role="status" class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-emerald-800">{{ message }}</p>
        <div class="flex items-center gap-3"><button :disabled="!canManage || form.processing || !isDirty" class="rounded-xl bg-violet-700 px-6 py-3 font-semibold text-white disabled:opacity-40">{{ form.processing ? '保存中…' : '保存评价表单' }}</button><span v-if="isDirty" class="text-[12px] font-semibold text-amber-700">有未保存更改</span></div>
    </form>
</template>
