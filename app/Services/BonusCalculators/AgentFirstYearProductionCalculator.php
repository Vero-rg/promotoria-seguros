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
 * Calcula el Bono por Producción de 1.er Año Vida Trimestral — Agente.
 *
 * INVARIANTE DE NEGOCIO:
 *   - "Producción de 1.er año" SIEMPRE es un Bono, NUNCA una comisión.
 *   - Se evalúa CADA TRIMESTRE de forma independiente (se resetea entre trimestres).
 *   - Solo aplica a agentes que estén dentro de su primer año desde contratación.
 *
 * REGLAS POR TRIMESTRE:
 *   1. PCA trimestral ≥ min_pca del tier (PCA = Σ(premium_amount − $1,500) por póliza Pagada).
 *   2. Requisitos de producto del esquema (requires_product): cada tipo con su min_count.
 *   3. Si ambas condiciones se cumplen → bono desbloqueado para ESE trimestre.
 *   4. El monto del bono = PCA_trimestral × agent_percentage del tier alcanzado.
 *
 * Condiciones leídas de $tier->conditions:
 *   - min_pca  (float)  PCA mínimo requerido en el trimestre ($).
 */
class AgentFirstYearProductionCalculator implements BonusCalculatorInterface
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
                reason: 'El agente superó su primer año el ' . $firstYearEnd->format('d/m/Y') . '.',
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
        $pca = $this->calculateQuarterlyPca($user, $periodStart, $periodEnd);
        $policyCount = $this->countQuarterlyPolicies($user, $periodStart, $periodEnd);
        $productRules = $this->validateProductRules($user, $scheme, $periodStart, $periodEnd);
        $productsMet = $productRules['met'];
        $productBreakdown = $productRules['breakdown'];

        // ── Construir barras de producto ──────────────────────────────
        $productBars = [];
        foreach ($productBreakdown as $item) {
            $productBars[] = [
                'label'   => 'Pólizas ' . $item['product_type'],
                'current' => $item['count'],
                'target'  => $item['required'],
                'met'     => $item['met'],
            ];
        }

        // ── Evaluar tiers (mayor min_pca primero) ─────────────────────
        $easiestTierMinPca = 0.0;
        foreach ($tiers as $index => $tier) {
            $conditions = $tier->conditions ?? [];
            $minPca = (float) ($conditions['min_pca'] ?? 0);
            $easiestTierMinPca = $minPca;

            if ($pca >= $minPca) {
                $amount = $this->calculateAmount($tier, $user, $pca);

                // El bono SOLO se desbloquea si PCA Y TODOS los productos se cumplen
                $isAchieved = $productsMet;

                $pcaBar = [
                    'label'   => 'PCA Trimestral ($)',
                    'current' => round($pca, 2),
                    'target'  => $minPca,
                    'met'     => $pca >= $minPca,
                ];

                return [
                    'is_achieved' => $isAchieved,
                    'amount'      => $isAchieved ? $amount : 0.0,
                    'tier_index'  => $index,
                    'tier_data'   => $tier->toArray(),
                    'progress'    => [
                        'metric_label'   => 'PCA Trimestral ($)',
                        'current_value'  => round($pca, 2),
                        'required_value' => $minPca,
                    ],
                    'progress_breakdown' => array_merge(
                        [$pcaBar],
                        $productBars,
                        [
                            [
                                'label'   => 'Total Pólizas en el Trimestre',
                                'current' => $policyCount,
                                'target'  => 0,
                                'met'     => true,
                            ],
                        ],
                    ),
                    'details'     => [
                        'calculator'     => static::class,
                        'version_name'   => $version->version_name,
                        'pca'            => round($pca, 2),
                        'policy_count'   => $policyCount,
                        'first_year_end' => $firstYearEnd->toDateString(),
                    ],
                ];
            }
        }

        // ── No alcanzó ningún tier ────────────────────────────────────
        return $this->notAchieved(
            reason: "PCA trimestral: \$" . number_format($pca, 2)
                . " — mínimo requerido: \$" . number_format($easiestTierMinPca, 2),
            pca: $pca,
            requiredPca: $easiestTierMinPca,
            productBreakdown: $productBreakdown,
            policyCount: $policyCount,
        );
    }

    // ═══════════════════════════════════════════════════════════════════════
    // ── Métricas Trimestrales ─────────────────────────────────────────────
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Calcula la PCA trimestral del agente.
     *
     * PCA = Σ(premium_amount − $1,500) por cada póliza Pagada en el periodo.
     * Mínimo $0 por póliza.
     */
    private function calculateQuarterlyPca(Agent $agent, Carbon $start, Carbon $end): float
    {
        $policies = $agent->policies()
            ->whereBetween('issue_date', [$start, $end])
            ->where('status', Policy::STATUS_PAGADA)
            ->get();

        return $policies->sum(fn($p) => max(0, (float) $p->premium_amount - 1500));
    }

    /**
     * Cuenta el total de pólizas Pagadas en el trimestre.
     */
    private function countQuarterlyPolicies(Agent $agent, Carbon $start, Carbon $end): int
    {
        return $agent->policies()
            ->whereBetween('issue_date', [$start, $end])
            ->where('status', Policy::STATUS_PAGADA)
            ->count();
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

        if (empty($requiredProducts) || !is_array($requiredProducts)) {
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
            $minCount    = (int) ($item['min_count'] ?? 1);

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
            if (!$met) {
                $allMet = false;
            }

            $totalCount    += $count;
            $totalRequired += $minCount;

            $breakdown[] = [
                'product_type' => $productType,
                'count'        => $count,
                'required'     => $minCount,
                'met'          => $met,
            ];
        }

        return [
            'met'       => $allMet,
            'count'     => $totalCount,
            'required'  => $totalRequired,
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
            if (!is_string($productType) || $productType === '') {
                continue;
            }

            $resolvedTypes = $this->resolveProductAlias($productType);
            $count = $agent->policies()
                ->whereIn('product_type', $resolvedTypes)
                ->whereBetween('issue_date', [$start, $end])
                ->where('status', Policy::STATUS_PAGADA)
                ->count();

            $met = $count >= $minProductCount;
            if (!$met) {
                $allMet = false;
            }

            $totalCount += $count;

            $breakdown[] = [
                'product_type' => $productType,
                'count'        => $count,
                'required'     => $minProductCount,
                'met'          => $met,
            ];
        }

        return [
            'met'       => $allMet,
            'count'     => $totalCount,
            'required'  => $minProductCount,
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
        $percentage = (float) ($tier->agent_percentage ?? 0);
        return round($base * ($percentage / 100), 2);
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
    ): array {
        $breakdown = [
            [
                'label'   => 'PCA Trimestral ($)',
                'current' => round($pca, 2),
                'target'  => $requiredPca,
                'met'     => $requiredPca > 0 ? $pca >= $requiredPca : ($pca > 0),
            ],
        ];

        foreach ($productBreakdown as $item) {
            $breakdown[] = [
                'label'   => 'Pólizas ' . $item['product_type'],
                'current' => $item['count'],
                'target'  => $item['required'],
                'met'     => $item['met'],
            ];
        }

        $breakdown[] = [
            'label'   => 'Total Pólizas en el Trimestre',
            'current' => $policyCount,
            'target'  => 0,
            'met'     => true,
        ];

        return [
            'is_achieved' => false,
            'amount'      => 0.0,
            'tier_index'  => null,
            'tier_data'   => null,
            'progress'    => [
                'metric_label'   => 'PCA Trimestral ($)',
                'current_value'  => round($pca, 2),
                'required_value' => $requiredPca,
            ],
            'progress_breakdown' => $breakdown,
            'details' => ['reason' => $reason],
        ];
    }
}

