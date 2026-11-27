<?php

namespace Tests\Feature\BonusCalculators;

use App\Models\Agent;
use App\Models\Policy;
use App\Models\Promoter;
use App\Models\Scheme;
use App\Models\SchemeVersion;
use App\Models\SchemeTier;
use App\Services\BonusCalculators\ConnectionBonusCalculator;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Suite de pruebas para el Bono de Conexión Mensual (Connection Bonus).
 *
 * REGLAS DE NEGOCIO:
 *   1. Los agentes se acumulan durante el año (reclutados del 1 de enero al
 *      cierre del periodo).
 *   2. Cada agente califica con su PCA del periodo si alcanza el PCA mínimo.
 *   3. La cantidad de agentes calificados define la franja (1-2, 3-4, 5+).
 *   4. El pago es individual: cada agente paga su PCA × el % de su columna.
 *
 * Matriz de tiers:
 *   ┌───────────────┬────────────┬────────────┐
 *   │ Agentes       │ PCA ≥125K  │ PCA ≥250K  │
 *   ├───────────────┼────────────┼────────────┤
 *   │ 1–2 agentes   │    9%      │    11%     │
 *   │ 3–4 agentes   │   11%      │    13%     │
 *   │ 5+ agentes    │   12%      │    14%     │
 *   └───────────────┴────────────┴────────────┘
 */
class ConnectionBonusCalculatorTest extends TestCase
{
    use RefreshDatabase;

    protected ConnectionBonusCalculator $calculator;

    // ─── Constantes de la matriz ──────────────────────────────────────────

    protected const BASE_PCA_THRESHOLD  = 125_000;
    protected const HIGH_PCA_THRESHOLD  = 250_000;

    protected const PCT_1_2_BASE  = 9.0;
    protected const PCT_1_2_HIGH  = 11.0;
    protected const PCT_3_4_BASE  = 11.0;
    protected const PCT_3_4_HIGH  = 13.0;
    protected const PCT_5_PLUS_BASE = 12.0;
    protected const PCT_5_PLUS_HIGH = 14.0;

    /** PCA resultante de una póliza Vida de $130,000 (premium − $1,350). */
    protected const PCA_BASE = 128_650.0;

    /** PCA resultante de una póliza Vida de $260,000 (premium − $1,350). */
    protected const PCA_HIGH = 258_650.0;

    // ────────────────────────────────────────────────────────────────────

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-06-15'));
        $this->calculator = new ConnectionBonusCalculator();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow(null);
        parent::tearDown();
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 🟢 PAGO INDIVIDUAL
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * 🟢 1 agente con $128,650 PCA → franja 1-2, columna base → 9%.
     */
    public function test_single_recruit_at_base_pca_gets_nine_percent(): void
    {
        $scheme   = $this->createMatrixScheme();
        $promoter = Promoter::factory()->create();
        $period   = $this->defaultPeriod();

        $recruit = $this->createRecruit($promoter, '2026-03-01');
        $this->createPaidPolicy($recruit, 130_000, '2026-04-01');

        $result = $this->calculator->calculate($promoter, $scheme, $period['start'], $period['end']);

        $this->assertTrue($result['is_achieved'], 'Debió alcanzarse. Razón: '.($result['details']['reason'] ?? '—'));
        $this->assertEquals(11_578.5, $result['amount']);

        $breakdown = $result['details']['agent_breakdown'];
        $this->assertCount(1, $breakdown);
        $this->assertEquals(self::PCA_BASE, $breakdown[0]['pca']);
        $this->assertEquals(self::PCT_1_2_BASE, $breakdown[0]['percentage']);
        $this->assertEquals(11_578.5, $breakdown[0]['amount']);
    }

    /**
     * 🟢 1 agente con $258,650 PCA → franja 1-2, columna alta → 11%.
     */
    public function test_single_recruit_at_high_pca_gets_eleven_percent(): void
    {
        $scheme   = $this->createMatrixScheme();
        $promoter = Promoter::factory()->create();
        $period   = $this->defaultPeriod();

        $recruit = $this->createRecruit($promoter, '2026-03-01');
        $this->createPaidPolicy($recruit, 260_000, '2026-04-01');

        $result = $this->calculator->calculate($promoter, $scheme, $period['start'], $period['end']);

        $this->assertTrue($result['is_achieved']);
        $this->assertEquals(28_451.5, $result['amount']); // 258,650 × 11%
        $this->assertEquals(self::PCT_1_2_HIGH, $result['details']['agent_breakdown'][0]['percentage']);
    }

