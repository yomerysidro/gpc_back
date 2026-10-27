<?php

namespace App\Http\Requests\Role;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Lista completa de permisos del rol: un arreglo vacío le quita todos.
 * Que cada permiso pertenezca a su módulo y que el módulo esté asignado
 * al rol se valida en RoleData, dentro de la transacción.
 */
class RolePermissionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'permisos' => ['present', 'array'],
            'permisos.*.idModulo' => ['required', 'integer'],
            'permisos.*.idPermiso' => ['required', 'integer'],
        ];
    }
}
