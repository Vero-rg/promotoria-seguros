<?php

namespace Tests\Feature\BonusCalculators;

use App\Models\Agent;
use App\Models\Policy;
use App\Models\Promoter;
use App\Models\Scheme;
use App\Models\SchemeTier;
use App\Models\SchemeVersion;
use App\Services\BonusCalculators\AdditionalAgentsCalculator;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Suite de pruebas para el Bono Adicional por Agentes con Compensación Trimestral.
 *
 * REGLAS DE NEGOCIO:
 *   1. El Promoter DEBE haber ganado el bono prerequisite (dependency_scheme_id).
 *   2. Solo cuentan los agentes que ganaron la Bono Activity Ratio en el periodo.
 *   3. Cada agente cuenta una sola vez por trimestre.
 *   4. El porcentaje se aplica sobre la PP total de la promotoría.
 *
 * Tiers:
 *   ┌──────────────┬────────┐
 *   │   Agentes    │   %    │
 *   ├──────────────┼────────┤
 *   │      1       │   2%   │
 *   │      2       │   3%   │
 *   │      3       │   4%   │
 *   │      4       │   5%   │
 *   │     5+       │   7%   │
 *   └──────────────┴────────┘
 */
class AdditionalAgentsCalculatorTest extends TestCase
{
    use RefreshDatabase;

    protected AdditionalAgentsCalculator $calculator;

    protected Scheme $prerequisiteScheme;

    protected Scheme $activityRatioScheme;

    // ────────────────────────────────────────────────────────────────────

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-06-15'));

        // Bono prerequisite «Producción de 1er Año Trimestral» (Promotor)
        $this->prerequisiteScheme = $this->createPrerequisiteScheme();

        // Bono prerequisite de los agentes requerido por Activity Ratio
        $this->createAgentFirstYearScheme();

        // Bono «Activity Ratio» que define si un agente tiene compensación
        $this->activityRatioScheme = $this->createActivityRatioScheme();

        $this->calculator = new AdditionalAgentsCalculator();
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
     * 🟢 5 agentes que ganan Activity Ratio → Tier 5 (5+) → 7%.
     *
     * PP total = $500,000. Monto = $500,000 × 7% = $35,000.
     */
    public function test_five_compensated_agents_reach_top_tier(): void
    {
        $scheme   = $this->createAdditionalAgentsScheme();
        $promoter = Promoter::factory()->create();

        $this->createQualifiedAgents($promoter, 5, 100_000);

        $result = $this->calculator->calculate(
            $promoter,
            $scheme,
            Carbon::parse('2026-01-01'),
            Carbon::parse('2026-12-31'),
        );

        $this->assertTrue($result['is_achieved'], 'Debió alcanzarse. Razón: '.($result['details']['reason'] ?? '—'));
        $this->assertEquals(7.0, (float) $result['tier_data']['promoter_percentage']);
        $this->assertEquals(35_000.0, $result['amount']);
        $this->assertEquals(5, $result['details']['qualified_agents']);
    }

    /**
     * 🟢 Exactamente 3 agentes con compensación → Tier 3 → 4%.
     */
    public function test_three_compensated_agents_reach_four_percent(): void
    {
        $scheme   = $this->createAdditionalAgentsScheme();
        $promoter = Promoter::factory()->create();

        $this->createQualifiedAgents($promoter, 3, 100_000);

        $result = $this->calculator->calculate(
            $promoter,
            $scheme,
            Carbon::parse('2026-01-01'),
            Carbon::parse('2026-12-31'),
        );

        $this->assertTrue($result['is_achieved']);
        $this->assertEquals(4.0, (float) $result['tier_data']['promoter_percentage']);
        $this->assertEquals(12_000.0, $result['amount']);
    }

