import '@shopify/ui-extensions/preact';
import {render} from 'preact';
import {useEffect, useState} from 'preact/hooks';
import {fetchConfiguration} from './runtime.mjs';

export default function extension() {
  render(<TrustBadges />, document.body);
}

function TrustBadges() {
  const [configuration, setConfiguration] = useState(null);

  useEffect(() => {
    let active = true;
    fetchConfiguration(shopify)
      .then((value) => active && setConfiguration(value))
      .catch(() => active && setConfiguration(null));
    return () => { active = false; };
  }, []);

  if (!configuration) return null;

  return (
    <s-grid gridTemplateColumns={`repeat(${Math.min(configuration.trust_items.length, 3)}, 1fr)`} gap="base">
      {configuration.trust_items.map((item) => (
        <s-stack key={item.key} alignItems="center" gap="small-200">
          <s-icon type={item.icon} size="large" tone="neutral" />
          <s-heading>{item.title}</s-heading>
          {item.description ? <s-text type="small">{item.description}</s-text> : null}
        </s-stack>
      ))}
    </s-grid>
  );
}
