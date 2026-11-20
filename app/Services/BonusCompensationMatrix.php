<?php

namespace App\Services;

use App\Models\Scheme;
use App\Models\SchemeTier;
use App\Models\SchemeVersion;
use Illuminate\Support\Collection;

/**
 * Construye el resumen de consulta de cada bono: la matriz de compensación,
 * los requisitos del esquema y el «siguiente nivel» con lo que falta.
 *
 * El frontend solo renderiza la estructura ya normalizada, de modo que
 * agregar un bono nuevo no requiere cambios en el panel.
 */
class BonusCompensationMatrix
{
    /** Condiciones cuyo valor se muestra como moneda. */
    private const MONEY_KEYS = ['min_pp', 'min_pca'];

    /** Condiciones cuyo valor se muestra como porcentaje. */
    private const PERCENT_KEYS = ['min_irp'];

    /** Condiciones cuyo valor se muestra con un decimal. */
    private const DECIMAL_KEYS = ['min_policies', 'min_recruits'];

    /**
     * Resumen completo de la matriz del bono para mostrar en el panel.
     *
     * @param  Scheme  $scheme  Esquema (con versions.tiers cargados).
     * @param  array   $result  Resultado enriquecido del bono.
     * @return array{dependency: ?string, products: array, recruits: ?array, anticipos: array, equivalences: array, rules: array, tiers: array}
     */
    public function build(Scheme $scheme, array $result): array
    {
        $tiers = $this->orderedTiers($scheme, $result);
        $achievedTierId = $result['tier_data']['id'] ?? null;

        return [
            'dependency'   => $result['dependency_scheme_name'] ?? null,
            'products'     => $this->products($scheme),
            'recruits'     => $this->recruits($scheme, $result),
            'anticipos'    => $this->anticipos($scheme),
            'equivalences' => $this->equivalences($scheme),
            'rules'        => $this->rules($scheme),
            'tiers'        => $tiers
                ->values()
                ->map(fn (SchemeTier $tier, int $index) => $this->tierRow($scheme, $tier, $index, $achievedTierId))
                ->all(),
        ];
    }

    /**
     * Porcentaje y monto que el usuario ganó con este bono.
     *
     * @return array{percentage: ?float, fixed_amount: ?float, amount: float, note: ?string}
     */
    public function earned(Scheme $scheme, array $result): array
    {
        $tier = $result['tier_data'] ?? null;
        $details = $result['details'] ?? [];

        $percentage = $tier !== null ? $this->percentageOf($scheme, $tier) : null;
        $note = null;

        if ((string) $scheme->template_key === 'agent_first_year_production' && isset($details['cash_percentage'])) {
            // El pago real depende de la estrategia aplicada (efectivo o automático).
            $percentage = ($details['payment_strategy'] ?? 'split') === 'automatic'
                ? (float) ($details['automatic_percentage'] ?? 0)
                : (float) $details['cash_percentage'];

            $note = 'Efectivo '.$this->percent((float) $details['cash_percentage'])
                .' · Automático '.$this->percent((float) ($details['automatic_percentage'] ?? 0));
        }

        if (!empty($details['best_month'])) {
            $note = 'Tramo del mejor mes: '.$details['best_month'];
        }

        return [
            'percentage'   => $percentage,
            'fixed_amount' => $tier !== null && !empty($tier['fixed_amount']) ? (float) $tier['fixed_amount'] : null,
            'amount'       => (float) ($result['amount'] ?? 0),
            'note'         => $note,
        ];
    }

