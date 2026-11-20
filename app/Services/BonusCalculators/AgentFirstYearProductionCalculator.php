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
 * Calcula el Bono por Producción de 1.er Año Vida Trimestral — Agente.
 *
 * INVARIANTE DE NEGOCIO:
 *   - "Producción de 1.er año" SIEMPRE es un Bono, NUNCA una comisión.
 *   - Se evalúa CADA TRIMESTRE de forma independiente (se resetea entre trimestres).
 *   - Solo aplica a agentes que estén dentro de su primer año desde contratación.
 *
 * REGLAS POR TRIMESTRE:
 *   1. PCA trimestral ≥ min_pca del tier (PCA = Σ(premium_amount − $1,350) por póliza Pagada).
 *   2. Requisitos de producto del esquema (requires_product): cada tipo con su min_count.
 *   3. Si ambas condiciones se cumplen → bono desbloqueado para ESE trimestre.
 *   4. El monto se calcula por separado según el conducto de pago de cada póliza:
 *        - PCA en efectivo × agent_percentage del tier alcanzado (Bono Efectivo).
 *        - PCA automática × agent_automatic_percentage del tier alcanzado (Bono Automático).
 *   5. REGLA DEL 60%: si al menos 60% de las pólizas del trimestre se pagó en
 *      automático, toda la PCA se paga con el porcentaje automático.
 *
 * Condiciones leídas de $tier->conditions:
 *   - min_pca  (float)  PCA mínimo requerido en el trimestre ($).
 */
class AgentFirstYearProductionCalculator implements BonusCalculatorInterface
{
    use ProductAliasResolver;

