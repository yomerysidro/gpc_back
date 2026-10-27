<?php

namespace App\Http\Requests\User;

use App\Models\Role\Role;
use App\Models\User\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UserUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
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
            'idRol' => ['required', 'integer', Rule::exists(Role::class, 'id_rol')->where('estado', true)],
        ];
    }
}
