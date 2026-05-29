<?php

namespace App\Http\Controllers;

use App\Models\Policy;
use App\Models\Agent;
use App\Models\Scheme;
use App\Models\Promoter;
use Illuminate\Http\Request;
use Inertia\Inertia;

class PolicyController extends Controller
{
    /**
     * Obtiene el mapa de producto → { agent_percentage, promoter_percentage }
     * desde los esquemas de comisión activos (última versión vigente).
     */
    private function getProductCommissionMap(): array
    {
        $schemes = Scheme::with(['versions.tiers'])
            ->where('type', 'commission')
            ->where('is_active', true)
            ->get();

        $map = [];

        foreach ($schemes as $scheme) {
            $latestVersion = $scheme->versions->sortByDesc('starts_at')->first();
            if (!$latestVersion || !$latestVersion->tiers) continue;

            foreach ($latestVersion->tiers as $tier) {
                $conditions = $tier->conditions ?? [];
                $productType = $conditions['product_type'] ?? null;
                if (!$productType) continue;

                // Solo sobreescribe si no existe aún (primer encuentro gana)
                if (!isset($map[$productType])) {
                    $map[$productType] = [
                        'agent_percentage' => (float) ($tier->agent_percentage ?? 0),
                        'promoter_percentage' => (float) ($tier->promoter_percentage ?? 0),
                    ];
                }
            }
        }

        return $map;
    }

    public function index()
    {
        $policies = Policy::with('agent.promoter')->latest()->get();
        return Inertia::render('Policies/Index', [
            'policies' => $policies,
            'promoters' => Promoter::orderBy('name')->get(),
        ]);
    }

    public function create()
    {
        return Inertia::render('Policies/Create', [
            'agents' => Agent::orderBy('name')->get(),
            'productCommissionMap' => $this->getProductCommissionMap(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'agent_id' => 'required|exists:agents,id',
            'policy_number' => 'required|string|unique:policies',
            'client_name' => 'nullable|string|max:255',
            'issue_date' => 'required|date',
            'premium_amount' => 'required|numeric',
            'commission_percentage' => 'nullable|numeric',
            'commission_amount' => 'nullable|numeric',
            'promoter_commission_percentage' => 'nullable|numeric',
            'promoter_commission_amount' => 'nullable|numeric',
            'isr_retention' => 'nullable|numeric',
            'billing_retention' => 'nullable|numeric',
            'status' => 'nullable|string',
            'product_type' => 'nullable|string',
        ], [
            'required' => 'El campo :attribute es obligatorio.',
            'string' => 'El campo :attribute debe ser texto.',
            'numeric' => 'El campo :attribute debe ser un número.',
            'date' => 'El campo :attribute debe ser una fecha válida.',
            'exists' => 'El :attribute seleccionado es inválido.',
            'unique' => 'El :attribute ya está registrado.',
        ], [
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
        ]);

        Policy::create($validated);
        return redirect()->route('policies.index')->with('success', 'Póliza creada exitosamente.');
    }

    /**
     * Actualiza solo el estatus de una póliza.
     */
    public function updateStatus(Request $request, Policy $policy)
    {
        $validated = $request->validate([
            'status' => 'required|string|in:' . implode(',', Policy::STATUSES),
        ], [
            'status.in' => 'El estatus seleccionado no es válido.',
        ]);

        $policy->changeStatus($validated['status']);

        return back()->with('success', "Estatus cambiado a \"{$validated['status']}\".");
    }

    public function show(Policy $policy)
    {
        $policy->load('agent.promoter');
        return Inertia::render('Policies/Show', [
            'policy' => $policy
        ]);
    }

    public function edit(Policy $policy)
    {
        return Inertia::render('Policies/Edit', [
            'policy' => $policy,
            'agents' => Agent::orderBy('name')->get(),
            'productCommissionMap' => $this->getProductCommissionMap(),
        ]);
    }

    public function update(Request $request, Policy $policy)
    {
        $validated = $request->validate([
            'agent_id' => 'sometimes|required|exists:agents,id',
            'policy_number' => 'sometimes|required|string|unique:policies,policy_number,' . $policy->id,
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
        ], [
            'required' => 'El campo :attribute es obligatorio.',
            'string' => 'El campo :attribute debe ser texto.',
            'numeric' => 'El campo :attribute debe ser un número.',
            'date' => 'El campo :attribute debe ser una fecha válida.',
            'exists' => 'El :attribute seleccionado es inválido.',
            'unique' => 'El :attribute ya está registrado.',
        ], [
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
        ]);

        $policy->update($validated);
        return redirect()->route('policies.index')->with('success', 'Póliza actualizada exitosamente.');
    }

    public function destroy(Policy $policy)
    {
        $policy->delete();
        return redirect()->route('policies.index')->with('success', 'Póliza eliminada.');
    }
}