import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { once } from 'node:events';
import { mkdtemp, rm, writeFile } from 'node:fs/promises';
import { createServer } from 'node:http';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { createInterface } from 'node:readline';
import test from 'node:test';

const serverPath = fileURLToPath(new URL('./mcp-server.mjs', import.meta.url));

function startServer(env = {}) {
  const child = spawn(process.execPath, [serverPath], {
    stdio: ['pipe', 'pipe', 'pipe'],
    env: {
      ...process.env,
      DECOADMIN_BASE_URL: '',
      DECOADMIN_API_TOKEN: '',
      DECOADMIN_CONFIG_FILE: '/private/tmp/decoadmin-codex-missing-test-config.json',
      ...env,
    },
  });
  const responses = [];
  createInterface({ input: child.stdout }).on('line', (line) => responses.push(JSON.parse(line)));
  return { child, responses };
}

async function request(instance, payload) {
  instance.child.stdin.write(`${JSON.stringify(payload)}\n`);
  for (let attempt = 0; attempt < 100; attempt += 1) {
    const response = instance.responses.find((item) => item.id === payload.id);
    if (response) return response;
    await new Promise((resolve) => setTimeout(resolve, 10));
  }
  throw new Error('MCP response timeout');
}

async function stop(instance) {
  instance.child.stdin.end();
  await once(instance.child, 'exit');
}

test('initializes and advertises read and confirmed-write tools', async () => {
  const instance = startServer();
  const initialized = await request(instance, {
    jsonrpc: '2.0', id: 1, method: 'initialize', params: { protocolVersion: '2025-06-18' },
  });
  assert.equal(initialized.result.serverInfo.name, 'decoadmin');

  const listed = await request(instance, { jsonrpc: '2.0', id: 2, method: 'tools/list', params: {} });
  assert.equal(listed.result.tools.length, 12);
  assert.equal(listed.result.tools.filter((tool) => tool.annotations.readOnlyHint).length, 6);
  assert.equal(listed.result.tools.find((tool) => tool.name === 'decoadmin_execute_confirmed_action').annotations.destructiveHint, true);
  await stop(instance);
});

test('returns a Chinese configuration error without exposing token values', async () => {
  const instance = startServer({ DECOADMIN_API_TOKEN: 'secret-that-must-not-appear' });
  const response = await request(instance, {
    jsonrpc: '2.0', id: 3, method: 'tools/call',
    params: { name: 'decoadmin_list_stores', arguments: {} },
  });
  assert.equal(response.result.isError, true);
  assert.match(response.result.content[0].text, /尚未配置/);
  assert.doesNotMatch(JSON.stringify(response), /secret-that-must-not-appear/);
  await stop(instance);
});

test('sanitizes backend model names from missing resource errors', async () => {
  const directory = await mkdtemp(join(tmpdir(), 'decoadmin-codex-error-test-'));
  const configPath = join(directory, 'config.json');
  const api = createServer((_request, response) => {
    response.writeHead(404, { 'content-type': 'application/json' });
    response.end(JSON.stringify({ message: 'No query results for model [App\\Models\\Store] 999999' }));
  });
  api.listen(0, '127.0.0.1');
  await once(api, 'listening');
  const address = api.address();
  await writeFile(configPath, JSON.stringify({
    base_url: `http://127.0.0.1:${address.port}`,
    api_token: 'dca_test-token-for-sanitized-error',
  }), { mode: 0o600 });

  const instance = startServer({ DECOADMIN_CONFIG_FILE: configPath });
  const response = await request(instance, {
    jsonrpc: '2.0', id: 8, method: 'tools/call',
    params: { name: 'decoadmin_list_orders', arguments: { store_id: 999999 } },
  });
  assert.equal(response.result.isError, true);
  assert.match(response.result.content[0].text, /目标资源不存在或当前用户无权访问/);
  assert.doesNotMatch(JSON.stringify(response), /App\\\\Models\\\\Store/);

  await stop(instance);
  api.close();
  await once(api, 'close');
  await rm(directory, { recursive: true });
});

