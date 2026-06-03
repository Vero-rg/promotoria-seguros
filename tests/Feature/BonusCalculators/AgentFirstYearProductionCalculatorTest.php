<?php

namespace Tests\Feature\BonusCalculators;

use App\Models\Agent;
use App\Models\Policy;
use App\Models\Scheme;
use App\Models\SchemeVersion;
use App\Models\SchemeTier;
use App\Services\BonusCalculators\AgentFirstYearProductionCalculator;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Suite de pruebas para el bono "Producción 1.er Año Vida Trimestral" (Agent).
 *
 * Reglas de negocio bajo prueba:
 *   - Tipo: bonus | Target: agent | Frequency: trimestral | Metric: PCA
 *   - Requisitos globales: 1 póliza "Vida" + 1 póliza "Primordial" (min_product_count = 1)
 *   - 3 Tiers (bandas) con PCA mínimo y porcentajes diferenciados
 *   - Solo aplica a agentes en su primer año desde contratación
 *
 * Cada prueba es autocontenida: construye su propio Scheme, Version, Tiers,
 * Agent y Policies usando las factories de Laravel + RefreshDatabase.
 */
class AgentFirstYearProductionCalculatorTest extends TestCase
{
    use RefreshDatabase;

    protected AgentFirstYearProductionCalculator $calculator;

    // ─── Constantes de negocio reutilizables ────────────────────────────────

    protected const SCHEME_NAME = 'agent_first_year_production';

    protected const TIER_1_MIN_PCA                = 130_000;
    protected const TIER_1_AGENT_PERCENTAGE       = 10.0;
    protected const TIER_1_AGENT_AUTO_PERCENTAGE  = 16.0;

    protected const TIER_2_MIN_PCA                = 200_000;
    protected const TIER_2_AGENT_PERCENTAGE       = 14.0;
    protected const TIER_2_AGENT_AUTO_PERCENTAGE  = 20.0;

    protected const TIER_3_MIN_PCA                = 785_000;
    protected const TIER_3_AGENT_PERCENTAGE       = 36.0;
    protected const TIER_3_AGENT_AUTO_PERCENTAGE  = 44.0;

    // ────────────────────────────────────────────────────────────────────────

    protected function setUp(): void
    {
        parent::setUp();

        // Fijar «now» para que los cálculos de primer año sean deterministas
        Carbon::setTestNow(Carbon::parse('2026-06-15'));

        $this->calculator = new AgentFirstYearProductionCalculator();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow(null);
        parent::tearDown();
    }

    // ═══════════════════════════════════════════════════════════════════════
    // TESTS DE ÉXITO
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * 🟢 Escenario feliz: Agente con $150,000 PCA, 1 póliza Vida y 1 Primordial.
     *
     * Debe alcanzar el Tier 1 (min_pca = 130,000) y recibir 10% sobre PCA.
     * Monto esperado: $150,000 × 10% = $15,000.
     */
    public function test_agent_receives_tier_one_bonus_when_all_conditions_are_met(): void
    {
        // ── Arrange ────────────────────────────────────────────────────
        $scheme  = $this->createSchemeWithTiers();
        $agent   = Agent::factory()->create(['created_at' => '2026-01-01']);
        $period  = $this->defaultPeriod();

        // 1 póliza Vida con $100,000 + 1 Primordial con $50,000 → PCA = $150,000
        Policy::factory()
            ->vida()
            ->withPremium(100_000)
            ->for($agent)
            ->create(['issue_date' => '2026-02-15']);

        Policy::factory()
            ->primordial()
            ->withPremium(50_000)
            ->for($agent)
            ->create(['issue_date' => '2026-03-10']);

        // ── Act ────────────────────────────────────────────────────────
        $result = $this->calculator->calculate(
            $agent, $scheme, $period['start'], $period['end']
        );

        // ── Assert ─────────────────────────────────────────────────────
        $this->assertTrue($result['is_achieved'], 'El bono debió alcanzarse. Razón: ' . ($result['details']['reason'] ?? '—'));

        $this->assertEquals(15_000.0, $result['amount']);
        // Tier 1 es el de menor min_pca, queda en último lugar tras orden descendente
        $this->assertEquals(2, $result['tier_index'], 'Debió tomar el Tier 1 (índice 2, el último tras sort DESC).');

        $this->assertNotNull($result['tier_data']);
        $this->assertEquals(
            self::TIER_1_AGENT_PERCENTAGE,
            (float) $result['tier_data']['agent_percentage'],
            'El tier alcanzado debe tener agent_percentage = 10.0'
        );

        // Progreso
        $this->assertEquals(150_000.0, $result['progress']['current_value']);
        $this->assertEquals(self::TIER_1_MIN_PCA, $result['progress']['required_value']);
    }

