import assert from 'node:assert/strict';
import test from 'node:test';

import {decodeIdTokenClaims, runtimeFromIdToken} from './runtime.mjs';

test('resolves the test app runtime from Shopify ID token claims', () => {
  const token = tokenFor({
    aud: '179bfe6a970b5daabc4f6344bcd2733b',
    dest: 'https://macfox-test-app.myshopify.com',
  });

  assert.deepEqual(runtimeFromIdToken(token), {
    appOrigin: 'https://testadmin.decomkt.com',
    clientId: '179bfe6a970b5daabc4f6344bcd2733b',
    environment: 'test',
    shopDomain: 'macfox-test-app.myshopify.com',
  });
});

test('supports an audience list while selecting only a known environment', () => {
  const token = tokenFor({
    aud: ['unknown-client', '030f66008921f9d200ac057ef03fa163'],
    dest: 'https://production-shop.myshopify.com',
  });

  assert.equal(runtimeFromIdToken(token).environment, 'production');
});

test('rejects unknown clients and invalid shop destinations', () => {
  assert.throws(
    () => runtimeFromIdToken(tokenFor({aud: 'unknown-client', dest: 'https://shop.myshopify.com'})),
    /无法识别当前学生优惠应用环境/,
  );
  assert.throws(
    () =>
      runtimeFromIdToken(
        tokenFor({
          aud: '179bfe6a970b5daabc4f6344bcd2733b',
          dest: 'https://attacker.example.com',
        }),
      ),
    /无法读取当前 Shopify 店铺/,
  );
});

test('rejects malformed ID tokens', () => {
  assert.throws(() => decodeIdTokenClaims('not-a-jwt'), /无法读取 Shopify 登录身份/);
});

function tokenFor(claims) {
  return `${segment({alg: 'HS256', typ: 'JWT'})}.${segment(claims)}.signature`;
}

function segment(value) {
  return Buffer.from(JSON.stringify(value)).toString('base64url');
}
