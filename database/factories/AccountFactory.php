<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use App\Models\Account;
use App\Models\Household;

class AccountFactory extends Factory
{
    protected $model = Account::class;

    public function definition()
    {
        return [
            'household_id' => Household::factory(),
            'name' => $this->faker->word,
            'type' => 'bank',
            'current_balance' => 0,
            'initial_balance' => 0,
            'color' => '#000000',
            'icon' => 'wallet',
        ];
    }
}