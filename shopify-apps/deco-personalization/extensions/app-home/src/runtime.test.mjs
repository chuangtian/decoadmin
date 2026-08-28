import assert from 'node:assert/strict';
import test from 'node:test';

import {decodeIdTokenClaims, runtimeFromIdToken} from './runtime.mjs';

const TEST_CLIENT_ID = '1234567890abcdef1234567890abcdef';

test('resolves a dynamic shop and client id only to the fixed Test backend', () => {
  const token = tokenFor({
    aud: TEST_CLIENT_ID,
    dest: 'https://sample-test-store.myshopify.com',
  });

  assert.deepEqual(runtimeFromIdToken(token), {
    appOrigin: 'https://testadmin.decomkt.com',
    clientId: TEST_CLIENT_ID,
    environment: 'test',
    shopDomain: 'sample-test-store.myshopify.com',
  });
});

test('supports an audience list while retaining the first valid Shopify client id', () => {
  const token = tokenFor({
    aud: ['unknown-client', TEST_CLIENT_ID],
    dest: 'https://another-test-store.myshopify.com',
  });

  assert.equal(runtimeFromIdToken(token).clientId, TEST_CLIENT_ID);
});

test('rejects invalid clients, invalid shop destinations and malformed tokens', () => {
  assert.throws(
    () => runtimeFromIdToken(tokenFor({aud: 'unknown-client', dest: 'https://shop.myshopify.com'})),
    /无法识别当前个性化推荐测试应用/,
  );
  assert.throws(
    () => runtimeFromIdToken(tokenFor({aud: TEST_CLIENT_ID, dest: 'https://attacker.example.com'})),
    /无法读取当前 Shopify 店铺/,
  );
  assert.throws(() => decodeIdTokenClaims('not-a-jwt'), /无法读取 Shopify 登录身份/);
});

test('rejects the permanent denied production shop before any backend request', () => {
  assert.throws(
    () => runtimeFromIdToken(tokenFor({aud: TEST_CLIENT_ID, dest: 'https://macfoxebike.myshopify.com'})),
    /禁止用于个性化推荐应用操作/,
  );
});

function tokenFor(claims) {
  return `${segment({alg: 'HS256', typ: 'JWT'})}.${segment(claims)}.signature`;
}

function segment(value) {
  return Buffer.from(JSON.stringify(value)).toString('base64url');
}
