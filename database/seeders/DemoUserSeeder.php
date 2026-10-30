<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Fictional demo users only. "example.test" is a reserved domain that can never receive real mail.
 * Safe to run more than once: users are matched by email and updated, not duplicated.
 */
class DemoUserSeeder extends Seeder
{
    public function run(): void
    {
        $people = [
            ['name' => 'Lena Librarian', 'email' => 'librarian@example.test', 'role' => 'librarian'],
            ['name' => 'Sam Supervisor', 'email' => 'supervisor@example.test', 'role' => 'supervisor'],
            ['name' => 'Ana Reyes', 'email' => 'ana.reyes@example.test', 'role' => 'borrower'],
            ['name' => 'Ben Cruz', 'email' => 'ben.cruz@example.test', 'role' => 'borrower'],
            ['name' => 'Carla Santos', 'email' => 'carla.santos@example.test', 'role' => 'borrower'],
            // Deliberately invalid address (no "@"). Used to demonstrate the permanent-failure path.
            ['name' => 'Dino Lim', 'email' => 'dino.lim-at-example.test', 'role' => 'borrower'],
        ];

        foreach ($people as $person) {
            $user = User::firstOrNew(['email' => $person['email']]);

            // forceFill is used because "role" is not in the User model's fillable list.
            $user->forceFill([
                'name' => $person['name'],
                'role' => $person['role'],
                // Demo-only password for fictional accounts. Real authentication is built in Week 14.
                'password' => Hash::make('DemoPass#2026'),
            ])->save();
        }
    }
}