    /** Porcentaje mínimo de pólizas en automático para pagar todo el bono como automático. */
    private const AUTOMATIC_DOMINANT_THRESHOLD = 60.0;

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
            return $this->notAchieved('El Bono de Producción de 1.er Año (Agente) solo aplica a Agentes.');
        }

        // ── Validar que el agente esté en su primer año ────────────────
        $firstYearEnd = $user->created_at
            ? $user->created_at->copy()->addYear()
            : null;

        if ($firstYearEnd === null) {
            return $this->notAchieved('El agente no tiene fecha de registro.');
        }

        // Solo excluir si el periodo COMPLETO está fuera del primer año
        if ($periodStart->greaterThan($firstYearEnd)) {
            return $this->notAchieved(
                reason: 'El agente superó su primer año el '.$firstYearEnd->format('d/m/Y').'.',
            );
        }

        // ── Versión activa ────────────────────────────────────────────
        $version = $this->resolveActiveVersion($scheme, $periodStart, $periodEnd);
        if ($version === null) {
            return $this->notAchieved('No hay versión vigente del esquema para el periodo solicitado.');
        }

        $tiers = $version->tiers()
            ->get()
            ->sortByDesc(function (SchemeTier $tier) {
                return (float) ($tier->conditions['min_pca'] ?? 0);
            })
            ->values();

        if ($tiers->isEmpty()) {
            return $this->notAchieved('El esquema no tiene tiers configurados.');
        }

        // ── Métricas del trimestre ────────────────────────────────────
        $metrics = $this->calculateQuarterlyMetrics($user, $periodStart, $periodEnd);
        $pca = $metrics['total_pca'];
        $policyCount = $metrics['total_count'];
        $automaticRatio = $metrics['automatic_ratio'];

        $productRules = $this->validateProductRules($user, $scheme, $periodStart, $periodEnd);
        $productsMet = $productRules['met'];
        $productBreakdown = $productRules['breakdown'];

        // ── Construir barras de producto ──────────────────────────────
        $productBars = [];
        foreach ($productBreakdown as $item) {
            $productBars[] = [
                'label' => 'Pólizas '.$item['product_type'],
                'current' => $item['count'],
                'target' => $item['required'],
                'met' => $item['met'],
            ];
        }

        // ── Evaluar tiers (mayor min_pca primero) ─────────────────────
        $easiestTierMinPca = (float) ($tiers->last()->conditions['min_pca'] ?? 0);

        $cashTier = $this->findTierForPca($tiers, $metrics['cash_pca']);
        $automaticTier = $this->findTierForPca($tiers, $metrics['automatic_pca']);
        $totalTier = $this->findTierForPca($tiers, $pca);

        // Regla del 60%: si la mayoría de las pólizas del trimestre son
        // automáticas, toda la PCA se paga con el porcentaje automático.
        $automaticDominant = $policyCount > 0
            && $automaticRatio >= self::AUTOMATIC_DOMINANT_THRESHOLD;

        if ($automaticDominant) {
            $cashAmount = 0.0;
            $automaticAmount = $totalTier !== null
                ? $this->percentageAmount($totalTier, $pca, $this->automaticPercentage($totalTier))
                : 0.0;
            $appliedTier = $totalTier;
        } else {
            $cashAmount = $cashTier !== null
                ? $this->percentageAmount($cashTier, $metrics['cash_pca'], (float) ($cashTier->agent_percentage ?? 0))
                : 0.0;
            $automaticAmount = $automaticTier !== null
                ? $this->percentageAmount($automaticTier, $metrics['automatic_pca'], $this->automaticPercentage($automaticTier))
                : 0.0;
            $appliedTier = $cashAmount >= $automaticAmount ? $cashTier : $automaticTier;
        }

        $amount = round($cashAmount + $automaticAmount, 2);
        $fixedAmount = $this->fixedAmount($appliedTier);
        if ($fixedAmount !== null) {
            $amount = $fixedAmount;
        }

        $paymentBars = $this->buildPaymentBars($metrics, $cashTier, $automaticTier, $totalTier, $easiestTierMinPca, $automaticDominant);

        if ($appliedTier !== null) {
            $appliedMinPca = (float) ($appliedTier->conditions['min_pca'] ?? 0);

            // El bono SOLO se desbloquea si PCA Y TODOS los productos se cumplen
            $isAchieved = $productsMet;

            return [
                'is_achieved' => $isAchieved,
                'amount' => $isAchieved ? $amount : 0.0,
                'tier_index' => $tiers->search(fn (SchemeTier $tier) => $tier->id === $appliedTier->id),
                'tier_data' => $appliedTier->toArray(),
                'progress' => [
                    'metric_label' => 'PCA Trimestral ($)',
                    'current_value' => round($pca, 2),
                    'required_value' => $appliedMinPca,
                ],
                'progress_breakdown' => array_merge(
                    $paymentBars,
                    $productBars,
                    [
                        [
                            'label' => 'Total Pólizas en el Trimestre',
                            'current' => $policyCount,
                            'target' => 0,
                            'met' => true,
                        ],
                    ],
                ),
                'details' => [
                    'calculator' => static::class,
                    'version_name' => $version->version_name,
                    'pca' => round($pca, 2),
                    'cash_pca' => $metrics['cash_pca'],
                    'automatic_pca' => $metrics['automatic_pca'],
                    'cash_amount' => round($automaticDominant ? 0.0 : $cashAmount, 2),
                    'automatic_amount' => round($automaticAmount, 2),
                    'cash_percentage' => (float) ($cashTier->agent_percentage ?? 0),
                    'automatic_percentage' => $this->automaticPercentage($appliedTier),
                    'automatic_ratio' => $automaticRatio,
                    'payment_strategy' => $automaticDominant ? 'automatic' : 'split',
                    'policy_count' => $policyCount,
                    'first_year_end' => $firstYearEnd->toDateString(),
                ],
            ];
        }

        // ── No alcanzó ningún tier ────────────────────────────────────
        return $this->notAchieved(
            reason: 'PCA trimestral: $'.number_format($pca, 2)
                .' (efectivo: $'.number_format($metrics['cash_pca'], 2)
                .', automático: $'.number_format($metrics['automatic_pca'], 2)
                .') — mínimo requerido: $'.number_format($easiestTierMinPca, 2),
            pca: $pca,
            requiredPca: $easiestTierMinPca,
            productBreakdown: $productBreakdown,
            policyCount: $policyCount,
            paymentBars: $paymentBars,
        );
    }

    // ═══════════════════════════════════════════════════════════════════════
    // ── Métricas Trimestrales ─────────────────────────────────────────────
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Calcula la PCA del trimestre separada por conducto de pago.
     *
     * PCA = Σ(premium_amount − $1,350) por cada póliza Pagada en el periodo.
     * Las pólizas sin conducto de pago se consideran automáticas.
     *
     * @return array{cash_pca: float, automatic_pca: float, total_pca: float, cash_count: int, automatic_count: int, total_count: int, automatic_ratio: float}
     */
    private function calculateQuarterlyMetrics(Agent $agent, Carbon $start, Carbon $end): array
    {
        $policies = $agent->policies()
            ->whereBetween('issue_date', [$start, $end])
            ->where('status', Policy::STATUS_PAGADA)
            ->get(['payment_method', 'premium_amount']);

        $cashPca = 0.0;
        $automaticPca = 0.0;
        $cashCount = 0;
        $automaticCount = 0;

        foreach ($policies as $policy) {
            $pca = max(0, (float) $policy->premium_amount - Policy::PCA_DEDUCTION);

            if ($policy->payment_method === Policy::PAYMENT_METHOD_CASH) {
                $cashPca += $pca;
                $cashCount++;
            } else {
                $automaticPca += $pca;
                $automaticCount++;
            }
        }

        $totalCount = $cashCount + $automaticCount;

        return [
            'cash_pca' => round($cashPca, 2),
            'automatic_pca' => round($automaticPca, 2),
            'total_pca' => round($cashPca + $automaticPca, 2),
            'cash_count' => $cashCount,
            'automatic_count' => $automaticCount,
            'total_count' => $totalCount,
            'automatic_ratio' => $totalCount > 0
                ? round(($automaticCount / $totalCount) * 100, 2)
                : 0.0,
        ];
    }

    /**
     * Valida los requisitos de producto del esquema DENTRO del trimestre.
     *
     * Soporta dos formatos:
     *   - Nuevo: [{product_type: "Primordial", min_count: 3}]
     *   - Viejo:  ["Vida", "Primordial"] + min_product_count del esquema
     *
     * @return array{met: bool, count: int, required: int, breakdown: array}
     */
    private function validateProductRules(
        Agent $agent,
        Scheme $scheme,
        Carbon $start,
        Carbon $end,
    ): array {
        $requiredProducts = $scheme->requires_product ?? [];

        if (empty($requiredProducts) || ! is_array($requiredProducts)) {
            return ['met' => true, 'count' => 0, 'required' => 0, 'breakdown' => []];
        }

        $firstItem = $requiredProducts[0] ?? null;

        // Nuevo formato: [{product_type, min_count}]
        if (is_array($firstItem) && isset($firstItem['product_type'])) {
            return $this->validateNewFormat($agent, $requiredProducts, $start, $end);
        }

        // Viejo formato: array de strings + min_product_count
        if (is_string($firstItem)) {
            $minProductCount = (int) ($scheme->min_product_count ?? 0);

            return $this->validateOldFormat($agent, $requiredProducts, $minProductCount, $start, $end);
        }

        return ['met' => true, 'count' => 0, 'required' => 0, 'breakdown' => []];
    }

    /**
     * Valida el nuevo formato [{product_type, min_count}] dentro del trimestre.
     * TODOS los productos deben cumplir su min_count individual (AND).
     */
    private function validateNewFormat(
        Agent $agent,
        array $requiredProducts,
        Carbon $start,
        Carbon $end,
    ): array {
        $allMet = true;
        $totalCount = 0;
        $totalRequired = 0;
        $breakdown = [];

        foreach ($requiredProducts as $item) {
            $productType = $item['product_type'] ?? '';
            $minCount = (int) ($item['min_count'] ?? 1);

            if ($productType === '' || $minCount <= 0) {
                continue;
            }

            $resolvedTypes = $this->resolveProductAlias($productType);
            $count = $agent->policies()
                ->whereIn('product_type', $resolvedTypes)
                ->whereBetween('issue_date', [$start, $end])
                ->where('status', Policy::STATUS_PAGADA)
                ->count();

            $met = $count >= $minCount;
            if (! $met) {
                $allMet = false;
            }

            $totalCount += $count;
            $totalRequired += $minCount;

            $breakdown[] = [
                'product_type' => $productType,
                'count' => $count,
                'required' => $minCount,
                'met' => $met,
            ];
        }

        return [
            'met' => $allMet,
            'count' => $totalCount,
            'required' => $totalRequired,
            'breakdown' => $breakdown,
        ];
    }

    /**
     * Valida el viejo formato (array de strings + min_product_count único)
     * dentro del trimestre.
     */
    private function validateOldFormat(
        Agent $agent,
        array $requiredProducts,
        int $minProductCount,
        Carbon $start,
        Carbon $end,
    ): array {
        if ($minProductCount <= 0) {
            return ['met' => true, 'count' => 0, 'required' => 0, 'breakdown' => []];
        }

        $totalCount = 0;
        $allMet = true;
        $breakdown = [];

        foreach ($requiredProducts as $productType) {
            if (! is_string($productType) || $productType === '') {
                continue;
            }

            $resolvedTypes = $this->resolveProductAlias($productType);
            $count = $agent->policies()
                ->whereIn('product_type', $resolvedTypes)
                ->whereBetween('issue_date', [$start, $end])
                ->where('status', Policy::STATUS_PAGADA)
                ->count();

            $met = $count >= $minProductCount;
            if (! $met) {
                $allMet = false;
            }

            $totalCount += $count;

            $breakdown[] = [
                'product_type' => $productType,
                'count' => $count,
                'required' => $minProductCount,
                'met' => $met,
            ];
        }

        return [
            'met' => $allMet,
            'count' => $totalCount,
            'required' => $minProductCount,
            'breakdown' => $breakdown,
        ];
    }

    // ═══════════════════════════════════════════════════════════════════════
    // ── Helpers ────────────────────────────────────────────────────────────
    // ═══════════════════════════════════════════════════════════════════════

    private function resolveActiveVersion(Scheme $scheme, Carbon $start, Carbon $end): ?SchemeVersion
    {
        $versions = $scheme->relationLoaded('versions')
            ? $scheme->versions
            : $scheme->versions()->get();

        $active = $versions->filter(function (SchemeVersion $v) use ($start, $end) {
            $starts = Carbon::parse($v->starts_at);
            $ends = $v->ends_at ? Carbon::parse($v->ends_at) : null;
            if ($starts->greaterThan($end)) {
                return false;
            }
            if ($ends !== null && $ends->lessThan($start)) {
                return false;
            }

            return true;
        });

        return $active->sortByDesc('starts_at')->first();
    }

    /**
     * Devuelve el tier de mayor min_pca que la PCA indicada alcanza.
     *
     * @param  Collection<int, SchemeTier>  $tiers
     */
    private function findTierForPca(Collection $tiers, float $pca): ?SchemeTier
    {
        if ($pca <= 0) {
            return null;
        }

        foreach ($tiers as $tier) {
            if ($pca >= (float) ($tier->conditions['min_pca'] ?? 0)) {
                return $tier;
            }
        }

        return null;
    }

    private function percentageAmount(SchemeTier $tier, float $base, float $percentage): float
    {
        return round($base * ($percentage / 100), 2);
    }

    private function automaticPercentage(SchemeTier $tier): float
    {
        return (float) ($tier->agent_automatic_percentage ?? 0);
    }

    private function fixedAmount(?SchemeTier $tier): ?float
    {
        if ($tier !== null && ! empty($tier->fixed_amount) && (float) $tier->fixed_amount > 0) {
            return (float) $tier->fixed_amount;
        }

        return null;
    }

    /**
     * Construye las barras de progreso del conducto de pago.
     *
     * @param  array{cash_pca: float, automatic_pca: float, total_pca: float, automatic_ratio: float}  $metrics
     * @return array<int, array{label: string, current: float, target: float, met: bool}>
     */
    private function buildPaymentBars(
        array $metrics,
        ?SchemeTier $cashTier,
        ?SchemeTier $automaticTier,
        ?SchemeTier $totalTier,
        float $easiestTierMinPca,
        bool $automaticDominant,
    ): array {
        if ($automaticDominant) {
            $bars = [
                [
                    'label' => 'PCA Trimestral ($)',
                    'current' => $metrics['total_pca'],
                    'target' => $totalTier !== null
                        ? (float) ($totalTier->conditions['min_pca'] ?? 0)
                        : $easiestTierMinPca,
                    'met' => $totalTier !== null,
                ],
            ];
        } else {
            $bars = [
                [
                    'label' => 'PCA Efectivo ($)',
                    'current' => $metrics['cash_pca'],
                    'target' => $cashTier !== null
                        ? (float) ($cashTier->conditions['min_pca'] ?? 0)
                        : $easiestTierMinPca,
                    'met' => $cashTier !== null,
                ],
                [
                    'label' => 'PCA Automático ($)',
                    'current' => $metrics['automatic_pca'],
                    'target' => $automaticTier !== null
                        ? (float) ($automaticTier->conditions['min_pca'] ?? 0)
                        : $easiestTierMinPca,
                    'met' => $automaticTier !== null,
                ],
            ];
        }

        $bars[] = [
            'label' => 'Pólizas en pago automático (%)',
            'current' => $metrics['automatic_ratio'],
            'target' => self::AUTOMATIC_DOMINANT_THRESHOLD,
            'met' => $automaticDominant,
        ];

        return $bars;
    }

    /**
     * Retorna un resultado estándar de «no alcanzado».
     */
    private function notAchieved(
        string $reason = '',
        float $pca = 0.0,
        float $requiredPca = 0.0,
        array $productBreakdown = [],
        int $policyCount = 0,
        array $paymentBars = [],
    ): array {
        $breakdown = $paymentBars;

        if ($breakdown === []) {
            $breakdown[] = [
                'label' => 'PCA Trimestral ($)',
                'current' => round($pca, 2),
                'target' => $requiredPca,
                'met' => $requiredPca > 0 ? $pca >= $requiredPca : ($pca > 0),
            ];
        }

        foreach ($productBreakdown as $item) {
            $breakdown[] = [
                'label' => 'Pólizas '.$item['product_type'],
                'current' => $item['count'],
                'target' => $item['required'],
                'met' => $item['met'],
            ];
        }

        $breakdown[] = [
            'label' => 'Total Pólizas en el Trimestre',
            'current' => $policyCount,
            'target' => 0,
            'met' => true,
        ];

        return [
            'is_achieved' => false,
            'amount' => 0.0,
            'tier_index' => null,
            'tier_data' => null,
            'progress' => [
                'metric_label' => 'PCA Trimestral ($)',
                'current_value' => round($pca, 2),
                'required_value' => $requiredPca,
            ],
            'progress_breakdown' => $breakdown,
            'details' => ['reason' => $reason],
        ];
    }
}
