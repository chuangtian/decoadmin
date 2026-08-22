<?php

namespace App\Http\Requests;

use App\Support\CurrentOrganization;
use App\Support\CurrentStore;
use Illuminate\Foundation\Http\FormRequest;

class DestroyPaidAdvertisingGoalBoardRequest extends FormRequest
{
    public function authorize(): bool
    {
        $organization = app(CurrentOrganization::class)->get();
        $store = app(CurrentStore::class)->get();

        return $organization !== null
            && $store !== null
            && ($this->user()?->hasPermission('store.update', $organization, $store) ?? false);
    }

    public function rules(): array
    {
        return [
            'confirmed' => ['required', 'accepted'],
        ];
    }
}
