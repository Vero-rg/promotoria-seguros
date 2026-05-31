<?php

namespace Tests\Feature\BonusCalculators;

use App\Models\Agent;
use App\Models\Policy;
use App\Models\Promoter;
use App\Models\Scheme;
use App\Models\SchemeVersion;
use App\Models\SchemeTier;
use App\Services\BonusCalculators\PromoterFirstYearProductionCalculator;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Suite de pruebas para el Bono de Producción 1.er Año Trimestral (Promotor).
 *
 * REGLAS DE NEGOCIO:
 *   1. Doble condición PP + IRP: el tier se elige por PP >= min_pp Y IRP en [min_irp, max_irp].
 *   2. IRP global mínimo: si IRP < 91%, el bono falla automáticamente.
 *   3. Cuota trimestral de reclutamiento: Q1=2, Q2=3, Q3=4, Q4=6.
 *   4. Requisito de producto: mínimo 3 pólizas "Primordial" en el trimestre.
 *
 * Matriz de tiers:
 *   ┌──────────────┬─────────────────┬──────────────────┐
 *   │     PP       │ IRP 91–93.99%   │ IRP ≥ 94%        │
 *   ├──────────────┼─────────────────┼──────────────────┤
 *   │ ≥ $255,000   │      10%        │       14%        │
 *   │ ≥ $555,000   │      18%        │       22%        │
 *   └──────────────┴─────────────────┴──────────────────┘
 */
class PromoterFirstYearProductionCalculatorTest extends TestCase
{
    use RefreshDatabase;

    protected PromoterFirstYearProductionCalculator $calculator;

    // ─── Constantes ──────────────────────────────────────────────────────

    protected const GLOBAL_MIN_IRP = 91;

    protected const Q1_QUOTA = 2;
    protected const Q2_QUOTA = 3;
    protected const Q3_QUOTA = 4;
    protected const Q4_QUOTA = 6;

    // ────────────────────────────────────────────────────────────────────

