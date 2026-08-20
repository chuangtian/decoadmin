<!DOCTYPE html>
<html lang="zh-CN">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>@yield('title') · {{ config('app.name', 'DecoAdmin 运营中台') }}</title>
        <style>
            * { box-sizing: border-box; }
            body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 24px; background: #f4f7f9; color: #0f172a; font-family: ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
            main { width: min(560px, 100%); padding: 48px 40px; border: 1px solid #e2e8f0; border-radius: 24px; background: #fff; text-align: center; box-shadow: 0 18px 50px rgba(15, 23, 42, .08); }
            .code { color: #059669; font-size: 14px; font-weight: 700; letter-spacing: .18em; }
            h1 { margin: 14px 0 0; font-size: clamp(28px, 6vw, 42px); line-height: 1.2; }
            p { margin: 18px auto 0; max-width: 430px; color: #64748b; line-height: 1.8; }
            a, button { display: inline-flex; margin-top: 28px; padding: 12px 20px; border: 0; border-radius: 12px; background: #0f172a; color: #fff; font: inherit; font-size: 14px; font-weight: 700; text-decoration: none; cursor: pointer; }
            a:hover, button:hover { background: #1e293b; }
        </style>
    </head>
    <body>
        <main>
            <div class="code">@yield('code')</div>
            <h1>@yield('heading')</h1>
            <p>@yield('message')</p>
            @hasSection('backAction')
                <button type="button" onclick="document.referrer ? window.history.back() : window.location.assign('/')">返回上一页</button>
            @else
                <a href="/">返回管理后台</a>
            @endif
        </main>
    </body>
</html>
