<?php

namespace App\Services\BonusCalculators;

use App\Models\Agent;
use App\Models\Policy;
use App\Models\Promoter;
use App\Models\Scheme;
use App\Models\SchemeTier;
use App\Models\SchemeVersion;
use Carbon\Carbon;

/**
 * Calcula el Bono por Desarrollo Mensual (Monthly Development).
 *
 * REGLAS DE NEGOCIO — PROMOTER:
 *   1. El Promoter debe cumplir eficiencia al cobro >= min_collection_efficiency.
 *   2. El Promoter debe cumplir la cuota trimestral de reclutamiento (quarterly_recruits).
 *   3. Se evalúa cada Agent bajo el Promoter por separado:
 *      - PCA individual del agente en el periodo.
 *      - Antigüedad del agente (meses desde created_at).
 *   4. El tier se determina por: PCA del agente >= min_pca Y tenure en [min_month, max_month].
 *   5. El monto total es la suma de (PCA del agente × promoter_percentage del tier).
 *
 * Condiciones leídas de $tier->conditions:
 *   - min_pca    (float)  PCA mínimo individual del agente.
 *   - min_month  (int)    Mes mínimo de antigüedad del agente.
 *   - max_month  (int|null) Mes máximo de antigüedad (null = sin límite).
 */