test('reads a secure local configuration and supports the two-step write protocol', async () => {
  const directory = await mkdtemp(join(tmpdir(), 'decoadmin-codex-test-'));
  const configPath = join(directory, 'config.json');
  const apiToken = 'dca_test-token-for-local-config';
  const api = createServer(async (requestMessage, response) => {
    assert.equal(requestMessage.headers.authorization, `Bearer ${apiToken}`);
    const requestUrl = new URL(requestMessage.url, 'http://127.0.0.1');
    const dashboard = requestUrl.pathname.endsWith('/dashboard');
    const preparing = requestUrl.pathname.endsWith('/actions/prepare/analytics-refresh');
    const executing = requestUrl.pathname.endsWith('/actions/confirmation-test/execute');
    if (dashboard) {
      assert.equal(requestUrl.searchParams.get('include_test'), '0');
      assert.equal(requestUrl.searchParams.get('include_cancelled'), '1');
    }
    let requestBody = {};
    if (requestMessage.method === 'POST') {
      let raw = '';
      for await (const chunk of requestMessage) raw += chunk;
      requestBody = JSON.parse(raw);
    }
    if (preparing) assert.equal(requestBody.idempotency_key, 'prepare-test-001');
    if (executing) assert.equal(requestBody.confirmation_text, '确认执行');
    response.writeHead(200, { 'content-type': 'application/json' });
    response.end(JSON.stringify({
      schema_version: 'decoadmin-codex-v1',
      data: preparing
        ? {
            confirmation: { id: 'confirmation-test', action: 'refresh_analytics', summary: '刷新经营数据', status: 'pending', expires_at: '2026-09-02T00:10:00Z' },
            required_confirmation_text: '确认执行',
          }
        : executing
          ? {
              confirmation: { id: 'confirmation-test', action: 'refresh_analytics', summary: '刷新经营数据', status: 'executed', executed_at: '2026-09-02T00:01:00Z' },
              result: { action: 'refresh_analytics', cache_version: 2 },
              idempotent_replay: false,
            }
          : dashboard
            ? { store: { name: 'Macfox Bike', currency: 'USD' }, summary: {}, operations: {}, analytics: { period: {}, summary: {} } }
            : { items: [], pagination: { page: 1, per_page: 25, total: 0, last_page: 1 } },
      meta: { generated_at: '2026-09-02T00:00:00Z' },
    }));
  });
  api.listen(0, '127.0.0.1');
  await once(api, 'listening');
  const address = api.address();
  await writeFile(configPath, JSON.stringify({
    base_url: `http://127.0.0.1:${address.port}`,
    api_token: apiToken,
  }), { mode: 0o600 });

  const instance = startServer({ DECOADMIN_CONFIG_FILE: configPath });
  const response = await request(instance, {
    jsonrpc: '2.0', id: 4, method: 'tools/call',
    params: { name: 'decoadmin_list_stores', arguments: {} },
  });
  assert.equal(response.result.isError, undefined);
  assert.match(response.result.content[0].text, /本地开发/);
  const dashboardResponse = await request(instance, {
    jsonrpc: '2.0', id: 5, method: 'tools/call',
    params: {
      name: 'decoadmin_get_dashboard',
      arguments: { store_id: 2, include_test: false, include_cancelled: true },
    },
  });
  assert.equal(dashboardResponse.result.isError, undefined);
  const preparedResponse = await request(instance, {
    jsonrpc: '2.0', id: 6, method: 'tools/call',
    params: {
      name: 'decoadmin_prepare_analytics_refresh',
      arguments: { store_id: 2, idempotency_key: 'prepare-test-001' },
    },
  });
  assert.equal(preparedResponse.result.isError, undefined);
  assert.match(preparedResponse.result.content[0].text, /本轮不得执行/);
  const executedResponse = await request(instance, {
    jsonrpc: '2.0', id: 7, method: 'tools/call',
    params: {
      name: 'decoadmin_execute_confirmed_action',
      arguments: { confirmation_id: 'confirmation-test', confirmation_text: '确认执行' },
    },
  });
  assert.equal(executedResponse.result.isError, undefined);
  assert.match(executedResponse.result.content[0].text, /已执行确认单/);

  await stop(instance);
  api.close();
  await once(api, 'close');
  await rm(directory, { recursive: true });
});
