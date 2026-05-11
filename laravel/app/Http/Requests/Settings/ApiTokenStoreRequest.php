<?php

namespace App\Http\Requests\Settings;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ApiTokenStoreRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Name is purely a human label so the operator can tell their
            // CLI tokens apart from their CI tokens; 1–80 chars is enough
            // for that without bloating the table. Uniqueness per-user
            // keeps the list intelligible — duplicate names on the
            // same account would make the "Revoke" UX ambiguous.
            'name' => [
                'required',
                'string',
                'max:80',
                Rule::unique('personal_access_tokens', 'name')
                    ->where('tokenable_type', $this->user()::class)
                    ->where('tokenable_id', $this->user()->id),
            ],
        ];
    }
}
