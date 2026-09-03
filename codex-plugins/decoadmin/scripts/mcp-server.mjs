#!/usr/bin/env node

import { readFileSync, statSync } from 'node:fs';
import { randomUUID } from 'node:crypto';
import { homedir } from 'node:os';
import { join } from 'node:path';

const VERSION = '0.2.0';

const tools = [
  {
    name: 'decoadmin_list_stores',
    description: '列出当前令牌用户在已配置 DecoAdmin 环境中有权查看的店铺。',
    inputSchema: {
      type: 'object', additionalProperties: false,
      properties: {
        search: { type: 'string', maxLength: 100 },
        page: { type: 'integer', minimum: 1, default: 1 },
        per_page: { type: 'integer', minimum: 1, maximum: 50, default: 25 },
      },
    },
  },
  {
    name: 'decoadmin_get_dashboard',
    description: '查询一个店铺的经营概览、统计周期、趋势和运行摘要。',
    inputSchema: {
      type: 'object', additionalProperties: false, required: ['store_id'],
      properties: {
        store_id: { type: 'integer', minimum: 1, description: 'decoadmin_list_stores 返回的店铺 ID' },
        days: { type: 'integer', minimum: 1, maximum: 366, default: 30 },
        include_test: { type: 'boolean', default: false },
        include_cancelled: { type: 'boolean', default: true },
        comparison: { type: 'string', enum: ['none', 'previous', 'year', 'year_weekday'], default: 'previous' },
      },
    },
  },
  {
    name: 'decoadmin_list_orders',
    description: '分页查询一个店铺的脱敏订单信息，不返回顾客邮箱、电话或地址。',
    inputSchema: {
      type: 'object', additionalProperties: false, required: ['store_id'],
      properties: {
        store_id: { type: 'integer', minimum: 1 },
        search: { type: 'string', maxLength: 100 },
        financial_status: { type: 'string', maxLength: 40 },
        fulfillment_status: { type: 'string', enum: ['fulfilled', 'partial', 'restocked', 'unfulfilled'] },
        page: { type: 'integer', minimum: 1, default: 1 },
      },
    },
  },
  {
    name: 'decoadmin_get_operations',
    description: '查询一个店铺的同步、Webhook 和近期运行状态。',
    inputSchema: {
      type: 'object', additionalProperties: false, required: ['store_id'],
      properties: { store_id: { type: 'integer', minimum: 1 } },
    },
  },
  {
    name: 'decoadmin_get_configuration_status',
    description: '检查系统及店铺配置是否完整，仅返回布尔状态和缺失字段，不返回配置值或密钥。',
    inputSchema: {
      type: 'object', additionalProperties: false, required: ['store_id'],
      properties: { store_id: { type: 'integer', minimum: 1 } },
    },
  },
  {
    name: 'decoadmin_get_system_status',
    description: '查询当前组织可见的 DecoAdmin 服务、队列、调度器和近期事故摘要。',
    inputSchema: { type: 'object', additionalProperties: false, properties: {} },
  },
  {
    name: 'decoadmin_prepare_analytics_refresh',
    description: '生成刷新经营分析数据的确认单，不立即执行。必须向用户展示摘要并等待后续明确回复“确认执行”。',
    inputSchema: {
      type: 'object', additionalProperties: false, required: ['store_id'],
      properties: {
        store_id: { type: 'integer', minimum: 1 },
        idempotency_key: { type: 'string', minLength: 8, maxLength: 120 },
      },
    },
    annotations: { readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false },
  },
  {
    name: 'decoadmin_prepare_sync',
    description: '生成发起 Shopify 数据同步的确认单，不立即执行。必须等待用户后续明确回复“确认执行”。',
    inputSchema: {
      type: 'object', additionalProperties: false, required: ['store_id', 'type', 'mode'],
      properties: {
        store_id: { type: 'integer', minimum: 1 },
        type: { type: 'string', enum: ['products', 'orders', 'customers', 'inventory'] },
        mode: { type: 'string', enum: ['full', 'incremental'] },
        app_installation_id: { type: 'integer', minimum: 1 },
        idempotency_key: { type: 'string', minLength: 8, maxLength: 120 },
      },
    },
    annotations: { readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: true },
  },
  {
    name: 'decoadmin_prepare_sync_retry',
    description: '为一个失败同步任务生成重试确认单，不立即执行。必须等待用户后续明确回复“确认执行”。',
    inputSchema: {
      type: 'object', additionalProperties: false, required: ['store_id', 'sync_job_id'],
      properties: {
        store_id: { type: 'integer', minimum: 1 },
        sync_job_id: { type: 'integer', minimum: 1 },
        idempotency_key: { type: 'string', minLength: 8, maxLength: 120 },
      },
    },
    annotations: { readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: true },
  },
  {
    name: 'decoadmin_prepare_notification_update',
    description: '生成店铺通知开关修改确认单。只支持非密钥布尔设置，不能写入密码、Token 或 Webhook。',
    inputSchema: {
      type: 'object', additionalProperties: false, required: ['store_id'],
      properties: {
        store_id: { type: 'integer', minimum: 1 },
        mail_enabled: { type: 'boolean' },
        feishu_enabled: { type: 'boolean' },
        notify_sync_failed: { type: 'boolean' },
        notify_webhook_failed: { type: 'boolean' },
        notify_connection_unhealthy: { type: 'boolean' },
        idempotency_key: { type: 'string', minLength: 8, maxLength: 120 },
      },
    },
    annotations: { readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false },
  },
  {
    name: 'decoadmin_prepare_student_discount_status',
    description: '生成开启或关闭学生优惠活动的确认单，不立即执行。必须等待用户后续明确回复“确认执行”。',
    inputSchema: {
      type: 'object', additionalProperties: false, required: ['store_id', 'enabled'],
      properties: {
        store_id: { type: 'integer', minimum: 1 },
        enabled: { type: 'boolean' },
        idempotency_key: { type: 'string', minLength: 8, maxLength: 120 },
      },
    },
    annotations: { readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: true },
  },
  {
    name: 'decoadmin_execute_confirmed_action',
    description: '执行已生成的确认单。仅当用户在生成确认单后的新消息中明确回复“确认执行”时调用，绝不能在生成确认单的同一轮调用。',
    inputSchema: {
      type: 'object', additionalProperties: false, required: ['confirmation_id', 'confirmation_text'],
      properties: {
        confirmation_id: { type: 'string', format: 'uuid' },
        confirmation_text: { type: 'string', enum: ['确认执行'] },
      },
    },
    annotations: { readOnlyHint: false, destructiveHint: true, idempotentHint: true, openWorldHint: true },
  },
];

