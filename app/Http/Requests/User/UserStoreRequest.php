<?php

namespace App\Http\Requests\User;

use App\Models\Role\Role;
use App\Models\User\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * nombres y apellidoPaterno solo son obligatorios si la persona del DNI
 * no está registrada; eso lo comprueba UserData.
 */
class UserStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'dni' => ['required', 'digits:8'],
            'nombres' => ['nullable', 'string', 'max:150'],
            'apellidoPaterno' => ['nullable', 'string', 'max:150'],
            'apellidoMaterno' => ['nullable', 'string', 'max:150'],
            'correo' => ['required', 'email', 'max:150', Rule::unique(User::class, 'correo')],
            'idRol' => ['required', 'integer', Rule::exists(Role::class, 'id_rol')->where('estado', true)],
        ];
    }
}