    /**
     * 🟢 Agente con $250,000 PCA y ambos productos → debe alcanzar Tier 2.
     *
     * Monto esperado: $250,000 × 14% = $35,000.
     */
    public function test_agent_receives_tier_two_bonus_with_higher_pca(): void
    {
        $scheme = $this->createSchemeWithTiers();
        $agent  = Agent::factory()->create(['created_at' => '2026-01-01']);
        $period = $this->defaultPeriod();

        Policy::factory()->vida()->withPremium(150_000)->for($agent)
            ->create(['issue_date' => '2026-02-01']);
        Policy::factory()->primordial()->withPremium(100_000)->for($agent)
            ->create(['issue_date' => '2026-02-15']);

        $result = $this->calculator->calculate($agent, $scheme, $period['start'], $period['end']);

        $this->assertTrue($result['is_achieved']);
        $this->assertEquals(35_000.0, $result['amount']);
        $this->assertEquals(1, $result['tier_index'], 'Debió tomar el Tier 2 (índice 1, el segundo tras sort DESC).');
        $this->assertEquals(
            self::TIER_2_AGENT_PERCENTAGE,
            (float) $result['tier_data']['agent_percentage']
        );
    }

    // ═══════════════════════════════════════════════════════════════════════
    // TESTS DE FALLO
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * 🔴 El agente tiene suficiente PCA ($300,000 → Tier 2) pero le falta
     *    la póliza Primordial requerida por reglas globales.
     */
    public function test_agent_fails_bonus_due_to_missing_required_product(): void
    {
        $scheme = $this->createSchemeWithTiers();
        $agent  = Agent::factory()->create(['created_at' => '2026-01-01']);
        $period = $this->defaultPeriod();

        // Solo 2 pólizas Vida — sin Primordial
        Policy::factory()->vida()->withPremium(200_000)->for($agent)
            ->create(['issue_date' => '2026-03-01']);
        Policy::factory()->vida()->withPremium(100_000)->for($agent)
            ->create(['issue_date' => '2026-04-01']);

        $result = $this->calculator->calculate($agent, $scheme, $period['start'], $period['end']);

        $this->assertFalse($result['is_achieved'], 'Debió fallar por falta de producto Primordial.');
        $this->assertEquals(0.0, $result['amount']);
        $this->assertStringContainsString('Primordial', $result['details']['reason'] ?? '');
    }

    /**
     * 🔴 El agente tiene ambos productos requeridos pero su PCA ($100,000)
     *    no alcanza ni siquiera el Tier 1 (min_pca = 130,000).
     */
    public function test_agent_fails_bonus_due_to_low_volume(): void
    {
        $scheme = $this->createSchemeWithTiers();
        $agent  = Agent::factory()->create(['created_at' => '2026-01-01']);
        $period = $this->defaultPeriod();

        Policy::factory()->vida()->withPremium(60_000)->for($agent)
            ->create(['issue_date' => '2026-02-01']);
        Policy::factory()->primordial()->withPremium(40_000)->for($agent)
            ->create(['issue_date' => '2026-03-01']);

        $result = $this->calculator->calculate($agent, $scheme, $period['start'], $period['end']);

        $this->assertFalse($result['is_achieved'], 'Debió fallar por PCA insuficiente.');
        $this->assertEquals(0.0, $result['amount']);

        // El progreso debe mostrar el valor real vs lo requerido
        $this->assertEquals(100_000.0, $result['progress']['current_value']);
        $this->assertEquals(self::TIER_1_MIN_PCA, $result['progress']['required_value']);
    }