for (const tool of tools.slice(0, 6)) {
  tool.annotations = { readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false };
}

function environmentLabel(hostname) {
  if (hostname === 'admin.decomkt.com') return '正式服';
  if (hostname === 'testadmin.decomkt.com') return '测试服';
  if (hostname === 'localhost' || hostname === '127.0.0.1' || hostname.endsWith('.trycloudflare.com')) return '本地开发';
  return hostname;
}

function configuration() {
  const environmentBaseUrl = String(process.env.DECOADMIN_BASE_URL || '').trim();
  const environmentToken = String(process.env.DECOADMIN_API_TOKEN || '').trim();
  const fileConfiguration = environmentBaseUrl || environmentToken ? {} : configurationFile();
  const baseUrl = String(environmentBaseUrl || fileConfiguration.base_url || '').trim().replace(/\/+$/, '');
  const token = String(environmentToken || fileConfiguration.api_token || '').trim();
  if (!baseUrl || !token) throw new Error('插件尚未配置 DECOADMIN_BASE_URL 和 DECOADMIN_API_TOKEN。');

  let parsed;
  try {
    parsed = new URL(baseUrl);
  } catch {
    throw new Error('DECOADMIN_BASE_URL 不是有效网址。');
  }
  const localHttp = parsed.protocol === 'http:' && ['localhost', '127.0.0.1'].includes(parsed.hostname);
  if (parsed.protocol !== 'https:' && !localHttp) throw new Error('DecoAdmin 地址必须使用 HTTPS；仅 localhost 可使用 HTTP。');

  return { baseUrl, token, environment: environmentLabel(parsed.hostname) };
}

function configurationFile() {
  const configPath = String(process.env.DECOADMIN_CONFIG_FILE || join(homedir(), '.agents', 'plugins', 'decoadmin.local.json'));
  try {
    const stat = statSync(configPath);
    if ((stat.mode & 0o077) !== 0) throw new Error('配置文件权限过宽，请设置为 600。');
    const parsed = JSON.parse(readFileSync(configPath, 'utf8'));
    return parsed && typeof parsed === 'object' ? parsed : {};
  } catch (error) {
    if (error?.code === 'ENOENT') return {};
    if (error instanceof SyntaxError) throw new Error('DecoAdmin 本地插件配置文件不是有效 JSON。');
    throw error;
  }
}

