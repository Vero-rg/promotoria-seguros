<?php

namespace Tests\Feature\BonusCalculators;

use App\Models\Agent;
use App\Models\Policy;
use App\Models\Promoter;
use App\Models\Scheme;
use App\Models\SchemeVersion;
use App\Models\SchemeTier;
use App\Services\BonusCalculators\AdditionalAgentsCalculator;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Suite de pruebas para el Bono Adicional por Agentes con Compensación Trimestral.
 *
 * REGLAS DE NEGOCIO:
 *   1. El Promoter DEBE haber ganado el bono prerequisite (dependency_scheme_id).
 *   2. Solo cuentan agentes ACTIVOS bajo el promotor.
 *   3. El porcentaje se aplica sobre la PP total de la promotoría.
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

    // ─── ID de referencia para el esquema prerequisite ──────────────────

    protected int $prerequisiteSchemeId;

    // ────────────────────────────────────────────────────────────────────

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-06-15'));

        // Crear el bono prerequisite "Producción 1er Año Vida" (activo por defecto)
        $prerequisite = Scheme::create([
            'name'      => 'first_year_production',
            'type'      => 'bonus',
            'target'    => 'promoter',
            'is_active' => true,
        ]);

        SchemeVersion::create([
            'scheme_id'    => $prerequisite->id,
            'version_name' => 'v1.0',
            'starts_at'    => '2026-01-01',
            'ends_at'      => '2026-12-31',
        ]);

        $this->prerequisiteSchemeId = $prerequisite->id;

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
     * 🟢 5 agentes calificados → Tier 5 (5+) → 7%.
     *
     * PP total = $500,000. Monto = $500,000 × 7% = $35,000.
     */
    public function test_promoter_achieves_tier_five_with_five_qualifying_agents(): void
    {
        $scheme   = $this->createAdditionalAgentsScheme();
        $promoter = Promoter::factory()->create();
        $period   = $this->defaultPeriod();

        // ── 5 agentes activos con PP ────────────────────────────────────
        $agents = [];
        for ($i = 0; $i < 5; $i++) {
            $agents[] = Agent::factory()
                ->for($promoter, 'promoter')
                ->create(['is_active' => true]);

            Policy::factory()->vida()->withPremium(100_000)
                ->for($agents[$i])
                ->create(['issue_date' => '2026-03-01', 'status' => Policy::STATUS_ACTIVA]);
        }

        // PP total = 5 × $100K = $500K

        $result = $this->calculator->calculate($promoter, $scheme, $period['start'], $period['end']);

        $this->assertTrue($result['is_achieved'], 'Debió alcanzarse. Razón: ' . ($result['details']['reason'] ?? '—'));
        $this->assertEquals(7.0, (float) $result['tier_data']['promoter_percentage']);
        $this->assertEquals(35_000.0, $result['amount']); // $500K × 7%
        $this->assertEquals(5, $result['details']['active_agents']);
    }

    /**
     * 🟢 Exactamente 3 agentes → Tier 3 (3 agentes) → 4%.
     *
     * PP total = $300,000. Monto = $300,000 × 4% = $12,000.
     */
    public function test_promoter_achieves_tier_three_with_exactly_three_qualifying_agents(): void
    {
        $scheme   = $this->createAdditionalAgentsScheme();
        $promoter = Promoter::factory()->create();
        $period   = $this->defaultPeriod();

        for ($i = 0; $i < 3; $i++) {
            $agent = Agent::factory()
                ->for($promoter, 'promoter')
                ->create(['is_active' => true]);

            Policy::factory()->vida()->withPremium(100_000)
                ->for($agent)
                ->create(['issue_date' => '2026-03-01', 'status' => Policy::STATUS_ACTIVA]);
        }

        $result = $this->calculator->calculate($promoter, $scheme, $period['start'], $period['end']);

        $this->assertTrue($result['is_achieved']);
        $this->assertEquals(4.0, (float) $result['tier_data']['promoter_percentage']);
        $this->assertEquals(12_000.0, $result['amount']); // $300K × 4%
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 🔴 TESTS DE FALLO
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * 🔴 El promotor tiene 5 agentes calificados, pero el bono prerequisite
     *    "Producción 1er Año" está INACTIVO → NO ganó el bono previo.
     */
    public function test_promoter_fails_completely_if_dependency_bonus_not_achieved(): void
    {
        // ── Desactivar el bono prerequisite ────────────────────────────
        Scheme::where('id', $this->prerequisiteSchemeId)->update(['is_active' => false]);

        $scheme   = $this->createAdditionalAgentsScheme();
        $promoter = Promoter::factory()->create();
        $period   = $this->defaultPeriod();

        // 5 agentes calificados
        for ($i = 0; $i < 5; $i++) {
            $agent = Agent::factory()
                ->for($promoter, 'promoter')
                ->create(['is_active' => true]);

            Policy::factory()->vida()->withPremium(100_000)
                ->for($agent)
                ->create(['issue_date' => '2026-03-01', 'status' => Policy::STATUS_ACTIVA]);
        }

        $result = $this->calculator->calculate($promoter, $scheme, $period['start'], $period['end']);

        $this->assertFalse($result['is_achieved'], 'Debió fallar porque el bono prerequisite no está activo.');
        $this->assertStringContainsString('prerequisite', strtolower($result['details']['reason'] ?? ''));
    }

    /**
     * 🔴 Promotor cumple dependencia, pero tiene 0 agentes activos → falla.
     */
    public function test_promoter_fails_with_zero_qualifying_agents(): void
    {
        $scheme   = $this->createAdditionalAgentsScheme();
        $promoter = Promoter::factory()->create();
        $period   = $this->defaultPeriod();

        // Sin agentes

        $result = $this->calculator->calculate($promoter, $scheme, $period['start'], $period['end']);

        $this->assertFalse($result['is_achieved']);
        $this->assertEquals(0, $result['progress']['current_value']);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 🟡 TESTS DE PRECISIÓN
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * 🟡 6 agentes en total, pero solo 2 están ACTIVOS → Tier 2 (3%).
     *
     * Los inactivos son ignorados en el conteo.
     * PP total = la suma de TODOS (activos + inactivos) = 6 × $100K = $600K.
     * Monto = $600,000 × 3% = $18,000.
     */
    public function test_non_qualifying_agents_are_ignored_in_the_count(): void
    {
        $scheme   = $this->createAdditionalAgentsScheme();
        $promoter = Promoter::factory()->create();
        $period   = $this->defaultPeriod();

        // ── 2 agentes ACTIVOS ──────────────────────────────────────────
        for ($i = 0; $i < 2; $i++) {
            $agent = Agent::factory()
                ->for($promoter, 'promoter')
                ->create(['is_active' => true]);

            Policy::factory()->vida()->withPremium(100_000)
                ->for($agent)
                ->create(['issue_date' => '2026-03-01', 'status' => Policy::STATUS_ACTIVA]);
        }

        // ── 4 agentes INACTIVOS (no deben contar para el tier) ─────────
        for ($i = 0; $i < 4; $i++) {
            $agent = Agent::factory()
                ->for($promoter, 'promoter')
                ->create(['is_active' => false]);

            Policy::factory()->vida()->withPremium(100_000)
                ->for($agent)
                ->create(['issue_date' => '2026-03-01', 'status' => Policy::STATUS_ACTIVA]);
        }

        $result = $this->calculator->calculate($promoter, $scheme, $period['start'], $period['end']);

        $this->assertTrue($result['is_achieved']);

        // Debe ser Tier 2 (2 agentes → 3%), NO Tier 5 (5+ → 7%)
        $this->assertEquals(3.0, (float) $result['tier_data']['promoter_percentage']);
        $this->assertNotEquals(7.0, (float) $result['tier_data']['promoter_percentage']);

        // PP total = 6 × $100K = $600K (los inactivos también contribuyen PP)
        $this->assertEquals(18_000.0, $result['amount']); // $600K × 3%

        // Solo 2 agentes activos contados
        $this->assertEquals(2, $result['details']['active_agents']);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 🟡 TEST ADICIONAL
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * 🔴 Un Agent no puede recibir este bono.
     */
    public function test_agent_cannot_receive_additional_agents_bonus(): void
    {
        $scheme = $this->createAdditionalAgentsScheme();
        $agent  = Agent::factory()->create();
        $period = $this->defaultPeriod();

        $result = $this->calculator->calculate($agent, $scheme, $period['start'], $period['end']);

        $this->assertFalse($result['is_achieved']);
        $this->assertStringContainsString('Promotor', $result['details']['reason'] ?? '');
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 🔧 HELPERS
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Crea el esquema «Adicional por Agentes» que depende del bono prerequisite.
     */
    protected function createAdditionalAgentsScheme(): Scheme
    {
        $scheme = Scheme::create([
            'name'                 => 'additional_agents',
            'type'                 => 'bonus',
            'target'               => 'promoter',
            'is_active'            => true,
            'metric_base'          => 'PP',
            'frequency'            => 'trimestral',
            'dependency_scheme_id' => (string) $this->prerequisiteSchemeId,
        ]);

        $version = SchemeVersion::create([
            'scheme_id'    => $scheme->id,
            'version_name' => 'v1.0 — Adicional por Agentes',
            'starts_at'    => '2026-01-01',
            'ends_at'      => '2026-12-31',
        ]);

        $tiers = [
            // [min_agents, max_agents, promoter_pct]
            [1, 1,     2.0],
            [2, 2,     3.0],
            [3, 3,     4.0],
            [4, 4,     5.0],
            [5, null,  7.0],
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