class MonthlyDevelopmentCalculator implements BonusCalculatorInterface
{
    /**
     * {@inheritDoc}
     */
    public function calculate(
        Agent|Promoter $user,
        Scheme $scheme,
        Carbon $periodStart,
        Carbon $periodEnd
    ): array {
        // ── Solo aplica a Promoters ────────────────────────────────────
        if ($user instanceof Agent) {
            return $this->notAchieved(
                reason: 'El Bono de Desarrollo Mensual (Promoter) solo aplica a Promotores.',
            );
        }

        // ── 1. Versión activa ──────────────────────────────────────────
        $version = $this->resolveActiveVersion($scheme, $periodStart, $periodEnd);
        if ($version === null) {
            return $this->notAchieved('No hay versión vigente del esquema para el periodo solicitado.');
        }

        $tiers = $version->tiers()->get()
            ->sortByDesc(function (SchemeTier $t) {
                // Ordenar por porcentaje descendente, luego por min_pca descendente
                return [
                    (float) ($t->promoter_percentage ?? 0),
                    (float) ($t->conditions['min_pca'] ?? 0),
                ];
            })
            ->values();
        if ($tiers->isEmpty()) {
            return $this->notAchieved('El esquema no tiene tiers configurados.');
        }

        // ── 2. Validar que el promotor tenga agentes activos/en periodo ──
        $agents = $user->agents()->activeInPeriod($periodEnd->toDateString())->get();

        if ($agents->isEmpty()) {
            return $this->notAchieved(
                reason: 'El promotor no tiene agentes activos.',
                requiredEfficiency: $minEfficiency ?? ($scheme->min_collection_efficiency ?? null),
            );
        }

        // ── 3. Validar Reglas Globales ──────────────────────────────────

        // 3a. Cuota trimestral de reclutamiento (cálculo anticipado)
        $quarterlyQuota = $scheme->quarterly_recruits ?? [];
        $currentQuarter = null;
        $requiredRecruits = null;
        $actualRecruits = null;
        if (!empty($quarterlyQuota)) {
            $currentQuarter = $this->getCurrentQuarter($periodEnd);
            $requiredRecruits = $quarterlyQuota["q{$currentQuarter}"] ?? 0;
            if ($requiredRecruits > 0) {
                $actualRecruits = $this->countYearToDateRecruits($user, $periodEnd);
            }
        }

        // 3b. Eficiencia al cobro
        $minEfficiency = (float) ($scheme->min_collection_efficiency ?? 0);
        if ($minEfficiency > 0) {
            $actualEfficiency = $this->calculateCollectionEfficiency($user, $periodStart, $periodEnd);
            if ($actualEfficiency < $minEfficiency) {
                return $this->notAchieved(
                    reason: "Eficiencia al cobro ({$actualEfficiency}%) por debajo del mínimo requerido ({$minEfficiency}%).",
                    collectionEfficiency: $actualEfficiency,
                    quarterlyRecruits: $actualRecruits,
                    requiredEfficiency: $minEfficiency,
                    requiredRecruits: $requiredRecruits,
                    totalAgents: $agents->count(),
                );
            }
        } else {
            $actualEfficiency = null;
        }

        // 3c. Validar cuota trimestral de reclutamiento
        if ($requiredRecruits > 0 && $actualRecruits < $requiredRecruits) {
            return $this->notAchieved(
                reason: "Cuota trimestral Q{$currentQuarter} no cumplida: requiere {$requiredRecruits} reclutas, tiene {$actualRecruits}.",
                collectionEfficiency: $actualEfficiency,
                quarterlyRecruits: $actualRecruits,
                requiredEfficiency: $minEfficiency,
                requiredRecruits: $requiredRecruits,
                totalAgents: $agents->count(),
            );
        }

        // ── 4. Evaluar cada agente contra los tiers ────────────────────

        $totalAmount = 0.0;
        $matchedAgents = [];
        $bestTierIndex = null;
        $bestTierData = null;
        $bestPercentage = 0.0;

        foreach ($agents as $agent) {
            $agentPca   = $this->calculateAgentPca($agent, $periodStart, $periodEnd);
            $tenureMonth = $this->calculateAgentTenureMonth($agent, $periodEnd);

            foreach ($tiers as $index => $tier) {
                $conditions = $tier->conditions ?? [];
                $minPca   = (float) ($conditions['min_pca'] ?? 0);
                $minMonth = (int) ($conditions['min_month'] ?? 1);
                $maxMonth = isset($conditions['max_month'])
                    ? (int) $conditions['max_month']
                    : PHP_INT_MAX;

                if ($agentPca >= $minPca && $tenureMonth >= $minMonth && $tenureMonth <= $maxMonth) {
                    $pct = (float) ($tier->promoter_percentage ?? 0);
                    $agentAmount = round($agentPca * ($pct / 100), 2);

                    // Sumar fixed_amount si existe
                    if (!empty($tier->fixed_amount) && (float) $tier->fixed_amount > 0) {
                        $agentAmount += (float) $tier->fixed_amount;
                    }

                    $totalAmount += $agentAmount;

                    $matchedAgents[] = [
                        'agent_id'      => $agent->id,
                        'agent_name'    => $agent->name,
                        'pca'           => round($agentPca, 2),
                        'tenure_month'  => $tenureMonth,
                        'tier_index'    => $index,
                        'percentage'    => $pct,
                        'amount'        => round($agentAmount, 2),
                    ];

                    // Registrar el mejor tier para el resumen
                    if ($pct > $bestPercentage) {
                        $bestPercentage = $pct;
                        $bestTierIndex = $index;
                        $bestTierData  = $tier->toArray();
                    }

                    break; // Un agente solo puede calificar a UN tier (el primero que cumpla)
                }
            }
        }

        // ── 5. Resultado ────────────────────────────────────────────────
        if (empty($matchedAgents)) {
            return $this->notAchieved(
                reason: 'Ningún agente califica para los tiers configurados.',
                collectionEfficiency: $actualEfficiency,
                quarterlyRecruits: $actualRecruits ?? null,
                requiredEfficiency: $minEfficiency,
                requiredRecruits: $requiredRecruits ?? null,
                totalAgents: $agents->count(),
                matchedAgents: 0,
            );
        }

        return [
            'is_achieved' => true,
            'amount'      => round($totalAmount, 2),
            'tier_index'  => $bestTierIndex,
            'tier_data'   => $bestTierData,
            'progress'    => [
                'metric_label'   => 'Agentes Calificados',
                'current_value'  => count($matchedAgents),
                'required_value' => 1,
            ],
            'progress_breakdown' => [
                [
                    'label'   => 'Agentes que Califican',
                    'current' => count($matchedAgents),
                    'target'  => 1,
                    'met'     => count($matchedAgents) >= 1,
                ],
                [
                    'label'   => 'Total Agentes Evaluados',
                    'current' => $agents->count(),
                    'target'  => 0,
                    'met'     => true,
                ],
                [
                    'label'   => 'Eficiencia al Cobro (%)',
                    'current' => $actualEfficiency !== null ? round($actualEfficiency, 2) : 0,
                    'target'  => $minEfficiency,
                    'met'     => $minEfficiency > 0 ? ($actualEfficiency ?? 0) >= $minEfficiency : true,
                ],
                [
                    'label'   => 'Reclutas Trimestrales',
                    'current' => $actualRecruits ?? 0,
                    'target'  => $requiredRecruits ?? 0,
                    'met'     => $requiredRecruits > 0 ? ($actualRecruits ?? 0) >= $requiredRecruits : true,
                ],
            ],
            'details'     => [
                'calculator'             => static::class,
                'version_name'           => $version->version_name,
                'collection_efficiency'  => $actualEfficiency,
                'quarterly_recruits'     => $actualRecruits ?? null,
                'current_quarter'        => $currentQuarter ?? null,
                'matched_agents'         => $matchedAgents,
                'total_agents_evaluated' => $agents->count(),
            ],
        ];
    }