function storeId(value) {
  if (!Number.isInteger(value) || value < 1) throw new Error('store_id 必须是大于 0 的整数。');
  return value;
}

function queryString(values) {
  const query = new URLSearchParams();
  for (const [key, value] of Object.entries(values)) {
    if (key !== 'store_id' && value !== undefined && value !== null && value !== '') {
      query.set(key, typeof value === 'boolean' ? (value ? '1' : '0') : String(value));
    }
  }
  return query.size ? `?${query}` : '';
}

async function apiRequest(method, path, { query = {}, body } = {}) {
  const { baseUrl, token, environment } = configuration();
  const controller = new AbortController();
  const timeout = setTimeout(() => controller.abort(), 30_000);
  try {
    const response = await fetch(`${baseUrl}${path}${queryString(query)}`, {
      method, redirect: 'error', signal: controller.signal,
      headers: {
        Accept: 'application/json',
        Authorization: `Bearer ${token}`,
        ...(body === undefined ? {} : { 'Content-Type': 'application/json' }),
        'User-Agent': `DecoAdmin-Codex-Plugin/${VERSION}`,
      },
      ...(body === undefined ? {} : { body: JSON.stringify(body) }),
    });
    const payload = await response.json().catch(() => null);
    if (!response.ok) {
      const stableMessage = {
        401: '插件授权已失效，请重新授权。',
        403: '当前用户权限不足。',
        404: '目标资源不存在或当前用户无权访问。',
        429: '请求过于频繁，请稍后重试。',
      }[response.status];
      const message = stableMessage
        || (response.status >= 500 ? '后台暂时不可用，请稍后重试。' : payload?.error?.message || payload?.message || `后台返回 HTTP ${response.status}`);
      throw new Error(`${environment}请求失败：${message}`);
    }
    if (!payload || payload.schema_version !== 'decoadmin-codex-v1') throw new Error(`${environment}返回了无法识别的数据格式。`);
    return { ...payload, environment };
  } catch (error) {
    if (error?.name === 'AbortError') throw new Error(`${environment}查询超时，请检查后台网络和运行状态。`);
    throw error;
  } finally {
    clearTimeout(timeout);
  }
}

async function apiGet(path, query = {}) {
  return apiRequest('GET', path, { query });
}

async function apiPost(path, body) {
  return apiRequest('POST', path, { body });
}

function result(payload, text) {
  return {
    content: [{ type: 'text', text: `环境：${payload.environment}\n${text}` }],
    structuredContent: { data: payload.data, meta: { ...payload.meta, environment: payload.environment } },
  };
}

function currency(value, code = '') {
  const amount = Number(value || 0);
  const formatted = Number.isFinite(amount) ? amount.toLocaleString('zh-CN', { maximumFractionDigits: 2 }) : '0';
  return `${code ? `${code} ` : ''}${formatted}`;
}

function summarizeStores(payload) {
  const stores = Array.isArray(payload.data?.items) ? payload.data.items : [];
  if (!stores.length) return '当前账号没有可查看的活跃店铺。';
  const lines = stores.map((store) => `- #${store.id} ${store.name}：Shopify ${store.connection_status}，最近同步 ${store.last_sync?.status || '暂无'}`);
  return `共 ${payload.data?.pagination?.total ?? stores.length} 家店铺，当前页显示 ${stores.length} 家：\n${lines.join('\n')}`;
}

function summarizeDashboard(payload) {
  const data = payload.data || {};
  const store = data.store || {};
  const summary = data.analytics?.summary || data.summary || {};
  const operations = data.operations || {};
  return [
    `${store.name || '当前店铺'}经营概览：`,
    `- 统计周期：${data.analytics?.period?.from || '-'} 至 ${data.analytics?.period?.to || '-'}`,
    `- 净销售额：${currency(summary.net_sales ?? summary.sales, store.currency)}`,
    `- 订单数：${Number(summary.orders || 0).toLocaleString('zh-CN')}`,
    `- 平均订单金额：${currency(summary.average_order_value, store.currency)}`,
    `- 开放告警：${operations.open_alerts || 0}；24 小时失败同步：${operations.failed_sync_jobs_24h || 0}；失败 Webhook：${operations.failed_webhooks_24h || 0}`,
  ].join('\n');
}

