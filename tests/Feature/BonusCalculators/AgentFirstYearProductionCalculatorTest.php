<?php

namespace Tests\Feature\BonusCalculators;

use App\Models\Agent;
use App\Models\Policy;
use App\Models\Promoter;
use App\Models\Scheme;
use App\Models\SchemeTier;
use App\Models\SchemeVersion;
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

    protected const TIER_1_MIN_PCA = 130_000;

    protected const TIER_1_AGENT_PERCENTAGE = 10.0;

    protected const TIER_1_AGENT_AUTO_PERCENTAGE = 16.0;

    protected const TIER_2_MIN_PCA = 200_000;

    protected const TIER_2_AGENT_PERCENTAGE = 14.0;

    protected const TIER_2_AGENT_AUTO_PERCENTAGE = 20.0;

    protected const TIER_3_MIN_PCA = 785_000;

    protected const TIER_3_AGENT_PERCENTAGE = 36.0;

    protected const TIER_3_AGENT_AUTO_PERCENTAGE = 44.0;

    // ────────────────────────────────────────────────────────────────────────

    protected function setUp(): void
    {
        parent::setUp();

        // Fijar «now» para que los cálculos de primer año sean deterministas
        Carbon::setTestNow(Carbon::parse('2026-06-15'));

        $this->calculator = new AgentFirstYearProductionCalculator;
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
     * 🟢 Escenario feliz: Agente con $198,650 PCA, 1 póliza Vida y 1 Primordial.
     *
     * PCA real: 200,000 − 1,350 = 198,650 (la póliza Primordial solo cumple
     * el requisito de producto: su monto no participa en el bono).
     * Debe alcanzar el Tier 1 (min_pca = 130,000) y recibir 10% sobre PCA.
     * Monto esperado: $198,650 × 10% = $19,865.
     */
    public function test_agent_receives_tier_one_bonus_when_all_conditions_are_met(): void
    {
        // ── Arrange ────────────────────────────────────────────────────
        $scheme = $this->createSchemeWithTiers();
        $agent = Agent::factory()->create(['created_at' => '2026-01-01']);
        $period = $this->defaultPeriod();

        // 1 póliza Vida con $200,000 → PCA = $198,650; 1 Primordial (requisito)
        Policy::factory()
            ->vida()
            ->pagada()
            ->withPremium(200_000)
            ->for($agent)
            ->create(['issue_date' => '2026-02-15']);

        Policy::factory()
            ->primordial()
            ->pagada()
            ->withPremium(50_000)
            ->for($agent)
            ->create(['issue_date' => '2026-03-10']);

        // ── Act ────────────────────────────────────────────────────────
        $result = $this->calculator->calculate(
            $agent, $scheme, $period['start'], $period['end']
        );

        // ── Assert ─────────────────────────────────────────────────────
        $this->assertTrue($result['is_achieved'], 'El bono debió alcanzarse. Razón: '.($result['details']['reason'] ?? '—'));

        $this->assertEquals(19_865.0, $result['amount']);
        // Tier 1 es el de menor min_pca, queda en último lugar tras orden descendente
        $this->assertEquals(2, $result['tier_index'], 'Debió tomar el Tier 1 (índice 2, el último tras sort DESC).');

        $this->assertNotNull($result['tier_data']);
        $this->assertEquals(
            self::TIER_1_AGENT_PERCENTAGE,
            (float) $result['tier_data']['agent_percentage'],
            'El tier alcanzado debe tener agent_percentage = 10.0'
        );

        // Progreso
        $this->assertEquals(198_650.0, $result['progress']['current_value']);
        $this->assertEquals(self::TIER_1_MIN_PCA, $result['progress']['required_value']);
    }

    /**
     * 🟢 Agente con $248,650 PCA y ambos productos → debe alcanzar Tier 2.
     *
     * PCA real: 250,000 − 1,350 = 248,650 (Primordial fuera del monto).
     * Monto esperado: $248,650 × 14% = $34,811.
     */
    public function test_agent_receives_tier_two_bonus_with_higher_pca(): void
    {
        $scheme = $this->createSchemeWithTiers();
        $agent = Agent::factory()->create(['created_at' => '2026-01-01']);
        $period = $this->defaultPeriod();

        Policy::factory()->vida()->pagada()->withPremium(250_000)->for($agent)
            ->create(['issue_date' => '2026-02-01']);
        Policy::factory()->primordial()->pagada()->withPremium(100_000)->for($agent)
            ->create(['issue_date' => '2026-02-15']);

        $result = $this->calculator->calculate($agent, $scheme, $period['start'], $period['end']);

        $this->assertTrue($result['is_achieved']);
        $this->assertEquals(34_811.0, $result['amount']);
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
        $agent = Agent::factory()->create(['created_at' => '2026-01-01']);
        $period = $this->defaultPeriod();

        // Solo 2 pólizas Vida — sin Primordial
        Policy::factory()->vida()->pagada()->withPremium(200_000)->for($agent)
            ->create(['issue_date' => '2026-03-01']);
        Policy::factory()->vida()->pagada()->withPremium(100_000)->for($agent)
            ->create(['issue_date' => '2026-04-01']);

        $result = $this->calculator->calculate($agent, $scheme, $period['start'], $period['end']);

        $this->assertFalse($result['is_achieved'], 'Debió fallar por falta de producto Primordial.');
        $this->assertEquals(0.0, $result['amount']);

        // Verificar que el breakdown muestre Primordial como no cumplido
        $breakdown = $result['progress_breakdown'] ?? [];
        $primordialBar = collect($breakdown)->first(fn ($b) => str_contains($b['label'] ?? '', 'Primordial'));
        $this->assertNotNull($primordialBar, 'Debe existir barra de Primordial en el breakdown.');
        $this->assertEquals(0, $primordialBar['current'], 'Conteo de Primordial debe ser 0.');
        $this->assertFalse($primordialBar['met'], 'Primordial no debe estar cumplido.');
    }

    /**
     * 🔴 El agente tiene ambos productos requeridos pero su PCA ($58,650)
     *    no alcanza ni siquiera el Tier 1 (min_pca = 130,000).
     */
    public function test_agent_fails_bonus_due_to_low_volume(): void
    {
        $scheme = $this->createSchemeWithTiers();
        $agent = Agent::factory()->create(['created_at' => '2026-01-01']);
        $period = $this->defaultPeriod();

        Policy::factory()->vida()->pagada()->withPremium(60_000)->for($agent)
            ->create(['issue_date' => '2026-02-01']);
        Policy::factory()->primordial()->pagada()->withPremium(40_000)->for($agent)
            ->create(['issue_date' => '2026-03-01']);

        $result = $this->calculator->calculate($agent, $scheme, $period['start'], $period['end']);

        $this->assertFalse($result['is_achieved'], 'Debió fallar por PCA insuficiente.');
        $this->assertEquals(0.0, $result['amount']);

        // El progreso debe mostrar el valor real vs lo requerido
        $this->assertEquals(58_650.0, $result['progress']['current_value']);
        $this->assertEquals(self::TIER_1_MIN_PCA, $result['progress']['required_value']);
    }

    /**
     * 🔴 El agente no tiene NINGUNA póliza de los productos requeridos.
     */
    public function test_agent_fails_when_has_zero_required_policies(): void
    {
        $scheme = $this->createSchemeWithTiers();
        $agent = Agent::factory()->create(['created_at' => '2026-01-01']);
        $period = $this->defaultPeriod();

        // Sin pólizas
        $result = $this->calculator->calculate($agent, $scheme, $period['start'], $period['end']);

        $this->assertFalse($result['is_achieved']);

        // Verificar que el breakdown muestre Vida como no cumplido
        $breakdown = $result['progress_breakdown'] ?? [];
        $vidaBar = collect($breakdown)->first(fn ($b) => str_contains($b['label'] ?? '', 'Vida'));
        $this->assertNotNull($vidaBar, 'Debe existir barra de Vida en el breakdown.');
        $this->assertEquals(0, $vidaBar['current'], 'Conteo de Vida debe ser 0.');
        $this->assertFalse($vidaBar['met'], 'Vida no debe estar cumplido.');
    }

    /**
     * 🔴 El agente ya superó su primer año → no aplica.
     */
    public function test_agent_fails_bonus_when_beyond_first_year(): void
    {
        $scheme = $this->createSchemeWithTiers();
        $agent = Agent::factory()->create(['created_at' => '2023-12-01']); // 2.5 años atrás
        $period = $this->defaultPeriod();

        Policy::factory()->vida()->pagada()->withPremium(500_000)->for($agent)
            ->create(['issue_date' => '2026-01-15']);
        Policy::factory()->primordial()->pagada()->withPremium(500_000)->for($agent)
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
        $scheme = $this->createSchemeWithTiers();
        $promoter = Promoter::factory()->create();
        $period = $this->defaultPeriod();

        $result = $this->calculator->calculate($promoter, $scheme, $period['start'], $period['end']);

        $this->assertFalse($result['is_achieved']);
        $this->assertStringContainsString('Agente', $result['details']['reason'] ?? '');
    }

    // ═══════════════════════════════════════════════════════════════════════
    // TESTS ADICIONALES DE PRECISIÓN
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * 🟢 El agente tiene más de 1 póliza por producto requerido → igual aplica.
     *
     * PCA real: 3 pólizas Vida (170,000) − 3 × $1,350 = $165,950.
     * Las 2 Primordial solo cumplen el requisito y no aportan monto.
     * Monto esperado: $165,950 × 10% = $16,595.
     */
    public function test_agent_with_extra_policies_still_qualifies(): void
    {
        $scheme = $this->createSchemeWithTiers();
        $agent = Agent::factory()->create(['created_at' => '2026-01-01']);
        $period = $this->defaultPeriod();

        // 3 Vida + 2 Primordial — excede el mínimo
        Policy::factory()->vida()->pagada()->withPremium(70_000)->for($agent)
            ->create(['issue_date' => '2026-02-01']);
        Policy::factory()->vida()->pagada()->withPremium(60_000)->for($agent)
            ->create(['issue_date' => '2026-02-15']);
        Policy::factory()->vida()->pagada()->withPremium(40_000)->for($agent)
            ->create(['issue_date' => '2026-03-01']);
        Policy::factory()->primordial()->pagada()->withPremium(20_000)->for($agent)
            ->create(['issue_date' => '2026-03-15']);
        Policy::factory()->primordial()->pagada()->withPremium(20_000)->for($agent)
            ->create(['issue_date' => '2026-04-01']);
        // PCA Vida = 165,950 → Tier 1

        $result = $this->calculator->calculate($agent, $scheme, $period['start'], $period['end']);

        $this->assertTrue($result['is_achieved']);
        $this->assertEquals(16_595.0, $result['amount']); // 165,950 × 10%
    }

    /**
     * 🔴 Pólizas con issue_date fuera del periodo de evaluación NO cuentan.
     *
     * Comportamiento trimestral: solo se consideran pólizas cuya fecha
     * de emisión cae dentro del rango [periodStart, periodEnd].
     */
    public function test_policies_outside_evaluation_period_are_excluded(): void
    {
        $scheme = $this->createSchemeWithTiers();
        $agent = Agent::factory()->create(['created_at' => '2026-01-01']);
        // Periodo: Q1 2026 (enero-marzo)
        $period = [
            'start' => Carbon::parse('2026-01-01'),
            'end' => Carbon::parse('2026-03-31'),
        ];

        // Póliza DENTRO del periodo: $80,000 → PCA = $78,650
        Policy::factory()->vida()->pagada()->withPremium(80_000)->for($agent)
            ->create(['issue_date' => '2026-02-15']);

        // Póliza FUERA del periodo (abril): $200,000 — NO debe contar
        Policy::factory()->primordial()->pagada()->withPremium(200_000)->for($agent)
            ->create(['issue_date' => '2026-04-01']);

        $result = $this->calculator->calculate($agent, $scheme, $period['start'], $period['end']);

        // PCA = $78,650 (solo la póliza de febrero). No alcanza Tier 1 ($130,000).
        $this->assertFalse($result['is_achieved']);
        $this->assertEquals(78_650.0, $result['progress']['current_value']);
    }

    /**
     * 🟢 Los tiers se evalúan en orden: si el agente califica para Tier 3,
     *    debe tomar ese (no el Tier 1 o 2).
     *
     * PCA real: 800,000 − 1,350 = 798,650 (la Primordial no aporta monto).
     * Monto esperado: $798,650 × 36% = $287,514.
     */
    public function test_agent_gets_highest_qualifying_tier(): void
    {
        $scheme = $this->createSchemeWithTiers();
        $agent = Agent::factory()->create(['created_at' => '2026-01-01']);
        $period = $this->defaultPeriod();

        // PCA = $798,650 → debe calificar para Tier 3
        Policy::factory()->vida()->pagada()->withPremium(800_000)->for($agent)
            ->create(['issue_date' => '2026-02-01']);
        Policy::factory()->primordial()->pagada()->withPremium(300_000)->for($agent)
            ->create(['issue_date' => '2026-03-01']);

        $result = $this->calculator->calculate($agent, $scheme, $period['start'], $period['end']);

        $this->assertTrue($result['is_achieved']);
        $this->assertEquals(0, $result['tier_index'], 'Debió tomar el Tier 3 (índice 0, el primero tras sort DESC).');
        $this->assertEquals(287_514.0, $result['amount']); // 798,650 × 36%
        $this->assertEquals(
            self::TIER_3_AGENT_PERCENTAGE,
            (float) $result['tier_data']['agent_percentage']
        );
    }

    // ═══════════════════════════════════════════════════════════════════════
    // TESTS DEL CONDUCTO DE PAGO
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * 🟢 Con menos del 60% de pólizas automáticas el bono se calcula por separado:
     *    PCA en efectivo × Bono Efectivo + PCA automática × Bono Automático.
     *
     * Vida efectivo $200,000  → PCA $198,650 → Tier 1 (10%)  = $19,865
     * Vida automático $150,000 → PCA $148,650 → Tier 1 (16%) = $23,784
     * Primordial efectivo $50,000 → requisito de producto, sin monto
     * Total esperado: $43,649.
     */
    public function test_bonus_splits_cash_and_automatic_pca_when_automatic_share_is_below_sixty_percent(): void
    {
        $scheme = $this->createSchemeWithTiers();
        $agent = Agent::factory()->create(['created_at' => '2026-01-01']);
        $period = $this->defaultPeriod();

        Policy::factory()->vida()->pagada()->withPremium(200_000)->for($agent)
            ->create([
                'issue_date' => '2026-02-01',
                'payment_method' => Policy::PAYMENT_METHOD_CASH,
            ]);

        Policy::factory()->vida()->pagada()->withPremium(150_000)->for($agent)
            ->create([
                'issue_date' => '2026-03-01',
                'payment_method' => Policy::PAYMENT_METHOD_AUTOMATIC,
            ]);

        Policy::factory()->primordial()->pagada()->withPremium(50_000)->for($agent)
            ->create([
                'issue_date' => '2026-03-15',
                'payment_method' => Policy::PAYMENT_METHOD_CASH,
            ]);

        $result = $this->calculator->calculate($agent, $scheme, $period['start'], $period['end']);

        $this->assertTrue($result['is_achieved']);
        $this->assertEquals(43_649.0, $result['amount']);
        $this->assertEquals('split', $result['details']['payment_strategy']);
        $this->assertEquals(198_650.0, $result['details']['cash_pca']);
        $this->assertEquals(148_650.0, $result['details']['automatic_pca']);
        $this->assertEquals(50.0, $result['details']['automatic_ratio']);
    }

    /**
     * 🟢 Con 60% o más de pólizas automáticas toda la PCA se paga como bono
     *    automático, aunque existan pólizas en efectivo.
     *
     * Vida PCA $1,243,250 → Tier 3 (44% automático) = $547,030.
     * La póliza Primordial solo cumple el requisito y no suma al monto.
     */
    public function test_bonus_pays_everything_as_automatic_when_sixty_percent_of_policies_are_automatic(): void
    {
        $scheme = $this->createSchemeWithTiers();
        $agent = Agent::factory()->create(['created_at' => '2026-01-01']);
        $period = $this->defaultPeriod();

        $automatic = ['payment_method' => Policy::PAYMENT_METHOD_AUTOMATIC];
        $cash = ['payment_method' => Policy::PAYMENT_METHOD_CASH];

        Policy::factory()->vida()->pagada()->withPremium(500_000)->for($agent)
            ->create(['issue_date' => '2026-02-01', ...$automatic]);
        Policy::factory()->vida()->pagada()->withPremium(300_000)->for($agent)
            ->create(['issue_date' => '2026-02-10', ...$automatic]);
        Policy::factory()->vida()->pagada()->withPremium(200_000)->for($agent)
            ->create(['issue_date' => '2026-02-20', ...$automatic]);
        Policy::factory()->vida()->pagada()->withPremium(150_000)->for($agent)
            ->create(['issue_date' => '2026-03-01', ...$cash]);
        Policy::factory()->vida()->pagada()->withPremium(100_000)->for($agent)
            ->create(['issue_date' => '2026-03-10', ...$cash]);
        Policy::factory()->primordial()->pagada()->withPremium(80_000)->for($agent)
            ->create(['issue_date' => '2026-03-20', ...$cash]);

        $result = $this->calculator->calculate($agent, $scheme, $period['start'], $period['end']);

        $this->assertTrue($result['is_achieved']);
        $this->assertEquals(547_030.0, $result['amount']);
        $this->assertEquals('automatic', $result['details']['payment_strategy']);
        $this->assertEquals(0.0, $result['details']['cash_amount']);
        $this->assertEquals(60.0, $result['details']['automatic_ratio']);
        $this->assertEquals(0, $result['tier_index'], 'Debió aplicar el Tier 3.');
    }

    /**
     * 🟢 El bono se desbloquea con la PCA TOTAL: el conducto de pago
     *    (efectivo / automático) es solo informativo y no genera barras.
     *
     * PCA $248,650 (efectivo) → Tier 2. El breakdown solo muestra la PCA total,
     * los productos requeridos y el conteo de pólizas.
     */
    public function test_payment_channel_is_informational_and_does_not_create_bars(): void
    {
        $scheme = $this->createSchemeWithTiers();
        $agent = Agent::factory()->create(['created_at' => '2026-01-01']);
        $period = $this->defaultPeriod();

        Policy::factory()->vida()->pagada()->withPremium(250_000)->for($agent)
            ->create(['issue_date' => '2026-02-01', 'payment_method' => Policy::PAYMENT_METHOD_CASH]);
        Policy::factory()->primordial()->pagada()->withPremium(40_000)->for($agent)
            ->create(['issue_date' => '2026-03-01', 'payment_method' => Policy::PAYMENT_METHOD_CASH]);

        $result = $this->calculator->calculate($agent, $scheme, $period['start'], $period['end']);

        $this->assertTrue($result['is_achieved']);

        $labels = array_column($result['progress_breakdown'], 'label');

        $this->assertContains('PCA Total del Trimestre ($)', $labels);
        $this->assertNotContains('PCA Efectivo ($)', $labels, 'El efectivo no debe ser un requisito.');
        $this->assertNotContains('PCA Automático ($)', $labels, 'El automático no debe ser un requisito.');
        $this->assertNotContains('Pólizas en pago automático (%)', $labels, 'El % automático no debe ser un requisito.');

        $pcaBar = collect($result['progress_breakdown'])->firstWhere('label', 'PCA Total del Trimestre ($)');
        $this->assertEquals(248_650.0, (float) $pcaBar['current']);
        $this->assertTrue($pcaBar['met']);

        // Info informativa del pago
        $info = $result['details']['payment_info'];
        $this->assertEquals('split', $info['strategy']);
        $this->assertEquals(248_650.0, $info['cash_pca']);
        $this->assertEquals(0.0, $info['automatic_pca']);
        $this->assertEquals(0.0, $info['automatic_ratio']);
        $this->assertEquals(14.0, $info['cash_percentage']);
        $this->assertEquals(14.0, $info['applied_percentage']);
    }

    /**
     * 🟢 Cuando domina el pago automático, el porcentaje aplicado es el
     *    automático del tramo alcanzado.
     *
     * La póliza Primordial no suma monto ni participa en el % de conducto.
     */
    public function test_payment_info_reports_applied_automatic_percentage(): void
    {
        $scheme = $this->createSchemeWithTiers();
        $agent = Agent::factory()->create(['created_at' => '2026-01-01']);
        $period = $this->defaultPeriod();

        Policy::factory()->vida()->pagada()->withPremium(300_000)->for($agent)
            ->create(['issue_date' => '2026-02-01', 'payment_method' => Policy::PAYMENT_METHOD_AUTOMATIC]);
        Policy::factory()->primordial()->pagada()->withPremium(40_000)->for($agent)
            ->create(['issue_date' => '2026-03-01', 'payment_method' => Policy::PAYMENT_METHOD_AUTOMATIC]);

        $result = $this->calculator->calculate($agent, $scheme, $period['start'], $period['end']);

        $this->assertTrue($result['is_achieved']);

        $info = $result['details']['payment_info'];
        $this->assertEquals('automatic', $info['strategy']);
        $this->assertEquals(100.0, $info['automatic_ratio']);
        $this->assertEquals(1, $info['total_policy_count']);
        $this->assertEquals(1, $info['automatic_policy_count']);
        $this->assertEquals(0.0, $info['cash_percentage']);
        $this->assertGreaterThan(0, $info['automatic_percentage']);
        $this->assertEquals(20.0, $info['applied_percentage']);
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
            'name' => self::SCHEME_NAME,
            'type' => 'bonus',
            'target' => 'agent',
            'is_active' => true,
            'metric_base' => 'PCA',
            'frequency' => 'trimestral',
            'requires_product' => ['Vida', 'Primordial'],
            'min_product_count' => 1,
        ]);

        $version = SchemeVersion::create([
            'scheme_id' => $scheme->id,
            'version_name' => 'v1.0 — Q1 2026',
            'starts_at' => '2026-01-01',
            'ends_at' => '2026-12-31',
        ]);

        // ── Tier 1: $130,000 PCA → 10% ─────────────────────────────────
        SchemeTier::create([
            'scheme_version_id' => $version->id,
            'conditions' => ['min_pca' => self::TIER_1_MIN_PCA],
            'agent_percentage' => self::TIER_1_AGENT_PERCENTAGE,
            'agent_automatic_percentage' => self::TIER_1_AGENT_AUTO_PERCENTAGE,
            'promoter_percentage' => 0,
            'fixed_amount' => null,
        ]);

        // ── Tier 2: $200,000 PCA → 14% ─────────────────────────────────
        SchemeTier::create([
            'scheme_version_id' => $version->id,
            'conditions' => ['min_pca' => self::TIER_2_MIN_PCA],
            'agent_percentage' => self::TIER_2_AGENT_PERCENTAGE,
            'agent_automatic_percentage' => self::TIER_2_AGENT_AUTO_PERCENTAGE,
            'promoter_percentage' => 0,
            'fixed_amount' => null,
        ]);

        // ── Tier 3: $785,000 PCA → 36% ─────────────────────────────────
        SchemeTier::create([
            'scheme_version_id' => $version->id,
            'conditions' => ['min_pca' => self::TIER_3_MIN_PCA],
            'agent_percentage' => self::TIER_3_AGENT_PERCENTAGE,
            'agent_automatic_percentage' => self::TIER_3_AGENT_AUTO_PERCENTAGE,
            'promoter_percentage' => 0,
            'fixed_amount' => null,
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
            'end' => Carbon::parse('2026-12-31'),
        ];
    }
}
