import {render} from 'preact';
import {useEffect, useState} from 'preact/hooks';

import {runtimeFromIdToken} from './runtime.mjs';

const CONNECTION_PATH = '/api/shopify-app/personalization/connection';
const BOOTSTRAP_PATH = '/api/shopify-app/personalization/bootstrap';
const MANAGEMENT_PATH = '/shopify-app/personalization';

/**
 * @typedef {{
 *   status: 'checking' | 'connected' | 'error',
 *   message: string,
 *   storeName: string,
 *   environment: string,
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
  /** @type {ConnectionState} */
  const initialConnection = {
    status: 'checking',
    message: '',
    storeName: '',
    environment: '',
  };
  const [connection, setConnection] = useState(initialConnection);

  useEffect(() => {
    const controller = new AbortController();
    let cancelled = false;

    resolveRuntime()
      .then(async (resolvedRuntime) => {
        if (cancelled) return;
        setRuntime(resolvedRuntime);

        const connectionUrl = buildUrl(resolvedRuntime.appOrigin, CONNECTION_PATH, {
          shop: resolvedRuntime.shopDomain,
        });
        const connectionPayload = await authenticatedRequestJson(connectionUrl, {
          signal: controller.signal,
        });
        if (connectionPayload.connected !== true) {
          throw new Error('该 Shopify 店铺尚未连接 DecoAdmin Commerce Hub。');
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

  const managementUrl = runtime.appOrigin
    ? buildUrl(runtime.appOrigin, MANAGEMENT_PATH, {
        shop: runtime.shopDomain,
        source: 'shopify',
      })
    : '';

  return (
    <s-page heading="Deco 个性化推荐" inlineSize="base">
      <s-section heading="连接状态">
        <s-stack direction="block" gap="base">
          <s-stack direction="inline" gap="base" alignItems="center">
            <ConnectionBadge status={connection.status} />
            <s-text type="strong">{runtime.shopDomain || '未知店铺'}</s-text>
          </s-stack>

          {connection.status === 'checking' && (
            <s-paragraph color="subdued">正在验证 DecoAdmin Commerce Hub 连接和个性化推荐安装…</s-paragraph>
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

      <s-section heading="管理个性化推荐">
        <s-stack direction="block" gap="base">
          <s-paragraph color="subdued">
            推荐策略、商品规则、组件样式、桌面与移动预览均在 DecoAdmin 中完成。打开后需要登录，并会自动定位到当前店铺。
          </s-paragraph>
          <s-button
            variant="primary"
            href={managementUrl || undefined}
            target="_top"
            disabled={!managementUrl || connection.status !== 'connected'}
          >
            前往 DecoAdmin 管理
          </s-button>
        </s-stack>
      </s-section>

      <s-section heading="安全发布流程">
        <s-stack direction="block" gap="base">
          <s-ordered-list>
            <s-list-item>在 DecoAdmin 创建策略、规则和推荐组件草稿。</s-list-item>
            <s-list-item>完成桌面和移动预览后启用后端配置。</s-list-item>
            <s-list-item>在测试主题中添加推荐区块；当前页面不会自动修改主题。</s-list-item>
          </s-ordered-list>
          <s-banner heading="Smart Cart 默认关闭" tone="info">
            Smart Cart 必须先通过兼容性检查和主题预览，且可随时恢复 Shopify 默认购物车。
          </s-banner>
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

function publicMessage(message) {
  const value = String(message || '').trim();
  if (!value || value.length > 240) {
    return '请稍后重试；如果问题持续，请联系 DecoAdmin 管理员。';
  }
  return value;
}

/** @param {string} url @param {RequestInit} [options] */
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

/** @param {string} url @param {RequestInit} [options] */
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
