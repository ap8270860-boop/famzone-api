<?php

namespace App\Events\Safety;

use App\Models\CheckInEscalation;
use App\Models\User;
use App\Services\Safety\CheckInEscalationService;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * The chain has finished, one way or the other.
 *
 * Goes back to the person who checked in, so the circular view on their home
 * screen fills in while they are looking at it rather than on their next pull
 * to refresh. That is the whole reason this event exists: the check-in card is
 * a thing people watch for a minute after tapping it, and a progress bar that
 * only ever moves when you leave the screen and come back is not a progress
 * bar.
 *
 * One event for both endings, with `contact` null when nobody answered.
 * Deliberately not two: the client's job in both cases is to replace the chain
 * it is holding with the one in this frame, and splitting that into two
 * handlers would mean two chances to forget one of them.
 *
 * The full chain travels inline, not a delta. It is a handful of people with a
 * status each — small enough that sending the whole truth costs nothing, and
 * sending the whole truth means a client that missed an earlier frame is
 * corrected by this one instead of compounding the gap.
 */
class CheckInAcknowledged implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly CheckInEscalation $escalation,
        public readonly User $owner,
        public readonly ?User $contact,
    ) {
    }

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('user.'.$this->owner->uuid)];
    }

    public function broadcastAs(): string
    {
        return 'check_in.acknowledged';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'chain' => app(CheckInEscalationService::class)->present($this->escalation),

            /*
             | Who confirmed, inline and separately from the chain.
             |
             | The chain carries this too, but the client shows a toast the
             | moment the frame lands and should not have to dig through a
             | list of steps to find a name for it. Null when the chain ran
             | out — there is nobody to name, and that is the news.
             */
            'contact' => $this->contact === null ? null : [
                'id' => $this->contact->uuid,
                'name' => $this->contact->name,
                'avatar_url' => $this->contact->avatar_url,
            ],
        ];
    }
}
