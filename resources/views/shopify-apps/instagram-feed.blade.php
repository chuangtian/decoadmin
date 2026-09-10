<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Instagram 内容</title>

        {{--
            App Bridge 必须是页面里第一个脚本，否则 Shopify 不认这是内嵌应用。
            data-api-key 是公开的 client id，不是机密。
        --}}
        <script src="https://cdn.shopify.com/shopifycloud/app-bridge.js" data-api-key="{{ $clientId }}"></script>

        @vite('resources/js/embedded/instagram-feed.ts')
    </head>
    <body class="antialiased">
        {{--
            壳页面不带任何店铺数据：身份要等 App Bridge 拿到 session token 之后，
            由前端带着 Bearer 调 /api/shopify-app/instagram-feed/* 才能确定。
            这样即使有人直接打开这个地址，也拿不到任何店铺信息。
        --}}
        <div
            id="instagram-feed-embedded"
            data-api-base="{{ $apiBase }}"
            data-environment="{{ $environment }}"
        ></div>
    </body>
</html>
