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
 * Calcula el Bono por Conexión Mensual (Connection Bonus).
 *
 * REGLA DE NEGOCIO — MATRIZ DE DOBLE ENTRADA:
 *   1. Cada recluta debe cumplir INDIVIDUALMENTE el PCA mínimo del tier.
 *   2. El porcentaje se determina por el recluta con MAYOR PCA individual.
 *   3. La cantidad de reclutas que califican define la franja (1-2, 3-4, 5+).
 *
 * Condiciones leídas de $tier->conditions:
 *   - min_recruits  (int)       Mínimo de reclutas que califican.
 *   - max_recruits  (int|null)  Máximo de reclutas (null = sin límite).
 *   - min_pca       (float)     PCA mínimo individual que cada recluta debe alcanzar.
 */
class ConnectionBonusCalculator implements BonusCalculatorInterface
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
        // Solo aplica a Promoters (un agente no puede «conectar» reclutas)
        if ($user instanceof Agent) {
            return $this->notAchieved('El Bono por Conexión solo aplica a Promotores.');
        }

        $version = $this->resolveActiveVersion($scheme, $periodStart, $periodEnd);
        if ($version === null) {
            return $this->notAchieved('No hay versión vigente del esquema para el periodo solicitado.');
        }

        // ── Ordenar tiers por porcentaje descendente (mejor pago primero) ──
        $tiers = $version->tiers()->get()
            ->sortByDesc(fn (SchemeTier $t) => (float) ($t->promoter_percentage ?? 0))
            ->values();

        if ($tiers->isEmpty()) {
            return $this->notAchieved('El esquema no tiene tiers configurados.');
        }

        // ── Calcular PCA individual de cada recluta ─────────────────────
        $recruitPcas = $this->calculateIndividualPcas($user, $periodStart, $periodEnd);

        if (empty($recruitPcas)) {
            return $this->notAchieved(
                reason: 'No se encontraron agentes reclutados en el periodo.',
                recruitCount: 0,
                totalPca: 0.0,
                universalThreshold: $universalThreshold ?? $tiers->min(fn (SchemeTier $t) => (float) ($t->conditions['min_pca'] ?? PHP_FLOAT_MAX)),
                minRecruits: $tiers->min(fn (SchemeTier $t) => (int) ($t->conditions['min_recruits'] ?? PHP_INT_MAX)),
            );
        }

        // ── Determinar umbral universal (el menor min_pca de todos los tiers) ──
        $universalThreshold = $tiers->min(function (SchemeTier $tier) {
            return (float) ($tier->conditions['min_pca'] ?? PHP_FLOAT_MAX);
        });

        // ── Filtrar reclutas por umbral universal ───────────────────────
        $qualifiedByUniversal = array_filter(
            $recruitPcas,
            fn (float $pca) => $pca >= $universalThreshold
        );

        if (empty($qualifiedByUniversal)) {
            return $this->notAchieved(
                reason: 'Ningún recluta alcanza el PCA mínimo universal de $' . number_format($universalThreshold),
                recruitCount: count($recruitPcas),
                totalPca: array_sum($recruitPcas),
                universalThreshold: $universalThreshold,
                minRecruits: $tiers->min(fn (SchemeTier $t) => (int) ($t->conditions['min_recruits'] ?? PHP_INT_MAX)),
            );
        }

        $qualifiedCount = count($qualifiedByUniversal);
        $highestPca     = max($qualifiedByUniversal);
        $totalQualifiedPca = array_sum($qualifiedByUniversal);

        // ── Evaluar cada tier de la matriz (mejor porcentaje primero) ────
        foreach ($tiers as $index => $tier) {
            $conditions  = $tier->conditions ?? [];
            $minRecruits = (int) ($conditions['min_recruits'] ?? 0);
            $maxRecruits = isset($conditions['max_recruits'])
                ? (int) $conditions['max_recruits']
                : PHP_INT_MAX;
            $minPca = (float) ($conditions['min_pca'] ?? 0);

            // ¿La cantidad de reclutas cae en la franja?
            if ($qualifiedCount < $minRecruits || $qualifiedCount > $maxRecruits) {
                continue;
            }

            // ¿El recluta con mayor PCA individual supera el umbral de este tier?
            if ($highestPca < $minPca) {
                continue;
            }

            // ── ¡Match! Calcular monto ──────────────────────────────────
            $amount = $this->calculateAmount($tier, $user, $totalQualifiedPca);

            return [
                'is_achieved' => true,
                'amount'      => $amount,
                'tier_index'  => $index,
                'tier_data'   => $tier->toArray(),
                'progress'    => [
                    'metric_label'   => 'Reclutas Calificados',
                    'current_value'  => $qualifiedCount,
                    'required_value' => $minRecruits,
                ],
                'progress_breakdown' => [
                    [
                        'label'   => 'Reclutas que Califican',
                        'current' => $qualifiedCount,
                        'target'  => $minRecruits,
                        'met'     => $qualifiedCount >= $minRecruits,
                    ],
                    [
                        'label'   => 'PCA Individual Más Alta ($)',
                        'current' => round($highestPca, 2),
                        'target'  => $minPca,
                        'met'     => $highestPca >= $minPca,
                    ],
                    [
                        'label'   => 'PCA Total Calificada ($)',
                        'current' => round($totalQualifiedPca, 2),
                        'target'  => 0,
                        'met'     => true,
                    ],
                ],
                'details'     => [
                    'calculator'            => static::class,
                    'version_name'          => $version->version_name,
                    'total_recruits'        => count($recruitPcas),
                    'qualified_recruits'    => $qualifiedCount,
                    'universal_threshold'   => $universalThreshold,
                    'highest_individual_pca' => round($highestPca, 2),
                    'total_qualified_pca'   => round($totalQualifiedPca, 2),
                    'individual_pcas'       => array_map(fn ($v) => round($v, 2), $recruitPcas),
                ],
            ];
        }

        // ── Ningún tier coincidió ───────────────────────────────────────
        return $this->notAchieved(
            reason: 'Ningún tier coincide. Reclutas calificados: ' . $qualifiedCount .
                    ', PCA total calificado: $' . number_format($totalQualifiedPca, 2) .
                    ', PCA más alto: $' . number_format($highestPca, 2),
            recruitCount: $qualifiedCount,
            totalPca: $totalQualifiedPca,
            universalThreshold: $universalThreshold,
            minRecruits: $tiers->min(fn (SchemeTier $t) => (int) ($t->conditions['min_recruits'] ?? PHP_INT_MAX)),
        );
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    /**
     * Calcula la PCA individual de cada agente reclutado en el periodo.
     *
     * Retorna un array asociativo [agent_id => pca_individual].
     *
     * @param  Promoter  $promoter
     * @param  Carbon    $start
     * @param  Carbon    $end
     * @return array<int, float>
     */
    private function calculateIndividualPcas(
        Promoter $promoter,
        Carbon $start,
        Carbon $end
    ): array {
        $recruits = $promoter->agents()
            ->whereBetween('created_at', [$start, $end])
            ->with(['policies' => function ($query) use ($start, $end) {
                $query->whereBetween('issue_date', [$start, $end]);
            }])
            ->get();

        $result = [];

        foreach ($recruits as $recruit) {
            $pca = $recruit->policies->sum('premium_amount');
            $result[$recruit->id] = (float) $pca;
        }

        return $result;
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
     * Calcula el monto del bono: (base × porcentaje) + monto fijo.
     *
     * El fixed_amount se SUMA al monto porcentual, no lo reemplaza.
     */
    private function calculateAmount(SchemeTier $tier, Agent|Promoter $user, float $base): float
    {
        $percentage = $user instanceof Agent
            ? (float) ($tier->agent_percentage ?? 0)
            : (float) ($tier->promoter_percentage ?? 0);

        $amount = round($base * ($percentage / 100), 2);

        // Sumar monto fijo (graduación / excelencia) si existe
        if (!empty($tier->fixed_amount) && (float) $tier->fixed_amount > 0) {
            $amount += (float) $tier->fixed_amount;
        }

        return round($amount, 2);
    }

    /**
     * Retorna un resultado estándar de «no alcanzado».
     */
    private function notAchieved(
        string $reason,
        int $recruitCount = 0,
        float $totalPca = 0.0,
        ?float $universalThreshold = null,
        ?int $minRecruits = null,
    ): array {
        return [
            'is_achieved' => false,
            'amount'      => 0.0,
            'tier_index'  => null,
            'tier_data'   => null,
            'progress'    => [
                'metric_label'   => 'Reclutas Calificados',
                'current_value'  => $recruitCount,
                'required_value' => (float) ($minRecruits ?? 0),
            ],
            'progress_breakdown' => [
                [
                    'label'   => 'Reclutas que Califican',
                    'current' => $recruitCount,
                    'target'  => $minRecruits ?? 0,
                    'met'     => $minRecruits !== null ? $recruitCount >= $minRecruits : false,
                ],
                [
                    'label'   => 'PCA Mín. por Recluta ($)',
                    'current' => $recruitCount > 0 ? round($totalPca / $recruitCount, 2) : 0,
                    'target'  => $universalThreshold ?? 0,
                    'met'     => $universalThreshold !== null && $recruitCount > 0,
                ],
                [
                    'label'   => 'PCA Total Calificada ($)',
                    'current' => round($totalPca, 2),
                    'target'  => 0,
                    'met'     => $totalPca > 0,
                ],
            ],
            'details' => [
                'reason'      => $reason,
                'total_pca'   => round($totalPca, 2),
            ],
        ];
    }
}
