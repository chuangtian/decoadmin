<script setup lang="ts">
export type HealthData = {
 coupons?:{code:string;status:string;summary?:string;ends_at?:string|null}[];generated_at:string;timezone:string;yesterday:string;sent:number;failed:number;recipients:number;unsubscribed:number;
 failure_rate:number|null;unsubscribe_rate:number|null;cohort_matures_at:string;recent_sent:number;recent_raw_opened:number;recent_clicked:number;
 warmup:{enabled:boolean;week:number;current:number;target:number;goal:number;capacity:number;steps:number[]};human_open_rate:number|null;human_open_eligible:boolean;circuit_open:boolean;daily_used:number;scheduled:boolean;
 campaigns:{uuid:string;name:string;sent:number;unsubscribed:number;rate:number;eligible:boolean;exceeded:boolean}[];
};
defineProps<{health:HealthData;dailyLimit:number}>();
defineEmits<{refresh:[]}>();
const percent=(n:number|null)=>n===null?'暂无样本':`${n.toFixed(2)}%`;
</script>
<template>
 <div class="health-report">
  <div class="toolbar"><strong>每日巡检</strong><button @click="$emit('refresh')">刷新</button></div>
  <p>生成于 {{health.generated_at}} · 加州日历日</p>
  <section><h3>活动码校验</h3><p>校验 Shopify 当前状态；ACTIVE 不代表所有车型或购物车都适用。</p><p v-if="!health.coupons?.length">尚未配置巡检优惠码，请在流程设置中添加。</p><p v-for="coupon in health.coupons" :key="coupon.code"><b>{{coupon.code}}</b> · {{coupon.status}} · {{coupon.summary || '尚无可用详情'}} · 到期：{{coupon.ends_at || '未返回到期时间'}}</p></section><div class="cards">
   <article><h3>失败率</h3><strong>{{percent(health.failure_rate)}}</strong><p>阈值 &lt; 2.00% · 昨日失败 {{health.failed}} / 发送与失败 {{health.sent+health.failed}}</p><span>{{health.failure_rate===null?'暂无样本':health.failure_rate<2?'达标':'未达标'}}</span></article>
   <article><h3>真·退订率</h3><strong>{{percent(health.unsubscribe_rate)}}</strong><p>阈值 &lt; 0.50% · 昨日收件人 {{health.recipients}}，其中 {{health.unsubscribed}} 人在收信 72 小时内通过邮件链接退订。</p><span>{{health.unsubscribe_rate===null?'暂无样本':health.unsubscribe_rate<0.5?'达标':'未达标'}}</span></article>
   <article><h3>真人打开率</h3><strong>{{percent(health.human_open_rate)}}</strong><p>阈值 ≥ 22.00% · 近 3 天滚动发送 {{health.recent_sent}}</p><span>{{!health.human_open_eligible?'发送不足 50，跳过判定':health.human_open_rate===null?'暂无可分类记录':health.human_open_rate>=22?'达标（过滤后估计）':'未达标（过滤后估计）'}}</span></article>
  </div>
  <p>失败率、退订率取昨日（{{health.yesterday}}）；真人打开率取近 3 天滚动。退订仅认邮件内退订链接，不含 Shopify 同步回灌。72 小时观察窗口内数据仍会更新。</p>
  <div class="cards"><article><h3>昨日 · {{health.yesterday}}</h3><p>发送 {{health.sent}} · 失败 {{health.failed}} · 真·邮件退订 {{health.unsubscribed}}</p></article><article><h3>近 3 天滚动</h3><p>发送 {{health.recent_sent}} · 原始打开 {{health.recent_raw_opened}} · 点击 {{health.recent_clicked}}</p></article></div>
  <section><h3>发送中群发 · 单封退订率</h3><p>红线 0.50% · 满 500 封才判定 · 超线自动只暂停该群发</p><table><thead><tr><th>群发</th><th>已发送</th><th>真·邮件退订</th><th>退订率</th><th>判定</th></tr></thead><tbody><tr v-for="c in health.campaigns" :key="c.uuid"><td>{{c.name}}</td><td>{{c.sent}}</td><td>{{c.unsubscribed}}</td><td>{{percent(c.rate)}}</td><td>{{!c.eligible?'样本不足':c.exceeded?'超线':'达标'}}</td></tr><tr v-if="!health.campaigns.length"><td colspan="5">当前没有发送中的群发</td></tr></tbody></table><p>分母为该群发已发送数；分子为收过该群发、且在发送后通过邮件链接退订的联系人。按整个生命周期累计，恢复后仍超线会再次暂停。</p></section>
  <section><h3>域名预热档位</h3><p>当前日上限 {{health.warmup.current}} · 本周应到 {{health.warmup.target}} · 阶梯目标 {{health.warmup.goal}} · 通道能力顶 {{health.warmup.capacity}}</p><p>第 {{health.warmup.week}} 周 · 自动升档{{health.warmup.enabled?'已启用':'未启用'}} · 阶梯 {{health.warmup.steps.join(' → ')}}</p></section><p>真人打开为保守过滤后的估计：排除已识别机器人、图片代理与预取。无法判断的打开只计入原始打开，不代表没有阅读。</p><div class="cards"><article><h3>熔断</h3><strong>{{health.circuit_open?'已暂停新发送':'CLOSED·正常'}}</strong><p>近 60 分钟失败或结果不确定至少 10 次，且比例达到 50%，暂停新发送。结果不确定记录另行保留核对。</p></article><article><h3>当前日发送上限</h3><strong>{{dailyLimit.toLocaleString()}}</strong><p>今日已占用 {{health.daily_used}} · 定时执行{{health.scheduled?'已启用':'未启用'}}</p></article></div>
 </div>
</template>
<style scoped>
.health-report{color:#1e293b}.toolbar{display:flex;justify-content:space-between;align-items:center}.toolbar button{border:1px solid #dce5ed;border-radius:10px;padding:10px 20px;background:white;color:#047857}.cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:20px;margin:24px 0}article,section{background:white;border:1px solid #e2e8f0;border-radius:16px;padding:24px}h3{font-size:17px;margin:0 0 16px}article strong{font-size:28px}p{font-size:14px;line-height:1.7;color:#64748b}span{font-size:14px;color:#047857}table{width:100%;border-collapse:collapse}td,th{text-align:left;border-bottom:1px solid #e2e8f0;padding:14px 8px}section{overflow:auto}
</style>
