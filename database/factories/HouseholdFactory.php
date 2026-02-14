<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use App\Models\Household;

class HouseholdFactory extends Factory
{
    protected $model = Household::class;

    public function definition()
    {
        return [
            'name' => $this->faker->lastName . ' Family',
        ];
    }
}