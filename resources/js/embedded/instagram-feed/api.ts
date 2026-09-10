/**
 * Shopify App Home 内嵌页面的 API 客户端。
 *
 * 不用 Cookie、不用 CSRF：身份是 App Bridge 的 session token，每次请求现取一次。
 * session token 只有一分钟有效期，自己缓存反而会制造大量 401，App Bridge 内部已经
 * 处理了复用，所以这里不做缓存。
 *
 * 服务端的 shopify.id-token 中间件会把查询串里的 shop 与令牌的 dest 做比对，
 * 所以每个请求都要带 shop；它从令牌里取，避免依赖可被改写的页面地址。
 */

interface AppBridge {
    idToken?: () => Promise<string>;
    auth?: { idToken?: () => Promise<string> };
}

declare global {
    interface Window {
        shopify?: AppBridge;
    }
}

export class ApiError extends Error {
    constructor(
        message: string,
        public readonly code: string,
        public readonly status: number,
        public readonly validation: Record<string, string[]> = {},
    ) {
        super(message);
        this.name = 'ApiError';
    }
}

/** 等 App Bridge 就绪。脚本是同步加载的，但 window.shopify 的注入可能晚一拍。 */
async function bridge(): Promise<AppBridge> {
    for (let attempt = 0; attempt < 50; attempt++) {
        const candidate = window.shopify;
        if (candidate && (candidate.idToken || candidate.auth?.idToken)) {
            return candidate;
        }
        await new Promise(resolve => setTimeout(resolve, 100));
    }

    throw new ApiError('请在 Shopify 后台打开本应用后再使用。', 'APP_BRIDGE_UNAVAILABLE', 0);
}

async function sessionToken(): Promise<string> {
    const app = await bridge();
    // 新旧两版 App Bridge 的取法不同，两种都兼容。
    if (app.idToken) {
        return app.idToken();
    }
    if (app.auth?.idToken) {
        return app.auth.idToken();
    }

    throw new ApiError('无法获取 Shopify 会话令牌。', 'APP_BRIDGE_UNAVAILABLE', 0);
}

/** 从 session token 的 dest 取店铺域名。只解码不验签，服务端会独立校验。 */
function shopFromToken(token: string): string {
    const segments = token.split('.');
    if (segments.length !== 3) {
        throw new ApiError('Shopify 会话令牌格式无效。', 'INVALID_SHOPIFY_ID_TOKEN', 401);
    }

    const padded = segments[1].replace(/-/g, '+').replace(/_/g, '/');
    const claims = JSON.parse(atob(padded + '='.repeat((4 - (padded.length % 4)) % 4)));
    const destination = String(claims?.dest ?? '').replace(/^https:\/\//, '').replace(/\/$/, '');
    if (!/^[a-z0-9][a-z0-9-]*\.myshopify\.com$/.test(destination)) {
        throw new ApiError('Shopify 会话令牌缺少店铺信息。', 'INVALID_SHOPIFY_ID_TOKEN', 401);
    }

    return destination;
}

export interface ApiClient {
    get<T>(path: string, query?: Record<string, string>): Promise<T>;
    post<T>(path: string, body?: unknown): Promise<T>;
    put<T>(path: string, body?: unknown): Promise<T>;
    del<T>(path: string, body?: unknown): Promise<T>;
}

export function createApiClient(base: string): ApiClient {
    const send = async <T>(
        method: string,
        path: string,
        body?: unknown,
        query: Record<string, string> = {},
        isRetry = false,
    ): Promise<T> => {
        const token = await sessionToken();
        const url = new URL(base + path, window.location.origin);
        url.searchParams.set('shop', shopFromToken(token));
        for (const [key, value] of Object.entries(query)) {
            url.searchParams.set(key, value);
        }

        const response = await fetch(url.toString(), {
            method,
            // 明确不带 Cookie：内嵌 iframe 里的第三方 Cookie 本就不可靠，
            // 身份完全由 Authorization 头承担。
            credentials: 'omit',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                Authorization: `Bearer ${token}`,
            },
            body: body === undefined ? undefined : JSON.stringify(body),
        });

        // 令牌过期时换一个新的重试一次；再失败就按错误上报，避免无限重试。
        if (response.status === 401 && !isRetry) {
            return send<T>(method, path, body, query, true);
        }

        const payload = await response.json().catch(() => null);

        if (!response.ok) {
            throw new ApiError(
                payload?.error?.message ?? payload?.message ?? '操作失败，请稍后重试。',
                payload?.error?.code ?? 'REQUEST_FAILED',
                response.status,
                payload?.errors ?? {},
            );
        }

        return payload?.data as T;
    };

    return {
        get: (path, query) => send('GET', path, undefined, query),
        post: (path, body) => send('POST', path, body),
        put: (path, body) => send('PUT', path, body),
        del: (path, body) => send('DELETE', path, body),
    };
}
