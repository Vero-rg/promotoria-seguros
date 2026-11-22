<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_agent_persists_cedular_tax_enabled_flag(): void
    {
        $this->actingAs(User::factory()->create())->post(route('agents.store'), [
            'name' => 'Juan Pérez',
            'is_active' => true,
            'cedular_tax_enabled' => true,
        ])->assertRedirect(route('directorio'));

        $this->assertTrue(Agent::firstOrFail()->cedular_tax_enabled);
    }

    public function test_update_agent_persists_cedular_tax_enabled_flag(): void
    {
        $agent = Agent::factory()->create(['cedular_tax_enabled' => false]);

        $this->actingAs(User::factory()->create())->put(route('agents.update', $agent), [
            'name' => $agent->name,
            'is_active' => true,
            'cedular_tax_enabled' => true,
        ])->assertRedirect(route('directorio'));

        $this->assertTrue($agent->fresh()->cedular_tax_enabled);
    }
}
