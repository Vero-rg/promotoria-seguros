<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreAgentRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'promoter_id' => 'nullable|exists:promoters,id',
            'name' => 'required|string|max:255',
            'photo' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:2048',
            'is_active' => 'boolean',
            'cedular_tax_enabled' => 'boolean',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'required' => 'El campo :attribute es obligatorio.',
            'string' => 'El campo :attribute debe ser texto.',
            'boolean' => 'El campo :attribute debe ser verdadero o falso.',
            'image' => 'El campo :attribute debe ser una imagen.',
            'mimes' => 'El campo :attribute debe ser una imagen JPG, PNG o WebP.',
            'name.max' => 'El campo :attribute no debe tener más de :max caracteres.',
            'photo.max' => 'El campo :attribute no debe superar los :max kilobytes.',
            'exists' => 'El :attribute seleccionado es inválido.',
        ];
    }

    /**
     * Get custom attributes for validator errors.
     */
    public function attributes(): array
    {
        return [
            'promoter_id' => 'promotor',
            'name' => 'nombre',
            'photo' => 'foto de perfil',
            'is_active' => 'estado de la cuenta',
            'cedular_tax_enabled' => 'impuesto cedular',
        ];
    }
}
