<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePolicyRequest extends FormRequest
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
        $policyId = $this->route('policy')->id;

        return [
            'agent_id' => 'sometimes|required|exists:agents,id',
            'policy_number' => 'sometimes|required|string|unique:policies,policy_number,' . $policyId,
            'client_name' => 'nullable|string|max:255',
            'issue_date' => 'sometimes|required|date',
            'premium_amount' => 'sometimes|required|numeric',
            'commission_percentage' => 'nullable|numeric',
            'commission_amount' => 'nullable|numeric',
            'promoter_commission_percentage' => 'nullable|numeric',
            'promoter_commission_amount' => 'nullable|numeric',
            'isr_retention' => 'nullable|numeric',
            'billing_retention' => 'nullable|numeric',
            'status' => 'sometimes|string',
            'product_type' => 'nullable|string',
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
            'numeric' => 'El campo :attribute debe ser un número.',
            'date' => 'El campo :attribute debe ser una fecha válida.',
            'exists' => 'El :attribute seleccionado es inválido.',
            'unique' => 'El :attribute ya está registrado.',
        ];
    }

    /**
     * Get custom attributes for validator errors.
     */
    public function attributes(): array
    {
        return [
            'agent_id' => 'agente',
            'policy_number' => 'número de póliza',
            'issue_date' => 'fecha de emisión',
            'premium_amount' => 'prima total',
            'commission_percentage' => 'porcentaje de comisión (agente)',
            'commission_amount' => 'monto de comisión (agente)',
            'promoter_commission_percentage' => 'porcentaje de comisión (promotor)',
            'promoter_commission_amount' => 'monto de comisión (promotor)',
            'isr_retention' => 'retención de ISR',
            'billing_retention' => 'costo de facturación',
            'status' => 'estatus',
            'product_type' => 'tipo de producto',
        ];
    }
}
