<?php

namespace Tests\Feature;

use App\Models\Policy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Suite de pruebas para el modelo Policy.
 *
 * REGLA DE NEGOCIO VIGENTE:
 *   - ISR y costo de facturación se deducen de las COMISIONES (agente y promotor),
 *     NO de la prima total.
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
     * 🟢 agentNetAmount: comisión bruta $10,000 − ISR 10% − facturación 5% = $8,500.
     */
    public function test_agent_net_amount_with_pagada_status(): void
    {
        $policy = Policy::factory()->create([
            'status'                       => Policy::STATUS_PAGADA,
            'premium_amount'               => 100_000.00,
            'commission_amount'            => 10_000.00,
            'promoter_commission_amount'   => 5_000.00,
            'isr_retention'                => 10.00,
            'billing_retention'            => 5.00,
        ]);

        // Agente: $10,000 − ($10,000 × 10%) − ($10,000 × 5%)
        //       = $10,000 − $1,000 − $500 = $8,500
        $this->assertEquals(8_500.00, $policy->agentNetAmount());
    }

    /**
     * 🟢 promoterNetAmount: comisión bruta $5,000 − ISR 10% − facturación 5% = $4,250.
     */
    public function test_promoter_net_amount_with_pagada_status(): void
    {
        $policy = Policy::factory()->create([
            'status'                       => Policy::STATUS_PAGADA,
            'premium_amount'               => 100_000.00,
            'commission_amount'            => 10_000.00,
            'promoter_commission_amount'   => 5_000.00,
            'isr_retention'                => 10.00,
            'billing_retention'            => 5.00,
        ]);

        // Promotor: $5,000 − ($5,000 × 10%) − ($5,000 × 5%)
        //          = $5,000 − $500 − $250 = $4,250
        $this->assertEquals(4_250.00, $policy->promoterNetAmount());
    }

    /**
     * 🟢 netAmount: prima $100K − agentNet $8,500 − promoterNet $4,250 = $87,250.
     */
    public function test_net_amount_with_pagada_status(): void
    {
        $policy = Policy::factory()->create([
            'status'                       => Policy::STATUS_PAGADA,
            'premium_amount'               => 100_000.00,
            'commission_amount'            => 10_000.00,
            'promoter_commission_amount'   => 5_000.00,
            'isr_retention'                => 10.00,
            'billing_retention'            => 5.00,
        ]);

        // Prima $100,000 — netAmount = agente neto $8,500 + promotor neto $4,250 = $12,750
        $this->assertEquals(12_750.00, $policy->netAmount());
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
            'billing_retention'            => 5.00,
        ]);

        // Agente neto: $5,000 − $500 − $250 = $4,250
        // netAmount: $4,250 + $0 = $4,250
        $this->assertEquals(4_250.00, $policy->agentNetAmount());
        $this->assertEquals(0.00, $policy->promoterNetAmount());
        $this->assertEquals(4_250.00, $policy->netAmount());
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
            'billing_retention'            => 0.00,
        ]);

        $this->assertEquals(10_000.00, $policy->agentNetAmount());
        $this->assertEquals(5_000.00, $policy->promoterNetAmount());
        $this->assertEquals(15_000.00, $policy->netAmount());
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
            'billing_retention'            => 5.00,
        ]);

        $this->assertEquals(0.00, $policy->agentNetAmount());
        $this->assertEquals(0.00, $policy->promoterNetAmount());
        $this->assertEquals(0.00, $policy->netAmount());
    }

    /**
     * 🟡 Retenciones null: se tratan como 0%.
     */
    public function test_null_retentions_treated_as_zero(): void
    {
        $policy = Policy::factory()->create([
            'status'                       => Policy::STATUS_PAGADA,
            'premium_amount'               => 100_000.00,
            'commission_amount'            => 10_000.00,
            'promoter_commission_amount'   => 5_000.00,
            'isr_retention'                => null,
            'billing_retention'            => null,
        ]);

        $this->assertEquals(10_000.00, $policy->agentNetAmount());
        $this->assertEquals(5_000.00, $policy->promoterNetAmount());
        $this->assertEquals(15_000.00, $policy->netAmount());
    }
}