    // ═══════════════════════════════════════════════════════════════════════
    // ── Métodos privados ──────────────────────────────────────────────────
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Calcula la eficiencia al cobro del promotor como:
     * (suma de primas de pólizas ACTIVAS / suma de primas totales) × 100.
     */
    private function calculateCollectionEfficiency(
        Promoter $promoter,
        Carbon $periodStart,
        Carbon $periodEnd
    ): float {
        $agentIds = $promoter->agents()->activeInPeriod($periodEnd->toDateString())->pluck('id');

        if ($agentIds->isEmpty()) {
            return 0.0;
        }

        $totalPremiums = (float) Policy::whereIn('agent_id', $agentIds)
            ->whereBetween('issue_date', [$periodStart, $periodEnd])
            ->where('status', '!=', Policy::STATUS_NO_TOMADA)
            ->sum('premium_amount');

        $pagadaPremiums = (float) Policy::whereIn('agent_id', $agentIds)
            ->whereBetween('issue_date', [$periodStart, $periodEnd])
            ->where('status', Policy::STATUS_PAGADA)
            ->sum('premium_amount');

        return $totalPremiums > 0
            ? round(($pagadaPremiums / $totalPremiums) * 100, 2)
            : 0.0;
    }

    /**
     * Determina el trimestre calendario a partir de la fecha de fin del periodo evaluado.
     *
     * @param  Carbon  $periodEnd  Fecha de cierre del periodo evaluado.
     * @return int  1, 2, 3 o 4.
     */
    private function getCurrentQuarter(Carbon $periodEnd): int
    {
        return (int) ceil($periodEnd->month / 3);
    }

    /**
     * Cuenta los agentes reclutados desde el 1 de enero del año de $periodEnd
     * hasta el cierre del trimestre correspondiente.
     *
     * @param  Promoter  $promoter   Promotor cuyos reclutas se cuentan.
     * @param  Carbon    $periodEnd  Fecha de cierre del periodo evaluado.
     * @return int
     */
    private function countYearToDateRecruits(Promoter $promoter, Carbon $periodEnd): int
    {
        $quarter    = (int) ceil($periodEnd->month / 3);
        $yearStart  = $periodEnd->copy()->startOfYear();
        $quarterEnd = $periodEnd->copy()->startOfYear()->addMonths($quarter * 3)->endOfMonth();
        $currentQuarterStart = $periodEnd->copy()->startOfQuarter();

        return $promoter->agents()
            ->whereBetween('created_at', [$yearStart, $quarterEnd])
            ->where(function ($query) use ($currentQuarterStart) {
                $query->where('is_active', true)
                      ->orWhere(function ($q) use ($currentQuarterStart) {
                          $q->where('is_active', false)
                            ->whereNotNull('deactivated_at')
                            ->where('deactivated_at', '>=', $currentQuarterStart);
                      });
            })
            ->count();
    }

