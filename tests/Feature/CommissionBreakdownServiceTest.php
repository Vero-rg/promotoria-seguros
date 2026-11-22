<?php

namespace Tests\Feature;

use App\Models\Policy;
use App\Models\Scheme;
use App\Services\CommissionBreakdownService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommissionBreakdownServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_gross_commission_uses_pca_and_subtracts_cedular(): void
    {
        // PCA = 100,000 − 1,350 = 98,650 → 56% = 55,244.00
        // ISR 10% = 5,524.40 → cedular 5% = 2,485.98 → facturación 5% = 2,361.68
        $policy = Policy::factory()->make([
            'premium_amount' => 100_000,
            'commission_percentage' => 56,
            'promoter_commission_percentage' => 14,
            'isr_retention' => 10,
            'cedular_tax' => 5,
            'billing_retention' => 5,
            'status' => Policy::STATUS_PAGADA,
        ]);

        $breakdown = app(CommissionBreakdownService::class)->forPolicy($policy, CommissionBreakdownService::ROLE_AGENT);

        $this->assertEquals(55_244.00, $breakdown['gross']);
        $this->assertEquals(5_524.40, $breakdown['isr']);
        $this->assertEquals(2_485.98, $breakdown['cedular']);
        $this->assertEquals(2_361.68, $breakdown['billing']);
        $this->assertEquals(44_871.94, $breakdown['net']);
    }

    public function test_gross_commission_falls_back_to_the_active_commission_scheme(): void
    {
        $scheme = Scheme::create([
            'name' => 'Comisión de prueba',
            'type' => 'commission',
            'target' => 'both',
            'is_active' => true,
        ]);

        $version = $scheme->versions()->create([
            'version_name' => 'v1',
            'starts_at' => now()->subMonth()->toDateString(),
        ]);

        $version->tiers()->create([
            'conditions' => ['product_type' => 'METALIFE'],
            'agent_percentage' => 56,
            'promoter_percentage' => 14,
        ]);

        $policy = Policy::factory()->make([
            'product_type' => 'METALIFE',
            'premium_amount' => 100_000,
            'commission_percentage' => 0,
            'commission_amount' => 0,
            'promoter_commission_percentage' => 0,
            'promoter_commission_amount' => 0,
            'status' => Policy::STATUS_PAGADA,
        ]);

        $service = app(CommissionBreakdownService::class);

        // PCA = 98,650 → agente 56% = 55,244.00 · promotor 14% = 13,811.00
        $this->assertEquals(55_244.00, $service->grossCommission($policy, CommissionBreakdownService::ROLE_AGENT));
        $this->assertEquals(13_811.00, $service->grossCommission($policy, CommissionBreakdownService::ROLE_PROMOTER));
    }

    public function test_it_prefers_the_stored_commission_amount_when_present(): void
    {
        $policy = Policy::factory()->make([
            'premium_amount' => 100_000,
            'commission_percentage' => 56,
            'commission_amount' => 50_000,
            'status' => Policy::STATUS_PAGADA,
        ]);

        $this->assertEquals(50_000.00, app(CommissionBreakdownService::class)->grossCommission($policy, CommissionBreakdownService::ROLE_AGENT));
    }

    public function test_it_ignores_policies_that_are_not_paid(): void
    {
        $policy = Policy::factory()->make([
            'premium_amount' => 100_000,
            'commission_percentage' => 56,
            'status' => Policy::STATUS_ACTIVA,
        ]);

        $breakdown = app(CommissionBreakdownService::class)->forPolicy($policy, CommissionBreakdownService::ROLE_AGENT);

        $this->assertSame([
            'gross' => 0.0,
            'isr' => 0.0,
            'cedular' => 0.0,
            'billing' => 0.0,
            'net' => 0.0,
        ], $breakdown);
    }

    public function test_summarize_totals_agent_and_promoter_roles_separately(): void
    {
        $policies = collect([
            Policy::factory()->make([
                'premium_amount' => 100_000,
                'commission_percentage' => 56,
                'promoter_commission_percentage' => 14,
                'isr_retention' => 0,
                'cedular_tax' => 0,
                'billing_retention' => 0,
                'status' => Policy::STATUS_PAGADA,
            ]),
        ]);

        $service = app(CommissionBreakdownService::class);

        $this->assertEquals(55_244.00, $service->summarize($policies, CommissionBreakdownService::ROLE_AGENT)['gross']);
        $this->assertEquals(13_811.00, $service->summarize($policies, CommissionBreakdownService::ROLE_PROMOTER)['gross']);
    }
}
