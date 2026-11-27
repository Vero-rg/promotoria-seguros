<?php

namespace App\Services\BonusCalculators;

use App\Models\Agent;
use App\Models\Policy;
use App\Models\Promoter;
use App\Models\Scheme;
use App\Models\SchemeTier;
use App\Models\SchemeVersion;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Calcula el Bono por Conexión Mensual (Connection Bonus).
 *
 * REGLA DE NEGOCIO — MATRIZ DE DOBLE ENTRADA CON PAGO INDIVIDUAL:
 *   1. Los agentes se ACUMULAN durante el año: para un periodo se consideran
 *      todos los reclutados entre el 1 de enero y el cierre del periodo.
 *   2. Cada agente califica con su PCA del periodo (no acumulada), siempre que
 *      alcance el PCA mínimo de entrada de la matriz.
 *   3. La cantidad de agentes calificados define la franja (1-2, 3-4, 5+).
 *   4. El pago es INDIVIDUAL: cada agente paga su propia PCA × el porcentaje
 *      de la columna que su PCA alcanza dentro de esa franja. Si el tier tiene
 *      fixed_amount, se suma por cada agente.
 *
 * Condiciones leídas de $tier->conditions:
 *   - min_recruits (int)      Mínimo de agentes calificados de la franja.
 *   - max_recruits (int|null) Máximo de agentes de la franja (null = sin límite).
 *   - min_pca      (float)    PCA individual mínima para la columna.
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

        // ── Tiers ordenados por porcentaje descendente (mejor pago primero) ──
        $tiers = $version->tiers()->get()
            ->sortByDesc(fn (SchemeTier $t) => (float) ($t->promoter_percentage ?? 0))
            ->values();

        if ($tiers->isEmpty()) {
            return $this->notAchieved('El esquema no tiene tiers configurados.');
        }

        // ── PCA del periodo por agente reclutado en el año ───────────────
        $entryMinPca = (float) $tiers->min(
            fn (SchemeTier $t) => (float) ($t->conditions['min_pca'] ?? 0)
        );

        $recruits       = $this->recruitsWithPca($user, $periodStart, $periodEnd);
        $totalRecruits  = count($recruits);
        $reference      = $this->referenceTier($tiers);
        $refMinRecruits = (int) ($reference->conditions['min_recruits'] ?? 0);

        // Desglose: todos los reclutados del año, califiquen o no.
        $rows           = [];
        $qualifiedCount = 0;
        $highestPca     = 0.0;

        foreach ($recruits as $recruit) {
            $qualifies = $recruit['pca'] > 0 && $recruit['pca'] >= $entryMinPca;
            $qualifiedCount += $qualifies ? 1 : 0;
            $highestPca = max($highestPca, $recruit['pca']);

            $rows[$recruit['agent_id']] = [
                'agent_id'     => $recruit['agent_id'],
                'agent_name'   => $recruit['agent_name'],
                'agent_label'  => $recruit['agent_label'],
                'pca'          => round($recruit['pca'], 2),
                'qualified'    => $qualifies,
                'percentage'   => null,
                'base_amount'  => 0.0,
                'fixed_amount' => null,
                'amount'       => 0.0,
                'note'         => $qualifies ? null : 'Sin PCA mínimo',
            ];
        }

        if ($totalRecruits === 0) {
            return $this->notAchieved(
                reason: 'No se encontraron agentes reclutados en el año.',
                totalRecruits: 0,
                qualifiedCount: 0,
                highestPca: 0.0,
                minRecruits: $refMinRecruits,
                minPca: $entryMinPca,
            );
        }

        if ($qualifiedCount === 0) {
            return $this->notAchieved(
                reason: 'Ningún agente alcanzó el PCA mínimo de la matriz ($'.number_format($entryMinPca, 2).').',
                totalRecruits: $totalRecruits,
                qualifiedCount: 0,
                highestPca: $highestPca,
                minRecruits: $refMinRecruits,
                minPca: $entryMinPca,
                agentBreakdown: $this->sortedRows($rows),
            );
        }

        // ── Franja de la matriz según el número de agentes calificados ──
        $bandTiers = $this->bandTiers($tiers, $qualifiedCount);

        if ($bandTiers->isEmpty()) {
            return $this->notAchieved(
                reason: "La cantidad de agentes calificados ({$qualifiedCount}) no cae en ninguna franja de la matriz.",
                totalRecruits: $totalRecruits,
                qualifiedCount: $qualifiedCount,
                highestPca: $highestPca,
                minRecruits: $refMinRecruits,
                minPca: $entryMinPca,
                agentBreakdown: $this->sortedRows($rows),
            );
        }

        // ── Pago individual: cada agente con su propia columna ──────────
        $totalAmount       = 0.0;
        $totalBase         = 0.0;
        $totalFixed        = 0.0;
        $totalQualifiedPca = 0.0;
        $appliedTier       = null;

        foreach ($recruits as $recruit) {
            if (! $rows[$recruit['agent_id']]['qualified']) {
                continue;
            }

            $tier = $this->tierForPca($bandTiers, $recruit['pca']);
            if ($tier === null) {
                continue;
            }

            $percentage  = (float) ($tier->promoter_percentage ?? 0);
            $baseAmount  = round($recruit['pca'] * ($percentage / 100), 2);
            $fixedAmount = ! empty($tier->fixed_amount) && (float) $tier->fixed_amount > 0
                ? (float) $tier->fixed_amount
                : 0.0;

            $rows[$recruit['agent_id']] = array_merge($rows[$recruit['agent_id']], [
                'percentage'   => $percentage,
                'base_amount'  => $baseAmount,
                'fixed_amount' => $fixedAmount > 0 ? $fixedAmount : null,
                'amount'       => round($baseAmount + $fixedAmount, 2),
            ]);

            $totalBase += $baseAmount;
            $totalFixed += $fixedAmount;
            $totalAmount += $baseAmount + $fixedAmount;
            $totalQualifiedPca += $recruit['pca'];

            if ($appliedTier === null
                || $percentage > (float) ($appliedTier->promoter_percentage ?? 0)) {
                $appliedTier = $tier;
            }
        }

        $minRecruits = (int) $bandTiers->min(
            fn (SchemeTier $t) => (int) ($t->conditions['min_recruits'] ?? 0)
        );

        return [
            'is_achieved' => true,
            'amount'      => round($totalAmount, 2),
            'tier_index'  => $appliedTier === null
                ? null
                : $tiers->search(fn (SchemeTier $t) => $t->id === $appliedTier->id),
            'tier_data'   => $appliedTier?->toArray(),
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
                    'target'  => $entryMinPca,
                    'met'     => $highestPca >= $entryMinPca,
                ],
            ],
            'details'     => [
                'calculator'             => static::class,
                'version_name'           => $version->version_name,
                'total_recruits'         => $totalRecruits,
                'qualified_recruits'     => $qualifiedCount,
                'entry_min_pca'          => $entryMinPca,
                'total_qualified_pca'    => round($totalQualifiedPca, 2),
                'highest_individual_pca' => round($highestPca, 2),
                'total_base_amount'      => round($totalBase, 2),
                'total_fixed_amount'     => round($totalFixed, 2),
                'agent_breakdown'        => $this->sortedRows($rows),
                'metrics'                => [
                    'min_recruits' => $qualifiedCount,
                    'min_pca'      => round($highestPca, 2),
                ],
            ],
        ];
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    /**
     * Agentes reclutados en el año (hasta el cierre del periodo) con su PCA
     * del periodo evaluado.
     *
     * @return array<int, array{agent_id: int, agent_name: string, pca: float}>
     */
    private function recruitsWithPca(Promoter $promoter, Carbon $periodStart, Carbon $periodEnd): array
    {
        $yearStart = $periodEnd->copy()->startOfYear();

        $recruits = $promoter->agents()
            ->whereBetween('created_at', [$yearStart, $periodEnd->copy()->endOfDay()])
            ->with(['policies' => function ($query) use ($periodStart, $periodEnd) {
                $query->whereBetween('issue_date', [$periodStart, $periodEnd])
                    ->where('status', Policy::STATUS_PAGADA)
                    ->countableForBonusAmount();
            }])
            ->orderBy('created_at')
            ->get();

        return $recruits->map(function (Agent $recruit) use ($promoter) {
            // PCA = PNA − $1,350 por póliza (mínimo $0)
            $pca = $recruit->policies->sum(
                fn (Policy $policy) => max(0, (float) $policy->premium_amount - Policy::PCA_DEDUCTION)
            );

            return [
                'agent_id'    => (int) $recruit->id,
                'agent_name'  => (string) $recruit->name,
                'agent_label' => $recruit->shortName($promoter->name),
                'pca'         => (float) $pca,
            ];
        })->all();
    }

    /**
     * Tiers de la franja que corresponde al número de agentes calificados.
     * Si varias franjas coinciden, gana la que exige más agentes.
     *
     * @param  Collection<int, SchemeTier>  $tiers
     * @return Collection<int, SchemeTier>
     */
    private function bandTiers(Collection $tiers, int $qualifiedCount): Collection
    {
        $matching = $tiers->filter(function (SchemeTier $tier) use ($qualifiedCount) {
            $conditions  = $tier->conditions ?? [];
            $minRecruits = (int) ($conditions['min_recruits'] ?? 0);
            $maxRecruits = isset($conditions['max_recruits'])
                ? (int) $conditions['max_recruits']
                : PHP_INT_MAX;

            return $qualifiedCount >= $minRecruits && $qualifiedCount <= $maxRecruits;
        });

        if ($matching->isEmpty()) {
            return $matching;
        }

        $bandMin = $matching->max(
            fn (SchemeTier $t) => (int) ($t->conditions['min_recruits'] ?? 0)
        );

        return $matching
            ->filter(fn (SchemeTier $t) => (int) ($t->conditions['min_recruits'] ?? 0) === $bandMin)
            ->values();
    }

    /**
     * Tier de la franja cuya columna de PCA alcanza el agente (la más alta).
     *
     * @param  Collection<int, SchemeTier>  $bandTiers
     */
    private function tierForPca(Collection $bandTiers, float $pca): ?SchemeTier
    {
        return $bandTiers
            ->sortByDesc(fn (SchemeTier $t) => (float) ($t->conditions['min_pca'] ?? 0))
            ->first(fn (SchemeTier $t) => $pca >= (float) ($t->conditions['min_pca'] ?? 0));
    }

    /**
     * Tier más accesible de la matriz (para metas del breakdown).
     *
     * @param  Collection<int, SchemeTier>  $tiers
     */
    private function referenceTier(Collection $tiers): SchemeTier
    {
        return $tiers->sortBy(fn (SchemeTier $t) => [
            (int) ($t->conditions['min_recruits'] ?? PHP_INT_MAX),
            (float) ($t->conditions['min_pca'] ?? PHP_FLOAT_MAX),
        ])->first();
    }

    /**
     * Desglose ordenado por PCA descendente.
     *
     * @param  array<int, array>  $rows
     * @return array<int, array>
     */
    private function sortedRows(array $rows): array
    {
        return collect($rows)
            ->sortByDesc('pca')
            ->values()
            ->all();
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
     * Retorna un resultado estándar de «no alcanzado» con breakdown
     * sincronizado al tier de referencia más accesible.
     *
     * @param  array<int, array>  $agentBreakdown
     */
    private function notAchieved(
        string $reason,
        int $totalRecruits = 0,
        int $qualifiedCount = 0,
        float $highestPca = 0.0,
        ?int $minRecruits = null,
        ?float $minPca = null,
        array $agentBreakdown = [],
    ): array {
        $totalPca = array_sum(array_column(
            array_filter($agentBreakdown, fn (array $row) => ! empty($row['qualified'])),
            'pca'
        ));

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
                    'met'     => $minRecruits !== null && $qualifiedCount >= $minRecruits,
                ],
                [
                    'label'   => 'PCA Individual Más Alta ($)',
                    'current' => round($highestPca, 2),
                    'target'  => $minPca ?? 0,
                    'met'     => $minPca !== null && $highestPca >= $minPca,
                ],
            ],
            'details' => [
                'reason'             => $reason,
                'total_recruits'     => $totalRecruits,
                'qualified_recruits' => $qualifiedCount,
                'total_pca'          => round($totalPca, 2),
                'agent_breakdown'    => $agentBreakdown,
                'metrics'            => [
                    'min_recruits' => $qualifiedCount,
                    'min_pca'      => round($highestPca, 2),
                ],
            ],
        ];
    }
}
