<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreAgentRequest;
use App\Http\Requests\UpdateAgentRequest;
use App\Models\Agent;
use App\Models\Promoter;
use App\Services\BonusOrchestratorService;
use App\Services\CommissionBreakdownService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class AgentController extends Controller
{
    public function __construct(private CommissionBreakdownService $commissions) {}

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

    public function store(StoreAgentRequest $request)
    {
        $validated = $request->validated();

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

        // 2. Comisiones generadas con desglose secuencial ISR → cedular → facturación
        $commissionBreakdown = $this->commissions->summarize($policies, CommissionBreakdownService::ROLE_AGENT);

        // Comisión bruta por póliza para el historial (usa el esquema activo si la póliza no tiene porcentaje)
        $policies->each(function ($policy) {
            $policy->setAttribute(
                'resolved_commission_amount',
                $this->commissions->grossCommission($policy, CommissionBreakdownService::ROLE_AGENT)
            );
        });

        // 3. Bonos: Usar el Orquestador de Bonos (Strategy Pattern)
        //    El orquestador expande automáticamente el periodo para bonos
        //    trimestrales/anuales según la frecuencia de cada esquema.
        $bonusOrchestrator = app(BonusOrchestratorService::class);

        $visualStart = Carbon::parse($startDate);
        $visualEnd = Carbon::parse($endDate);

        $orchestratorResult = $bonusOrchestrator->calculateAll(
            user: $agent,
            periodStart: $visualStart,
            periodEnd: $visualEnd,
            visualRangeStart: $visualStart,
            visualRangeEnd: $visualEnd,
            monthlyView: true,
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
            'promoters' => Promoter::orderBy('name')->get(),
        ]);
    }

    public function update(UpdateAgentRequest $request, Agent $agent)
    {
        $validated = $request->validated();

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
            } elseif (! $validated['is_active'] && $agent->is_active) {
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
