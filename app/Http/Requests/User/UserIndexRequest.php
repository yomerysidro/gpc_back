<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Filtros del listado (query string). estado llega como texto: "true"/"false" o "1"/"0".
 */
class UserIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:150'],
            'idRol' => ['nullable', 'integer'],
            'estado' => ['nullable', 'in:true,false,1,0'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * Filtros con sus tipos, sin los que no se enviaron.
     */
    public function filters(): array
    {
        return array_filter([
            'search' => $this->validated('search'),
            'idRol' => $this->filled('idRol') ? (int) $this->validated('idRol') : null,
            'estado' => $this->filled('estado') ? $this->boolean('estado') : null,
            'page' => $this->filled('page') ? (int) $this->validated('page') : null,
        ], fn ($value) => $value !== null);
    }
}
