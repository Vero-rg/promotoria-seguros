<?php

namespace Database\Factories;

use App\Models\Policy;
use App\Models\Agent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Policy>
 */
class PolicyFactory extends Factory
{
    protected $model = Policy::class;

    public function definition(): array
    {
        return [
            'agent_id'                     => Agent::factory(),
            'policy_number'                => 'POL-' . fake()->unique()->numerify('######'),
            'client_name'                  => fake()->name(),
            'issue_date'                   => fake()->dateTimeBetween('-6 months', 'now'),
            'premium_amount'               => fake()->randomFloat(2, 5000, 50000),
            'commission_percentage'        => fake()->randomFloat(2, 5, 25),
            'commission_amount'            => 0, // Se calculará en el observer o manualmente
            'promoter_commission_percentage' => fake()->randomFloat(2, 0, 15),
            'promoter_commission_amount'   => 0,
            'isr_retention'                => 10.00,
            'billing_retention'            => 5.00,
            'status'                       => Policy::STATUS_ACTIVA,
            'product_type'                 => 'Vida', // default
        ];
    }

    public function vida(): static
    {
        return $this->state(fn (array $attributes) => [
            'product_type' => 'Vida',
        ]);
    }

    public function primordial(): static
    {
        return $this->state(fn (array $attributes) => [
            'product_type' => 'Primordial',
        ]);
    }

    public function withPremium(float $amount): static
    {
        return $this->state(fn (array $attributes) => [
            'premium_amount' => $amount,
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Policy::STATUS_NO_TOMADA,
        ]);
    }
}
