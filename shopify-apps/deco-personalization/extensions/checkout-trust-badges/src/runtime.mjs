const CONFIGURATION_PATH = '/api/shopify-app/personalization/checkout/configuration';

export function configurationEndpoint(entries) {
  if (!Array.isArray(entries)) return '';
  const entry = entries.find((candidate) => {
    const metafield = candidate?.metafield ?? candidate;
    return ['deco_personalization', '$app:deco_personalization'].includes(metafield?.namespace)
      && metafield?.key === 'checkout_configuration_url';
  });
  const metafield = entry?.metafield ?? entry;
  try {
    const endpoint = new URL(String(metafield?.value ?? ''));
    if (endpoint.protocol !== 'https:'
      || endpoint.username || endpoint.password || endpoint.hash
      || endpoint.pathname !== CONFIGURATION_PATH
      || endpoint.search) return '';
    return endpoint.toString();
  } catch {
    return '';
  }
}

export async function fetchConfiguration(api) {
  const endpoint = configurationEndpoint(api?.appMetafields?.value);
  if (!endpoint) return null;
  const token = await api.sessionToken.get();
  const response = await fetch(endpoint, {
    method: 'GET',
    headers: {Authorization: `Bearer ${token}`, Accept: 'application/json'},
  });
  if (!response.ok) return null;
  const payload = await response.json();
  return normalizeConfiguration(payload?.data);
}

export function normalizeConfiguration(value) {
  if (!value || value.enabled !== true || !Array.isArray(value.trust_items)) return null;
  const trustItems = value.trust_items.slice(0, 6).map((item, index) => ({
    key: string(item?.key, 64) || `trust_${index + 1}`,
    icon: string(item?.icon, 32) || 'check-circle',
    title: string(item?.title, 80),
    description: string(item?.description, 120),
    position: boundedInteger(item?.position, index + 1),
  })).filter((item) => item.title);
  if (trustItems.length === 0) return null;
  trustItems.sort((left, right) => left.position - right.position);
  return {trust_items: trustItems};
}

function string(value, maximum) {
  return typeof value === 'string' ? value.trim().slice(0, maximum) : '';
}

function boundedInteger(value, fallback) {
  const normalized = Number(value);
  return Number.isInteger(normalized) && normalized >= 1 && normalized <= 100 ? normalized : fallback;
}
