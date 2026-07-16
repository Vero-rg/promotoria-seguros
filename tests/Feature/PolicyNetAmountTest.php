<?php

namespace Tests\Feature;

use App\Models\Policy;
use Tests\TestCase;

class PolicyNetAmountTest extends TestCase
{
    /** @test */
    public function agent_net_amount_returns_zero_for_non_pagada_policy()
    {
        $policy = new Policy([
            'agent_id' => 1,
            'status' => 'Activa',
            'commission_amount' => 50040.00,
            'isr_retention' => 10,
            'billing_retention' => 5,
        ]);

        $this->assertEquals(0.0, $policy->agentNetAmount());
    }

    /** @test */
    public function agent_net_amount_applies_isr_then_billing_sequentially()
    {
        // commission=50040.00, ISR=10%, billing=5%
        // ISR = 50040 × 0.10 = 5004.00
        // After ISR = 50040 - 5004 = 45036.00
        // Billing = 45036 × 0.05 = 2251.80
        // Net = 45036 - 2251.80 = 42784.20
        $policy = new Policy([
            'agent_id' => 1,
            'status' => 'Pagada',
            'commission_amount' => 50040.00,
            'isr_retention' => 10,
            'billing_retention' => 5,
        ]);

        $this->assertEquals(42784.20, $policy->agentNetAmount());
    }

    /** @test */
    public function agent_net_amount_with_zero_retentions_returns_full_commission()
    {
        $policy = new Policy([
            'agent_id' => 1,
            'status' => 'Pagada',
            'commission_amount' => 10000.00,
            'isr_retention' => 0,
            'billing_retention' => 0,
        ]);

        $this->assertEquals(10000.00, $policy->agentNetAmount());
    }

    /** @test */
    public function promoter_net_amount_returns_zero_for_non_pagada_policy()
    {
        $policy = new Policy([
            'agent_id' => 1,
            'status' => 'No tomada',
            'promoter_commission_amount' => 5000.00,
            'isr_retention' => 10,
            'billing_retention' => 5,
        ]);

        $this->assertEquals(0.0, $policy->promoterNetAmount());
    }

    /** @test */
    public function promoter_net_amount_applies_isr_then_billing_sequentially()
    {
        // promoter_commission=5000.00, ISR=10%, billing=5%
        // ISR = 5000 × 0.10 = 500.00
        // After ISR = 5000 - 500 = 4500.00
        // Billing = 4500 × 0.05 = 225.00
        // Net = 4500 - 225 = 4275.00
        $policy = new Policy([
            'agent_id' => 1,
            'status' => 'Pagada',
            'promoter_commission_amount' => 5000.00,
            'isr_retention' => 10,
            'billing_retention' => 5,
        ]);

        $this->assertEquals(4275.00, $policy->promoterNetAmount());
    }

    /** @test */
    public function net_amount_returns_sum_of_agent_and_promoter_net_commissions()
    {
        // netAmount() = agentNetAmount() + promoterNetAmount()
        // agent: 50040 at 10% + 5% = 42784.20
        // promoter: 5000 at 10% + 5% = 4275.00
        // total = 42784.20 + 4275.00 = 47059.20
        $policy = new Policy();
        $policy->setRawAttributes([
            'agent_id' => 1,
            'status' => 'Pagada',
            'commission_amount' => '50040.00',
            'promoter_commission_amount' => '5000.00',
            'isr_retention' => '10.00',
            'billing_retention' => '5.00',
        ]);

        $this->assertEquals(47059.20, $policy->netAmount());
    }
}
