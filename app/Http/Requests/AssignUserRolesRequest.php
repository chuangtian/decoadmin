<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AssignUserRolesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'role_ids' => ['required_without:assignments', 'array'],
            'role_ids.*' => ['integer', 'distinct', 'exists:roles,id'],
            'assignments' => ['required_without:role_ids', 'array'],
            'assignments.*.role_id' => ['required', 'integer', 'exists:roles,id'],
            'assignments.*.store_id' => ['nullable', 'integer', 'exists:stores,id'],
        ];
    }
}
