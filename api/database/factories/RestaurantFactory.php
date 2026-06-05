<?php

namespace Database\Factories;

use App\Models\Restaurant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Restaurant>
 */
class RestaurantFactory extends Factory
{
    protected $model = Restaurant::class;

    public function definition(): array
    {
        $name = fake()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numerify('####'),
            'is_active' => true,
        ];
    }

    /**
     * Restaurante suspenso (inadimplente / desativado).
     */
    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
