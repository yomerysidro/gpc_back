<?php

namespace App\Http\Requests\Module;

use Illuminate\Foundation\Http\FormRequest;

class ModuleStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'estado' => ['required', 'boolean'],
        ];
    }
}