    /**
     * Siguiente nivel alcanzable y lo que falta para llegar a él.
     *
     * Se omite en bonos cuya matriz se evalúa por agente (Desarrollo Mensual),
     * porque no existe un único «siguiente nivel» para el promotor.
     *
     * @return array{label: string, requirement: string, percentage: float, gaps: array<int, array>}|null
     */
    public function nextLevel(Scheme $scheme, array $result): ?array
    {
        if ((string) $scheme->template_key === 'monthly_development') {
            return null;
        }

        $tiers = $this->orderedTiers($scheme, $result)
            ->sortBy(fn (SchemeTier $tier) => $this->percentageOf($scheme, $tier))
            ->values();

        if ($tiers->isEmpty()) {
            return null;
        }

        $currentTierId = $result['tier_data']['id'] ?? null;
        $currentPercentage = 0.0;

        if ($currentTierId !== null) {
            $current = $tiers->firstWhere('id', $currentTierId);
            $currentPercentage = $current !== null ? $this->percentageOf($scheme, $current) : 0.0;
        }

        /** @var SchemeTier|null $next */
        $next = $tiers->first(fn (SchemeTier $tier) => $this->percentageOf($scheme, $tier) > $currentPercentage);

        if ($next === null) {
            return null;
        }

        $gaps = $this->gaps($next, $result['details']['metrics'] ?? []);

        if ($gaps === []) {
            return null;
        }

        [$label, $requirement] = $this->tierLabels($scheme, $next, $tiers->search(fn (SchemeTier $t) => $t->id === $next->id));

        $percentage = $this->percentageOf($scheme, $next);
        $verb = $gaps[0]['gap'] <= 1 ? 'Falta' : 'Faltan';

        $message = $verb.' '.$this->gapPhrase($gaps[0])
            .' para '.$label.' ('.$this->percent($percentage).')';

        if (count($gaps) > 1) {
            $message .= ' y '.(count($gaps) - 1).' requisito más';
        }

        return [
            'label'       => $label,
            'requirement' => $requirement,
            'percentage'  => $percentage,
            'gaps'        => $gaps,
            'message'     => $message,
        ];
    }

    /**
     * Frase corta y legible de lo que falta para el siguiente nivel.
     */
    private function gapPhrase(array $gap): string
    {
        $value = (float) $gap['gap'];

        $formatted = match ($gap['format']) {
            'currency' => $this->money($value),
            'percent'  => $this->percent($value),
            'decimal'  => rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.'),
            default    => (string) (int) $value,
        };

        return match ($gap['key']) {
            'min_pp'       => $formatted.' en producción primaria (PP)',
            'min_pca'      => $formatted.' en PCA',
            'min_irp'      => $formatted.' puntos de IRP',
            'min_policies' => $formatted.' de promedio mensual',
            'min_recruits' => $formatted.' '.($value == 1 ? 'recluta' : 'reclutas'),
            'min_agents'   => $formatted.' '.($value == 1 ? 'agente con compensación' : 'agentes con compensación'),
            default        => $formatted.' de '.strtolower($gap['label']),
        };
    }

    // ═══════════════════════════════════════════════════════════════════════
    // ── Tramos ────────────────────────────────────────────────────────────
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Tiers de la versión vigente en el periodo evaluado, ordenados por su
     * umbral ascendente.
     *
     * @return Collection<int, SchemeTier>
     */
    private function orderedTiers(Scheme $scheme, array $result): Collection
    {
        $versions = $scheme->relationLoaded('versions') ? $scheme->versions : $scheme->versions()->get();

        $start = $result['effective_period_start'] ?? null;
        $end   = $result['effective_period_end'] ?? null;

        $active = $versions->filter(function (SchemeVersion $version) use ($start, $end) {
            if ($start === null || $end === null) {
                return true;
            }

            $startsAt = $version->starts_at;
            $endsAt   = $version->ends_at;

            if ($startsAt !== null && $startsAt > $end) {
                return false;
            }

            return $endsAt === null || $endsAt >= $start;
        });

        /** @var SchemeVersion|null $version */
        $version = ($active->isNotEmpty() ? $active : $versions)->sortByDesc('starts_at')->first();

        if ($version === null) {
            return collect();
        }

        $tiers = $version->relationLoaded('tiers') ? $version->tiers : $version->tiers()->get();

        return $tiers
            ->sortBy(fn (SchemeTier $tier) => [
                $this->threshold($tier),
                (float) ($tier->conditions['max_policies'] ?? $tier->conditions['max_recruits'] ?? 0),
            ])
            ->values();
    }