    /**
     * 🟢 Cada agente se paga con su propio porcentaje (ejemplo de negocio).
     *
     * 4 agentes → franja 3-4 (base 11%, alta 13%).
     * Agente A: PCA $128,650 → 11% = $14,151.50
     * Agentes B, C, D: PCA $258,650 → 13% = $33,624.50 cada uno
     * Total = $115,025.00
     */
    public function test_each_agent_is_paid_individually_with_its_own_percentage(): void
    {
        $scheme   = $this->createMatrixScheme();
        $promoter = Promoter::factory()->create();
        $period   = $this->defaultPeriod();

        $baseAgent = $this->createRecruit($promoter, '2026-02-01', 'Agente Base');
        $this->createPaidPolicy($baseAgent, 130_000, '2026-03-01');

        foreach (range(1, 3) as $i) {
            $highAgent = $this->createRecruit($promoter, '2026-02-0'.($i + 1), "Agente Alto {$i}");
            $this->createPaidPolicy($highAgent, 260_000, '2026-03-02');
        }

        $result = $this->calculator->calculate($promoter, $scheme, $period['start'], $period['end']);

        $this->assertTrue($result['is_achieved'], 'Debió alcanzarse. Razón: '.($result['details']['reason'] ?? '—'));
        $this->assertEquals(115_025.0, $result['amount']);
        $this->assertEquals(4, $result['details']['qualified_recruits']);

        $breakdown = collect($result['details']['agent_breakdown']);

        $baseRow = $breakdown->firstWhere('agent_name', 'Agente Base');
        $this->assertEquals(self::PCT_3_4_BASE, $baseRow['percentage']);
        $this->assertEquals(14_151.5, $baseRow['amount']);

        $highRows = $breakdown->where('percentage', self::PCT_3_4_HIGH);
        $this->assertCount(3, $highRows);
        foreach ($highRows as $row) {
            $this->assertEquals(33_624.5, $row['amount']);
        }
    }

