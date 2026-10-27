<?php

namespace App\Http\Requests\Role;

use App\Models\Role\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Alta y edición de rol. En la edición se ignora el propio rol al validar el nombre único.
 */
class RoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nombre' => [
                'required',
                'string',
                'max:50',
                Rule::unique(Role::class, 'nombre')->ignore($this->route('id'), 'id_rol'),
            ],
        ];
    }
}
