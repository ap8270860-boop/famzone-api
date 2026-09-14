<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            AdminSeeder::class,

            /*
             | The reminder catalogue: twelve categories and their presets.
             |
             | Safe to run on every deploy — it matches on `key` and updates in
             | place, so ids stay stable and the reminders people have already
             | built on these rows keep pointing at the same thing.
             */
            ReminderCatalogueSeeder::class,
        ]);

        // User::factory(10)->create();

        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);
    }
}
