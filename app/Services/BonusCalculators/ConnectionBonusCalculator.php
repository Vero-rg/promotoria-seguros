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
        $totalRecruits = count($recruitPcas);

        if (empty($recruitPcas)) {
            // Referencia: el tier más accesible para mostrar metas en el breakdown
            $easiestTier = $tiers->sortBy(fn (SchemeTier $t) => [
                (int) ($t->conditions['min_recruits'] ?? PHP_INT_MAX),
                (float) ($t->conditions['min_pca'] ?? PHP_FLOAT_MAX),
            ])->first();
            $refMinRecruits = (int) ($easiestTier->conditions['min_recruits'] ?? 0);
            $refMinPca = (float) ($easiestTier->conditions['min_pca'] ?? 0);

            return $this->notAchieved(
                reason: 'No se encontraron agentes reclutados en el periodo.',
                totalRecruits: 0,
                qualifiedCount: 0,
                totalQualifiedPca: 0.0,
                highestPca: 0.0,
                minRecruits: $refMinRecruits,
                minPca: $refMinPca,
            );
        }

        // ── Evaluar cada tier de la matriz (mejor porcentaje primero) ────
        foreach ($tiers as $index => $tier) {
            $conditions  = $tier->conditions ?? [];
            $minRecruits = (int) ($conditions['min_recruits'] ?? 0);
            $maxRecruits = isset($conditions['max_recruits'])
                ? (int) $conditions['max_recruits']
                : PHP_INT_MAX;
            $minPca = (float) ($conditions['min_pca'] ?? 0);

            // ── Filtrar reclutas por el min_pca ESPECÍFICO de este tier ──
            $qualifiedByTier = array_filter(
                $recruitPcas,
                fn (float $pca) => $pca >= $minPca
            );
            $qualifiedCount = count($qualifiedByTier);

            // ── Validar franja de volumen [min_recruits, max_recruits] ──
            if ($qualifiedCount < $minRecruits || $qualifiedCount > $maxRecruits) {
                continue;
            }

            // ── ¡Match! Calcular monto sobre la base EXCLUSIVA del tier ──
            $highestPca = $qualifiedCount > 0 ? max($qualifiedByTier) : 0.0;
            $totalQualifiedPca = array_sum($qualifiedByTier);
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
                ],
                'details'     => [
                    'calculator'            => static::class,
                    'version_name'          => $version->version_name,
                    'total_recruits'        => $totalRecruits,
                    'qualified_recruits'    => $qualifiedCount,
                    'min_pca_tier'          => $minPca,
                    'highest_individual_pca' => round($highestPca, 2),
                    'total_qualified_pca'   => round($totalQualifiedPca, 2),
                    'individual_pcas'       => array_map(fn ($v) => round($v, 2), $recruitPcas),
                ],
            ];
        }

        // ── Ningún tier coincidió ───────────────────────────────────────
        // Referencia: el tier más accesible para mostrar metas en el breakdown
        $easiestTier = $tiers->sortBy(fn (SchemeTier $t) => [
            (int) ($t->conditions['min_recruits'] ?? PHP_INT_MAX),
            (float) ($t->conditions['min_pca'] ?? PHP_FLOAT_MAX),
        ])->first();
        $refMinRecruits = (int) ($easiestTier->conditions['min_recruits'] ?? 0);
        $refMinPca = (float) ($easiestTier->conditions['min_pca'] ?? 0);

        // Calcular progreso contra el tier más accesible
        $refQualified = array_filter($recruitPcas, fn (float $pca) => $pca >= $refMinPca);
        $refCount = count($refQualified);
        $refHighestPca = $refCount > 0 ? max($refQualified) : 0.0;
        $refTotalPca = array_sum($refQualified);

        return $this->notAchieved(
            reason: 'Ningún tier coincide. Total reclutas: ' . $totalRecruits .
                    ', PCA total: $' . number_format(array_sum($recruitPcas), 2),
            totalRecruits: $totalRecruits,
            qualifiedCount: $refCount,
            totalQualifiedPca: $refTotalPca,
            highestPca: $refHighestPca,
            minRecruits: $refMinRecruits,
            minPca: $refMinPca,
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
                $query->whereBetween('issue_date', [$start, $end])
                    ->where('status', Policy::STATUS_PAGADA);
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
     * Retorna un resultado estándar de «no alcanzado» con breakdown
     * sincronizado al tier de referencia más accesible.
     *
     * @param  string      $reason             Motivo del fallo.
     * @param  int         $totalRecruits      Total de reclutas en el periodo.
     * @param  int         $qualifiedCount     Reclutas que califican contra el tier de referencia.
     * @param  float       $totalQualifiedPca  Suma del PCA de los reclutas calificados.
     * @param  float       $highestPca         PCA individual más alto entre los calificados.
     * @param  int|null    $minRecruits        Meta mínima de reclutas del tier de referencia.
     * @param  float|null  $minPca             PCA mínimo por recluta del tier de referencia.
     */
    private function notAchieved(
        string $reason,
        int $totalRecruits = 0,
        int $qualifiedCount = 0,
        float $totalQualifiedPca = 0.0,
        float $highestPca = 0.0,
        ?int $minRecruits = null,
        ?float $minPca = null,
    ): array {
        return [
            'is_achieved' => false,
            'amount'      => 0.0,
            'tier_index'  => null,
            'tier_data'   => null,
            'progress'    => [
                'metric_label'   => 'Reclutas Calificados',
                'current_value'  => $qualifiedCount,
                'required_value' => (float) ($minRecruits ?? 0),
            ],
            'progress_breakdown' => [
                [
                    'label'   => 'Reclutas que Califican',
                    'current' => $qualifiedCount,
                    'target'  => $minRecruits ?? 0,
                    'met'     => $minRecruits !== null ? $qualifiedCount >= $minRecruits : false,
                ],
                [
                    'label'   => 'PCA Individual Más Alta ($)',
                    'current' => round($highestPca, 2),
                    'target'  => $minPca ?? 0,
                    'met'     => $minPca !== null && $highestPca >= $minPca,
                ],
            ],
            'details' => [
                'reason'         => $reason,
                'total_recruits' => $totalRecruits,
                'total_pca'      => round($totalQualifiedPca, 2),
            ],
        ];
    }
}
