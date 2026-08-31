<script setup lang="ts">
import { computed, ref, watch } from 'vue';

type EmailBlockType = 'heading' | 'paragraph' | 'button' | 'note' | 'divider' | 'spacer' | 'discount_code';
type EmailAlignment = 'left' | 'center' | 'right';

interface EmailContentBlock {
    type: EmailBlockType;
    text?: string;
    align?: EmailAlignment;
    font_size?: number;
    bold?: boolean;
    italic?: boolean;
    underline?: boolean;
    color?: string;
    background_color?: string;
    url?: string;
    width?: 'auto' | 'full';
    spacing?: number;
}

interface TemplateVariable { key: string; label: string }

const props = defineProps<{
    modelValue: EmailContentBlock[];
    disabled: boolean;
    approval: boolean;
    variables: TemplateVariable[];
    discountSample: string;
    primaryColor: string;
}>();

const emit = defineEmits<{ 'update:modelValue': [value: EmailContentBlock[]] }>();
const selectedIndex = ref(0);
const fontSizes = [12, 14, 16, 17, 18, 20, 24, 28, 32, 36, 40, 48];
const blocks = computed(() => props.modelValue ?? []);
const selectedBlock = computed(() => blocks.value[selectedIndex.value] ?? null);
const styleable = computed(() => selectedBlock.value && ['heading', 'paragraph', 'button', 'note'].includes(selectedBlock.value.type));
const buttonCount = computed(() => blocks.value.filter(block => block.type === 'button').length);

watch(() => props.modelValue, value => {
    if (!value.length) selectedIndex.value = 0;
    else if (selectedIndex.value >= value.length) selectedIndex.value = value.length - 1;
}, { deep: false });

const cloneBlocks = () => blocks.value.map(block => ({ ...block }));
const patchBlock = (index: number, patch: Partial<EmailContentBlock>) => {
    if (props.disabled || !blocks.value[index]) return;
    const next = cloneBlocks();
    next[index] = { ...next[index], ...patch };
    emit('update:modelValue', next);
};
const inputValue = (event: Event) => (event.target as HTMLInputElement | HTMLTextAreaElement).value;
const inputNumber = (event: Event) => Number((event.target as HTMLInputElement | HTMLSelectElement).value);
const inputWidth = (event: Event): 'auto' | 'full' => inputValue(event) === 'auto' ? 'auto' : 'full';

const defaultBlock = (type: EmailBlockType): EmailContentBlock => {
    if (type === 'heading') return { type, text: 'Your email heading', align: 'left', font_size: 32, bold: true, italic: false, underline: false, color: '#111111' };
    if (type === 'button') return { type, text: 'SHOP NOW', url: '', align: 'center', font_size: 18, bold: true, italic: false, underline: false, color: '#FFFFFF', background_color: props.primaryColor, width: 'full' };
    if (type === 'note') return { type, text: 'Add a short note.', align: 'left', font_size: 14, bold: false, italic: true, underline: false, color: '#5F5F5F' };
    if (type === 'divider') return { type, color: '#E5E7EB', spacing: 20 };
    if (type === 'spacer') return { type, spacing: 20 };
    return { type: 'paragraph', text: 'Write your email content here.', align: 'left', font_size: 17, bold: false, italic: false, underline: false, color: '#404040' };
};
const addBlock = (type: EmailBlockType) => {
    if (props.disabled || (type === 'button' && buttonCount.value >= 3)) return;
    const next = cloneBlocks();
    const insertAt = Math.min(selectedIndex.value + 1, next.length);
    next.splice(insertAt, 0, defaultBlock(type));
    emit('update:modelValue', next);
    selectedIndex.value = insertAt;
};
const removeBlock = (index: number) => {
    if (props.disabled || blocks.value[index]?.type === 'discount_code' || blocks.value.length <= 1) return;
    const next = cloneBlocks();
    next.splice(index, 1);
    emit('update:modelValue', next);
    selectedIndex.value = Math.max(0, Math.min(index, next.length - 1));
};
const moveBlock = (index: number, direction: -1 | 1) => {
    const target = index + direction;
    if (props.disabled || target < 0 || target >= blocks.value.length) return;
    const next = cloneBlocks();
    [next[index], next[target]] = [next[target], next[index]];
    emit('update:modelValue', next);
    selectedIndex.value = target;
};
const insertVariable = (key: string) => {
    const token = `{{ ${key} }}`;
    let index = selectedIndex.value;
    if (!selectedBlock.value || !['heading', 'paragraph', 'button', 'note'].includes(selectedBlock.value.type)) {
        addBlock('paragraph');
        index = selectedIndex.value;
    }
    const text = blocks.value[index]?.text ?? '';
    patchBlock(index, { text: `${text}${text && !/[\s\n]$/.test(text) ? ' ' : ''}${token}` });
};
const variableToken = (key: string) => `{{ ${key} }}`;
const blockLabel = (block: EmailContentBlock) => ({
    heading: '标题', paragraph: '正文', button: '按钮', note: '说明', divider: '分隔线', spacer: '留白', discount_code: '优惠码',
}[block.type]);
const textStyle = (block: EmailContentBlock) => ({
    textAlign: block.align ?? 'left',
    fontSize: `${block.font_size ?? 17}px`,
    fontWeight: block.bold ? '800' : '400',
    fontStyle: block.italic ? 'italic' : 'normal',
    textDecoration: block.underline ? 'underline' : 'none',
    color: block.color ?? '#404040',
});
</script>

