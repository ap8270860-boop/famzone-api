<?php

namespace App\Events\Safety;

use App\Models\CheckInEscalation;
use App\Models\CheckInEscalationStep;
use App\Models\User;
use App\Services\Safety\CheckInEscalationService;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * It is your turn to confirm somebody's check-in.
 *
 * Sent to exactly one person: whoever currently holds the request. Not the
 * whole family, not everybody on the list — the entire point of the chain is
 * that Vinod is not disturbed while Kulsoom still has it, and a broadcast to
 * all three would undo that in one line.
 *
 * ShouldBroadcastNow rather than the queue. Not because half a second matters
 * here the way it does for an alarm, but because this frame has a deadline
 * attached to it: the countdown the recipient sees starts when the server says
 * it does, and a frame that sat in a queue for two minutes arrives claiming
 * thirty minutes of grace that are already twenty-eight.
 *
 * The frame is self-sufficient. Everything needed to draw the banner — who
 * checked in, their photo, the step id to answer with, when it moves on — is
 * inline, so a phone that receives this while offline from the API still shows
 * a complete card rather than a spinner over a uuid.
 *
 * And a websocket only reaches somebody with the app open. The durable half is
 * the user_notifications row written just before this fires; push notification
 * is the piece that is still missing, and until it lands a closed app learns
 * about this when it is next opened, from the feed.
 */
class CheckInRequested implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly CheckInEscalation $escalation,
        public readonly CheckInEscalationStep $step,
        public readonly User $owner,
        public readonly User $contact,
    ) {
    }

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('user.'.$this->contact->uuid)];
    }

    public function broadcastAs(): string
    {
        return 'check_in.requested';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        /*
         | Wire the relations up by hand rather than letting the presenter
         | lazy-load them.
         |
         | Everything here is already in memory — the step, its chain and the
         | person who checked in were all loaded by the service that fired
         | this. Handing them over explicitly turns three round trips into
         | none, and more importantly makes the frame's contents independent
         | of whatever the database happens to say a moment later.
         */
        $escalation = $this->escalation;
        $escalation->setRelation('user', $this->owner);

        $step = $this->step;
        $step->setRelation('escalation', $escalation);
        $step->setRelation('contact', $this->contact);

        return [
            'request' => app(CheckInEscalationService::class)->request($step),
        ];
    }
}
