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
 * Calcula el Bono por Producción de 1.er Año Trimestral — Promotor.
 *
 * INVARIANTE DE NEGOCIO: "Producción de 1.er año" SIEMPRE es un Bono, NUNCA una comisión.
 *
 * REGLAS DE NEGOCIO:
 *   1. PP (Producción Primaria) = suma de primas en el periodo.
 *   2. IRP (Índice de Retención de Pólizas) = (activas / totales) × 100.
 *   3. El IRP global debe ser >= min_irp del esquema (ej. 91%) o el bono falla.
 *   4. Debe cumplirse la cuota trimestral de reclutamiento (quarterly_recruits).
 *   5. Debe cumplirse el requisito de producto (requires_product + min_product_count).
 *   6. El tier se elige por PP >= min_pp E IRP en [min_irp, max_irp].
 *
 * Condiciones leídas de $tier->conditions:
 *   - min_pp    (float)      PP mínima ($).
 *   - min_irp   (float)      IRP mínimo del tier (%).
 *   - max_irp   (float|null) IRP máximo del tier (null = sin límite).
 */
class PromoterFirstYearProductionCalculator implements BonusCalculatorInterface
{
    use ProductAliasResolver;
    /**
     * {@inheritDoc}
     */
    public function calculate(
        Agent|Promoter $user,
        Scheme $scheme,
        Carbon $periodStart,
        Carbon $periodEnd
    ): array {
        if ($user instanceof Agent) {
            return $this->notAchieved('El Bono de Producción de 1.er Año (Promotor) solo aplica a Promotores.');
        }

        // ── 1. Versión activa ──────────────────────────────────────────
        $version = $this->resolveActiveVersion($scheme, $periodStart, $periodEnd);
        if ($version === null) {
            return $this->notAchieved('No hay versión vigente del esquema para el periodo solicitado.');
        }

        $tiers = $version->tiers()->get()
            ->sortByDesc(fn (SchemeTier $t) => (float) ($t->promoter_percentage ?? 0))
            ->values();

        if ($tiers->isEmpty()) {
            return $this->notAchieved('El esquema no tiene tiers configurados.');
        }

        // ── 2. Métricas base ────────────────────────────────────────────
        $pp  = $this->calculatePP($user, $periodStart, $periodEnd);
        $irp = $this->calculateIRP($user, $periodStart, $periodEnd);

        // ── 3. Validar Reglas Globales ──────────────────────────────────

        // 3a. IRP mínimo global
        $globalMinIrp = (float) ($scheme->min_irp ?? 0);
        if ($globalMinIrp > 0 && $irp < $globalMinIrp) {
            return $this->notAchieved(
                reason: "IRP ({$irp}%) por debajo del mínimo global requerido ({$globalMinIrp}%).",
                pp: $pp,
                irp: $irp,
                globalMinIrp: $globalMinIrp,
            );
        }

        // 3b. Cuota trimestral de reclutamiento
        $quarterlyQuota = $scheme->quarterly_recruits ?? [];
        if (!empty($quarterlyQuota)) {
            $currentQuarter = $this->getCurrentQuarter($periodEnd);
            $requiredRecruits = $quarterlyQuota[$currentQuarter] ?? 0;
            if ($requiredRecruits > 0) {
                $actualRecruits = $this->countYearToDateRecruits($user, $periodEnd);
                if ($actualRecruits < $requiredRecruits) {
                    return $this->notAchieved(
                        reason: "Cuota trimestral Q{$currentQuarter} no cumplida: requiere {$requiredRecruits} reclutas, tiene {$actualRecruits}.",
                        pp: $pp,
                        irp: $irp,
                        globalMinIrp: $globalMinIrp,
                    );
                }
            }
        }

        // 3c. Requisito de producto (requires_product + min_product_count)
        $requiredProducts = $scheme->requires_product ?? [];
        $minProductCount  = (int) ($scheme->min_product_count ?? 0);
        if (!empty($requiredProducts) && $minProductCount > 0) {
            foreach ($requiredProducts as $productType) {
                $count = $this->countPoliciesByType($user, $productType, $periodStart, $periodEnd);
                if ($count < $minProductCount) {
                    return $this->notAchieved(
                        reason: "No cumple con el requisito de producto «{$productType}»: requiere {$minProductCount}, tiene {$count}.",
                        pp: $pp,
                        irp: $irp,
                        globalMinIrp: $globalMinIrp,
                    );
                }
            }
        }

        // ── 4. Evaluar tiers (mejor porcentaje primero) ──────────────────
        foreach ($tiers as $index => $tier) {
            $conditions = $tier->conditions ?? [];

            $minPp  = (float) ($conditions['min_pp']  ?? 0);
            $minIrp = (float) ($conditions['min_irp'] ?? 0);
            $maxIrp = isset($conditions['max_irp'])
                ? (float) $conditions['max_irp']
                : 100.0;

            $ppOk  = $pp >= $minPp;
            $irpOk = $irp >= $minIrp && $irp <= $maxIrp;

            if ($ppOk && $irpOk) {
                $amount = $this->calculateAmount($tier, $user, $pp);

                return [
                    'is_achieved' => true,
                    'amount'      => $amount,
                    'tier_index'  => $index,
                    'tier_data'   => $tier->toArray(),
                    'progress'    => [
                        'metric_label'   => 'PP ($)',
                        'current_value'  => round($pp, 2),
                        'required_value' => $minPp,
                    ],
                    'progress_breakdown' => [
                        [
                            'label'   => 'Producción Primaria — PP ($)',
                            'current' => round($pp, 2),
                            'target'  => $minPp,
                            'met'     => $pp >= $minPp,
                        ],
                        [
                            'label'   => 'Índice de Retención — IRP (%)',
                            'current' => round($irp, 2),
                            'target'  => $globalMinIrp,
                            'met'     => $irp >= $globalMinIrp,
                        ],
                    ],
                    'details'     => [
                        'calculator'   => static::class,
                        'version_name' => $version->version_name,
                        'pp'           => round($pp, 2),
                        'irp'          => round($irp, 2),
                    ],
                ];
            }
        }

        // ── 5. Ningún tier alcanzado ────────────────────────────────────
        return $this->notAchieved(
            reason: "PP: \$" . number_format($pp, 2) . ", IRP: {$irp}% — sin tier coincidente.",
            pp: $pp,
            irp: $irp,
            globalMinIrp: $globalMinIrp,
        );
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    /**
     * Calcula la Producción Primaria (PP): suma de primas en el periodo.
     */
    private function calculatePP(Promoter $promoter, Carbon $start, Carbon $end): float
    {
        $agentIds = $promoter->agents()->activeInPeriod($end->toDateString())->pluck('id');
        if ($agentIds->isEmpty()) {
            return 0.0;
        }

        return (float) Policy::whereIn('agent_id', $agentIds)
            ->whereBetween('issue_date', [$start, $end])
            ->sum('premium_amount');
    }

    /**
     * Calcula el IRP (Índice de Retención de Pólizas):
     * (primas activas / primas totales) × 100.
     */
    private function calculateIRP(Promoter $promoter, Carbon $start, Carbon $end): float
    {
        $agentIds = $promoter->agents()->activeInPeriod($end->toDateString())->pluck('id');
        if ($agentIds->isEmpty()) {
            return 0.0;
        }

        $totalPremiums = (float) Policy::whereIn('agent_id', $agentIds)
            ->whereBetween('issue_date', [$start, $end])
            ->sum('premium_amount');

        $activePremiums = (float) Policy::whereIn('agent_id', $agentIds)
            ->whereBetween('issue_date', [$start, $end])
            ->where('status', Policy::STATUS_ACTIVA)
            ->sum('premium_amount');

        return $totalPremiums > 0
            ? round(($activePremiums / $totalPremiums) * 100, 2)
            : 0.0;
    }

    /**
     * Cuenta las pólizas de un tipo de producto específico en el periodo.
     * Soporta alias: 'Vida' cuenta METLIFE + PERFECTLIFE.
     */
    private function countPoliciesByType(Promoter $promoter, string $productType, Carbon $start, Carbon $end): int
    {
        $agentIds = $promoter->agents()->activeInPeriod($end->toDateString())->pluck('id');
        $resolvedTypes = $this->resolveProductAlias($productType);

        return Policy::whereIn('agent_id', $agentIds)
            ->whereIn('product_type', $resolvedTypes)
            ->whereBetween('issue_date', [$start, $end])
            ->count();
    }

    /**
     * Determina el trimestre calendario a partir de la fecha de fin del periodo evaluado.
     *
     * Ejemplo: si $periodEnd es 2025-03-31, el mes es 3 → Q1.
     *           si $periodEnd es 2025-08-15, el mes es 8 → Q3.
     *
     * @param  Carbon  $periodEnd  Fecha de cierre del periodo evaluado.
     * @return int  1, 2, 3 o 4.
     */
    private function getCurrentQuarter(Carbon $periodEnd): int
    {
        return (int) ceil($periodEnd->month / 3);
    }

    /**
     * Cuenta los agentes reclutados desde el inicio del año hasta el cierre
     * del trimestre evaluado, tomando como referencia el año de $periodEnd.
     *
     * @param  Promoter  $promoter   Promotor cuyos reclutas se cuentan.
     * @param  Carbon    $periodEnd  Fecha de cierre del periodo evaluado.
     * @return int
     */
    private function countYearToDateRecruits(Promoter $promoter, Carbon $periodEnd): int
    {
        $quarter   = (int) ceil($periodEnd->month / 3);
        $yearStart = $periodEnd->copy()->startOfYear();
        $quarterEnd = $periodEnd->copy()->startOfYear()->addMonths($quarter * 3)->endOfMonth();

        return $promoter->agents()
            ->whereBetween('created_at', [$yearStart, $quarterEnd])
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
        float $pp = 0.0,
        float $irp = 0.0,
        float $globalMinIrp = 0.0,
    ): array {
        return [
            'is_achieved' => false,
            'amount'      => 0.0,
            'tier_index'  => null,
            'tier_data'   => null,
            'progress'    => [
                'metric_label'   => 'PP ($)',
                'current_value'  => round($pp, 2),
                'required_value' => 0.0,
            ],
            'progress_breakdown' => [
                [
                    'label'   => 'Producción Primaria — PP ($)',
                    'current' => round($pp, 2),
                    'target'  => 0,
                    'met'     => $pp > 0,
                ],
                [
                    'label'   => 'Índice de Retención — IRP (%)',
                    'current' => round($irp, 2),
                    'target'  => $globalMinIrp,
                    'met'     => $globalMinIrp > 0 ? $irp >= $globalMinIrp : true,
                ],
            ],
            'details' => [
                'reason' => $reason,
                'pp'     => round($pp, 2),
                'irp'    => $irp,
            ],
        ];
    }
}
