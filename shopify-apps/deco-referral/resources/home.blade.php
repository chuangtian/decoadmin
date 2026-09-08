<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="shopify-api-key" content="{{ $clientId }}">
    <title>Deco Referral Test</title>
    <script src="https://cdn.shopify.com/shopifycloud/app-bridge.js"></script>
    <script src="https://cdn.shopify.com/shopifycloud/polaris-1.js"></script>
</head>
<body>
<s-page heading="推荐与联盟 · 测试版">
    <s-section heading="应用连接">
        <s-paragraph id="status">正在验证 Shopify 店铺身份……</s-paragraph>
        <s-button id="connect">重新连接</s-button>
    </s-section>
    <s-section heading="管理推荐与联盟">
        <s-paragraph>在 DecoAdmin 中管理计划、推广员和推广链接。打开后台后需要使用有权访问当前店铺的账号登录。</s-paragraph>
        <s-link href="{{ route('affiliate.shopify.management', ['shop' => $shop]) }}" target="_blank">打开 DecoAdmin 后台</s-link>
    </s-section>
    <s-section heading="当前测试范围">
        <s-paragraph>仅限 macfox-test-app。优惠码同步、订单归因和佣金结算尚未完成，当前版本不可用于正式推广。</s-paragraph>
    </s-section>
</s-page>
<script>
    const endpoint = {{ Illuminate\Support\Js::from(route('affiliate.shopify.bootstrap', ['shop' => $shop])) }};
    const configured = {{ Illuminate\Support\Js::from($clientId !== '') }};
    const status = document.getElementById('status');
    const button = document.getElementById('connect');
    async function connect() {
        button.disabled = true;
        status.textContent = '正在验证 Shopify 店铺身份……';
        try {
            if (!configured || !window.shopify) {
                status.textContent = '测试应用尚未配置完成，请联系管理员。';
                return;
            }
            const token = await shopify.idToken();
            const response = await fetch(endpoint, {
                method: 'POST',
                headers: {Authorization: `Bearer ${token}`, Accept: 'application/json'},
            });
            const result = await response.json();
            status.textContent = response.ok
                ? '已连接 macfox-test-app，独立应用授权验证通过。'
                : (result.error?.message || '连接未成功，请稍后重试。');
        } catch {
            status.textContent = '连接未成功，请从 Shopify 后台重新打开应用后重试。';
        } finally {
            button.disabled = false;
        }
    }
    button.addEventListener('click', connect);
    connect();
</script>
</body>
</html>
