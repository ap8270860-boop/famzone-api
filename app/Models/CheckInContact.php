<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One entry in somebody's "tell these people, in this order" list.
 *
 * @property-read User $owner
 * @property-read User $contact
 */
#[Fillable(['position'])]
class CheckInContact extends Model
{
    /**
     * How many people one chain may hold.
     *
     * Ten is not a technical limit — it is the point past which the feature
     * stops being what it is for. A chain of thirty at half an hour apart
     * takes fifteen hours to exhaust, which is longer than the day the
     * check-in was about. If nobody in the first ten has answered, waiting for
     * the eleventh is not the remedy.
     */
    public const MAX_CONTACTS = 10;

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
        return ['position' => 'integer'];
    }

    /**
     * @return BelongsTo<User, CheckInContact>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return BelongsTo<User, CheckInContact>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(User::class, 'contact_id');
    }

    /**
     * @param  Builder<CheckInContact>  $query
     */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('position')->orderBy('id');
    }
}
