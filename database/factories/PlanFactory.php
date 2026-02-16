<?php

namespace Database\Factories;

use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;

class PlanFactory extends Factory
{
    protected $model = Plan::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->word,
            'slug' => $this->faker->slug,
            'type' => $this->faker->randomElement(['monthly', 'yearly', 'lifetime', 'free']),
            'price' => $this->faker->numberBetween(10000, 1000000),
            'discount_price' => null,
            'currency' => 'IDR',
            'features' => [],
            'description' => $this->faker->sentence,
            'is_active' => true,
            'is_popular' => false,
            'sort_order' => $this->faker->numberBetween(1, 100),
        ];
    }
}