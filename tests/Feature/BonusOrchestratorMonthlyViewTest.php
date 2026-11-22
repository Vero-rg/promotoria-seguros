<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\Policy;
use App\Models\Promoter;
use App\Models\Scheme;
use App\Models\SchemeVersion;
use App\Services\BonusCalculators\BonusCalculatorFactory;
use App\Services\BonusCalculators\BonusCalculatorInterface;
use App\Services\BonusOrchestratorService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Suite de pruebas del desglose mensual del panel de bonos.
 *
 * REGLAS DE NEGOCIO CUBIERTAS:
 *   1. Con $monthlyView, los bonos mensuales se evalúan mes a mes y el monto
 *      del periodo es la suma de los meses (con desglose por mes).
 *   2. Con $monthlyView, los bonos trimestrales se limitan al mes cuando el
 *      rango visual abarca un solo mes.
 *   3. Sin $monthlyView el comportamiento previo se conserva intacto
 *      (bonos trimestrales sobre el trimestre completo).
 */
class BonusOrchestratorMonthlyViewTest extends TestCase
{
    use RefreshDatabase;

    protected Agent $agent;
    protected BonusOrchestratorService $orchestrator;

    protected function setUp(): void
    {
        parent::setUp();

        $factory = new BonusCalculatorFactory();
        $factory->register('test_monthly', FixedEvaluationAmountCalculator::class);
        $factory->register('test_quarterly', FixedEvaluationAmountCalculator::class);

        $this->orchestrator = new BonusOrchestratorService($factory);

        $this->agent = Agent::factory()->create();

        // Una póliza en enero y otra en marzo (febrero queda sin producción)
        Policy::factory()->for($this->agent)->create(['issue_date' => '2026-01-10']);
        Policy::factory()->for($this->agent)->create(['issue_date' => '2026-03-10']);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 🟢 BONOS MENSUALES
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * 🟢 Trimestre: el bono mensual suma los 3 meses y expone el desglose.
     */
    public function test_monthly_bonus_sums_each_month_in_quarter_view(): void
    {
        $this->createScheme('test_monthly', 'mensual');

        $result = $this->orchestrator->calculateAll(
            user: $this->agent,
            periodStart: Carbon::parse('2026-01-01'),
            periodEnd: Carbon::parse('2026-03-31'),
            visualRangeStart: Carbon::parse('2026-01-01'),
            visualRangeEnd: Carbon::parse('2026-03-31'),
            monthlyView: true,
        );

        $bonus = $result['results'][0];

        // 2 meses con producción × $1,000 por evaluación
        $this->assertSame(2000.0, (float) $bonus['amount']);
        $this->assertTrue($bonus['is_achieved']);
        $this->assertCount(3, $bonus['monthly_breakdown']);

        $amounts = array_column($bonus['monthly_breakdown'], 'amount');
        $this->assertSame([1000.0, 0.0, 1000.0], array_map('floatval', $amounts));

        $labels = array_column($bonus['monthly_breakdown'], 'label');
        $this->assertSame(['Enero 2026', 'Febrero 2026', 'Marzo 2026'], $labels);

        // El periodo efectivo es el trimestre completo
        $this->assertSame('2026-01-01', $bonus['effective_period_start']);
        $this->assertSame('2026-03-31', $bonus['effective_period_end']);
    }

    /**
     * 🟢 Un solo mes: el bono mensual se evalúa únicamente en ese mes.
     */
    public function test_monthly_bonus_evaluates_only_the_selected_month(): void
    {
        $this->createScheme('test_monthly', 'mensual');

        $result = $this->orchestrator->calculateAll(
            user: $this->agent,
            periodStart: Carbon::parse('2026-02-01'),
            periodEnd: Carbon::parse('2026-02-28'),
            visualRangeStart: Carbon::parse('2026-02-01'),
            visualRangeEnd: Carbon::parse('2026-02-28'),
            monthlyView: true,
        );

        $bonus = $result['results'][0];

        $this->assertSame(0.0, (float) $bonus['amount']);
        $this->assertCount(0, $bonus['monthly_breakdown']);
        $this->assertSame('2026-02-01', $bonus['effective_period_start']);
        $this->assertSame('2026-02-28', $bonus['effective_period_end']);
    }

    /**
     * 🟡 Sin desglose mensual se conserva el comportamiento previo
     *    (una sola evaluación del mes que contiene el fin del periodo).
     */
    public function test_monthly_bonus_keeps_legacy_behaviour_without_monthly_view(): void
    {
        $this->createScheme('test_monthly', 'mensual');

        $result = $this->orchestrator->calculateAll(
            user: $this->agent,
            periodStart: Carbon::parse('2026-01-01'),
            periodEnd: Carbon::parse('2026-03-31'),
            visualRangeStart: Carbon::parse('2026-01-01'),
            visualRangeEnd: Carbon::parse('2026-03-31'),
        );

        $bonus = $result['results'][0];

        $this->assertSame(1000.0, (float) $bonus['amount']);
        $this->assertCount(0, $bonus['monthly_breakdown']);
        $this->assertSame('2026-03-01', $bonus['effective_period_start']);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 🟢 BONOS TRIMESTRALES
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * 🟢 Un mes seleccionado: el bono trimestral se calcula solo con ese mes.
     */
    public function test_quarterly_bonus_uses_single_month_when_range_is_one_month(): void
    {
        $this->createScheme('test_quarterly', 'trimestral');

        $result = $this->orchestrator->calculateAll(
            user: $this->agent,
            periodStart: Carbon::parse('2026-01-01'),
            periodEnd: Carbon::parse('2026-01-31'),
            visualRangeStart: Carbon::parse('2026-01-01'),
            visualRangeEnd: Carbon::parse('2026-01-31'),
            monthlyView: true,
        );

        $bonus = $result['results'][0];

        $this->assertSame(1000.0, (float) $bonus['amount']);
        $this->assertSame('2026-01-01', $bonus['effective_period_start']);
        $this->assertSame('2026-01-31', $bonus['effective_period_end']);
        $this->assertCount(0, $bonus['monthly_breakdown']);
    }

    /**
     * 🟢 Trimestre seleccionado: el bono trimestral conserva el trimestre completo.
     */
    public function test_quarterly_bonus_keeps_full_quarter_in_quarter_view(): void
    {
        $this->createScheme('test_quarterly', 'trimestral');

        $result = $this->orchestrator->calculateAll(
            user: $this->agent,
            periodStart: Carbon::parse('2026-01-01'),
            periodEnd: Carbon::parse('2026-03-31'),
            visualRangeStart: Carbon::parse('2026-01-01'),
            visualRangeEnd: Carbon::parse('2026-03-31'),
            monthlyView: true,
        );

        $bonus = $result['results'][0];

        $this->assertSame(1000.0, (float) $bonus['amount']);
        $this->assertSame('2026-01-01', $bonus['effective_period_start']);
        $this->assertSame('2026-03-31', $bonus['effective_period_end']);
        $this->assertCount(0, $bonus['monthly_breakdown']);
    }

    /**
     * 🟡 Sin desglose mensual el bono trimestral expande al trimestre
     *    aunque el rango visual sea de un solo mes (comportamiento previo).
     */
    public function test_quarterly_bonus_expands_to_quarter_without_monthly_view(): void
    {
        $this->createScheme('test_quarterly', 'trimestral');

        $result = $this->orchestrator->calculateAll(
            user: $this->agent,
            periodStart: Carbon::parse('2026-01-01'),
            periodEnd: Carbon::parse('2026-01-31'),
            visualRangeStart: Carbon::parse('2026-01-01'),
            visualRangeEnd: Carbon::parse('2026-01-31'),
        );

        $bonus = $result['results'][0];

        $this->assertSame('2026-03-31', $bonus['effective_period_end']);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 🟢 FORMATO FRONTEND
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * 🟢 toFrontendFormat expone el desglose mensual y la bandera de total.
     */
    public function test_frontend_format_exposes_monthly_breakdown(): void
    {
        $this->createScheme('test_monthly', 'mensual');

        $result = $this->orchestrator->calculateAll(
            user: $this->agent,
            periodStart: Carbon::parse('2026-01-01'),
            periodEnd: Carbon::parse('2026-03-31'),
            visualRangeStart: Carbon::parse('2026-01-01'),
            visualRangeEnd: Carbon::parse('2026-03-31'),
            monthlyView: true,
        );

        $bonus = $this->orchestrator->toFrontendFormat($result)[0];

        $this->assertTrue($bonus['monthly_total']);
        $this->assertCount(3, $bonus['monthly_breakdown']);
        $this->assertTrue($bonus['monthly_breakdown'][0]['unlocked']);
        $this->assertFalse($bonus['monthly_breakdown'][1]['unlocked']);
        $this->assertTrue($bonus['conditions'][0]['_isMonthlyAmount']);
        $this->assertEquals(2000.0, $bonus['amount']);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 🔧 HELPERS
    // ═══════════════════════════════════════════════════════════════════════

    protected function createScheme(string $templateKey, string $frequency): Scheme
    {
        $scheme = Scheme::create([
            'name'         => $templateKey,
            'type'         => 'bonus',
            'template_key' => $templateKey,
            'target'       => 'agent',
            'is_active'    => true,
            'metric_base'  => 'PCA',
            'frequency'    => $frequency,
        ]);

        SchemeVersion::create([
            'scheme_id'    => $scheme->id,
            'version_name' => 'v1.0',
            'starts_at'    => '2026-01-01',
            'ends_at'      => '2026-12-31',
        ]);

        return $scheme->fresh(['versions.tiers']);
    }
}

/**
 * Calculadora de prueba: otorga un monto fijo por evaluación cuando hay
 * producción en el periodo. Permite distinguir una evaluación (mes o
 * trimestre) de la suma de varias evaluaciones mensuales.
 */
class FixedEvaluationAmountCalculator implements BonusCalculatorInterface
{
    public const AMOUNT_PER_EVALUATION = 1000.0;

    public function calculate(
        Agent|Promoter $user,
        Scheme $scheme,
        Carbon $periodStart,
        Carbon $periodEnd
    ): array {
        $policiesCount = $user->policies()
            ->whereBetween('issue_date', [$periodStart->toDateString(), $periodEnd->toDateString()])
            ->count();

        $achieved = $policiesCount > 0;

        return [
            'is_achieved' => $achieved,
            'amount'      => $achieved ? self::AMOUNT_PER_EVALUATION : 0.0,
            'tier_index'  => null,
            'tier_data'   => null,
            'progress'    => [
                'metric_label'   => 'Pólizas del periodo',
                'current_value'  => $policiesCount,
                'required_value' => 1.0,
            ],
            'progress_breakdown' => [
                [
                    'label'   => 'Pólizas del periodo',
                    'current' => $policiesCount,
                    'target'  => 1,
                    'met'     => $achieved,
                ],
            ],
            'details' => [],
        ];
    }
}
