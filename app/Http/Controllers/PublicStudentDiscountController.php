<?php

namespace App\Http\Controllers;

use App\Exceptions\StudentDiscountException;
use App\Models\StudentDiscountClaim;
use App\Services\StudentDiscount\StudentDiscountClaimService;
use App\Services\StudentDiscount\StudentDiscountCodeService;
use App\Services\StudentDiscount\StudentDiscountPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PublicStudentDiscountController extends Controller
{
    public function __construct(
        private StudentDiscountClaimService $claims,
        private StudentDiscountCodeService $codes,
        private StudentDiscountPresenter $presenter,
    ) {}

    public function store(Request $request): JsonResponse
    {
        try {
            $hasEvidence = $request->hasFile('evidence');
            $values = $request->validate([
                'name' => $hasEvidence
                    ? ['required', 'string', 'min:2', 'max:120', 'regex:/\A[\pL\pM][\pL\pM\pN .,\'’()\-]{1,119}\z/u']
                    : ['sometimes', 'nullable', 'string', 'min:2', 'max:120', 'regex:/\A[\pL\pM][\pL\pM\pN .,\'’()\-]{1,119}\z/u'],
                'email' => ['required', 'email:rfc', 'max:320'],
                'privacy_consent' => $hasEvidence
                    ? ['required', 'accepted']
                    : ['sometimes', 'nullable', 'accepted'],
                'idempotency_key' => ['required', 'string', 'min:8', 'max:120', 'regex:/^[A-Za-z0-9._:-]+$/'],
                'evidence' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            ]);
            $store = $request->attributes->get('student_discount_store');
            $submittedName = $values['name'] ?? null;
            $name = is_string($submittedName)
                ? preg_replace('/\s+/u', ' ', trim($submittedName))
                : null;
            $result = $this->claims->submit(
                $store,
                is_string($name) ? $name : null,
                $values['email'],
                $request->file('evidence'),
                $values['idempotency_key'],
                $request->boolean('privacy_consent'),
            );

            return response()->json([
                'data' => [
                    ...$this->presenter->publicClaim($result['claim'], $result['code']),
                    'claim_token' => $result['claim_token'],
                ],
            ], $result['claim']->status === 'pending' ? 202 : 200);
        } catch (ValidationException $exception) {
            return response()->json(['error' => [
                'code' => 'VALIDATION_FAILED',
                'message' => '提交内容不符合要求。',
                'fields' => $exception->errors(),
            ]], 422);
        } catch (StudentDiscountException $exception) {
            return response()->json(['error' => [
                'code' => $exception->errorCode,
                'message' => $exception->getMessage(),
            ]], $exception->statusCode);
        } catch (\Throwable) {
            return response()->json(['error' => [
                'code' => 'STUDENT_DISCOUNT_UNAVAILABLE',
                'message' => '学生优惠服务暂时不可用，请稍后重试。',
            ]], 503);
        }
    }

    public function info(Request $request): JsonResponse
    {
        $store = $request->attributes->get('student_discount_store');
        $campaign = $store->studentDiscountCampaign;
        $proxyPath = (string) config('student_discount.active.proxy_path');

        return response()->json(['data' => [
            'environment' => (string) config('student_discount.environment'),
            'shop' => $store->shopify_domain,
            'campaign_enabled' => (bool) $campaign?->enabled,
            'proxy_path' => $proxyPath,
            'claim_endpoint' => rtrim($proxyPath, '/').'/claims',
        ]]);
    }

    public function show(Request $request, StudentDiscountClaim $claim): JsonResponse
    {
        $store = $request->attributes->get('student_discount_store');
        if ($claim->store_id !== $store->id) {
            return response()->json(['error' => ['code' => 'CLAIM_NOT_FOUND', 'message' => '未找到该申请。']], 404);
        }

        $token = (string) ($request->query('claim_token') ?: $request->header('X-Student-Claim-Token', ''));
        if (! $this->claims->verifyClaimToken($claim, $token)) {
            return response()->json(['error' => ['code' => 'INVALID_CLAIM_TOKEN', 'message' => '申请查询凭证无效。']], 403);
        }

        $code = $claim->discountCode;
        if ($code) {
            $code = $this->codes->syncUsage($code);
        }

        return response()->json(['data' => $this->presenter->publicClaim($claim, $code)]);
    }
}