function summarizeOrders(payload) {
  const data = payload.data || {};
  const page = data.pagination || {};
  const lines = (data.items || []).slice(0, 10).map((order) =>
    `- ${order.order_number || `#${order.id}`}：${currency(order.total_price, order.currency)}，支付 ${order.financial_status || '未知'}，履约 ${order.fulfillment_status || '未履约'}`);
  return [`共 ${page.total || 0} 笔订单，当前第 ${page.page || 1}/${page.last_page || 1} 页。`, ...lines].join('\n');
}

function summarizeOperations(payload) {
  const data = payload.data || {};
  const sync = data.sync?.summary || {};
  const webhooks = data.webhooks?.summary || {};
  const logs = data.logs?.summary || {};
  return [
    '店铺运行摘要：',
    `- 同步任务：总计 ${sync.total || 0}，运行中 ${sync.running || 0}，失败 ${sync.failed || 0}`,
    `- Webhook：总计 ${webhooks.total || 0}，处理中 ${webhooks.active || 0}，失败 ${webhooks.failed || 0}`,
    `- 近期记录：操作 ${logs.operations || 0}，集成 ${logs.integrations || 0}，异常 ${logs.exceptions || 0}`,
  ].join('\n');
}

function summarizeConfiguration(payload) {
  const data = payload.data || {};
  const missing = [];
  for (const [scope, sections] of Object.entries({ 系统: data.system || {}, 店铺通知: data.store_notifications || {} })) {
    for (const [section, status] of Object.entries(sections)) {
      if (!status?.configured) missing.push(`${scope}.${section}：${(status?.missing || []).join('、') || '未完成'}`);
    }
  }
  if (!data.shopify?.connected) missing.push(`Shopify 连接：${data.shopify?.status || 'disconnected'}`);
  if (!data.student_discount?.configured) missing.push('学生优惠：尚未配置');
  return missing.length ? `发现 ${missing.length} 项未完成：\n- ${missing.join('\n- ')}` : '已检查的系统与店铺配置均完整。';
}

function summarizeSystem(payload) {
  const data = payload.data || {};
  const services = (data.services || []).filter((item) => item.status !== 'healthy');
  const queues = (data.queues || []).filter((item) => item.status !== 'healthy');
  return [
    `系统整体状态：${data.summary?.status || 'unknown'}（${data.summary?.checked_at || '未知时间'}）`,
    `- 服务异常/待确认：${services.length ? services.map((item) => `${item.name}:${item.status}`).join('，') : '无'}`,
    `- 队列异常/待确认：${queues.length ? queues.map((item) => `${item.label}:${item.status}`).join('，') : '无'}`,
    `- 近 24 小时事故：${(data.incidents || []).map((item) => `${item.label} ${item.count ?? '未知'}`).join('；') || '无'}`,
  ].join('\n');
}

function idempotencyKey(args) {
  return args.idempotency_key || randomUUID();
}

function summarizePrepared(payload) {
  const confirmation = payload.data?.confirmation || {};
  if (confirmation.status === 'executed') {
    return `该操作已经执行。确认单：${confirmation.id}`;
  }
  return [
    `待确认操作：${confirmation.summary || '后台写操作'}`,
    `确认单：${confirmation.id || '未知'}`,
    `有效期至：${confirmation.expires_at || '未知'}`,
    '请向用户展示以上内容并停止。本轮不得执行；只有用户在后续消息中明确回复“确认执行”后才能继续。',
  ].join('\n');
}

function summarizeExecuted(payload) {
  const confirmation = payload.data?.confirmation || {};
  const replayed = payload.data?.idempotent_replay === true;
  return [
    replayed ? '该确认单此前已经执行，本次返回原结果，没有重复写入。' : '已执行确认单。',
    `操作：${confirmation.summary || confirmation.action || '后台写操作'}`,
    `确认单：${confirmation.id || '未知'}`,
    `执行时间：${confirmation.executed_at || '未知'}`,
  ].join('\n');
}

async function callTool(name, args = {}) {
  let payload;
  switch (name) {
    case 'decoadmin_list_stores':
      payload = await apiGet('/api/codex/v1/stores', args);
      return result(payload, summarizeStores(payload));
    case 'decoadmin_get_dashboard':
      payload = await apiGet(`/api/codex/v1/stores/${storeId(args.store_id)}/dashboard`, {
        days: args.days ?? 30, include_test: args.include_test ?? false,
        include_cancelled: args.include_cancelled ?? true, comparison: args.comparison ?? 'previous',
      });
      return result(payload, summarizeDashboard(payload));
    case 'decoadmin_list_orders':
      payload = await apiGet(`/api/codex/v1/stores/${storeId(args.store_id)}/orders`, args);
      return result(payload, summarizeOrders(payload));
    case 'decoadmin_get_operations':
      payload = await apiGet(`/api/codex/v1/stores/${storeId(args.store_id)}/operations`);
      return result(payload, summarizeOperations(payload));
    case 'decoadmin_get_configuration_status':
      payload = await apiGet(`/api/codex/v1/stores/${storeId(args.store_id)}/configuration-status`);
      return result(payload, summarizeConfiguration(payload));
    case 'decoadmin_get_system_status':
      payload = await apiGet('/api/codex/v1/system/status');
      return result(payload, summarizeSystem(payload));
    case 'decoadmin_prepare_analytics_refresh':
      payload = await apiPost(`/api/codex/v1/stores/${storeId(args.store_id)}/actions/prepare/analytics-refresh`, {
        idempotency_key: idempotencyKey(args),
      });
      return result(payload, summarizePrepared(payload));
    case 'decoadmin_prepare_sync':
      payload = await apiPost(`/api/codex/v1/stores/${storeId(args.store_id)}/actions/prepare/sync`, {
        type: args.type,
        mode: args.mode,
        ...(args.app_installation_id ? { app_installation_id: args.app_installation_id } : {}),
        idempotency_key: idempotencyKey(args),
      });
      return result(payload, summarizePrepared(payload));
    case 'decoadmin_prepare_sync_retry':
      payload = await apiPost(`/api/codex/v1/stores/${storeId(args.store_id)}/actions/prepare/sync-retry`, {
        sync_job_id: args.sync_job_id,
        idempotency_key: idempotencyKey(args),
      });
      return result(payload, summarizePrepared(payload));
    case 'decoadmin_prepare_notification_update': {
      const body = { idempotency_key: idempotencyKey(args) };
      for (const key of ['mail_enabled', 'feishu_enabled', 'notify_sync_failed', 'notify_webhook_failed', 'notify_connection_unhealthy']) {
        if (Object.hasOwn(args, key)) body[key] = args[key];
      }
      payload = await apiPost(`/api/codex/v1/stores/${storeId(args.store_id)}/actions/prepare/store-notifications`, body);
      return result(payload, summarizePrepared(payload));
    }
    case 'decoadmin_prepare_student_discount_status':
      payload = await apiPost(`/api/codex/v1/stores/${storeId(args.store_id)}/actions/prepare/student-discount-status`, {
        enabled: args.enabled,
        idempotency_key: idempotencyKey(args),
      });
      return result(payload, summarizePrepared(payload));
    case 'decoadmin_execute_confirmed_action':
      payload = await apiPost(`/api/codex/v1/actions/${args.confirmation_id}/execute`, {
        confirmation_text: args.confirmation_text,
      });
      return result(payload, summarizeExecuted(payload));
    default:
      throw new Error(`未知工具：${name}`);
  }
}

function send(id, value, error) {
  const message = error
    ? { jsonrpc: '2.0', id, error: { code: -32603, message: error.message || '工具执行失败。' } }
    : { jsonrpc: '2.0', id, result: value };
  process.stdout.write(`${JSON.stringify(message)}\n`);
}

async function handle(message) {
  if (message?.jsonrpc !== '2.0' || typeof message.method !== 'string') {
    if (message?.id !== undefined) send(message.id, null, new Error('无效的 MCP 请求。'));
    return;
  }
  if (message.id === undefined) return;

  try {
    if (message.method === 'initialize') {
      send(message.id, {
        protocolVersion: message.params?.protocolVersion || '2025-06-18',
        capabilities: { tools: { listChanged: false } },
        serverInfo: { name: 'decoadmin', version: VERSION },
      });
    } else if (message.method === 'ping') {
      send(message.id, {});
    } else if (message.method === 'tools/list') {
      send(message.id, { tools });
    } else if (message.method === 'tools/call') {
      send(message.id, await callTool(message.params?.name, message.params?.arguments || {}));
    } else {
      send(message.id, null, new Error(`不支持的方法：${message.method}`));
    }
  } catch (error) {
    if (message.method === 'tools/call') {
      send(message.id, { content: [{ type: 'text', text: error.message || '工具执行失败。' }], isError: true });
    } else {
      send(message.id, null, error);
    }
  }
}

let buffer = '';
process.stdin.setEncoding('utf8');
process.stdin.on('data', (chunk) => {
  buffer += chunk;
  const lines = buffer.split(/\r?\n/);
  buffer = lines.pop() || '';
  for (const line of lines) {
    if (!line.trim()) continue;
    try {
      void handle(JSON.parse(line));
    } catch {
      send(null, null, new Error('收到无法解析的 MCP 消息。'));
    }
  }
});