    protected function setUp(): void
    {
        parent::setUp();

        // Fijar «now» en Q4 (octubre 2026) para la mayoría de pruebas
        Carbon::setTestNow(Carbon::parse('2026-10-15'));

        $this->calculator = new PromoterFirstYearProductionCalculator();
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
     * 🟢 Promotor con $600K PP, IRP 95%, 6 reclutas (Q4), 4 Primordial.
     *
     * PP ≥ $555K, IRP ≥ 94% → Tier máximo: 22%.
     * Monto: $600,000 × 22% = $132,000.
     */
    public function test_promoter_achieves_max_tier_with_high_pp_and_excellent_irp(): void
    {
        $scheme   = $this->createProductionScheme();
        $promoter = Promoter::factory()->create();
        $period   = $this->defaultPeriod();

        // ── 6 reclutas para Q4 ─────────────────────────────────────────
        $agents = $this->createRecruits($promoter, 6);

        // ── 4 pólizas Primordial ($400K, todas activas) ────────────────
        foreach (range(0, 3) as $i) {
            Policy::factory()->primordial()->withPremium(100_000)
                ->for($agents[$i])
                ->create(['issue_date' => '2026-08-01', 'status' => Policy::STATUS_ACTIVA]);
        }

        // ── PP extra: $170K activa + $30K cancelada ────────────────────
        // PP total = $400K + $170K + $30K = $600K
        // Activo = $400K + $170K = $570K → IRP = 570/600 = 95%
        Policy::factory()->vida()->withPremium(170_000)
            ->for($agents[4])
            ->create(['issue_date' => '2026-08-15', 'status' => Policy::STATUS_ACTIVA]);

        Policy::factory()->vida()->withPremium(30_000)
            ->for($agents[5])
            ->create(['issue_date' => '2026-09-01', 'status' => Policy::STATUS_CANCELADA]);

        // ── Act ────────────────────────────────────────────────────────
        $result = $this->calculator->calculate($promoter, $scheme, $period['start'], $period['end']);

        // ── Assert ─────────────────────────────────────────────────────
        $this->assertTrue($result['is_achieved'], 'Debió alcanzarse. Razón: ' . ($result['details']['reason'] ?? '—'));

        $this->assertEquals(132_000.0, $result['amount']);
        $this->assertEquals(22.0, (float) $result['tier_data']['promoter_percentage']);
        $this->assertEquals(600_000.0, $result['progress']['current_value']);
    }

    /**
     * 🟢 Misma PP ($600K) pero IRP de 92% → cae en la franja 91–93.99% → 18%.
     *
     * Monto: $600,000 × 18% = $108,000.
     */
    public function test_promoter_gets_lower_percentage_in_same_pp_tier_due_to_lower_irp(): void
    {
        $scheme   = $this->createProductionScheme();
        $promoter = Promoter::factory()->create();
        $period   = $this->defaultPeriod();

        $agents = $this->createRecruits($promoter, 6);

        // ── 4 pólizas Primordial ($400K activas) ───────────────────────
        foreach (range(0, 3) as $i) {
            Policy::factory()->primordial()->withPremium(100_000)
                ->for($agents[$i])
                ->create(['issue_date' => '2026-08-01', 'status' => Policy::STATUS_ACTIVA]);
        }

        // ── Para IRP = 92% con PP = $600K ──────────────────────────────
        // Activo = 92% de $600K = $552K. Cancelado = $48K.
        // Ya tenemos $400K activo (Primordial). Necesitamos $152K activo + $48K cancelado.
        Policy::factory()->vida()->withPremium(152_000)
            ->for($agents[4])
            ->create(['issue_date' => '2026-08-15', 'status' => Policy::STATUS_ACTIVA]);

        Policy::factory()->vida()->withPremium(48_000)
            ->for($agents[5])
            ->create(['issue_date' => '2026-09-01', 'status' => Policy::STATUS_CANCELADA]);

        // IRP = (400K + 152K) / 600K = 552/600 = 92% ✓

        $result = $this->calculator->calculate($promoter, $scheme, $period['start'], $period['end']);

        $this->assertTrue($result['is_achieved']);
        $this->assertEquals(108_000.0, $result['amount']);
        $this->assertEquals(18.0, (float) $result['tier_data']['promoter_percentage']);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 🔴 TESTS DE FALLO
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * 🔴 Q4 requiere 6 reclutas, pero el promotor solo tiene 5.
     *    PP, IRP y pólizas Primordial están OK.
     */
    public function test_promoter_fails_bonus_due_to_missing_recruitment_goal(): void
    {
        $scheme   = $this->createProductionScheme();
        $promoter = Promoter::factory()->create();
        $period   = $this->defaultPeriod();

        // ── Solo 5 reclutas (Q4 necesita 6) ────────────────────────────
        $agents = $this->createRecruits($promoter, 5);

        // ── PP e IRP excelentes ────────────────────────────────────────
        foreach (range(0, 3) as $i) {
            Policy::factory()->primordial()->withPremium(150_000)
                ->for($agents[$i])
                ->create(['issue_date' => '2026-08-01', 'status' => Policy::STATUS_ACTIVA]);
        }

        $result = $this->calculator->calculate($promoter, $scheme, $period['start'], $period['end']);

        $this->assertFalse($result['is_achieved'], 'Debió fallar por no cumplir cuota Q4.');
        $this->assertStringContainsString('Q4', $result['details']['reason'] ?? '');
        $this->assertStringContainsString('requiere 6', $result['details']['reason'] ?? '');
    }

    /**
     * 🔴 Solo 2 pólizas Primordial — se requieren 3 (min_product_count = 3).
     *    PP, IRP y reclutas están OK.
     */
    public function test_promoter_fails_bonus_due_to_missing_required_policies(): void
    {
        $scheme   = $this->createProductionScheme();
        $promoter = Promoter::factory()->create();
        $period   = $this->defaultPeriod();

        $agents = $this->createRecruits($promoter, 6);

        // ── Solo 2 pólizas Primordial ($300K activas) ──────────────────
        Policy::factory()->primordial()->withPremium(150_000)
            ->for($agents[0])
            ->create(['issue_date' => '2026-08-01', 'status' => Policy::STATUS_ACTIVA]);

        Policy::factory()->primordial()->withPremium(150_000)
            ->for($agents[1])
            ->create(['issue_date' => '2026-08-01', 'status' => Policy::STATUS_ACTIVA]);

        // ── Resto de PP con otras pólizas para cumplir PP e IRP ────────
        Policy::factory()->vida()->withPremium(300_000)
            ->for($agents[2])
            ->create(['issue_date' => '2026-08-15', 'status' => Policy::STATUS_ACTIVA]);

        // PP = $600K, activo = $600K → IRP = 100%

        $result = $this->calculator->calculate($promoter, $scheme, $period['start'], $period['end']);

        $this->assertFalse($result['is_achieved'], 'Debió fallar por falta de pólizas Primordial.');
        $this->assertStringContainsString('Primordial', $result['details']['reason'] ?? '');
    }

    /**
     * 🔴 IRP de 89% — por debajo del mínimo global de 91%.
     *    Todo lo demás (PP, reclutas, pólizas) está OK.
     */
    public function test_promoter_fails_completely_if_irp_is_below_minimum(): void
    {
        $scheme   = $this->createProductionScheme();
        $promoter = Promoter::factory()->create();
        $period   = $this->defaultPeriod();

        $agents = $this->createRecruits($promoter, 6);

        // ── 4 Primordial ($400K activas) ───────────────────────────────
        foreach (range(0, 3) as $i) {
            Policy::factory()->primordial()->withPremium(100_000)
                ->for($agents[$i])
                ->create(['issue_date' => '2026-08-01', 'status' => Policy::STATUS_ACTIVA]);
        }

        // ── IRP = 89% con PP = $600K ───────────────────────────────────
        // Activo = 89% de $600K = $534K. Cancelado = $66K.
        // Ya tenemos $400K activo → necesitamos $134K activo + $66K cancelado.
        Policy::factory()->vida()->withPremium(134_000)
            ->for($agents[4])
            ->create(['issue_date' => '2026-08-15', 'status' => Policy::STATUS_ACTIVA]);

        Policy::factory()->vida()->withPremium(66_000)
            ->for($agents[5])
            ->create(['issue_date' => '2026-09-01', 'status' => Policy::STATUS_CANCELADA]);

        // IRP = (400K + 134K) / 600K = 534/600 = 89% < 91%

        $result = $this->calculator->calculate($promoter, $scheme, $period['start'], $period['end']);

        $this->assertFalse($result['is_achieved'], 'Debió fallar por IRP debajo del mínimo global.');
        $this->assertStringContainsString('IRP', $result['details']['reason'] ?? '');
        $this->assertEquals(89.0, $result['details']['irp']);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 🟡 TESTS ADICIONALES
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * 🟡 PP base ($300K) con IRP 95% → Tier 14% (PP ≥ 255K, IRP ≥ 94%).
     */
    public function test_promoter_with_base_pp_and_high_irp_gets_14_percent(): void
    {
        $scheme   = $this->createProductionScheme();
        $promoter = Promoter::factory()->create();
        $period   = $this->defaultPeriod();

        $agents = $this->createRecruits($promoter, 6);

        // 3 Primordial ($300K activas)
        foreach (range(0, 2) as $i) {
            Policy::factory()->primordial()->withPremium(100_000)
                ->for($agents[$i])
                ->create(['issue_date' => '2026-08-01', 'status' => Policy::STATUS_ACTIVA]);
        }

        // IRP = 100% (todo activo), PP = $300K
        // PP ≥ $255K + IRP ≥ 94% → 14%
        $result = $this->calculator->calculate($promoter, $scheme, $period['start'], $period['end']);

        $this->assertTrue($result['is_achieved']);
        $this->assertEquals(14.0, (float) $result['tier_data']['promoter_percentage']);
        $this->assertEquals(42_000.0, $result['amount']); // $300K × 14%
    }

    /**
     * 🔴 Un Agent no puede recibir este bono.
     */
    public function test_agent_cannot_receive_promoter_production_bonus(): void
    {
        $scheme = $this->createProductionScheme();
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
     * Crea el esquema «Producción 1.er Año Trimestral» con 4 tiers.
     */
    protected function createProductionScheme(): Scheme
    {
        $scheme = Scheme::create([
            'name'              => 'first_year_production',
            'type'              => 'bonus',
            'target'            => 'promoter',
            'is_active'         => true,
            'metric_base'       => 'PP',
            'frequency'         => 'trimestral',
            'min_irp'           => self::GLOBAL_MIN_IRP,
            'quarterly_recruits' => [
                1 => self::Q1_QUOTA,
                2 => self::Q2_QUOTA,
                3 => self::Q3_QUOTA,
                4 => self::Q4_QUOTA,
            ],
            'requires_product'  => ['Primordial'],
            'min_product_count' => 3,
        ]);

        $version = SchemeVersion::create([
            'scheme_id'    => $scheme->id,
            'version_name' => 'v1.0 — Producción 1.er Año Trimestral',
            'starts_at'    => '2026-01-01',
            'ends_at'      => '2026-12-31',
        ]);

        $tiers = [
            // [min_pp, min_irp, max_irp, promoter_pct]
            [255_000, 91, 93.99, 10.0],
            [255_000, 94, null,  14.0],
            [555_000, 91, 93.99, 18.0],
            [555_000, 94, null,  22.0],
        ];

        foreach ($tiers as [$minPp, $minIrp, $maxIrp, $pct]) {
            SchemeTier::create([
                'scheme_version_id'   => $version->id,
                'conditions'          => [
                    'min_pp'  => $minPp,
                    'min_irp' => $minIrp,
                    'max_irp' => $maxIrp,
                ],
                'agent_percentage'    => 0,
                'promoter_percentage' => $pct,
                'fixed_amount'        => null,
            ]);
        }

        return $scheme->fresh(['versions.tiers']);
    }

    /**
     * Crea N agentes (reclutas) bajo el promotor, todos creados en 2026.
     *
     * @return array<int, Agent>
     */
    protected function createRecruits(Promoter $promoter, int $count): array
    {
        $agents = [];
        for ($i = 0; $i < $count; $i++) {
            $agents[] = Agent::factory()
                ->for($promoter, 'promoter')
                ->create([
                    'created_at' => Carbon::parse('2026-0' . ($i + 1) . '-01'),
                    'is_active'  => true,
                ]);
        }
        return $agents;
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
