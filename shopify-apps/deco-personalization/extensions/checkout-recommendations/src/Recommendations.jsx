import '@shopify/ui-extensions/preact';
import {useAppMetafields} from '@shopify/ui-extensions/checkout/preact';
import {render} from 'preact';
import {useEffect, useMemo, useRef, useState} from 'preact/hooks';
import {
  EVENTS,
  eventPayload,
  fetchConfiguration,
  normalizeCollectionProducts,
  selectNextCandidate,
} from './runtime.mjs';

const CONFIGURATION_METAFIELD_FILTER = Object.freeze({
  namespace: '$app:deco_personalization',
  key: 'checkout_configuration_url',
  type: 'shop',
});

const COLLECTION_QUERY = `query CheckoutPersonalizationCollection($id: ID!, $productsFirst: Int!) {
  node(id: $id) {
    __typename
    ... on Collection {
      id
      products(first: $productsFirst) {
        nodes {
          id
          title
          featuredImage { url altText }
          variants(first: 100) {
            nodes {
              id
              title
              availableForSale
              image { url altText }
              price { amount currencyCode }
            }
          }
        }
      }
    }
  }
}`;

export default function extension() {
  render(<Recommendations />, document.body);
}

function Recommendations() {
  const [configuration, setConfiguration] = useState(null);
  const [candidates, setCandidates] = useState([]);
  const [loaded, setLoaded] = useState(false);
  const [dismissed, setDismissed] = useState(() => new Set());
  const [shown, setShown] = useState(() => new Set());
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const busyRef = useRef(false);
  const impressionRef = useRef('');
  const completionRef = useRef(false);
  const configurationMetafields = useAppMetafields(CONFIGURATION_METAFIELD_FILTER);

  useEffect(() => {
    let active = true;
    setLoaded(false);
    fetchConfiguration(shopify, configurationMetafields)
      .then(async (value) => {
        if (!active || !value) return;
        const result = /** @type {{data?: {node?: unknown}, errors?: unknown[]}} */ (await shopify.query(COLLECTION_QUERY, {
          variables: {id: value.collection_id, productsFirst: value.scan_limit},
          version: '2026-07',
        }));
        if (!active || result.errors?.length) return;
        setConfiguration(value);
        setCandidates(normalizeCollectionProducts(result.data?.node));
      })
      .catch(() => {})
      .finally(() => active && setLoaded(true));
    return () => { active = false; };
  }, [configurationMetafields]);

  const lines = shopify.lines.value;
  const canAdd = shopify.instructions.value.lines.canAddCartLine;
  const current = useMemo(() => {
    if (!canAdd || !configuration) return null;
    const candidate = selectNextCandidate(candidates, lines, dismissed);
    return candidate && (shown.has(candidate.variant_id) || shown.size < configuration.maximum_recommendations)
      ? candidate
      : null;
  }, [canAdd, candidates, configuration, dismissed, lines, shown]);

  useEffect(() => {
    if (!configuration || !current || impressionRef.current === current.variant_id) return;
    impressionRef.current = current.variant_id;
    setShown((previous) => new Set([...previous, current.variant_id]));
    publish(EVENTS.impression, eventPayload(configuration, [current]));
  }, [configuration, current]);

  useEffect(() => {
    if (!loaded || !configuration || current || completionRef.current) return;
    completionRef.current = true;
    const offered = candidates.filter((candidate) => shown.has(candidate.variant_id));
    publish(EVENTS.sequenceCompleted, eventPayload(configuration, offered));
  }, [candidates, configuration, current, loaded, shown]);

  async function addCurrent() {
    if (!configuration || !current || busyRef.current || !canAdd) return;
    busyRef.current = true;
    setBusy(true);
    setError('');
    publish(EVENTS.click, eventPayload(configuration, [current]));
    try {
      const result = await shopify.applyCartLinesChange({
        type: 'addCartLine',
        merchandiseId: current.variant_id,
        quantity: 1,
      });
      if (result?.type === 'error') throw new Error('cart_change_rejected');
      publish(EVENTS.addSuccess, eventPayload(configuration, [current]));
      setDismissed((previous) => new Set([...previous, current.variant_id]));
    } catch {
      setError('This item could not be added. Please try again.');
      publish(EVENTS.addFailed, eventPayload(configuration, [current]));
    } finally {
      busyRef.current = false;
      setBusy(false);
    }
  }

  if (!configuration || !current) return null;

  return (
    <s-section>
      <s-stack gap="base">
        <s-heading>{configuration.component.heading}</s-heading>
        {error ? <s-banner tone="critical">{error}</s-banner> : null}
        <s-grid key={current.variant_id} gridTemplateColumns="64px 1fr auto" gap="base" alignItems="center">
          {current.image_url
            ? <s-image src={current.image_url} alt={current.image_alt || current.title} aspectRatio="1" />
            : <s-box><s-icon type="image" size="large" tone="neutral" /></s-box>}
          <s-stack gap="small-200">
            <s-heading>{current.title}</s-heading>
            {current.variant_title && current.variant_title !== 'Default Title'
              ? <s-text type="small">{current.variant_title}</s-text>
              : null}
            <s-text>{current.currency} {current.amount}</s-text>
          </s-stack>
          <s-button variant="primary" disabled={busy || !canAdd} loading={busy} onClick={addCurrent}>
            {configuration.component.button_label}
          </s-button>
        </s-grid>
      </s-stack>
    </s-section>
  );
}

function publish(name, payload) {
  try {
    void shopify.analytics.publish(name, payload);
  } catch {
    // Analytics must never interrupt checkout rendering or cart changes.
  }
}
