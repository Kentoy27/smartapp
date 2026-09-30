<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\User>
 */
class UserFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'username' => Str::slug(fake()->unique()->userName()),
            'email' => fake()->unique()->safeEmail(),
            'password' => 'password', // hashed automatically by the model cast
            'is_superadmin' => false,
        ];
    }
}
