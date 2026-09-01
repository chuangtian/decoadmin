import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { once } from 'node:events';
import { mkdtemp, readFile, rm, stat } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';

const configurePath = fileURLToPath(new URL('./configure.mjs', import.meta.url));

async function configure(baseUrl, output, token = ['dca_', '00000000-0000-0000-0000-000000000000', '.', 'test-secret-with-enough-length'].join('')) {
  const child = spawn(process.execPath, [configurePath, '--base-url', baseUrl, '--output', output], {
    stdio: ['pipe', 'pipe', 'pipe'],
  });
  let stdout = '';
  let stderr = '';
  child.stdout.on('data', (chunk) => { stdout += chunk; });
  child.stderr.on('data', (chunk) => { stderr += chunk; });
  child.stdin.end(`${token}\n`);
  const [code] = await once(child, 'exit');
  return { code, stdout, stderr, token };
}

test('writes a protected test environment configuration without printing the token', async () => {
  const directory = await mkdtemp(join(tmpdir(), 'decoadmin-configure-test-'));
  const output = join(directory, 'config.json');
  const result = await configure('https://testadmin.decomkt.com', output);

  assert.equal(result.code, 0);
  assert.match(result.stdout, /测试服插件配置已保存/);
  assert.doesNotMatch(result.stdout, new RegExp(result.token));
  assert.doesNotMatch(result.stderr, new RegExp(result.token));
  assert.equal((await stat(output)).mode & 0o077, 0);
  assert.deepEqual(JSON.parse(await readFile(output, 'utf8')), {
    base_url: 'https://testadmin.decomkt.com',
    api_token: result.token,
  });

  await rm(directory, { recursive: true });
});

test('rejects unapproved remote hosts and does not print the token', async () => {
  const directory = await mkdtemp(join(tmpdir(), 'decoadmin-configure-reject-'));
  const result = await configure('https://example.com', join(directory, 'config.json'));

  assert.notEqual(result.code, 0);
  assert.match(result.stderr, /只允许配置本地、测试服或正式服/);
  assert.doesNotMatch(result.stderr, new RegExp(result.token));

  await rm(directory, { recursive: true });
});
