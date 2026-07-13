<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class CompleteCustomerAccountClaimRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'password' => ['nullable', 'string', 'min:10', 'max:255', 'confirmed'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'password.min' => 'Hasło musi mieć co najmniej 10 znaków.',
            'password.max' => 'Hasło może mieć maksymalnie 255 znaków.',
            'password.confirmed' => 'Powtórzone hasło nie jest takie samo.',
        ];
    }
}
