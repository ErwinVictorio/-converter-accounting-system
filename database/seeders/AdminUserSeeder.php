<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Creates the first account that can sign in at /login.
 *
 * Every route now sits behind the "auth" middleware, so a fresh install has no
 * way in until this runs:
 *
 *     php artisan db:seed --class=AdminUserSeeder
 *
 * Kept out of DatabaseSeeder on purpose -- a full `db:seed` also re-runs the
 * supplier and customer seeders, which is not what you want just to add a login.
 *
 * Sign in with the username below. Override either value with ADMIN_USERNAME /
 * ADMIN_PASSWORD in .env, and change the password after the first sign-in.
 */
class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $username = env('ADMIN_USERNAME', 'BIR');
        $password = env('ADMIN_PASSWORD', 'bir');

        // firstOrCreate, not updateOrCreate: re-running must never quietly reset
        // a password that has already been changed.
        $user = User::firstOrCreate(
            ['username' => $username],
            [
                'name' => $username,
                // The User model casts "password" => "hashed", so this is hashed on save.
                'password' => $password,
            ]
        );

        if ($user->wasRecentlyCreated) {
            $this->command->info("Created login: {$username} / {$password}");
            $this->command->warn('Change this password after signing in.');

            return;
        }

        $this->command->warn("{$username} already exists -- password left untouched.");
    }
}