    /**
     * Umbral principal del tier (el mínimo de la métrica que lo define).
     */
    private function threshold(SchemeTier $tier): float
    {
        $conditions = $tier->conditions ?? [];

        foreach (['min_policies', 'min_agents', 'min_recruits', 'min_pp', 'min_pca', 'min_month'] as $key) {
            if (isset($conditions[$key])) {
                return (float) $conditions[$key];
            }
        }

        return 0.0;
    }

    /**
     * Fila de la matriz para un tier.
     */
    private function tierRow(Scheme $scheme, SchemeTier $tier, int $index, ?int $achievedTierId): array
    {
        [$label, $requirement] = $this->tierLabels($scheme, $tier, $index);

        $fixedAmount = !empty($tier->fixed_amount) ? (float) $tier->fixed_amount : null;
        $percentage = $this->percentageOf($scheme, $tier);

        $payment = match (true) {
            $fixedAmount !== null => $this->money($fixedAmount),
            (string) $scheme->template_key === 'agent_first_year_production' => 'Efectivo '
                .$this->percent((float) ($tier->agent_percentage ?? 0))
                .' · Automático '.$this->percent((float) ($tier->agent_automatic_percentage ?? 0)),
            default => $this->percent($percentage),
        };

        return [
            'label'        => $label,
            'requirement'  => $requirement,
            'percentage'   => $percentage,
            'fixed_amount' => $fixedAmount,
            'payment'      => $payment,
            'achieved'     => $achievedTierId !== null && (int) $tier->id === (int) $achievedTierId,
        ];
    }

    /**
     * Nombre y requisito legible de un tier según el tipo de bono.
     *
     * @return array{0: string, 1: string}
     */
    private function tierLabels(Scheme $scheme, SchemeTier $tier, int $index): array
    {
        $conditions = $tier->conditions ?? [];

        return match ((string) $scheme->template_key) {
            'activity_ratio' => [
                $conditions['classification'] ?? 'Nivel '.($index + 1),
                'Promedio mensual '.$this->range($conditions['min_policies'] ?? 0, $conditions['max_policies'] ?? null, 2),
            ],
            'agent_first_year_production' => [
                'PCA ≥ '.$this->money($conditions['min_pca'] ?? 0),
                $this->productsSummary($scheme) ?: '—',
            ],
            'first_year_production' => [
                'PP ≥ '.$this->money($conditions['min_pp'] ?? 0),
                'IRP ≥ '.$this->percent($conditions['min_irp'] ?? 0),
            ],
            'additional_agents' => [
                $this->agentsLabel($conditions),
                'Agentes con Activity Ratio ganado',
            ],
            'connection' => [
                $this->range($conditions['min_recruits'] ?? 0, $conditions['max_recruits'] ?? null, 0).' reclutas',
                'PCA individual ≥ '.$this->money($conditions['min_pca'] ?? 0),
            ],
            'monthly_development' => [
                'Antigüedad '.$this->range($conditions['min_month'] ?? 1, $conditions['max_month'] ?? null, 0).' meses',
                'PCA del agente ≥ '.$this->money($conditions['min_pca'] ?? 0),
            ],
            default => [
                'Nivel '.($index + 1),
                '—',
            ],
        };
    }

