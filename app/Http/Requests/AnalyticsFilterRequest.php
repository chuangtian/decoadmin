<?php

namespace App\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class AnalyticsFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'days' => ['nullable', 'integer', 'min:1', 'max:366'],
            'date_from' => ['nullable', 'required_with:date_to', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'required_with:date_from', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'include_test' => ['nullable', 'boolean'],
            'include_cancelled' => ['nullable', 'boolean'],
            'comparison' => ['nullable', 'in:none,previous,year,year_weekday,custom'],
            'comparison_date_from' => ['nullable', 'required_if:comparison,custom', 'date_format:Y-m-d'],
            'comparison_date_to' => ['nullable', 'required_if:comparison,custom', 'date_format:Y-m-d', 'after_or_equal:comparison_date_from'],
            'report_type' => ['nullable', 'in:sales,products,customers,inventory'],
            'metric' => ['nullable', 'string', 'max:64', 'regex:/^[a-z0-9_]+$/'],
            'dimension' => ['nullable', 'string', 'max:64', 'regex:/^[a-z0-9_]+$/'],
            'visualization' => ['nullable', 'in:line,bar,donut,table'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($this->filled(['date_from', 'date_to'])) {
                $from = CarbonImmutable::parse((string) $this->input('date_from'));
                $to = CarbonImmutable::parse((string) $this->input('date_to'));
                if ($from->diffInDays($to) > 365) {
                    $validator->errors()->add('date_to', '自定义统计范围最多为 366 天。');
                }
            }

            if ($this->filled(['comparison_date_from', 'comparison_date_to'])) {
                $from = CarbonImmutable::parse((string) $this->input('comparison_date_from'));
                $to = CarbonImmutable::parse((string) $this->input('comparison_date_to'));
                if ($from->diffInDays($to) > 365) {
                    $validator->errors()->add('comparison_date_to', '自定义对比范围最多为 366 天。');
                }
            }
        }];
    }

    /** @return array<string, mixed> */
    public function filters(): array
    {
        return $this->safe()->only([
            'days', 'date_from', 'date_to', 'include_test', 'include_cancelled',
            'comparison', 'comparison_date_from', 'comparison_date_to',
            'report_type', 'metric', 'dimension', 'visualization',
        ]);
    }
}
