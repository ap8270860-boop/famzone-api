<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A preset inside a category — the thing you tap before the editor opens.
 *
 * @property-read ReminderCategory $category
 */
#[Fillable([
    'key', 'name', 'icon', 'default_time', 'default_repeat',
    'default_weekday_mask', 'sort_order', 'is_active',
])]
class ReminderTemplate extends Model
{
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
            'default_weekday_mask' => 'integer',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<ReminderCategory, ReminderTemplate>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ReminderCategory::class, 'reminder_category_id');
    }

    /**
     * The suggested time as "HH:MM", or null when the preset has no opinion.
     *
     * Cast by hand rather than through a `time` cast: Laravel's datetime casts
     * turn a TIME column into a Carbon dated 1 January of year zero, which
     * then serialises as a full ISO timestamp. The client wants two numbers.
     */
    public function defaultTimeLabel(): ?string
    {
        if (blank($this->default_time)) {
            return null;
        }

        return substr((string) $this->default_time, 0, 5);
    }
}
