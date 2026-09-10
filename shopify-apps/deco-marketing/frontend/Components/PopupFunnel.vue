<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
export type Funnel={days:number;start:string;end:string;timezone:string;subscribers:number;new_subscribers:number;currently_subscribed:number;impressions:number;submissions:number;closes:number;inactive:number;submit_rate:number;close_rate:number;inactive_rate:number;daily:{day:string;impressions:number;submissions:number;closes:number;inactive:number;submit_rate:number;close_rate:number}[]};
defineProps<{report:Funnel;base:string}>();
const count=(n:number)=>n.toLocaleString('en-US');
const rate=(n:number)=>`${n.toFixed(2)}%`;
</script>
<template>
 <section class="funnel">
  <header><h3>店面订阅弹窗漏斗 · {{report.start}} → {{report.end}}</h3><div class="periods"><Link v-for="days in [7,30,90]" :key="days" :href="`${base}?tab=popup&days=${days}`" :class="{active:report.days===days}" :aria-current="report.days===days?'page':undefined">{{days}}天</Link><Link :href="`${base}?tab=popup&days=${report.days}`">↻ 刷新</Link></div></header>
  <div class="funnel-cards">
   <article><span>累计订阅人数</span><strong class="green">{{count(report.subscribers)}}</strong><small>本期新增 +{{count(report.new_subscribers)}} · 当前可发信 {{count(report.currently_subscribed)}}</small></article>
   <article><span>曝光量</span><strong>{{count(report.impressions)}}</strong><small>独立访客看到弹窗（按访客 × 天去重）</small></article>
   <article><span>提交率</span><strong class="green">{{rate(report.submit_rate)}}</strong><small>{{count(report.submissions)}} 次提交 / {{count(report.impressions)}} 次曝光</small></article>
   <article><span>关闭率</span><strong>{{rate(report.close_rate)}}</strong><small>{{count(report.closes)}} 次点关闭</small></article>
   <article><span>未操作</span><strong>{{rate(report.inactive_rate)}}</strong><small>{{count(report.inactive)}} 人划走/未操作</small></article>
  </div>
  <div class="definition"><strong>口径：</strong>曝光/提交/关闭均按「独立访客 × 天」去重，重复上报不虚增。「累计订阅人数」是真的落进联系人表的人，「提交」是表单提交次数，两者分别统计。累计不受上面 7/30/90 天窗口影响，本期新增看卡片副标题。「提交率」是表单转化率，与实际净新增订阅会有差异。直接划走页面（既不提交也不关闭）计入「未操作」。按 UTC 日期统计；同一天提交后又关闭不会重复扣减未操作人数。曝光与行为仅统计允许分析的访问。</div>
  <section class="daily"><h3>按天明细（有曝光的天）</h3><div class="scroll"><table><thead><tr><th>日期</th><th>曝光</th><th>提交</th><th>提交率</th><th>关闭</th><th>关闭率</th></tr></thead><tbody><tr v-for="row in report.daily" :key="row.day"><td>{{row.day}}</td><td>{{count(row.impressions)}}</td><td>{{count(row.submissions)}}</td><td class="green">{{rate(row.submit_rate)}}</td><td>{{count(row.closes)}}</td><td>{{rate(row.close_rate)}}</td></tr><tr v-if="!report.daily.length"><td colspan="6">所选时间段暂无曝光记录</td></tr></tbody></table></div></section>
 </section>
</template>
<style scoped>
.funnel{display:grid;gap:24px}.funnel header{display:flex;justify-content:space-between;align-items:center;gap:20px}.funnel h3{font-size:16px;font-weight:650;color:#172b3a}.periods{display:flex;gap:8px;white-space:nowrap}.periods a{padding:10px 15px;border-radius:9px;border:1px solid #e1e7ed;background:#fff;font-size:14px;font-weight:600;color:#64748b}.periods .active{color:#047857;background:#e8f6ef;border-color:#cde7da}.funnel-cards{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:16px}.funnel-cards article{display:flex;flex-direction:column;gap:12px;background:white;border:1px solid #e5eaf0;border-radius:16px;padding:24px}.funnel-cards span{font-size:13px;color:#718096}.funnel-cards strong{font-size:30px;letter-spacing:-.5px}.funnel-cards small{font-size:12px;line-height:1.6;color:#8a98a7}.green{color:#047857}.definition{border:1px solid #e2e8f0;border-radius:14px;background:#f8fafc;padding:22px;line-height:1.9;font-size:13px;color:#526172}.daily{background:#fff;border:1px solid #e5eaf0;border-radius:16px;padding:24px}.daily h3{margin-bottom:18px}.scroll{overflow-x:auto}table{width:100%;border-collapse:collapse;text-align:left;font-size:13px}th,td{padding:15px;border-bottom:1px solid #edf1f5;white-space:nowrap}th{color:#718096;font-weight:600}@media(max-width:1100px){.funnel-cards{grid-template-columns:repeat(3,minmax(0,1fr))}}@media(max-width:760px){.funnel header{align-items:flex-start;flex-direction:column}.funnel-cards{grid-template-columns:repeat(2,minmax(0,1fr))}.funnel-cards article,.daily{padding:18px}}
</style>