    /**
     * 🔴 El agente no tiene NINGUNA póliza de los productos requeridos.
     */
    public function test_agent_fails_when_has_zero_required_policies(): void
    {
        $scheme = $this->createSchemeWithTiers();
        $agent  = Agent::factory()->create(['created_at' => '2026-01-01']);
        $period = $this->defaultPeriod();

        // Sin pólizas
        $result = $this->calculator->calculate($agent, $scheme, $period['start'], $period['end']);

        $this->assertFalse($result['is_achieved']);
        $this->assertStringContainsString('Vida', $result['details']['reason'] ?? '');
    }

    /**
     * 🔴 El agente ya superó su primer año → no aplica.
     */
    public function test_agent_fails_bonus_when_beyond_first_year(): void
    {
        $scheme = $this->createSchemeWithTiers();
        $agent  = Agent::factory()->create(['created_at' => '2023-12-01']); // 2.5 años atrás
        $period = $this->defaultPeriod();

        Policy::factory()->vida()->withPremium(500_000)->for($agent)
            ->create(['issue_date' => '2026-01-15']);
        Policy::factory()->primordial()->withPremium(500_000)->for($agent)
            ->create(['issue_date' => '2026-01-20']);

        $result = $this->calculator->calculate($agent, $scheme, $period['start'], $period['end']);

        $this->assertFalse($result['is_achieved']);
        $this->assertStringContainsString('primer año', $result['details']['reason'] ?? '');
    }

    /**
     * 🔴 Un Promoter intenta usar esta calculadora → rechazado.
     */
    public function test_promoter_cannot_receive_agent_first_year_bonus(): void
    {
        $scheme   = $this->createSchemeWithTiers();
        $promoter = \App\Models\Promoter::factory()->create();
        $period   = $this->defaultPeriod();

        $result = $this->calculator->calculate($promoter, $scheme, $period['start'], $period['end']);

        $this->assertFalse($result['is_achieved']);
        $this->assertStringContainsString('Agente', $result['details']['reason'] ?? '');
    }

    // ═══════════════════════════════════════════════════════════════════════
    // TESTS ADICIONALES DE PRECISIÓN
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * 🟢 El agente tiene más de 1 póliza por producto requerido → igual aplica.
     */
    public function test_agent_with_extra_policies_still_qualifies(): void
    {
        $scheme = $this->createSchemeWithTiers();
        $agent  = Agent::factory()->create(['created_at' => '2026-01-01']);
        $period = $this->defaultPeriod();

        // 3 Vida + 2 Primordial — excede el mínimo
        Policy::factory()->vida()->withPremium(50_000)->for($agent)
            ->create(['issue_date' => '2026-02-01']);
        Policy::factory()->vida()->withPremium(40_000)->for($agent)
            ->create(['issue_date' => '2026-02-15']);
        Policy::factory()->vida()->withPremium(30_000)->for($agent)
            ->create(['issue_date' => '2026-03-01']);
        Policy::factory()->primordial()->withPremium(20_000)->for($agent)
            ->create(['issue_date' => '2026-03-15']);
        Policy::factory()->primordial()->withPremium(20_000)->for($agent)
            ->create(['issue_date' => '2026-04-01']);
        // PCA total = 160,000 → Tier 1

        $result = $this->calculator->calculate($agent, $scheme, $period['start'], $period['end']);

        $this->assertTrue($result['is_achieved']);
        $this->assertEquals(16_000.0, $result['amount']); // 160,000 × 10%
    }

    /**
     * 🔴 Pólizas fuera del primer año del agente NO cuentan para el PCA.
     */
    public function test_policies_outside_first_year_are_excluded_from_pca(): void
    {
        $scheme = $this->createSchemeWithTiers();
        $agent  = Agent::factory()->create(['created_at' => '2025-08-01']);
        $period = $this->defaultPeriod();

        // Póliza en el primer año (dentro del año de contratación): $80,000
        Policy::factory()->vida()->withPremium(80_000)->for($agent)
            ->create(['issue_date' => '2025-11-01']);

        // Póliza fuera del primer año (después de 2026-08-01): $200,000 — NO debe contar para PCA
        // Pero SÍ cuenta para el requisito global de producto (Primordial existe)
        Policy::factory()->primordial()->withPremium(200_000)->for($agent)
            ->create(['issue_date' => '2026-09-01']);

        $result = $this->calculator->calculate($agent, $scheme, $period['start'], $period['end']);

        // PCA = $80,000 < Tier 1 (130,000) → falla
        $this->assertFalse($result['is_achieved']);
        $this->assertEquals(80_000.0, $result['progress']['current_value']);
    }

