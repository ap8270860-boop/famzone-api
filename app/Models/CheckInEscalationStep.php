<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One person's place in one chain.
 *
 * @property-read CheckInEscalation $escalation
 * @property-read User $contact
 */
#[Fillable(['position', 'status', 'notified_at', 'responded_at'])]
class CheckInEscalationStep extends Model
{
    /** Their turn has not come. */
    public const STATUS_WAITING = 'waiting';

    /** The request is with them now. */
    public const STATUS_NOTIFIED = 'notified';

    /** They confirmed they know. */
    public const STATUS_ACCEPTED = 'accepted';

    /** They passed it along. */
    public const STATUS_REJECTED = 'rejected';

    /** The wait ran out with no answer. */
    public const STATUS_EXPIRED = 'expired';

    /** Somebody earlier accepted, so this never went out. */
    public const STATUS_SKIPPED = 'skipped';

    /**
     * Statuses that mean this person's turn is over, however it ended.
     *
     * @var list<string>
     */
    public const CLOSED_STATUSES = [
        self::STATUS_ACCEPTED,
        self::STATUS_REJECTED,
        self::STATUS_EXPIRED,
        self::STATUS_SKIPPED,
    ];

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
            'position' => 'integer',
            'notified_at' => 'datetime',
            'responded_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<CheckInEscalation, CheckInEscalationStep>
     */
    public function escalation(): BelongsTo
    {
        return $this->belongsTo(CheckInEscalation::class, 'check_in_escalation_id');
    }

    /**
     * @return BelongsTo<User, CheckInEscalationStep>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(User::class, 'contact_id');
    }

    /**
     * Whether this step is still waiting for an answer from its person.
     *
     * This one method is what the notification feed's Accept button is drawn
     * from. Nothing else decides it, and nothing caches it.
     */
    public function isAwaitingResponse(): bool
    {
        return $this->status === self::STATUS_NOTIFIED;
    }

    /**
     * @param  Builder<CheckInEscalationStep>  $query
     */
    public function scopeAwaiting(Builder $query): void
    {
        $query->where('status', self::STATUS_NOTIFIED);
    }
}
