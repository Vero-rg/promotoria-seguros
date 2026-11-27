<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\BonusAdvance;
use App\Models\Promoter;
use App\Models\Scheme;
use App\Services\BonusCalculators\BonusCalculatorFactory;
use Carbon\Carbon;

/**
 * Calcula y guarda los anticipos mensuales de los bonos trimestrales que los
 * permiten (Producción de 1.er Año de Vida para agentes y promotores).
 *
 * Lógica del anticipo:
 *   - Cada mes se evalúa el bono sobre el acumulado del trimestre hasta ese mes.
 *   - El anticipo del mes es lo que falta para llegar al acumulado calculado:
 *       anticipo(mes) = acumulado(mes) − anticipos entregados anteriormente
 *   - Así, si en el mes 2 el porcentaje sube, el anticipo incluye la diferencia
 *     retroactiva sobre el acumulado de los meses anteriores.
 *   - El monto calculado se puede sustituir manualmente; en ese caso la
 *     diferencia de los meses siguientes se recalcula con el monto guardado.
 *   - Cuando el porcentaje sube respecto al mes anterior, el anticipo se
 *     desglosa en: ventas del mes al porcentaje vigente + ajuste retroactivo
 *     de los meses previos.
 */
class BonusAdvanceService
{
    /** Bonos que permiten anticipos mensuales. */
    public const SUPPORTED_TEMPLATES = [
        'agent_first_year_production',
        'first_year_production',
    ];

    public function __construct(
        protected BonusCalculatorFactory $factory = new BonusCalculatorFactory(),
    ) {
    }

    /**
     * Indica si el esquema maneja anticipos mensuales.
     */
    public function supports(Scheme $scheme): bool
    {
        return (bool) $scheme->requires_anticipos
            && in_array((string) $scheme->template_key, self::SUPPORTED_TEMPLATES, true);
    }

    /**
     * Anticipos del trimestre (o de los meses transcurridos) para un usuario.
     *
     * @return array{months: array<int, array>, total: float, calculated_total: float, has_overrides: bool}
     */
    public function build(Scheme $scheme, Agent|Promoter $user, Carbon $rangeEnd): array
    {
        if (! $this->supports($scheme)) {
            return [];
        }

        $scheme->loadMissing(['versions.tiers']);
        $calculator = $this->factory->make($scheme->template_key);

        $userType = $user instanceof Agent ? 'agent' : 'promoter';
        $quarterStart = $rangeEnd->copy()->startOfQuarter();
        $months = $this->months($quarterStart, $rangeEnd);

        $overrides = BonusAdvance::query()
            ->where('scheme_id', $scheme->id)
            ->where('user_type', $userType)
            ->where('user_id', $user->id)
            ->whereIn('period_month', array_column($months, 'key'))
            ->pluck('amount', 'period_month');

        $config = $scheme->anticipos_config ?? [];
        $metricLabel = $this->metricLabel($scheme);
        $paidSoFar = 0.0;
        $calculatedTotal = 0.0;
        $previousMetric = 0.0;
        $previousPercentage = null;
        $rows = [];

        foreach ($months as $index => $month) {
            $evaluation = $calculator->calculate($user, $scheme, $quarterStart, $month['end']);
            $cumulative = max(0.0, round((float) ($evaluation['amount'] ?? 0), 2));

            $minimum = (float) ($config['month_'.($index + 1).'_min'] ?? 0);
            $metric = $this->cumulativeMetric($evaluation);
            $monthMetric = round($metric - $previousMetric, 2);
            $belowMinimum = $minimum > 0 && $metric < $minimum;

            $percentage = $this->reachedPercentage($scheme, $evaluation);
            $calculated = $belowMinimum ? 0.0 : max(0.0, round($cumulative - $paidSoFar, 2));
            $override = $overrides[$month['key']] ?? null;
            $amount = $override !== null ? round((float) $override, 2) : $calculated;

            $breakdown = $this->buildBreakdown($calculated, $monthMetric, $percentage, $previousPercentage);

            $rows[] = [
                'month'               => $month['key'],
                'label'               => $month['label'],
                'cumulative_amount'   => $cumulative,
                'previous_total'      => round($paidSoFar, 2),
                'calculated'          => $calculated,
                'amount'              => $amount,
                'is_override'         => $override !== null,
                'minimum'             => $minimum,
                'below_minimum'       => $belowMinimum,
                'unlocked'            => (bool) ($evaluation['is_achieved'] ?? false),
                'percentage'          => $percentage,
                // Desglose del anticipo: mes al porcentaje vigente + retroactivo.
                'previous_percentage' => $previousPercentage,
                'percentage_delta'    => $breakdown['percentage_delta'],
                'month_amount'        => $breakdown['month_amount'],
                'retroactive_amount'  => $breakdown['retroactive_amount'],
                // Ventas del mes (PCA del agente o PP del equipo) para comparar el %.
                'metric_label'        => $metricLabel,
                'metric'              => round($metric, 2),
                'month_metric'        => $monthMetric,
            ];

            $previousMetric = $metric;
            $previousPercentage = $percentage;
            $paidSoFar = round($paidSoFar + $amount, 2);
            $calculatedTotal = round($calculatedTotal + $calculated, 2);
        }

        return [
            'months'           => $rows,
            'total'            => round(array_sum(array_column($rows, 'amount')), 2),
            'calculated_total' => $calculatedTotal,
            'has_overrides'    => $overrides->isNotEmpty(),
            'metric_label'     => $metricLabel,
        ];
    }

