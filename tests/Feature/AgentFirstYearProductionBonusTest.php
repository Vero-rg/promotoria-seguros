<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\Policy;
use App\Models\Scheme;
use App\Models\SchemeVersion;
use App\Models\SchemeTier;
use App\Services\BonusCalculators\AgentFirstYearProductionCalculator;
use Carbon\Carbon;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class AgentFirstYearProductionBonusTest extends TestCase
{
    use RefreshDatabase;
    private function createSchemeWithProductRequirements(array $requiresProduct): Scheme
    {
        $scheme = Scheme::create([
            'name' => 'Producción 1er Año Vida Trimestral (3 meses)',
            'type' => 'bonus',
            'template_key' => 'agent_first_year_production',
            'target' => 'agent',
            'is_active' => true,
            'metric_base' => 'PCA',
            'frequency' => 'trimestral',
            'requires_product' => $requiresProduct,
        ]);

        $version = SchemeVersion::create([
            'scheme_id' => $scheme->id,
            'version_name' => 'v1',
            'starts_at' => '2026-01-01',
            'ends_at' => null,
        ]);

        SchemeTier::create([
            'scheme_version_id' => $version->id,
            'conditions' => json_encode(['min_pca' => 10000]),
            'agent_percentage' => 10,
        ]);

        return $scheme->fresh(['versions.tiers']);
    }

    /** @test */
    public function bonus_unlocks_when_primordial_count_meets_minimum_and_pca_is_met()
    {
        // Agent in first year
        $agent = Agent::factory()->create([
            'created_at' => Carbon::now()->subMonths(3),
        ]);

        // 3 PRIMORDIAL policies with enough PCA
        for ($i = 0; $i < 3; $i++) {
            Policy::factory()->create([
                'agent_id' => $agent->id,
                'status' => 'Pagada',
                'premium_amount' => 20000,
                'product_type' => 'PRIMORDIAL',
                'issue_date' => Carbon::now()->subDays($i),
            ]);
        }

        $scheme = $this->createSchemeWithProductRequirements([
            ['product_type' => 'PRIMORDIAL', 'min_count' => 3],
        ]);

        $calculator = new AgentFirstYearProductionCalculator();
        $result = $calculator->calculate(
            $agent,
            $scheme,
            Carbon::now()->startOfQuarter(),
            Carbon::now()->endOfQuarter()
        );

        $this->assertTrue($result['is_achieved'], 'Bonus should unlock with 3 PRIMORDIAL policies');
        $this->assertGreaterThan(0, $result['amount']);
    }

    /** @test */
    public function bonus_does_not_unlock_when_primordial_count_below_minimum()
    {
        $agent = Agent::factory()->create([
            'created_at' => Carbon::now()->subMonths(3),
        ]);

        // Only 2 PRIMORDIAL policies — below min_count of 3
        for ($i = 0; $i < 2; $i++) {
            Policy::factory()->create([
                'agent_id' => $agent->id,
                'status' => 'Pagada',
                'premium_amount' => 20000,
                'product_type' => 'PRIMORDIAL',
                'issue_date' => Carbon::now()->subDays($i),
            ]);
        }

        $scheme = $this->createSchemeWithProductRequirements([
            ['product_type' => 'PRIMORDIAL', 'min_count' => 3],
        ]);

        $calculator = new AgentFirstYearProductionCalculator();
        $result = $calculator->calculate(
            $agent,
            $scheme,
            Carbon::now()->startOfQuarter(),
            Carbon::now()->endOfQuarter()
        );

        $this->assertFalse($result['is_achieved'], 'Bonus should NOT unlock with only 2 PRIMORDIAL');
        $this->assertEquals(0.0, $result['amount']);
    }

    /** @test */
    public function bonus_unlocks_with_vida_alias_resolving_to_metlife_and_perfectlife()
    {
        $agent = Agent::factory()->create([
            'created_at' => Carbon::now()->subMonths(3),
        ]);

        // 2 METLIFE + 1 PERFECTLIFE = 3 "Vida" policies
        Policy::factory()->create([
            'agent_id' => $agent->id,
            'status' => 'Pagada',
            'premium_amount' => 20000,
            'product_type' => 'METLIFE',
            'issue_date' => Carbon::now()->subDays(1),
        ]);
        Policy::factory()->create([
            'agent_id' => $agent->id,
            'status' => 'Pagada',
            'premium_amount' => 20000,
            'product_type' => 'METLIFE',
            'issue_date' => Carbon::now()->subDays(2),
        ]);
        Policy::factory()->create([
            'agent_id' => $agent->id,
            'status' => 'Pagada',
            'premium_amount' => 20000,
            'product_type' => 'PERFECTLIFE',
            'issue_date' => Carbon::now()->subDays(3),
        ]);

        $scheme = $this->createSchemeWithProductRequirements([
            ['product_type' => 'Vida', 'min_count' => 3],
        ]);

        $calculator = new AgentFirstYearProductionCalculator();
        $result = $calculator->calculate(
            $agent,
            $scheme,
            Carbon::now()->startOfQuarter(),
            Carbon::now()->endOfQuarter()
        );

        $this->assertTrue($result['is_achieved'], 'Bonus should unlock with 3 Vida (METLIFE+PERFECTLIFE)');
        $this->assertGreaterThan(0, $result['amount']);
    }

    /** @test */
    public function bonus_fails_when_one_of_two_required_products_not_met()
    {
        $agent = Agent::factory()->create([
            'created_at' => Carbon::now()->subMonths(3),
        ]);

        // 3 PRIMORDIAL (meets requirement) but only 1 Vida (fails)
        for ($i = 0; $i < 3; $i++) {
            Policy::factory()->create([
                'agent_id' => $agent->id,
                'status' => 'Pagada',
                'premium_amount' => 20000,
                'product_type' => 'PRIMORDIAL',
                'issue_date' => Carbon::now()->subDays($i),
            ]);
        }
        Policy::factory()->create([
            'agent_id' => $agent->id,
            'status' => 'Pagada',
            'premium_amount' => 20000,
            'product_type' => 'METLIFE',
            'issue_date' => Carbon::now()->subDays(4),
        ]);

        $scheme = $this->createSchemeWithProductRequirements([
            ['product_type' => 'PRIMORDIAL', 'min_count' => 3],
            ['product_type' => 'Vida', 'min_count' => 2],
        ]);

        $calculator = new AgentFirstYearProductionCalculator();
        $result = $calculator->calculate(
            $agent,
            $scheme,
            Carbon::now()->startOfQuarter(),
            Carbon::now()->endOfQuarter()
        );

        $this->assertFalse($result['is_achieved'], 'Bonus should NOT unlock — Vida min_count not met');
        $this->assertEquals(0.0, $result['amount']);
    }
}
