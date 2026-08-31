export const APPROVED_TEST_SHOP = 'macfox-test-app.myshopify.com';
export const APPROVED_DEV_ORGANIZATION = 'E-LINK TECHNOLOGY CO LTD';
export const DENIED_SHOP = 'macfoxebike.myshopify.com';

export function guardTestAction(action, environment = process.env) {
  const shop = normalizeShop(environment.PERSONALIZATION_TEST_SHOP);
  const organization = String(environment.PERSONALIZATION_DEV_ORGANIZATION || '').trim();
  if (shop === DENIED_SHOP) {
    throw new Error('Permanent denied shop resolved; Test action stopped.');
  }
  if (shop !== APPROVED_TEST_SHOP) {
    throw new Error(`Test action requires exact shop ${APPROVED_TEST_SHOP}.`);
  }
  if (organization !== APPROVED_DEV_ORGANIZATION) {
    throw new Error(`Test action requires Dev Dashboard organization ${APPROVED_DEV_ORGANIZATION}.`);
  }
  if (action === 'deploy'
    && environment.PERSONALIZATION_TEST_RELEASE_CONFIRM !== 'deco-personalization-test') {
    throw new Error('Test deploy requires the exact release confirmation token.');
  }
  return {action, shop, organization};
}

function normalizeShop(value) {
  const shop = String(value || '').trim().toLowerCase();
  return /^[a-z0-9][a-z0-9-]*\.myshopify\.com$/.test(shop) ? shop : '';
}
