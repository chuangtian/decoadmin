const APP_ENVIRONMENTS = Object.freeze({
  '179bfe6a970b5daabc4f6344bcd2733b': Object.freeze({
    environment: 'test',
    appOrigin: 'https://testadmin.decomkt.com',
  }),
  '030f66008921f9d200ac057ef03fa163': Object.freeze({
    environment: 'production',
    appOrigin: 'https://admin.decomkt.com',
  }),
});

/**
 * Decode only enough context to route the request to the correct trusted app
 * origin. The backend still verifies the token signature and every claim.
 */
export function runtimeFromIdToken(token) {
  const claims = decodeIdTokenClaims(token);
  const clientId = audiences(claims.aud).find((audience) => APP_ENVIRONMENTS[audience]);
  const environment = clientId ? APP_ENVIRONMENTS[clientId] : null;
  const shopDomain = shopDomainFromDestination(claims.dest);

  if (!clientId || !environment) {
    throw new Error('无法识别当前学生优惠应用环境，请联系 DecoAdmin 管理员。');
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
