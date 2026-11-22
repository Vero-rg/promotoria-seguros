<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\Policy;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page()
    {
        $response = $this->get('/dashboard');
        $response->assertRedirect('/login');
    }

    public function test_authenticated_users_can_visit_the_dashboard()
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->get('/dashboard');
        $response->assertStatus(200);
    }

    public function test_net_income_equals_pna_minus_net_commissions(): void
    {
        $this->actingAs(User::factory()->create());

        Policy::factory()
            ->for(Agent::factory())
            ->create([
                'issue_date' => now()->startOfMonth()->toDateString(),
                'premium_amount' => 100_000,
                'commission_percentage' => 56,
                'commission_amount' => 55_244,
                'promoter_commission_percentage' => 14,
                'promoter_commission_amount' => 13_811,
                'isr_retention' => 10,
                'cedular_tax' => 5,
                'billing_retention' => 5,
                'status' => Policy::STATUS_PAGADA,
                'product_type' => 'METALIFE',
                'payment_method' => Policy::PAYMENT_METHOD_AUTOMATIC,
            ]);

        // Comisiones netas: agente 44,871.94 + promotor 11,217.98 = 56,089.92
        // Ingreso neto = PNA 100,000 − 56,089.92
        $this->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard/Index')
                ->where('kpis.total_pna', 100_000)
                ->where('kpis.total_pca', 98_650)
                ->where('kpis.net_income', 43_910.08)
                ->where('top_agents.0.commission_breakdown.gross', 55_244)
                ->where('top_agents.0.commission_breakdown.cedular', 2_485.98)
                ->where('top_agents.0.commission_breakdown.net', 44_871.94)
            );
    }

    public function test_trends_group_by_day_and_include_premium_for_short_periods(): void
    {
        $this->actingAs(User::factory()->create());

        $issueDate = now()->startOfMonth()->toDateString();

        Policy::factory()
            ->for(Agent::factory())
            ->create([
                'issue_date' => $issueDate,
                'premium_amount' => 90_000,
                'status' => Policy::STATUS_PAGADA,
                'payment_method' => Policy::PAYMENT_METHOD_AUTOMATIC,
            ]);

        $this->get(route('dashboard', ['filter' => 'month']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('trend_grouping', 'day')
                ->where('trends', function ($trends) use ($issueDate) {
                    $point = collect($trends)->firstWhere('date', $issueDate);

                    return $point !== null
                        && $point['count'] === 1
                        && (float) $point['premium'] === 90_000.0;
                })
            );
    }

    public function test_trends_group_by_month_for_long_periods(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('dashboard', ['filter' => 'year']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('trend_grouping', 'month')
                ->has('trends', 12)
            );
    }
}