    /**
     * Desglosa el anticipo del mes entre las ventas del mes al porcentaje
     * vigente y el ajuste retroactivo de los meses anteriores.
     *
     * El retroactivo es el resto del anticipo calculado: incluye la diferencia
     * de porcentaje aplicada al acumulado previo y los montos que se hayan
     * arrastrado (por ejemplo, un mínimo mensual no alcanzado).
     *
     * @return array{month_amount: float|null, retroactive_amount: float|null, percentage_delta: float|null}
     */
    private function buildBreakdown(float $calculated, float $monthMetric, ?float $percentage, ?float $previousPercentage): array
    {
        $empty = ['month_amount' => null, 'retroactive_amount' => null, 'percentage_delta' => null];

        if ($calculated <= 0 || $percentage === null || $monthMetric <= 0) {
            return $empty;
        }

        $monthAmount = round($monthMetric * ($percentage / 100), 2);
        $retroactiveAmount = round($calculated - $monthAmount, 2);

        if ($retroactiveAmount <= 0) {
            return $empty;
        }

        return [
            'month_amount'       => $monthAmount,
            'retroactive_amount' => $retroactiveAmount,
            'percentage_delta'   => $previousPercentage !== null
                ? round($percentage - $previousPercentage, 2)
                : null,
        ];
    }

    /**
     * Guarda (o actualiza) el monto del anticipo de un mes.
     */
    public function save(Scheme $scheme, string $userType, int $userId, string $periodMonth, float $amount, ?int $createdBy = null): BonusAdvance
    {
        return BonusAdvance::updateOrCreate(
            [
                'scheme_id'    => $scheme->id,
                'user_type'    => $userType,
                'user_id'      => $userId,
                'period_month' => $periodMonth,
            ],
            [
                'amount'     => round($amount, 2),
                'created_by' => $createdBy,
            ],
        );
    }

    /**
     * Meses calendario desde el inicio del trimestre hasta el fin del rango.
     *
     * @return array<int, array{key: string, label: string, end: Carbon}>
     */
    private function months(Carbon $quarterStart, Carbon $rangeEnd): array
    {
        $months = [];
        $cursor = $quarterStart->copy()->startOfMonth();
        $last = $rangeEnd->copy()->startOfMonth();

        while ($cursor->lessThanOrEqualTo($last)) {
            $months[] = [
                'key'   => $cursor->format('Y-m'),
                'label' => ucfirst($cursor->copy()->locale('es')->translatedFormat('F Y')),
                'end'   => $cursor->copy()->endOfMonth(),
            ];
            $cursor->addMonthNoOverflow()->startOfMonth();
        }

        return $months;
    }

    /**
     * Etiqueta de la métrica vendida del mes (PCA del agente o PP del equipo).
     */
    private function metricLabel(Scheme $scheme): string
    {
        return $scheme->target === 'promoter' ? 'PP' : 'PCA';
    }

    /**
     * Métrica acumulada evaluada (PP del promotor o PCA del agente).
     */
    private function cumulativeMetric(array $evaluation): float
    {
        $metrics = $evaluation['details']['metrics'] ?? [];

        return (float) ($metrics['min_pp'] ?? $metrics['min_pca'] ?? 0);
    }

    /**
     * Porcentaje alcanzado en el mes (efectivo/automático en agentes).
     */
    private function reachedPercentage(Scheme $scheme, array $evaluation): ?float
    {
        $tier = $evaluation['tier_data'] ?? null;

        if ($tier === null) {
            return null;
        }

        if ($scheme->target === 'promoter') {
            return (float) ($tier['promoter_percentage'] ?? 0);
        }

        $details = $evaluation['details'] ?? [];

        return ($details['payment_strategy'] ?? 'split') === 'automatic'
            ? (float) ($details['automatic_percentage'] ?? 0)
            : (float) ($details['cash_percentage'] ?? $tier['agent_percentage'] ?? 0);
    }
}
