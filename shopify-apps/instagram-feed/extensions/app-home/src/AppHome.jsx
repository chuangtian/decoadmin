import {render} from 'preact';
import {useEffect, useState} from 'preact/hooks';

const CONNECTION_PATH = '/api/shopify-app/instagram-feed/connection';
const BOOTSTRAP_PATH = '/api/shopify-app/instagram-feed/bootstrap';
const MANAGEMENT_PATH = '/shopify-app/instagram-feed';
const THEME_BLOCK_HANDLE = 'instagram_videos';

/**
 * Shopify Admin 内的 App 首页。
 *
 * 这里只做两件事：确认店铺已接入 DecoAdmin，并调 bootstrap 建立本 App 的
 * Shopify 会话（DecoAdmin 后台发布前台数据要用它）。真正的内容管理在 DecoAdmin。
 *
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
  const shopDomain = String(shopify?.config?.shop || '').trim();
  const clientId = String(shopify?.config?.apiKey || '').trim();
  const appOrigin = getAppOrigin(shopify?.config?.appOrigins);
  const [connection, setConnection] = useState({
    status: 'checking',
    message: '',
    storeName: '',
    environment: '',
  });

  useEffect(() => {
    if (!appOrigin || !shopDomain) {
      setConnection({
        status: 'error',
        message: '无法读取当前应用环境，请重新打开应用。',
        storeName: '',
        environment: '',
      });
      return undefined;
    }

    const controller = new AbortController();
    const url = buildUrl(appOrigin, CONNECTION_PATH, {shop: shopDomain});

    requestJson(url, {signal: controller.signal})
      .then(async (connectionPayload) => {
        if (connectionPayload.connected !== true) {
          throw new Error('该 Shopify 店铺尚未连接 DecoAdmin。');
        }

        const bootstrapUrl = buildUrl(appOrigin, BOOTSTRAP_PATH, {shop: shopDomain});
        const payload = await requestJson(bootstrapUrl, {
          method: 'POST',
          signal: controller.signal,
        });

        setConnection({
          status: 'connected',
          message: '',
          storeName: String(payload.store?.name || '').trim(),
          environment: String(payload.environment || '').trim(),
        });
      })
      .catch((error) => {
        if (error?.name === 'AbortError') return;
        setConnection({
          status: 'error',
          message: publicMessage(error instanceof Error ? error.message : ''),
          storeName: '',
          environment: '',
        });
      });

    return () => controller.abort();
  }, [appOrigin, shopDomain]);

  const managementUrl = appOrigin
    ? buildUrl(appOrigin, MANAGEMENT_PATH, {shop: shopDomain, source: 'shopify'})
    : '';
  const themeEditorUrl = buildThemeEditorUrl(shopDomain, clientId);

  return (
    <s-page heading="Deco Instagram 内容" inlineSize="base">
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

      <s-section heading="管理 Instagram 内容">
        <s-stack direction="block" gap="base">
          <s-paragraph color="subdued">
            账号授权、内容同步、展示组编排和发布都在 DecoAdmin 中完成。打开后需要单独登录，并会自动定位到当前店铺。
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
            <s-list-item>确认添加 Instagram 视频 应用区块。</s-list-item>
            <s-list-item>在区块设置里填入 DecoAdmin 中的展示组标识，再调整版式与颜色后保存主题。</s-list-item>
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

function getAppOrigin(origins) {
  const values = Array.isArray(origins) ? origins : [origins];

  for (const value of values) {
    if (!value) continue;
    try {
      return new URL(value).origin;
    } catch {
      continue;
    }
  }

  return '';
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

/** 后端异常信息不直接展示给商家：过长或为空时换成通用提示。 */
function publicMessage(message) {
  const value = String(message || '').trim();
  if (!value || value.length > 240) {
    return '请稍后重试；如果问题持续，请联系 DecoAdmin 管理员。';
  }
  return value;
}

async function requestJson(url, options = {}) {
  const response = await fetch(url, {
    ...options,
    headers: {Accept: 'application/json'},
  });
  const payload = await response.json().catch(() => ({}));

  if (!response.ok) {
    throw new Error(publicMessage(payload.error?.message));
  }

  return payload.data || {};
}
