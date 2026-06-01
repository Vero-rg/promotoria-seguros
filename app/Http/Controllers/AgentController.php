<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Models\Promoter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class AgentController extends Controller
{
    // El index no es necesario aquí, ya que el directorio maneja la vista general
    public function index()
    {
        return redirect()->route('directorio');
    }

    // El create también se maneja de forma unificada desde PromoterController@create
    public function create()
    {
        return redirect()->route('promoters.create');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'promoter_id' => 'nullable|exists:promoters,id',
            'name' => 'required|string|max:255',
            'photo' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:2048',
            'is_active' => 'boolean',
        ]);

        if ($request->hasFile('photo')) {
            $validated['photo'] = $request->file('photo')->store('photos', 'public');
        }

        Agent::create($validated);
        
        return redirect()->route('directorio')->with('success', 'Agente registrado correctamente.');
    }

    public function show(Request $request, Agent $agent)
    {
        $startDate = $request->query('start_date', now()->startOfMonth()->toDateString());
        $endDate = $request->query('end_date', now()->endOfMonth()->toDateString());

        $agent->load(['promoter', 'policies' => function ($query) use ($startDate, $endDate) {
            $query->whereBetween('issue_date', [$startDate, $endDate])->latest('issue_date');
        }]);

        // ─── Estadísticas del agente ─────────────────
        $policies = $agent->policies;

        // 1. Cantidad de pólizas vendidas
        $policiesCount = $policies->count();

        // 2. Comisiones generadas (esquema base de 3 productos)
        //    Obtenemos el mapa de comisiones desde PolicyController
        $productCommissionMap = app(\App\Http\Controllers\PolicyController::class)->getProductCommissionMap();
        $totalCommissions = $policies->sum(function ($policy) use ($productCommissionMap) {
            $map = $productCommissionMap[$policy->product_type] ?? null;
            if ($map) {
                return (float) $policy->premium_amount * ($map['agent_percentage'] / 100);
            }
            return (float) $policy->commission_amount;
        });

        // 3. Bonos: Usar el Orquestador de Bonos (Strategy Pattern)
        $bonusOrchestrator = app(\App\Services\BonusOrchestratorService::class);

        $orchestratorResult = $bonusOrchestrator->calculateAll(
            user: $agent,
            periodStart: \Carbon\Carbon::parse($startDate),
            periodEnd: \Carbon\Carbon::parse($endDate),
        );

        $bonusesProgress = $bonusOrchestrator->toFrontendFormat($orchestratorResult);

        return Inertia::render('Directory/Show', [
            'entity' => $agent,
            'type' => 'agent',
            'stats' => [
                'policies_count' => $policiesCount,
                'total_commissions' => round($totalCommissions, 2),
                'bonuses' => $bonusesProgress,
            ],
            'filters' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
            ],
        ]);
    }

    public function edit(Agent $agent)
    {
        return Inertia::render('Directory/Edit', [
            'entity' => $agent,
            'type' => 'agent',
            'promoters' => Promoter::orderBy('name')->get()
        ]);
    }

    public function update(Request $request, Agent $agent)
    {
        $validated = $request->validate([
            'promoter_id' => 'nullable|exists:promoters,id',
            'name' => 'sometimes|required|string|max:255',
            'photo' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:2048',
            'is_active' => 'boolean',
        ]);

        if ($request->hasFile('photo')) {
            // Delete old photo if exists
            if ($agent->photo && Storage::disk('public')->exists($agent->photo)) {
                Storage::disk('public')->delete($agent->photo);
            }
            $validated['photo'] = $request->file('photo')->store('photos', 'public');
        }

        // Gestionar deactivated_at según el cambio de estado
        if (array_key_exists('is_active', $validated)) {
            if ($validated['is_active'] && $agent->deactivated_at !== null) {
                // Se reactiva → limpiar fecha de baja
                $validated['deactivated_at'] = null;
            } elseif (!$validated['is_active'] && $agent->is_active) {
                // Se da de baja → registrar fecha
                $validated['deactivated_at'] = now();
            }
        }

        $agent->update($validated);
        return redirect()->route('directorio')->with('success', 'Agente actualizado correctamente.');
    }

    public function destroy(Agent $agent)
    {
        $agent->delete();
        return redirect()->route('directorio')->with('success', 'Agente eliminado correctamente.');
    }
}