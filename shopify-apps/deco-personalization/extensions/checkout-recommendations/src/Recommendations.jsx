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

const COLLECTION_QUERY = `query CheckoutPersonalizationCollection($id: ID!, $productsFirst: Int!, $after: String) {
  node(id: $id) {
    __typename
    ... on Collection {
      id
      products(first: $productsFirst, after: $after) {
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
        pageInfo { hasNextPage endCursor }
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
  const [nextCursor, setNextCursor] = useState('');
  const [scannedCount, setScannedCount] = useState(0);
  const [loadingPage, setLoadingPage] = useState(false);
  const [paginationFailed, setPaginationFailed] = useState(false);
  const [dismissed, setDismissed] = useState(() => new Set());
  const [shown, setShown] = useState(() => new Set());
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const busyRef = useRef(false);
  const pageRequestRef = useRef(false);
  const paginationGenerationRef = useRef(0);
  const impressionRef = useRef('');
  const completionRef = useRef(false);
  const configurationMetafields = useAppMetafields(CONFIGURATION_METAFIELD_FILTER);

  useEffect(() => {
    let active = true;
    paginationGenerationRef.current += 1;
    pageRequestRef.current = false;
    setLoaded(false);
    setConfiguration(null);
    setCandidates([]);
    setNextCursor('');
    setScannedCount(0);
    setPaginationFailed(false);
    setDismissed(new Set());
    setShown(new Set());
    impressionRef.current = '';
    completionRef.current = false;
    fetchConfiguration(shopify, configurationMetafields)
      .then(async (value) => {
        if (!active || !value) return;
        const page = await fetchCollectionPage(value, '', 0);
        if (!active) return;
        setConfiguration(value);
        setCandidates(page.candidates);
        setNextCursor(page.next_cursor);
        setScannedCount(page.scanned_count);
      })
      .catch(() => {})
      .finally(() => active && setLoaded(true));
    return () => { active = false; };
  }, [configurationMetafields]);

  const lines = shopify.lines.value;
  const canAdd = shopify.instructions.value.lines.canAddCartLine;
  const current = useMemo(() => {
    if (!canAdd || !configuration) return null;
    if (configuration.maximum_recommendations !== null
      && dismissed.size >= configuration.maximum_recommendations) return null;
    return selectNextCandidate(candidates, lines, dismissed);
  }, [canAdd, candidates, configuration, dismissed, lines]);

  const maximumReached = Boolean(configuration?.maximum_recommendations !== null
    && dismissed.size >= configuration.maximum_recommendations);

  useEffect(() => {
    if (!loaded || !configuration || current || maximumReached || !nextCursor || pageRequestRef.current || paginationFailed) return;
    const generation = paginationGenerationRef.current;
    pageRequestRef.current = true;
    setLoadingPage(true);
    fetchCollectionPage(configuration, nextCursor, scannedCount)
      .then((page) => {
        if (generation !== paginationGenerationRef.current) return;
        setCandidates((previous) => appendCandidates(previous, page.candidates));
        setNextCursor(page.next_cursor);
        setScannedCount((previous) => previous + page.scanned_count);
      })
      .catch(() => {
        if (generation === paginationGenerationRef.current) setPaginationFailed(true);
      })
      .finally(() => {
        if (generation !== paginationGenerationRef.current) return;
        pageRequestRef.current = false;
        setLoadingPage(false);
      });
  }, [configuration, current, loaded, maximumReached, nextCursor, paginationFailed, scannedCount]);

  useEffect(() => {
    if (!configuration || !current || impressionRef.current === current.variant_id) return;
    impressionRef.current = current.variant_id;
    setShown((previous) => new Set([...previous, current.variant_id]));
    publish(EVENTS.impression, eventPayload(configuration, [current]));
  }, [configuration, current]);

  useEffect(() => {
    if (!loaded || !configuration || current || (!maximumReached && nextCursor) || loadingPage || paginationFailed || completionRef.current) return;
    completionRef.current = true;
    const offered = candidates.filter((candidate) => shown.has(candidate.variant_id));
    publish(EVENTS.sequenceCompleted, eventPayload(configuration, offered));
  }, [candidates, configuration, current, loaded, loadingPage, maximumReached, nextCursor, paginationFailed, shown]);

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

  const awaitingNext = Boolean(configuration && loaded && !current && !maximumReached && nextCursor && !paginationFailed);
  if (!configuration || (!current && !awaitingNext)) return null;

  return (
    <s-section>
      <s-stack gap="base">
        <s-heading>{configuration.component.heading}</s-heading>
        {error ? <s-banner tone="critical">{error}</s-banner> : null}
        {current ? <s-grid key={current.variant_id} gridTemplateColumns="64px 1fr auto" gap="base" alignItems="center">
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
        </s-grid> : <s-text>Loading next recommendation…</s-text>}
      </s-stack>
    </s-section>
  );
}

async function fetchCollectionPage(configuration, after, rankOffset) {
  const result = /** @type {{data?: {node?: unknown}, errors?: unknown[]}} */ (await shopify.query(COLLECTION_QUERY, {
    variables: {
      id: configuration.collection_id,
      productsFirst: configuration.page_size,
      after: after || null,
    },
    version: '2026-07',
  }));
  if (result.errors?.length) throw new Error('collection_query_failed');
  const collection = /** @type {{products?: {nodes?: unknown[], pageInfo?: {hasNextPage?: boolean, endCursor?: string}}}} */ (result.data?.node);
  const nodes = Array.isArray(collection?.products?.nodes) ? collection.products.nodes : [];
  const pageInfo = collection?.products?.pageInfo;
  return {
    candidates: normalizeCollectionProducts(result.data?.node, rankOffset),
    next_cursor: pageInfo?.hasNextPage === true && typeof pageInfo.endCursor === 'string' ? pageInfo.endCursor : '',
    scanned_count: nodes.length,
  };
}

function appendCandidates(previous, additions) {
  const variantIds = new Set(previous.map((candidate) => candidate.variant_id));
  return [...previous, ...additions.filter((candidate) => !variantIds.has(candidate.variant_id))];
}

function publish(name, payload) {
  try {
    void shopify.analytics.publish(name, payload);
  } catch {
    // Analytics must never interrupt checkout rendering or cart changes.
  }
}
