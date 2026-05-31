<?php

namespace Tests\Feature\BonusCalculators;

use App\Models\Agent;
use App\Models\Policy;
use App\Models\Scheme;
use App\Models\SchemeVersion;
use App\Models\SchemeTier;
use App\Services\BonusCalculators\ActivityRatioCalculator;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Suite de pruebas para el Bono Activity Ratio Trimestral (Agente).
 *
 * REGLAS DE NEGOCIO:
 *   1. El Agente DEBE haber ganado el bono prerequisite.
 *   2. PNA de cada póliza determina su peso:
 *      $16K–$21K → 0.5 | $21K–$60K → 1.0 | $60K–$120K → 1.5 | ≥ $120K → 2.0
 *   3. Promedio mensual = Σ(pesos) / 3.
 *   4. Tier por promedio en [min_policies, max_policies].
 *   5. Monto = PCA × agent_percentage.
 *
 * Tiers:
 *   ┌────────────────────┬──────────┬──────────┬─────┐
 *   │ Clasificación      │ Mín Prom │ Máx Prom │  %  │
 *   ├────────────────────┼──────────┼──────────┼─────┤
 *   │ Activo 12          │   1.00   │   1.49   │  2% │
 *   │ Activo 18          │   1.50   │   1.99   │  3% │
 *   │ Productivo 24      │   2.00   │   2.99   │  5% │
 *   │ Productivo 36      │   3.00   │   ∞      │  7% │
 *   └────────────────────┴──────────┴──────────┴─────┘
 */
class ActivityRatioCalculatorTest extends TestCase
{
    use RefreshDatabase;

    protected ActivityRatioCalculator $calculator;

    protected int $prerequisiteSchemeId;

    // ─── Constantes PNA ─────────────────────────────────────────────────

    protected const PNA_EQUIVALENCES = [
        ['min_pna' =>  16000, 'max_pna' =>  20999, 'policies' => 0.5],
        ['min_pna' =>  21000, 'max_pna' =>  59999, 'policies' => 1.0],
        ['min_pna' =>  60000, 'max_pna' => 119999, 'policies' => 1.5],
        ['min_pna' => 120000, 'max_pna' =>   null, 'policies' => 2.0],
    ];

    // ────────────────────────────────────────────────────────────────────

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-06-15'));

        // Bono prerequisite "Producción 1er Año Vida Trimestral" (activo)
        $prerequisite = Scheme::create([
            'name'      => 'agent_first_year_production',
            'type'      => 'bonus',
            'target'    => 'agent',
            'is_active' => true,
        ]);

        SchemeVersion::create([
            'scheme_id'    => $prerequisite->id,
            'version_name' => 'v1.0',
            'starts_at'    => '2026-01-01',
            'ends_at'      => '2026-12-31',
        ]);

        $this->prerequisiteSchemeId = $prerequisite->id;

        $this->calculator = new ActivityRatioCalculator();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow(null);
        parent::tearDown();
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 🟢 TESTS DE ÉXITO
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * 🟢 5 pólizas de $150,000 PNA → 2.0 c/u = 10 → promedio 3.33 → Productivo 36 → 7%.
     *
     * PCA = 5 × $150K = $750K. Monto = $750K × 7% = $52,500.
     */
    public function test_agent_achieves_productivo_36_with_high_pna_policies(): void
    {
        $scheme = $this->createActivityRatioScheme();
        $agent  = Agent::factory()->create();
        $period = $this->defaultPeriod();

        // 5 pólizas de $150K PNA cada una (≥ $120K → 2.0)
        for ($i = 0; $i < 5; $i++) {
            Policy::factory()->vida()->withPremium(150_000)
                ->for($agent)
                ->create(['issue_date' => '2026-03-01', 'status' => Policy::STATUS_ACTIVA]);
        }

        $result = $this->calculator->calculate($agent, $scheme, $period['start'], $period['end']);

        $this->assertTrue($result['is_achieved'], 'Debió alcanzarse. Razón: ' . ($result['details']['reason'] ?? '—'));

        // Promedio: 10 / 3 = 3.33
        $this->assertEqualsWithDelta(3.33, $result['progress']['current_value'], 0.01);
        $this->assertEquals(7.0, (float) $result['tier_data']['agent_percentage']);
        $this->assertEquals(52_500.0, $result['amount']); // $750K × 7%
        $this->assertEquals(750_000.0, $result['details']['pca']);
    }

