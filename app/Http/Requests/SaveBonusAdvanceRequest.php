<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SaveBonusAdvanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'scheme_id'    => ['required', 'integer', 'exists:schemes,id'],
            'user_type'    => ['required', 'in:agent,promoter'],
            'user_id'      => ['required', 'integer', 'min:1'],
            'period_month' => ['required', 'string', 'regex:/^\d{4}-\d{2}$/'],
            'amount'       => ['required', 'numeric', 'min:0', 'max:99999999'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'scheme_id.required'    => 'El esquema es obligatorio.',
            'scheme_id.exists'      => 'El esquema seleccionado no existe.',
            'user_type.required'    => 'El tipo de usuario es obligatorio.',
            'user_type.in'          => 'El tipo de usuario no es válido.',
            'user_id.required'      => 'El usuario es obligatorio.',
            'period_month.required' => 'El mes del anticipo es obligatorio.',
            'period_month.regex'    => 'El mes del anticipo debe tener el formato AAAA-MM.',
            'amount.required'       => 'El monto del anticipo es obligatorio.',
            'amount.numeric'        => 'El monto del anticipo debe ser un número.',
            'amount.min'            => 'El monto del anticipo no puede ser negativo.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'scheme_id'    => 'esquema',
            'user_type'    => 'tipo de usuario',
            'user_id'      => 'usuario',
            'period_month' => 'mes',
            'amount'       => 'monto',
        ];
    }
}
