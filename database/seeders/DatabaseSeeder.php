<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use RuntimeException;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $email = (string) config('workstate.demo_user.email');
        $password = (string) config('workstate.demo_user.password');

        if ($email === '' || $password === '') {
            throw new RuntimeException('Set SEED_USER_EMAIL and SEED_USER_PASSWORD before seeding.');
        }

        User::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => (string) config('workstate.demo_user.name'),
                'password' => $password,
                'email_verified_at' => now(),
            ],
        );
    }
}
