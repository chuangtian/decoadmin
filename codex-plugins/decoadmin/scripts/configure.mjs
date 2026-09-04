#!/usr/bin/env node

import { chmod, mkdir, writeFile } from 'node:fs/promises';
import { homedir } from 'node:os';
import { dirname, join } from 'node:path';

function argument(name, fallback = '') {
  const index = process.argv.indexOf(name);
  return index >= 0 && process.argv[index + 1] ? process.argv[index + 1] : fallback;
}

async function stdin() {
  let value = '';
  process.stdin.setEncoding('utf8');
  for await (const chunk of process.stdin) value += chunk;
  return value.trim();
}

function environment(parsed) {
  if (['127.0.0.1', 'localhost'].includes(parsed.hostname) && parsed.protocol === 'http:') return '本地开发';
  if (parsed.hostname === 'testadmin.decomkt.com' && parsed.protocol === 'https:') return '测试服';
  if (parsed.hostname === 'admin.decomkt.com' && parsed.protocol === 'https:') return '正式服';
  throw new Error('只允许配置本地、测试服或正式服的明确 DecoAdmin 地址。');
}

const baseUrl = argument('--base-url', 'http://127.0.0.1:8000').replace(/\/+$/, '');
const output = argument('--output', join(homedir(), '.agents', 'plugins', 'decoadmin.local.json'));
const token = await stdin();

let parsed;
try {
  parsed = new URL(baseUrl);
} catch {
  throw new Error('DecoAdmin 地址不是有效网址。');
}
const label = environment(parsed);
if (!token.startsWith('dca_') || !token.includes('.')) {
  throw new Error('没有收到有效的 DecoAdmin Codex 令牌。');
}

await mkdir(dirname(output), { recursive: true, mode: 0o700 });
await writeFile(output, `${JSON.stringify({ base_url: baseUrl, api_token: token }, null, 2)}\n`, { mode: 0o600 });
await chmod(output, 0o600);
process.stdout.write(`${label}插件配置已保存：${output}（令牌未显示）\n`);