    /**
     * 🟢 Los tiers se evalúan en orden: si el agente califica para Tier 3,
     *    debe tomar ese (no el Tier 1 o 2).
     */
    public function test_agent_gets_highest_qualifying_tier(): void
    {
        $scheme = $this->createSchemeWithTiers();
        $agent  = Agent::factory()->create(['created_at' => '2026-01-01']);
        $period = $this->defaultPeriod();

        // PCA = $800,000 → debe calificar para Tier 3
        Policy::factory()->vida()->withPremium(500_000)->for($agent)
            ->create(['issue_date' => '2026-02-01']);
        Policy::factory()->primordial()->withPremium(300_000)->for($agent)
            ->create(['issue_date' => '2026-03-01']);

        $result = $this->calculator->calculate($agent, $scheme, $period['start'], $period['end']);

        $this->assertTrue($result['is_achieved']);
        $this->assertEquals(0, $result['tier_index'], 'Debió tomar el Tier 3 (índice 0, el primero tras sort DESC).');
        $this->assertEquals(288_000.0, $result['amount']); // 800,000 × 36%
        $this->assertEquals(
            self::TIER_3_AGENT_PERCENTAGE,
            (float) $result['tier_data']['agent_percentage']
        );
    }

    // ═══════════════════════════════════════════════════════════════════════
    // HELPERS DE CONSTRUCCIÓN DE DATOS
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Crea el esquema completo "Producción 1.er Año Vida Trimestral" con:
     *   - Reglas globales: requires_product = ['Vida', 'Primordial'], min_product_count = 1
     *   - 3 Tiers con PCA mínimo y porcentajes diferenciados
     */
    protected function createSchemeWithTiers(): Scheme
    {
        $scheme = Scheme::create([
            'name'              => self::SCHEME_NAME,
            'type'              => 'bonus',
            'target'            => 'agent',
            'is_active'         => true,
            'metric_base'       => 'PCA',
            'frequency'         => 'trimestral',
            'requires_product'  => ['Vida', 'Primordial'],
            'min_product_count' => 1,
        ]);

        $version = SchemeVersion::create([
            'scheme_id'    => $scheme->id,
            'version_name' => 'v1.0 — Q1 2026',
            'starts_at'    => '2026-01-01',
            'ends_at'      => '2026-12-31',
        ]);

        // ── Tier 1: $130,000 PCA → 10% ─────────────────────────────────
        SchemeTier::create([
            'scheme_version_id'          => $version->id,
            'conditions'                 => ['min_pca' => self::TIER_1_MIN_PCA],
            'agent_percentage'           => self::TIER_1_AGENT_PERCENTAGE,
            'agent_automatic_percentage' => self::TIER_1_AGENT_AUTO_PERCENTAGE,
            'promoter_percentage'        => 0,
            'fixed_amount'               => null,
        ]);

        // ── Tier 2: $200,000 PCA → 14% ─────────────────────────────────
        SchemeTier::create([
            'scheme_version_id'          => $version->id,
            'conditions'                 => ['min_pca' => self::TIER_2_MIN_PCA],
            'agent_percentage'           => self::TIER_2_AGENT_PERCENTAGE,
            'agent_automatic_percentage' => self::TIER_2_AGENT_AUTO_PERCENTAGE,
            'promoter_percentage'        => 0,
            'fixed_amount'               => null,
        ]);

        // ── Tier 3: $785,000 PCA → 36% ─────────────────────────────────
        SchemeTier::create([
            'scheme_version_id'          => $version->id,
            'conditions'                 => ['min_pca' => self::TIER_3_MIN_PCA],
            'agent_percentage'           => self::TIER_3_AGENT_PERCENTAGE,
            'agent_automatic_percentage' => self::TIER_3_AGENT_AUTO_PERCENTAGE,
            'promoter_percentage'        => 0,
            'fixed_amount'               => null,
        ]);

        return $scheme->fresh(['versions.tiers']);
    }

    /**
     * Periodo por defecto para las pruebas: año completo 2026.
     *
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
