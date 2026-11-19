<?php

namespace App\Http\Requests\User;

use App\Models\Role\Role;
use App\Models\User\User;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UserUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return ! $this->filled('password') || $this->user()?->isSystemAdministrator() === true;
    }

    public function rules(): array
    {
        return [
            'correo' => [
                'required',
                'email',
                'max:150',
                Rule::unique(User::class, 'correo')->ignore($this->route('id'), 'id_usuario'),
            ],
            'idRol' => ['sometimes', 'required', 'integer', Rule::exists(Role::class, 'id_rol')->where('estado', true)],
            'password' => [
                'bail', 'nullable', 'required_with:password_confirmation', 'string', 'min:8', 'confirmed',
                function (string $attribute, string $value, Closure $fail): void {
                    if (strlen($value) > 72) {
                        $fail('La contraseña supera el límite de 72 bytes.');
                    }
                },
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'correo.unique' => 'Este correo ya está asociado a otro usuario.',
            'password.min' => 'La contraseña debe tener al menos 8 caracteres.',
            'password.confirmed' => 'Las contraseñas no coinciden.',
            'password.required_with' => 'Ingresa la nueva contraseña.',
        ];
    }
}
