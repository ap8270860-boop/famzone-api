<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A named circle on the family map.
 *
 * @property-read User $user
 */
#[Fillable([
    'name',
    'kind',
    'latitude',
    'longitude',
    'radius_m',
    'notify_on_arrive',
    'notify_on_leave',
])]
class FamilyPlace extends Model
{
    /**
     * The kinds the app draws an icon for.
     *
     * Not a database constraint and not validated against — a kind the server
     * has never heard of is stored and handed back, and the client falls back
     * to a neutral pin. The alternative is a deployment every time somebody
     * wants "grandparents".
     */
    public const KINDS = [
        'home',
        'school',
        'office',
        'hospital',
        'gym',
        'park',
        'shop',
        'custom',
    ];

    /** Below this, GPS cannot tell inside from outside. */
    public const MIN_RADIUS_M = 80;

    /** Above this it is not a place, it is a neighbourhood. */
    public const MAX_RADIUS_M = 2000;

    /** One person may not have a hundred of these. */
    public const MAX_PER_USER = 25;

    protected static function booted(): void
    {
        static::creating(function (self $place) {
            $place->uuid ??= (string) Str::uuid7();
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'radius_m' => 'integer',
            'notify_on_arrive' => 'boolean',
            'notify_on_leave' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<User, FamilyPlace>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<PlaceVisit>
     */
    public function visits(): HasMany
    {
        return $this->hasMany(PlaceVisit::class, 'place_id');
    }

    /**
     * The distance past which somebody counts as having left.
     *
     * Wider than the radius they entered at, and deliberately so — see the
     * note on hysteresis in the place_visits migration. Twenty per cent plus
     * a flat thirty metres: the factor handles large places, the flat pad
     * handles small ones, where twenty per cent of 80 m is less than the
     * accuracy of the fix being tested.
     */
    public function exitRadius(): float
    {
        return $this->radius_m * 1.2 + 30;
    }
}
