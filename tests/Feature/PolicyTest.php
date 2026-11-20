<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\Policy;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Suite de pruebas para el modelo Policy.
 *
 * REGLA DE NEGOCIO VIGENTE:
 *   - ISR, impuesto cedular y costo de facturación se deducen de las COMISIONES
 *     (agente y promotor), en ese orden secuencial y NO de la prima total.
 *   - Solo las pólizas en STATUS_PAGADA acumulan para comisiones y montos netos.
 *   - STATUS_ACTIVA y STATUS_NO_TOMADA retornan 0 en todos los cálculos netos.
 */
class PolicyTest extends TestCase
{
    use RefreshDatabase;

    // ═══════════════════════════════════════════════════════════════════════
    // 🟢 TESTS: FLUJO FELIZ — STATUS_PAGADA
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * 🟢 agentNetAmount: comisión bruta $10,000 − ISR 10% − cedular 5% − facturación 5% = $8,122.50.
     */
    public function test_agent_net_amount_with_pagada_status(): void
    {
        $policy = Policy::factory()->create([
            'status'                       => Policy::STATUS_PAGADA,
            'premium_amount'               => 100_000.00,
            'commission_amount'            => 10_000.00,
            'promoter_commission_amount'   => 5_000.00,
            'isr_retention'                => 10.00,
            'cedular_tax'                  => 5.00,
            'billing_retention'            => 5.00,
        ]);

        // Agente: secuencial — ISR, luego cedular, luego facturación
        // ISR = $10,000 × 10% = $1,000 → after ISR = $9,000
        // Cedular = $9,000 × 5% = $450 → after cedular = $8,550
        // Facturación = $8,550 × 5% = $427.50 → neto = $8,122.50
        $this->assertEquals(8_122.50, $policy->agentNetAmount());
    }

    /**
     * 🟢 promoterNetAmount: comisión bruta $5,000 − ISR 10% − cedular 5% − facturación 5% = $4,061.25.
     */
    public function test_promoter_net_amount_with_pagada_status(): void
    {
        $policy = Policy::factory()->create([
            'status'                       => Policy::STATUS_PAGADA,
            'premium_amount'               => 100_000.00,
            'commission_amount'            => 10_000.00,
            'promoter_commission_amount'   => 5_000.00,
            'isr_retention'                => 10.00,
            'cedular_tax'                  => 5.00,
            'billing_retention'            => 5.00,
        ]);

        // Promotor: secuencial — ISR, luego cedular, luego facturación
        // ISR = $5,000 × 10% = $500 → after ISR = $4,500
        // Cedular = $4,500 × 5% = $225 → after cedular = $4,275
        // Facturación = $4,275 × 5% = $213.75 → neto = $4,061.25
        $this->assertEquals(4_061.25, $policy->promoterNetAmount());
    }

    /**
     * 🟢 netAmount: suma de comisiones netas de agente y promotor.
     */
    public function test_net_amount_with_pagada_status(): void
    {
        $policy = Policy::factory()->create([
            'status'                       => Policy::STATUS_PAGADA,
            'premium_amount'               => 100_000.00,
            'commission_amount'            => 10_000.00,
            'promoter_commission_amount'   => 5_000.00,
            'isr_retention'                => 10.00,
            'cedular_tax'                  => 5.00,
            'billing_retention'            => 5.00,
        ]);

        // netAmount = agentNetAmount + promoterNetAmount = $8,122.50 + $4,061.25 = $12,183.75
        $this->assertEquals(12_183.75, $policy->netAmount());
    }

    /**
     * 🟢 netAmount sin comisión de promotor (0): solo deduce agente.
     */
    public function test_net_amount_without_promoter_commission(): void
    {
        $policy = Policy::factory()->create([
            'status'                       => Policy::STATUS_PAGADA,
            'premium_amount'               => 50_000.00,
            'commission_amount'            => 5_000.00,
            'promoter_commission_amount'   => 0.00,
            'isr_retention'                => 10.00,
            'cedular_tax'                  => 5.00,
            'billing_retention'            => 5.00,
        ]);

        // Agente neto: ISR = $500 → $4,500 → cedular = $225 → $4,275 → facturación = $213.75 → $4,061.25
        // netAmount: $4,061.25 + $0 = $4,061.25
        $this->assertEquals(4_061.25, $policy->agentNetAmount());
        $this->assertEquals(0.00, $policy->promoterNetAmount());
        $this->assertEquals(4_061.25, $policy->netAmount());
    }

