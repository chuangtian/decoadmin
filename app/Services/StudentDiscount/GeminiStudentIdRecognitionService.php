<?php

namespace App\Services\StudentDiscount;

use App\Models\StudentDiscountClaim;
use App\Services\SystemSettingsService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Storage;
use JsonException;

class GeminiStudentIdRecognitionService
{
    private const DOCUMENT_CLASSIFICATION_PROMPT = <<<'PROMPT'
Classify the document type shown in this image and return only the requested structured JSON.

This task is only visual document-type recognition. It is not an authenticity, forgery, validity, ownership, identity-match, enrollment-status, or fraud assessment. Set is_student_id to true when the overall layout looks like a student ID or campus student identity card. Relevant visual features can include a school name or logo and student-card elements such as a person name, portrait photo, or student identifier. Use the overall card layout and any readable combination of those features; do not require every field.

Watermarks, labels, or wording such as "TEST SAMPLE", "NOT VALID", "SAMPLE", "样本", "仿制", or statements that authenticity is uncertain must not cause is_student_id to be false when the image otherwise looks like a student identity card. Do not make is_student_id false because the card may be forged, expired, invalid, not current, not owned by the submitter, because a displayed name might not match a submitted name, or because current enrollment cannot be proven. All of those questions are outside this classification task.

Set is_student_id to false only when the image does not present a recognizable student-card form or is unrelated. Use confidence from 0 to 100 to measure how strongly the image resembles a student identity card and how readable its relevant identity information is; confidence must not measure authenticity or validity. Do not invent unreadable values. Mask any student identifier and never return it in full. Keep review_notes brief, categorical, and free of transcribed document text.
PROMPT;

    public function __construct(
        private HttpFactory $http,
        private SystemSettingsService $settings,
    ) {}

    /** @return array{ok: bool, result: array<string, mixed>|null, confidence: float|null, model: string, failure_code: string|null} */
    public function recognize(StudentDiscountClaim $claim): array
    {
        $configuration = $this->settings->studentAiForServer();
        $model = $configuration['gemini_model'];
        if (blank($configuration['gemini_api_key']) || blank($model) || blank($claim->evidence_path)) {
            return $this->failure($model, 'not_configured');
        }

        try {
            $bytes = Storage::disk((string) $claim->evidence_disk)->get((string) $claim->evidence_path);
            $response = $this->http
                ->acceptJson()
                ->asJson()
                ->withHeaders(['x-goog-api-key' => $configuration['gemini_api_key']])
                ->connectTimeout(5)
                ->timeout(25)
                ->post("https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent", [
                    'contents' => [[
                        'parts' => [
                            ['text' => self::DOCUMENT_CLASSIFICATION_PROMPT],
                            ['inlineData' => ['mimeType' => $claim->evidence_mime, 'data' => base64_encode($bytes)]],
                        ],
                    ]],
                    'generationConfig' => [
                        'responseMimeType' => 'application/json',
                        'responseSchema' => [
                            'type' => 'OBJECT',
                            'properties' => [
                                'is_student_id' => ['type' => 'BOOLEAN'],
                                'institution_name' => ['type' => 'STRING', 'nullable' => true],
                                'student_name' => ['type' => 'STRING', 'nullable' => true],
                                'student_identifier_masked' => ['type' => 'STRING', 'nullable' => true],
                                'expiry_date' => ['type' => 'STRING', 'nullable' => true],
                                'confidence' => ['type' => 'NUMBER'],
                                'review_notes' => ['type' => 'STRING'],
                            ],
                            'required' => ['is_student_id', 'confidence', 'review_notes'],
                        ],
                    ],
                ]);
        } catch (ConnectionException) {
            return $this->failure($model, 'timeout');
        } catch (\Throwable) {
            return $this->failure($model, 'storage_or_transport_error');
        }

        if ($response->failed()) {
            return $this->failure($model, $this->classifyApiFailure($response));
        }

        $text = data_get($response->json(), 'candidates.0.content.parts.0.text');
        if (! is_string($text) || blank($text)) {
            return $this->failure($model, 'missing_content');
        }

        try {
            $result = json_decode($text, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $this->failure($model, 'invalid_json');
        }

        $confidence = is_array($result) ? ($result['confidence'] ?? null) : null;
        $isStudentId = is_array($result) ? ($result['is_student_id'] ?? null) : null;
        if (! is_bool($isStudentId) || ! is_numeric($confidence) || (float) $confidence < 0 || (float) $confidence > 100) {
            return $this->failure($model, 'invalid_confidence');
        }

        return [
            'ok' => true,
            'result' => $result,
            'confidence' => (float) $confidence,
            'model' => $model,
            'failure_code' => null,
        ];
    }

    /** @return array{ok: false, result: null, confidence: null, model: string, failure_code: string} */
    private function failure(string $model, string $code): array
    {
        return ['ok' => false, 'result' => null, 'confidence' => null, 'model' => $model, 'failure_code' => $code];
    }

    private function classifyApiFailure(Response $response): string
    {
        $payload = $response->json();
        $providerStatus = strtoupper((string) data_get($payload, 'error.status', ''));
        $message = mb_strtolower((string) data_get($payload, 'error.message', ''));
        $reasons = collect((array) data_get($payload, 'error.details', []))
            ->map(fn (mixed $detail): string => strtoupper((string) data_get($detail, 'reason', '')))
            ->filter()
            ->all();

        if (in_array('API_KEY_INVALID', $reasons, true)
            || ($providerStatus === 'INVALID_ARGUMENT' && str_contains($message, 'api key'))) {
            return 'invalid_api_key';
        }
        if (in_array($response->status(), [401, 403], true) || $providerStatus === 'PERMISSION_DENIED') {
            return 'permission_denied';
        }
        if ($response->status() === 404
            || $providerStatus === 'NOT_FOUND'
            || (str_contains($message, 'model') && str_contains($message, 'not found'))) {
            return 'model_not_found';
        }
        if ($response->status() === 429 || $providerStatus === 'RESOURCE_EXHAUSTED') {
            return 'rate_limited';
        }

        return 'api_error';
    }
}
