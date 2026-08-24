<?php

namespace App\Http\Requests;

use App\Services\Reputation\ReputationDashboardService;
use Illuminate\Foundation\Http\FormRequest;

class UpsertReputationGoalsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'month' => ['required', 'date_format:Y-m'],
            'targets' => ['required', 'array'],
            'targets.satisfied_reviews' => ['required', 'numeric', 'min:0', 'max:999999999'],
            'targets.reddit_views' => ['required', 'numeric', 'min:0', 'max:999999999'],
            'targets.reddit_comments' => ['required', 'numeric', 'min:0', 'max:999999999'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $targets = collect($this->input('targets', []))
            ->only(ReputationDashboardService::GOAL_METRICS)
            ->all();

        $this->merge(['targets' => $targets]);
    }
}
