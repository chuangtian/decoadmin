<?php

namespace App\Http\Controllers;

use App\Exceptions\StudentDiscountException;
use App\Models\Store;
use App\Models\StudentDiscountClaim;
use App\Services\StudentDiscount\StudentDiscountClaimService;
use App\Services\StudentDiscount\StudentDiscountCodeService;
use App\Services\StudentDiscount\StudentDiscountEmailTemplateService;
use App\Services\StudentDiscount\StudentDiscountPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class PublicStudentDiscountController extends Controller
{
    public function __construct(
        private StudentDiscountClaimService $claims,
        private StudentDiscountCodeService $codes,
        private StudentDiscountEmailTemplateService $emailTemplates,
        private StudentDiscountPresenter $presenter,
    ) {}

    public function retryPage(Request $request): Response
    {
        $store = $request->attributes->get('student_discount_store');

        if (! $store->studentDiscountCampaign?->enabled) {
            return $this->retryPageResponse($store, [
                'state' => 'error',
                'message' => 'Student discount verification is currently unavailable for this store.',
            ]);
        }

        return $this->retryPageResponse($store, [
            'state' => 'form',
            'submittedName' => '',
            'submittedEmail' => '',
            'errors' => [],
        ]);
    }

    public function retryStore(Request $request): Response
    {
        $store = $request->attributes->get('student_discount_store');
        if (! $store->studentDiscountCampaign?->enabled) {
            return $this->retryPageResponse($store, [
                'state' => 'error',
                'message' => 'Student discount verification is currently unavailable for this store.',
            ]);
        }

        $validator = Validator::make($request->all(), [
            'full_name' => ['required', 'string', 'min:2', 'max:120', 'regex:/\A[\pL\pM][\pL\pM\pN .,\'’()\-]{1,119}\z/u'],
            'email' => ['required', 'email:rfc', 'max:320'],
            'privacy_consent' => ['required', 'accepted'],
            'evidence' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'website' => ['nullable', 'max:0'],
        ], [
            'full_name.required' => 'Please enter your full name.',
            'full_name.regex' => 'Please enter a valid full name.',
            'email.required' => 'Please enter your email address.',
            'email.email' => 'Please enter a valid email address.',
            'privacy_consent.accepted' => 'Please agree to the Privacy Policy and Terms of Service.',
            'evidence.required' => 'Please upload a student ID image.',
            'evidence.mimes' => 'Please upload a JPG, PNG or WebP image.',
            'evidence.max' => 'The student ID image must be 5MB or smaller.',
            'website.max' => 'Unable to submit this request.',
        ]);

        if ($validator->fails()) {
            return $this->retryPageResponse($store, [
                'state' => 'form',
                'submittedName' => (string) $request->input('full_name', ''),
                'submittedEmail' => (string) $request->input('email', ''),
                'errors' => $validator->errors()->all(),
            ]);
        }

        try {
            $result = $this->claims->submit(
                $store,
                (string) $validator->validated()['full_name'],
                (string) $validator->validated()['email'],
                $request->file('evidence'),
                'verification-page-'.Str::uuid()->toString(),
                true,
            );

            return $this->retryPageResponse($store, [
                'state' => 'success',
                'message' => $result['code']
                    ? 'Your student discount has been approved. Check your email for the discount code.'
                    : 'Your request has been submitted. We will email you when the review is complete.',
            ]);
        } catch (StudentDiscountException) {
            return $this->retryPageResponse($store, [
                'state' => 'form',
                'submittedName' => (string) $request->input('full_name', ''),
                'submittedEmail' => (string) $request->input('email', ''),
                'errors' => ['We could not submit your request. Please check your information and try again.'],
            ]);
        } catch (\Throwable) {
            return $this->retryPageResponse($store, [
                'state' => 'form',
                'submittedName' => (string) $request->input('full_name', ''),
                'submittedEmail' => (string) $request->input('email', ''),
                'errors' => ['The student discount service is temporarily unavailable. Please try again later.'],
            ]);
        }
    }

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
            'support_page_url' => $campaign ? $this->emailTemplates->supportPageUrl($campaign, $store) : null,
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

    /** @param array<string, mixed> $data */
    private function retryPageResponse(Store $store, array $data): Response
    {
        $campaign = $store->studentDiscountCampaign;
        $branding = $campaign
            ? (array) data_get($this->emailTemplates->configuration($campaign, $store), 'branding', [])
            : [];
        $primaryColor = (string) ($branding['primary_color'] ?? '#111111');
        if (preg_match('/^#[0-9A-Fa-f]{6}$/', $primaryColor) !== 1) {
            $primaryColor = '#111111';
        }

        return response()->view('student-discounts.retry', [
            'storeName' => $store->name,
            'storeUrl' => 'https://'.$store->shopify_domain,
            'formAction' => '/'.trim((string) config('student_discount.active.proxy_path'), '/').'/verify/claims',
            'logoUrl' => (string) ($branding['logo_url'] ?? ''),
            'primaryColor' => $primaryColor,
            'state' => 'error',
            'message' => 'Student discount verification is currently unavailable.',
            'errors' => [],
            'submittedName' => '',
            'submittedEmail' => '',
            ...$data,
        ], 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
            'Pragma' => 'no-cache',
            'X-Robots-Tag' => 'noindex, nofollow',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }
}
