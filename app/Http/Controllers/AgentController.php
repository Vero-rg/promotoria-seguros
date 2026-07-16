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

        // 2. Comisiones generadas con desglose secuencial ISR → facturación
        $productCommissionMap = app(\App\Http\Controllers\PolicyController::class)->getProductCommissionMap();

        $agentGross   = 0;
        $agentIsr     = 0;
        $agentBilling = 0;
        $agentNet     = 0;

        foreach ($policies as $policy) {
            // Solo pólizas Pagadas generan comisión
            if ($policy->status !== \App\Models\Policy::STATUS_PAGADA) continue;

            $map = $productCommissionMap[$policy->product_type] ?? null;
            if ($map) {
                $gross = (float) $policy->premium_amount * ($map['agent_percentage'] / 100);
            } else {
                $gross = (float) $policy->commission_amount;
            }

            // Per-policy sequential ISR → billing (matching Policy::agentNetAmount)
            $isrPct   = (float) ($policy->isr_retention ?? 0);
            $billPct  = (float) ($policy->billing_retention ?? 0);
            $isr      = $gross * ($isrPct / 100);
            $afterIsr = $gross - $isr;
            $billing  = $afterIsr * ($billPct / 100);
            $net      = $afterIsr - $billing;

            $agentGross   += $gross;
            $agentIsr     += $isr;
            $agentBilling += $billing;
            $agentNet     += $net;
        }

        $commissionBreakdown = [
            'gross'   => round($agentGross, 2),
            'isr'     => round($agentIsr, 2),
            'billing' => round($agentBilling, 2),
            'net'     => round($agentNet, 2),
        ];

        // 3. Bonos: Usar el Orquestador de Bonos (Strategy Pattern)
        //    El orquestador expande automáticamente el periodo para bonos
        //    trimestrales/anuales según la frecuencia de cada esquema.
        $bonusOrchestrator = app(\App\Services\BonusOrchestratorService::class);

        $visualStart = \Carbon\Carbon::parse($startDate);
        $visualEnd   = \Carbon\Carbon::parse($endDate);

        $orchestratorResult = $bonusOrchestrator->calculateAll(
            user: $agent,
            periodStart: $visualStart,
            periodEnd: $visualEnd,
            visualRangeStart: $visualStart,
            visualRangeEnd: $visualEnd,
        );

        $bonusesProgress = $bonusOrchestrator->toFrontendFormat($orchestratorResult);

        return Inertia::render('Directory/Show', [
            'entity' => $agent,
            'type' => 'agent',
            'stats' => [
                'policies_count' => $policiesCount,
                'commission_breakdown' => $commissionBreakdown,
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