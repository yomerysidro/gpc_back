<?php

namespace App\Http\Requests\Permission;

use App\Models\Module\Module;
use App\Models\Permission\Permission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Alta (POST /modulos/{id}/permisos) y edición (PUT /permisos/{id}).
 * Solo en la edición el {id} de la ruta es el del permiso.
 *
 * La clave debe empezar con el slug de su módulo ("reportes.ver" en /reportes).
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
            'clave' => ['required', 'string', 'max:50', 'regex:/^[a-z0-9]+(-[a-z0-9]+)*\.[a-z0-9]+(-[a-z0-9]+)*$/', $unique],
            'valor' => ['nullable', 'string', 'max:50'],
            'comentario' => ['nullable', 'string', 'max:50'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                // Si la clave ya falló (formato, única...) o el módulo no existe, no hay prefijo que comparar: el 404 lo da el controller.
                if ($validator->errors()->has('clave') || ! $module = $this->module()) {
                    return;
                }

                $slug = $module->slug();

                if (Str::before($this->input('clave'), '.') !== $slug) {
                    $validator->errors()->add('clave', "La clave debe empezar con '$slug.' para este módulo");
                }
            },
        ];
    }

    /**
     * En el alta {id} es el módulo; en la edición, el permiso.
     */
    private function module(): ?Module
    {
        if ($this->isMethod('put')) {
            return Permission::with('module')->find($this->route('id'))?->module;
        }

        return Module::find($this->route('id'));
    }
}
