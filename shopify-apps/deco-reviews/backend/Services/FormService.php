<?php

namespace DecoReviews\Services;

use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use DecoReviews\Models\ReviewForm;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class FormService
{
    public function __construct(private ReviewService $reviews) {}

    public static function defaults(): array
    {
        return ['version' => 'initial', 'heading' => 'Share your experience',
            'description' => 'All ratings are welcome. Please share your honest experience.',
            'name_label' => 'Name', 'title_label' => 'Review title', 'body_label' => 'Your review',
            'submit_label' => 'Submit review', 'thank_you' => 'Thank you. Your review has been received.',
            'allow_photos' => true, 'allow_video' => true, 'questions' => []];
    }

    public function configuration(Store $store): array
    {
        $this->reviews->active($store);
        $row = ReviewForm::where('organization_id', $store->organization_id)->where('store_id', $store->id)->first();

        return array_replace(self::defaults(), $row?->configuration ?? [], ['version' => $row?->version ?? 'initial']);
    }

    public function save(Store $store, User $user, array $input): void
    {
        $this->reviews->authorize($user, $store, true);
        $data = Validator::make($input, [
            'version' => 'required|string|max:36', 'heading' => 'required|string|max:160',
            'description' => 'nullable|string|max:300', 'name_label' => 'required|string|max:160',
            'title_label' => 'required|string|max:160', 'body_label' => 'required|string|max:160',
            'submit_label' => 'required|string|max:160', 'thank_you' => 'required|string|max:300',
            'allow_photos' => 'required|boolean', 'allow_video' => 'required|boolean',
            'questions' => 'present|array|list|max:10', 'questions.*' => 'array:id,label,type,required,public,kind,product_ids,options,min,max',
            'questions.*.id' => 'required|uuid|distinct', 'questions.*.label' => 'required|string|max:160',
            'questions.*.type' => ['required', Rule::in(['single', 'multiple', 'scale'])],
            'questions.*.required' => 'required|boolean', 'questions.*.public' => 'required|boolean',
            'questions.*.kind' => ['required', Rule::in(['all', 'product', 'store'])],
            'questions.*.product_ids' => 'present|array|list|max:100', 'questions.*.product_ids.*' => 'integer|min:1',
            'questions.*.options' => 'present|array|list|max:20', 'questions.*.options.*' => 'required|string|max:100',
            'questions.*.min' => 'required|integer|between:1,10', 'questions.*.max' => 'required|integer|between:1,10',
        ])->validate();
        $allProducts = [];
        foreach ($data['questions'] as $index => &$question) {
            $question['options'] = array_map('trim', $question['options']);
            $question['product_ids'] = array_values(array_unique(array_map('intval', $question['product_ids'])));
            $question['public'] = (bool) $question['public'];
            $question['required'] = (bool) $question['required'];
            if ($question['type'] === 'scale') {
                if ($question['min'] >= $question['max']) {
                    throw ValidationException::withMessages(["questions.{$index}.max" => '量表最大值必须大于最小值。']);
                }
                $question['options'] = [];
            } elseif (count($question['options']) < 2 || count(array_unique($question['options'])) !== count($question['options']) || in_array('', $question['options'], true)) {
                throw ValidationException::withMessages(["questions.{$index}.options" => '至少需要两个不重复的选项。']);
            }
            if ($question['kind'] === 'store' && $question['product_ids'] !== []) {
                throw ValidationException::withMessages(["questions.{$index}.product_ids" => '店铺评价问题不能限定商品。']);
            }
            $allProducts = array_merge($allProducts, $question['product_ids']);
        }
        unset($question);
        $allProducts = array_values(array_unique($allProducts));
        if (Product::where('organization_id', $store->organization_id)->where('store_id', $store->id)->whereIn('id', $allProducts)->count() !== count($allProducts)) {
            throw ValidationException::withMessages(['questions' => '问题范围中的商品必须属于当前店铺。']);
        }
        DB::transaction(function () use ($store, $user, $data) {
            Store::whereKey($store->id)->lockForUpdate()->firstOrFail();
            $current = $this->configuration($store);
            if ($data['version'] !== $current['version']) {
                throw ValidationException::withMessages(['version' => '表单已被更新，请刷新后再修改，避免覆盖他人的设置。']);
            }
            unset($data['version']);
            ReviewForm::updateOrCreate(['organization_id' => $store->organization_id, 'store_id' => $store->id], [
                'version' => (string) Str::uuid(), 'configuration' => $data,
            ]);
            $this->reviews->audit($store, $user, 'form.updated', null, ['question_count' => count($data['questions'])]);
        });
    }

    public function forProduct(Store $store, string $kind, ?int $productId): array
    {
        $config = $this->configuration($store);
        $config['questions'] = array_values(array_filter($config['questions'], function ($question) use ($kind, $productId) {
            return in_array($question['kind'], ['all', $kind], true)
                && ($question['product_ids'] === [] || ($kind === 'product' && in_array($productId, $question['product_ids'], true)));
        }));

        return $config;
    }

    /** Capture immutable labels and visibility; never serialize a raw client answers object. */
    public function snapshot(Store $store, string $kind, ?int $productId, array $input, array $files): array
    {
        $form = $this->forProduct($store, $kind, $productId);
        if ($form['version'] !== 'initial' && ($input['form_version'] ?? '') !== $form['version']) {
            throw ValidationException::withMessages(['form_version' => 'The review form has changed. Please refresh before submitting.']);
        }
        foreach ($files as $file) {
            $video = str_starts_with($file->getMimeType(), 'video/');
            if (($video && ! $form['allow_video']) || (! $video && ! $form['allow_photos'])) {
                throw ValidationException::withMessages(['media' => 'This media type is not enabled for this review form.']);
            }
        }
        $answers = $input['answers'] ?? [];
        Validator::make(['answers' => $answers], ['answers' => 'array|max:10'])->validate();
        if (array_diff(array_keys($answers), array_column($form['questions'], 'id'))) {
            throw ValidationException::withMessages(['answers' => 'An answer does not belong to this review form.']);
        }
        $snapshot = [];
        foreach ($form['questions'] as $question) {
            $key = $question['id'];
            $value = $answers[$key] ?? null;
            if ($value === null || $value === '' || $value === []) {
                if ($question['required']) {
                    throw ValidationException::withMessages(["answers.{$key}" => 'Please answer: '.$question['label']]);
                }

                continue;
            }
            $rules = match ($question['type']) {
                'single' => ["answers.{$key}" => ['required', 'string', Rule::in($question['options'])]],
                'multiple' => ["answers.{$key}" => 'required|array|list|min:1|max:20', "answers.{$key}.*" => ['required', 'string', 'distinct', Rule::in($question['options'])]],
                'scale' => ["answers.{$key}" => ['required', 'integer', 'min:'.$question['min'], 'max:'.$question['max']]],
            };
            Validator::make(['answers' => $answers], $rules)->validate();
            $snapshot[] = ['question_id' => $key, 'label' => $question['label'], 'type' => $question['type'],
                'value' => is_array($value) ? array_values($value) : (string) $value, 'public' => $question['public'] === true];
        }

        return $snapshot;
    }
}
