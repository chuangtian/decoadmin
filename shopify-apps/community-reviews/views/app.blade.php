<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="shopify-api-key" content="{{ $clientId }}">
    <script src="https://cdn.shopify.com/shopifycloud/app-bridge.js"></script>
    <title>Community Reviews</title>
    <style>body{font:15px/1.6 system-ui;background:#f6f6f7;color:#222;margin:0;padding:40px}main{max-width:680px;margin:auto;background:white;border:1px solid #ddd;border-radius:16px;padding:32px}h1{font-size:26px}a{display:inline-block;padding:12px 22px;border-radius:9px;background:#111;color:white;text-decoration:none;margin-top:16px}[hidden]{display:none}p{color:#555}</style>
</head>
<body>
<main><h1>Community Reviews · 买家秀评价</h1><p>从店铺评论库抽取四星、五星评价，关联在售车型素材，在店铺页面展示循环轮播。</p><p id="status" role="status">正在连接此店铺…</p><a id="manage" hidden target="_blank" rel="noopener">打开 DecoAdmin 配置</a></main>
<script>
(async () => {
    const status = document.getElementById('status');
    try {
        const token = await shopify.idToken();
        const response = await fetch('/api/shopify-app/community-reviews/bootstrap?shop=' + encodeURIComponent(@json($shop)), {method:'POST',headers:{Authorization:'Bearer '+token,Accept:'application/json'}});
        const result = await response.json();
        if (!response.ok || !result.data?.management_url) throw new Error(result.error?.message || '该店铺尚未连接 DecoAdmin，请先在 DecoAdmin 连接店铺。');
        status.textContent = '店铺已连接。前往 DecoAdmin 配置素材和商品，然后在主题编辑器添加 Community Reviews 区块。';
        const link = document.getElementById('manage'); link.href = result.data.management_url; link.hidden = false;
    } catch(error) { status.textContent = error.message || '连接失败，请刷新后重试。'; }
})();
</script>
</body>
</html>