    /**
     * Porcentaje que paga el tier al usuario evaluado.
     */
    private function percentageOf(Scheme $scheme, SchemeTier|array $tier): float
    {
        $key = $scheme->target === 'promoter' ? 'promoter_percentage' : 'agent_percentage';

        return (float) ($tier[$key] ?? 0);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // ── Requisitos del esquema ────────────────────────────────────────────
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Productos requeridos por el esquema (soporta formato nuevo y legado).
     *
     * @return array<int, array{label: string, min: int}>
     */
    private function products(Scheme $scheme): array
    {
        $required = $scheme->requires_product ?? [];

        if (empty($required)) {
            return [];
        }

        $defaultMin = (int) ($scheme->min_product_count ?? 1);
        $products = [];

        foreach ($required as $item) {
            if (is_array($item)) {
                $products[] = [
                    'label' => (string) ($item['product_type'] ?? '—'),
                    'min'   => (int) ($item['min_count'] ?? $defaultMin),
                ];
            } elseif (is_string($item) && $item !== '') {
                $products[] = ['label' => $item, 'min' => max(1, $defaultMin)];
            }
        }

        return $products;
    }

    /**
     * Resumen compacto de productos requeridos: «Vida ×1 · Primordial ×3».
     */
    private function productsSummary(Scheme $scheme): string
    {
        return collect($this->products($scheme))
            ->map(fn (array $product) => $product['label'].' ×'.$product['min'])
            ->implode(' · ');
    }

    /**
     * Meta de reclutas por trimestre y el avance del trimestre evaluado.
     */
    private function recruits(Scheme $scheme, array $result): ?array
    {
        $quota = $scheme->quarterly_recruits ?? [];

        if (empty(array_filter($quota))) {
            return null;
        }

        $periodEnd = $result['effective_period_end'] ?? null;
        $quarter = $periodEnd !== null ? (int) ceil((int) substr($periodEnd, 5, 2) / 3) : null;

        $details = $result['details'] ?? [];
        $actual = $details['quarterly_recruits'] ?? $details['metrics']['min_recruits'] ?? null;

        // Los bonos que muestran una barra «Reclutas...» reportan ahí su avance.
        if ($actual === null) {
            foreach ($result['progress_breakdown'] ?? [] as $item) {
                if (str_starts_with((string) ($item['label'] ?? ''), 'Reclutas')) {
                    $actual = $item['current'] ?? null;
                    break;
                }
            }
        }

        return [
            'current_quarter' => $quarter,
            'goal'            => $quarter !== null ? (int) ($quota["q{$quarter}"] ?? 0) : null,
            'actual'          => $actual !== null ? (int) $actual : null,
            'quarters'        => collect(range(1, 4))
                ->map(fn (int $q) => ['label' => "Q{$q}", 'required' => (int) ($quota["q{$q}"] ?? 0)])
                ->all(),
        ];
    }

    /**
     * Metas mínimas de producción para cada anticipo mensual.
     *
     * @return array<int, array{label: string, min: float}>
     */
    private function anticipos(Scheme $scheme): array
    {
        if (! $scheme->requires_anticipos) {
            return [];
        }

        $config = $scheme->anticipos_config ?? [];
        $target = $scheme->target === 'agent' ? '' : ' del equipo';
        $rows = [];

        foreach (['month_1_min' => 'Anticipo mes 1', 'month_2_min' => 'Anticipo mes 2'] as $key => $label) {
            $min = (float) ($config[$key] ?? 0);

            if ($min > 0) {
                $rows[] = ['label' => $label, 'min' => $min];
            }
        }

        if ($rows === []) {
            return [['label' => 'Permite anticipos mensuales', 'min' => 0.0]];
        }

        return $rows;
    }

    /**
     * Reglas de equivalencia PNA (solo Activity Ratio).
     *
     * @return array<int, array{label: string, value: string}>
     */
    private function equivalences(Scheme $scheme): array
    {
        if ((string) $scheme->template_key !== 'activity_ratio') {
            return [];
        }

        return collect($scheme->pna_equivalences ?? [])
            ->map(fn (array $item) => [
                'label' => $this->range($item['min_pna'] ?? 0, $item['max_pna'] ?? null, 0, true),
                'value' => rtrim(rtrim(number_format((float) ($item['policies'] ?? 0), 2, '.', ''), '0'), '.'),
            ])
            ->values()
            ->all();
    }

    /**
     * Reglas cortas que ayudan a leer el bono.
     *
     * @return array<int, string>
     */
    private function rules(Scheme $scheme): array
    {
        $rules = [];

        if ((string) $scheme->template_key === 'activity_ratio') {
            $rules[] = 'Solo cuentan pólizas de vida (METALIFE y PERFECTLIFE).';
            $rules[] = 'Promedio mensual = Total ponderado ÷ 3.';
        }

        if ((float) ($scheme->min_irp ?? 0) > 0) {
            $rules[] = 'IRP mínimo global: '.$this->percent((float) $scheme->min_irp).'.';
        }

        if ((float) ($scheme->min_collection_efficiency ?? 0) > 0) {
            $rules[] = 'Eficiencia al cobro mínima: '.$this->percent((float) $scheme->min_collection_efficiency).'.';
        }

        if ((string) $scheme->template_key === 'connection') {
            $rules[] = 'El porcentaje lo define el recluta con la PCA más alta.';
        }

        if ((string) $scheme->template_key === 'additional_agents') {
            $rules[] = 'Cada agente cuenta una vez por trimestre.';
        }

        return $rules;
    }

    /**
     * Requisitos faltantes del tier objetivo según las métricas actuales.
     *
     * @param  array<string, mixed>  $metrics
     * @return array<int, array{label: string, current: float, target: float, gap: float, format: string}>
     */
    private function gaps(SchemeTier $tier, array $metrics): array
    {
        $gaps = [];

        foreach ($tier->conditions ?? [] as $key => $value) {
            if (! str_starts_with((string) $key, 'min_') || ! array_key_exists($key, $metrics) || $value === null || $value === '') {
                continue;
            }

            $current = (float) $metrics[$key];
            $target = (float) $value;

            if ($current >= $target) {
                continue;
            }

            $gaps[] = [
                'key'     => $key,
                'label'   => $this->metricLabel($key, $tier),
                'current' => round($current, 2),
                'target'  => round($target, 2),
                'gap'     => round($target - $current, 2),
                'format'  => $this->format($key),
            ];
        }

        return $gaps;
    }

    private function metricLabel(string $key, SchemeTier $tier): string
    {
        return match ($key) {
            'min_pp'       => 'Producción primaria',
            'min_pca'      => 'PCA',
            'min_irp'      => 'IRP',
            'min_policies' => 'Promedio mensual',
            'min_recruits' => 'Reclutas en el periodo',
            'min_agents'   => 'Agentes con compensación',
            default        => $key,
        };
    }

    private function format(string $key): string
    {
        return match (true) {
            in_array($key, self::MONEY_KEYS, true)   => 'currency',
            in_array($key, self::PERCENT_KEYS, true) => 'percent',
            in_array($key, self::DECIMAL_KEYS, true) => 'decimal',
            default                                  => 'integer',
        };
    }

    // ═══════════════════════════════════════════════════════════════════════
    // ── Formateo ──────────────────────────────────────────────────────────
    // ═══════════════════════════════════════════════════════════════════════

    private function money(float|int|string|null $value): string
    {
        return '$'.number_format((float) $value, 0, '.', ',');
    }

    private function percent(float|int|string|null $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.').'%';
    }

    private function agentsLabel(array $conditions): string
    {
        $min = (int) ($conditions['min_agents'] ?? 0);
        $max = $conditions['max_agents'] ?? null;

        if ($max === null || $max === '' || (int) $max === $min) {
            return $min.' '.($min === 1 ? 'agente' : 'agentes');
        }

        return $min.'–'.$max.' agentes';
    }

    /**
     * Rango legible: «1.00 – 1.49», «5+», «$16,000 – $20,999».
     */
    private function range(float|int|string $min, float|int|string|null $max, int $decimals, bool $asMoney = false): string
    {
        $format = fn (float|int|string $value) => $asMoney
            ? $this->money($value)
            : number_format((float) $value, $decimals, '.', '');

        if ($max === null || $max === '') {
            return $format($min).'+';
        }

        if ((float) $min === (float) $max) {
            return $format($min);
        }

        return $format($min).' – '.$format($max);
    }
}
