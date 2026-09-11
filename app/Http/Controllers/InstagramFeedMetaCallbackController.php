<?php

namespace App\Http\Controllers;

use App\Exceptions\InstagramFeedException;
use App\Services\InstagramFeed\InstagramAccountService;
use App\Services\InstagramFeed\InstagramFeedStoreCredentials;
use App\Services\InstagramFeed\MetaOAuthStateService;
use App\Services\InstagramFeed\MetaSignedRequestValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Throwable;

/**
 * Meta 侧的公开回调。
 *
 * 这些路由没有 DecoAdmin 会话：OAuth 回调靠一次性 state 还原店铺，
 * 解除授权与数据删除回调靠 signed_request 验签。
 */
class InstagramFeedMetaCallbackController extends Controller
{
    public function __construct(
        private MetaOAuthStateService $states,
        private InstagramAccountService $accounts,
        private MetaSignedRequestValidator $signedRequests,
        private InstagramFeedStoreCredentials $credentials,
    ) {}

    /**
     * Instagram Login 与 Facebook Login 共用的授权回调。
     *
     * URL 里用短名（instagram / facebook）保持回调地址简洁，内部再映射成
     * provider 键。回调地址必须与 Meta 后台登记的完全一致，所以路径不能改。
     */
    public function oauthCallback(Request $request, string $provider): Response
    {
        $provider = $provider === 'facebook' ? 'facebook_login' : 'instagram_login';

        $denied = $request->query('error_description')
            ?? $request->query('error_message')
            ?? $request->query('error');
        if (is_string($denied) && $denied !== '') {
            return $this->popup('授权未完成', str_replace('+', ' ', $denied), false);
        }

        try {
            $this->states->assertProvider($provider);
            $consumed = $this->states->consume((string) $request->query('state'));
            if ($consumed['provider'] !== $provider) {
                throw new InstagramFeedException('OAUTH_PROVIDER_MISMATCH', '授权方式与请求不一致，请重新发起连接。', 400);
            }

            $code = $request->query('code');
            if (! is_string($code) || $code === '' || mb_strlen($code) > 2048) {
                throw new InstagramFeedException('OAUTH_CODE_MISSING', '未收到授权码，请重试一次。', 400);
            }

            // 换 token 要用发起授权的那个店铺自己的 Meta 应用密钥，所以必须在
            // state 还原出店铺之后、调用 Graph API 之前加载它的凭证。
            $this->credentials->apply($consumed['store']);

            $result = $provider === 'facebook_login'
                ? $this->accounts->completeFacebookLogin($consumed['store'], $consumed['user'], $code)
                : $this->accounts->completeInstagramLogin($consumed['store'], $consumed['user'], $code);
        } catch (InstagramFeedException $exception) {
            return $this->popup('连接失败', $exception->getMessage(), false);
        } catch (Throwable) {
            return $this->popup('连接失败', '请稍后重试；如果问题持续，请联系 DecoAdmin 管理员。', false);
        }

        if ($result['needs_page_selection']) {
            return $this->popup(
                '授权成功，还要选一个账号',
                '你有多个主页关联了 Instagram 账号，关掉这个窗口后回到应用页面选择要展示的那个。',
                true,
            );
        }

        return $this->popup(
            '已连接 @'.(string) $result['account']->username,
            '关掉这个窗口后回到应用页面，点「同步内容」就能把媒体拉进来。',
            true,
        );
    }

    /**
     * Meta 的 Deauthorize callback：用户在 Instagram / Facebook 侧移除了本应用。
     *
     * 验签失败一律返回 200 但不做任何处理，不透露账号是否存在。
     */
    public function deauthorize(Request $request): Response
    {
        $payload = $this->signedRequests->parse((string) $request->input('signed_request'));
        if ($payload !== null) {
            $this->accounts->purgeByMetaUser($payload['user_id']);
        }

        return response('ok', 200);
    }

    /**
     * Meta 的 Data deletion request callback。
     *
     * 规范要求返回 { url, confirmation_code }。删除是同步完成的，所以状态页
     * 直接显示「已删除」。
     */
    public function dataDeletion(Request $request): JsonResponse
    {
        $payload = $this->signedRequests->parse((string) $request->input('signed_request'));
        if ($payload === null) {
            return response()->json(['error' => 'invalid signed_request'], 400);
        }

        $this->accounts->purgeByMetaUser($payload['user_id']);

        // 确认码只用于让用户回查进度，不含任何用户数据。
        $confirmationCode = substr(hash('sha256', $payload['user_id'].':'.now()->getTimestampMs()), 0, 16);

        return response()->json([
            'url' => route('instagram-feed.meta.data-deletion.status', ['code' => $confirmationCode]),
            'confirmation_code' => $confirmationCode,
        ]);
    }

