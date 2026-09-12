<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One stay inside one place.
 *
 * No uuid. This is internal bookkeeping the client never addresses directly —
 * it reads the *label* a visit produces, never the visit — and a uuid on a
 * table written on every boundary crossing is a column and an index earning
 * nothing.
 *
 * @property-read FamilyPlace $place
 * @property-read User $user
 */
#[Fillable(['entered_at', 'confirmed_at', 'left_at'])]
class PlaceVisit extends Model
{
    /**
     * How long somebody must stay before it counts as arriving.
     *
     * This is the whole defence against a drive-past. At 40 km/h a car
     * crosses a 150 m circle in about thirteen seconds, so a minute is
     * comfortably longer than any pass-through and comfortably shorter than
     * any actual visit.
     *
     * It costs a delay on genuine arrivals, which is the right trade: a
     * notification a minute late is useful, and a notification that fires
     * every time somebody drives down their own street is switched off within
     * a week.
     */
    public const DWELL_SECONDS = 60;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'entered_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'left_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<FamilyPlace, PlaceVisit>
     */
    public function place(): BelongsTo
    {
        return $this->belongsTo(FamilyPlace::class, 'place_id');
    }

    /**
     * @return BelongsTo<User, PlaceVisit>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Still inside. */
    public function isOpen(): bool
    {
        return $this->left_at === null;
    }

    /** Inside long enough to count. */
    public function isConfirmed(): bool
    {
        return $this->confirmed_at !== null;
    }

    /**
     * Open visits only.
     *
     * @param  Builder<PlaceVisit>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('left_at');
    }
}
