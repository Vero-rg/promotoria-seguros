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
 * Calcula el Bono Activity Ratio Trimestral (Agente).
 *
 * REGLAS DE NEGOCIO:
 *   1. El Agente DEBE haber ganado el bono prerequisite (dependency_scheme_id).
 *   2. Cada póliza se pondera según su PNA (pna_equivalences del esquema):
 *      - $16,000–$20,999 → 0.5 pólizas
 *      - $21,000–$59,999 → 1.0 póliza
 *      - $60,000–$119,999 → 1.5 pólizas
 *      - ≥ $120,000 → 2.0 pólizas
 *      - < $16,000 → ignoradas
 *   3. Promedio mensual = total ponderado / 3 (trimestre).
 *   4. El tier se elige por promedio mensual en [min_policies, max_policies].
 *   5. Base de pago: PCA del agente en el periodo.
 *
 * Condiciones leídas de $tier->conditions:
 *   - classification  (string)    Etiqueta del tier (informativa).
 *   - min_policies    (float)     Promedio mensual mínimo.
 *   - max_policies    (float|null) Promedio mensual máximo.
 */
class ActivityRatioCalculator implements BonusCalculatorInterface
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
        // ── Solo aplica a Agents ──────────────────────────────────────
        if ($user instanceof Promoter) {
            return $this->notAchieved('El Bono Activity Ratio solo aplica a Agentes.');
        }

        // ── 1. Versión activa ──────────────────────────────────────────
        $version = $this->resolveActiveVersion($scheme, $periodStart, $periodEnd);
        if ($version === null) {
            return $this->notAchieved('No hay versión vigente del esquema para el periodo solicitado.');
        }

        $tiers = $version->tiers()->get()
            ->sortByDesc(fn (SchemeTier $t) => (float) ($t->agent_percentage ?? 0))
            ->values();

        if ($tiers->isEmpty()) {
            return $this->notAchieved('El esquema no tiene tiers configurados.');
        }

        // ── 2. Verificar dependencia ───────────────────────────────────
        if (!empty($scheme->dependency_scheme_id)) {
            $prerequisite = Scheme::where('id', (int) $scheme->dependency_scheme_id)
                ->where('is_active', true)
                ->first();

            if ($prerequisite === null) {
                return $this->notAchieved(
                    reason: "El bono prerequisite (ID: {$scheme->dependency_scheme_id}) no está activo o no existe.",
                );
            }
        }

        // ── 3. Métricas ─────────────────────────────────────────────────
        $pnaEquivalences = $scheme->pna_equivalences ?? [];
        $weightedTotal   = $this->calculateWeightedPolicies($user, $pnaEquivalences, $periodStart, $periodEnd);
        $monthlyAverage  = round($weightedTotal / 3, 2);
        $pca             = $this->calculateAgentPca($user, $periodStart, $periodEnd);

        // ── 4. Evaluar tiers (mejor porcentaje primero) ──────────────────
        foreach ($tiers as $index => $tier) {
            $conditions = $tier->conditions ?? [];

            $minPolicies = (float) ($conditions['min_policies'] ?? 0);
            $maxPolicies = isset($conditions['max_policies'])
                ? (float) $conditions['max_policies']
                : PHP_FLOAT_MAX;

            if ($monthlyAverage >= $minPolicies && $monthlyAverage <= $maxPolicies) {
                $amount = $this->calculateAmount($tier, $user, $pca);

                return [
                    'is_achieved' => true,
                    'amount'      => $amount,
                    'tier_index'  => $index,
                    'tier_data'   => $tier->toArray(),
                    'progress'    => [
                        'metric_label'   => 'Promedio Mensual Ponderado',
                        'current_value'  => $monthlyAverage,
                        'required_value' => $minPolicies,
                    ],
                    'progress_breakdown' => [
                        [
                            'label'   => 'Pólizas Totales',
                            'current' => $this->getRawPolicyCount($user, $periodStart, $periodEnd),
                            'target'  => 0,
                            'met'     => true,
                        ],
                        [
                            'label'   => 'Total Ponderado (PNA)',
                            'current' => $weightedTotal,
                            'target'  => 0,
                            'met'     => true,
                        ],
                        [
                            'label'   => 'Promedio Mensual Ponderado',
                            'current' => $monthlyAverage,
                            'target'  => $minPolicies,
                            'met'     => $monthlyAverage >= $minPolicies,
                        ],
                        [
                            'label'   => 'PCA Trimestral ($)',
                            'current' => round($pca, 2),
                            'target'  => 0,
                            'met'     => true,
                        ],
                    ],
                    'details'     => [
                        'calculator'       => static::class,
                        'version_name'     => $version->version_name,
                        'classification'   => $conditions['classification'] ?? '—',
                        'weighted_total'   => $weightedTotal,
                        'monthly_average'  => $monthlyAverage,
                        'pca'              => round($pca, 2),
                    ],
                ];
            }
        }

        return $this->notAchieved(
            reason: "Promedio mensual: {$monthlyAverage} — sin tier coincidente.",
            monthlyAverage: $monthlyAverage,
            weightedTotal: $weightedTotal,
            rawCount: $this->getRawPolicyCount($user, $periodStart, $periodEnd),
            pca: $pca,
            minRequired: $tiers->last()?->conditions['min_policies'] ?? 0,
        );
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    /**
     * Calcula el total de pólizas ponderadas según las equivalencias PNA.
     *
     * @param  Agent  $agent
     * @param  array  $equivalences  Esquema de equivalencias PNA del Scheme.
     * @return float  Total ponderado (ej. 10.0 para 5 pólizas de $150K PNA).
     */
    private function calculateWeightedPolicies(
        Agent $agent,
        array $equivalences,
        Carbon $start,
        Carbon $end
    ): float {
        $policies = $agent->policies()
            ->whereBetween('issue_date', [$start, $end])
            ->get();

        if ($policies->isEmpty() || empty($equivalences)) {
            return 0.0;
        }

        $total = 0.0;

        foreach ($policies as $policy) {
            $pna = (float) ($policy->premium_amount ?? 0);

            // Buscar el peso correspondiente en las equivalencias
            $weight = $this->resolvePnaWeight($pna, $equivalences);
            $total += $weight;
        }

        return round($total, 2);
    }

    /**
     * Encuentra el peso (policies) que corresponde a un monto PNA dado.
     */
    private function resolvePnaWeight(float $pna, array $equivalences): float
    {
        foreach ($equivalences as $eq) {
            $minPna = (float) ($eq['min_pna'] ?? 0);
            $maxPna = isset($eq['max_pna']) ? (float) $eq['max_pna'] : PHP_FLOAT_MAX;

            if ($pna >= $minPna && $pna <= $maxPna) {
                return (float) ($eq['policies'] ?? 0);
            }
        }

        return 0.0; // Por debajo del rango mínimo → no suma
    }

    /**
     * Calcula la PCA (Prima Computable Ajustada) del agente en el periodo.
     */
    private function calculateAgentPca(Agent $agent, Carbon $start, Carbon $end): float
    {
        return (float) $agent->policies()
            ->whereBetween('issue_date', [$start, $end])
            ->sum('premium_amount');
    }

    /**
     * Cuenta las pólizas sin ponderar (solo para progreso informativo).
     */
    private function getRawPolicyCount(Agent $agent, Carbon $start, Carbon $end): int
    {
        return $agent->policies()
            ->whereBetween('issue_date', [$start, $end])
            ->count();
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

    private function calculateAmount(SchemeTier $tier, Agent|Promoter $user, float $base): float
    {
        if (!empty($tier->fixed_amount) && (float) $tier->fixed_amount > 0) {
            return (float) $tier->fixed_amount;
        }
        $percentage = $user instanceof Agent
            ? (float) ($tier->agent_percentage ?? 0)
            : (float) ($tier->promoter_percentage ?? 0);
        return round($base * ($percentage / 100), 2);
    }

    private function notAchieved(
        string $reason,
        float $monthlyAverage = 0.0,
        float $weightedTotal = 0.0,
        int $rawCount = 0,
        float $pca = 0.0,
        ?float $minRequired = null,
    ): array {
        $target = $minRequired ?? 0.0;

        return [
            'is_achieved' => false,
            'amount'      => 0.0,
            'tier_index'  => null,
            'tier_data'   => null,
            'progress'    => [
                'metric_label'   => 'Promedio Mensual Ponderado',
                'current_value'  => $monthlyAverage,
                'required_value' => $target,
            ],
            'progress_breakdown' => [
                [
                    'label'   => 'Pólizas Totales',
                    'current' => $rawCount,
                    'target'  => 0,
                    'met'     => $rawCount > 0,
                ],
                [
                    'label'   => 'Total Ponderado (PNA)',
                    'current' => $weightedTotal,
                    'target'  => 0,
                    'met'     => $weightedTotal > 0,
                ],
                [
                    'label'   => 'Promedio Mensual Ponderado',
                    'current' => $monthlyAverage,
                    'target'  => $target,
                    'met'     => $target > 0 ? $monthlyAverage >= $target : false,
                ],
                [
                    'label'   => 'PCA Trimestral ($)',
                    'current' => round($pca, 2),
                    'target'  => 0,
                    'met'     => $pca > 0,
                ],
            ],
            'details' => ['reason' => $reason],
        ];
    }
}
