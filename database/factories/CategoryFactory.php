<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use App\Models\Category;

class CategoryFactory extends Factory
{
    protected $model = Category::class;

    public function definition()
    {
        return [
            'name' => $this->faker->word,
            'type' => 'expense',
            'icon' => 'tag',
            'color' => '#000000',
            'is_default' => true,
        ];
    }
}