<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * One tile in the reminder picker.
 *
 * @property-read \Illuminate\Database\Eloquent\Collection<int, ReminderTemplate> $templates
 */
#[Fillable([
    'key', 'name', 'icon', 'vibe', 'colour_from', 'colour_to',
    'tagline', 'is_custom', 'sort_order', 'is_active',
])]
class ReminderCategory extends Model
{
    /** The one every account gets, for things that fit nowhere else. */
    public const KEY_CUSTOM = 'custom';

    /** Medicine is the only category the client treats specially. */
    public const KEY_MEDICINE = 'medicine';

    protected static function booted(): void
    {
        static::creating(function (self $row) {
            $row->uuid ??= (string) Str::uuid7();
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_custom' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return HasMany<ReminderTemplate, ReminderCategory>
     */
    public function templates(): HasMany
    {
        return $this->hasMany(ReminderTemplate::class)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    /**
     * @param  Builder<ReminderCategory>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id');
    }
}
