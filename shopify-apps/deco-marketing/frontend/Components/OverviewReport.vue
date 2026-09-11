<script setup lang="ts">
import { computed } from 'vue';
export type Report = {
 abandoned_7d:number;contacts:number;subscribed:number;sent:number;automation_sent:number;campaign_sent:number;raw_open_rate:number;human_open_rate:number|null;click_rate:number;attributed_orders:number;waiting:number;
 revenue:{currency:string;revenue:number;orders:number}[];
 revenue_by_flow:{flow:string;currency:string;revenue:number;orders:number}[];
 flows:{key:string;name:string;enabled:boolean;steps:number;active:number;sent:number;opened:number;human_opened:number|null;clicked:number;failed:number;suppressed:number}[];
 recent:{uuid:string;flow:string;email:string;source:string;step:number;steps:number;status:string;stop_reason:string|null;next_at:string|null}[];
 campaigns:Record<string,{name:string;audience:number;sent:number;opened:number;clicked:number;human_opened:number|null}>;
};
const props=defineProps<{report:Report;timezone:string}>();
const money=(n:number,c:string)=>new Intl.NumberFormat('zh-CN',{style:'currency',currency:c}).format(n);
const flowName=(key:string)=>props.report.flows.find(f=>f.key===key)?.name??(key==='campaign'?'群发':key);
const percent=(n:number|null)=>n===null?'—':`${n}%`;
const revenue=computed(()=>props.report.revenue.length?props.report.revenue.map(r=>money(r.revenue,r.currency)).join(' / '):'0');
const cards=computed(()=>[
 {title:'归因收入',value:revenue.value,note:'30天·点击后5天成单'},
 {title:'归因订单',value:props.report.attributed_orders,note:'近30天'},
 {title:'已发送',value:props.report.sent,note:`近30天 · 流程 ${props.report.automation_sent} + 群发 ${props.report.campaign_sent}`},
 {title:'真人打开率',value:percent(props.report.human_open_rate),note:`原始 ${props.report.raw_open_rate}% · 真人过滤口径待核实`},
 {title:'点击率',value:percent(props.report.click_rate),note:'近30天 · 按已发送邮件去重'},
 {title:'联系人',value:props.report.contacts,note:`已订阅 ${props.report.subscribed}`},
 {title:'7天弃购',value:props.report.abandoned_7d,note:'已同步 · 尚未完成的结账'},
 {title:'到货等待',value:props.report.waiting,note:'当前等待通知'},
]);
const state=(s:string)=>({active:'进行中',completed:'完成',stopped:'取消',held:'待核对',waiting:'等待中'}[s]??s);
const time=(s:string|null)=>s?new Date(s).toLocaleString('zh-CN',{timeZone:props.timezone}):'—';
</script>
<template>
 <section class="overview-report">
  <div class="metrics"><article v-for="card in cards" :key="card.title"><span>{{card.title}}</span><strong>{{typeof card.value==='number'?card.value.toLocaleString():card.value}}</strong><small>{{card.note}}</small></article></div>
  <section class="report-card"><h3>归因收入按流程（近 30 天 · 最后点击归因）</h3><div class="revenue-lines"><p v-for="r in report.revenue_by_flow" :key="r.flow+r.currency">{{flowName(r.flow)}} <strong>{{money(r.revenue,r.currency)}}</strong>（{{r.orders}} 单）</p><p v-if="!report.revenue_by_flow.length" class="muted">近 30 天暂无归因订单</p></div></section>
  <section class="report-card"><h3>流程状态</h3><div class="scroll"><table><thead><tr><th>流程</th><th>状态</th><th>进行中</th><th>30天发送</th><th>真人打开 / 原始 / 点击</th><th>失败 / 压制</th></tr></thead><tbody><tr v-for="f in report.flows" :key="f.key"><td><strong>{{f.name}}</strong><small>{{f.steps}} 封序列</small></td><td><span class="state" :class="{on:f.enabled}">{{f.enabled?'已启用':'未启用'}}</span></td><td>{{f.active}}</td><td>{{f.sent}}</td><td>{{f.human_opened??'—'}} / {{f.opened}} / {{f.clicked}}</td><td>{{f.failed}} / {{f.suppressed}}</td></tr></tbody></table></div></section>
  <section class="report-card"><h3>群发概览（近 30 天）</h3><div v-if="Object.keys(report.campaigns).length" class="scroll"><table><thead><tr><th>名称</th><th>受众</th><th>发送 / 真人打开 / 点击</th></tr></thead><tbody><tr v-for="(c,id) in report.campaigns" :key="id"><td>{{c.name}}</td><td>{{c.audience}}</td><td>{{c.sent}} / {{c.human_opened??'—'}} / {{c.clicked}}</td></tr></tbody></table></div><p v-else class="muted">近 30 天暂无群发发送——到「群发」Tab 新建 Campaign。</p></section>
  <section class="report-card"><h3>最近流程活动</h3><div class="scroll"><table><thead><tr><th>流程</th><th>联系人</th><th>触发</th><th>进度</th><th>状态</th><th>下一步</th></tr></thead><tbody><tr v-for="r in report.recent" :key="r.uuid"><td>{{flowName(r.flow)}}</td><td>{{r.email}}</td><td>{{r.source}}</td><td>第 {{Math.min(r.step+1,r.steps)}} 步</td><td>{{state(r.status)}}<small v-if="r.stop_reason">{{r.stop_reason}}</small></td><td>{{time(r.next_at)}}</td></tr><tr v-if="!report.recent.length"><td colspan="6" class="muted">暂无流程活动</td></tr></tbody></table></div></section>
 </section>
</template>
<style scoped>
.overview-report{display:grid;gap:24px}.metrics{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px}.metrics article,.report-card{background:#fff;border:1px solid #e5eaf0;border-radius:16px;padding:24px;box-shadow:0 2px 5px #10203003}.metrics article{display:flex;flex-direction:column;gap:12px}.metrics span,.muted{color:#718096;font-size:13px}.metrics strong{font-size:28px;color:#172b3a;letter-spacing:-.5px}.metrics small{color:#8a98a7;font-size:12px;line-height:1.6}.report-card h3{font-size:16px;font-weight:700;margin-bottom:20px}.revenue-lines{display:flex;gap:28px;flex-wrap:wrap;font-size:14px}.scroll{overflow-x:auto}table{width:100%;border-collapse:collapse;text-align:left;font-size:13px}th{color:#718096;background:#f8fafc;font-weight:600;white-space:nowrap}th,td{padding:14px 16px;border-bottom:1px solid #edf1f5}td{max-width:290px;overflow-wrap:anywhere}td small{display:block;color:#8a98a7;margin-top:5px;font-size:12px}.state{padding:5px 8px;border-radius:6px;background:#f0f4f7;color:#718096}.state.on{background:#ecfdf5;color:#047857}@media(max-width:900px){.metrics{grid-template-columns:repeat(2,minmax(0,1fr))}.report-card{padding:18px}}@media(min-width:1500px){.metrics{grid-template-columns:repeat(8,minmax(0,1fr))}.metrics article{padding:18px}.metrics strong{font-size:26px}}
</style>
