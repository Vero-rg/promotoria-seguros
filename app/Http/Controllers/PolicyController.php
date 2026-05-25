<?php

namespace App\Http\Controllers;

use App\Models\Policy;
use App\Models\Agent;   
use Illuminate\Http\Request;
use Inertia\Inertia;

class PolicyController extends Controller
{
    public function index()
    {
        $policies = Policy::with('agent')->latest()->get();
        return Inertia::render('Policies/Index', [
            'policies' => $policies
        ]);
    }

    public function create()
    {
        return Inertia::render('Policies/Create', [
            'agents' => Agent::orderBy('name')->get()
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'agent_id' => 'required|exists:agents,id',
            'policy_number' => 'required|string|unique:policies',
            'issue_date' => 'required|date',
            'premium_amount' => 'required|numeric',
            'commission_percentage' => 'nullable|numeric',
            'commission_amount' => 'nullable|numeric',
            'isr_retention' => 'nullable|numeric',
            'billing_retention' => 'nullable|numeric',
            'status' => 'nullable|string',
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
            'commission_percentage' => 'porcentaje de comisión',
            'commission_amount' => 'monto de comisión',
            'isr_retention' => 'retención de ISR',
            'billing_retention' => 'costo de facturación',
            'status' => 'estatus',
        ]);

        Policy::create($validated);
        return redirect()->route('policies.index')->with('success', 'Póliza creada exitosamente.');
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
            'agents' => Agent::orderBy('name')->get()
        ]);
    }

    public function update(Request $request, Policy $policy)
    {
        $validated = $request->validate([
            'agent_id' => 'sometimes|required|exists:agents,id',
            'policy_number' => 'sometimes|required|string|unique:policies,policy_number,' . $policy->id,
            'issue_date' => 'sometimes|required|date',
            'premium_amount' => 'sometimes|required|numeric',
            'commission_percentage' => 'nullable|numeric',
            'commission_amount' => 'nullable|numeric',
            'isr_retention' => 'nullable|numeric',
            'billing_retention' => 'nullable|numeric',
            'status' => 'sometimes|string',
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
            'commission_percentage' => 'porcentaje de comisión',
            'commission_amount' => 'monto de comisión',
            'isr_retention' => 'retención de ISR',
            'billing_retention' => 'costo de facturación',
            'status' => 'estatus',
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