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
 * REGLA DE NEGOCIO — MATRIZ DE DOBLE ENTRADA:
 *   1. Cada recluta debe cumplir INDIVIDUALMENTE el PCA mínimo del tier.
 *   2. El porcentaje se determina por el recluta con MAYOR PCA individual.
 *   3. La franja de reclutas (1-2, 3-4, 5+) + PCA ($125K / $250K) define el %.
 *
 * Matriz de tiers:
 *   ┌───────────────┬────────────┬────────────┐
 *   │ Reclutas      │ PCA ≥125K  │ PCA ≥250K  │
 *   ├───────────────┼────────────┼────────────┤
 *   │ 1–2 reclutas  │    9%      │    11%     │
 *   │ 3–4 reclutas  │   11%      │    13%     │
 *   │ 5+ reclutas   │   12%      │    14%     │
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
    // 🟢 TESTS DE ÉXITO
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * 🟢 1 recluta con $130,000 PCA → franja 1-2, PCA base → 9%.
     *
     * Monto esperado: $130,000 × 9% = $11,700.
     */
    public function test_promoter_gets_9_percent_with_one_recruit_at_base_pca(): void
    {
        $scheme   = $this->createMatrixScheme();
        $promoter = Promoter::factory()->create();
        $period   = $this->defaultPeriod();

        // 1 recluta con $130,000 PCA (supera $125K por $5,000)
        $recruit = Agent::factory()
            ->for($promoter, 'promoter')
            ->create(['created_at' => '2026-03-01']);

        Policy::factory()->vida()->withPremium(130_000)
            ->for($recruit)
            ->create(['issue_date' => '2026-04-01']);

        $result = $this->calculator->calculate($promoter, $scheme, $period['start'], $period['end']);

        $this->assertTrue($result['is_achieved'], 'Debió alcanzarse. Razón: ' . ($result['details']['reason'] ?? '—'));

        // 9% sobre $130,000
        $this->assertEquals(11_700.0, $result['amount']);
        $this->assertEquals(self::PCT_1_2_BASE, (float) $result['tier_data']['promoter_percentage']);
    }

    /**
     * 🟢 5 reclutas, al menos uno tiene $260,000 PCA → franja 5+, PCA alto → 14%.
     *
     * Los otros 4 reclutas tienen exactamente $125,000 cada uno.
     * Monto esperado: ($260K + 4×$125K) × 14% = $760,000 × 14% = $106,400.
     */
    public function test_promoter_gets_14_percent_with_five_recruits_at_highest_pca(): void
    {
        $scheme   = $this->createMatrixScheme();
        $promoter = Promoter::factory()->create();
        $period   = $this->defaultPeriod();

        // 4 reclutas con PCA base ($125,000)
        for ($i = 0; $i < 4; $i++) {
            $agent = Agent::factory()
                ->for($promoter, 'promoter')
                ->create(['created_at' => '2026-02-01']);

            Policy::factory()->vida()->withPremium(125_000)
                ->for($agent)
                ->create(['issue_date' => '2026-03-01']);
        }

        // 1 recluta estrella con $260,000 PCA
        $star = Agent::factory()
            ->for($promoter, 'promoter')
            ->create(['created_at' => '2026-02-01']);

        Policy::factory()->vida()->withPremium(260_000)
            ->for($star)
            ->create(['issue_date' => '2026-03-15']);

        $result = $this->calculator->calculate($promoter, $scheme, $period['start'], $period['end']);

        $this->assertTrue($result['is_achieved']);

        $totalPca = 4 * 125_000 + 260_000; // $760,000
        $expectedAmount = round($totalPca * (self::PCT_5_PLUS_HIGH / 100), 2); // $106,400

        $this->assertEquals($expectedAmount, $result['amount']);
        $this->assertEquals(self::PCT_5_PLUS_HIGH, (float) $result['tier_data']['promoter_percentage']);
        $this->assertEquals(5, $result['details']['qualified_recruits']);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 🔴 TESTS DE FALLO / COMPORTAMIENTO CRÍTICO
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * 🔴 3 reclutas, pero uno solo tiene $100,000 PCA (debajo del umbral base).
     *
     * Comportamiento esperado: el recluta de $100K NO califica.
     * Solo 2 reclutas califican → franja 1-2, PCA base → 9%.
     */
    public function test_fails_if_any_recruit_is_below_minimum_pca_threshold(): void
    {
        $scheme   = $this->createMatrixScheme();
        $promoter = Promoter::factory()->create();
        $period   = $this->defaultPeriod();

        // Recluta 1: $130,000 PCA — SÍ califica
        $ok1 = Agent::factory()->for($promoter, 'promoter')
            ->create(['created_at' => '2026-03-01']);
        Policy::factory()->vida()->withPremium(130_000)
            ->for($ok1)->create(['issue_date' => '2026-04-01']);

        // Recluta 2: $150,000 PCA — SÍ califica
        $ok2 = Agent::factory()->for($promoter, 'promoter')
            ->create(['created_at' => '2026-03-01']);
        Policy::factory()->vida()->withPremium(150_000)
            ->for($ok2)->create(['issue_date' => '2026-04-01']);

        // Recluta 3: $100,000 PCA — NO califica (por debajo de $125K)
        $bad = Agent::factory()->for($promoter, 'promoter')
            ->create(['created_at' => '2026-03-01']);
        Policy::factory()->vida()->withPremium(100_000)
            ->for($bad)->create(['issue_date' => '2026-04-01']);

        $result = $this->calculator->calculate($promoter, $scheme, $period['start'], $period['end']);

        // El bono SÍ se alcanza, pero solo con 2 reclutas calificados → 9%
        $this->assertTrue($result['is_achieved'], 'Debió alcanzarse con solo 2 reclutas calificados.');
        $this->assertEquals(2, $result['details']['qualified_recruits'], 'Solo 2 reclutas debieron calificar.');
        $this->assertEquals(self::PCT_1_2_BASE, (float) $result['tier_data']['promoter_percentage']);

        // Monto = ($130K + $150K) × 9% = $25,200
        $this->assertEquals(25_200.0, $result['amount']);
    }

    /**
     * 🔴 Un agente NO puede recibir este bono (solo aplica a Promoters).
     */
    public function test_agent_cannot_receive_connection_bonus(): void
    {
        $scheme = $this->createMatrixScheme();
        $agent  = Agent::factory()->create();
        $period = $this->defaultPeriod();

        $result = $this->calculator->calculate($agent, $scheme, $period['start'], $period['end']);

        $this->assertFalse($result['is_achieved']);
        $this->assertStringContainsString('Promotor', $result['details']['reason'] ?? '');
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 🟡 TESTS DE PRECISIÓN DE LA MATRIZ
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * 🟡 2 reclutas: uno con $130K y otro con $260K.
     *
     * - Franja: 1–2 reclutas.
     * - PCA más alto: $260K ≥ $250K → umbral alto.
     * - Porcentaje: 11% (NO 9%).
     */
    public function test_percentage_is_based_on_recruit_with_highest_pca(): void
    {
        $scheme   = $this->createMatrixScheme();
        $promoter = Promoter::factory()->create();
        $period   = $this->defaultPeriod();

        // Recluta base: $130,000
        $base = Agent::factory()->for($promoter, 'promoter')
            ->create(['created_at' => '2026-03-01']);
        Policy::factory()->vida()->withPremium(130_000)
            ->for($base)->create(['issue_date' => '2026-04-01']);

        // Recluta estrella: $260,000
        $star = Agent::factory()->for($promoter, 'promoter')
            ->create(['created_at' => '2026-03-01']);
        Policy::factory()->vida()->withPremium(260_000)
            ->for($star)->create(['issue_date' => '2026-04-01']);

        $result = $this->calculator->calculate($promoter, $scheme, $period['start'], $period['end']);

        $this->assertTrue($result['is_achieved']);

        // Debe ser 11%, NO 9%
        $this->assertEquals(self::PCT_1_2_HIGH, (float) $result['tier_data']['promoter_percentage']);
        $this->assertNotEquals(self::PCT_1_2_BASE, (float) $result['tier_data']['promoter_percentage']);

        // Monto = ($130K + $260K) × 11% = $390K × 11% = $42,900
        $this->assertEquals(42_900.0, $result['amount']);
    }

    /**
     * 🟡 Verifica que el fixed_amount de graduación se SUMA al monto porcentual.
     *
     * Usamos el tier de 1-2 reclutas con PCA base (9%) y le agregamos
     * un fixed_amount de $5,000 por bono de graduación.
     *
     * Monto esperado: ($130,000 × 9%) + $5,000 = $11,700 + $5,000 = $16,700.
     */
    public function test_includes_graduation_and_excellence_fixed_bonuses(): void
    {
        // ── Arrange: crear esquema con fixed_amount en el tier ──────────
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

        // Tier 1-2 base (9%) + $5,000 fijo de graduación
        SchemeTier::create([
            'scheme_version_id'   => $version->id,
            'conditions'          => ['min_recruits' => 1, 'max_recruits' => 2, 'min_pca' => self::BASE_PCA_THRESHOLD],
            'agent_percentage'    => 0,
            'promoter_percentage' => self::PCT_1_2_BASE,     // 9%
            'fixed_amount'        => 5_000,                   // Bono de graduación
        ]);

        // Tier 1-2 alto (11%)
        SchemeTier::create([
            'scheme_version_id'   => $version->id,
            'conditions'          => ['min_recruits' => 1, 'max_recruits' => 2, 'min_pca' => self::HIGH_PCA_THRESHOLD],
            'agent_percentage'    => 0,
            'promoter_percentage' => self::PCT_1_2_HIGH,
            'fixed_amount'        => null,
        ]);

        $scheme->loadMissing(['versions.tiers']);

        $promoter = Promoter::factory()->create();
        $period   = $this->defaultPeriod();

        // 1 recluta con $130,000 PCA
        $recruit = Agent::factory()->for($promoter, 'promoter')
            ->create(['created_at' => '2026-03-01']);
        Policy::factory()->vida()->withPremium(130_000)
            ->for($recruit)->create(['issue_date' => '2026-04-01']);

        // ── Act ────────────────────────────────────────────────────────
        $result = $this->calculator->calculate($promoter, $scheme, $period['start'], $period['end']);

        // ── Assert ─────────────────────────────────────────────────────
        $this->assertTrue($result['is_achieved']);

        // $130,000 × 9% = $11,700 + $5,000 fijo = $16,700
        $this->assertEquals(16_700.0, $result['amount']);
        $this->assertEquals(9.0, (float) $result['tier_data']['promoter_percentage']);
        $this->assertEquals(5_000.0, (float) $result['tier_data']['fixed_amount']);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 🟡 TESTS ADICIONALES DE COBERTURA DE MATRIZ
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * 🟡 3 reclutas, todos en PCA base ($125K-$150K) → franja 3-4, base → 11%.
     */
    public function test_three_recruits_at_base_pca_get_11_percent(): void
    {
        $scheme   = $this->createMatrixScheme();
        $promoter = Promoter::factory()->create();
        $period   = $this->defaultPeriod();

        for ($i = 0; $i < 3; $i++) {
            $agent = Agent::factory()->for($promoter, 'promoter')
                ->create(['created_at' => '2026-03-01']);
            Policy::factory()->vida()->withPremium(140_000)
                ->for($agent)->create(['issue_date' => '2026-04-01']);
        }

        $result = $this->calculator->calculate($promoter, $scheme, $period['start'], $period['end']);

        $this->assertTrue($result['is_achieved']);
        $this->assertEquals(self::PCT_3_4_BASE, (float) $result['tier_data']['promoter_percentage']);
        $this->assertEquals(3, $result['details']['qualified_recruits']);
        // $420,000 × 11% = $46,200
        $this->assertEquals(46_200.0, $result['amount']);
    }

    /**
     * 🟡 4 reclutas, uno con PCA alto → franja 3-4, alto → 13%.
     */
    public function test_four_recruits_with_one_high_pca_get_13_percent(): void
    {
        $scheme   = $this->createMatrixScheme();
        $promoter = Promoter::factory()->create();
        $period   = $this->defaultPeriod();

        // 3 base
        for ($i = 0; $i < 3; $i++) {
            $agent = Agent::factory()->for($promoter, 'promoter')
                ->create(['created_at' => '2026-03-01']);
            Policy::factory()->vida()->withPremium(130_000)
                ->for($agent)->create(['issue_date' => '2026-04-01']);
        }

        // 1 alto
        $star = Agent::factory()->for($promoter, 'promoter')
            ->create(['created_at' => '2026-03-01']);
        Policy::factory()->vida()->withPremium(300_000)
            ->for($star)->create(['issue_date' => '2026-04-01']);

        $result = $this->calculator->calculate($promoter, $scheme, $period['start'], $period['end']);

        $this->assertTrue($result['is_achieved']);
        $this->assertEquals(self::PCT_3_4_HIGH, (float) $result['tier_data']['promoter_percentage']);
        // (3×$130K + $300K) × 13% = $690K × 13% = $89,700
        $this->assertEquals(89_700.0, $result['amount']);
    }

    /**
     * 🟡 5 reclutas en PCA base → franja 5+, base → 12%.
     */
    public function test_five_recruits_at_base_pca_get_12_percent(): void
    {
        $scheme   = $this->createMatrixScheme();
        $promoter = Promoter::factory()->create();
        $period   = $this->defaultPeriod();

        for ($i = 0; $i < 5; $i++) {
            $agent = Agent::factory()->for($promoter, 'promoter')
                ->create(['created_at' => '2026-03-01']);
            Policy::factory()->vida()->withPremium(130_000)
                ->for($agent)->create(['issue_date' => '2026-04-01']);
        }

        $result = $this->calculator->calculate($promoter, $scheme, $period['start'], $period['end']);

        $this->assertTrue($result['is_achieved']);
        $this->assertEquals(self::PCT_5_PLUS_BASE, (float) $result['tier_data']['promoter_percentage']);
        // 5 × $130K × 12% = $650K × 12% = $78,000
        $this->assertEquals(78_000.0, $result['amount']);
    }

    /**
     * 🔴 Promoter sin ningún recluta en el periodo → no aplica.
     */
    public function test_promoter_with_zero_recruits_fails(): void
    {
        $scheme   = $this->createMatrixScheme();
        $promoter = Promoter::factory()->create();
        $period   = $this->defaultPeriod();

        $result = $this->calculator->calculate($promoter, $scheme, $period['start'], $period['end']);

        $this->assertFalse($result['is_achieved']);
        $this->assertEquals(0, $result['progress']['current_value']);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 🔧 HELPERS DE CONSTRUCCIÓN DE DATOS
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Crea el esquema completo «Bono de Conexión Mensual» con la matriz
     * de 6 tiers (3 franjas × 2 umbrales PCA).
     */
    protected function createMatrixScheme(): Scheme
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
                'fixed_amount'        => null,
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
