export const APPROVED_DEV_ORGANIZATION = 'E-LINK TECHNOLOGY CO LTD';

export function guardProductionAction(action, environment = process.env) {
  const organization = String(environment.PERSONALIZATION_DEV_ORGANIZATION || '').trim();
  if (organization !== APPROVED_DEV_ORGANIZATION) {
    throw new Error(`Production action requires Dev Dashboard organization ${APPROVED_DEV_ORGANIZATION}.`);
  }
  if (action === 'deploy'
    && environment.PERSONALIZATION_PRODUCTION_RELEASE_CONFIRM !== 'deco-personalization-production') {
    throw new Error('Production deploy requires the exact release confirmation token.');
  }
  return {action, organization};
}
