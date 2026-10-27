<?php

namespace App\Http\Requests\Permission;

use App\Models\Permission\Permission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Alta (POST /modulos/{id}/permisos) y edición (PUT /permisos/{id}).
 * Solo en la edición el {id} de la ruta es el del permiso.
 */
class PermissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $unique = Rule::unique(Permission::class, 'clave');

        if ($this->isMethod('put')) {
            $unique->ignore($this->route('id'), 'id_permiso');
        }

        return [
            'nombre' => ['required', 'string', 'max:50'],
            'clave' => ['required', 'string', 'max:50', $unique],
            'valor' => ['nullable', 'string', 'max:50'],
            'comentario' => ['nullable', 'string', 'max:50'],
        ];
    }
}
