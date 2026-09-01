import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { once } from 'node:events';
import { readFile } from 'node:fs/promises';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { createInterface } from 'node:readline';
import test from 'node:test';

const pluginRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..');

test('plugin MCP config starts from the installed root without placeholder expansion', async () => {
  const raw = await readFile(resolve(pluginRoot, '.mcp.json'), 'utf8');
  assert.doesNotMatch(raw, /\$\{PLUGIN_ROOT\}/);
  const config = JSON.parse(raw).mcpServers.decoadmin;
  assert.equal(config.cwd, '.');
  assert.equal(config.args[0], './scripts/mcp-server.mjs');

  const child = spawn(config.command, config.args, {
    cwd: resolve(pluginRoot, config.cwd),
    stdio: ['pipe', 'pipe', 'pipe'],
    env: {
      ...process.env,
      DECOADMIN_BASE_URL: '',
      DECOADMIN_API_TOKEN: '',
      DECOADMIN_CONFIG_FILE: '/private/tmp/decoadmin-plugin-startup-missing.json',
    },
  });
  const responses = [];
  createInterface({ input: child.stdout }).on('line', (line) => responses.push(JSON.parse(line)));
  child.stdin.write(`${JSON.stringify({
    jsonrpc: '2.0', id: 1, method: 'initialize', params: { protocolVersion: '2025-06-18' },
  })}\n`);

  for (let attempt = 0; attempt < 100 && !responses.some((item) => item.id === 1); attempt += 1) {
    await new Promise((resolvePromise) => setTimeout(resolvePromise, 10));
  }
  const initialized = responses.find((item) => item.id === 1);
  assert.equal(initialized?.result?.serverInfo?.name, 'decoadmin');
  child.stdin.end();
  await once(child, 'exit');
});