    /**
     * 🟢 5 agentes con PCA base → franja 5+ → 12% cada uno.
     */
    public function test_five_agents_at_base_pca_get_twelve_percent_each(): void
    {
        $scheme   = $this->createMatrixScheme();
        $promoter = Promoter::factory()->create();
        $period   = $this->defaultPeriod();

        foreach (range(1, 5) as $i) {
            $agent = $this->createRecruit($promoter, '2026-02-0'.$i);
            $this->createPaidPolicy($agent, 130_000, '2026-03-01');
        }

        $result = $this->calculator->calculate($promoter, $scheme, $period['start'], $period['end']);

        $this->assertTrue($result['is_achieved']);
        $this->assertEquals(77_190.0, $result['amount']); // 5 × 15,438
        $this->assertEquals(self::PCT_5_PLUS_BASE, $result['details']['agent_breakdown'][0]['percentage']);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 🟢 ACUMULACIÓN ANUAL DE AGENTES
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * 🟢 Los agentes reclutados antes del periodo siguen contando: 2 de
     *    febrero + 1 de octubre = 3 agentes → franja 3-4 (11%).
     */
    public function test_agents_accumulate_through_the_year(): void
    {
        $scheme   = $this->createMatrixScheme();
        $promoter = Promoter::factory()->create();

        $period = [
            'start' => Carbon::parse('2026-10-01'),
            'end'   => Carbon::parse('2026-10-31'),
        ];

        // Reclutados en el año, con venta en octubre
        foreach (['2026-02-01', '2026-03-01', '2026-10-05'] as $createdAt) {
            $agent = $this->createRecruit($promoter, $createdAt);
            $this->createPaidPolicy($agent, 130_000, '2026-10-15');
        }

        $result = $this->calculator->calculate($promoter, $scheme, $period['start'], $period['end']);

        $this->assertTrue($result['is_achieved'], 'Debió alcanzarse. Razón: '.($result['details']['reason'] ?? '—'));
        $this->assertEquals(3, $result['details']['total_recruits']);
        $this->assertEquals(3, $result['details']['qualified_recruits']);
        $this->assertEquals(42_454.5, $result['amount']); // 3 × (128,650 × 11%)
    }

    /**
     * 🔴 Un agente reclutado en un año anterior no cuenta para este año.
     */
    public function test_recruit_from_previous_year_does_not_count(): void
    {
        $scheme   = $this->createMatrixScheme();
        $promoter = Promoter::factory()->create();

        $period = [
            'start' => Carbon::parse('2026-10-01'),
            'end'   => Carbon::parse('2026-10-31'),
        ];

        $old = $this->createRecruit($promoter, '2025-11-01');
        $this->createPaidPolicy($old, 130_000, '2026-10-15');

        $current = $this->createRecruit($promoter, '2026-10-02');
        $this->createPaidPolicy($current, 130_000, '2026-10-15');

        $result = $this->calculator->calculate($promoter, $scheme, $period['start'], $period['end']);

        $this->assertTrue($result['is_achieved']);
        $this->assertEquals(1, $result['details']['total_recruits']); // solo el de 2026
        $this->assertEquals(11_578.5, $result['amount']);             // franja 1-2 → 9%
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 🟡 AGENTES QUE NO CALIFICAN
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * 🟡 Un agente debajo del PCA mínimo aparece en el desglose pero no paga
     *    ni cuenta para la franja.
     */
    public function test_agent_below_minimum_pca_is_listed_but_does_not_pay(): void
    {
        $scheme   = $this->createMatrixScheme();
        $promoter = Promoter::factory()->create();
        $period   = $this->defaultPeriod();

        $qualified = $this->createRecruit($promoter, '2026-02-01', 'Agente Califica');
        $this->createPaidPolicy($qualified, 130_000, '2026-03-01');

        $low = $this->createRecruit($promoter, '2026-02-02', 'Agente Bajo');
        $this->createPaidPolicy($low, 100_000, '2026-03-01');

        $result = $this->calculator->calculate($promoter, $scheme, $period['start'], $period['end']);

        $this->assertTrue($result['is_achieved']);
        $this->assertEquals(11_578.5, $result['amount']); // solo el calificado

        $breakdown = collect($result['details']['agent_breakdown']);
        $lowRow = $breakdown->firstWhere('agent_name', 'Agente Bajo');

        $this->assertFalse($lowRow['qualified']);
        $this->assertNull($lowRow['percentage']);
        $this->assertEquals(0.0, $lowRow['amount']);
    }

    /**
     * 🔴 Ningún agente alcanza el PCA mínimo → bono no alcanzado.
     */
    public function test_no_qualified_agents_returns_not_achieved(): void
    {
        $scheme   = $this->createMatrixScheme();
        $promoter = Promoter::factory()->create();
        $period   = $this->defaultPeriod();

        $agent = $this->createRecruit($promoter, '2026-02-01');
        $this->createPaidPolicy($agent, 100_000, '2026-03-01');

        $result = $this->calculator->calculate($promoter, $scheme, $period['start'], $period['end']);

        $this->assertFalse($result['is_achieved']);
        $this->assertEquals(0.0, $result['amount']);
        $this->assertStringContainsString('PCA mínimo', $result['details']['reason'] ?? '');
        $this->assertCount(1, $result['details']['agent_breakdown']);
    }

    /**
     * 🔴 Sin agentes reclutados en el año → bono no alcanzado.
     */
    public function test_promoter_without_recruits_returns_not_achieved(): void
    {
        $scheme   = $this->createMatrixScheme();
        $promoter = Promoter::factory()->create();
        $period   = $this->defaultPeriod();

        $result = $this->calculator->calculate($promoter, $scheme, $period['start'], $period['end']);

        $this->assertFalse($result['is_achieved']);
        $this->assertEquals(0.0, $result['amount']);
        $this->assertStringContainsString('No se encontraron agentes', $result['details']['reason'] ?? '');
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 🟡 REGLAS ADICIONALES
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * 🟡 El monto fijo del tramo se suma por cada agente que lo alcanza.
     */
    public function test_fixed_amount_is_added_per_agent(): void
    {
        $scheme = $this->createMatrixScheme(fixedAmounts: [self::PCT_1_2_BASE => 5_000]);
        $promoter = Promoter::factory()->create();
        $period   = $this->defaultPeriod();

        $recruit = $this->createRecruit($promoter, '2026-03-01');
        $this->createPaidPolicy($recruit, 130_000, '2026-04-01');

        $result = $this->calculator->calculate($promoter, $scheme, $period['start'], $period['end']);

        $this->assertTrue($result['is_achieved']);
        $this->assertEquals(16_578.5, $result['amount']); // 11,578.50 + 5,000

        $row = $result['details']['agent_breakdown'][0];
        $this->assertEquals(5_000.0, $row['fixed_amount']);
        $this->assertEquals(16_578.5, $row['amount']);
    }

    /**
     * 🟡 El monto de las pólizas Primordial no suma a la PCA del agente.
     */
    public function test_primordial_premiums_do_not_add_to_recruit_pca(): void
    {
        $scheme   = $this->createMatrixScheme();
        $promoter = Promoter::factory()->create();
        $period   = $this->defaultPeriod();

        $recruit = $this->createRecruit($promoter, '2026-03-01');
        $this->createPaidPolicy($recruit, 130_000, '2026-04-01');

        Policy::factory()->primordial()->pagada()->withPremium(500_000)
            ->for($recruit)
            ->create(['issue_date' => '2026-04-05']);

        $result = $this->calculator->calculate($promoter, $scheme, $period['start'], $period['end']);

        $this->assertTrue($result['is_achieved']);
        $this->assertEquals(11_578.5, $result['amount']);
        $this->assertEquals(self::PCA_BASE, $result['details']['agent_breakdown'][0]['pca']);
    }

    /**
     * 🔴 Un Agent no puede recibir este bono.
     */
    public function test_agent_cannot_receive_connection_bonus(): void
    {
        $scheme = $this->createMatrixScheme();
        $agent  = Agent::factory()->create();
        $period = $this->defaultPeriod();

        $result = $this->calculator->calculate($agent, $scheme, $period['start'], $period['end']);

        $this->assertFalse($result['is_achieved']);
        $this->assertStringContainsString('Promotores', $result['details']['reason'] ?? '');
    }

    /**
     * 🔴 Solo las pólizas Pagada aportan PCA.
     */
    public function test_non_paid_policies_do_not_count(): void
    {
        $scheme   = $this->createMatrixScheme();
        $promoter = Promoter::factory()->create();
        $period   = $this->defaultPeriod();

        $recruit = $this->createRecruit($promoter, '2026-03-01');

        Policy::factory()->vida()->withPremium(130_000)
            ->for($recruit)
            ->create(['issue_date' => '2026-04-01', 'status' => Policy::STATUS_ACTIVA]);

        $result = $this->calculator->calculate($promoter, $scheme, $period['start'], $period['end']);

        $this->assertFalse($result['is_achieved']);
        $this->assertEquals(0.0, $result['details']['agent_breakdown'][0]['pca']);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 🔧 HELPERS
    // ═══════════════════════════════════════════════════════════════════════

    private function createRecruit(Promoter $promoter, string $createdAt, ?string $name = null): Agent
    {
        $attributes = ['created_at' => $createdAt, 'is_active' => true];

        if ($name !== null) {
            $attributes['name'] = $name;
        }

        return Agent::factory()->for($promoter, 'promoter')->create($attributes);
    }

    private function createPaidPolicy(Agent $agent, float $premium, string $issueDate): Policy
    {
        return Policy::factory()->vida()->pagada()->withPremium($premium)
            ->for($agent)
            ->create(['issue_date' => $issueDate]);
    }

    /**
     * Crea el esquema «Conexión Mensual» con la matriz de 6 tramos.
     *
     * @param  array<float, float>  $fixedAmounts  [porcentaje => monto fijo]
     */
    protected function createMatrixScheme(array $fixedAmounts = []): Scheme
    {
        $scheme = Scheme::create([
            'name'              => 'connection',
            'type'              => 'bonus',
            'target'            => 'promoter',
            'is_active'         => true,
            'metric_base'       => 'PCA',
            'frequency'         => 'mensual',
            'requires_product'  => null,
            'min_product_count' => 0,
        ]);

        $version = SchemeVersion::create([
            'scheme_id'    => $scheme->id,
            'version_name' => 'v1.0 — Conexión Mensual',
            'starts_at'    => '2026-01-01',
            'ends_at'      => '2026-12-31',
        ]);

        $tiers = [
            // [min_recruits, max_recruits, min_pca, promoter_percentage]
            [1, 2, self::BASE_PCA_THRESHOLD, self::PCT_1_2_BASE],       // 9%
            [1, 2, self::HIGH_PCA_THRESHOLD, self::PCT_1_2_HIGH],       // 11%
            [3, 4, self::BASE_PCA_THRESHOLD, self::PCT_3_4_BASE],       // 11%
            [3, 4, self::HIGH_PCA_THRESHOLD, self::PCT_3_4_HIGH],       // 13%
            [5, null, self::BASE_PCA_THRESHOLD, self::PCT_5_PLUS_BASE], // 12%
            [5, null, self::HIGH_PCA_THRESHOLD, self::PCT_5_PLUS_HIGH], // 14%
        ];

        foreach ($tiers as [$minR, $maxR, $minPca, $pct]) {
            SchemeTier::create([
                'scheme_version_id'   => $version->id,
                'conditions'          => [
                    'min_recruits' => $minR,
                    'max_recruits' => $maxR,
                    'min_pca'      => $minPca,
                ],
                'agent_percentage'    => 0,
                'promoter_percentage' => $pct,
                'fixed_amount'        => $fixedAmounts[$pct] ?? null,
            ]);
        }

        return $scheme->fresh(['versions.tiers']);
    }

    /**
     * Periodo por defecto para las pruebas: año 2026 completo.
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
