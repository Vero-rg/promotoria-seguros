<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\BonusAdvance;
use App\Models\Policy;
use App\Models\Promoter;
use App\Models\Scheme;
use App\Models\SchemeTier;
use App\Models\SchemeVersion;
use App\Models\User;
use App\Services\BonusAdvanceService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Anticipos mensuales de los bonos de Producción de 1.er Año de Vida.
 *
 * Regla: cada mes se evalúa el acumulado del trimestre y el anticipo es la
 * diferencia contra lo ya entregado, de modo que al subir de nivel el mes
 * siguiente incluye la diferencia retroactiva.
 */
class BonusAdvanceServiceTest extends TestCase
{
    use RefreshDatabase;

    protected BonusAdvanceService $advances;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-09-30'));
        $this->advances = new BonusAdvanceService();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow(null);
        parent::tearDown();
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 🟢 AGENTE
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * 🟢 El anticipo de cada mes es la diferencia contra lo ya entregado.
     *
     * PCA acumulada: julio $200K (14%), agosto $400K (20%), sept $600K (26%).
     * Anticipos: 28,000 · 52,000 · 76,000 → total 156,000 (monto del trimestre).
     */
    public function test_agent_advances_are_the_difference_against_previous_months(): void
    {
        $scheme = $this->createAgentScheme();
        $agent = Agent::factory()->create(['created_at' => '2026-01-01', 'is_active' => true]);

        $this->createPaidPolicy($agent, '2026-07-10', 201_350);
        $this->createPaidPolicy($agent, '2026-08-10', 201_350);
        $this->createPaidPolicy($agent, '2026-09-10', 201_350);

        $result = $this->advances->build($scheme, $agent, Carbon::parse('2026-09-30'));

        $this->assertCount(3, $result['months']);
        $this->assertSame('PCA', $result['metric_label']);
        $this->assertSame([14.0, 20.0, 26.0], array_column($result['months'], 'percentage'));
        $this->assertSame([28_000.0, 52_000.0, 76_000.0], array_column($result['months'], 'amount'));
        $this->assertSame(156_000.0, $result['total']);
        $this->assertFalse($result['has_overrides']);

        // Ventas del mes: PCA vendida en cada mes y acumulada hasta ese mes
        $this->assertSame([200_000.0, 200_000.0, 200_000.0], array_map('floatval', array_column($result['months'], 'month_metric')));
        $this->assertSame([200_000.0, 400_000.0, 600_000.0], array_map('floatval', array_column($result['months'], 'metric')));
    }

    /**
     * 🟢 Si el rango termina en agosto, solo se muestran julio y agosto.
     */
    public function test_agent_advances_only_cover_months_up_to_the_range_end(): void
    {
        $scheme = $this->createAgentScheme();
        $agent = Agent::factory()->create(['created_at' => '2026-01-01', 'is_active' => true]);

        $this->createPaidPolicy($agent, '2026-07-10', 201_350);
        $this->createPaidPolicy($agent, '2026-08-10', 201_350);
        $this->createPaidPolicy($agent, '2026-09-10', 201_350);

        $result = $this->advances->build($scheme, $agent, Carbon::parse('2026-08-31'));

        $this->assertCount(2, $result['months']);
        $this->assertSame(['2026-07', '2026-08'], array_column($result['months'], 'month'));
        $this->assertSame(80_000.0, $result['total']); // acumulado de agosto (20% de $400K)
    }

