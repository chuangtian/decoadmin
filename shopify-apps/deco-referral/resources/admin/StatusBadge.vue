<script setup lang="ts">
import { computed } from 'vue';
const props = defineProps<{ status: string; label?: string }>();
const labels: Record<string, string> = { active:'已启用', paused:'已暂停', suspended:'已暂停', pending:'待确认', waitlisted:'候补', approved:'已通过', rejected:'已拒绝', draft:'草稿', available:'可结算', reserved:'已占用', settled:'已结算', paid:'已记录付款', cancelled:'已取消', failed:'失败', review:'需审核', open:'待审核', reviewing:'审核中', dismissed:'已排除', unattributed:'未归因', partially_refunded:'部分退款', refunded:'已全额退款', superseded:'归属已更正', earned:'获得奖励资格', issued:'已发放', redeemed:'已使用', expired:'已过期', revoked:'已撤销', revoke_pending:'撤销处理中' };
const tone = computed(() => ['active','approved','available','settled','paid','issued','redeemed'].includes(props.status) ? 'success' : ['failed','rejected'].includes(props.status) ? 'danger' : ['pending','waitlisted','draft','review','open','reviewing','reserved','revoke_pending'].includes(props.status) ? 'warning' : 'neutral');
</script>
<template><span class="affiliate-status" :data-tone="tone"><span aria-hidden="true" class="affiliate-status-dot" />{{ label ?? labels[status] ?? status }}</span></template>
