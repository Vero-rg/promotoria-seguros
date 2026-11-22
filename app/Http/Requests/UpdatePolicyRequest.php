<?php

namespace App\Http\Requests;

use App\Models\Policy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
     * The cedular tax is optional: when omitted it is stored as 0 on update.
     */
    protected function prepareForValidation(): void
    {
        if ($this->input('cedular_tax') === null || $this->input('cedular_tax') === '') {
            $this->merge(['cedular_tax' => 0]);
        }
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
            'isr_retention' => 'required|numeric|min:0|max:100',
            'cedular_tax' => 'nullable|numeric|min:0|max:100',
            'billing_retention' => 'required|numeric|min:0|max:100',
            'status' => 'sometimes|string',
            'product_type' => 'nullable|string',
            'payment_method' => ['required', Rule::in(Policy::PAYMENT_METHODS)],
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
            'premium_amount' => 'PNA',
            'commission_percentage' => 'porcentaje de comisión (agente)',
            'commission_amount' => 'monto de comisión (agente)',
            'promoter_commission_percentage' => 'porcentaje de comisión (promotor)',
            'promoter_commission_amount' => 'monto de comisión (promotor)',
            'isr_retention' => 'retención de ISR',
            'cedular_tax' => 'impuesto cedular',
            'billing_retention' => 'costo de facturación',
            'status' => 'estatus',
            'product_type' => 'tipo de producto',
            'payment_method' => 'conducto de pago',
        ];
    }
}
