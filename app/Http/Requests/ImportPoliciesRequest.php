<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ImportPoliciesRequest extends FormRequest
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
            'file' => 'required|file|mimes:xlsx,xls,csv,txt|max:10240',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'required' => 'Selecciona un archivo para importar.',
            'mimes' => 'El archivo debe ser de Excel (.xlsx, .xls) o CSV.',
            'max' => 'El archivo no debe superar los 10 MB.',
            'uploaded' => 'No se pudo subir el archivo. Verifica que no supere los 10 MB e inténtalo de nuevo.',
        ];
    }

    /**
     * Get custom attributes for validator errors.
     */
    public function attributes(): array
    {
        return [
            'file' => 'archivo',
        ];
    }
}
