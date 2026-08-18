<?php

namespace App\Http\Requests;

use App\Support\CurrentOrganization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $organization = app(CurrentOrganization::class)->require();
        $role = $this->route('role');

        return [
            'name' => ['required', 'string', 'max:100'],
            'slug' => [
                'required',
                'alpha_dash:ascii',
                'max:100',
                Rule::unique('roles')->where('organization_id', $organization->getKey())->ignore($role),
            ],
            'description' => ['nullable', 'string', 'max:2000'],
            'permission_ids' => ['sometimes', 'array'],
            'permission_ids.*' => ['integer', 'distinct', 'exists:permissions,id'],
        ];
    }
}
