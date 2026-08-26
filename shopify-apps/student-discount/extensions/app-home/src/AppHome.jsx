import {render} from 'preact';
import {useEffect, useState} from 'preact/hooks';

import {runtimeFromIdToken} from './runtime.mjs';

const CONNECTION_PATH = '/api/shopify-app/student-discounts/connection';
const BOOTSTRAP_PATH = '/api/shopify-app/student-discounts/bootstrap';
const MANAGEMENT_PATH = '/shopify-app/student-discounts';
const THEME_BLOCK_HANDLE = 'student_discount';

/**
 * @typedef {{
 *   status: 'checking' | 'connected' | 'error',
 *   message?: string,
 *   storeName?: string,
 *   environment?: string,
 * }} ConnectionState
 */

export default async () => {
  render(<App />, document.body);
};

function App() {
  const [runtime, setRuntime] = useState({
    appOrigin: '',
    clientId: '',
    environment: '',
    shopDomain: '',
  });
  const [connection, setConnection] = useState({
    status: 'checking',
    message: '',
    storeName: '',
    environment: '',
  });

  useEffect(() => {
    const controller = new AbortController();
    let cancelled = false;

    resolveRuntime()
      .then(async (resolvedRuntime) => {
        if (cancelled) return;
        setRuntime(resolvedRuntime);

        const url = buildUrl(resolvedRuntime.appOrigin, CONNECTION_PATH, {
          shop: resolvedRuntime.shopDomain,
        });
        const connectionPayload = await authenticatedRequestJson(url, {
          signal: controller.signal,
        });
        if (connectionPayload.connected !== true) {
          throw new Error('该 Shopify 店铺尚未连接 DecoAdmin。');
        }

        const bootstrapUrl = buildUrl(resolvedRuntime.appOrigin, BOOTSTRAP_PATH, {
          shop: resolvedRuntime.shopDomain,
        });
        const payload = await authenticatedRequestJson(bootstrapUrl, {
          method: 'POST',
          signal: controller.signal,
        });

        if (cancelled) return;
        setConnection({
          status: 'connected',
          message: '',
          storeName: String(payload.store?.name || '').trim(),
          environment: String(payload.environment || '').trim(),
        });
      })
      .catch((error) => {
        if (cancelled || error?.name === 'AbortError') return;
        setConnection({
          status: 'error',
          message: publicMessage(error instanceof Error ? error.message : ''),
          storeName: '',
          environment: '',
        });
      });

    return () => {
      cancelled = true;
      controller.abort();
    };
  }, []);

  const {appOrigin, clientId, shopDomain} = runtime;

  const managementUrl = appOrigin
    ? buildUrl(appOrigin, MANAGEMENT_PATH, {shop: shopDomain, source: 'shopify'})
    : '';
  const themeEditorUrl = buildThemeEditorUrl(shopDomain, clientId);

  return (
    <s-page heading="Deco 学生优惠" inlineSize="base">
      <s-section heading="连接状态">
        <s-stack direction="block" gap="base">
          <s-stack direction="inline" gap="base" alignItems="center">
            <ConnectionBadge status={connection.status} />
            <s-text type="strong">{shopDomain || '未知店铺'}</s-text>
          </s-stack>

          {connection.status === 'checking' && (
            <s-paragraph color="subdued">正在检查 DecoAdmin 连接…</s-paragraph>
          )}

          {connection.status === 'connected' && (
            <s-paragraph color="subdued">
              {connection.storeName ? `${connection.storeName} 已连接。` : '店铺已连接。'}
              {connection.environment ? ` 当前环境：${connection.environment}。` : ''}
            </s-paragraph>
          )}

          {connection.status === 'error' && (
            <s-banner heading="暂时无法确认连接" tone="warning">
              {connection.message}
            </s-banner>
          )}
        </s-stack>
      </s-section>

      <s-section heading="管理学生优惠">
        <s-stack direction="block" gap="base">
          <s-paragraph color="subdued">
            活动设置、教育邮箱规则和学生证审核均在 DecoAdmin 中完成。打开后需要单独登录，并会自动定位到当前店铺。
          </s-paragraph>
          <s-button
            variant="primary"
            href={managementUrl || undefined}
            target="_top"
            disabled={!managementUrl}
          >
            前往 DecoAdmin 管理
          </s-button>
        </s-stack>
      </s-section>

      <s-section heading="添加到在线商店">
        <s-stack direction="block" gap="base">
          <s-ordered-list>
            <s-list-item>打开当前主题编辑器。</s-list-item>
            <s-list-item>确认添加 Student discount 应用区块。</s-list-item>
            <s-list-item>选择显示内容、位置、宽度和颜色后保存主题。</s-list-item>
          </s-ordered-list>
          <s-button
            variant="secondary"
            href={themeEditorUrl || undefined}
            target="_top"
            disabled={!themeEditorUrl}
          >
            添加到主题
          </s-button>
        </s-stack>
      </s-section>
    </s-page>
  );
}

