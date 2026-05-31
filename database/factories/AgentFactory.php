<?php

namespace Database\Factories;

use App\Models\Agent;
use App\Models\Promoter;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Agent>
 */
class AgentFactory extends Factory
{
    protected $model = Agent::class;

    public function definition(): array
    {
        return [
            'promoter_id' => Promoter::factory(),
            'name'        => fake()->firstName() . ' ' . fake()->lastName(),
            'is_active'   => true,
            'created_at'  => now()->subMonths(rand(0, 11)), // Siempre en su primer año
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    /**
     * Simula un agente que ya superó su primer año.
     */
    public function veteran(): static
    {
        return $this->state(fn (array $attributes) => [
            'created_at' => now()->subYears(2),
        ]);
    }
}
