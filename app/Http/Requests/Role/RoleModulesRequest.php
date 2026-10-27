<?php

namespace App\Http\Requests\Role;

use App\Models\Module\Module;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Lista completa de módulos del rol: un arreglo vacío le quita todos.
 */
class RoleModulesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'modulos' => ['present', 'array'],
            'modulos.*' => ['integer', 'distinct', Rule::exists(Module::class, 'id_modulo')],
        ];
    }
}
