<?php

namespace App\Http\Controllers;

use App\Services\Codex\CodexMcpToolService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CodexRemoteMcpController extends Controller
{
    private const PROTOCOL_VERSION = '2025-06-18';

    public function __invoke(Request $request, CodexMcpToolService $tools): JsonResponse|Response
    {
        $payload = $request->json()->all();
        if (array_is_list($payload)) {
            $responses = collect($payload)
                ->map(fn ($message) => $this->handle($request, $tools, $message))
                ->filter()
                ->values()
                ->all();

            return $responses === [] ? response('', 202) : $this->json($responses);
        }

        $response = $this->handle($request, $tools, $payload);

        return $response === null ? response('', 202) : $this->json($response);
    }

    /** @return array<string, mixed>|null */
    private function handle(Request $request, CodexMcpToolService $tools, mixed $message): ?array
    {
        if (! is_array($message) || ($message['jsonrpc'] ?? null) !== '2.0' || ! is_string($message['method'] ?? null)) {
            return $this->error(is_array($message) ? ($message['id'] ?? null) : null, -32600, '无效的 MCP 请求。');
        }

        $id = $message['id'] ?? null;
        if (! array_key_exists('id', $message)) {
            return null;
        }
        $params = is_array($message['params'] ?? null) ? $message['params'] : [];

        return match ($message['method']) {
            'initialize' => $this->result($id, [
                'protocolVersion' => self::PROTOCOL_VERSION,
                'capabilities' => ['tools' => ['listChanged' => false]],
                'serverInfo' => ['name' => 'decoadmin', 'version' => '0.3.0'],
            ]),
            'ping' => $this->result($id, (object) []),
            'tools/list' => $this->result($id, ['tools' => $tools->definitions()]),
            'tools/call' => $this->toolCall($request, $tools, $id, $params),
            default => $this->error($id, -32601, '不支持的 MCP 方法。'),
        };
    }

    /** @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function toolCall(Request $request, CodexMcpToolService $tools, mixed $id, array $params): array
    {
        $name = $params['name'] ?? null;
        $arguments = $params['arguments'] ?? [];
        if (! is_string($name) || ! is_array($arguments)) {
            return $this->error($id, -32602, '工具名称或参数无效。');
        }

        return $this->result($id, $tools->call($request, $name, $arguments));
    }

    /** @return array<string, mixed> */
    private function result(mixed $id, mixed $result): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    /** @return array<string, mixed> */
    private function error(mixed $id, int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }

    private function json(array $payload): JsonResponse
    {
        return response()->json($payload, 200, [
            'Cache-Control' => 'no-store',
            'MCP-Protocol-Version' => self::PROTOCOL_VERSION,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
