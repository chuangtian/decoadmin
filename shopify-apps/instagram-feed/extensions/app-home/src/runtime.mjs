/**
 * Admin UI 扩展的运行时上下文。
 *
 * `admin.app.home.render` 的 API 对象只提供 auth / i18n / intents / storage /
 * query / toast / app / loading / tools，没有 `config`，拿不到店铺域名和后端地址。
 * 因此这里从 App Bridge 的 session token 里解出路由所需的最小上下文：
 * `aud` 给出当前 App 的 client_id，`dest` 给出当前店铺。
 *
 * 只解码、不验签。后端中间件会用 client_secret 重新验签并逐项校验 claim。
 */
const APP_ENVIRONMENTS = Object.freeze({
  // local 与 test 共用同一个 Shopify App（Cloudflare 隧道已废弃）。
  d3446448682d2950aa75cea4a399d50f: Object.freeze({
    environment: 'test',
    appOrigin: 'https://testadmin.decomkt.com',
  }),
  // 生产 App 尚未创建。创建后在此登记它的 client_id 与 https://admin.decomkt.com。
});

export function runtimeFromIdToken(token) {
  const claims = decodeIdTokenClaims(token);
  const clientId = audiences(claims.aud).find((audience) => APP_ENVIRONMENTS[audience]);
  const environment = clientId ? APP_ENVIRONMENTS[clientId] : null;
  const shopDomain = shopDomainFromDestination(claims.dest);

  if (!clientId || !environment) {
    throw new Error('无法识别当前 Instagram 内容应用环境，请联系 DecoAdmin 管理员。');
  }
  if (!shopDomain) {
    throw new Error('无法读取当前 Shopify 店铺，请重新打开应用。');
  }

  return {
    appOrigin: environment.appOrigin,
    clientId,
    environment: environment.environment,
    shopDomain,
  };
}

export function decodeIdTokenClaims(token) {
  const segments = String(token || '').split('.');
  if (segments.length !== 3 || !segments[1]) {
    throw new Error('无法读取 Shopify 登录身份，请重新打开应用。');
  }

  try {
    const normalized = segments[1].replace(/-/g, '+').replace(/_/g, '/');
    const padded = normalized.padEnd(Math.ceil(normalized.length / 4) * 4, '=');
    const binary = atob(padded);
    const bytes = Uint8Array.from(binary, (character) => character.charCodeAt(0));
    const claims = JSON.parse(new TextDecoder().decode(bytes));

    if (!claims || typeof claims !== 'object' || Array.isArray(claims)) {
      throw new Error('invalid claims');
    }

    return claims;
  } catch {
    throw new Error('无法读取 Shopify 登录身份，请重新打开应用。');
  }
}

function audiences(value) {
  const values = Array.isArray(value) ? value : [value];
  return values.filter((audience) => typeof audience === 'string' && audience.trim());
}

function shopDomainFromDestination(value) {
  try {
    const destination = new URL(String(value || ''));
    const hostname = destination.hostname.toLowerCase();

    if (destination.protocol !== 'https:' || !/^[a-z0-9][a-z0-9-]*\.myshopify\.com$/.test(hostname)) {
      return '';
    }

    return hostname;
  } catch {
    return '';
  }
}
