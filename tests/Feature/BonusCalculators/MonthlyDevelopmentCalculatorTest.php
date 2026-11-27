<?php

namespace Tests\Feature\BonusCalculators;

use App\Models\Agent;
use App\Models\Policy;
use App\Models\Promoter;
use App\Models\Scheme;
use App\Models\SchemeVersion;
use App\Models\SchemeTier;
use App\Services\BonusCalculators\MonthlyDevelopmentCalculator;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Suite de pruebas para el Bono de Desarrollo Mensual (Monthly Development).
 *
 * REGLAS DE NEGOCIO:
 *   1. Eficiencia al cobro del Promoter >= min_collection_efficiency (81%).
 *   2. Cuota trimestral de reclutamiento (quarterly_recruits).
 *   3. Antigüedad del agente determina la franja (1-12 meses vs 13-24 meses).
 *   4. PCA individual del agente determina el porcentaje dentro de la franja.
 *
 * Matriz de tiers (promoter_percentage):
 *   ┌────────────────────┬──────────────┬───────────────┐
 *   │  Antigüedad        │ PCA ≥ $125K  │ PCA ≥ $400K   │
 *   ├────────────────────┼──────────────┼───────────────┤
 *   │ Mes 1 – 12         │     9%       │     15%       │
 *   │ Mes 13 – 24        │     0%       │      8%       │
 *   └────────────────────┴──────────────┴───────────────┘
 */
class MonthlyDevelopmentCalculatorTest extends TestCase
{
    use RefreshDatabase;

    protected MonthlyDevelopmentCalculator $calculator;

    // ─── Constantes ──────────────────────────────────────────────────────

    protected const MIN_COLLECTION_EFFICIENCY = 81;
    protected const Q1_QUOTA = 1;
    protected const Q2_QUOTA = 2;
    protected const Q3_QUOTA = 3;
    protected const Q4_QUOTA = 4;

    // ────────────────────────────────────────────────────────────────────

