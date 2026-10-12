<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(AccountClassificationSeeder::class);

        User::create([
            'username' => 'testuser',
            'email' => 'test@smartapp.local',
            'password' => Hash::make('password123'),
        ]);

        User::create([
            'username' => 'psds',
            'email' => 'psds@gmail.com',
            'password' => Hash::make('superadmin123'),
            'role' => 'psds',
            'is_superadmin' => true,
        ]);

        User::create([
            'username' => 'superadmin',
            'email' => 'superadmin@gmail.com',
            'password' => Hash::make('superadmin123'),
            'role' => 'superadmin',
            'is_superadmin' => true,
        ]);
    }
}
