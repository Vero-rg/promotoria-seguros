<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Models\Policy;
use App\Models\Promoter;
use App\Models\Scheme;
use App\Services\BonusOrchestratorService;
use App\Services\CommissionBreakdownService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function __construct(private CommissionBreakdownService $commissions) {}

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
            case 'q1':
                $startDate = Carbon::create(now()->year, 1, 1)->startOfDay();
                $endDate = Carbon::create(now()->year, 3, 31)->endOfDay();
                break;
            case 'q2':
                $startDate = Carbon::create(now()->year, 4, 1)->startOfDay();
                $endDate = Carbon::create(now()->year, 6, 30)->endOfDay();
                break;
            case 'q3':
                $startDate = Carbon::create(now()->year, 7, 1)->startOfDay();
                $endDate = Carbon::create(now()->year, 9, 30)->endOfDay();
                break;
            case 'q4':
                $startDate = Carbon::create(now()->year, 10, 1)->startOfDay();
                $endDate = Carbon::create(now()->year, 12, 31)->endOfDay();
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
            ->where('status', Policy::STATUS_PAGADA)
            ->get();

        $prevPolicies = Policy::whereBetween('issue_date', [$prevStart, $prevEnd])
            ->where('status', Policy::STATUS_PAGADA)
            ->get();

        // ─── 1. KPIs GLOBALES ─────────────────────────

        // PNA (Prima Neta Acumulada) y PCA (Prima Computable Acumulada)
        // PNA = total de prima pagada por el cliente (suma de premium_amount)
        // PCA = PNA - $1,350 por cada póliza (mín $0 por póliza). Es la base de comisiones y bonos.
        $totalPNA = round($policies->sum('premium_amount'), 2);
        $prevPNA = round($prevPolicies->sum('premium_amount'), 2);
        $totalPCA = round($policies->sum(fn ($p) => $p->pcaAmount()), 2);
        $prevPCA = round($prevPolicies->sum(fn ($p) => $p->pcaAmount()), 2);

        // Ingreso Neto: PNA menos las comisiones netas (agente + promotor) ya pagadas.
        // Cada comisión descuenta ISR, impuesto cedular y costo de facturación,
        // por lo que el resultado es lo que queda a la promotoría del periodo.
        $currentCommissions = $this->commissions->summarize($policies, CommissionBreakdownService::ROLE_AGENT);
        $currentPromoterCommissions = $this->commissions->summarize($policies, CommissionBreakdownService::ROLE_PROMOTER);
        $currentNetCommissions = round($currentCommissions['net'] + $currentPromoterCommissions['net'], 2);

        $prevCommissions = $this->commissions->summarize($prevPolicies, CommissionBreakdownService::ROLE_AGENT);
        $prevPromoterCommissions = $this->commissions->summarize($prevPolicies, CommissionBreakdownService::ROLE_PROMOTER);
        $prevNetCommissions = round($prevCommissions['net'] + $prevPromoterCommissions['net'], 2);

        $currentNetIncome = round($totalPNA - $currentNetCommissions, 2);
        $prevNetIncome = round($prevPNA - $prevNetCommissions, 2);
        $incomeGrowth = $prevNetIncome > 0 ? round((($currentNetIncome - $prevNetIncome) / $prevNetIncome) * 100, 1) : 0;

        // Volumen de Pólizas (por tipo de producto) — usando nombres reales
        $policiesByProduct = [
            'METALIFE' => $policies->where('product_type', 'METALIFE')->count(),
            'PERFECTLIFE' => $policies->where('product_type', 'PERFECTLIFE')->count(),
            'PRIMORDIAL' => $policies->where('product_type', 'PRIMORDIAL')->count(),
        ];
        $prevPoliciesByProduct = [
            'METALIFE' => $prevPolicies->where('product_type', 'METALIFE')->count(),
            'PERFECTLIFE' => $prevPolicies->where('product_type', 'PERFECTLIFE')->count(),
            'PRIMORDIAL' => $prevPolicies->where('product_type', 'PRIMORDIAL')->count(),
        ];
        $totalPolicies = $policies->count();
        $prevTotalPolicies = $prevPolicies->count();
        $policiesGrowth = $prevTotalPolicies > 0 ? round((($totalPolicies - $prevTotalPolicies) / $prevTotalPolicies) * 100, 1) : 0;

        // Premios vendidos por producto (volumen en $)
        $premiumByProduct = [
            'METALIFE' => round($policies->where('product_type', 'METALIFE')->sum('premium_amount'), 2),
            'PERFECTLIFE' => round($policies->where('product_type', 'PERFECTLIFE')->sum('premium_amount'), 2),
            'PRIMORDIAL' => round($policies->where('product_type', 'PRIMORDIAL')->sum('premium_amount'), 2),
        ];
        $prevPremiumByProduct = [
            'METALIFE' => round($prevPolicies->where('product_type', 'METALIFE')->sum('premium_amount'), 2),
            'PERFECTLIFE' => round($prevPolicies->where('product_type', 'PERFECTLIFE')->sum('premium_amount'), 2),
            'PRIMORDIAL' => round($prevPolicies->where('product_type', 'PRIMORDIAL')->sum('premium_amount'), 2),
        ];

        // Bonos Proyectados: total + desglose agente/promotor
        $bonusSchemes = Scheme::where('type', 'bonus')->where('is_active', true)->get();

        // ── Calcular bonos reales usando BonusOrchestratorService ─────
        $bonusOrchestrator = app(BonusOrchestratorService::class);

        $projectedAgentBonuses = 0;
        $projectedPromoterBonuses = 0;

        // ── Fechas de promotoría: los bonos de promotor se evalúan
        //     sobre el trimestre completo, no solo el mes visual ──────
        $promoterStart = $endDate->copy()->startOfQuarter();
        $promoterEnd = $endDate->copy()->endOfQuarter();

        // Agentes activos con pólizas en el periodo
        $activeAgentsWithPolicies = Agent::with(['promoter', 'policies' => function ($q) use ($startDate, $endDate) {
            $q->whereBetween('issue_date', [$startDate, $endDate]);
        }])->where('is_active', true)->get()->filter(fn ($a) => $a->policies->isNotEmpty());

        foreach ($activeAgentsWithPolicies as $agent) {
            try {
                $result = $bonusOrchestrator->calculateAll(
                    user: $agent,
                    periodStart: $startDate,
                    periodEnd: $endDate,
                    visualRangeStart: $startDate,
                    visualRangeEnd: $endDate,
                );
                $projectedAgentBonuses += $result['summary']['total_amount'] ?? 0;
            } catch (\Exception $e) {
                continue;
            }
        }

        // Promotores activos con equipo con pólizas en el periodo
        $activePromotersWithActivity = Promoter::with(['agents.policies' => function ($q) use ($promoterStart, $promoterEnd) {
            $q->whereBetween('issue_date', [$promoterStart, $promoterEnd]);
        }])->where('is_active', true)->get()->filter(function ($p) {
            return $p->agents->sum(fn ($a) => $a->policies->count()) > 0;
        });

        foreach ($activePromotersWithActivity as $promoter) {
            try {
                $result = $bonusOrchestrator->calculateAll(
                    user: $promoter,
                    periodStart: $startDate,
                    periodEnd: $endDate,
                    visualRangeStart: $startDate,
                    visualRangeEnd: $endDate,
                );
                $projectedPromoterBonuses += $result['summary']['total_amount'] ?? 0;
            } catch (\Exception $e) {
                continue;
            }
        }

        $projectedBonuses = round($projectedAgentBonuses + $projectedPromoterBonuses, 2);

        // Periodo anterior: estimación simplificada (no podemos re-ejecutar el orquestador)
        $prevProjectedBonuses = $this->calculateProjectedBonuses($bonusSchemes, $prevPolicies);
        $bonusesGrowth = $prevProjectedBonuses > 0 ? round((($projectedBonuses - $prevProjectedBonuses) / $prevProjectedBonuses) * 100, 1) : 0;

        // Fuerza de Ventas Activa
        $activePromoters = Promoter::where('is_active', true)->count();
        $activeAgents = Agent::where('is_active', true)->count();

        // ─── 2. ALERTAS INTELIGENTES ──────────────────
        // Analiza agentes y promotores contra sus esquemas de bono/comisión
        // para detectar oportunidades: cerca de desbloquear, mezcla de productos, etc.

        $alerts = [];

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
        $topAgentsForAlerts = Agent::with(['promoter', 'policies' => function ($q) use ($startDate, $endDate) {
            $q->whereBetween('issue_date', [$startDate, $endDate]);
        }])->where('is_active', true)->get()
            ->sortByDesc(fn ($a) => $a->policies->sum('premium_amount'))
            ->take(20);

        foreach ($topAgentsForAlerts as $agent) {
            if (count($alerts) >= 15) {
                break;
            }

            $policiesCount = $agent->policies->count();
            $totalVolume = round($agent->policies->sum('premium_amount'), 2);
            $pca = round($agent->policies->sum(fn ($p) => $p->pcaAmount()), 2);

            // ── Comisiones: calcular lo generado vs lo potencial ──────
            $currentCommission = $this->commissions->summarize($agent->policies, CommissionBreakdownService::ROLE_AGENT)['gross'];

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
                    visualRangeStart: $startDate,
                    visualRangeEnd: $endDate,
                );
                $bonusesProgress = $bonusOrchestrator->toFrontendFormat($orchestratorResult);

                foreach ($bonusesProgress as $bonus) {
                    $name = $bonus['name'] ?? '';
                    $unlocked = $bonus['unlocked'] ?? false;
                    $conditions = $bonus['conditions'] ?? [];
                    $progress = (float) ($bonus['progress'] ?? 0);
                    $target = (float) ($bonus['target'] ?? 0);

                    if ($unlocked) {
                        continue;
                    } // Ya lo desbloqueó

                    // ── Alerta inteligente: Primordial es la única condición faltante ──
                    if (($bonus['template_key'] ?? '') === 'first_year_production') {
                        $unmetConditions = array_filter($conditions, fn ($c) => ! ($c['met'] ?? true));
                        if (count($unmetConditions) === 1) {
                            $onlyUnmet = array_values($unmetConditions)[0];
                            if (stripos($onlyUnmet['label'] ?? '', 'Primordial') !== false) {
                                $alerts[] = [
                                    'type' => 'warning',
                                    'message' => "¡Casi listo! A {$agent->name} solo le falta cumplir con el requisito de pólizas Primordial para desbloquear su Bono de Producción de 1.er Año.",
                                    'icon' => 'Award',
                                ];
                            }
                        }
                    }

                    $pct = $target > 0 ? round(($progress / $target) * 100, 1) : 0;

                    // Alerta si está entre 50% y 99% del objetivo principal
                    if ($pct >= 50 && $pct < 100 && $target > 0) {
                        $firstCond = $conditions[0] ?? null;
                        $metricLabel = $firstCond['label'] ?? 'progreso';
                        $remaining = $target - $progress;
                        $remainingFormatted = $remaining >= 1000
                            ? '$'.number_format($remaining, 0)
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
                        if (! $met && stripos($label, 'mix') !== false) {
                            $alerts[] = [
                                'type' => 'agent_product_mix',
                                'message' => "📦 {$agent->name} necesita diversificar productos para «{$name}» — requiere mezcla de ramos.",
                                'icon' => 'target',
                            ];
                        }
                    }

                    if (count($alerts) >= 15) {
                        break 2;
                    }
                }
            } catch (\Exception $e) {
                // Si falla el orquestador para un agente, continuamos con el siguiente
                continue;
            }

            // ── Alerta de comisión: si tiene pólizas pero poca comisión ──
            if ($policiesCount >= 2 && $currentCommission < $totalVolume * 0.05) {
                $alerts[] = [
                    'type' => 'agent_low_commission',
                    'message' => "💸 {$agent->name} tiene {$policiesCount} pólizas pero su comisión (\$".number_format($currentCommission, 0).') es baja. Revisa los productos vendidos.',
                    'icon' => 'dollar',
                ];
            }
        }

        // ── 2c. Alerta de ritmo mensual de producto ────────────────────
        //     Verifica si los agentes en su primer año, con bonos trimestrales
        //     que requieren productos, llevan al menos 1 póliza este mes.
        //     Evalúa TODOS los agentes elegibles (no solo top performers).
        $monthlyPaceAlerts = $this->generateMonthlyProductPaceAlerts($startDate, $endDate);
        $alerts = array_merge($alerts, array_slice($monthlyPaceAlerts, 0, 10));

        // ── 2d. Alertas de Promotores ──────────────────────────────────
        $topPromotersForAlerts = Promoter::with(['agents.policies' => function ($q) use ($promoterStart, $promoterEnd) {
            $q->whereBetween('issue_date', [$promoterStart, $promoterEnd]);
        }])->where('is_active', true)->get()
            ->sortByDesc(function ($p) {
                return $p->agents->sum(fn ($a) => $a->policies->sum('premium_amount'));
            })
            ->take(15);

        foreach ($topPromotersForAlerts as $promoter) {
            if (count($alerts) >= 20) {
                break;
            }

            $teamVolume = $promoter->agents->sum(fn ($a) => $a->policies->sum('premium_amount'));
            $agentCount = $promoter->agents->where('is_active', true)->count();
            $recruitsInPeriod = Agent::where('promoter_id', $promoter->id)
                ->whereBetween('created_at', [$startDate, $endDate])->count();

            // ── Evaluar cada bono para este promotor ──────────────────
            try {
                $orchestratorResult = $bonusOrchestrator->calculateAll(
                    user: $promoter,
                    periodStart: $startDate,
                    periodEnd: $endDate, visualRangeStart: $startDate,
                    visualRangeEnd: $endDate, );
                $bonusesProgress = $bonusOrchestrator->toFrontendFormat($orchestratorResult);

                foreach ($bonusesProgress as $bonus) {
                    $name = $bonus['name'] ?? '';
                    $unlocked = $bonus['unlocked'] ?? false;
                    $conditions = $bonus['conditions'] ?? [];
                    $progress = (float) ($bonus['progress'] ?? 0);
                    $target = (float) ($bonus['target'] ?? 0);

                    if ($unlocked) {
                        continue;
                    }

                    // ── Alerta inteligente: Primordial es la única condición faltante ──
                    if (($bonus['template_key'] ?? '') === 'first_year_production') {
                        $unmetConditions = array_filter($conditions, fn ($c) => ! ($c['met'] ?? true));
                        if (count($unmetConditions) === 1) {
                            $onlyUnmet = array_values($unmetConditions)[0];
                            if (stripos($onlyUnmet['label'] ?? '', 'Primordial') !== false) {
                                $alerts[] = [
                                    'type' => 'warning',
                                    'message' => "¡Casi listo! A {$promoter->name} solo le falta cumplir con el requisito de pólizas Primordial para desbloquear su Bono de Producción de 1.er Año.",
                                    'icon' => 'Award',
                                ];
                            }
                        }
                    }

                    $pct = $target > 0 ? round(($progress / $target) * 100, 1) : 0;

                    // Alerta de bonos cerca de desbloquear (50-99%)
                    if ($pct >= 50 && $pct < 100 && $target > 0) {
                        $firstCond = $conditions[0] ?? null;
                        $metricLabel = $firstCond['label'] ?? 'progreso';
                        $remaining = $target - $progress;
                        $remainingFormatted = $remaining >= 1000
                            ? '$'.number_format($remaining, 0)
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
                        if (! $met && (stripos($label, 'recluta') !== false || stripos($label, 'agente') !== false)) {
                            $current = $cond['current'] ?? 0;
                            $tgt = $cond['target'] ?? 0;
                            if ($current > 0 && $tgt > 0 && $current >= $tgt - 3) {
                                $alerts[] = [
                                    'type' => 'promoter_recruits',
                                    'message' => "👥 A {$promoter->name} le falta(n) ".($tgt - $current)." recluta(s) más para «{$name}» (tiene {$current} de {$tgt}).",
                                    'icon' => 'users',
                                ];
                            }
                        }

                        // IRP bajo
                        if (! $met && stripos($label, 'IRP') !== false) {
                            $current = $cond['current'] ?? 0;
                            $tgt = $cond['target'] ?? 0;
                            $alerts[] = [
                                'type' => 'promoter_low_irp',
                                'message' => "📉 {$promoter->name} tiene IRP de {$current}% (mín. {$tgt}%) para «{$name}». Revisa retención de pólizas.",
                                'icon' => 'alert',
                            ];
                        }
                    }

                    if (count($alerts) >= 20) {
                        break 2;
                    }
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
        $policiesWithoutProduct = $policies->where(fn ($p) => empty($p->product_type))->count();
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
        $topAgents = Agent::with(['promoter', 'policies' => function ($q) use ($startDate, $endDate) {
            $q->whereBetween('issue_date', [$startDate, $endDate]);
        }])->where('is_active', true)->get()
            ->map(function ($agent) use ($startDate, $endDate, $bonusOrchestrator) {
                // Sparkline: daily policy count
                $dailyData = $agent->policies
                    ->groupBy(fn ($p) => $p->issue_date->toDateString())
                    ->map(fn ($g) => $g->count())
                    ->toArray();

                // Calcular comisiones con desglose ISR → cedular → facturación
                $commissionBreakdown = $this->commissions->summarize($agent->policies, CommissionBreakdownService::ROLE_AGENT);

                // Calcular bonos ganados por este agente (usando el orquestador)
                $orchestratorResult = $bonusOrchestrator->calculateAll(
                    user: $agent,
                    periodStart: $startDate,
                    periodEnd: $endDate,
                    visualRangeStart: $startDate,
                    visualRangeEnd: $endDate,
                );
                $bonusesProgress = $bonusOrchestrator->toFrontendFormat($orchestratorResult);
                $bonusNames = collect($bonusesProgress)
                    ->filter(fn ($b) => $b['unlocked'] ?? false)
                    ->pluck('name')
                    ->values()
                    ->toArray();
                $bonusDetails = collect($bonusesProgress)
                    ->filter(fn ($b) => $b['unlocked'] ?? false)
                    ->map(fn ($b) => [
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
                    'total_commission' => $commissionBreakdown['gross'],
                    'commission_breakdown' => $commissionBreakdown,
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
            ->map(function ($promoter) use ($startDate, $endDate, $bonusOrchestrator) {
                $teamVolume = $promoter->agents->sum(function ($agent) {
                    return $agent->policies->sum('premium_amount');
                });

                // ── Bonos: cálculo delegado 100% al orquestador (Strategy) ──
                $orchestratorResult = $bonusOrchestrator->calculateAll(
                    user: $promoter,
                    periodStart: $startDate,
                    periodEnd: $endDate,
                    visualRangeStart: $startDate,
                    visualRangeEnd: $endDate,
                );
                $bonusesProgress = $bonusOrchestrator->toFrontendFormat($orchestratorResult);
                $bonusDetails = collect($bonusesProgress)
                    ->filter(fn ($b) => $b['unlocked'] ?? false)
                    ->map(fn ($b) => [
                        'name' => $b['name'] ?? '',
                        'amount' => $b['amount'] ?? 0,
                        'progress_label' => $b['progress_label'] ?? '',
                    ])
                    ->values()
                    ->toArray();

                // Reconstruir bonusNames desde el orquestador (más preciso)
                $bonusNames = collect($bonusesProgress)
                    ->filter(fn ($b) => $b['unlocked'] ?? false)
                    ->pluck('name')
                    ->values()
                    ->toArray();
                $bonusesSecured = count($bonusNames);
                $bonusesTotal = max(count($bonusesProgress), 4);

                // ── Comisiones del promotor con desglose ─────────────
                $teamPolicies = $promoter->agents->flatMap(fn ($agent) => $agent->policies);
                $commissionBreakdown = $this->commissions->summarize($teamPolicies, CommissionBreakdownService::ROLE_PROMOTER);

                return [
                    'id' => $promoter->id,
                    'name' => $promoter->name,
                    'photo' => $promoter->photo,
                    'team_volume' => round($teamVolume, 2),
                    'bonuses_secured' => $bonusesSecured,
                    'bonuses_total' => $bonusesTotal,
                    'bonus_names' => $bonusNames,
                    'bonus_details' => $bonusDetails,
                    'total_commission' => $commissionBreakdown['gross'],
                    'commission_breakdown' => $commissionBreakdown,
                ];
            })
            ->sortByDesc('team_volume')
            ->take(5)
            ->values();

        // ─── 5. TENDENCIAS ────────────────────────────
        // Periodos cortos se muestran por día; periodos largos, por mes.
        $trend = $this->buildTrend($startDate, $endDate);
        $trends = $trend['points'];

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
            'trend_grouping' => $trend['grouping'],
        ]);
    }

    /**
     * Construye la serie de tendencia de pólizas pagadas, agrupada por día
     * (periodos de hasta 62 días) o por mes (periodos más largos).
     *
     * @return array{grouping: string, points: array<int, array{date: string, label: string, count: int, premium: float}>}
     */
    private function buildTrend(Carbon $startDate, Carbon $endDate): array
    {
        $grouping = $startDate->diffInDays($endDate) <= 62 ? 'day' : 'month';

        $rows = Policy::whereBetween('issue_date', [$startDate, $endDate])
            ->where('status', Policy::STATUS_PAGADA)
            ->selectRaw('DATE(issue_date) as date, COUNT(*) as aggregate, COALESCE(SUM(premium_amount), 0) as premium')
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        $totals = [];

        foreach ($rows as $row) {
            $key = $grouping === 'day' ? $row->date : substr($row->date, 0, 7);

            $totals[$key] ??= ['count' => 0, 'premium' => 0.0];
            $totals[$key]['count'] += (int) $row->aggregate;
            $totals[$key]['premium'] += (float) $row->premium;
        }

        $points = [];
        $cursor = $grouping === 'day' ? $startDate->copy()->startOfDay() : $startDate->copy()->startOfMonth();
        $last = $endDate->copy()->startOfDay();

        while ($cursor->lessThanOrEqualTo($last)) {
            $key = $cursor->format($grouping === 'day' ? 'Y-m-d' : 'Y-m');
            $totals[$key] ??= ['count' => 0, 'premium' => 0.0];

            $points[] = [
                'date' => $cursor->toDateString(),
                'label' => $cursor->translatedFormat($grouping === 'day' ? 'd M' : 'M Y'),
                'count' => $totals[$key]['count'],
                'premium' => round($totals[$key]['premium'], 2),
            ];

            $grouping === 'day' ? $cursor->addDay() : $cursor->addMonth();
        }

        return ['grouping' => $grouping, 'points' => $points];
    }

    /**
     * Calcula la proyección de bonos basado en esquemas activos y pólizas actuales.
     * PCA = PNA - $1,350 por póliza (base para cálculos de bonos).
     *
     * IMPORTANTE: No se puede usar ?? para encadenar porcentajes porque un 0
     * legítimo en una columna (ej. agent_percentage = 0 en un esquema de
     * promotor) no es null y bloquearía el fallback a la columna correcta.
     * En su lugar se elige la tasa según el target del esquema y se valida > 0.
     */
    private function calculateProjectedBonuses($bonusSchemes, $policies): float
    {
        $total = 0;

        // PCA total del periodo (base para proyecciones porcentuales)
        $totalPCA = $policies->sum(fn ($p) => $p->pcaAmount());

        foreach ($bonusSchemes as $scheme) {
            $latestVersion = $scheme->versions()->orderByDesc('starts_at')->first();
            if (! $latestVersion) {
                continue;
            }

            // Elegir la tasa correcta PARA ESTE ESQUEMA según su target,
            // evitando el falso positivo de ?? con valor 0.
            $resolvePct = function ($tier) use ($scheme): float {
                if ($scheme->target === 'promoter') {
                    return (float) ($tier->promoter_percentage ?? 0);
                }
                // agent o both: priorizar agent_percentage, luego agent_automatic_percentage
                $agentPct = (float) ($tier->agent_percentage ?? 0);
                if ($agentPct > 0) {
                    return $agentPct;
                }
                $autoPct = (float) ($tier->agent_automatic_percentage ?? 0);
                if ($autoPct > 0) {
                    return $autoPct;
                }

                // Fallback: si todo es 0, intentar promoter_percentage
                return (float) ($tier->promoter_percentage ?? 0);
            };

            // Encontrar el tier con mayor porcentaje positivo (mejor escenario)
            $bestTier = $latestVersion->tiers
                ->sortByDesc(fn ($tier) => $resolvePct($tier))
                ->first();

            if (! $bestTier) {
                continue;
            }

            $fixedAmount = (float) ($bestTier->fixed_amount ?? 0);
            if ($fixedAmount > 0) {
                $total += $fixedAmount;
            } else {
                $percentage = $resolvePct($bestTier);
                if ($percentage > 0) {
                    $total += $totalPCA * ($percentage / 100);
                }
            }
        }

        return round($total, 2);
    }

    /**
     * Genera alertas de ritmo mensual de producto para agentes en su primer año.
     *
     * Evalúa TODOS los agentes elegibles (no solo top performers) que:
     * - Están en su primer año desde created_at
     * - Tienen un bono trimestral activo con requires_product configurado
     * - No han emitido al menos 1 póliza del producto requerido este mes
     *
     * Genera alertas a 7 y 3 días del cierre del mes.
     */
    private function generateMonthlyProductPaceAlerts($startDate, $endDate): array
    {
        $alerts = [];
        $now = now();
        $daysUntilMonthEnd = $now->copy()->endOfMonth()->diffInDays($now, false);

        // Solo generar alertas cuando quedan 7 días o menos
        if ($daysUntilMonthEnd > 7 || $daysUntilMonthEnd < 0) {
            return [];
        }

        $monthStart = $now->copy()->startOfMonth();
        $monthEnd = $now->copy()->endOfMonth();

        // ── Cargar esquemas trimestrales activos con requires_product ──
        $trimestralSchemes = Scheme::where('type', 'bonus')
            ->where('is_active', true)
            ->where('frequency', 'trimestral')
            ->whereNotNull('requires_product')
            ->where('target', 'agent')
            ->get()
            ->filter(function ($scheme) {
                $rp = $scheme->requires_product;

                return ! empty($rp) && is_array($rp);
            });

        if ($trimestralSchemes->isEmpty()) {
            return [];
        }

        // ── Cargar TODOS los agentes activos en su primer año ──────────
        $firstYearAgents = Agent::where('is_active', true)
            ->where('created_at', '>=', $now->copy()->subYear())
            ->get();

        foreach ($firstYearAgents as $agent) {
            foreach ($trimestralSchemes as $scheme) {
                $requiresProduct = $scheme->requires_product;

                foreach ($requiresProduct as $item) {
                    $productType = is_array($item) ? ($item['product_type'] ?? '') : $item;
                    if ($productType === '') {
                        continue;
                    }

                    // Resolver alias: 'Vida' → ['METALIFE', 'PERFECTLIFE']
                    $resolvedTypes = $this->resolveProductTypesForAlert($productType);

                    // Contar pólizas del agente este mes
                    $countThisMonth = Policy::where('agent_id', $agent->id)
                        ->whereIn('product_type', $resolvedTypes)
                        ->whereBetween('issue_date', [$monthStart, $monthEnd])
                        ->where('status', Policy::STATUS_PAGADA)
                        ->count();

                    if ($countThisMonth >= 1) {
                        continue; // Ya tiene al menos 1, sin alerta
                    }

                    // Determinar nivel de urgencia
                    if ($daysUntilMonthEnd <= 3) {
                        $alerts[] = [
                            'type' => 'product_monthly_pace_urgent',
                            'message' => "🚨 {$agent->name} aún no tiene una póliza {$productType} este mes para el bono «{$scheme->name}» — quedan {$daysUntilMonthEnd} días.",
                            'icon' => 'alert-triangle',
                        ];
                    } elseif ($daysUntilMonthEnd <= 7) {
                        $alerts[] = [
                            'type' => 'product_monthly_pace',
                            'message' => "⏳ {$agent->name} aún no tiene una póliza {$productType} este mes para el bono «{$scheme->name}» — quedan {$daysUntilMonthEnd} días.",
                            'icon' => 'clock',
                        ];
                    }
                }
            }
        }

        return $alerts;
    }

    /**
     * Resuelve un tipo de producto, incluyendo el alias 'Vida'.
     */
    private function resolveProductTypesForAlert(string $productType): array
    {
        $aliases = ['Vida' => ['METALIFE', 'PERFECTLIFE']];

        return $aliases[$productType] ?? [$productType];
    }
}
