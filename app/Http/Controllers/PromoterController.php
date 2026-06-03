<?php

namespace App\Http\Controllers;

use App\Models\Promoter;
use App\Models\Agent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class PromoterController extends Controller
{
    public function index(Request $request)
    {
        $search = $request->query('search');
        $type = $request->query('type');
        $date = $request->query('date');
        $status = $request->query('status');

        $promoters = collect();
        $agents = collect();

        $promoterIdSearch = null;
        $agentIdSearch = null;

        // Detectar si la búsqueda incluye la letra P o A seguida de un número
        if ($search) {
            if (preg_match('/^[pP](\d+)$/', $search, $matches)) {
                $promoterIdSearch = $matches[1];
            } elseif (preg_match('/^[aA](\d+)$/', $search, $matches)) {
                $agentIdSearch = $matches[1];
            }
        }

        if (!$type || $type === 'promoter') {
            $promoters = Promoter::with('agents')
                ->when($search, function ($query, $search) use ($promoterIdSearch) {
                    $query->where(function ($q) use ($search, $promoterIdSearch) {
                        $q->where('name', 'like', "%{$search}%")
                          ;
                        if ($promoterIdSearch) {
                            $q->orWhere('id', $promoterIdSearch);
                        } elseif (is_numeric($search)) {
                            $q->orWhere('id', $search);
                        }
                    });
                })
                ->when($date, function ($query, $date) {
                    $query->whereDate('created_at', $date);
                })
                ->when($status !== null && $status !== '', function ($query) use ($status) {
                    $query->where('is_active', $status === 'active');
                })
                ->latest()
                ->get()
                ->map(function ($item) {
                    $item->type = 'promoter';
                    $item->uid = 'promoter_' . $item->id;
                    $item->display_id = 'P' . $item->id;
                    return $item;
                });
        }

        if (!$type || $type === 'agent') {
            $agents = Agent::with('promoter')
                ->when($search, function ($query, $search) use ($agentIdSearch) {
                    $query->where(function ($q) use ($search, $agentIdSearch) {
                        $q->where('name', 'like', "%{$search}%")
                          ;
                        if ($agentIdSearch) {
                            $q->orWhere('id', $agentIdSearch);
                        } elseif (is_numeric($search)) {
                            $q->orWhere('id', $search);
                        }
                    });
                })
                ->when($date, function ($query, $date) {
                    $query->whereDate('created_at', $date);
                })
                ->when($status !== null && $status !== '', function ($query) use ($status) {
                    $query->where('is_active', $status === 'active');
                })
                ->latest()
                ->get()
                ->map(function ($item) {
                    $item->type = 'agent';
                    $item->uid = 'agent_' . $item->id;
                    $item->display_id = 'A' . $item->id;
                    return $item;
                });
        }

        // Unimos las colecciones, las ordenamos por fecha de creación y reseteamos las llaves
        $directory = $promoters->concat($agents)->sortByDesc('created_at')->values();

        return Inertia::render('Directory/Index', [
            'directory' => $directory,
            'filters' => [
                'search' => $search,
                'type' => $type,
                'date' => $date,
                'status' => $status,
            ]
        ]);
    }

    public function create()
    {
        return Inertia::render('Directory/Create', [
            'promoters' => Promoter::orderBy('name')->get()
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'photo' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:2048',
            'is_active' => 'boolean',
        ]);

        if ($request->hasFile('photo')) {
            $validated['photo'] = $request->file('photo')->store('photos', 'public');
        }

        Promoter::create($validated);
        
        return redirect()->route('directorio')->with('success', 'Promotor registrado correctamente.');
    }

    public function show(Request $request, Promoter $promoter)
    {
        $startDate = $request->query('start_date', now()->startOfMonth()->toDateString());
        $endDate = $request->query('end_date', now()->endOfMonth()->toDateString());

        // Cargar agentes con sus pólizas filtradas por el rango de fechas.
        // Solo se incluyen agentes activos O inactivos dados de baja en el mes del periodo o después.
        $promoter->load(['agents' => function ($query) use ($startDate, $endDate) {
            $query->activeInPeriod($endDate)
                ->with(['policies' => function ($q) use ($startDate, $endDate) {
                    $q->whereBetween('issue_date', [$startDate, $endDate])->latest('issue_date');
                }]);
        }]);

        $agents = $promoter->agents;

        // ─── 4 Tarjetas de Estadísticas ─────────────────

        // 1. Agentes Activos (total en su red)
        $activeAgentsCount = $agents->where('is_active', true)->count();

        // 2. Nuevos Reclutamientos (agentes creados en el periodo)
        $newRecruitments = Agent::where('promoter_id', $promoter->id)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->count();

        // 3. Volumen de Venta del Equipo (suma de primas en el periodo)
        $teamSalesVolume = $agents->sum(function ($agent) {
            return $agent->policies->sum('premium_amount');
        });

        // ─── Esquemas de Bono para Promotores (Orquestador Strategy) ────
        //    El orquestador expande automáticamente el periodo para bonos
        //    trimestrales/anuales según la frecuencia de cada esquema.
        $bonusOrchestrator = app(\App\Services\BonusOrchestratorService::class);

        $visualStart = \Carbon\Carbon::parse($startDate);
        $visualEnd   = \Carbon\Carbon::parse($endDate);

        $orchestratorResult = $bonusOrchestrator->calculateAll(
            user: $promoter,
            periodStart: $visualStart,
            periodEnd: $visualEnd,
            visualRangeStart: $visualStart,
            visualRangeEnd: $visualEnd,
        );

        $bonusesProgress = $bonusOrchestrator->toFrontendFormat($orchestratorResult);

        // 4. Bonos Alcanzados
        $bonusesAchieved = count(array_filter($bonusesProgress, fn($b) => $b['unlocked'] ?? false));
        $bonusesTotal = count($bonusesProgress);

        // ─── Comisiones del Promotor (basado en esquema de comisión activo) ────
        $commissionScheme = \App\Models\Scheme::with(['versions.tiers'])
            ->where('type', 'commission')
            ->where('is_active', true)
            ->whereIn('target', ['promoter', 'both'])
            ->first();

        $commissionsByAgent = [];
        $totalPromoterCommission = 0;

        if ($commissionScheme) {
            $latestVersion = $commissionScheme->versions->sortByDesc('starts_at')->first();

            if ($latestVersion && $latestVersion->tiers->isNotEmpty()) {
                foreach ($agents as $agent) {
                    $agentCommission = 0;
                    $agentPolicies = [];

                    foreach ($agent->policies as $policy) {
                        $productType = $policy->product_type;
                        $tier = $latestVersion->tiers->first(function ($t) use ($productType) {
                            $conds = $t->conditions ?? [];
                            return ($conds['product_type'] ?? '') === $productType;
                        });

                        $percentage = $tier ? (float) ($tier->promoter_percentage ?? 0) : 0;
                        $commission = (float) $policy->premium_amount * ($percentage / 100);
                        $agentCommission += $commission;

                        $agentPolicies[] = [
                            'id' => $policy->id,
                            'client_name' => $policy->client_name,
                            'product_type' => $productType,
                            'premium_amount' => (float) $policy->premium_amount,
                            'percentage' => $percentage,
                            'commission' => round($commission, 2),
                        ];
                    }

                    if ($agentCommission > 0 || $agent->policies->isNotEmpty()) {
                        $commissionsByAgent[] = [
                            'agent_id' => $agent->id,
                            'agent_name' => $agent->name,
                            'photo' => $agent->photo,
                            'total_commission' => round($agentCommission, 2),
                            'policies_count' => $agent->policies->count(),
                            'policies' => $agentPolicies,
                        ];
                        $totalPromoterCommission += $agentCommission;
                    }
                }
            }
        }

        // ─── Datos por Agente para el Directorio Operativo ─────────────────
        $agentsData = $agents->map(function ($agent) {
            // Determinar el producto requerido revisando los esquemas de bono de desarrollo
            $requiredProduct = null;
            $minPoliciesForBonus = 1;

            // Buscar si algún bono activo de tipo desarrollo exige producto específico
            $activeBonusSchemes = \App\Models\Scheme::with(['versions.tiers'])
                ->where('type', 'bonus')
                ->where('target', 'promoter')
                ->where('is_active', true)
                ->get();

            $developmentBonus = $activeBonusSchemes->filter(function ($s) {
                return stripos($s->name, 'desarrollo') !== false;
            })->first();

            if ($developmentBonus) {
                $latestVersion = $developmentBonus->versions->sortByDesc('starts_at')->first();
                if ($latestVersion && $latestVersion->tiers->isNotEmpty()) {
                    $tier = $latestVersion->tiers->first();
                    $conditions = $tier->conditions ?? [];
                    $requiredProduct = $conditions['required_product'] ?? null;
                    $minPoliciesForBonus = (int) ($conditions['min_policies'] ?? 1);
                }
            }

            $meetsRequirement = false;
            if ($requiredProduct) {
                $meetsRequirement = $agent->policies->where('product_type', $requiredProduct)->count() >= $minPoliciesForBonus;
            } else {
                $meetsRequirement = $agent->policies->count() >= $minPoliciesForBonus;
            }

            return [
                'id' => $agent->id,
                'name' => $agent->name,
                'photo' => $agent->photo,
                'is_active' => $agent->is_active,
                'policies_count' => $agent->policies->count(),
                'policies' => $agent->policies->map(function ($p) {
                    return [
                        'id' => $p->id,
                        'client_name' => $p->client_name,
                        'product_type' => $p->product_type,
                        'issue_date' => $p->issue_date->toDateString(),
                        'premium_amount' => (float) $p->premium_amount,
                        'commission_amount' => (float) $p->commission_amount,
                    ];
                })->values(),
                'meets_requirement' => $meetsRequirement,
                'required_product' => $requiredProduct,
                'min_policies_for_bonus' => $minPoliciesForBonus,
            ];
        })->values();

        return Inertia::render('Directory/Show', [
            'entity' => $promoter,
            'type' => 'promoter',
            'stats' => [
                'active_agents' => $activeAgentsCount,
                'new_recruitments' => $newRecruitments,
                'team_sales_volume' => round($teamSalesVolume, 2),
                'bonuses_achieved' => $bonusesAchieved,
                'bonuses_total' => $bonusesTotal,
                'bonuses' => $bonusesProgress,
                'agents' => $agentsData,
                'commissions' => $commissionsByAgent,
                'total_promoter_commission' => round($totalPromoterCommission, 2),
                'commission_scheme_name' => $commissionScheme?->name ?? null,
            ],
            'filters' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
            ],
        ]);
    }

    public function edit(Promoter $promoter)
    {
        return Inertia::render('Directory/Edit', [
            'entity' => $promoter,
            'type' => 'promoter',
            'promoters' => Promoter::orderBy('name')->get()
        ]);
    }

    public function update(Request $request, Promoter $promoter)
    {
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'photo' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:2048',
            'is_active' => 'boolean',
        ]);

        if ($request->hasFile('photo')) {
            // Delete old photo if exists
            if ($promoter->photo && Storage::disk('public')->exists($promoter->photo)) {
                Storage::disk('public')->delete($promoter->photo);
            }
            $validated['photo'] = $request->file('photo')->store('photos', 'public');
        }

        $promoter->update($validated);
        return redirect()->route('directorio')->with('success', 'Promotor actualizado correctamente.');
    }

    public function destroy(Promoter $promoter)
    {
        $promoter->delete();
        return redirect()->route('directorio')->with('success', 'Promotor eliminado correctamente.');
    }
}