function ConnectionBadge({status}) {
  if (status === 'connected') {
    return <s-badge tone="success">已连接</s-badge>;
  }

  if (status === 'error') {
    return <s-badge tone="warning">待确认</s-badge>;
  }

  return <s-badge tone="info">检查中</s-badge>;
}

function buildUrl(origin, path, params) {
  if (!origin) return '';
  const url = new URL(path, `${origin}/`);

  Object.entries(params).forEach(([key, value]) => {
    if (value) url.searchParams.set(key, String(value));
  });

  return url.toString();
}

function buildThemeEditorUrl(shopDomain, clientId) {
  if (!shopDomain || !clientId) return '';

  const url = new URL(`https://${shopDomain}/admin/themes/current/editor`);
  url.searchParams.set('template', 'index');
  url.searchParams.set('addAppBlockId', `${clientId}/${THEME_BLOCK_HANDLE}`);
  url.searchParams.set('target', 'newAppsSection');
  return url.toString();
}

function publicMessage(message) {
  const value = String(message || '').trim();
  if (!value || value.length > 240) {
    return '请稍后重试；如果问题持续，请联系 DecoAdmin 管理员。';
  }
  return value;
}

/**
 * @param {string} url
 * @param {RequestInit} [options]
 */
async function requestJson(url, options = {}) {
  const optionHeaders = Reflect.get(options, 'headers');
  const response = await fetch(url, {
    ...options,
    headers: {
      Accept: 'application/json',
      ...(optionHeaders || {}),
    },
  });
  const payload = await response.json().catch(() => ({}));

  if (!response.ok) {
    throw new Error(publicMessage(payload.error?.message));
  }

  return payload.data || {};
}

async function resolveRuntime() {
  const token = await getIdToken();
  if (!token) {
    throw new Error('无法读取 Shopify 登录身份，请重新打开应用。');
  }

  return runtimeFromIdToken(token);
}

/**
 * @param {string} url
 * @param {RequestInit} [options]
 */
async function authenticatedRequestJson(url, options = {}) {
  const token = await getIdToken();
  if (!token) {
    throw new Error('无法读取 Shopify 登录身份，请重新打开应用。');
  }

  const optionHeaders = Reflect.get(options, 'headers');
  return requestJson(url, {
    ...options,
    headers: {
      ...(optionHeaders || {}),
      Authorization: `Bearer ${token}`,
    },
  });
}

async function getIdToken() {
  const auth = Reflect.get(shopify, 'auth');
  const extensionIdToken = auth && Reflect.get(auth, 'idToken');
  if (typeof extensionIdToken === 'function') {
    return extensionIdToken.call(auth);
  }

  const appBridgeIdToken = Reflect.get(shopify, 'idToken');
  if (typeof appBridgeIdToken === 'function') {
    return appBridgeIdToken.call(shopify);
  }

  return null;
}