    /**
     * 🟢 6 pólizas de $18,000 PNA → 0.5 c/u = 3 → promedio 1.0 → Activo 12 → 2%.
     *
     * PCA = 6 × $18K = $108K. Monto = $108K × 2% = $2,160.
     */
    public function test_agent_achieves_activo_12_with_fractional_policies(): void
    {
        $scheme = $this->createActivityRatioScheme();
        $agent  = Agent::factory()->create();
        $period = $this->defaultPeriod();

        // 6 pólizas de $18K PNA (rango $16K–$21K → 0.5)
        for ($i = 0; $i < 6; $i++) {
            Policy::factory()->vida()->withPremium(18_000)
                ->for($agent)
                ->create(['issue_date' => '2026-03-01', 'status' => Policy::STATUS_ACTIVA]);
        }

        $result = $this->calculator->calculate($agent, $scheme, $period['start'], $period['end']);

        $this->assertTrue($result['is_achieved']);

        // Promedio: 3 / 3 = 1.0
        $this->assertEquals(1.0, $result['progress']['current_value']);
        $this->assertEquals(2.0, (float) $result['tier_data']['agent_percentage']);
        $this->assertEquals(2_160.0, $result['amount']); // $108K × 2%
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 🔴 TESTS DE FALLO
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * 🔴 Agente con promedio 4.0 pero prerequisite INACTIVO → falla.
     */
    public function test_agent_fails_completely_if_dependency_bonus_not_achieved(): void
    {
        // Desactivar prerequisite
        Scheme::where('id', $this->prerequisiteSchemeId)->update(['is_active' => false]);

        $scheme = $this->createActivityRatioScheme();
        $agent  = Agent::factory()->create();
        $period = $this->defaultPeriod();

        // Pólizas que darían promedio 4.0
        for ($i = 0; $i < 6; $i++) {
            Policy::factory()->vida()->withPremium(150_000) // 2.0 c/u
                ->for($agent)
                ->create(['issue_date' => '2026-03-01', 'status' => Policy::STATUS_ACTIVA]);
        }

        $result = $this->calculator->calculate($agent, $scheme, $period['start'], $period['end']);

        $this->assertFalse($result['is_achieved'], 'Debió fallar: prerequisite inactivo.');
        $this->assertStringContainsString('prerequisite', strtolower($result['details']['reason'] ?? ''));
    }

    /**
     * 🔴 1 póliza de $50K PNA → peso 1.0 → promedio 0.33 < 1.0 → falla.
     */
    public function test_agent_fails_if_average_is_below_minimum(): void
    {
        $scheme = $this->createActivityRatioScheme();
        $agent  = Agent::factory()->create();
        $period = $this->defaultPeriod();

        // 1 póliza de $50K (rango $21K–$60K → 1.0)
        Policy::factory()->vida()->withPremium(50_000)
            ->for($agent)
            ->create(['issue_date' => '2026-03-01', 'status' => Policy::STATUS_ACTIVA]);

        $result = $this->calculator->calculate($agent, $scheme, $period['start'], $period['end']);

        $this->assertFalse($result['is_achieved'], 'Debió fallar: promedio 0.33 < 1.0.');
        $this->assertEquals(0.33, $result['progress']['current_value']);
        $this->assertStringContainsString('0.33', $result['details']['reason'] ?? '');
    }

    /**
     * 🔴 10 pólizas de $10,000 PNA → todas debajo del mínimo ($16K) → peso 0 → falla.
     */
    public function test_policies_below_minimum_pna_are_ignored(): void
    {
        $scheme = $this->createActivityRatioScheme();
        $agent  = Agent::factory()->create();
        $period = $this->defaultPeriod();

        // 10 pólizas de $10K (por debajo de $16K → no suman)
        for ($i = 0; $i < 10; $i++) {
            Policy::factory()->vida()->withPremium(10_000)
                ->for($agent)
                ->create(['issue_date' => '2026-03-01', 'status' => Policy::STATUS_ACTIVA]);
        }

        $result = $this->calculator->calculate($agent, $scheme, $period['start'], $period['end']);

        $this->assertFalse($result['is_achieved'], 'Debió fallar: promedio 0.');
        $this->assertEquals(0.0, $result['progress']['current_value']);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 🟡 TEST ADICIONAL
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * 🔴 Un Promoter no puede recibir este bono.
     */
    public function test_promoter_cannot_receive_activity_ratio_bonus(): void
    {
        $scheme   = $this->createActivityRatioScheme();
        $promoter = \App\Models\Promoter::factory()->create();
        $period   = $this->defaultPeriod();

        $result = $this->calculator->calculate($promoter, $scheme, $period['start'], $period['end']);

        $this->assertFalse($result['is_achieved']);
        $this->assertStringContainsString('Agente', $result['details']['reason'] ?? '');
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 🔧 HELPERS
    // ═══════════════════════════════════════════════════════════════════════

    protected function createActivityRatioScheme(): Scheme
    {
        $scheme = Scheme::create([
            'name'                 => 'activity_ratio',
            'type'                 => 'bonus',
            'target'               => 'agent',
            'is_active'            => true,
            'metric_base'          => 'PCA',
            'frequency'            => 'trimestral',
            'dependency_scheme_id' => (string) $this->prerequisiteSchemeId,
            'pna_equivalences'     => self::PNA_EQUIVALENCES,
        ]);

        $version = SchemeVersion::create([
            'scheme_id'    => $scheme->id,
            'version_name' => 'v1.0 — Activity Ratio Trimestral',
            'starts_at'    => '2026-01-01',
            'ends_at'      => '2026-12-31',
        ]);

        $tiers = [
            // [classification, min_policies, max_policies, agent_pct]
            ['Activo 12',       1.0,  1.49,  2.0],
            ['Activo 18',       1.5,  1.99,  3.0],
            ['Productivo 24',   2.0,  2.99,  5.0],
            ['Productivo 36',   3.0,  null,  7.0],
        ];

        foreach ($tiers as [$class, $min, $max, $pct]) {
            SchemeTier::create([
                'scheme_version_id'   => $version->id,
                'conditions'          => [
                    'classification' => $class,
                    'min_policies'   => $min,
                    'max_policies'   => $max,
                ],
                'agent_percentage'    => $pct,
                'promoter_percentage' => 0,
                'fixed_amount'        => null,
            ]);
        }

        return $scheme->fresh(['versions.tiers']);
    }

    /**
     * @return array{start: Carbon, end: Carbon}
     */
    protected function defaultPeriod(): array
    {
        return [
            'start' => Carbon::parse('2026-01-01'),
            'end'   => Carbon::parse('2026-12-31'),
        ];
    }
}
