<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePolicyRequest;
use App\Http\Requests\UpdatePolicyRequest;
use App\Models\Policy;
use App\Models\Agent;
use App\Models\Promoter;
use App\Services\ProductCommissionService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class PolicyController extends Controller
{
    public function __construct(private ProductCommissionService $productCommissionService)
    {
    }

    /**
     * Obtiene el mapa de producto → { agent_percentage, promoter_percentage }
     * desde los esquemas de comisión activos (última versión vigente).
     */
    public function getProductCommissionMap(): array
    {
        return $this->productCommissionService->map();
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
            'agents' => Agent::where('is_active', true)->orderBy('name')->get(),
            'productCommissionMap' => $this->getProductCommissionMap(),
        ]);
    }

    public function store(StorePolicyRequest $request)
    {
        $validated = $request->validated();

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
            'agents' => Agent::where('is_active', true)->orderBy('name')->get(),
            'productCommissionMap' => $this->getProductCommissionMap(),
        ]);
    }

    public function update(UpdatePolicyRequest $request, Policy $policy)
    {
        $validated = $request->validated();

        $policy->update($validated);
        return redirect()->route('policies.index')->with('success', 'Póliza actualizada exitosamente.');
    }

    public function destroy(Policy $policy)
    {
        $policy->delete();
        return redirect()->route('policies.index')->with('success', 'Póliza eliminada.');
    }
}