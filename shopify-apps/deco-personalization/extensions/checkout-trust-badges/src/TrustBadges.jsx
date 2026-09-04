import '@shopify/ui-extensions/preact';
import {useAppMetafields} from '@shopify/ui-extensions/checkout/preact';
import {render} from 'preact';
import {useEffect, useState} from 'preact/hooks';
import {fetchConfiguration} from './runtime.mjs';

const CONFIGURATION_METAFIELD_FILTER = Object.freeze({
  namespace: '$app:deco_personalization',
  key: 'checkout_configuration_url',
  type: 'shop',
});

export default function extension() {
  render(<TrustBadges />, document.body);
}

function TrustBadges() {
  const [configuration, setConfiguration] = useState(null);
  const configurationMetafields = useAppMetafields(CONFIGURATION_METAFIELD_FILTER);

  useEffect(() => {
    let active = true;
    fetchConfiguration(shopify, configurationMetafields)
      .then((value) => active && setConfiguration(value))
      .catch(() => active && setConfiguration(null));
    return () => { active = false; };
  }, [configurationMetafields]);

  if (!configuration) return null;

  return (
    <s-section>
      <s-grid
        accessibilityLabel="Checkout trust information"
        gridTemplateColumns={`repeat(${Math.min(configuration.trust_items.length, 3)}, 1fr)`}
        gap="base"
      >
        {configuration.trust_items.map((item) => (
          <s-stack key={item.key} alignItems="center" gap="small-200">
            <s-icon type={item.icon} size="large" tone="neutral" />
            <s-heading>{item.title}</s-heading>
            {item.description ? <s-text type="small">{item.description}</s-text> : null}
          </s-stack>
        ))}
      </s-grid>
    </s-section>
  );
}
