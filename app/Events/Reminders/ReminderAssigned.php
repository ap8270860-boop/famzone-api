<?php

namespace App\Events\Reminders;

use App\Models\Reminder;
use App\Models\User;
use App\Services\Reminders\ReminderService;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Somebody put a reminder on your phone.
 *
 * A request, not an instruction — which is why the reminder does not ring
 * until it is accepted. The frame exists so a phone that is open shows the ask
 * immediately instead of on the next pull-to-refresh; the durable half is the
 * notification row written just before this fires.
 *
 * Sent to the assignee alone. Nobody else in the family has any business
 * knowing what medicine somebody has been asked to take.
 */
class ReminderAssigned implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly Reminder $reminder,
        public readonly User $owner,
        public readonly User $assignee,
    ) {
    }

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('user.'.$this->assignee->uuid)];
    }

    public function broadcastAs(): string
    {
        return 'reminder.assigned';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        $reminder = $this->reminder;
        $reminder->setRelation('owner', $this->owner);
        $reminder->setRelation('assignee', $this->assignee);

        return [
            // The whole reminder inline, so the card renders without a round
            // trip — the round trip being exactly what fails on the phone in
            // a lift that this feature is supposed to reach.
            'reminder' => app(ReminderService::class)->present($reminder, $this->assignee),
        ];
    }
}