<template>
    <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white">
        <div class="border-b border-slate-200 bg-slate-50 px-4 py-3">
            <div class="flex flex-wrap items-center gap-2">
                <button v-for="item in ([['paragraph', '＋ 正文'], ['heading', '＋ 标题'], ['button', '＋ 按钮'], ['note', '＋ 说明'], ['divider', '＋ 分隔线'], ['spacer', '＋ 留白']] as const)" :key="item[0]" type="button" :disabled="disabled || (item[0] === 'button' && buttonCount >= 3)" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-700 shadow-sm hover:border-blue-300 hover:text-blue-700 disabled:cursor-not-allowed disabled:opacity-40" @click="addBlock(item[0])">{{ item[1] }}</button>
            </div>
            <div v-if="styleable && selectedBlock" class="mt-3 flex flex-wrap items-center gap-2 border-t border-slate-200 pt-3">
                <select :value="selectedBlock.font_size" :disabled="disabled" aria-label="字号" class="h-9 rounded-lg border border-slate-200 bg-white px-2 text-xs" @change="patchBlock(selectedIndex, { font_size: inputNumber($event) })"><option v-for="size in fontSizes" :key="size" :value="size">{{ size }} px</option></select>
                <button type="button" :disabled="disabled" class="h-9 min-w-9 rounded-lg border px-2 text-sm font-black" :class="selectedBlock.bold ? 'border-blue-300 bg-blue-50 text-blue-700' : 'border-slate-200 bg-white text-slate-700'" aria-label="加粗" @click="patchBlock(selectedIndex, { bold: !selectedBlock.bold })">B</button>
                <button type="button" :disabled="disabled" class="h-9 min-w-9 rounded-lg border px-2 text-sm italic" :class="selectedBlock.italic ? 'border-blue-300 bg-blue-50 text-blue-700' : 'border-slate-200 bg-white text-slate-700'" aria-label="斜体" @click="patchBlock(selectedIndex, { italic: !selectedBlock.italic })">I</button>
                <button type="button" :disabled="disabled" class="h-9 min-w-9 rounded-lg border px-2 text-sm underline" :class="selectedBlock.underline ? 'border-blue-300 bg-blue-50 text-blue-700' : 'border-slate-200 bg-white text-slate-700'" aria-label="下划线" @click="patchBlock(selectedIndex, { underline: !selectedBlock.underline })">U</button>
                <span class="mx-1 h-6 w-px bg-slate-200" />
                <button v-for="alignment in ([['left', '左'], ['center', '中'], ['right', '右']] as const)" :key="alignment[0]" type="button" :disabled="disabled || selectedBlock.type === 'button'" class="h-9 rounded-lg border px-2.5 text-xs font-semibold" :class="selectedBlock.align === alignment[0] ? 'border-blue-300 bg-blue-50 text-blue-700' : 'border-slate-200 bg-white text-slate-600'" @click="patchBlock(selectedIndex, { align: alignment[0] })">{{ alignment[1] }}</button>
                <label class="ml-1 flex h-9 items-center gap-2 rounded-lg border border-slate-200 bg-white px-2 text-xs text-slate-600"><span>文字</span><input :value="selectedBlock.color" :disabled="disabled" type="color" class="h-6 w-7 cursor-pointer border-0 bg-transparent p-0" @input="patchBlock(selectedIndex, { color: inputValue($event) })"></label>
                <label v-if="selectedBlock.type === 'button'" class="flex h-9 items-center gap-2 rounded-lg border border-slate-200 bg-white px-2 text-xs text-slate-600"><span>背景</span><input :value="selectedBlock.background_color" :disabled="disabled" type="color" class="h-6 w-7 cursor-pointer border-0 bg-transparent p-0" @input="patchBlock(selectedIndex, { background_color: inputValue($event) })"></label>
                <select v-if="selectedBlock.type === 'button'" :value="selectedBlock.width" :disabled="disabled" aria-label="按钮宽度" class="h-9 rounded-lg border border-slate-200 bg-white px-2 text-xs" @change="patchBlock(selectedIndex, { width: inputWidth($event) })"><option value="full">整行按钮</option><option value="auto">内容宽度</option></select>
            </div>
        </div>

        <div class="space-y-3 bg-slate-100 p-4">
            <article v-for="(block, index) in blocks" :key="`${block.type}-${index}`" class="group relative rounded-xl border bg-white transition" :class="selectedIndex === index ? 'border-blue-400 ring-2 ring-blue-100' : 'border-slate-200 hover:border-slate-300'" @click="selectedIndex = index">
                <div class="absolute -top-2.5 left-3 z-10 rounded-full border border-slate-200 bg-white px-2 py-0.5 text-[10px] font-semibold text-slate-500 shadow-sm">{{ blockLabel(block) }}</div>
                <div class="absolute right-2 top-2 z-10 flex gap-1 opacity-0 transition group-hover:opacity-100" :class="selectedIndex === index ? 'opacity-100' : ''">
                    <button type="button" :disabled="disabled || index === 0" class="flex h-7 w-7 items-center justify-center rounded-md bg-white text-xs text-slate-500 shadow ring-1 ring-slate-200 disabled:opacity-30" aria-label="上移" @click.stop="moveBlock(index, -1)">↑</button>
                    <button type="button" :disabled="disabled || index === blocks.length - 1" class="flex h-7 w-7 items-center justify-center rounded-md bg-white text-xs text-slate-500 shadow ring-1 ring-slate-200 disabled:opacity-30" aria-label="下移" @click.stop="moveBlock(index, 1)">↓</button>
                    <button type="button" :disabled="disabled || block.type === 'discount_code' || blocks.length <= 1" class="flex h-7 w-7 items-center justify-center rounded-md bg-white text-sm text-rose-500 shadow ring-1 ring-slate-200 disabled:opacity-30" aria-label="删除区块" @click.stop="removeBlock(index)">×</button>
                </div>

                <div v-if="['heading', 'paragraph', 'note'].includes(block.type)" class="px-5 py-5 pt-7">
                    <textarea :value="block.text" :disabled="disabled" :rows="block.type === 'paragraph' ? 4 : 2" class="w-full resize-y border-0 bg-transparent p-0 leading-relaxed outline-none disabled:cursor-default" :style="textStyle(block)" @focus="selectedIndex = index" @input="patchBlock(index, { text: inputValue($event) })" />
                </div>
                <div v-else-if="block.type === 'button'" class="space-y-3 px-5 py-6 pt-8">
                    <div class="text-center"><input :value="block.text" :disabled="disabled" class="max-w-full border-0 px-6 py-4 text-center outline-none" :class="block.width === 'full' ? 'w-full' : 'w-auto min-w-48'" :style="{ ...textStyle(block), backgroundColor: block.background_color ?? primaryColor }" maxlength="120" aria-label="按钮文字" @focus="selectedIndex = index" @input="patchBlock(index, { text: inputValue($event) })"></div>
                    <label v-if="selectedIndex === index" class="block"><span class="text-[11px] font-semibold text-slate-500">按钮链接（留空使用店铺首页）</span><input :value="block.url" :disabled="disabled" type="url" maxlength="2048" placeholder="https://…" class="mt-1.5 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs outline-none focus:border-blue-400" @input="patchBlock(index, { url: inputValue($event) })"></label>
                </div>
                <div v-else-if="block.type === 'discount_code'" class="px-5 py-7 pt-8"><div class="break-all border-[3px] px-4 py-5 text-center font-mono text-xl font-extrabold tracking-wider" :style="{ borderColor: primaryColor }">{{ discountSample }}</div><p class="mt-2 text-center text-[11px] text-slate-400">系统区块，会在发送时填入真实优惠码，不可删除</p></div>
                <div v-else-if="block.type === 'divider'" class="px-5 py-7" :style="{ paddingTop: `${block.spacing ?? 20}px`, paddingBottom: `${block.spacing ?? 20}px` }"><div class="border-t" :style="{ borderColor: block.color ?? '#E5E7EB' }" /><div v-if="selectedIndex === index" class="mt-3 flex items-center gap-3"><label class="text-[11px] text-slate-500">颜色 <input :value="block.color" type="color" :disabled="disabled" @input="patchBlock(index, { color: inputValue($event) })"></label><label class="text-[11px] text-slate-500">间距 <input :value="block.spacing" type="range" min="8" max="64" :disabled="disabled" @input="patchBlock(index, { spacing: inputNumber($event) })"></label></div></div>
                <div v-else class="flex items-center justify-center text-[11px] text-slate-400" :style="{ height: `${block.spacing ?? 20}px` }">留白 {{ block.spacing ?? 20 }} px</div>
            </article>
        </div>

        <div class="border-t border-slate-200 bg-white px-4 py-3">
            <p class="text-xs font-semibold text-slate-600">插入安全变量</p>
            <div class="mt-2 flex flex-wrap gap-2"><button v-for="variable in variables" :key="variable.key" type="button" :disabled="disabled" class="rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 font-mono text-xs text-slate-700 hover:border-emerald-300 hover:bg-emerald-50 disabled:opacity-50" :title="variable.label" @click="insertVariable(variable.key)">{{ variableToken(variable.key) }}</button></div>
            <p class="mt-2 text-[11px] leading-5 text-slate-400">选中区块后设置字号、粗细、对齐和颜色；按钮链接仅支持 http/https。编辑器不会保存 HTML、脚本或 iframe。</p>
        </div>
    </div>
</template>