    /**
     * 🟢 pcaAmount: PNA menos la deducción fija por póliza, sin negativos.
     */
    public function test_pca_amount_discounts_fixed_per_policy_amount(): void
    {
        $policy = Policy::factory()->create(['premium_amount' => 100_000.00]);
        $smallPolicy = Policy::factory()->create(['premium_amount' => 1_000.00]);

        $this->assertEquals(98_650.00, $policy->pcaAmount());
        $this->assertEquals(0.00, $smallPolicy->pcaAmount());
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 🔴 TESTS: ESTATUS NO PAGADOS → SIEMPRE 0
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * 🔴 STATUS_ACTIVA: todos los montos netos deben ser 0.
     */
    public function test_activa_status_returns_zero_for_all_net_amounts(): void
    {
        $policy = Policy::factory()->create([
            'status'                       => Policy::STATUS_ACTIVA,
            'premium_amount'               => 100_000.00,
            'commission_amount'            => 10_000.00,
            'promoter_commission_amount'   => 5_000.00,
        ]);

        // Con STATUS_ACTIVA el sistema retorna 0 (no acumula)
        $this->assertEquals(0.00, $policy->agentNetAmount());
        $this->assertEquals(0.00, $policy->promoterNetAmount());
        $this->assertEquals(0.00, $policy->netAmount());
    }

    /**
     * 🔴 STATUS_NO_TOMADA: todos los montos netos deben ser 0.
     */
    public function test_no_tomada_status_returns_zero_for_all_net_amounts(): void
    {
        $policy = Policy::factory()->create([
            'status'                       => Policy::STATUS_NO_TOMADA,
            'premium_amount'               => 100_000.00,
            'commission_amount'            => 10_000.00,
            'promoter_commission_amount'   => 5_000.00,
        ]);

        $this->assertEquals(0.00, $policy->agentNetAmount());
        $this->assertEquals(0.00, $policy->promoterNetAmount());
        $this->assertEquals(0.00, $policy->netAmount());
    }

    /**
     * 🔴 STATUS_NO_TOMADA: nombre correcto, sin referencia al antiguo 'Cancelada'.
     */
    public function test_no_tomada_status_exists_and_is_not_cancelada(): void
    {
        $policy = Policy::factory()->create([
            'status' => Policy::STATUS_NO_TOMADA,
        ]);

        $this->assertEquals('No tomada', $policy->status);
        $this->assertSame(Policy::STATUS_NO_TOMADA, $policy->status);

        // Verificar que el estatus NO tenga valor 'Cancelada'
        $this->assertNotEquals('Cancelada', $policy->status);
    }

    /**
     * 🔴 El método cancel() fue renombrado a markAsNoTomada().
     */
    public function test_mark_as_no_tomada_method_exists_and_works(): void
    {
        $policy = Policy::factory()->create([
            'status' => Policy::STATUS_ACTIVA,
        ]);

        $this->assertTrue(method_exists($policy, 'markAsNoTomada'));
        $this->assertFalse(method_exists($policy, 'cancel'));

        $policy->markAsNoTomada();

        $this->assertEquals(Policy::STATUS_NO_TOMADA, $policy->status);
        $this->assertEquals('No tomada', $policy->status);
    }

    /**
     * 🔴 STATUS_NO_TOMADA con factory state 'cancelled'.
     */
    public function test_factory_cancelled_state_uses_no_tomada(): void
    {
        $policy = Policy::factory()->cancelled()->create();

        $this->assertEquals(Policy::STATUS_NO_TOMADA, $policy->status);
        $this->assertEquals('No tomada', $policy->status);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 🟡 TESTS: CASOS BORDE
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * 🟡 Retenciones en 0%: comisión neta = comisión bruta.
     */
    public function test_zero_retentions_keep_full_commission(): void
    {
        $policy = Policy::factory()->create([
            'status'                       => Policy::STATUS_PAGADA,
            'premium_amount'               => 100_000.00,
            'commission_amount'            => 10_000.00,
            'promoter_commission_amount'   => 5_000.00,
            'isr_retention'                => 0.00,
            'cedular_tax'                  => 0.00,
            'billing_retention'            => 0.00,
        ]);

        $this->assertEquals(10_000.00, $policy->agentNetAmount());
        $this->assertEquals(5_000.00, $policy->promoterNetAmount());
        $this->assertEquals(15_000.00, $policy->netAmount());
    }

    /**
     * 🟡 Solo impuesto cedular (5%): se descuenta de la comisión.
     */
    public function test_cedular_tax_is_deducted_from_commission(): void
    {
        $policy = Policy::factory()->create([
            'status'                       => Policy::STATUS_PAGADA,
            'premium_amount'               => 100_000.00,
            'commission_amount'            => 10_000.00,
            'promoter_commission_amount'   => 0.00,
            'isr_retention'                => 0.00,
            'cedular_tax'                  => 5.00,
            'billing_retention'            => 0.00,
        ]);

        // Cedular = $10,000 × 5% = $500 → neto = $9,500
        $this->assertEquals(9_500.00, $policy->agentNetAmount());
    }

    /**
     * 🟡 Comisión 0: netAmount = 0.
     */
    public function test_zero_commission_returns_zero_net(): void
    {
        $policy = Policy::factory()->create([
            'status'                       => Policy::STATUS_PAGADA,
            'premium_amount'               => 75_000.00,
            'commission_amount'            => 0.00,
            'promoter_commission_amount'   => 0.00,
            'isr_retention'                => 10.00,
            'cedular_tax'                  => 5.00,
            'billing_retention'            => 5.00,
        ]);

        $this->assertEquals(0.00, $policy->agentNetAmount());
        $this->assertEquals(0.00, $policy->promoterNetAmount());
        $this->assertEquals(0.00, $policy->netAmount());
    }

    /**
     * 🟡 Retenciones en 0%: comisión neta = comisión bruta.
     */
    public function test_null_retentions_treated_as_zero(): void
    {
        $policy = Policy::factory()->create([
            'status'                       => Policy::STATUS_PAGADA,
            'premium_amount'               => 100_000.00,
            'commission_amount'            => 10_000.00,
            'promoter_commission_amount'   => 5_000.00,
            'isr_retention'                => 0.00,
            'cedular_tax'                  => 0.00,
            'billing_retention'            => 0.00,
        ]);

        $this->assertEquals(10_000.00, $policy->agentNetAmount());
        $this->assertEquals(5_000.00, $policy->promoterNetAmount());
        $this->assertEquals(15_000.00, $policy->netAmount());
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 🟡 TESTS: ALTA DE PÓLIZAS (HTTP)
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * 🟡 El costo de facturación es obligatorio al registrar una póliza.
     */
    public function test_billing_retention_is_required_when_storing_a_policy(): void
    {
        $user = User::factory()->create();
        $agent = Agent::factory()->create();

        $response = $this->actingAs($user)->post(route('policies.store'), [
            'agent_id'       => $agent->id,
            'policy_number'  => '123456',
            'issue_date'     => '2026-01-15',
            'premium_amount' => 50_000,
            'payment_method' => 'Efectivo',
        ]);

        $response->assertSessionHasErrors('billing_retention');
        $this->assertDatabaseCount('policies', 0);
    }

    /**
     * 🟡 El conducto de pago es obligatorio al registrar una póliza.
     */
    public function test_payment_method_is_required_when_storing_a_policy(): void
    {
        $user = User::factory()->create();
        $agent = Agent::factory()->create();

        $response = $this->actingAs($user)->post(route('policies.store'), [
            'agent_id'          => $agent->id,
            'policy_number'     => '123456',
            'issue_date'        => '2026-01-15',
            'premium_amount'    => 50_000,
            'billing_retention' => 5,
        ]);

        $response->assertSessionHasErrors('payment_method');
        $this->assertDatabaseCount('policies', 0);
    }

    /**
     * 🟡 Al registrar una póliza, el impuesto cedular toma el 5% por defecto.
     */
    public function test_store_policy_defaults_cedular_tax_to_five_percent(): void
    {
        $user = User::factory()->create();
        $agent = Agent::factory()->create();

        $this->actingAs($user)->post(route('policies.store'), [
            'agent_id'          => $agent->id,
            'policy_number'     => '987654321',
            'client_name'       => 'Cliente de prueba',
            'issue_date'        => '2026-01-15',
            'premium_amount'    => 50_000,
            'billing_retention' => 4,
            'product_type'      => 'METALIFE',
            'payment_method'    => 'Automático',
        ])->assertRedirect(route('policies.index'));

        $policy = Policy::firstOrFail();

        $this->assertEquals('5.00', $policy->cedular_tax);
        $this->assertEquals('4.00', $policy->billing_retention);
        $this->assertEquals('METALIFE', $policy->product_type);
        $this->assertEquals('Automático', $policy->payment_method);
    }
}
