const APP_CONFIG_BY_CLIENT_ID = new Map([
  ['2157d17bf0b595f9a52cc3bf18b5b616', {
    appOrigin: 'https://testadmin.decomkt.com',
    environment: 'test',
  }],
  ['b4dca5d161757cb26e0e805642a3b775', {
    appOrigin: 'https://admin.decomkt.com',
    environment: 'production',
  }],
]);
const DENIED_SHOPS = new Set(['macfoxebike.myshopify.com']);

/**
 * Decode only enough context to route the request to an approved fixed origin.
 * The Laravel backend still verifies the token signature, audience and claims.
 */
export function runtimeFromIdToken(token) {
  const claims = decodeIdTokenClaims(token);
  const clientId = audiences(claims.aud).find((audience) => APP_CONFIG_BY_CLIENT_ID.has(audience));
  const appConfig = APP_CONFIG_BY_CLIENT_ID.get(clientId);
  const shopDomain = shopDomainFromDestination(claims.dest);

  if (!appConfig) {
    throw new Error('无法识别当前个性化推荐应用，请联系 DecoAdmin 管理员。');
  }
  if (!shopDomain) {
    throw new Error('无法读取当前 Shopify 店铺，请重新打开应用。');
  }
  if (DENIED_SHOPS.has(shopDomain)) {
    throw new Error('该店铺禁止用于个性化推荐应用操作。');
  }

  return {
    appOrigin: appConfig.appOrigin,
    clientId,
    environment: appConfig.environment,
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
