import '@shopify/ui-extensions/preact';
import {useAppMetafields} from '@shopify/ui-extensions/checkout/preact';
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
  const [loaded, setLoaded] = useState(false);
  const [dismissed, setDismissed] = useState(() => new Set());
  const [shown, setShown] = useState(() => new Set());
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const busyRef = useRef(false);
  const requestGeneration = useRef(0);
  const impressionRef = useRef('');
  const completionRef = useRef(false);
  const configurationMetafields = useAppMetafields(CONFIGURATION_METAFIELD_FILTER);
  const lines = shopify.lines.value;
  const canAdd = shopify.instructions.value.lines.canAddCartLine;
  const canUpdateDiscountCodes = shopify.instructions.value.discounts?.canUpdateDiscountCodes === true;
  const lineSignature = JSON.stringify((Array.isArray(lines) ? lines : []).map((line) => [
    line?.merchandise?.product?.id,
    line?.merchandise?.id,
    line?.quantity,
  ]));

  useEffect(() => {
    let active = true;
    setLoaded(false);
    fetchConfiguration(shopify, configurationMetafields)
      .then((value) => { if (active) setConfiguration(value); })
      .catch(() => { if (active) setConfiguration(null); })
      .finally(() => { if (active) setLoaded(true); });
    return () => { active = false; };
  }, [configurationMetafields]);

  useEffect(() => {
    if (!configuration) { setCandidates([]); return; }
    const generation = ++requestGeneration.current;
    setLoaded(false);
    refreshRecommendations(configuration, lines)
      .then((items) => {
        if (generation !== requestGeneration.current) return;
        setCandidates(items);
        completionRef.current = false;
      })
      .catch(() => { if (generation === requestGeneration.current) setCandidates([]); })
      .finally(() => { if (generation === requestGeneration.current) setLoaded(true); });
  }, [configuration, lineSignature]);

  const current = useMemo(() => canAdd && configuration
    ? selectNextCandidate(candidates, lines, dismissed)
    : null, [canAdd, candidates, configuration, dismissed, lineSignature]);

  useEffect(() => {
    if (!configuration || !current || impressionRef.current === current.variant_id) return;
    impressionRef.current = current.variant_id;
    setShown((previous) => new Set([...previous, current.variant_id]));
    publish(EVENTS.impression, eventPayload(configuration, [current]));
  }, [configuration, current]);

  useEffect(() => {
    if (!loaded || !configuration || current || completionRef.current) return;
    completionRef.current = true;
    publish(EVENTS.sequenceCompleted, eventPayload(configuration, candidates.filter((candidate) => shown.has(candidate.variant_id))));
  }, [candidates, configuration, current, loaded, shown]);

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
      const result = await shopify.applyCartLinesChange({
        type: 'addCartLine',
        merchandiseId: current.variant_id,
        quantity: current.minimum_purchase_quantity,
      });
      if (result?.type === 'error') throw new Error('cart_change_rejected');
      if (current.discount?.code) {
        const alreadyApplied = (shopify.discountCodes?.value ?? []).some(
          (discount) => String(discount?.code ?? '').toLowerCase() === current.discount.code.toLowerCase(),
        );
        if (!alreadyApplied && canUpdateDiscountCodes) {
          const discountResult = await shopify.applyDiscountCodeChange({
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
      const refreshed = await refreshRecommendations(configuration, shopify.lines.value);
      setCandidates(refreshed);
    } catch {
      setError('This item could not be added. Please try again.');
      publish(EVENTS.addFailed, eventPayload(configuration, [current]));
    } finally {
      busyRef.current = false;
      setBusy(false);
    }
  }

  if (!configuration || !loaded || !current) return null;

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
            {current.discount?.percentage && current.discounted_amount
              ? <s-stack gap="small-100">
                  <s-text tone="neutral">Original {current.currency} {current.amount}</s-text>
                  <s-text tone="success">{current.discount.percentage}% off</s-text>
                  <s-text>Now {current.currency} {current.discounted_amount}</s-text>
                  {current.discount.code ? <s-text tone="success">Code {current.discount.code}</s-text> : null}
                </s-stack>
              : <s-text>{current.currency} {current.amount}</s-text>}
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