    /**
     * 🟢 Un monto modificado manualmente se usa para calcular la diferencia
     *    de los meses siguientes.
     */
    public function test_override_changes_the_next_month_difference(): void
    {
        $scheme = $this->createAgentScheme();
        $agent = Agent::factory()->create(['created_at' => '2026-01-01', 'is_active' => true]);

        $this->createPaidPolicy($agent, '2026-07-10', 201_350);
        $this->createPaidPolicy($agent, '2026-08-10', 201_350);

        $this->advances->save($scheme, 'agent', $agent->id, '2026-07', 30_000);

        $result = $this->advances->build($scheme, $agent, Carbon::parse('2026-08-31'));

        $this->assertTrue($result['has_overrides']);
        $this->assertTrue($result['months'][0]['is_override']);
        $this->assertSame(30_000.0, $result['months'][0]['amount']);
        $this->assertSame(28_000.0, $result['months'][0]['calculated']);

        // Acumulado agosto $400K × 20% = $80K; ya entregado $30K → diferencia $50K
        $this->assertSame(50_000.0, $result['months'][1]['amount']);
        $this->assertSame(80_000.0, $result['total']);
        $this->assertSame(1, BonusAdvance::count());
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 🟢 PROMOTOR
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * 🟢 El promotor recibe anticipos sobre la PP acumulada del equipo.
     */
    public function test_promoter_advances_use_cumulative_team_pp(): void
    {
        $scheme = $this->createPromoterScheme();
        $promoter = Promoter::factory()->create();
        $agent = Agent::factory()->for($promoter, 'promoter')->create(['is_active' => true]);

        $this->createPaidPolicy($agent, '2026-07-10', 120_000);
        $this->createPaidPolicy($agent, '2026-08-10', 100_000);

        $result = $this->advances->build($scheme, $promoter, Carbon::parse('2026-08-31'));

        $this->assertCount(2, $result['months']);
        $this->assertSame('PP', $result['metric_label']);
        // Julio: $120K × 10% = $12,000
        $this->assertSame(12_000.0, $result['months'][0]['amount']);
        // Agosto: $220K × 14% = $30,800 menos $12,000 = $18,800
        $this->assertSame(18_800.0, $result['months'][1]['amount']);
        $this->assertSame(30_800.0, $result['total']);

        // PP vendida por mes
        $this->assertSame(120_000.0, (float) $result['months'][0]['month_metric']);
        $this->assertSame(100_000.0, (float) $result['months'][1]['month_metric']);
        $this->assertSame(220_000.0, (float) $result['months'][1]['metric']);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 🟡 ALCANCE
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * 🟡 Los bonos que no permiten anticipos no generan desglose.
     */
    public function test_bonus_without_advances_support_returns_empty(): void
    {
        $scheme = Scheme::create([
            'name'              => 'connection',
            'template_key'      => 'connection',
            'type'              => 'bonus',
            'target'            => 'promoter',
            'is_active'         => true,
            'frequency'         => 'mensual',
            'requires_anticipos' => true,
        ]);

        $this->assertSame([], $this->advances->build($scheme, Promoter::factory()->create(), Carbon::parse('2026-09-30')));
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 🟢 ENDPOINT
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * 🟢 El anticipo se guarda y se actualiza desde el panel.
     */
    public function test_endpoint_stores_and_updates_an_advance(): void
    {
        $scheme = $this->createAgentScheme();
        $agent = Agent::factory()->create();
        $user = User::factory()->create();

        $payload = [
            'scheme_id'    => $scheme->id,
            'user_type'    => 'agent',
            'user_id'      => $agent->id,
            'period_month' => '2026-07',
            'amount'       => 30_000,
        ];

        $this->actingAs($user)->post(route('bonus-advances.store'), $payload)->assertRedirect();

        $this->assertDatabaseHas('bonus_advances', [
            'scheme_id'    => $scheme->id,
            'user_id'      => $agent->id,
            'period_month' => '2026-07',
            'amount'       => 30_000,
        ]);

        $this->actingAs($user)
            ->post(route('bonus-advances.store'), array_merge($payload, ['amount' => 45_500]))
            ->assertRedirect();

        $this->assertSame(1, BonusAdvance::count());
        $this->assertEquals(45_500, (float) BonusAdvance::first()->amount);
    }

    /**
     * 🔴 No se permite guardar un anticipo en un bono que no lo admite.
     */
    public function test_endpoint_rejects_bonus_without_advances(): void
    {
        $scheme = Scheme::create([
            'name'         => 'activity_ratio',
            'template_key' => 'activity_ratio',
            'type'         => 'bonus',
            'target'       => 'agent',
            'is_active'    => true,
        ]);

        $agent = Agent::factory()->create();

        $this->actingAs(User::factory()->create())
            ->post(route('bonus-advances.store'), [
                'scheme_id'    => $scheme->id,
                'user_type'    => 'agent',
                'user_id'      => $agent->id,
                'period_month' => '2026-07',
                'amount'       => 1_000,
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(0, BonusAdvance::count());
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 🔧 HELPERS
    // ═══════════════════════════════════════════════════════════════════════

    private function createPaidPolicy(Agent $agent, string $issueDate, float $premium): void
    {
        Policy::factory()->vida()->withPremium($premium)->pagada()
            ->for($agent)
            ->create([
                'issue_date'     => $issueDate,
                'payment_method' => Policy::PAYMENT_METHOD_CASH,
            ]);
    }

    private function createAgentScheme(): Scheme
    {
        $scheme = Scheme::create([
            'name'               => 'agent_first_year_production',
            'template_key'       => 'agent_first_year_production',
            'type'               => 'bonus',
            'target'             => 'agent',
            'is_active'          => true,
            'frequency'          => 'trimestral',
            'requires_anticipos' => true,
            'requires_product'   => [],
            'anticipos_config'   => ['month_1_min' => 0, 'month_2_min' => 0],
        ]);

        $version = SchemeVersion::create([
            'scheme_id'    => $scheme->id,
            'version_name' => 'v1.0',
            'starts_at'    => '2026-01-01',
            'ends_at'      => '2026-12-31',
        ]);

        foreach ([100_000 => 10.0, 200_000 => 14.0, 400_000 => 20.0, 600_000 => 26.0] as $minPca => $pct) {
            SchemeTier::create([
                'scheme_version_id'          => $version->id,
                'conditions'                 => ['min_pca' => $minPca],
                'agent_percentage'           => $pct,
                'agent_automatic_percentage' => $pct,
                'promoter_percentage'        => 0,
            ]);
        }

        return $scheme->fresh(['versions.tiers']);
    }

    private function createPromoterScheme(): Scheme
    {
        $scheme = Scheme::create([
            'name'               => 'first_year_production',
            'template_key'       => 'first_year_production',
            'type'               => 'bonus',
            'target'             => 'promoter',
            'is_active'          => true,
            'frequency'          => 'trimestral',
            'requires_anticipos' => true,
            'requires_product'   => [],
            'min_irp'            => 0,
            'anticipos_config'   => ['month_1_min' => 0, 'month_2_min' => 0],
        ]);

        $version = SchemeVersion::create([
            'scheme_id'    => $scheme->id,
            'version_name' => 'v1.0',
            'starts_at'    => '2026-01-01',
            'ends_at'      => '2026-12-31',
        ]);

        foreach ([100_000 => 10.0, 200_000 => 14.0] as $minPp => $pct) {
            SchemeTier::create([
                'scheme_version_id'   => $version->id,
                'conditions'          => ['min_pp' => $minPp, 'min_irp' => 0],
                'agent_percentage'    => 0,
                'promoter_percentage' => $pct,
            ]);
        }

        return $scheme->fresh(['versions.tiers']);
    }
}
