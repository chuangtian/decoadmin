<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="shopify-api-key" content="{{ $clientId }}"><script src="https://cdn.shopify.com/shopifycloud/app-bridge.js"></script>
<script src="https://cdn.shopify.com/shopifycloud/polaris.js"></script><title>Deco Reviews</title></head>
<body><s-page heading="Deco Reviews"><s-section heading="Your review workspace">
<s-paragraph>Manage product and store reviews, moderation, invitations and display settings in DecoAdmin.</s-paragraph>
<s-paragraph id="connection-status">Checking the app connection…</s-paragraph>
<s-link id="management-link" hidden target="_blank">Open review management</s-link>
</s-section></s-page>
<script>
(async () => {
    const message = document.getElementById('connection-status');
    try {
        const token = await shopify.idToken();
        const response = await fetch('/api/shopify-app/deco-reviews/bootstrap?shop=' + encodeURIComponent(@json($shop)), {method:'POST', headers:{Authorization:'Bearer ' + token, Accept:'application/json'}});
        if (!response.ok) throw new Error('connection');
        const result = await response.json();
        const link = document.getElementById('management-link');
        link.href = result.data.management_url; link.hidden = false;
        message.textContent = 'Connected. Sign in to DecoAdmin with your authorized account to manage this store.';
    } catch (_) { message.textContent = 'Connection not available. Check installation permissions and reopen this app.'; }
})();
</script></body></html>