    protected function setUp(): void
    {
        parent::setUp();

        // Fijar «now» en Q2 (junio 2026) para la mayoría de pruebas
        Carbon::setTestNow(Carbon::parse('2026-06-15'));

        $this->calculator = new MonthlyDevelopmentCalculator();
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
     * 🟢 Agente en mes 6 con $450,000 PCA. Promoter con 85% eficiencia
     *    y cumple cuota Q2 (2 reclutas). Debe obtener 15%.
     *
     * Monto: ($450,000 − $1,350) × 15% = $67,297.50.
     */
    public function test_promoter_achieves_bonus_for_agent_in_first_year_with_high_pca(): void
    {
        $scheme   = $this->createMonthlyDevelopmentScheme();
        $promoter = Promoter::factory()->create();
        $period   = [
            'start' => Carbon::parse('2026-06-01'),
            'end'   => Carbon::parse('2026-06-30'),
        ];

        // ── Agente principal: mes 6, $450K PCA ──────────────────────────
        $agent1 = Agent::factory()
            ->for($promoter, 'promoter')
            ->create([
                'created_at' => '2026-01-01',  // mes 6 al 2026-06-30
                'is_active'  => true,
            ]);

        Policy::factory()->vida()->withPremium(450_000)
            ->for($agent1)
            ->create([
                'issue_date' => '2026-06-10',
                'status'     => Policy::STATUS_PAGADA,
            ]);

        // ── Agente extra para cumplir cuota Q2 (necesita 2 reclutas) ────
        // También genera la eficiencia al cobro: $100K no tomado (excluido del denominador)
        $agent2 = Agent::factory()
            ->for($promoter, 'promoter')
            ->create([
                'created_at' => '2026-02-01',  // dentro YTD Q2
                'is_active'  => true,
            ]);

        Policy::factory()->vida()->withPremium(100_000)
            ->for($agent2)
            ->create([
                'issue_date' => '2026-06-10',
                'status'     => Policy::STATUS_NO_TOMADA,
            ]);

        // Eficiencia = Pagada / (Pagada + Activa) = 450K / 450K = 100% ≥ 81% ✓

        // ── Act ────────────────────────────────────────────────────────
        $result = $this->calculator->calculate($promoter, $scheme, $period['start'], $period['end']);

        // ── Assert ─────────────────────────────────────────────────────
        $this->assertTrue($result['is_achieved'], 'Debió alcanzarse. Razón: ' . ($result['details']['reason'] ?? '—'));

        $this->assertEquals(67_297.5, $result['amount']);
        $this->assertEquals(15.0, (float) $result['tier_data']['promoter_percentage']);
        $this->assertEquals(1, count($result['details']['matched_agents']));
        $this->assertEquals(6, $result['details']['matched_agents'][0]['tenure_month']);
    }

    /**
     * 🟡 El monto de las pólizas Primordial no suma a la PCA del agente.
     *
     * Vida $450K → PCA $448,650 → 15% = $67,297.50 (la Primordial no aporta).
     */
    public function test_primordial_premiums_do_not_add_to_agent_pca(): void
    {
        $scheme   = $this->createMonthlyDevelopmentScheme();
        $promoter = Promoter::factory()->create();
        $period   = [
            'start' => Carbon::parse('2026-06-01'),
            'end'   => Carbon::parse('2026-06-30'),
        ];

        $agent1 = Agent::factory()
            ->for($promoter, 'promoter')
            ->create([
                'created_at' => '2026-01-01',
                'is_active'  => true,
            ]);

        Policy::factory()->vida()->withPremium(450_000)
            ->for($agent1)
            ->create([
                'issue_date' => '2026-06-10',
                'status'     => Policy::STATUS_PAGADA,
            ]);

        Policy::factory()->primordial()->withPremium(500_000)
            ->for($agent1)
            ->create([
                'issue_date' => '2026-06-10',
                'status'     => Policy::STATUS_PAGADA,
            ]);

        $agent2 = Agent::factory()
            ->for($promoter, 'promoter')
            ->create([
                'created_at' => '2026-02-01',
                'is_active'  => true,
            ]);

        Policy::factory()->vida()->withPremium(100_000)
            ->for($agent2)
            ->create([
                'issue_date' => '2026-06-10',
                'status'     => Policy::STATUS_NO_TOMADA,
            ]);

        $result = $this->calculator->calculate($promoter, $scheme, $period['start'], $period['end']);

        $this->assertTrue($result['is_achieved'], 'Debió alcanzarse. Razón: ' . ($result['details']['reason'] ?? '—'));

        $this->assertEquals(67_297.5, $result['amount']);
        $this->assertEquals(448_650.0, (float) $result['details']['matched_agents'][0]['pca']);
    }

    /**
     * 🟢 El desglose por agente expone PCA, porcentaje y monto de cada uno,
     *    incluyendo a los que no califican.
     */
    public function test_agent_breakdown_lists_each_agent_with_its_own_amount(): void
    {
        $scheme   = $this->createMonthlyDevelopmentScheme();
        $promoter = Promoter::factory()->create();
        $period   = [
            'start' => Carbon::parse('2026-06-01'),
            'end'   => Carbon::parse('2026-06-30'),
        ];

        // Agente con PCA alta → 15%
        $highAgent = Agent::factory()->for($promoter, 'promoter')
            ->create(['created_at' => '2026-01-01', 'is_active' => true, 'name' => 'Agente Uno']);
        Policy::factory()->vida()->pagada()->withPremium(450_000)
            ->for($highAgent)->create(['issue_date' => '2026-06-10']);

        // Agente con PCA base → 9%
        $baseAgent = Agent::factory()->for($promoter, 'promoter')
            ->create(['created_at' => '2026-01-02', 'is_active' => true, 'name' => 'Agente Dos']);
        Policy::factory()->vida()->pagada()->withPremium(200_000)
            ->for($baseAgent)->create(['issue_date' => '2026-06-10']);

        // Agente sin PCA mínimo → no califica
        $lowAgent = Agent::factory()->for($promoter, 'promoter')
            ->create(['created_at' => '2026-01-03', 'is_active' => true, 'name' => 'Agente Tres']);
        Policy::factory()->vida()->pagada()->withPremium(50_000)
            ->for($lowAgent)->create(['issue_date' => '2026-06-10']);

        $result = $this->calculator->calculate($promoter, $scheme, $period['start'], $period['end']);

        $this->assertTrue($result['is_achieved'], 'Debió alcanzarse. Razón: ' . ($result['details']['reason'] ?? '—'));
        $this->assertEquals(85_176.0, $result['amount']); // 67,297.50 + 17,878.50

        $breakdown = $result['details']['agent_breakdown'];
        $this->assertCount(3, $breakdown);

        // Ordenado por PCA descendente
        $this->assertSame('Agente Uno', $breakdown[0]['agent_label']);
        $this->assertEquals(448_650.0, (float) $breakdown[0]['pca']);
        $this->assertEquals(15.0, (float) $breakdown[0]['percentage']);
        $this->assertEquals(67_297.5, (float) $breakdown[0]['amount']);
        $this->assertEquals(6, $breakdown[0]['tenure_month']);

        $this->assertSame('Agente Dos', $breakdown[1]['agent_label']);
        $this->assertEquals(198_650.0, (float) $breakdown[1]['pca']);
        $this->assertEquals(9.0, (float) $breakdown[1]['percentage']);
        $this->assertEquals(17_878.5, (float) $breakdown[1]['amount']);

        $this->assertSame('Agente Tres', $breakdown[2]['agent_label']);
        $this->assertFalse($breakdown[2]['qualified']);
        $this->assertNull($breakdown[2]['percentage']);
        $this->assertEquals(0.0, (float) $breakdown[2]['amount']);
        $this->assertSame('No califica para ningún tramo', $breakdown[2]['note']);
    }

    /**
     * 🟢 Mismo agente ($450K PCA) pero en mes 15 → franja 13-24 → 8%.
     *
     * Monto: ($450,000 − $1,350) × 8% = $35,892.00.
     */
    public function test_promoter_achieves_reduced_bonus_for_agent_in_second_year(): void
    {
        $scheme   = $this->createMonthlyDevelopmentScheme();
        $promoter = Promoter::factory()->create();
        $period   = [
            'start' => Carbon::parse('2026-06-01'),
            'end'   => Carbon::parse('2026-06-30'),
        ];

        // ── Agente en mes 15 ────────────────────────────────────────────
        $agent1 = Agent::factory()
            ->for($promoter, 'promoter')
            ->create([
                'created_at' => '2025-04-01',  // → mes 15 al 2026-06-30
                'is_active'  => true,
            ]);

        Policy::factory()->vida()->withPremium(450_000)
            ->for($agent1)
            ->create([
                'issue_date' => '2026-06-10',
                'status'     => Policy::STATUS_PAGADA,
            ]);

        // ── Agentes extra para eficiencia y cuota Q2 ──────────────────
        // Agente 1 (2025) NO cuenta para cuota YTD Q2. Necesitamos 2 reclutas en 2026.
        $agent2 = Agent::factory()
            ->for($promoter, 'promoter')
            ->create([
                'created_at' => '2026-02-01',
                'is_active'  => true,
            ]);

        Policy::factory()->vida()->withPremium(50_000)
            ->for($agent2)
            ->create([
                'issue_date' => '2026-06-10',
                'status'     => Policy::STATUS_NO_TOMADA,
            ]);

        $agent3 = Agent::factory()
            ->for($promoter, 'promoter')
            ->create([
                'created_at' => '2026-03-01',
                'is_active'  => true,
            ]);

        Policy::factory()->vida()->withPremium(50_000)
            ->for($agent3)
            ->create([
                'issue_date' => '2026-06-10',
                'status'     => Policy::STATUS_PAGADA,
            ]);

        // Eficiencia = Pagada / (Pagada + Activa) = (450K + 50K) / (450K + 50K) = 500K/500K = 100% ✓
        // Q2 YTD reclutas = 2 (agent2 + agent3) ✓

        $result = $this->calculator->calculate($promoter, $scheme, $period['start'], $period['end']);

        $this->assertTrue($result['is_achieved']);
        $this->assertEquals(35_892.0, $result['amount']);
        $this->assertEquals(8.0, (float) $result['tier_data']['promoter_percentage']);
        $this->assertEquals(15, $result['details']['matched_agents'][0]['tenure_month']);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 🔴 TESTS DE FALLO POR REGLAS GLOBALES
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * 🔴 Promoter cumple todo excepto eficiencia al cobro: 75% < 81%.
     */
    public function test_promoter_fails_due_to_low_collection_efficiency(): void
    {
        $scheme   = $this->createMonthlyDevelopmentScheme();
        $promoter = Promoter::factory()->create();
        $period   = $this->defaultPeriod();

        // ── Agente principal: $450K pagado ─────────────────────────────
        $agent1 = Agent::factory()
            ->for($promoter, 'promoter')
            ->create(['created_at' => '2026-01-01', 'is_active' => true]);

        Policy::factory()->vida()->withPremium(450_000)
            ->for($agent1)
            ->create(['issue_date' => '2026-03-01', 'status' => Policy::STATUS_PAGADA]);

        // ── $150K activo (arrastra el denominador sin sumar al numerador)
        //     → eficiencia = Pagada / (Pagada + Activa) = 450K / 600K = 75%
        $agent2 = Agent::factory()
            ->for($promoter, 'promoter')
            ->create(['created_at' => '2026-02-01', 'is_active' => true]);

        Policy::factory()->vida()->withPremium(150_000)
            ->for($agent2)
            ->create(['issue_date' => '2026-04-01', 'status' => Policy::STATUS_ACTIVA]);

        $result = $this->calculator->calculate($promoter, $scheme, $period['start'], $period['end']);

        $this->assertFalse($result['is_achieved'], 'Debió fallar por baja eficiencia al cobro.');
        $this->assertStringContainsString('Eficiencia', $result['details']['reason'] ?? '');
        $this->assertEquals(75.0, $result['details']['collection_efficiency']);
    }

    /**
     * 🔴 En Q3 se requieren 3 reclutas, pero el promotor solo tiene 2.
     */
    public function test_promoter_fails_due_to_missing_quarterly_recruits_goal(): void
    {
        // ── Cambiar fecha a Q3 (septiembre 2026) ────────────────────────
        Carbon::setTestNow(Carbon::parse('2026-09-15'));

        $scheme   = $this->createMonthlyDevelopmentScheme();
        $promoter = Promoter::factory()->create();
        $period   = [
            'start' => Carbon::parse('2026-07-01'),
            'end'   => Carbon::parse('2026-09-30'),
        ];

        // ── Agente con $450K PCA ────────────────────────────────────────
        $agent1 = Agent::factory()
            ->for($promoter, 'promoter')
            ->create(['created_at' => '2026-01-01', 'is_active' => true]);

        Policy::factory()->vida()->withPremium(450_000)
            ->for($agent1)
            ->create(['issue_date' => '2026-08-01', 'status' => Policy::STATUS_PAGADA]);

        // ── Solo 2 reclutas YTD (Q3 requiere 3) ─────────────────────────
        $agent2 = Agent::factory()
            ->for($promoter, 'promoter')
            ->create(['created_at' => '2026-02-01', 'is_active' => true]);

        Policy::factory()->vida()->withPremium(50_000)
            ->for($agent2)
            ->create(['issue_date' => '2026-08-05', 'status' => Policy::STATUS_PAGADA]);

        // Eficiencia = Pagada / (Pagada + Activa) = (450K + 50K) / (450K + 50K) = 100% ✓

        $result = $this->calculator->calculate($promoter, $scheme, $period['start'], $period['end']);

        $this->assertFalse($result['is_achieved'], 'Debió fallar por no cumplir cuota trimestral Q3.');
        $this->assertStringContainsString('Q3', $result['details']['reason'] ?? '');
        $this->assertEquals(2, $result['details']['quarterly_recruits']);
    }

    /**
     * 🟢 La cuota se aplica también con el formato de llaves «q1..q4»
     *    (el que guarda el panel de esquemas).
     */
    public function test_quarterly_quota_is_enforced_with_prefixed_keys(): void
    {
        $scheme = $this->createMonthlyDevelopmentScheme();
        $scheme->update(['quarterly_recruits' => ['q1' => 1, 'q2' => 2, 'q3' => 3, 'q4' => 4]]);

        $promoter = Promoter::factory()->create();
        $period   = [
            'start' => Carbon::parse('2026-10-01'),
            'end'   => Carbon::parse('2026-10-31'),
        ];

        // 1 solo recluta en el año; Q4 exige 4
        $agent = Agent::factory()
            ->for($promoter, 'promoter')
            ->create(['created_at' => '2026-10-02', 'is_active' => true]);

        Policy::factory()->vida()->pagada()->withPremium(450_000)
            ->for($agent)
            ->create(['issue_date' => '2026-10-10']);

        $result = $this->calculator->calculate($promoter, $scheme, $period['start'], $period['end']);

        $this->assertFalse($result['is_achieved'], 'Debió fallar por no cumplir cuota Q4.');
        $this->assertStringContainsString('Q4', $result['details']['reason'] ?? '');
        $this->assertStringContainsString('requiere 4', $result['details']['reason'] ?? '');
        $this->assertEquals(1, $result['details']['quarterly_recruits']);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 🔴 TEST DE FALLO POR ANTIGÜEDAD
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * 🔴 Agente en mes 26 → supera el rango máximo de todos los tiers (24).
     *    Ningún tier aplica aunque el PCA y las reglas globales se cumplan.
     */
    public function test_agent_beyond_twenty_four_months_does_not_generate_bonus(): void
    {
        $scheme   = $this->createMonthlyDevelopmentScheme();
        $promoter = Promoter::factory()->create();
        $period   = [
            'start' => Carbon::parse('2026-06-01'),
            'end'   => Carbon::parse('2026-06-30'),
        ];

        // ── Agente en mes 26 ────────────────────────────────────────────
        $agent1 = Agent::factory()
            ->for($promoter, 'promoter')
            ->create([
                'created_at' => '2024-05-01',  // → mes 26 al 2026-06-30
                'is_active'  => true,
            ]);

        Policy::factory()->vida()->withPremium(450_000)
            ->for($agent1)
            ->create([
                'issue_date' => '2026-06-10',
                'status'     => Policy::STATUS_PAGADA,
            ]);

        // ── Agentes extra para cumplir cuota Q2 (necesita 2 reclutas) ──
        // El agente de 2024 NO cuenta para YTD (es anterior al 1-ene-2026)
        $agent2 = Agent::factory()
            ->for($promoter, 'promoter')
            ->create(['created_at' => '2026-02-01', 'is_active' => true]);

        Policy::factory()->vida()->withPremium(50_000)
            ->for($agent2)
            ->create(['issue_date' => '2026-06-10', 'status' => Policy::STATUS_PAGADA]);

        $agent3 = Agent::factory()
            ->for($promoter, 'promoter')
            ->create(['created_at' => '2026-03-01', 'is_active' => true]);

        Policy::factory()->vida()->withPremium(50_000)
            ->for($agent3)
            ->create(['issue_date' => '2026-06-10', 'status' => Policy::STATUS_NO_TOMADA]);

        // Eficiencia = Pagada / (Pagada + Activa) = (450K + 50K) / (450K + 50K) = 500K/500K = 100% ✓
        // Q2 reclutas = 2 (agent2 + agent3, ambos creados en 2026) ✓

        $result = $this->calculator->calculate($promoter, $scheme, $period['start'], $period['end']);

        $this->assertFalse($result['is_achieved'], 'Debió fallar: el agente supera los 24 meses.');
        $this->assertStringContainsString('Ningún agente califica', $result['details']['reason'] ?? '');
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 🟡 TESTS ADICIONALES
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * 🟡 Agente en mes 6 con $150K PCA → franja 1-12, PCA base → 9%.
     */
    public function test_agent_in_first_year_with_base_pca_gets_9_percent(): void
    {
        $scheme   = $this->createMonthlyDevelopmentScheme();
        $promoter = Promoter::factory()->create();
        $period   = [
            'start' => Carbon::parse('2026-06-01'),
            'end'   => Carbon::parse('2026-06-30'),
        ];

        $agent = Agent::factory()
            ->for($promoter, 'promoter')
            ->create(['created_at' => '2026-01-01', 'is_active' => true]);

        Policy::factory()->vida()->withPremium(150_000)
            ->for($agent)
            ->create(['issue_date' => '2026-06-10', 'status' => Policy::STATUS_PAGADA]);

        // Agente extra para cuota y eficiencia (no tomado, excluido del denominador)
        // Eficiencia = Pagada / (Pagada + Activa) = 150K / 150K = 100% ✓
        $aux = Agent::factory()
            ->for($promoter, 'promoter')
            ->create(['created_at' => '2026-02-01', 'is_active' => true]);

        Policy::factory()->vida()->withPremium(30_000)
            ->for($aux)
            ->create(['issue_date' => '2026-06-10', 'status' => Policy::STATUS_NO_TOMADA]);

        $result = $this->calculator->calculate($promoter, $scheme, $period['start'], $period['end']);

        $this->assertTrue($result['is_achieved']);
        $this->assertEquals(9.0, (float) $result['tier_data']['promoter_percentage']);
        $this->assertEquals(13_378.5, $result['amount']); // ($150K − $1,350) × 9%
    }

    /**
     * 🔴 Un Agent no puede recibir este bono (solo Promoters).
     */
    public function test_agent_cannot_receive_monthly_development_bonus(): void
    {
        $scheme = $this->createMonthlyDevelopmentScheme();
        $agent  = Agent::factory()->create();
        $period = $this->defaultPeriod();

        $result = $this->calculator->calculate($agent, $scheme, $period['start'], $period['end']);

        $this->assertFalse($result['is_achieved']);
        $this->assertStringContainsString('Promotor', $result['details']['reason'] ?? '');
    }

    /**
     * 🔴 Promoter sin agentes activos → no aplica.
     */
    public function test_promoter_with_no_active_agents_fails(): void
    {
        $scheme   = $this->createMonthlyDevelopmentScheme();
        $promoter = Promoter::factory()->create();
        $period   = $this->defaultPeriod();

        $result = $this->calculator->calculate($promoter, $scheme, $period['start'], $period['end']);

        $this->assertFalse($result['is_achieved']);
        $this->assertStringContainsString('agentes activos', $result['details']['reason'] ?? '');
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 🔧 HELPERS
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Crea el esquema «Bono de Desarrollo Mensual» con reglas globales y
     * 4 tiers basados en antigüedad del agente × PCA.
     */
    protected function createMonthlyDevelopmentScheme(): Scheme
    {
        $scheme = Scheme::create([
            'name'                     => 'monthly_development',
            'type'                     => 'bonus',
            'target'                   => 'promoter',
            'is_active'                => true,
            'metric_base'              => 'PCA',
            'frequency'                => 'mensual',
            'min_collection_efficiency' => self::MIN_COLLECTION_EFFICIENCY,
            'quarterly_recruits'       => [
                1 => self::Q1_QUOTA,
                2 => self::Q2_QUOTA,
                3 => self::Q3_QUOTA,
                4 => self::Q4_QUOTA,
            ],
            'requires_product'         => null,
            'min_product_count'        => 0,
        ]);

        $version = SchemeVersion::create([
            'scheme_id'    => $scheme->id,
            'version_name' => 'v1.0 — Desarrollo Mensual',
            'starts_at'    => '2026-01-01',
            'ends_at'      => '2026-12-31',
        ]);

        $tiers = [
            // [min_pca, min_month, max_month, promoter_pct]
            [125_000, 1,  12,  9.0],   // Año 1, PCA base
            [400_000, 1,  12, 15.0],   // Año 1, PCA alto
            [125_000, 13, 24,  0.0],   // Año 2, PCA base → 0%
            [400_000, 13, 24,  8.0],   // Año 2, PCA alto
        ];

        foreach ($tiers as [$minPca, $minMonth, $maxMonth, $pct]) {
            SchemeTier::create([
                'scheme_version_id'   => $version->id,
                'conditions'          => [
                    'min_pca'   => $minPca,
                    'min_month' => $minMonth,
                    'max_month' => $maxMonth,
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