    /**
     * 🟢 Más agentes que el rango configurado conservan el tramo más alto.
     *
     * El tabulador cubre hasta 5 agentes; con 6 el bono NO debe bloquearse.
     */
    public function test_six_compensated_agents_keep_the_top_tier(): void
    {
        $scheme   = $this->createAdditionalAgentsScheme();
        $promoter = Promoter::factory()->create();

        $this->createQualifiedAgents($promoter, 6, 100_000);

        $result = $this->calculator->calculate(
            $promoter,
            $scheme,
            Carbon::parse('2026-01-01'),
            Carbon::parse('2026-12-31'),
        );

        $this->assertTrue($result['is_achieved'], 'Con 6 agentes debe conservarse el tramo 5+.');
        $this->assertEquals(7.0, (float) $result['tier_data']['promoter_percentage']);
        $this->assertEquals(6, $result['details']['qualified_agents']);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 🟡 AGENTES SIN COMPENSACIÓN
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * 🟡 Los agentes que no ganan Activity Ratio no cuentan para el tramo.
     *
     * 3 agentes con producción + 2 registrados sin pólizas → 3 calificados.
     */
    public function test_agents_without_activity_ratio_are_not_counted(): void
    {
        $scheme   = $this->createAdditionalAgentsScheme();
        $promoter = Promoter::factory()->create();

        $this->createQualifiedAgents($promoter, 3, 100_000);

        // Agentes registrados sin pólizas: no ganan Activity Ratio
        Agent::factory()->count(2)->for($promoter, 'promoter')->create(['is_active' => true]);

        $result = $this->calculator->calculate(
            $promoter,
            $scheme,
            Carbon::parse('2026-01-01'),
            Carbon::parse('2026-12-31'),
        );

        $this->assertTrue($result['is_achieved']);
        $this->assertEquals(3, $result['details']['qualified_agents']);
        $this->assertEquals(5, $result['details']['active_agents']);
        $this->assertEquals(4.0, (float) $result['tier_data']['promoter_percentage']);
        $this->assertEquals(12_000.0, $result['amount']); // PP $300K × 4%
    }

    /**
     * 🟡 Cada agente cuenta una sola vez por trimestre.
     *
     * Un agente con 5 pólizas que gana Activity Ratio suma 1, no 5.
     */
    public function test_an_agent_counts_only_once_regardless_of_policy_count(): void
    {
        $scheme   = $this->createAdditionalAgentsScheme();
        $promoter = Promoter::factory()->create();

        $agent = Agent::factory()->for($promoter, 'promoter')->create(['is_active' => true]);

        // 5 pólizas pagadas del mismo agente
        Policy::factory()->vida()->withPremium(100_000)->count(5)
            ->for($agent)
            ->create(['issue_date' => '2026-03-01', 'status' => Policy::STATUS_PAGADA]);

        $result = $this->calculator->calculate(
            $promoter,
            $scheme,
            Carbon::parse('2026-01-01'),
            Carbon::parse('2026-12-31'),
        );

        $this->assertTrue($result['is_achieved']);
        $this->assertEquals(1, $result['details']['qualified_agents']);
        $this->assertEquals(2.0, (float) $result['tier_data']['promoter_percentage']);
        $this->assertEquals(10_000.0, $result['amount']); // $500K × 2%
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 🔴 TESTS DE FALLO
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * 🔴 El bono prerequisite está INACTIVO → el bono no se otorga.
     */
    public function test_fails_if_prerequisite_bonus_is_inactive(): void
    {
        Scheme::where('id', $this->prerequisiteScheme->id)->update(['is_active' => false]);

        $scheme   = $this->createAdditionalAgentsScheme();
        $promoter = Promoter::factory()->create();

        $this->createQualifiedAgents($promoter, 5, 100_000);

        $result = $this->calculator->calculate(
            $promoter,
            $scheme,
            Carbon::parse('2026-01-01'),
            Carbon::parse('2026-12-31'),
        );

        $this->assertFalse($result['is_achieved']);
        $this->assertStringContainsString('prerequisite', strtolower($result['details']['reason'] ?? ''));
    }

    /**
     * 🔴 Promotor sin agentes → no hay agentes con compensación.
     */
    public function test_fails_with_zero_compensated_agents(): void
    {
        $scheme   = $this->createAdditionalAgentsScheme();
        $promoter = Promoter::factory()->create();

        $result = $this->calculator->calculate(
            $promoter,
            $scheme,
            Carbon::parse('2026-01-01'),
            Carbon::parse('2026-12-31'),
        );

        $this->assertFalse($result['is_achieved']);
        $this->assertEquals(0, $result['progress']['current_value']);
    }

    /**
     * 🔴 Sin esquema Activity Ratio no se puede determinar la compensación.
     */
    public function test_fails_if_activity_ratio_scheme_is_missing(): void
    {
        $this->activityRatioScheme->delete();

        $scheme   = $this->createAdditionalAgentsScheme();
        $promoter = Promoter::factory()->create();

        $this->createQualifiedAgents($promoter, 3, 100_000);

        $result = $this->calculator->calculate(
            $promoter,
            $scheme,
            Carbon::parse('2026-01-01'),
            Carbon::parse('2026-12-31'),
        );

        $this->assertFalse($result['is_achieved']);
        $this->assertStringContainsString('Activity Ratio', $result['details']['reason'] ?? '');
    }

    /**
     * 🔴 Un Agent no puede recibir este bono.
     */
    public function test_agent_cannot_receive_additional_agents_bonus(): void
    {
        $scheme = $this->createAdditionalAgentsScheme();
        $agent  = Agent::factory()->create();

        $result = $this->calculator->calculate(
            $agent,
            $scheme,
            Carbon::parse('2026-01-01'),
            Carbon::parse('2026-12-31'),
        );

        $this->assertFalse($result['is_achieved']);
        $this->assertStringContainsString('Promotor', $result['details']['reason'] ?? '');
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 🟡 BARRAS DE PROGRESO
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * 🟡 La barra del bono prerequisite aparece una sola vez y marcada como
     *    dependencia (el frontend la reemplaza con el estado real del bono hijo).
     */
    public function test_prerequisite_bar_is_listed_only_once(): void
    {
        $scheme   = $this->createAdditionalAgentsScheme();
        $promoter = Promoter::factory()->create();

        $result = $this->calculator->calculate(
            $promoter,
            $scheme,
            Carbon::parse('2026-01-01'),
            Carbon::parse('2026-12-31'),
        );

        $dependencyBars = array_filter(
            $result['progress_breakdown'],
            fn (array $item) => ($item['_isDependency'] ?? false) === true,
        );

        $this->assertCount(1, $dependencyBars, 'La dependencia no debe duplicarse.');

        $prerequisiteBars = array_filter(
            $result['progress_breakdown'],
            fn (array $item) => $item['label'] === $this->prerequisiteScheme->name,
        );

        $this->assertCount(1, $prerequisiteBars, 'El bono prerequisite debe mostrarse una sola vez.');
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 🔧 HELPERS
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Crea N agentes activos con una póliza pagada de vida que les permite
     * ganar el Bono Activity Ratio.
     */
    private function createQualifiedAgents(Promoter $promoter, int $count, float $premium): void
    {
        for ($i = 0; $i < $count; $i++) {
            $agent = Agent::factory()
                ->for($promoter, 'promoter')
                ->create(['is_active' => true]);

            Policy::factory()->vida()->withPremium($premium)
                ->for($agent)
                ->create(['issue_date' => '2026-03-01', 'status' => Policy::STATUS_PAGADA]);
        }
    }

    private function createPrerequisiteScheme(): Scheme
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

    /**
     * Bono «Producción 1er Año Vida Trimestral (Agentes)» que Activity Ratio
     * exige como prerequisite. Tier accesible: cualquier PCA > $0.
     */
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
            'scheme_version_id'          => $version->id,
            'conditions'                 => ['min_pca' => 1],
            'agent_percentage'           => 0,
            'agent_automatic_percentage' => 0,
            'promoter_percentage'        => 0,
        ]);

        return $scheme->fresh(['versions.tiers']);
    }

    /**
     * Esquema Activity Ratio con un tier accesible: basta una póliza pagada.
     */
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
            'pna_equivalences'     => [
                ['min_pna' => 16000, 'max_pna' => 20999, 'policies' => 0.5],
                ['min_pna' => 21000, 'max_pna' => 59999, 'policies' => 1.0],
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

        SchemeTier::create([
            'scheme_version_id'   => $version->id,
            'conditions'          => ['min_policies' => 0.1, 'max_policies' => null],
            'agent_percentage'    => 2.0,
            'promoter_percentage' => 0,
        ]);

        return $scheme->fresh(['versions.tiers']);
    }

    /**
     * Esquema «Adicional por Agentes» que depende del bono prerequisite.
     */
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
            'version_name' => 'v1.0 — Adicional por Agentes',
            'starts_at'    => '2026-01-01',
            'ends_at'      => '2026-12-31',
        ]);

        $tiers = [
            // [min_agents, max_agents, promoter_pct]
            [1, 1,    2.0],
            [2, 2,    3.0],
            [3, 3,    4.0],
            [4, 4,    5.0],
            [5, 5,    7.0],
        ];

        foreach ($tiers as [$min, $max, $pct]) {
            SchemeTier::create([
                'scheme_version_id'   => $version->id,
                'conditions'          => [
                    'min_agents' => $min,
                    'max_agents' => $max,
                ],
                'agent_percentage'    => 0,
                'promoter_percentage' => $pct,
                'fixed_amount'        => null,
            ]);
        }

        return $scheme->fresh(['versions.tiers']);
    }
}
