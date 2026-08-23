<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'username' => fake()->unique()->userName(),
            'phone_country_code' => '+91',
            'phone_number' => fake()->unique()->numerify('9#########'),
            'phone_verified_at' => now(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'about' => fake()->sentence(6),
            'locale' => 'en',
            'timezone' => 'Asia/Kolkata',
            'user_type' => User::TYPE_ADULT,
            'status' => User::STATUS_ACTIVE,
            'push_enabled' => true,
            'remember_token' => Str::random(10),
        ];
    }

    /** Phone not yet verified. */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'phone_verified_at' => null,
            'email_verified_at' => null,
        ]);
    }

    /** Has a registered device, so push can be targeted at them. */
    public function withDevice(string $type = User::DEVICE_ANDROID): static
    {
        return $this->state(fn (array $attributes) => [
            'device_token' => Str::random(163),
            'device_type' => $type,
            'device_id' => (string) Str::uuid(),
            'device_model' => 'Pixel 8',
            'app_version' => '1.0.0',
        ]);
    }

    /** Currently broadcasting a location. */
    public function sharingLocation(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_sharing_location' => true,
            'last_latitude' => fake()->latitude(8, 35),
            'last_longitude' => fake()->longitude(68, 97),
            'last_location_at' => now(),
            'battery_level' => fake()->numberBetween(15, 100),
        ]);
    }

    /** A school- or college-age child account. */
    public function kid(string $stage = User::STAGE_SCHOOL): static
    {
        return $this->state(fn (array $attributes) => [
            'user_type' => User::TYPE_KID,
            'education_stage' => $stage,
            'date_of_birth' => now()->subYears($stage === User::STAGE_SCHOOL ? 12 : 19),
        ]);
    }

    /** A senior citizen account. */
    public function senior(): static
    {
        return $this->state(fn (array $attributes) => [
            'user_type' => User::TYPE_SENIOR,
            'date_of_birth' => now()->subYears(68),
        ]);
    }

    /** Paying subscriber. */
    public function premium(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_premium' => true,
            'subscription_plan' => 'monthly',
            'subscription_expires_at' => now()->addMonth(),
        ]);
    }

    /** Banned account. */
    public function banned(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => User::STATUS_BANNED,
            'banned_at' => now(),
            'ban_reason' => 'Violated community guidelines',
        ]);
    }
}