    /**
     * Calcula el PCA individual de un agente en el periodo.
     */
    private function calculateAgentPca(Agent $agent, Carbon $start, Carbon $end): float
    {
        return (float) $agent->policies()
            ->whereBetween('issue_date', [$start, $end])
            ->where('status', Policy::STATUS_PAGADA)
            ->sum('premium_amount');
    }

    /**
     * Calcula los meses de antigüedad de un agente desde su created_at
     * hasta la fecha de cierre del periodo evaluado.
     *
     * Retorna 1 para el primer mes, 2 para el segundo, etc.
     *
     * @param  Agent   $agent      Agente a evaluar.
     * @param  Carbon  $periodEnd  Fecha de cierre del periodo evaluado.
     * @return int
     */
    private function calculateAgentTenureMonth(Agent $agent, Carbon $periodEnd): int
    {
        if ($agent->created_at === null) {
            return 1;
        }

        return (int) $agent->created_at->diffInMonths($periodEnd) + 1;
    }

    private function resolveActiveVersion(Scheme $scheme, Carbon $start, Carbon $end): ?SchemeVersion
    {
        $versions = $scheme->relationLoaded('versions')
            ? $scheme->versions
            : $scheme->versions()->get();

        $active = $versions->filter(function (SchemeVersion $v) use ($start, $end) {
            $starts = Carbon::parse($v->starts_at);
            $ends   = $v->ends_at ? Carbon::parse($v->ends_at) : null;
            if ($starts->greaterThan($end)) return false;
            if ($ends !== null && $ends->lessThan($start)) return false;
            return true;
        });

        return $active->sortByDesc('starts_at')->first();
    }

    /**
     * Retorna un resultado estándar de «no alcanzado».
     */
    private function notAchieved(
        string $reason,
        ?float $collectionEfficiency = null,
        ?int $quarterlyRecruits = null,
        ?float $requiredEfficiency = null,
        ?int $requiredRecruits = null,
        int $totalAgents = 0,
        int $matchedAgents = 0,
    ): array {
        return [
            'is_achieved' => false,
            'amount'      => 0.0,
            'tier_index'  => null,
            'tier_data'   => null,
            'progress'    => [
                'metric_label'   => 'Agentes Calificados',
                'current_value'  => $matchedAgents,
                'required_value' => 0.0,
            ],
            'progress_breakdown' => [
                [
                    'label'   => 'Agentes que Califican',
                    'current' => $matchedAgents,
                    'target'  => 1,
                    'met'     => $matchedAgents >= 1,
                ],
                [
                    'label'   => 'Total Agentes Evaluados',
                    'current' => $totalAgents,
                    'target'  => 0,
                    'met'     => $totalAgents > 0,
                ],
                [
                    'label'   => 'Eficiencia al Cobro (%)',
                    'current' => $collectionEfficiency !== null ? round($collectionEfficiency, 2) : 0,
                    'target'  => $requiredEfficiency ?? 0,
                    'met'     => $requiredEfficiency !== null ? ($collectionEfficiency ?? 0) >= $requiredEfficiency : true,
                ],
                [
                    'label'   => 'Reclutas Trimestrales',
                    'current' => $quarterlyRecruits ?? 0,
                    'target'  => $requiredRecruits ?? 0,
                    'met'     => $requiredRecruits !== null ? ($quarterlyRecruits ?? 0) >= $requiredRecruits : true,
                ],
            ],
            'details' => [
                'reason'                => $reason,
                'collection_efficiency' => $collectionEfficiency,
                'quarterly_recruits'    => $quarterlyRecruits,
            ],
        ];
    }
}
