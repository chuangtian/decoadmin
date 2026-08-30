import '@shopify/ui-extensions/preact';
import {
  useAppMetafields,
  useApplyCartLinesChange,
  useApplyDiscountCodeChange,
  useCartLines,
  useDiscountCodes,
  useInstructions,
} from '@shopify/ui-extensions/checkout/preact';
import {render} from 'preact';
import {useEffect, useMemo, useRef, useState} from 'preact/hooks';
import {
  EVENTS,
  eventPayload,
  fetchConfiguration,
  fetchRecommendations,
  selectNextCandidate,
} from './runtime.mjs';

const CONFIGURATION_METAFIELD_FILTER = Object.freeze({
  namespace: '$app:deco_personalization',
  key: 'checkout_configuration_url',
  type: 'shop',
});

export default function extension() {
  render(<Recommendations />, document.body);
}

function Recommendations() {
  const [configuration, setConfiguration] = useState(null);
  const [candidates, setCandidates] = useState([]);
  const [configurationLoaded, setConfigurationLoaded] = useState(false);
  const [recommendationsLoaded, setRecommendationsLoaded] = useState(false);
  const [refreshing, setRefreshing] = useState(false);
  const [lastCandidate, setLastCandidate] = useState(null);
  const [dismissed, setDismissed] = useState(() => new Set());
  const [shown, setShown] = useState(() => new Set());
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const busyRef = useRef(false);
  const requestGeneration = useRef(0);
  const impressionRef = useRef('');
  const completionRef = useRef(false);
  const configurationMetafields = useAppMetafields(CONFIGURATION_METAFIELD_FILTER);
  const lines = useCartLines();
  const instructions = useInstructions();
  const discountCodes = useDiscountCodes();
  const applyCartLinesChange = useApplyCartLinesChange();
  const applyDiscountCodeChange = useApplyDiscountCodeChange();
  const canAdd = instructions.lines.canAddCartLine;
  const canUpdateDiscountCodes = instructions.discounts?.canUpdateDiscountCodes === true;
  const lineSignature = JSON.stringify((Array.isArray(lines) ? lines : []).map((line) => [
    line?.merchandise?.product?.id,
    line?.merchandise?.id,
    line?.quantity,
  ]));

  useEffect(() => {
    let active = true;
    setConfigurationLoaded(false);
    setRecommendationsLoaded(false);
    setConfiguration(null);
    setCandidates([]);
    setLastCandidate(null);
    fetchConfiguration(shopify, configurationMetafields)
      .then((value) => { if (active) setConfiguration(value); })
      .catch(() => { if (active) setConfiguration(null); })
      .finally(() => { if (active) setConfigurationLoaded(true); });
    return () => { active = false; };
  }, [configurationMetafields]);

  useEffect(() => {
    if (!configuration) {
      requestGeneration.current += 1;
      setRefreshing(false);
      return;
    }
    const generation = ++requestGeneration.current;
    setRefreshing(true);
    refreshRecommendations(configuration, lines)
      .then((items) => {
        if (generation !== requestGeneration.current) return;
        setCandidates(items);
        completionRef.current = false;
      })
      .catch(() => { if (generation === requestGeneration.current) setCandidates([]); })
      .finally(() => {
        if (generation !== requestGeneration.current) return;
        setRecommendationsLoaded(true);
        setRefreshing(false);
      });
  }, [configuration, lineSignature]);

  const current = useMemo(() => configuration
    ? selectNextCandidate(candidates, lines, dismissed)
    : null, [candidates, configuration, dismissed, lineSignature]);
  const displayedCandidate = current ?? ((busy || refreshing) ? lastCandidate : null);

  useEffect(() => {
    if (current) setLastCandidate(current);
  }, [current]);

  useEffect(() => {
    if (!configuration || !current || impressionRef.current === current.variant_id) return;
    impressionRef.current = current.variant_id;
    setShown((previous) => new Set([...previous, current.variant_id]));
    publish(EVENTS.impression, eventPayload(configuration, [current]));
  }, [configuration, current]);

  useEffect(() => {
    if (!recommendationsLoaded || refreshing || !configuration || current || completionRef.current) return;
    completionRef.current = true;
    publish(EVENTS.sequenceCompleted, eventPayload(configuration, candidates.filter((candidate) => shown.has(candidate.variant_id))));
  }, [candidates, configuration, current, recommendationsLoaded, refreshing, shown]);

  async function refreshRecommendations(activeConfiguration, activeLines) {
    return fetchRecommendations(shopify, activeConfiguration, activeLines, {
      market: shopify.localization?.market?.value?.handle ?? '',
      currency: shopify.cost?.totalAmount?.value?.currencyCode ?? '',
      language: shopify.localization?.language?.value?.isoCode ?? '',
    });
  }

  async function addCurrent() {
    if (!configuration || !current || busyRef.current || !canAdd) return;
    busyRef.current = true;
    setBusy(true);
    setError('');
    publish(EVENTS.click, eventPayload(configuration, [current]));
    try {
      const result = await applyCartLinesChange({
        type: 'addCartLine',
        merchandiseId: current.variant_id,
        quantity: current.minimum_purchase_quantity,
      });
      if (result?.type === 'error') throw new Error('cart_change_rejected');
      if (current.discount?.code) {
        const alreadyApplied = discountCodes.some(
          (discount) => String(discount?.code ?? '').toLowerCase() === current.discount.code.toLowerCase(),
        );
        if (!alreadyApplied && canUpdateDiscountCodes) {
          const discountResult = await applyDiscountCodeChange({
            type: 'addDiscountCode',
            code: current.discount.code,
          });
          if (discountResult?.type === 'error') {
            setError(`Item added. Apply discount code ${current.discount.code} to receive the offer.`);
          }
        } else if (!alreadyApplied && !canUpdateDiscountCodes) {
          setError(`Item added. Apply discount code ${current.discount.code} to receive the offer.`);
        }
      }
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

  if (!configurationLoaded || !recommendationsLoaded || !configuration || !displayedCandidate) return null;

  return (
    <s-section>
      <s-box paddingInline="large-200">
        <s-stack gap="base">
          <s-stack alignItems="center">
            <s-heading>{configuration.component.heading}</s-heading>
          </s-stack>
          {error ? <s-banner tone="critical">{error}</s-banner> : null}
          <s-grid key={displayedCandidate.variant_id} gridTemplateColumns="64px 1fr auto" gap="base" alignItems="center">
            {displayedCandidate.image_url
              ? <s-image src={displayedCandidate.image_url} alt={displayedCandidate.image_alt || displayedCandidate.title} aspectRatio="1" />
              : <s-box><s-icon type="image" size="large" tone="neutral" /></s-box>}
            <s-stack gap="small-200">
              <s-heading>{displayedCandidate.title}</s-heading>
              {displayedCandidate.variant_title && displayedCandidate.variant_title !== 'Default Title'
                ? <s-text type="small">{displayedCandidate.variant_title}</s-text>
                : null}
              {displayedCandidate.discount?.percentage && displayedCandidate.discounted_amount
                ? <s-stack gap="small-100">
                    <s-text tone="neutral">Original {displayedCandidate.currency} {displayedCandidate.amount}</s-text>
                    <s-text tone="success">{displayedCandidate.discount.percentage}% off</s-text>
                    <s-text>Now {displayedCandidate.currency} {displayedCandidate.discounted_amount}</s-text>
                    {displayedCandidate.discount.code ? <s-text tone="success">Code {displayedCandidate.discount.code}</s-text> : null}
                  </s-stack>
                : <s-text>{displayedCandidate.currency} {displayedCandidate.amount}</s-text>}
            </s-stack>
            <s-button
              variant="primary"
              disabled={busy || refreshing || !canAdd || !current}
              loading={busy || (refreshing && !current)}
              onClick={addCurrent}
            >
              {configuration.component.button_label}
            </s-button>
          </s-grid>
        </s-stack>
      </s-box>
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
