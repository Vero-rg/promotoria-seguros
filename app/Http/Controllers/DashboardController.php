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

        // PNA (Prima Neta Acumulada / Prima Pagada) y PCA (Prima Computable Acumulada)
        // PNA = total de prima pagada por el cliente (suma de premium_amount)
        // PCA = PNA - $1,500 por cada póliza (mín $0 por póliza). Es la base para cálculos de bonos.
        $totalPNA = round($policies->sum('premium_amount'), 2);
        $prevPNA = round($prevPolicies->sum('premium_amount'), 2);
        $totalPCA = round($policies->sum(fn($p) => max(0, (float) $p->premium_amount - 1500)), 2);
        $prevPCA = round($prevPolicies->sum(fn($p) => max(0, (float) $p->premium_amount - 1500)), 2);

        // Volumen de Pólizas (por tipo de producto) — usando nombres reales
        $policiesByProduct = [
            'METLIFE' => $policies->where('product_type', 'METLIFE')->count(),
            'PERFECTLIFE' => $policies->where('product_type', 'PERFECTLIFE')->count(),
            'PRIMORDIAL' => $policies->where('product_type', 'PRIMORDIAL')->count(),
        ];
        $prevPoliciesByProduct = [
            'METLIFE' => $prevPolicies->where('product_type', 'METLIFE')->count(),
            'PERFECTLIFE' => $prevPolicies->where('product_type', 'PERFECTLIFE')->count(),
            'PRIMORDIAL' => $prevPolicies->where('product_type', 'PRIMORDIAL')->count(),
        ];
        $totalPolicies = $policies->count();
        $prevTotalPolicies = $prevPolicies->count();
        $policiesGrowth = $prevTotalPolicies > 0 ? round((($totalPolicies - $prevTotalPolicies) / $prevTotalPolicies) * 100, 1) : 0;

        // Premios vendidos por producto (volumen en $)
        $premiumByProduct = [
            'METLIFE' => round($policies->where('product_type', 'METLIFE')->sum('premium_amount'), 2),
            'PERFECTLIFE' => round($policies->where('product_type', 'PERFECTLIFE')->sum('premium_amount'), 2),
            'PRIMORDIAL' => round($policies->where('product_type', 'PRIMORDIAL')->sum('premium_amount'), 2),
        ];
        $prevPremiumByProduct = [
            'METLIFE' => round($prevPolicies->where('product_type', 'METLIFE')->sum('premium_amount'), 2),
            'PERFECTLIFE' => round($prevPolicies->where('product_type', 'PERFECTLIFE')->sum('premium_amount'), 2),
            'PRIMORDIAL' => round($prevPolicies->where('product_type', 'PRIMORDIAL')->sum('premium_amount'), 2),
        ];

        // Bonos Proyectados: total + desglose agente/promotor
        $bonusSchemes = Scheme::where('type', 'bonus')->where('is_active', true)->get();
        $agentBonusSchemes = $bonusSchemes->where('target', 'agent');
        $promoterBonusSchemes = $bonusSchemes->where('target', 'promoter');

        $projectedAgentBonuses = $this->calculateProjectedBonuses($agentBonusSchemes, $policies);
        $projectedPromoterBonuses = $this->calculateProjectedBonuses($promoterBonusSchemes, $policies);
        $projectedBonuses = $projectedAgentBonuses + $projectedPromoterBonuses;

        $prevProjectedBonuses = $this->calculateProjectedBonuses($bonusSchemes, $prevPolicies);
        $bonusesGrowth = $prevProjectedBonuses > 0 ? round((($projectedBonuses - $prevProjectedBonuses) / $prevProjectedBonuses) * 100, 1) : 0;

        // Fuerza de Ventas Activa
        $activePromoters = Promoter::where('is_active', true)->count();
        $activeAgents = Agent::where('is_active', true)->count();

        // ─── 2. ALERTAS INTELIGENTES ──────────────────
        // Analiza agentes y promotores contra sus esquemas de bono/comisión
        // para detectar oportunidades: cerca de desbloquear, mezcla de productos, etc.

        $alerts = [];
        $bonusOrchestrator = app(\App\Services\BonusOrchestratorService::class);

        // ── 2a. Alerta de cierre de periodo ──────────────────────────
        $daysToClose = now()->diffInDays(now()->endOfQuarter(), false);
        if ($daysToClose > 0 && $daysToClose <= 10) {
            $alerts[] = [
                'type' => 'period_close',
                'message' => "⏰ Faltan {$daysToClose} días para el cierre trimestral. ¡Impulsa a tu equipo!",
                'icon' => 'clock',
            ];
        }

        // ── 2b. Alertas de Agentes ────────────────────────────────────
        // Evaluamos los agentes más productivos (top 20 por volumen)
        $topAgentsForAlerts = Agent::with(['policies' => function ($q) use ($startDate, $endDate) {
            $q->whereBetween('issue_date', [$startDate, $endDate]);
        }])->where('is_active', true)->get()
            ->sortByDesc(fn($a) => $a->policies->sum('premium_amount'))
            ->take(20);

        foreach ($topAgentsForAlerts as $agent) {
            if (count($alerts) >= 15) break;

            $policiesCount = $agent->policies->count();
            $totalVolume = round($agent->policies->sum('premium_amount'), 2);
            $pca = round($agent->policies->sum(fn($p) => max(0, (float) $p->premium_amount - 1500)), 2);

            // ── Comisiones: calcular lo generado vs lo potencial ──────
            $productCommissionMap = app(\App\Http\Controllers\PolicyController::class)->getProductCommissionMap();
            $currentCommission = $agent->policies->sum(function ($p) use ($productCommissionMap) {
                $map = $productCommissionMap[$p->product_type] ?? null;
                if ($map) return (float) $p->premium_amount * ($map['agent_percentage'] / 100);
                return (float) $p->commission_amount;
            });

            // Alertas de volumen bajo: si tiene pocas pólizas
            if ($policiesCount === 0 && $totalVolume === 0.0) {
                // No alertamos por inactividad total (demasiado ruido)
                continue;
            }

            // ── Evaluar cada bono activo para este agente ─────────────
            try {
                $orchestratorResult = $bonusOrchestrator->calculateAll(
                    user: $agent,
                    periodStart: $startDate,
                    periodEnd: $endDate,
                );
                $bonusesProgress = $bonusOrchestrator->toFrontendFormat($orchestratorResult);

                foreach ($bonusesProgress as $bonus) {
                    $name = $bonus['name'] ?? '';
                    $unlocked = $bonus['unlocked'] ?? false;
                    $conditions = $bonus['conditions'] ?? [];
                    $progress = (float) ($bonus['progress'] ?? 0);
                    $target = (float) ($bonus['target'] ?? 0);

                    if ($unlocked) continue; // Ya lo desbloqueó

                    $pct = $target > 0 ? round(($progress / $target) * 100, 1) : 0;

                    // Alerta si está entre 50% y 99% del objetivo principal
                    if ($pct >= 50 && $pct < 100 && $target > 0) {
                        $firstCond = $conditions[0] ?? null;
                        $metricLabel = $firstCond['label'] ?? 'progreso';
                        $remaining = $target - $progress;
                        $remainingFormatted = $remaining >= 1000
                            ? '$' . number_format($remaining, 0)
                            : round($remaining, 1);

                        $alerts[] = [
                            'type' => 'agent_near_bonus',
                            'message' => "🎯 {$agent->name} está al {$pct}% de desbloquear «{$name}» — le falta {$remainingFormatted} en {$metricLabel}.",
                            'icon' => 'target',
                        ];
                    }

                    // Alerta de mezcla de productos: si un bono requiere mix y el agente solo vende 1 tipo
                    foreach ($conditions as $cond) {
                        $met = $cond['met'] ?? true;
                        $label = $cond['label'] ?? '';
                        if (!$met && stripos($label, 'mix') !== false) {
                            $alerts[] = [
                                'type' => 'agent_product_mix',
                                'message' => "📦 {$agent->name} necesita diversificar productos para «{$name}» — requiere mezcla de ramos.",
                                'icon' => 'target',
                            ];
                        }
                    }

                    if (count($alerts) >= 15) break 2;
                }
            } catch (\Exception $e) {
                // Si falla el orquestador para un agente, continuamos con el siguiente
                continue;
            }

            // ── Alerta de comisión: si tiene pólizas pero poca comisión ──
            if ($policiesCount >= 2 && $currentCommission < $totalVolume * 0.05) {
                $alerts[] = [
                    'type' => 'agent_low_commission',
                    'message' => "💸 {$agent->name} tiene {$policiesCount} pólizas pero su comisión (\$" . number_format($currentCommission, 0) . ") es baja. Revisa los productos vendidos.",
                    'icon' => 'dollar',
                ];
            }
        }

        // ── 2c. Alertas de Promotores ──────────────────────────────────
        $topPromotersForAlerts = Promoter::with(['agents.policies' => function ($q) use ($startDate, $endDate) {
            $q->whereBetween('issue_date', [$startDate, $endDate]);
        }])->where('is_active', true)->get()
            ->sortByDesc(function ($p) {
                return $p->agents->sum(fn($a) => $a->policies->sum('premium_amount'));
            })
            ->take(15);

        foreach ($topPromotersForAlerts as $promoter) {
            if (count($alerts) >= 20) break;

            $teamVolume = $promoter->agents->sum(fn($a) => $a->policies->sum('premium_amount'));
            $agentCount = $promoter->agents->where('is_active', true)->count();
            $recruitsInPeriod = Agent::where('promoter_id', $promoter->id)
                ->whereBetween('created_at', [$startDate, $endDate])->count();

            // ── Evaluar cada bono para este promotor ──────────────────
            try {
                $orchestratorResult = $bonusOrchestrator->calculateAll(
                    user: $promoter,
                    periodStart: $startDate,
                    periodEnd: $endDate,
                );
                $bonusesProgress = $bonusOrchestrator->toFrontendFormat($orchestratorResult);

                foreach ($bonusesProgress as $bonus) {
                    $name = $bonus['name'] ?? '';
                    $unlocked = $bonus['unlocked'] ?? false;
                    $conditions = $bonus['conditions'] ?? [];
                    $progress = (float) ($bonus['progress'] ?? 0);
                    $target = (float) ($bonus['target'] ?? 0);

                    if ($unlocked) continue;

                    $pct = $target > 0 ? round(($progress / $target) * 100, 1) : 0;

                    // Alerta de bonos cerca de desbloquear (50-99%)
                    if ($pct >= 50 && $pct < 100 && $target > 0) {
                        $firstCond = $conditions[0] ?? null;
                        $metricLabel = $firstCond['label'] ?? 'progreso';
                        $remaining = $target - $progress;
                        $remainingFormatted = $remaining >= 1000
                            ? '$' . number_format($remaining, 0)
                            : round($remaining, 1);

                        $alerts[] = [
                            'type' => 'promoter_near_bonus',
                            'message' => "🏆 {$promoter->name} está al {$pct}% de «{$name}» — faltan {$remainingFormatted} en {$metricLabel}.",
                            'icon' => 'award',
                        ];
                    }

                    // Condiciones no cumplidas específicas
                    foreach ($conditions as $cond) {
                        $met = $cond['met'] ?? true;
                        $label = $cond['label'] ?? '';

                        // Reclutamiento bajo
                        if (!$met && (stripos($label, 'recluta') !== false || stripos($label, 'agente') !== false)) {
                            $current = $cond['current'] ?? 0;
                            $tgt = $cond['target'] ?? 0;
                            if ($current > 0 && $tgt > 0 && $current >= $tgt - 3) {
                                $alerts[] = [
                                    'type' => 'promoter_recruits',
                                    'message' => "👥 A {$promoter->name} le falta(n) " . ($tgt - $current) . " recluta(s) más para «{$name}» (tiene {$current} de {$tgt}).",
                                    'icon' => 'users',
                                ];
                            }
                        }

                        // IRP bajo
                        if (!$met && stripos($label, 'IRP') !== false) {
                            $current = $cond['current'] ?? 0;
                            $tgt = $cond['target'] ?? 0;
                            $alerts[] = [
                                'type' => 'promoter_low_irp',
                                'message' => "📉 {$promoter->name} tiene IRP de {$current}% (mín. {$tgt}%) para «{$name}». Revisa retención de pólizas.",
                                'icon' => 'alert',
                            ];
                        }
                    }

                    if (count($alerts) >= 20) break 2;
                }
            } catch (\Exception $e) {
                continue;
            }

            // ── Alerta de equipo sin reclutas ──────────────────────────
            if ($agentCount >= 3 && $recruitsInPeriod === 0) {
                $alerts[] = [
                    'type' => 'promoter_no_recruits',
                    'message' => "🧑‍🤝‍🧑 {$promoter->name} tiene {$agentCount} agentes pero 0 reclutas este periodo. Motívalo a expandir su red.",
                    'icon' => 'users',
                ];
            }

            // ── Alerta de agentes inactivos ────────────────────────────
            $inactiveAgents = $promoter->agents->where('is_active', false)->count();
            if ($inactiveAgents >= 2) {
                $alerts[] = [
                    'type' => 'promoter_inactive_agents',
                    'message' => "⚠️ {$promoter->name} tiene {$inactiveAgents} agentes inactivos. Considera dar de baja o reactivar.",
                    'icon' => 'alert',
                ];
            }
        }

        // ── 2d. Alerta Global: pólizas sin producto asignado ──────────
        $policiesWithoutProduct = $policies->where(fn($p) => empty($p->product_type))->count();
        if ($policiesWithoutProduct > 0) {
            $alerts[] = [
                'type' => 'data_quality',
                'message' => "📋 Hay {$policiesWithoutProduct} póliza(s) sin tipo de producto asignado. Corrige esto para métricas precisas.",
                'icon' => 'alert',
            ];
        }

        // Limitar alertas a 20 máximo para no saturar
        $alerts = array_slice($alerts, 0, 20);

        // ─── 3. TOP 5 AGENTES ─────────────────────────
        $topAgents = Agent::with(['policies' => function ($q) use ($startDate, $endDate) {
            $q->whereBetween('issue_date', [$startDate, $endDate]);
        }])->where('is_active', true)->get()
            ->map(function ($agent) use ($startDate, $endDate, $agentBonusSchemes) {
                // Sparkline: daily policy count
                $dailyData = $agent->policies
                    ->groupBy(fn($p) => $p->issue_date->toDateString())
                    ->map(fn($g) => $g->count())
                    ->toArray();

                // Calcular comisiones ganadas
                $productCommissionMap = app(\App\Http\Controllers\PolicyController::class)->getProductCommissionMap();
                $totalCommission = $agent->policies->sum(function ($policy) use ($productCommissionMap) {
                    $map = $productCommissionMap[$policy->product_type] ?? null;
                    if ($map) {
                        return (float) $policy->premium_amount * ($map['agent_percentage'] / 100);
                    }
                    return (float) $policy->commission_amount;
                });

                // Calcular bonos ganados por este agente (usando el orquestador)
                $bonusOrchestrator = app(\App\Services\BonusOrchestratorService::class);
                $orchestratorResult = $bonusOrchestrator->calculateAll(
                    user: $agent,
                    periodStart: $startDate,
                    periodEnd: $endDate,
                );
                $bonusesProgress = $bonusOrchestrator->toFrontendFormat($orchestratorResult);
                $bonusNames = collect($bonusesProgress)
                    ->filter(fn($b) => $b['unlocked'] ?? false)
                    ->pluck('name')
                    ->values()
                    ->toArray();
                $bonusDetails = collect($bonusesProgress)
                    ->filter(fn($b) => $b['unlocked'] ?? false)
                    ->map(fn($b) => [
                        'name' => $b['name'] ?? '',
                        'amount' => $b['amount'] ?? 0,
                        'progress_label' => $b['progress_label'] ?? '',
                    ])
                    ->values()
                    ->toArray();

                return [
                    'id' => $agent->id,
                    'name' => $agent->name,
                    'photo' => $agent->photo,
                    'policies_count' => $agent->policies->count(),
                    'total_volume' => round($agent->policies->sum('premium_amount'), 2),
                    'total_commission' => round($totalCommission, 2),
                    'bonus_names' => $bonusNames,
                    'bonus_details' => $bonusDetails,
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

                // Calcular cuántos bonos de los 4 tiene asegurados + nombres
                $bonusesSecured = 0;
                $bonusesTotal = $promoterBonusSchemes->count();
                $bonusNames = [];

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

                    if ($progress >= $target && $target > 0) {
                        $bonusesSecured++;
                        $bonusNames[] = $scheme->name;
                    }
                }

                // Bonus details usando el orquestador para obtener montos
                $bonusOrchestrator = app(\App\Services\BonusOrchestratorService::class);
                $orchestratorResult = $bonusOrchestrator->calculateAll(
                    user: $promoter,
                    periodStart: $startDate,
                    periodEnd: $endDate,
                );
                $bonusesProgress = $bonusOrchestrator->toFrontendFormat($orchestratorResult);
                $bonusDetails = collect($bonusesProgress)
                    ->filter(fn($b) => $b['unlocked'] ?? false)
                    ->map(fn($b) => [
                        'name' => $b['name'] ?? '',
                        'amount' => $b['amount'] ?? 0,
                        'progress_label' => $b['progress_label'] ?? '',
                    ])
                    ->values()
                    ->toArray();

                // Reconstruir bonusNames desde el orquestador (más preciso)
                $bonusNames = collect($bonusesProgress)
                    ->filter(fn($b) => $b['unlocked'] ?? false)
                    ->pluck('name')
                    ->values()
                    ->toArray();
                $bonusesSecured = count($bonusNames);
                $bonusesTotal = max(count($bonusesProgress), 4);

                return [
                    'id' => $promoter->id,
                    'name' => $promoter->name,
                    'photo' => $promoter->photo,
                    'team_volume' => round($teamVolume, 2),
                    'bonuses_secured' => $bonusesSecured,
                    'bonuses_total' => $bonusesTotal,
                    'bonus_names' => $bonusNames,
                    'bonus_details' => $bonusDetails,
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
                'total_pna' => $totalPNA,
                'prev_pna' => $prevPNA,
                'total_pca' => $totalPCA,
                'prev_pca' => $prevPCA,
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
     * PCA = PNA - $1,500 por póliza (base para cálculos de bonos).
     */
    private function calculateProjectedBonuses($bonusSchemes, $policies): float
    {
        $total = 0;

        // PCA total del periodo (base para proyecciones porcentuales)
        $totalPCA = $policies->sum(fn($p) => max(0, (float) $p->premium_amount - 1500));

        foreach ($bonusSchemes as $scheme) {
            $latestVersion = $scheme->versions()->orderByDesc('starts_at')->first();
            if (!$latestVersion) continue;

            // Encontrar el tier con mayor porcentaje (mejor escenario)
            $bestTier = $latestVersion->tiers->sortByDesc(function ($tier) {
                return (float) ($tier->agent_percentage ?? $tier->agent_automatic_percentage ?? $tier->promoter_percentage ?? 0);
            })->first();

            if (!$bestTier) continue;

            $fixedAmount = (float) ($bestTier->fixed_amount ?? 0);
            if ($fixedAmount > 0) {
                $total += $fixedAmount;
            } else {
                // Usar el porcentaje del mejor tier sobre la PCA total
                $percentage = (float) (
                    $bestTier->agent_percentage
                    ?? $bestTier->agent_automatic_percentage
                    ?? $bestTier->promoter_percentage
                    ?? 0
                );
                if ($percentage > 0) {
                    $total += $totalPCA * ($percentage / 100);
                }
            }
        }

        return round($total, 2);
    }
}
