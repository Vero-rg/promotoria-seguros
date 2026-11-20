<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\Policy;
use App\Models\Promoter;
use App\Models\Scheme;
use App\Models\SchemeTier;
use App\Models\SchemeVersion;
use App\Services\BonusCalculators\ActivityRatioCalculator;
use App\Services\BonusCalculators\AdditionalAgentsCalculator;
use App\Services\BonusCompensationMatrix;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pruebas del resumen de consulta de los bonos: matriz de compensación,
 * tramo alcanzado y brecha al siguiente nivel.
 */
class BonusCompensationMatrixTest extends TestCase
{
    use RefreshDatabase;

    protected BonusCompensationMatrix $matrix;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-06-15'));
        $this->matrix = new BonusCompensationMatrix();

        $this->createAgentFirstYearScheme();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow(null);
        parent::tearDown();
    }

    /**
     * 🟢 La matriz del Activity Ratio incluye equivalencias, tramos ordenados
     *    y marca el tramo alcanzado.
     */
    public function test_activity_ratio_matrix_lists_equivalences_and_achieved_tier(): void
    {
        $scheme = $this->createActivityRatioScheme();
        $agent  = Agent::factory()->create();

        // 3 pólizas de $50K → 1.0 equivalente c/u = 3.0 → promedio 1.00 → Activo 12
        Policy::factory()->vida()->withPremium(50_000)->count(3)
            ->for($agent)
            ->create(['issue_date' => '2026-03-01', 'status' => Policy::STATUS_PAGADA]);

        $result = (new ActivityRatioCalculator())->calculate(
            $agent,
            $scheme,
            Carbon::parse('2026-01-01'),
            Carbon::parse('2026-03-31'),
        );

        $matrix = $this->matrix->build($scheme, $this->withPeriod($result));

        $this->assertCount(4, $matrix['equivalences']);
        $this->assertSame('$16,000 – $20,999', $matrix['equivalences'][0]['label']);
        $this->assertSame('0.5', $matrix['equivalences'][0]['value']);

        $this->assertCount(4, $matrix['tiers']);
        $this->assertSame('Activo 12', $matrix['tiers'][0]['label']);
        $this->assertTrue($matrix['tiers'][0]['achieved']);
        $this->assertFalse($matrix['tiers'][1]['achieved']);
        $this->assertSame(2.0, $matrix['tiers'][0]['percentage']);
        $this->assertNotEmpty($matrix['rules']);
    }

    /**
     * 🟢 La brecha al siguiente nivel indica cuánto falta del promedio mensual.
     */
    public function test_activity_ratio_next_level_reports_missing_average(): void
    {
        $scheme = $this->createActivityRatioScheme();
        $agent  = Agent::factory()->create();

        Policy::factory()->vida()->withPremium(50_000)->count(3)
            ->for($agent)
            ->create(['issue_date' => '2026-03-01', 'status' => Policy::STATUS_PAGADA]);

        $result = (new ActivityRatioCalculator())->calculate(
            $agent,
            $scheme,
            Carbon::parse('2026-01-01'),
            Carbon::parse('2026-03-31'),
        );

        $next = $this->matrix->nextLevel($scheme, $this->withPeriod($result));

        $this->assertNotNull($next);
        $this->assertSame('Activo 18', $next['label']);
        $this->assertSame(3.0, $next['percentage']);
        $this->assertSame('min_policies', $next['gaps'][0]['key']);
        $this->assertEqualsWithDelta(0.5, $next['gaps'][0]['gap'], 0.01);
        $this->assertStringContainsString('Activo 18 (3%)', $next['message']);
    }

    /**
     * 🟢 El bono adicional expone su matriz por agentes y la brecha al
     *    siguiente tramo, además del bono prerequisite.
     */
    public function test_additional_agents_matrix_and_next_level(): void
    {
        $this->createPromoterFirstYearScheme();
        $activityRatioScheme = $this->createActivityRatioScheme();
        $scheme = $this->createAdditionalAgentsScheme();

        $promoter = Promoter::factory()->create();
        $agent    = Agent::factory()->for($promoter, 'promoter')->create(['is_active' => true]);

        Policy::factory()->vida()->withPremium(50_000)->count(3)
            ->for($agent)
            ->create(['issue_date' => '2026-03-01', 'status' => Policy::STATUS_PAGADA]);

        $calculator = new AdditionalAgentsCalculator();
        $this->assertNotNull($activityRatioScheme);

        $result = $calculator->calculate(
            $promoter,
            $scheme,
            Carbon::parse('2026-01-01'),
            Carbon::parse('2026-03-31'),
        );

        $result = $this->withPeriod($result);
        $result['dependency_scheme_name'] = 'Producción de 1er Año Trimestral';

        $matrix = $this->matrix->build($scheme, $result);
        $next = $this->matrix->nextLevel($scheme, $result);

        $this->assertSame('Producción de 1er Año Trimestral', $matrix['dependency']);
        $this->assertSame('1 agente', $matrix['tiers'][0]['label']);
        $this->assertTrue($matrix['tiers'][0]['achieved']);

        $this->assertNotNull($next);
        $this->assertSame('min_agents', $next['gaps'][0]['key']);
        $this->assertSame(1.0, (float) $next['gaps'][0]['gap']);
        $this->assertStringContainsString('agente con compensación', $next['message']);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 🔧 HELPERS
    // ═══════════════════════════════════════════════════════════════════════

    private function withPeriod(array $result): array
    {
        $result['effective_period_start'] = '2026-01-01';
        $result['effective_period_end']   = '2026-03-31';

        return $result;
    }

    private function createAgentFirstYearScheme(): Scheme
    {
        $scheme = Scheme::create([
            'name'             => 'agent_first_year_production',
            'template_key'     => 'agent_first_year_production',
            'type'             => 'bonus',
            'target'           => 'agent',
            'is_active'        => true,
            'frequency'        => 'trimestral',
            'requires_product' => [],
        ]);

        $version = SchemeVersion::create([
            'scheme_id'    => $scheme->id,
            'version_name' => 'v1.0',
            'starts_at'    => '2026-01-01',
            'ends_at'      => '2026-12-31',
        ]);

        SchemeTier::create([
            'scheme_version_id'   => $version->id,
            'conditions'          => ['min_pca' => 1],
            'agent_percentage'    => 0,
            'promoter_percentage' => 0,
        ]);

        return $scheme->fresh(['versions.tiers']);
    }

    private function createPromoterFirstYearScheme(): Scheme
    {
        $scheme = Scheme::create([
            'name'             => 'first_year_production',
            'template_key'     => 'first_year_production',
            'type'             => 'bonus',
            'target'           => 'promoter',
            'is_active'        => true,
            'frequency'        => 'trimestral',
            'requires_product' => [],
        ]);

        $version = SchemeVersion::create([
            'scheme_id'    => $scheme->id,
            'version_name' => 'v1.0',
            'starts_at'    => '2026-01-01',
            'ends_at'      => '2026-12-31',
        ]);

        SchemeTier::create([
            'scheme_version_id'   => $version->id,
            'conditions'          => ['min_pp' => 1, 'min_irp' => 0],
            'agent_percentage'    => 0,
            'promoter_percentage' => 0,
        ]);

        return $scheme->fresh(['versions.tiers']);
    }

    private function createActivityRatioScheme(): Scheme
    {
        $scheme = Scheme::create([
            'name'                 => 'activity_ratio',
            'template_key'         => 'activity_ratio',
            'type'                 => 'bonus',
            'target'               => 'agent',
            'is_active'            => true,
            'metric_base'          => 'PCA',
            'frequency'            => 'trimestral',
            'dependency_scheme_id' => 'agent_first_year_production',
            'requires_product'     => [['product_type' => 'Vida', 'min_count' => 1]],
            'pna_equivalences'     => [
                ['min_pna' => 16000, 'max_pna' => 20999, 'policies' => 0.5],
                ['min_pna' => 21000, 'max_pna' => 59000, 'policies' => 1.0],
                ['min_pna' => 60000, 'max_pna' => 119999, 'policies' => 1.5],
                ['min_pna' => 120000, 'max_pna' => null, 'policies' => 2.0],
            ],
        ]);

        $version = SchemeVersion::create([
            'scheme_id'    => $scheme->id,
            'version_name' => 'v1.0',
            'starts_at'    => '2026-01-01',
            'ends_at'      => '2026-12-31',
        ]);

        $tiers = [
            ['Activo 12', 1.0, 1.49, 2.0],
            ['Activo 18', 1.5, 1.99, 3.0],
            ['Productivo 24', 2.0, 2.99, 5.0],
            ['Productivo 36', 3.0, null, 7.0],
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
            ]);
        }

        return $scheme->fresh(['versions.tiers']);
    }

    private function createAdditionalAgentsScheme(): Scheme
    {
        $scheme = Scheme::create([
            'name'                 => 'additional_agents',
            'template_key'         => 'additional_agents',
            'type'                 => 'bonus',
            'target'               => 'promoter',
            'is_active'            => true,
            'metric_base'          => 'PP',
            'frequency'            => 'trimestral',
            'dependency_scheme_id' => 'first_year_production',
        ]);

        $version = SchemeVersion::create([
            'scheme_id'    => $scheme->id,
            'version_name' => 'v1.0',
            'starts_at'    => '2026-01-01',
            'ends_at'      => '2026-12-31',
        ]);

        foreach ([[1, 1, 2.0], [2, 2, 3.0], [3, 3, 4.0]] as [$min, $max, $pct]) {
            SchemeTier::create([
                'scheme_version_id'   => $version->id,
                'conditions'          => ['min_agents' => $min, 'max_agents' => $max],
                'agent_percentage'    => 0,
                'promoter_percentage' => $pct,
            ]);
        }

        return $scheme->fresh(['versions.tiers']);
    }
}
