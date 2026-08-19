<?php

namespace Database\Seeders;

use App\Models\Admin;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminSeeder extends Seeder
{
    /**
     * Seed the default administrator account.
     *
     * Safe to run more than once — the account is matched on its email
     * address and updated in place rather than duplicated.
     */
    public function run(): void
    {
        $admin = Admin::firstOrNew(['email' => 'famzone@admin.com']);

        $admin->fill([
            'name' => 'FamZone Admin',
            'username' => 'famzone',
            'phone_number' => null,
            // TODO: change this before the app leaves local development.
            'password' => Hash::make('password'),
            'type' => 'admin',
            'is_active' => true,
        ]);

        // Not mass assignable on the model, so it is set explicitly.
        $admin->email_verified_at ??= now();

        $admin->save();
    }
}
