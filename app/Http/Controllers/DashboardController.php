<?php

namespace App\Http\Controllers;

use App\Models\Policy;
use App\Models\Agent;
use App\Models\Promoter;
use App\Models\Scheme;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Carbon\Carbon;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $filter = $request->query('filter', 'month'); // today, month, year, custom
        $customStart = $request->query('start_date');
        $customEnd = $request->query('end_date');

        // ─── Calcular rango de fechas ─────────────────
        switch ($filter) {
            case 'today':
                $startDate = now()->startOfDay();
                $endDate = now()->endOfDay();
                break;
            case 'year':
                $startDate = now()->startOfYear();
                $endDate = now()->endOfYear();
                break;
            case 'custom':
                $startDate = $customStart ? Carbon::parse($customStart)->startOfDay() : now()->startOfMonth();
                $endDate = $customEnd ? Carbon::parse($customEnd)->endOfDay() : now()->endOfMonth();
                break;
            case 'month':
            default:
                $startDate = now()->startOfMonth();
                $endDate = now()->endOfMonth();
                break;
        }

        // Periodo anterior (mismo largo, un periodo atrás) para crecimiento
        $periodLength = $startDate->diffInDays($endDate) + 1;
        $prevStart = (clone $startDate)->subDays($periodLength);
        $prevEnd = (clone $startDate)->subDay();

        // ─── Pólizas en el periodo ─────────────────
        $policies = Policy::with('agent.promoter')
            ->whereBetween('issue_date', [$startDate, $endDate])
            ->get();

        $prevPolicies = Policy::whereBetween('issue_date', [$prevStart, $prevEnd])->get();

        // ─── 1. KPIs GLOBALES ─────────────────────────

        // Ingreso Neto: Prima total - comisiones agente - comisiones promotor - ISR - facturación
        $currentNetIncome = $policies->sum(function ($p) {
            $premium = (float) $p->premium_amount;
            $agentComm = (float) $p->commission_amount;
            $promoterComm = (float) $p->promoter_commission_amount;
            $isr = $premium * ((float) ($p->isr_retention ?? 0) / 100);
            $billing = $premium * ((float) ($p->billing_retention ?? 0) / 100);
            return $premium - $agentComm - $promoterComm - $isr - $billing;
        });
        $prevNetIncome = $prevPolicies->sum(function ($p) {
            $premium = (float) $p->premium_amount;
            $agentComm = (float) $p->commission_amount;
            $promoterComm = (float) $p->promoter_commission_amount;
            $isr = $premium * ((float) ($p->isr_retention ?? 0) / 100);
            $billing = $premium * ((float) ($p->billing_retention ?? 0) / 100);
            return $premium - $agentComm - $promoterComm - $isr - $billing;
        });
        $incomeGrowth = $prevNetIncome > 0 ? round((($currentNetIncome - $prevNetIncome) / $prevNetIncome) * 100, 1) : 0;

        // Volumen de Pólizas (por tipo de producto) — actual y periodo anterior
        $policiesByProduct = [
            '1' => $policies->where('product_type', '1')->count(),
            '2' => $policies->where('product_type', '2')->count(),
            '3' => $policies->where('product_type', '3')->count(),
        ];
        $prevPoliciesByProduct = [
            '1' => $prevPolicies->where('product_type', '1')->count(),
            '2' => $prevPolicies->where('product_type', '2')->count(),
            '3' => $prevPolicies->where('product_type', '3')->count(),
        ];
        $totalPolicies = $policies->count();
        $prevTotalPolicies = $prevPolicies->count();
        $policiesGrowth = $prevTotalPolicies > 0 ? round((($totalPolicies - $prevTotalPolicies) / $prevTotalPolicies) * 100, 1) : 0;

        // Premios vendidos por producto (volumen en $)
        $premiumByProduct = [
            '1' => round($policies->where('product_type', '1')->sum('premium_amount'), 2),
            '2' => round($policies->where('product_type', '2')->sum('premium_amount'), 2),
            '3' => round($policies->where('product_type', '3')->sum('premium_amount'), 2),
        ];
        $prevPremiumByProduct = [
            '1' => round($prevPolicies->where('product_type', '1')->sum('premium_amount'), 2),
            '2' => round($prevPolicies->where('product_type', '2')->sum('premium_amount'), 2),
            '3' => round($prevPolicies->where('product_type', '3')->sum('premium_amount'), 2),
        ];

        // Bonos Proyectados: total + desglose agente/promotor
        $bonusSchemes = Scheme::where('type', 'bonus')->where('is_active', true)->get();
        $agentBonusSchemes = $bonusSchemes->where('target', 'agent');
        $promoterBonusSchemes2 = $bonusSchemes->where('target', 'promoter');

        $projectedAgentBonuses = $this->calculateProjectedBonuses($agentBonusSchemes, $policies);
        $projectedPromoterBonuses = $this->calculateProjectedBonuses($promoterBonusSchemes2, $policies);
        $projectedBonuses = $projectedAgentBonuses + $projectedPromoterBonuses;

        $prevProjectedBonuses = $this->calculateProjectedBonuses($bonusSchemes, $prevPolicies);
        $bonusesGrowth = $prevProjectedBonuses > 0 ? round((($projectedBonuses - $prevProjectedBonuses) / $prevProjectedBonuses) * 100, 1) : 0;

        // Fuerza de Ventas Activa
        $activePromoters = Promoter::where('is_active', true)->count();
        $activeAgents = Agent::where('is_active', true)->count();

        // ─── 2. ALERTAS INTELIGENTES ──────────────────

        $alerts = [];

        // Alerta de cierre de periodo
        $daysToClose = now()->diffInDays(now()->endOfQuarter(), false);
        if ($daysToClose > 0 && $daysToClose <= 10) {
            $alerts[] = [
                'type' => 'period',
                'message' => "Faltan {$daysToClose} días para el cierre trimestral.",
                'icon' => 'clock',
            ];
        }

        // Alertas de agentes cerca de meta
        $agentBonusSchemes = Scheme::where('type', 'bonus')->where('target', 'agent')->where('is_active', true)->get();
        foreach ($agentBonusSchemes as $scheme) {
            $latestVersion = $scheme->versions()->orderByDesc('starts_at')->first();
            if (!$latestVersion) continue;
            $tier = $latestVersion->tiers->first();
            if (!$tier) continue;
            $conditions = $tier->conditions ?? [];
            $target = (float) ($conditions['target'] ?? 0);
            $metric = $conditions['metric'] ?? '';

            $allAgents = Agent::with(['policies' => function ($q) use ($startDate, $endDate) {
                $q->whereBetween('issue_date', [$startDate, $endDate]);
            }])->where('is_active', true)->get();

            foreach ($allAgents as $agent) {
                if ($metric === 'production') {
                    $progress = $agent->policies->sum('premium_amount');
                } else {
                    $progress = $agent->policies->count();
                }

                $pct = $target > 0 ? ($progress / $target) * 100 : 0;
                if ($pct >= 80 && $pct < 100) {
                    $remaining = $metric === 'production' ? ($target - $progress) : (int)($target - $progress);
                    $unit = $metric === 'production' ? 'en ventas' : 'póliza(s)';
                    $alerts[] = [
                        'type' => 'agent_near_goal',
                        'message' => "{$agent->name} está a " . ($metric === 'production' ? '$' . number_format($remaining, 2) : "{$remaining} {$unit}") . " de asegurar su bono \"{$scheme->name}\".",
                        'icon' => 'target',
                    ];
                }
                if (count($alerts) >= 8) break 2; // limit alerts
            }
        }

        // Alertas de requisitos de promotores
        $promoterBonusSchemes = Scheme::where('type', 'bonus')->where('target', 'promoter')->where('is_active', true)->get();
        foreach ($promoterBonusSchemes as $scheme) {
            $latestVersion = $scheme->versions()->orderByDesc('starts_at')->first();
            if (!$latestVersion) continue;
            $tier = $latestVersion->tiers->first();
            if (!$tier) continue;
            $conditions = $tier->conditions ?? [];
            $target = (int) ($conditions['target'] ?? 0);
            $metric = $conditions['metric'] ?? '';

            if ($metric === 'recruits') {
                $allPromoters = Promoter::where('is_active', true)->get();
                foreach ($allPromoters as $promoter) {
                    $recruits = Agent::where('promoter_id', $promoter->id)
                        ->whereBetween('created_at', [$startDate, $endDate])->count();
                    if ($recruits < $target && $recruits >= $target - 3 && $target > 0) {
                        $alerts[] = [
                            'type' => 'promoter_requirement',
                            'message' => "El equipo de {$promoter->name} necesita " . ($target - $recruits) . " reclutamiento(s) más para desbloquear \"{$scheme->name}\".",
                            'icon' => 'users',
                        ];
                    }
                    if (count($alerts) >= 10) break 2;
                }
            }
        }

        // ─── 3. TOP 5 AGENTES ─────────────────────────
        $topAgents = Agent::with(['policies' => function ($q) use ($startDate, $endDate) {
            $q->whereBetween('issue_date', [$startDate, $endDate]);
        }])->where('is_active', true)->get()
            ->map(function ($agent) use ($startDate, $endDate) {
                // Sparkline: daily policy count
                $dailyData = $agent->policies
                    ->groupBy(fn($p) => $p->issue_date->toDateString())
                    ->map(fn($g) => $g->count())
                    ->toArray();

                return [
                    'id' => $agent->id,
                    'name' => $agent->name,
                    'photo' => $agent->photo,
                    'policies_count' => $agent->policies->count(),
                    'total_volume' => round($agent->policies->sum('premium_amount'), 2),
                    'sparkline' => array_values($dailyData),
                    'sparkline_labels' => array_keys($dailyData),
                ];
            })
            ->sortByDesc('policies_count')
            ->take(5)
            ->values();

        // ─── 4. TOP 5 PROMOTORES ──────────────────────
        $topPromoters = Promoter::with(['agents.policies' => function ($q) use ($startDate, $endDate) {
            $q->whereBetween('issue_date', [$startDate, $endDate]);
        }])->where('is_active', true)->get()
            ->map(function ($promoter) use ($promoterBonusSchemes, $startDate, $endDate) {
                $teamVolume = $promoter->agents->sum(function ($agent) {
                    return $agent->policies->sum('premium_amount');
                });

                // Calcular cuántos bonos de los 4 tiene asegurados
                $bonusesSecured = 0;
                $bonusesTotal = $promoterBonusSchemes->count();

                foreach ($promoterBonusSchemes as $scheme) {
                    $latestVersion = $scheme->versions()->orderByDesc('starts_at')->first();
                    if (!$latestVersion) continue;
                    $tier = $latestVersion->tiers->first();
                    if (!$tier) continue;
                    $conditions = $tier->conditions ?? [];
                    $target = (int) ($conditions['target'] ?? 0);
                    $metric = $conditions['metric'] ?? '';

                    $progress = 0;
                    if ($metric === 'recruits' || $metric === 'additional_recruits') {
                        $progress = Agent::where('promoter_id', $promoter->id)
                            ->whereBetween('created_at', [$startDate, $endDate])->count();
                    } elseif ($metric === 'global_sales') {
                        $progress = $teamVolume;
                    } elseif ($metric === 'developed_agents') {
                        $requiredProduct = $conditions['required_product'] ?? null;
                        $minPolicies = (int) ($conditions['min_policies'] ?? 1);
                        $progress = $promoter->agents->filter(function ($agent) use ($requiredProduct, $minPolicies) {
                            if ($requiredProduct) {
                                return $agent->policies->where('product_type', $requiredProduct)->count() >= $minPolicies;
                            }
                            return $agent->policies->count() >= $minPolicies;
                        })->count();
                    }

                    if ($progress >= $target && $target > 0) $bonusesSecured++;
                }

                return [
                    'id' => $promoter->id,
                    'name' => $promoter->name,
                    'photo' => $promoter->photo,
                    'team_volume' => round($teamVolume, 2),
                    'bonuses_secured' => $bonusesSecured,
                    'bonuses_total' => max($bonusesTotal, 4),
                ];
            })
            ->sortByDesc('team_volume')
            ->take(5)
            ->values();

        // ─── 5. TENDENCIAS ────────────────────────────
        $trendsRaw = Policy::whereBetween('issue_date', [$startDate, $endDate])
            ->selectRaw('DATE(issue_date) as date, COUNT(*) as count')
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        $trends = $trendsRaw->map(fn($r) => [
            'date' => $r->date,
            'count' => $r->count,
            'label' => Carbon::parse($r->date)->translatedFormat('d M'),
        ])->values();

        return Inertia::render('Dashboard/Index', [
            'filter' => $filter,
            'start_date' => $startDate->toDateString(),
            'end_date' => $endDate->toDateString(),
            'kpis' => [
                'net_income' => round($currentNetIncome, 2),
                'prev_net_income' => round($prevNetIncome, 2),
                'income_growth' => $incomeGrowth,
                'policies_by_product' => $policiesByProduct,
                'prev_policies_by_product' => $prevPoliciesByProduct,
                'total_policies' => $totalPolicies,
                'prev_total_policies' => $prevTotalPolicies,
                'policies_growth' => $policiesGrowth,
                'premium_by_product' => $premiumByProduct,
                'prev_premium_by_product' => $prevPremiumByProduct,
                'projected_bonuses' => round($projectedBonuses, 2),
                'projected_agent_bonuses' => round($projectedAgentBonuses, 2),
                'projected_promoter_bonuses' => round($projectedPromoterBonuses, 2),
                'prev_projected_bonuses' => round($prevProjectedBonuses, 2),
                'bonuses_growth' => $bonusesGrowth,
                'active_promoters' => $activePromoters,
                'active_agents' => $activeAgents,
            ],
            'alerts' => $alerts,
            'top_agents' => $topAgents,
            'top_promoters' => $topPromoters,
            'trends' => $trends,
        ]);
    }

    /**
     * Calcula la proyección de bonos basado en esquemas activos y pólizas actuales.
     */
    private function calculateProjectedBonuses($bonusSchemes, $policies): float
    {
        $total = 0;

        foreach ($bonusSchemes as $scheme) {
            $latestVersion = $scheme->versions()->orderByDesc('starts_at')->first();
            if (!$latestVersion) continue;

            foreach ($latestVersion->tiers as $tier) {
                $fixedAmount = (float) ($tier->fixed_amount ?? 0);
                if ($fixedAmount > 0) {
                    // Si es un bono fijo, se suma si hay prospectos que lo están alcanzando
                    $total += $fixedAmount;
                }
            }
        }

        return $total;
    }
}
