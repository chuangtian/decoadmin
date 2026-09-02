import '@shopify/ui-extensions/preact';
import {useAppMetafields, useCartLines} from '@shopify/ui-extensions/checkout/preact';
import {render} from 'preact';
import {useEffect, useMemo, useRef, useState} from 'preact/hooks';
import {
  cartPermalink,
  configurationEndpoint,
  fetchRecommendations,
  fetchThankYouConfiguration,
  formatMoney,
  selectNextCandidate,
} from './runtime.mjs';

const CONFIGURATION_METAFIELD_FILTER = Object.freeze({
  namespace: '$app:deco_personalization',
  key: 'checkout_configuration_url',
  type: 'shop',
});

export default function extension() {
  render(<ThankYouRecommendations />, document.body);
}

function ThankYouRecommendations() {
  const [configuration, setConfiguration] = useState(null);
  const [candidates, setCandidates] = useState([]);
  const [configurationLoaded, setConfigurationLoaded] = useState(false);
  const [recommendationsLoaded, setRecommendationsLoaded] = useState(false);
  const [dismissed, setDismissed] = useState(() => new Set());
  const requestGeneration = useRef(0);
  const configurationMetafields = useAppMetafields(CONFIGURATION_METAFIELD_FILTER);
  const configurationUrl = configurationEndpoint(configurationMetafields);
  const lines = useCartLines();
  const language = shopify.localization?.language?.value?.isoCode ?? '';
  const lineSignature = JSON.stringify((Array.isArray(lines) ? lines : []).map((line) => [
    line?.merchandise?.product?.id,
    line?.merchandise?.id,
    line?.quantity,
  ]));

  useEffect(() => {
    let active = true;
    setConfigurationLoaded(false);
    setConfiguration(null);
    setCandidates([]);
    fetchThankYouConfiguration(shopify, configurationMetafields)
      .then((value) => { if (active) setConfiguration(value); })
      .catch(() => { if (active) setConfiguration(null); })
      .finally(() => { if (active) setConfigurationLoaded(true); });
    return () => { active = false; };
  }, [configurationUrl]);

  useEffect(() => {
    if (!configuration) {
      requestGeneration.current += 1;
      return;
    }
    const generation = ++requestGeneration.current;
    setRecommendationsLoaded(false);
    fetchRecommendations(shopify, configuration, lines, {
      market: shopify.localization?.market?.value?.handle ?? '',
      currency: shopify.cost?.totalAmount?.value?.currencyCode ?? '',
      cartSubtotal: shopify.cost?.subtotalAmount?.value?.amount ?? shopify.cost?.totalAmount?.value?.amount ?? null,
      surface: 'thank_you',
      language,
    })
      .then((items) => { if (generation === requestGeneration.current) setCandidates(items); })
      .catch(() => { if (generation === requestGeneration.current) setCandidates([]); })
      .finally(() => { if (generation === requestGeneration.current) setRecommendationsLoaded(true); });
  }, [configuration, lineSignature]);

  const current = useMemo(() => configuration
    ? selectNextCandidate(candidates, lines, dismissed)
    : null, [candidates, configuration, dismissed, lineSignature]);
  const storefrontUrl = shopify.shop?.storefrontUrl
    || (shopify.shop?.myshopifyDomain ? `https://${shopify.shop.myshopifyDomain}` : '');
  const addUrl = useMemo(() => cartPermalink(storefrontUrl, current), [current, storefrontUrl]);

  if (!configurationLoaded || !recommendationsLoaded || !configuration || !current || !addUrl) return null;

  return (
    <s-section>
      <s-box paddingInline="large-200">
        <s-stack gap="base">
          <s-stack alignItems="center">
            <s-heading>{configuration.component.heading}</s-heading>
          </s-stack>
          <s-grid key={current.variant_id} gridTemplateColumns="96px 1fr auto" gap="base" alignItems="center">
            {current.image_url
              ? <s-image src={current.image_url} alt={current.image_alt || current.title} aspectRatio="1" />
              : <s-box><s-icon type="image" size="large" tone="neutral" /></s-box>}
            <s-stack gap="small-200" minInlineSize="0">
              <s-heading>{current.title}</s-heading>
              {current.variant_title && current.variant_title !== 'Default Title'
                ? <s-text type="small">{current.variant_title}</s-text>
                : null}
              {current.discount?.percentage
                && current.discounted_amount
                && Number(current.discounted_amount) < Number(current.amount)
                ? <s-stack gap="small-100">
                    <s-text type="small" tone="success">{current.discount.percentage}% off</s-text>
                    <s-stack direction="inline" gap="small-200" alignItems="center">
                      <s-text type="strong">{formatMoney(current.discounted_amount, current.currency, language)}</s-text>
                      <s-text type="redundant">{formatMoney(current.amount, current.currency, language)}</s-text>
                    </s-stack>
                  </s-stack>
                : <s-text>{formatMoney(current.amount, current.currency, language)}</s-text>}
            </s-stack>
            <s-button
              variant="primary"
              inlineSize="fit-content"
              href={addUrl}
              target="_blank"
              onClick={() => setDismissed((previous) => new Set([...previous, current.variant_id]))}
            >
              {configuration.component.button_label}
            </s-button>
          </s-grid>
        </s-stack>
      </s-box>
    </s-section>
  );
}
