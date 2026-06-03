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
 * Calcula el Bono por Producción de 1.er Año — Agente.
 *
 * INVARIANTE DE NEGOCIO: "Producción de 1.er año" SIEMPRE es un Bono, NUNCA una comisión.
 *
 * Evalúa si un agente, en su primer año desde la contratación, alcanzó
 * un PCA mínimo (Prima Cobrada Anual).
 *
 * Condiciones leídas de $tier->conditions:
 *   - min_pca  (float)  PCA mínimo requerido en el primer año ($).
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
        // Solo aplica a Agents
        if ($user instanceof Promoter) {
            return $this->notAchieved('El Bono de Producción de 1.er Año (Agente) solo aplica a Agentes.');
        }

        $version = $this->resolveActiveVersion($scheme, $periodStart, $periodEnd);
        if ($version === null) {
            return $this->notAchieved('No hay versión vigente del esquema para el periodo solicitado.');
        }

        $tiers = $version->tiers()
            ->get()
            ->sortByDesc(function (SchemeTier $tier) {
                // Ordenar por min_pca descendente: el tier más exigente primero
                return (float) ($tier->conditions['min_pca'] ?? 0);
            })
            ->values(); // Reindexar para que tier_index sea 0, 1, 2…
        if ($tiers->isEmpty()) {
            return $this->notAchieved('El esquema no tiene tiers configurados.');
        }

        // ── Solo evaluar agentes en su primer año ──────────────────────
        $firstYearEnd = $user->created_at
            ? $user->created_at->copy()->addYear()
            : null;

        if ($firstYearEnd === null || $periodEnd->greaterThan($firstYearEnd)) {
            return $this->notAchieved('El agente ya superó su primer año de producción.');
        }

        // ── Validar Reglas Globales del Esquema ────────────────────────
        $primordialInfo = $this->validateGlobalRules($user, $scheme);
        $primordialMet   = $primordialInfo['met'];
        $primordialCount = $primordialInfo['count'];
        $primordialRequired = $primordialInfo['required'];

        // ── Métrica ────────────────────────────────────────────────────
        $pca = $this->calculateFirstYearPca($user);

        // ── Evaluar tiers (ordenados de mayor a menor min_pca) ────────
        $easiestTierMinPca = 0.0; // El PCA mínimo del tier más bajo (para progreso)
        foreach ($tiers as $index => $tier) {
            $conditions = $tier->conditions ?? [];
            $minPca = (float) ($conditions['min_pca'] ?? 0);

            // El último tier del recorrido (menor min_pca) es el más fácil
            $easiestTierMinPca = $minPca;

            if ($pca >= $minPca) {
                $amount = $this->calculateAmount($tier, $user, $pca);

                // El bono SOLO se desbloquea si PCA Y Primordial se cumplen
                $isAchieved = $primordialMet;

                return [
                    'is_achieved' => $isAchieved,
                    'amount'      => $isAchieved ? $amount : 0.0,
                    'tier_index'  => $index,
                    'tier_data'   => $tier->toArray(),
                    'progress'    => [
                        'metric_label'   => 'PCA 1.er Año ($)',
                        'current_value'  => round($pca, 2),
                        'required_value' => $minPca,
                    ],
                    'progress_breakdown' => [
                        [
                            'label'   => 'PCA Acumulada 1.er Año ($)',
                            'current' => round($pca, 2),
                            'target'  => $minPca,
                            'met'     => $pca >= $minPca,
                        ],
                        [
                            'label'   => 'Pólizas Primordial',
                            'current' => $primordialCount,
                            'target'  => $primordialRequired,
                            'met'     => $primordialMet,
                        ],
                    ],
                    'details'     => [
                        'calculator'     => static::class,
                        'version_name'   => $version->version_name,
                        'pca'            => round($pca, 2),
                        'first_year_end' => $firstYearEnd->toDateString(),
                    ],
                ];
            }
        }

        return $this->notAchieved(
            reason: "PCA 1.er año: \$" . number_format($pca, 2),
            currentPca: $pca,
            requiredPca: $easiestTierMinPca,
            primordialCount: $primordialCount,
            primordialRequired: $primordialRequired,
            primordialMet: $primordialMet,
        );
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    /**
     * Valida las reglas globales del esquema (requires_product, min_product_count).
     *
     * Retorna un arreglo con el conteo de pólizas Primordial y si se cumple
     * el requisito. Ya NO aborta el flujo — permite que el cálculo continúe
     * para mostrar el progress_breakdown completo.
     *
     * @return array{met: bool, count: int, required: int}
     */
    private function validateGlobalRules(
        Agent $agent,
        Scheme $scheme,
    ): array {
        $requiredProducts = $scheme->requires_product ?? [];
        $minProductCount  = (int) ($scheme->min_product_count ?? 0);

        // Si no hay productos requeridos configurados, se omite esta validación
        if (empty($requiredProducts) || $minProductCount <= 0) {
            return ['met' => true, 'count' => 0, 'required' => 0];
        }

        foreach ($requiredProducts as $productType) {
            // Resolver alias: 'Vida' → ['METLIFE', 'PERFECTLIFE']
            $resolvedTypes = $this->resolveProductAlias($productType);

            // Contar TODAS las pólizas del agente de los tipos resueltos, sin limitar por periodo.
            // El requisito de producto es global (¿tiene el agente este producto?),
            // mientras que el PCA sí está acotado al primer año.
            $count = $agent->policies()
                ->whereIn('product_type', $resolvedTypes)
                ->where('status', Policy::STATUS_PAGADA)
                ->count();

            return [
                'met'      => $count >= $minProductCount,
                'count'    => $count,
                'required' => $minProductCount,
            ];
        }

        return ['met' => true, 'count' => 0, 'required' => 0];
    }

    /**
     * Cuenta las pólizas del agente de un tipo de producto específico en un periodo.
     * Soporta alias: 'Vida' cuenta METLIFE + PERFECTLIFE.
     */
    private function countPoliciesByProduct(
        Agent $agent,
        string $productType,
        Carbon $periodStart,
        Carbon $periodEnd
    ): int {
        $resolvedTypes = $this->resolveProductAlias($productType);

        return $agent->policies()
            ->whereIn('product_type', $resolvedTypes)
            ->whereBetween('issue_date', [$periodStart, $periodEnd])
            ->where('status', Policy::STATUS_PAGADA)
            ->count();
    }

    /**
     * Suma las primas de pólizas del agente emitidas en su primer año.
     */
    private function calculateFirstYearPca(Agent $agent): float
    {
        $firstYearEnd = $agent->created_at->copy()->addYear();

        return (float) $agent->policies()
            ->whereBetween('issue_date', [$agent->created_at, $firstYearEnd])
            ->where('status', Policy::STATUS_PAGADA)
            ->sum('premium_amount');
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
        // Para Agent, siempre usamos agent_percentage
        $percentage = (float) ($tier->agent_percentage ?? 0);
        return round($base * ($percentage / 100), 2);
    }

    /**
     * Retorna un resultado estándar de «no alcanzado».
     *
     * @param  string  $reason              Motivo del fallo (legible por humanos).
     * @param  float   $currentPca          PCA actual para mostrar en el progreso (default 0).
     * @param  float   $requiredPca         PCA requerido para el tier más bajo (default 0).
     * @param  int     $primordialCount     Conteo real de pólizas Primordial pagadas.
     * @param  int     $primordialRequired  Mínimo requerido de pólizas Primordial.
     * @param  bool    $primordialMet       Si se cumple el requisito Primordial.
     */
    private function notAchieved(
        string $reason,
        float $currentPca = 0.0,
        float $requiredPca = 0.0,
        int $primordialCount = 0,
        int $primordialRequired = 0,
        bool $primordialMet = true,
    ): array {
        $breakdown = [
            [
                'label'   => 'PCA Acumulada 1.er Año ($)',
                'current' => round($currentPca, 2),
                'target'  => $requiredPca,
                'met'     => $requiredPca > 0 ? $currentPca >= $requiredPca : ($currentPca > 0),
            ],
        ];

        // Agregar barra de Primordial si el esquema lo requiere
        if ($primordialRequired > 0) {
            $breakdown[] = [
                'label'   => 'Pólizas Primordial',
                'current' => $primordialCount,
                'target'  => $primordialRequired,
                'met'     => $primordialMet,
            ];
        }

        return [
            'is_achieved' => false,
            'amount'      => 0.0,
            'tier_index'  => null,
            'tier_data'   => null,
            'progress'    => [
                'metric_label'   => 'PCA 1.er Año ($)',
                'current_value'  => round($currentPca, 2),
                'required_value' => $requiredPca,
            ],
            'progress_breakdown' => $breakdown,
            'details' => ['reason' => $reason],
        ];
    }
}
