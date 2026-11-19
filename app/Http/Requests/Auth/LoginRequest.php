<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * El body usa "email"; internamente se busca por la columna correo.
 */
class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'turnstileToken' => [config('services.turnstile.enabled') ? 'required' : 'nullable', 'string', 'max:2048'],
        ];
    }

    public function messages(): array
    {
        return ['turnstileToken.required' => 'Completa la verificación de seguridad para continuar.'];
    }
}