    /** 数据删除进度查询页。 */
    public function dataDeletionStatus(Request $request): Response
    {
        $code = (string) $request->query('code', '');
        $safeCode = preg_replace('/[^a-f0-9]/i', '', $code) ?? '';

        return $this->page(
            '数据删除请求已完成',
            '与该 Instagram 账号相关的授权信息和媒体缓存已从本应用删除。',
            $safeCode === '' ? '' : '确认码：'.$safeCode,
        );
    }

    /** Meta 有时会先 GET 探测一下回调地址。 */
    public function probe(): Response
    {
        return response('ok', 200);
    }

    /**
     * 第三方授权在新窗口里完成，回调页只需要：告诉用户结果、通知父窗口刷新、成功后自动关闭。
     */
    private function popup(string $heading, string $message, bool $ok): Response
    {
        $safeHeading = e($heading);
        $safeMessage = e($this->publicMessage($message));
        $dotColor = $ok ? '#008060' : '#d72c0d';
        $flag = $ok ? 'true' : 'false';
        $autoClose = $ok ? 'setTimeout(function () { window.close(); }, 1500);' : '';

        $html = <<<HTML
            <!DOCTYPE html>
            <html lang="zh-CN">
              <head>
                <meta charset="utf-8">
                <meta name="viewport" content="width=device-width,initial-scale=1">
                <title>{$safeHeading}</title>
                <style>
                  body { font-family: -apple-system, "Segoe UI", Roboto, sans-serif; margin: 0; padding: 48px 24px; background: #f6f6f7; color: #202223; }
                  .card { max-width: 420px; margin: 0 auto; background: #fff; border-radius: 12px; padding: 24px; box-shadow: 0 1px 4px rgba(0,0,0,.08); }
                  h1 { font-size: 18px; margin: 0 0 8px; }
                  p { font-size: 14px; line-height: 1.5; margin: 0 0 16px; color: #616161; word-break: break-word; }
                  .dot { display: inline-block; width: 8px; height: 8px; border-radius: 50%; margin-right: 8px; background: {$dotColor}; }
                  button { font: inherit; padding: 8px 16px; border-radius: 8px; border: 1px solid #babfc3; background: #fff; cursor: pointer; }
                </style>
              </head>
              <body>
                <div class="card">
                  <h1><span class="dot"></span>{$safeHeading}</h1>
                  <p>{$safeMessage}</p>
                  <button type="button" onclick="window.close()">关闭窗口</button>
                </div>
                <script>
                  try {
                    if (window.opener) {
                      window.opener.postMessage({ type: 'instagram-feed-auth', ok: {$flag} }, window.location.origin);
                    }
                  } catch (error) {}
                  {$autoClose}
                </script>
              </body>
            </html>
            HTML;

        return response($html, $ok ? 200 : 400, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    private function page(string $heading, string $message, string $note): Response
    {
        $safeHeading = e($heading);
        $safeMessage = e($message);
        $safeNote = $note === '' ? '' : '<p>'.e($note).'</p>';

        $html = <<<HTML
            <!DOCTYPE html>
            <html lang="zh-CN">
              <head>
                <meta charset="utf-8">
                <meta name="viewport" content="width=device-width,initial-scale=1">
                <title>{$safeHeading}</title>
                <style>
                  body { font-family: -apple-system, "Segoe UI", Roboto, sans-serif; margin: 0; padding: 48px 24px; background: #f6f6f7; color: #202223; }
                  .card { max-width: 480px; margin: 0 auto; background: #fff; border-radius: 12px; padding: 24px; }
                  h1 { font-size: 18px; margin: 0 0 12px; }
                  p { font-size: 14px; line-height: 1.6; color: #616161; margin: 0 0 8px; }
                </style>
              </head>
              <body>
                <div class="card">
                  <h1>{$safeHeading}</h1>
                  <p>{$safeMessage}</p>
                  {$safeNote}
                </div>
              </body>
            </html>
            HTML;

        return response($html, 200, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    /** 回调页面向公网，过长或空的内部消息一律替换成通用提示。 */
    private function publicMessage(string $message): string
    {
        $value = trim($message);

        return $value === '' || mb_strlen($value) > 240
            ? '请稍后重试；如果问题持续，请联系 DecoAdmin 管理员。'
            : $value;
    }
}
