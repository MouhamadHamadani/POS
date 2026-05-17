<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProfileUpdateRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'username' => [
                'required', 'string', 'max:60',
                Rule::unique(User::class)->ignore($this->user()->id),
            ],
            'email' => [
                'nullable', 'string', 'email', 'max:255',
                Rule::unique(User::class)->ignore($this->user()->id),
            ],
            'language' => ['required', 'in:en,ar'],
        ];
    }
}
